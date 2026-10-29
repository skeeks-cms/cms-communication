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
 * - chats linked to a lead, company, client, deal or task whose communication
 *   the employee reads (CrmRecords::communicationQuery(): a company or a person
 *   only when seen in full), unless the bot hides linked access.
 * Writing: "any" on the bot — every visible chat; "own" — chats the employee
 * took, or linked to an open deal he works on or to a client seen in full:
 * «Свои сделки» and "edit" write only through their open deals (owner's
 * decision 29.10.2026). Administrators follow their own access rows, as in mail.
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
        return $this->sendRefusal($conversation) === null;
    }

    /** Why the employee may not write to the chat (one sentence), null — he may. */
    public function sendRefusal(CmsCommConversation $conversation): ?string
    {
        $row = $this->rowOf($conversation);
        if (!$row || $row->send_level === CmsCommAccountAccess::SEND_NONE || !$this->canRead($conversation)) {
            return 'Писать в этот чат вам нельзя: нужен доступ к боту на отправку.';
        }
        if ($row->send_level === CmsCommAccountAccess::SEND_ANY) {
            return null;
        }

        // "Own": chats the employee took, or linked to an open deal he works on or to a client seen in full.
        if ((int)$conversation->assigned_user_id === (int)$this->user->id
            || CmsCommConversation::find()->andWhere(['id' => (int)$conversation->id])->andWhere($this->sendLinkedCondition())->exists()) {
            return null;
        }

        return 'С этим клиентом вы работаете только через свои сделки: привяжите чат к своей открытой сделке («Привязать»), тогда сможете отвечать.';
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

    /** The chat is linked to a CRM record whose communication the employee reads (CrmRecords::communicationQuery()). */
    private function linkedCondition(): array
    {
        $conditions = ['or'];
        foreach (CrmRecords::types() as $type => $config) {
            $class = $config['class'];
            $conditions[] = $this->linkedTo($class, CrmRecords::communicationQuery($type, $this->user)->select($class::tableName().'.id'));
        }

        return $conditions;
    }

    /**
     * Where "own" sending may write: a chat linked to an open deal the employee works on
     * (CrmRecords::workingLeadsQuery()) or to a company or a person seen in full.
     */
    private function sendLinkedCondition(): array
    {
        $lead = \skeeks\cms\models\CmsLead::class;
        $conditions = ['or', $this->linkedTo($lead, CrmRecords::workingLeadsQuery($this->user)->select($lead::tableName().'.id'))];
        foreach ([CrmRecords::COMPANY, CrmRecords::CLIENT] as $type) {
            $class = CrmRecords::codeOf($type);
            $conditions[] = $this->linkedTo($class, CrmRecords::communicationQuery($type, $this->user)->select($class::tableName().'.id'));
        }

        return $conditions;
    }

    private function linkedTo(string $modelCode, ActiveQuery $ids): array
    {
        return ['exists', CmsCommConversationLink::find()
            ->andWhere([CmsCommConversationLink::tableName().'.cms_comm_conversation_id' => new Expression(CmsCommConversation::tableName().'.id')])
            ->andWhere(['model_code' => $modelCode, 'model_id' => $ids])];
    }
}
