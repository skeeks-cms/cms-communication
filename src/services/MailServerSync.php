<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\mail\MailManageSessionInterface;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommLabel;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\communication\models\CmsCommSyncState;
use skeeks\cms\models\CmsWebNotify;

/**
 * The mailbox on the server and its copies in the CRM, inside jobs of one
 * connection (they hold the lock `comm-connection:N`):
 *
 * - apply(): moves asked for in the CRM (MailboxActions) are done on the
 *   server; the copy gets its new folder and UID, or stays where it was with
 *   `sync_error` and a notification to the employee who asked. Labels are
 *   written as IMAP keywords where the folder keeps own keywords.
 * - reconcile(): letters moved or deleted in the mail service itself. Every
 *   folder that holds known copies is checked for their UIDs; a copy gone
 *   from its folder is looked for among letters that appeared in the other
 *   folders since the last look (cursor per folder in cms_comm_sync_state,
 *   matched by Message-ID). Found: the copy takes its new place. Not found:
 *   it is `gone` (deleted in the mail service) and leaves the usual folders.
 *   A letter that comes back to "Входящие" is received again by Message-ID
 *   and its copy takes the new place (MailIngestService::addCopy()), so
 *   letters are never doubled.
 */
class MailServerSync
{
    /** Copies per pass of apply(). */
    public int $batchSize = 300;

    /** Letters of a watched folder looked at when it is watched for the first time. */
    public int $firstLookLimit = 2000;

    /** @var callable|null fn(string $message) progress for the job */
    public $onStage;

    private array $folders = [];

    /**
     * @return array moved, failed, labeled
     */
    public function apply(CmsCommConnection $connection, MailManageSessionInterface $session): array
    {
        $result = ['moved' => 0, 'failed' => 0, 'labeled' => 0];
        $this->loadFolders($session, $connection);

        $seen = [];
        while (true) {
            $copies = CmsCommMessageMailbox::find()
                ->andWhere(['cms_comm_connection_id' => (int)$connection->id])
                ->andWhere(['not', ['target_role' => null]])
                ->andWhere(['not', ['id' => $seen ?: [0]]])
                ->orderBy(['id' => SORT_ASC])->limit($this->batchSize)->all();
            if (!$copies) {
                break;
            }
            foreach ($copies as $copy) {
                $seen[] = (int)$copy->id;
            }
            $groups = [];
            foreach ($copies as $copy) {
                $target = $this->targetFolder($copy, $session, $connection);
                $groups[$copy->folder."\n".$copy->uidvalidity."\n".$target."\n".$copy->target_role][] = $copy;
            }
            foreach ($groups as $key => $group) {
                [$source, , $target] = explode("\n", $key);
                $done = $this->moveGroup($session, $source, $target, $group);
                $result['moved'] += $done;
                $result['failed'] += count(array_unique(array_map(static fn($c) => (int)$c->cms_comm_message_id, $group))) - $done;
            }
        }

        $result['labeled'] = $this->writeLabels($connection, $session);

        return $result;
    }

    /**
     * Finds letters moved or deleted in the mail service. Runs after the
     * receive job took new letters of "Входящие" and "Отправленные".
     *
     * @return array moved (found in another folder), gone (not found)
     */
    public function reconcile(CmsCommConnection $connection, MailManageSessionInterface $session): array
    {
        $this->loadFolders($session, $connection);
        $candidates = $this->missingCopies($connection, $session);

        // Letters that appeared in watched folders since the last look.
        $found = [];
        $byId = [];
        foreach ($candidates as $copy) {
            if ($copy->message && strpos((string)$copy->message->external_id, 'sx-noid.') !== 0) {
                $byId[strtolower((string)$copy->message->external_id)][] = $copy;
            }
        }
        $oldest = null;
        foreach ($candidates as $copy) {
            $at = (int)($copy->message->message_at ?? 0);
            $oldest = $oldest === null ? $at : min($oldest, $at);
        }
        foreach (CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id])
            ->andWhere(['not', ['folder_role' => CmsCommSyncState::RECEIVE_ROLES]])->all() as $state) {
            $status = $session->status((string)$state->folder);
            if ($status['uidvalidity'] !== null && $state->uidvalidity !== null && (int)$state->uidvalidity !== (int)$status['uidvalidity']) {
                $state->last_uid = 0;
            }
            $top = max(0, (int)$status['uidnext'] - 1);
            $uids = [];
            if ($byId && $top > (int)$state->last_uid) {
                if ((int)$state->last_uid > 0) {
                    $uids = $session->uidsAfterIn((string)$state->folder, (int)$state->last_uid);
                } elseif ($oldest) {
                    // The first look: letters moved there before the folder was watched.
                    $uids = array_slice($session->uidsSinceIn((string)$state->folder, $oldest - 2 * 86400), -$this->firstLookLimit);
                }
            }
            if ($uids) {
                foreach ($session->messageIds((string)$state->folder, $uids) as $uid => $messageId) {
                    foreach ($byId[strtolower($messageId)] ?? [] as $copy) {
                        if (isset($found[(int)$copy->id])) {
                            continue;
                        }
                        $this->place($copy, (string)$state->folder, $status['uidvalidity'], (int)$uid, (string)$state->folder_role);
                        $found[(int)$copy->id] = (int)$copy->cms_comm_message_id;
                    }
                }
            }
            $state->uidvalidity = $status['uidvalidity'];
            $state->uidnext = $status['uidnext'];
            $state->last_uid = max((int)$state->last_uid, $top);
            $state->last_success_at = time();
            $state->save(false);
        }

        $gone = [];
        foreach ($candidates as $copy) {
            if (!isset($found[(int)$copy->id])) {
                $copy->folder_role = CmsCommMessageMailbox::ROLE_GONE;
                $copy->save(false, ['folder_role']);
                $gone[] = (int)$copy->cms_comm_message_id;
            }
        }
        MailboxActions::refreshState(array_merge(array_values($found), $gone));

        return ['moved' => count($found), 'gone' => count($gone)];
    }

    /**
     * Server folders of the connection: every folder other than the receive
     * ones gets its watch row; rows of folders that no longer exist go.
     */
    public function loadFolders(MailManageSessionInterface $session, CmsCommConnection $connection): array
    {
        if (isset($this->folders[(int)$connection->id])) {
            return $this->folders[(int)$connection->id];
        }
        $list = $session->folderList();
        $states = CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id])->indexBy('folder')->all();
        $names = [];
        foreach ($list as $folder) {
            $names[$folder['name']] = true;
            $role = $folder['role'] ?: CmsCommMessageMailbox::ROLE_OTHER;
            $state = $states[$folder['name']] ?? null;
            if (in_array($role, CmsCommSyncState::RECEIVE_ROLES, true)) {
                // Receive cursors are created by the receive job; here only the delimiter.
                if ($state && $state->delimiter !== $folder['delimiter']) {
                    $state->delimiter = $folder['delimiter'];
                    $state->save(false, ['delimiter', 'updated_at']);
                }
                continue;
            }
            if (!$state) {
                $state = new CmsCommSyncState(['cms_comm_connection_id' => (int)$connection->id, 'folder' => $folder['name'], 'last_uid' => 0]);
            } elseif (in_array($state->folder_role, CmsCommSyncState::RECEIVE_ROLES, true)) {
                continue;
            }
            $state->folder_role = $role;
            $state->delimiter = $folder['delimiter'];
            if ($state->isNewRecord || $state->getDirtyAttributes()) {
                $state->save(false);
            }
        }
        foreach ($states as $name => $state) {
            if (!isset($names[$name]) && !in_array($state->folder_role, CmsCommSyncState::RECEIVE_ROLES, true)) {
                $state->delete();
            }
        }

        return $this->folders[(int)$connection->id] = $list;
    }

    /**
     * Copies whose letter is no longer in its server folder.
     *
     * @return CmsCommMessageMailbox[]
     */
    private function missingCopies(CmsCommConnection $connection, MailManageSessionInterface $session): array
    {
        $existing = array_column($this->folders[(int)$connection->id] ?? [], 'name', 'name');
        $folders = CmsCommMessageMailbox::find()->select('folder')->distinct()
            ->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'target_role' => null])
            ->andWhere(['not', ['uid' => null]])->andWhere(['not', ['folder' => null]])
            ->andWhere(['or', ['folder_role' => null], ['!=', 'folder_role', CmsCommMessageMailbox::ROLE_GONE]])
            ->column();
        $missing = [];
        foreach ($folders as $folder) {
            $query = CmsCommMessageMailbox::find()->with('message')
                ->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'folder' => $folder, 'target_role' => null])
                ->andWhere(['not', ['uid' => null]])
                ->andWhere(['or', ['folder_role' => null], ['!=', 'folder_role', CmsCommMessageMailbox::ROLE_GONE]]);
            if (!isset($existing[$folder])) {
                // The folder itself was removed or renamed in the mail service.
                foreach ($query->all() as $copy) {
                    $missing[] = $copy;
                }
                continue;
            }
            $status = $session->status((string)$folder);
            $copies = $query->andWhere(['uidvalidity' => $status['uidvalidity']])->indexBy('id')->all();
            if (!$copies) {
                continue;
            }
            $present = array_flip($session->existingUids((string)$folder, array_map(static fn($c) => (int)$c->uid, $copies)));
            foreach ($copies as $copy) {
                if (!isset($present[(int)$copy->uid])) {
                    $missing[] = $copy;
                }
            }
        }

        return $missing;
    }

    /** The copy is now in this server folder under this UID. */
    private function place(CmsCommMessageMailbox $copy, string $folder, ?int $uidvalidity, ?int $uid, string $role): void
    {
        $copy->folder = mb_substr($folder, 0, 190);
        $copy->uidvalidity = $uidvalidity;
        $copy->uid = $uid;
        $copy->folder_role = $role;
        $copy->save(false, ['folder', 'uidvalidity', 'uid', 'folder_role']);
    }

    /** Server folder a copy should go to; '' when the mailbox has none (the move fails). */
    private function targetFolder(CmsCommMessageMailbox $copy, MailManageSessionInterface $session, CmsCommConnection $connection): string
    {
        if ($copy->target_folder) {
            return (string)$copy->target_folder;
        }
        $role = (string)$copy->target_role;
        foreach ($this->folders[(int)$connection->id] ?? [] as $folder) {
            if ($folder['role'] === $role) {
                return (string)$folder['name'];
            }
        }
        if ($role === CmsCommMessageMailbox::ROLE_ARCHIVE) {
            // Like web mail services: the archive folder appears with the first archived letter.
            $name = (string)mb_convert_encoding('Архив', 'UTF7-IMAP', 'UTF-8');
            $session->createFolder($name);
            unset($this->folders[(int)$connection->id]);
            $this->loadFolders($session, $connection);
            foreach ($this->folders[(int)$connection->id] as &$folder) {
                if ($folder['name'] === $name) {
                    $folder['role'] = CmsCommMessageMailbox::ROLE_ARCHIVE;
                }
            }
            unset($folder);
            CmsCommSyncState::updateAll(['folder_role' => CmsCommMessageMailbox::ROLE_ARCHIVE],
                ['cms_comm_connection_id' => (int)$connection->id, 'folder' => $name]);

            return $name;
        }

        return '';
    }

    /**
     * Moves one group of copies (one source folder, one target) and records
     * the result.
     *
     * @param CmsCommMessageMailbox[] $copies
     * @return int letters moved
     */
    private function moveGroup(MailManageSessionInterface $session, string $source, string $target, array $copies): int
    {
        $role = (string)$copies[0]->target_role;
        $titles = CmsCommMessageMailbox::roleTitles();
        $messageIds = array_values(array_unique(array_map(static fn($c) => (int)$c->cms_comm_message_id, $copies)));
        try {
            if ($target === '') {
                throw new \DomainException('В почтовом ящике нет папки «'.($titles[$role] ?? $role).'».');
            }
            if ($target === $source) {
                foreach ($copies as $copy) {
                    $copy->folder_role = $role;
                    $this->finish($copy);
                }
                MailboxActions::refreshState($messageIds);

                return count($messageIds);
            }
            $status = $session->status($source);
            $uids = [];
            foreach ($copies as $copy) {
                if ($status['uidvalidity'] !== null && (int)$copy->uidvalidity !== (int)$status['uidvalidity']) {
                    $this->fail($copy, 'Папку «'.CmsCommSyncState::folderTitle($source).'» на сервере пересоздали: письмо не найдено. Обновите письма.');
                    continue;
                }
                $uids[(int)$copy->uid] = true;
            }
            $uids = array_keys($uids);
            $moved = $uids ? $this->moveOnServer($session, $source, array_map('intval', $uids), $target, $role) : ['uids' => [], 'missing' => [], 'uidvalidity' => null];
            foreach ($copies as $copy) {
                if (!$copy->target_role) {
                    continue;
                }
                if (in_array((int)$copy->uid, $moved['missing'], true)) {
                    $this->fail($copy, 'Письма уже нет в папке «'.CmsCommSyncState::folderTitle($source).'»: его переместили или удалили в почтовом ящике. Обновите письма.');
                    continue;
                }
                $newRole = $copy->target_role;
                $copy->folder = mb_substr($target, 0, 190);
                $copy->uidvalidity = $moved['uidvalidity'];
                $copy->uid = $moved['uids'][(int)$copy->uid] ?? null;
                $copy->folder_role = $newRole;
                $this->finish($copy);
            }
        } catch (\Throwable $e) {
            $text = $e instanceof \DomainException ? $e->getMessage() : 'Почтовый сервер не выполнил действие: '.$e->getMessage();
            foreach ($copies as $copy) {
                if ($copy->target_role) {
                    $this->fail($copy, $text);
                }
            }
            \Yii::warning('Действие с письмами не выполнено: '.$e->getMessage(), self::class);
        }
        MailboxActions::refreshState($messageIds);

        $failed = [];
        foreach ($copies as $copy) {
            if ($copy->sync_error) {
                $failed[(int)$copy->cms_comm_message_id] = true;
            }
        }

        return count($messageIds) - count($failed);
    }

    /**
     * @return array uidvalidity, uids: old => new|null, missing
     */
    private function moveOnServer(MailManageSessionInterface $session, string $source, array $uids, string $target, string $role): array
    {
        // Spam marks help servers that learn from flags; a server without own keywords just moves.
        if (in_array($role, [CmsCommMessageMailbox::ROLE_JUNK, CmsCommMessageMailbox::ROLE_INBOX, CmsCommMessageMailbox::ROLE_SENT], true)) {
            try {
                if ($session->supportsKeywords($source)) {
                    $junk = $role === CmsCommMessageMailbox::ROLE_JUNK;
                    $session->storeFlags($source, $uids, [$junk ? '$Junk' : '$NotJunk'], [$junk ? '$NotJunk' : '$Junk']);
                }
            } catch (\Throwable $e) {
                \Yii::warning('Отметка спама не записана: '.$e->getMessage(), self::class);
            }
        }

        return $session->move($source, $uids, $target);
    }

    private function finish(CmsCommMessageMailbox $copy): void
    {
        $copy->target_role = $copy->target_folder = $copy->target_by = null;
        $copy->sync_error = null;
        $copy->sync_error_at = null;
        $copy->save(false);
    }

    /** The copy stays where it is on the server; the employee who asked learns why. */
    private function fail(CmsCommMessageMailbox $copy, string $error): void
    {
        $by = (int)$copy->target_by;
        $copy->target_role = $copy->target_folder = $copy->target_by = null;
        $copy->sync_error = mb_substr($error, 0, 500);
        $copy->sync_error_at = time();
        $copy->save(false);
        if ($by && !isset($this->notified[$by][(int)$copy->cms_comm_message_id])) {
            $this->notified[$by][(int)$copy->cms_comm_message_id] = true;
            try {
                $notify = new CmsWebNotify();
                $notify->cms_user_id = $by;
                $notify->name = mb_substr('Письмо «'.($copy->message ? $copy->message->getAsText() : '#'.$copy->cms_comm_message_id).'» не перенесено: '.$error, 0, 250);
                $notify->model_code = MailNotifier::MODEL_CODE;
                $notify->model_id = (int)$copy->cms_comm_message_id;
                $notify->save();
            } catch (\Throwable $e) {
                \Yii::warning('Уведомление об ошибке не создано: '.$e->getMessage(), self::class);
            }
        }
    }

    private array $notified = [];

    /**
     * Labels as IMAP keywords on letters whose labels changed: added where
     * the CRM has them, removed where the CRM took them off. Folders without
     * own keywords keep labels in the CRM only.
     *
     * @return int letters written
     */
    private function writeLabels(CmsCommConnection $connection, MailManageSessionInterface $session): int
    {
        $count = 0;
        $copies = CmsCommMessageMailbox::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'labels_dirty' => 1, 'target_role' => null])
            ->orderBy(['id' => SORT_ASC])->limit(2000)->all();
        $byFolder = [];
        foreach ($copies as $copy) {
            if (!$copy->hasServerPlace()) {
                CmsCommMessageMailbox::updateAll(['labels_dirty' => 0], ['id' => $copy->id]);
                continue;
            }
            $byFolder[(string)$copy->folder][] = $copy;
        }
        foreach ($byFolder as $folder => $list) {
            try {
                if ($session->supportsKeywords($folder)) {
                    $wanted = [];
                    foreach ((new \yii\db\Query())->select(['cms_comm_message_id', 'cms_comm_label_id'])->from('{{%cms_comm_message_label}}')
                        ->andWhere(['cms_comm_message_id' => array_map(static fn($c) => (int)$c->cms_comm_message_id, $list)])->all() as $row) {
                        $wanted[(int)$row['cms_comm_message_id']][] = CmsCommLabel::keywordFor((int)$row['cms_comm_label_id']);
                    }
                    $flags = $session->flags($folder, array_map(static fn($c) => (int)$c->uid, $list));
                    foreach ($list as $copy) {
                        $have = array_values(array_filter($flags[(int)$copy->uid] ?? [], static fn($f) => stripos($f, 'SkeekS_label_') === 0));
                        $want = $wanted[(int)$copy->cms_comm_message_id] ?? [];
                        $add = array_values(array_diff($want, $have));
                        $remove = array_values(array_diff($have, $want));
                        if ($add || $remove) {
                            $session->storeFlags($folder, [(int)$copy->uid], $add, $remove);
                            $count++;
                        }
                    }
                }
                CmsCommMessageMailbox::updateAll(['labels_dirty' => 0], ['id' => array_map(static fn($c) => (int)$c->id, $list)]);
            } catch (\Throwable $e) {
                // Labels stay in the CRM; the next run tries again.
                \Yii::warning('Метки писем не записаны на сервер: '.$e->getMessage(), self::class);
            }
        }

        return $count;
    }
}
