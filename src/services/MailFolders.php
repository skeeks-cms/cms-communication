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
use skeeks\cms\communication\models\queries\CmsCommMessageQuery;
use skeeks\cms\models\User;
use skeeks\cms\rbac\CmsManager;
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

    private User $user;
    private int $siteId;
    private bool $isAdmin;

    public function __construct(User $user, int $siteId)
    {
        $this->user = $user;
        $this->siteId = $siteId;
        $this->isAdmin = \Yii::$app->authManager->checkAccess($user->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS);
    }

    /** Mailboxes whose folders the employee sees as a whole. */
    public function accounts(): array
    {
        $query = CmsCommAccount::find()->andWhere(['cms_site_id' => $this->siteId])->orderBy(['address' => SORT_ASC]);
        if (!$this->isAdmin) {
            $query->andWhere(['id' => CmsCommAccountAccess::find()->select('cms_comm_account_id')
                ->andWhere(['cms_user_id' => $this->user->id, 'read_level' => CmsCommAccountAccess::READ_ALL])]);
        }

        return $query->all();
    }

    /**
     * @return array[] each: key, title, group (null or mailbox address), unread
     */
    public function folders(): array
    {
        $folders = [
            ['key' => self::ALL, 'title' => 'Все письма', 'group' => null, 'icon' => 'mail'],
            ['key' => self::CLIENTS, 'title' => 'Мои клиенты', 'group' => null, 'icon' => 'users'],
        ];
        $accounts = $this->accounts();
        if ($this->isAdmin || $accounts) {
            $folders[] = ['key' => self::UNLINKED, 'title' => 'Без привязки', 'group' => null, 'icon' => 'help'];
        }
        foreach ($accounts as $account) {
            $folders[] = ['key' => 'in:'.$account->id, 'title' => 'Входящие', 'group' => $account->address, 'icon' => 'inbox'];
            $folders[] = ['key' => 'out:'.$account->id, 'title' => 'Отправленные', 'group' => $account->address, 'icon' => 'send'];
        }
        foreach ($folders as &$folder) {
            $folder['unread'] = (int)$this->query($folder['key'])->andWhere($this->unreadCondition())->count();
        }
        unset($folder);

        return $folders;
    }

    public function isKnown(string $key): bool
    {
        foreach ([self::ALL, self::CLIENTS, self::UNLINKED] as $fixed) {
            if ($key === $fixed) {
                return true;
            }
        }
        if (preg_match('/^(in|out):(\d+)$/', $key, $m)) {
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
        $query = CmsCommMessage::find()->cmsSite($this->siteId)->forManager($this->user)
            ->orderBy([$t.'.message_at' => SORT_DESC, $t.'.id' => SORT_DESC]);
        $hasLinks = ['exists', CmsCommLink::find()->andWhere([CmsCommLink::tableName().'.cms_comm_message_id' => $messageId])];

        if ($key === self::CLIENTS) {
            $query->linkedToVisible($this->user);
        } elseif ($key === self::UNLINKED) {
            $query->andWhere(['not', $hasLinks])->andWhere([$t.'.is_internal' => 0, $t.'.direction' => CmsCommMessage::DIRECTION_IN]);
        } elseif (preg_match('/^(in|out):(\d+)$/', $key, $m)) {
            $query->inAccount((int)$m[2])->andWhere([$t.'.direction' => $m[1] === 'in' ? CmsCommMessage::DIRECTION_IN : CmsCommMessage::DIRECTION_OUT]);
        }

        $search = trim($search);
        if ($search !== '') {
            $like = ['or',
                ['like', $t.'.subject', $search],
                ['like', $t.'.from_address', $search],
                ['like', $t.'.from_name', $search],
                ['like', $t.'.to_list', $search],
                ['like', $t.'.body_text', $search],
            ];
            $query->andWhere($like);
        }

        return $query;
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
