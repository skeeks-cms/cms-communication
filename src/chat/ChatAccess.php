<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\chat;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\models\User;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * Which bot chats an employee sees and where they may write, by the same
 * rules as mailboxes (a bot is an account with access rows):
 * - all chats of a bot where the employee reads everything;
 * - chats the employee took ("Взять диалог") or wrote to from the CRM;
 * - chats linked to a lead, company, client, deal or task the employee sees
 *   (`forManager()`), unless the bot hides linked access.
 * Writing: "any" on the bot — every visible chat; "own" — chats the employee
 * took or that are linked to records they see. Administrators follow their
 * own access rows, as in mail.
 */
class ChatAccess
{
    private User $user;
    /** @var CmsCommAccountAccess[]|null account id => row */
    private ?array $rows = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function user(): User
    {
        return $this->user;
    }

    /** Chats of bots the employee may read. */
    public function query(?int $siteId = null): ActiveQuery
    {
        $c = CmsCommConversation::tableName();
        $userId = (int)$this->user->id;
        $readAll = CmsCommAccount::find()->select('cms_comm_connection_id')
            ->andWhere(['id' => CmsCommAccountAccess::find()->select('cms_comm_account_id')->andWhere(['cms_user_id' => $userId, 'read_level' => CmsCommAccountAccess::READ_ALL])])
            ->andWhere(['not', ['cms_comm_connection_id' => null]]);
        $exposed = CmsCommAccount::find()->select('cms_comm_connection_id')->andWhere(['is_expose_linked' => 1])->andWhere(['not', ['cms_comm_connection_id' => null]]);

        $query = CmsCommConversation::find()
            ->andWhere(['not', [$c.'.cms_comm_connection_id' => null]])
            ->andWhere(['not', [$c.'.chat_type' => null]])
            ->andWhere(['or',
                [$c.'.cms_comm_connection_id' => $readAll],
                [$c.'.assigned_user_id' => $userId],
                ['exists', CmsCommMessage::find()->andWhere([CmsCommMessage::tableName().'.cms_comm_conversation_id' => new Expression($c.'.id'), 'sent_by_user_id' => $userId])],
                ['and', [$c.'.cms_comm_connection_id' => $exposed], $this->linkedCondition()],
                // The employee's own chat where their notifications come.
                [$c.'.id' => \skeeks\cms\communication\notify\NotifyLinks::ownChats($userId)],
            ]);
        // Private chats where colleagues get their notifications are theirs; administrators see them.
        if (!\Yii::$app->authManager->checkAccess($userId, \skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            $query->andWhere(['not in', $c.'.id', \skeeks\cms\communication\notify\NotifyLinks::chatsOfOthers($userId)]);
        }
        if ($siteId !== null) {
            $query->andWhere([$c.'.cms_site_id' => $siteId]);
        }

        return $query;
    }

    public function canRead(CmsCommConversation $conversation): bool
    {
        return $this->query()->andWhere([CmsCommConversation::tableName().'.id' => (int)$conversation->id])->exists();
    }

    public function canSend(CmsCommConversation $conversation): bool
    {
        $row = $this->rowOf($conversation);
        if (!$row || $row->send_level === CmsCommAccountAccess::SEND_NONE) {
            return false;
        }
        if (!$this->canRead($conversation)) {
            return false;
        }
        if ($row->send_level === CmsCommAccountAccess::SEND_ANY) {
            return true;
        }

        // "Own": chats the employee took or that belong to their clients.
        return (int)$conversation->assigned_user_id === (int)$this->user->id
            || CmsCommConversation::find()->andWhere(['id' => (int)$conversation->id])->andWhere($this->linkedCondition())->exists();
    }

    /** Bots (accounts) the employee has an access row on. */
    public function bots(int $siteId): array
    {
        return CmsCommAccount::find()
            ->andWhere(['cms_site_id' => $siteId, 'id' => array_keys($this->rows())])
            ->andWhere(['not', ['channel' => CmsCommAccount::CHANNEL_EMAIL]])
            ->andWhere(['not', ['cms_comm_connection_id' => null]])
            ->orderBy(['name' => SORT_ASC])->all();
    }

    /** The employee's access row on the bot of a chat, or null. */
    public function rowOf(CmsCommConversation $conversation): ?CmsCommAccountAccess
    {
        $accountId = CmsCommAccount::find()->select('id')->andWhere(['cms_comm_connection_id' => (int)$conversation->cms_comm_connection_id])->orderBy(['id' => SORT_ASC])->scalar();

        return $accountId ? ($this->rows()[(int)$accountId] ?? null) : null;
    }

    public function rowOfAccount(int $accountId): ?CmsCommAccountAccess
    {
        return $this->rows()[$accountId] ?? null;
    }

    /** @return CmsCommAccountAccess[] account id => row */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            foreach (CmsCommAccountAccess::find()->andWhere(['cms_user_id' => (int)$this->user->id])
                ->andWhere(['!=', 'read_level', CmsCommAccountAccess::READ_NONE])->all() as $row) {
                $this->rows[(int)$row->cms_comm_account_id] = $row;
            }
        }

        return $this->rows;
    }

    /** The chat is linked to a CRM record the employee sees. */
    private function linkedCondition(): array
    {
        $conversationId = new Expression(CmsCommConversation::tableName().'.id');
        $conditions = ['or'];
        foreach (CrmRecords::types() as $type => $config) {
            $class = $config['class'];
            $conditions[] = ['exists', CmsCommConversationLink::find()
                ->andWhere([CmsCommConversationLink::tableName().'.cms_comm_conversation_id' => $conversationId])
                ->andWhere(['model_code' => $class, 'model_id' => CrmRecords::visibleQuery($type, $this->user)->select($class::tableName().'.id')])];
        }

        return $conditions;
    }
}
