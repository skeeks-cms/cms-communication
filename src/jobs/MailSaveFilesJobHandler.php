<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\mail\MailDriverInterface;
use skeeks\cms\communication\mail\MailSessionInterface;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\communication\services\MailFileFetcher;
use skeeks\cms\communication\services\MailFileSave;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * "Сохранить файлы в CRM" (MailFileSave): goes over the chosen letters in id
 * order and keeps their files for good. A temporary copy only becomes
 * permanent; missing files are fetched from the mailbox, one read of the
 * letter for all its chosen files, over one IMAP session per connection for
 * the whole slice. The cursor (last letter id) is saved with the progress;
 * after $sliceSeconds the run requeues itself.
 */
class MailSaveFilesJobHandler extends AbstractJobHandler
{
    /** Letters per step. */
    public int $batchSize = 20;

    public int $sliceSeconds = 120;

    public int $sliceDelay = 5;

    /** @var array connection id => [session, selected folder|null] */
    private array $sessions = [];

    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $siteId = (int)$context->get('siteId');
        $criteria = MailFileSave::criteria($context->getPayload());
        $f = CmsCommMessageFile::tableName();
        $cursor = $context->getCursor();
        if (!isset($cursor['lastMessageId'])) {
            $preview = MailFileSave::preview($criteria, $siteId);
            $cursor = ['lastMessageId' => 0, 'saved' => 0, 'kept' => 0];
            $reporter->setTotal($preview['fetch'] + $preview['cached']);
            $context->setCursor($cursor);
        }

        $fetcher = new MailFileFetcher();
        $fetcher->keepForGood = true;
        $fetcher->rawLoader = fn(CmsCommMessageMailbox $copy) => $this->loadRaw($copy);
        $startedAt = time();
        $reporter->setStage('save', 'Сохранение файлов писем');
        try {
            while (true) {
                if ($reporter->isCancelled()) {
                    throw new JobCancelledException('Сохранение файлов остановлено.');
                }
                $messageIds = array_map('intval', MailFileSave::query($criteria, $siteId)
                    ->select($f.'.cms_comm_message_id')->distinct()
                    ->andWhere(['>', $f.'.cms_comm_message_id', (int)$cursor['lastMessageId']])
                    ->orderBy([$f.'.cms_comm_message_id' => SORT_ASC])->limit($this->batchSize)->column());
                if (!$messageIds) {
                    break;
                }
                foreach ($messageIds as $messageId) {
                    $this->saveLetter($messageId, $criteria, $siteId, $fetcher, $cursor, $reporter);
                    $cursor['lastMessageId'] = $messageId;
                }
                $context->setCursor($cursor);
                $reporter->heartbeat();
                if (time() - $startedAt >= $this->sliceSeconds) {
                    $reporter->setStage('pause', 'Сохранено файлов: '.($cursor['saved'] + $cursor['kept']).'. Продолжение через несколько секунд');
                    $context->requestRequeue($this->sliceDelay);
                }
            }
        } finally {
            $this->closeSessions();
        }

        $total = (int)$cursor['saved'] + (int)$cursor['kept'];
        $reporter->setResult(['saved' => (int)$cursor['saved'], 'kept' => (int)$cursor['kept']]);
        $reporter->setStage('complete', $total ? 'Сохранено файлов: '.$total : 'Все файлы уже сохранены в CRM');
    }

    private function saveLetter(int $messageId, array $criteria, int $siteId, MailFileFetcher $fetcher, array &$cursor, JobReporterInterface $reporter): void
    {
        $f = CmsCommMessageFile::tableName();
        $rows = MailFileSave::query($criteria, $siteId)->andWhere([$f.'.cms_comm_message_id' => $messageId])->all();
        $missing = [];
        foreach ($rows as $row) {
            if ($row->isStored()) {
                // A copy fetched on demand: it only stops being temporary.
                $row->is_cached = 0;
                $row->save(false, ['is_cached']);
                $cursor['kept']++;
                $reporter->countSuccess();
                $reporter->advance();
            } else {
                $missing[] = (int)$row->id;
            }
        }
        if (!$missing) {
            return;
        }
        $fetcher->onlyIds = $missing;
        $result = $fetcher->fetch($messageId, MailFileFetcher::KIND_ALL);
        foreach ($missing as $fileId) {
            if (!empty($result['files'][$fileId])) {
                $cursor['saved']++;
                $reporter->countSuccess();
            } else {
                $row = CmsCommMessageFile::findOne($fileId);
                $reporter->itemError('file', $messageId.':'.$fileId,
                    (string)($result['error'] ?? ($row ? $row->fetch_error : null) ?? 'Файл не загружен из почтового ящика.'));
            }
            $reporter->advance();
        }
    }

    /** The raw letter of a mailbox copy over the slice's session of its connection. */
    private function loadRaw(CmsCommMessageMailbox $copy): ?string
    {
        $connectionId = (int)$copy->cms_comm_connection_id;
        if (!isset($this->sessions[$connectionId])) {
            $connection = CmsCommConnection::findOne($connectionId);
            if (!$connection || !$connection->is_active) {
                throw new \RuntimeException('подключение ящика выключено');
            }
            $driver = $connection->createDriver(true);
            if (!$driver instanceof MailDriverInterface) {
                throw new \RuntimeException('драйвер не поддерживает почту');
            }
            $this->sessions[$connectionId] = ['session' => $driver->openSession(), 'folder' => null, 'uidvalidity' => null];
        }
        $entry = &$this->sessions[$connectionId];
        /** @var MailSessionInterface $session */
        $session = $entry['session'];
        if ($entry['folder'] !== (string)$copy->folder) {
            $selected = $session->select((string)$copy->folder);
            $entry['folder'] = (string)$copy->folder;
            $entry['uidvalidity'] = $selected['uidvalidity'] ?? null;
        }
        // A renumbered folder (other UIDVALIDITY): the stored UID points to another letter.
        if ($copy->uidvalidity && $entry['uidvalidity'] && (int)$entry['uidvalidity'] !== (int)$copy->uidvalidity) {
            return null;
        }
        $raws = $session->fetch([(int)$copy->uid]);

        return $raws[(int)$copy->uid] ?? null;
    }

    private function closeSessions(): void
    {
        foreach ($this->sessions as $entry) {
            try {
                $entry['session']->close();
            } catch (\Throwable $e) {
            }
        }
        $this->sessions = [];
    }
}
