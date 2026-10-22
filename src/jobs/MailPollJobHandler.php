<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\mail\MailDriverInterface;
use skeeks\cms\communication\mail\MailManageSessionInterface;
use skeeks\cms\communication\mail\MailSessionInterface;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommSyncState;
use skeeks\cms\communication\services\MailIngestService;
use skeeks\cms\communication\services\MailNotifier;
use skeeks\cms\communication\services\MailServerSync;
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
 *
 * New incoming letters of a run notify employees once, after the run
 * (MailNotifier): never the first receipt of a folder (the last
 * `initialDays` days) or a rescan after the server renumbered the folder.
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
        $fresh = [];
        $synced = null;
        try {
            foreach ($session->folders() as $folder) {
                if (!in_array($folder['role'], CmsCommSyncState::RECEIVE_ROLES, true)) {
                    continue;
                }
                if ($reporter->isCancelled()) {
                    break;
                }
                $received += $this->pollFolder($connection, $session, $ingest, $folder['name'], $folder['role'], $initialDays, $reporter, $fresh);
            }
            if ($session instanceof MailManageSessionInterface && !$reporter->isCancelled()) {
                $synced = $this->syncServer($connection, $session, $reporter);
            }
        } catch (\Throwable $e) {
            $this->saveError($connection, null, $e->getMessage());
            throw $e;
        } finally {
            $session->close();
        }

        $notified = 0;
        if ($fresh) {
            try {
                $notified = (new MailNotifier())->notifyNew($fresh);
            } catch (\Throwable $e) {
                // Notifications never undo received letters.
                \Yii::warning('Уведомления о новых письмах не созданы: '.$e->getMessage(), self::class);
            }
        }

        $reporter->setResult(['connectionId' => (int)$connection->id, 'received' => $received, 'notified' => $notified, 'server' => $synced]);
        $reporter->setStage('complete', $received ? "Получено писем: {$received}" : 'Новых писем нет');
    }

    /**
     * @param int[] $fresh ids of new incoming letters to notify about, added by the method
     */
    private function pollFolder(CmsCommConnection $connection, MailSessionInterface $session, MailIngestService $ingest, string $folder, string $role, int $initialDays, JobReporterInterface $reporter, array &$fresh = []): int
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
        // Only letters arriving after a receipt that already ran are new to people.
        $notify = $role === CmsCommSyncState::ROLE_INBOX && (int)$state->last_uid > 0;
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
                        $message = $ingest->ingest($connection, $role, $folder, $state->uidvalidity !== null ? (int)$state->uidvalidity : null, (int)$uid, $raws[$uid]);
                        if ($notify && $ingest->lastCreated) {
                            $fresh[] = (int)$message->id;
                        }
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

    /**
     * Actions asked for in the CRM that the action job has not done yet, then
     * letters moved or deleted in the mail service itself. A failure here
     * never undoes received letters: it is reported and the next run retries.
     */
    private function syncServer(CmsCommConnection $connection, MailManageSessionInterface $session, JobReporterInterface $reporter): ?array
    {
        $reporter->setStage('server', 'Сверка с почтовым ящиком');
        try {
            $sync = new MailServerSync();
            $applied = $sync->apply($connection, $session);

            return $applied + $sync->reconcile($connection, $session);
        } catch (\Throwable $e) {
            $reporter->itemError('server', 'connection:'.(int)$connection->id, mb_substr($e->getMessage(), 0, 300));
            \Yii::warning('Сверка с почтовым ящиком не выполнена: '.$e->getMessage(), self::class);

            return null;
        }
    }

    private function saveError(CmsCommConnection $connection, ?string $folder, string $message): void
    {
        $states = CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => $connection->id, 'folder_role' => CmsCommSyncState::RECEIVE_ROLES])
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
