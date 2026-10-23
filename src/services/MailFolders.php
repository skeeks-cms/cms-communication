<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\communication\models\queries\CmsCommMessageQuery;
use skeeks\cms\models\User;
use yii\db\Expression;

/**
 * Folders of the "Почта" section for one employee, built on top of
 * CmsCommMessageQuery::forManager(): a folder only narrows what the employee
 * may read anyway. Read marks are personal (cms_comm_message_read).
 */
class MailFolders
{
    public const ALL = 'all';
    public const CLIENTS = 'clients';
    public const UNLINKED = 'unlinked';
    /** Conversations the employee is responsible for ("Мне назначено"). */
    public const ASSIGNED = 'assigned';

    /** List filters (MailFolders::filter()): responsible person and CRM links. */
    public const ASSIGNEE_ME = 'me';
    public const ASSIGNEE_NONE = 'none';
    public const LINKED_YES = 'yes';
    public const LINKED_NO = 'no';

    /** The employee's drafts (MailDrafts): its counter is the number of drafts. */
    public const DRAFTS = 'drafts';

    private User $user;
    private int $siteId;

    public function __construct(User $user, int $siteId)
    {
        $this->user = $user;
        $this->siteId = $siteId;
    }

    /**
     * Mailboxes whose folders the employee sees as a whole: read "всё" in
     * their own access rows (administrators too).
     */
    public function accounts(): array
    {
        return CmsCommAccount::find()->andWhere(['cms_site_id' => $this->siteId])->orderBy(['address' => SORT_ASC])
            ->andWhere(['id' => CmsCommAccountAccess::find()->select('cms_comm_account_id')
                ->andWhere(['cms_user_id' => $this->user->id, 'read_level' => CmsCommAccountAccess::READ_ALL])])
            ->all();
    }

    /**
     * @return array[] each: key, title, group (null or mailbox address), unread
     */
    public function folders(): array
    {
        $folders = [['key' => self::ALL, 'title' => 'Все письма', 'group' => null, 'icon' => 'mail']];
        // "Ответственный" is hidden unless the site switches it on (CommunicationComponent::$showAssignee).
        if (\Yii::$app->communication->showAssignee) {
            $folders[] = ['key' => self::ASSIGNED, 'title' => 'Мне назначено', 'group' => null, 'icon' => 'user'];
        }
        $folders[] = ['key' => self::CLIENTS, 'title' => 'Мои клиенты', 'group' => null, 'icon' => 'users'];
        $accounts = $this->accounts();
        if ($accounts) {
            $folders[] = ['key' => self::UNLINKED, 'title' => 'Без привязки', 'group' => null, 'icon' => 'help'];
        }
        foreach ($accounts as $account) {
            $folders[] = ['key' => 'in:'.$account->id, 'title' => 'Входящие', 'group' => $account->address, 'icon' => 'inbox'];
            $folders[] = ['key' => 'out:'.$account->id, 'title' => 'Отправленные', 'group' => $account->address, 'icon' => 'send'];
            $folders[] = ['key' => 'archive:'.$account->id, 'title' => 'Архив', 'group' => $account->address, 'icon' => 'archive'];
            $folders[] = ['key' => 'junk:'.$account->id, 'title' => 'Спам', 'group' => $account->address, 'icon' => 'spam'];
            $folders[] = ['key' => 'trash:'.$account->id, 'title' => 'Корзина', 'group' => $account->address, 'icon' => 'trash'];
        }
        foreach ($folders as &$folder) {
            // Like web mail: unread letters of the trash are not counted.
            $folder['unread'] = strpos($folder['key'], 'trash:') === 0 ? 0
                : (int)$this->query($folder['key'])->andWhere($this->unreadCondition())->count();
        }
        unset($folder);
        // Right after "Мои клиенты" / "Без привязки", above the mailboxes.
        $drafts = ['key' => self::DRAFTS, 'title' => 'Черновики', 'group' => null, 'icon' => 'file',
            'unread' => (new MailDrafts($this->user, $this->siteId))->count()];
        $position = 0;
        foreach ($folders as $i => $folder) {
            if ($folder['group'] === null) {
                $position = $i + 1;
            }
        }
        array_splice($folders, $position, 0, [$drafts]);

        return $folders;
    }

    public function isKnown(string $key): bool
    {
        foreach ([self::ALL, self::ASSIGNED, self::CLIENTS, self::UNLINKED, self::DRAFTS] as $fixed) {
            if ($key === $fixed) {
                return true;
            }
        }
        if (preg_match('/^(in|out|archive|junk|trash):(\d+)$/', $key, $m)) {
            foreach ($this->accounts() as $account) {
                if ((int)$account->id === (int)$m[2]) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Letters of a folder the employee may read, newest first. */
    public function query(string $key, string $search = ''): CmsCommMessageQuery
    {
        $t = CmsCommMessage::tableName();
        $messageId = new Expression($t.'.id');
        // "Почта" shows letters; SMS and messenger messages live in the feeds and conversations.
        $query = CmsCommMessage::find()->cmsSite($this->siteId)->forManager($this->user)
            ->andWhere([$t.'.channel' => CmsCommAccount::CHANNEL_EMAIL])
            ->orderBy([$t.'.message_at' => SORT_DESC, $t.'.id' => SORT_DESC]);
        $hasLinks = ['exists', CmsCommLink::find()->andWhere([CmsCommLink::tableName().'.cms_comm_message_id' => $messageId])];

        $box = preg_match('/^(in|out|archive|junk|trash):(\d+)$/', $key, $m) ? $m : null;
        // Letters in the trash or spam of all their mailboxes live only in those folders.
        if (!$box || $box[1] === 'in' || $box[1] === 'out') {
            $query->andWhere([$t.'.box_state' => null]);
        }
        if ($key === self::ASSIGNED) {
            $query->assignedTo((int)$this->user->id);
        } elseif ($key === self::CLIENTS) {
            $query->linkedToVisible($this->user);
        } elseif ($key === self::UNLINKED) {
            $query->andWhere(['not', $hasLinks])->andWhere([$t.'.is_internal' => 0, $t.'.direction' => CmsCommMessage::DIRECTION_IN]);
        } elseif ($box && ($box[1] === 'in' || $box[1] === 'out')) {
            // Archived or moved to a folder of this mailbox: out of its "Входящие" and "Отправленные".
            $query->inAccount((int)$box[2])->inAccountRoles((int)$box[2], CmsCommMessageMailbox::AWAY_ROLES, false)
                ->andWhere([$t.'.direction' => $box[1] === 'in' ? CmsCommMessage::DIRECTION_IN : CmsCommMessage::DIRECTION_OUT]);
        } elseif ($box) {
            $roles = ['archive' => [CmsCommMessageMailbox::ROLE_ARCHIVE], 'junk' => [CmsCommMessageMailbox::ROLE_JUNK],
                'trash' => [CmsCommMessageMailbox::ROLE_TRASH, CmsCommMessageMailbox::ROLE_GONE]][$box[1]];
            $query->inAccountRoles((int)$box[2], $roles);
        }

        $this->search($query, $search);

        return $query;
    }

    /**
     * Search over whole conversations: letters of conversations where every
     * word of the query is found in some letter — sender, recipients,
     * subject or text (MailSearch). Matches count only in letters the
     * employee may read: an unseen letter must not reveal its words.
     */
    public function search(CmsCommMessageQuery $query, string $search): CmsCommMessageQuery
    {
        $t = CmsCommMessage::tableName();
        foreach (MailSearch::terms($search) as $term) {
            $conversations = CmsCommMessage::find()->select($t.'.cms_comm_conversation_id')
                ->cmsSite($this->siteId)->forManager($this->user)
                ->andWhere([$t.'.id' => MailSearch::messages($term, $this->siteId)]);
            $query->andWhere([$t.'.cms_comm_conversation_id' => $conversations]);
        }

        return $query;
    }

    /** Letters with this label. */
    public function withLabel(CmsCommMessageQuery $query, int $labelId): CmsCommMessageQuery
    {
        return $query->andWhere([CmsCommMessage::tableName().'.id' => (new \yii\db\Query())->select('cms_comm_message_id')
            ->from('{{%cms_comm_message_label}}')->andWhere(['cms_comm_label_id' => $labelId])]);
    }

    /**
     * Narrows a folder: $assignee — "me", "none" or an employee id (the
     * person responsible for the conversation); $linked — "yes" (linked to
     * the CRM) or "no" ("не разобрано": no CRM record yet). Unknown values
     * are ignored.
     */
    public function filter(CmsCommMessageQuery $query, string $assignee = '', string $linked = ''): CmsCommMessageQuery
    {
        if ($assignee === self::ASSIGNEE_ME) {
            $query->assignedTo((int)$this->user->id);
        } elseif ($assignee === self::ASSIGNEE_NONE) {
            $query->assignedTo(null);
        } elseif (ctype_digit($assignee) && (int)$assignee > 0) {
            $query->assignedTo((int)$assignee);
        }
        if ($linked === self::LINKED_YES) {
            $query->linked(true);
        } elseif ($linked === self::LINKED_NO) {
            $query->linked(false);
        }

        return $query;
    }

    /**
     * Employees responsible for conversations of letters this employee may
     * read: the choices of the "Ответственный" filter.
     *
     * @return array id => short name
     */
    public function assignees(): array
    {
        $ids = CmsCommMessage::find()->cmsSite($this->siteId)->forManager($this->user)
            ->select(CmsCommMessage::tableName().'.cms_comm_conversation_id');
        $users = \skeeks\cms\models\CmsUser::find()
            ->andWhere(['id' => \skeeks\cms\communication\models\CmsCommConversation::find()->select('assigned_user_id')->distinct()
                ->andWhere(['id' => $ids])->andWhere(['not', ['assigned_user_id' => null]])])
            ->all();
        $result = [];
        foreach ($users as $user) {
            if ((int)$user->id !== (int)$this->user->id) {
                $result[(int)$user->id] = (string)$user->shortDisplayName;
            }
        }
        asort($result);

        return $result;
    }

    /** Incoming letters without the employee's read mark. */
    public function unreadCondition(): array
    {
        $t = CmsCommMessage::tableName();

        return ['and',
            [$t.'.direction' => CmsCommMessage::DIRECTION_IN],
            // Letters collected from the mailbox history count as read.
            [$t.'.is_history' => 0],
            ['not', ['exists', (new \yii\db\Query())->from('{{%cms_comm_message_read}} r')
                ->andWhere(new Expression('r.cms_comm_message_id = '.$t.'.id'))
                ->andWhere(['r.cms_user_id' => (int)$this->user->id])]],
        ];
    }

    /** @return int[] ids of these letters the employee has read */
    public function readIds(array $messageIds): array
    {
        if (!$messageIds) {
            return [];
        }

        return array_map('intval', (new \yii\db\Query())->select('cms_comm_message_id')->from('{{%cms_comm_message_read}}')
            ->andWhere(['cms_user_id' => (int)$this->user->id, 'cms_comm_message_id' => $messageIds])->column());
    }

    public function markUnread(CmsCommMessage $message): void
    {
        \Yii::$app->db->createCommand()->delete('{{%cms_comm_message_read}}', [
            'cms_comm_message_id' => (int)$message->id,
            'cms_user_id' => (int)$this->user->id,
        ])->execute();
    }

    public function markRead(CmsCommMessage $message): void
    {
        \Yii::$app->db->createCommand()->upsert('{{%cms_comm_message_read}}', [
            'cms_comm_message_id' => (int)$message->id,
            'cms_user_id' => (int)$this->user->id,
            'read_at' => time(),
        ], false)->execute();
    }
}
