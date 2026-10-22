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
use skeeks\cms\communication\models\CmsCommSyncState;
use skeeks\cms\communication\services\MailIngestService;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobPermanentException;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Receives new letters of one connection: the inbox and the sent folder.
 *
 * The folder cursor (UIDVALIDITY, last UID) is committed in the same
 * transaction as the letters of each batch, so a crash never skips or
 * duplicates letters. A first run takes the last `initialDays` days.
 * A letter that cannot be parsed is reported and skipped.
 */
class MailPollJobHandler extends AbstractJobHandler
{
    /** Letters per transaction. */
    public int $batchSize = 20;

    /** Letters per folder and run; the rest arrive with the next run. */
    public int $maxPerFolder = 300;

    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $connection = CmsCommConnection::findOne((int)$context->get('connectionId'));
        if (!$connection || !$connection->is_active) {
            $reporter->setStage('complete', 'Подключение не найдено или выключено');
            return;
        }

        $reporter->setStage('connect', 'Подключение к почтовому серверу');
        $driver = $connection->createDriver(true);
        if (!$driver instanceof MailDriverInterface) {
            throw new JobPermanentException('Драйвер подключения не поддерживает почту.');
        }

        try {
            $session = $driver->openSession();
        } catch (\Throwable $e) {
            $this->saveError($connection, null, $e->getMessage());
            throw new JobPermanentException('Не удалось подключиться: '.$e->getMessage());
        }

        $ingest = new MailIngestService();
        $initialDays = (int)CmsCommConnection::component()->initialDays;
        $received = 0;
        try {
            foreach ($session->folders() as $folder) {
                if ($folder['role'] === null) {
                    continue;
                }
                if ($reporter->isCancelled()) {
                    break;
                }
                $received += $this->pollFolder($connection, $session, $ingest, $folder['name'], $folder['role'], $initialDays, $reporter);
            }
        } catch (\Throwable $e) {
            $this->saveError($connection, null, $e->getMessage());
            throw $e;
        } finally {
            $session->close();
        }

        $reporter->setResult(['connectionId' => (int)$connection->id, 'received' => $received]);
        $reporter->setStage('complete', $received ? "Получено писем: {$received}" : 'Новых писем нет');
    }

    private function pollFolder(CmsCommConnection $connection, MailSessionInterface $session, MailIngestService $ingest, string $folder, string $role, int $initialDays, JobReporterInterface $reporter): int
    {
        $state = CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => $connection->id, 'folder' => $folder])->one()
            ?? new CmsCommSyncState(['cms_comm_connection_id' => $connection->id, 'folder' => $folder, 'last_uid' => 0]);
        $state->folder_role = $role;

        $reporter->setStage('folder', 'Папка '.$this->folderTitle($folder));
        $selected = $session->select($folder);
        if ($state->uidvalidity !== null && $selected['uidvalidity'] !== null && (int)$state->uidvalidity !== (int)$selected['uidvalidity']) {
            // The server renumbered the folder: scan the window again; known letters are skipped by Message-ID.
            $state->last_uid = 0;
        }
        $state->uidvalidity = $selected['uidvalidity'];

        $uids = (int)$state->last_uid > 0
            ? $session->uidsAfter((int)$state->last_uid)
            : $session->uidsSince(time() - $initialDays * 86400);
        $uids = array_slice($uids, 0, $this->maxPerFolder);

        $count = 0;
        foreach (array_chunk($uids, $this->batchSize) as $chunk) {
            if ($reporter->isCancelled()) {
                break;
            }
            $raws = $session->fetch($chunk);
            $transaction = \Yii::$app->db->beginTransaction();
            try {
                foreach ($chunk as $uid) {
                    if (!isset($raws[$uid])) {
                        continue;
                    }
                    try {
                        $ingest->ingest($connection, $role, $folder, $state->uidvalidity !== null ? (int)$state->uidvalidity : null, (int)$uid, $raws[$uid]);
                        $count++;
                        $reporter->countSuccess();
                    } catch (\Throwable $e) {
                        // A broken letter must not block the folder.
                        $reporter->itemError('letter', $folder.':'.$uid, mb_substr($e->getMessage(), 0, 300));
                    }
                }
                $state->last_uid = max((int)$state->last_uid, max($chunk));
                $state->last_success_at = time();
                $state->last_error = null;
                $state->save(false);
                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }
            $reporter->advance(count($chunk));
            $reporter->heartbeat();
        }

        if (!$uids) {
            $state->last_success_at = time();
            $state->last_error = null;
            $state->save(false);
        }

        return $count;
    }

    private function saveError(CmsCommConnection $connection, ?string $folder, string $message): void
    {
        $states = CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => $connection->id])
            ->andFilterWhere(['folder' => $folder])->all();
        if (!$states) {
            $states = [new CmsCommSyncState(['cms_comm_connection_id' => $connection->id, 'folder' => 'INBOX', 'folder_role' => CmsCommSyncState::ROLE_INBOX, 'last_uid' => 0])];
        }
        foreach ($states as $state) {
            $state->last_error = mb_substr($message, 0, 1000);
            $state->last_error_at = time();
            $state->save(false);
        }
    }

    private function folderTitle(string $folder): string
    {
        $decoded = @mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP');

        return $decoded !== false && $decoded !== '' ? $decoded : $folder;
    }
}
