<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommLabel;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\communication\models\CmsCommSyncState;
use skeeks\cms\models\User;

/**
 * Actions with letters in the mailbox on the server, asked for by an
 * employee: to the trash, to the archive, to spam and back, to an ordinary
 * folder; CRM labels. One operation each for the "Почта" screen and future
 * API tools.
 *
 * The right is "Управление письмами" (`CmsCommAccountAccess::can_manage_messages`)
 * on the mailbox of the copy, administrators too. A move only
 * records where the copy should go (`target_role`): the screen shows the
 * letter there at once, and the job `comm.mail.apply` of the connection
 * moves it on the server (services\MailServerSync), or puts it back with an
 * error. A letter in the trash or spam of all its mailboxes leaves the usual
 * folders (`cms_comm_message.box_state`).
 *
 * One server letter may stand behind copies of several mailboxes of one
 * connection (aliases): they always move together.
 */
class MailboxActions
{
    public const TRASH = 'trash';
    public const ARCHIVE = 'archive';
    public const JUNK = 'junk';
    /** Back to "Входящие" (or "Отправленные" for an outgoing letter): "Не спам", "Восстановить". */
    public const RESTORE = 'restore';
    /** To an ordinary folder of the mailbox. */
    public const FOLDER = 'folder';

    /** Letters per request: "Выбрать все письма папки" is limited by it. */
    public const MAX_LETTERS = 5000;

    /** Server folders offered by "В папку": the inbox, the archive and ordinary folders. */
    private const FOLDER_ROLES = [CmsCommMessageMailbox::ROLE_INBOX, CmsCommMessageMailbox::ROLE_ARCHIVE, CmsCommMessageMailbox::ROLE_OTHER];

    private User $user;
    private int $siteId;
    private ?array $manageable = null;

    public function __construct(User $user, int $siteId)
    {
        $this->user = $user;
        $this->siteId = $siteId;
    }

    public static function actions(): array
    {
        return [
            self::TRASH => 'Удалить',
            self::ARCHIVE => 'В архив',
            self::JUNK => 'Это спам',
            self::RESTORE => 'Вернуть во входящие',
            self::FOLDER => 'В папку',
        ];
    }

    /** @return int[] mailboxes of the site where the employee manages letters */
    public function manageableAccountIds(): array
    {
        if ($this->manageable === null) {
            $query = CmsCommAccount::find()->select('id')->andWhere(['cms_site_id' => $this->siteId])
                ->andWhere(['id' => CmsCommAccountAccess::find()->select('cms_comm_account_id')
                    ->andWhere(['cms_user_id' => (int)$this->user->id, 'can_manage_messages' => 1])]);
            $this->manageable = array_map('intval', $query->column());
        }

        return $this->manageable;
    }

    public function canManageAny(): bool
    {
        return (bool)$this->manageableAccountIds();
    }

    /** Whether the employee may move this letter or change its labels. */
    public function canManage(CmsCommMessage $message): bool
    {
        $accounts = $this->manageableAccountIds();
        if (!$accounts) {
            return false;
        }
        if (in_array((int)$message->cms_comm_account_id, $accounts, true)) {
            return true;
        }
        foreach ($message->mailboxCopies as $copy) {
            if (in_array((int)$copy->cms_comm_account_id, $accounts, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Asks to move letters. The job of each connection does it on the server.
     *
     * @param CmsCommMessage[] $messages letters the employee may read
     * @param string|null $folder server folder for FOLDER (a watched folder of the connection)
     * @return array moved: letters that go there; skipped: letters left as they are, with reasons
     *               (reason => count); connections: ids whose jobs were started
     */
    public function move(array $messages, string $action, ?string $folder = null): array
    {
        if (!isset(self::actions()[$action])) {
            throw new \InvalidArgumentException('Неизвестное действие с письмом.');
        }
        if ($action === self::FOLDER && ($folder === null || $folder === '')) {
            throw new \InvalidArgumentException('Выберите папку.');
        }
        $accounts = $this->manageableAccountIds();
        $result = ['moved' => 0, 'skipped' => [], 'connections' => []];
        $skip = static function (string $reason) use (&$result) {
            $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;
        };

        $changedMessages = [];
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            foreach (array_chunk($messages, 200) as $chunk) {
                $ids = array_map(static fn(CmsCommMessage $m) => (int)$m->id, $chunk);
                $copies = CmsCommMessageMailbox::find()->andWhere(['cms_comm_message_id' => $ids])->all();
                $byMessage = [];
                foreach ($copies as $copy) {
                    $byMessage[(int)$copy->cms_comm_message_id][] = $copy;
                }
                foreach ($chunk as $message) {
                    $own = array_values(array_filter($byMessage[(int)$message->id] ?? [],
                        static fn(CmsCommMessageMailbox $c) => in_array((int)$c->cms_comm_account_id, $accounts, true)));
                    if (!$own) {
                        $skip('нет права «Управление письмами» на ящик письма');
                        continue;
                    }
                    $placed = array_values(array_filter($own, static fn(CmsCommMessageMailbox $c) => $c->hasServerPlace()));
                    if (!$placed) {
                        $skip(array_filter($own, static fn($c) => $c->folder_role === CmsCommMessageMailbox::ROLE_GONE)
                            ? 'письмо уже удалено в почтовом ящике'
                            : 'письмо ещё не появилось в почтовом ящике');
                        continue;
                    }
                    $targetRole = $this->targetRole($action, $message);
                    $changed = false;
                    foreach ($this->withSiblings($placed, $byMessage[(int)$message->id] ?? []) as $copy) {
                        if ($action === self::FOLDER) {
                            $targetRole = $this->serverFolderRoles((int)$copy->cms_comm_connection_id)[(string)$folder] ?? null;
                            if ($targetRole === null) {
                                $skip('такой папки нет в почтовом ящике');
                                continue 2;
                            }
                        }
                        if ($this->ask($copy, $targetRole, $action === self::FOLDER ? $folder : null)) {
                            $changed = true;
                            $result['connections'][(int)$copy->cms_comm_connection_id] = true;
                        }
                    }
                    if ($changed) {
                        $changedMessages[] = (int)$message->id;
                        $result['moved']++;
                    } else {
                        $skip('письмо уже там');
                    }
                }
            }
            self::refreshState($changedMessages);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $result['connections'] = array_keys($result['connections']);
        foreach ($result['connections'] as $connectionId) {
            self::pushApply($connectionId, $this->siteId);
        }

        return $result;
    }

    /**
     * Puts a label on letters or takes it off. Labels are CRM data; where
     * the server keeps own keywords, the job writes them there too.
     *
     * @param CmsCommMessage[] $messages
     * @return array changed: letters whose labels changed; skipped: reason => count
     */
    public function label(array $messages, CmsCommLabel $label, bool $on): array
    {
        if ((int)$label->cms_site_id !== $this->siteId) {
            throw new \InvalidArgumentException('Метка не найдена.');
        }
        $result = ['changed' => 0, 'skipped' => [], 'connections' => []];
        $table = '{{%cms_comm_message_label}}';
        $db = \Yii::$app->db;
        $changed = [];
        foreach ($messages as $message) {
            if (!$this->canManage($message)) {
                $reason = 'нет права «Управление письмами» на ящик письма';
                $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;
                continue;
            }
            $where = ['cms_comm_message_id' => (int)$message->id, 'cms_comm_label_id' => (int)$label->id];
            $has = (new \yii\db\Query())->from($table)->andWhere($where)->exists();
            if ($on && !$has) {
                $db->createCommand()->insert($table, $where + ['created_at' => time(), 'created_by' => (int)$this->user->id])->execute();
            } elseif (!$on && $has) {
                $db->createCommand()->delete($table, $where)->execute();
            } else {
                continue;
            }
            $changed[] = (int)$message->id;
            $result['changed']++;
        }
        if ($changed) {
            CmsCommMessageMailbox::updateAll(['labels_dirty' => 1], ['and',
                ['cms_comm_message_id' => $changed],
                ['not', ['uid' => null]],
                ['not', ['cms_comm_connection_id' => null]],
            ]);
            $connections = array_map('intval', CmsCommMessageMailbox::find()->select('cms_comm_connection_id')->distinct()
                ->andWhere(['cms_comm_message_id' => $changed, 'labels_dirty' => 1])->column());
            foreach ($connections as $connectionId) {
                self::pushApply($connectionId, $this->siteId);
            }
            $result['connections'] = $connections;
        }

        return $result;
    }

    /** Labels of the site, in their order. */
    public function labels(): array
    {
        return CmsCommLabel::find()->andWhere(['cms_site_id' => $this->siteId])
            ->orderBy(['priority' => SORT_ASC, 'name' => SORT_ASC])->all();
    }

    /** Whether the employee may add, rename and remove labels. */
    public function canEditLabels(): bool
    {
        return $this->canManageAny();
    }

    /**
     * Ordinary folders of the connection where letters can go, from the last
     * receive (the job watches every folder): folder => title.
     */
    public function serverFolders(int $connectionId): array
    {
        $result = [];
        foreach (CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => $connectionId])
            ->andWhere(['folder_role' => self::FOLDER_ROLES])
            ->all() as $state) {
            $result[(string)$state->folder] = $state->getTitle();
        }
        uasort($result, static fn($a, $b) => $a === 'Входящие' ? -1 : ($b === 'Входящие' ? 1 : strcasecmp($a, $b)));

        return $result;
    }

    /** @return int[] connections of the copies of these letters in mailboxes the employee manages */
    public function connectionsOf(array $messageIds): array
    {
        if (!$messageIds) {
            return [];
        }

        return array_map('intval', CmsCommMessageMailbox::find()->select('cms_comm_connection_id')->distinct()
            ->andWhere(['cms_comm_message_id' => $messageIds, 'cms_comm_account_id' => $this->manageableAccountIds() ?: [0]])
            ->andWhere(['not', ['cms_comm_connection_id' => null]])->column());
    }

    /**
     * Whether letters are in the trash or spam of all their mailboxes, for
     * the usual folders (cms_comm_message.box_state). A letter without copies
     * (sent from the CRM, not yet found in "Отправленные") stays visible.
     *
     * @param int[] $messageIds
     */
    public static function refreshState(array $messageIds): void
    {
        $messageIds = array_values(array_unique(array_map('intval', $messageIds)));
        foreach (array_chunk($messageIds, 500) as $chunk) {
            $roles = [];
            foreach (CmsCommMessageMailbox::find()->select(['cms_comm_message_id', 'folder_role', 'target_role'])
                ->andWhere(['cms_comm_message_id' => $chunk])->asArray()->all() as $row) {
                $roles[(int)$row['cms_comm_message_id']][] = $row['target_role'] ?: $row['folder_role'];
            }
            $states = [];
            foreach ($chunk as $id) {
                $state = null;
                $list = $roles[$id] ?? [];
                if ($list && !array_diff($list, CmsCommMessageMailbox::REMOVED_ROLES)) {
                    $state = in_array(CmsCommMessageMailbox::ROLE_JUNK, $list, true) ? CmsCommMessageMailbox::ROLE_JUNK : CmsCommMessageMailbox::ROLE_TRASH;
                }
                $states[(string)$state][] = $id;
            }
            foreach ($states as $state => $ids) {
                CmsCommMessage::updateAll(['box_state' => $state === '' ? null : $state], ['id' => $ids]);
            }
        }
    }

    /** Starts the job that does asked-for actions of the connection on the server. */
    public static function pushApply(int $connectionId, int $siteId): void
    {
        \Yii::$app->jobs->push('comm.mail.apply', ['connectionId' => $connectionId], ['siteId' => $siteId]);
    }

    private function targetRole(string $action, CmsCommMessage $message): string
    {
        switch ($action) {
            case self::TRASH:
                return CmsCommMessageMailbox::ROLE_TRASH;
            case self::ARCHIVE:
                return CmsCommMessageMailbox::ROLE_ARCHIVE;
            case self::JUNK:
                return CmsCommMessageMailbox::ROLE_JUNK;
            case self::FOLDER:
                return CmsCommMessageMailbox::ROLE_OTHER;
        }

        return $message->direction === CmsCommMessage::DIRECTION_OUT ? CmsCommMessageMailbox::ROLE_SENT : CmsCommMessageMailbox::ROLE_INBOX;
    }

    /**
     * Records where the copy should go: a role (the job finds the server
     * folder of it) or an exact server folder. Asking for the place where the
     * copy already is on the server cancels a move not done yet.
     */
    private function ask(CmsCommMessageMailbox $copy, string $role, ?string $folder): bool
    {
        $atServer = $folder !== null ? $copy->folder === $folder : $copy->folder_role === $role;
        if ($atServer) {
            $copy->target_role = $copy->target_folder = $copy->target_by = null;
        } elseif ($copy->target_role === $role && $copy->target_folder === $folder) {
            return false;
        } else {
            $copy->target_role = $role;
            $copy->target_folder = $folder;
            $copy->target_by = (int)$this->user->id;
        }
        if (!$copy->isAttributeChanged('target_role') && !$copy->isAttributeChanged('target_folder')) {
            return false;
        }
        $copy->sync_error = null;
        $copy->sync_error_at = null;
        $copy->save(false, ['target_role', 'target_folder', 'target_by', 'sync_error', 'sync_error_at']);

        return true;
    }

    /**
     * Copies of other mailboxes of the same connection that are the same
     * server letter: they move with it.
     *
     * @param CmsCommMessageMailbox[] $placed
     * @param CmsCommMessageMailbox[] $all
     * @return CmsCommMessageMailbox[]
     */
    private function withSiblings(array $placed, array $all): array
    {
        $keys = [];
        foreach ($placed as $copy) {
            $keys[$copy->cms_comm_connection_id.'|'.$copy->folder.'|'.$copy->uid] = true;
        }
        $result = [];
        foreach ($all as $copy) {
            if (isset($keys[$copy->cms_comm_connection_id.'|'.$copy->folder.'|'.$copy->uid])) {
                $result[] = $copy;
            }
        }

        return $result;
    }

    private array $folderRoles = [];

    /** @return array server folder => role, of the folders serverFolders() offers */
    private function serverFolderRoles(int $connectionId): array
    {
        if (!isset($this->folderRoles[$connectionId])) {
            $this->folderRoles[$connectionId] = array_map('strval', CmsCommSyncState::find()->select('folder_role')->indexBy('folder')
                ->andWhere(['cms_comm_connection_id' => $connectionId])
                ->andWhere(['folder_role' => self::FOLDER_ROLES])->column());
        }

        return $this->folderRoles[$connectionId];
    }
}
