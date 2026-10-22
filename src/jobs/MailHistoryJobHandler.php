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
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\communication\models\CmsCommSyncState;
use skeeks\cms\communication\services\MailIngestService;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\exceptions\JobPermanentException;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * "Сбор писем" of a mailbox: letters of the inbox and the sent folder since
 * a chosen date, read-only. Each letter is received like a new one (links to
 * leads, companies and clients, feed entries by its own date) but counts as
 * read (is_history). Letters after the receive job's cursor are not taken:
 * they are new and stay with it, unread. Known letters are skipped: by their place on the server
 * before fetching, by Message-ID after it, so a repeated or wider collection
 * never duplicates anything.
 *
 * The run works in slices: the cursor (folder, UIDVALIDITY, last UID) is saved
 * with the progress, and after $sliceSeconds the run requeues itself. Between
 * slices the mailbox lock (comm-connection:N) is free, so receiving new
 * letters is not held up by a long collection.
 */
class MailHistoryJobHandler extends AbstractJobHandler
{
    /** Letters per transaction. */
    public int $batchSize = 20;

    /** Work this long in one process, then let the receiving of new letters in. */
    public int $sliceSeconds = 120;

    /** Pause before the next slice, seconds. */
    public int $sliceDelay = 5;

    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $connection = CmsCommConnection::findOne((int)$context->get('connectionId'));
        if (!$connection || !$connection->is_active) {
            $reporter->setStage('complete', 'Подключение не найдено или выключено');
            return;
        }
        $since = (int)$context->get('since');

        $reporter->setStage('connect', 'Подключение к почтовому серверу');
        $driver = $connection->createDriver(true);
        if (!$driver instanceof MailDriverInterface) {
            throw new JobPermanentException('Драйвер подключения не поддерживает почту.');
        }
        try {
            $session = $driver->openSession();
        } catch (\Throwable $e) {
            throw new JobPermanentException('Не удалось подключиться: '.$e->getMessage());
        }

        $startedAt = time();
        $cursor = $context->getCursor();
        $ingest = new MailIngestService();
        try {
            $folders = array_values(array_filter($session->folders(), static fn($f) => $f['role'] !== null));
            if (!isset($cursor['total'])) {
                // The first slice fixes where each folder ends and counts the letters for the progress bar.
                $cursor = ['total' => 0, 'folders' => [], 'received' => 0];
                foreach ($folders as $folder) {
                    $selected = $session->select($folder['name']);
                    $state = ['uidvalidity' => $selected['uidvalidity'], 'lastUid' => 0, 'done' => false,
                        'stopUid' => $this->stopUid($connection, $session, $folder['name'], $selected['uidvalidity'])];
                    $cursor['folders'][$folder['name']] = $state;
                    $cursor['total'] += count($this->uidsToCollect($session, $since, $state));
                }
                $reporter->setTotal($cursor['total']);
                $context->setCursor($cursor);
            }

            foreach ($folders as $folder) {
                $name = $folder['name'];
                $state = $cursor['folders'][$name] ?? null;
                if (!$state || $state['done']) {
                    continue;
                }
                $reporter->setStage('folder', 'Папка '.$this->folderTitle($name));
                $selected = $session->select($name);
                if ($state['uidvalidity'] !== null && $selected['uidvalidity'] !== null && (int)$state['uidvalidity'] !== (int)$selected['uidvalidity']) {
                    // The server renumbered the folder: scan it again; known letters are skipped by Message-ID.
                    $state['lastUid'] = 0;
                    $state['stopUid'] = $this->stopUid($connection, $session, $name, $selected['uidvalidity']);
                }
                $state['uidvalidity'] = $selected['uidvalidity'];

                $uids = $this->uidsToCollect($session, $since, $state);
                foreach (array_chunk($uids, $this->batchSize) as $chunk) {
                    if ($reporter->isCancelled()) {
                        throw new JobCancelledException('Сбор писем остановлен.');
                    }
                    $cursor['received'] += $this->collect($connection, $session, $ingest, $name, (string)$folder['role'],
                        $state['uidvalidity'] !== null ? (int)$state['uidvalidity'] : null, $chunk, $reporter);
                    $state['lastUid'] = max(array_map('intval', $chunk));
                    $cursor['folders'][$name] = $state;
                    $context->setCursor($cursor);
                    $reporter->heartbeat();

                    if (time() - $startedAt >= $this->sliceSeconds) {
                        $reporter->setStage('pause', 'Собрано писем: '.(int)$cursor['received'].'. Продолжение через несколько секунд');
                        $context->requestRequeue($this->sliceDelay);
                    }
                }
                $state['done'] = true;
                $cursor['folders'][$name] = $state;
                $context->setCursor($cursor);
            }
        } finally {
            $session->close();
        }

        $reporter->setResult(['connectionId' => (int)$connection->id, 'since' => $since, 'received' => (int)$cursor['received']]);
        $reporter->setStage('complete', $cursor['received'] ? 'Собрано писем: '.(int)$cursor['received'] : 'Новых писем за этот срок нет: все уже были в CRM');
    }

    /**
     * The last UID of a folder that belongs to the history. Letters after it
     * are new ones: they are left to the receive job, which shows them unread.
     * Its cursor (CmsCommSyncState) marks the line; a folder never received
     * yet leaves the last initialDays to the first receive.
     */
    private function stopUid(CmsCommConnection $connection, MailSessionInterface $session, string $folder, $uidvalidity): int
    {
        $sync = CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'folder' => $folder])->one();
        if ($sync && (int)$sync->last_uid > 0
            && ($sync->uidvalidity === null || $uidvalidity === null || (int)$sync->uidvalidity === (int)$uidvalidity)) {
            return (int)$sync->last_uid;
        }
        $recent = $session->uidsSince(time() - (int)CmsCommConnection::component()->initialDays * 86400);
        if ($recent) {
            return min(array_map('intval', $recent)) - 1;
        }
        $all = $session->uidsSince(0);

        return $all ? max(array_map('intval', $all)) : 0;
    }

    /** @return int[] UIDs of the period still to collect in the selected folder, ascending */
    private function uidsToCollect(MailSessionInterface $session, int $since, array $state): array
    {
        $uids = array_values(array_filter(array_map('intval', $session->uidsSince($since)),
            static fn(int $uid) => $uid > (int)$state['lastUid'] && $uid <= (int)$state['stopUid']));
        sort($uids);

        return $uids;
    }

    /** @return int letters new to the CRM */
    private function collect(CmsCommConnection $connection, MailSessionInterface $session, MailIngestService $ingest, string $folder,
        string $role, ?int $uidvalidity, array $uids, JobReporterInterface $reporter): int
    {
        // Letters received earlier already have their place on the server: not even fetched.
        $known = array_map('intval', CmsCommMessageMailbox::find()->select('uid')->andWhere([
            'cms_comm_connection_id' => (int)$connection->id,
            'folder' => mb_substr($folder, 0, 190),
            'uid' => $uids,
        ])->andFilterWhere(['uidvalidity' => $uidvalidity])->column());
        $wanted = array_values(array_diff(array_map('intval', $uids), $known));
        if ($known) {
            $reporter->countSkipped(count($known));
        }

        $count = 0;
        if ($wanted) {
            $raws = $session->fetch($wanted);
            $transaction = \Yii::$app->db->beginTransaction();
            try {
                foreach ($wanted as $uid) {
                    if (!isset($raws[$uid])) {
                        $reporter->countSkipped();
                        continue;
                    }
                    try {
                        $ingest->ingest($connection, $role, $folder, $uidvalidity, $uid, $raws[$uid], true);
                        if ($ingest->lastCreated) {
                            $count++;
                            $reporter->countSuccess();
                        } else {
                            $reporter->countSkipped();
                        }
                    } catch (\Throwable $e) {
                        // A broken letter must not stop the collection.
                        $reporter->itemError('letter', $folder.':'.$uid, mb_substr($e->getMessage(), 0, 300));
                    }
                }
                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }
        }
        $reporter->advance(count($uids));

        return $count;
    }

    private function folderTitle(string $folder): string
    {
        $decoded = @mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP');

        return $decoded !== false && $decoded !== '' ? $decoded : $folder;
    }
}
