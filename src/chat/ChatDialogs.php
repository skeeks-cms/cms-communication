<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\chat;

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;

/**
 * Operations with a dialog of a bot chat, one for the "Чаты" screen, MCP
 * tools and later the AI: take it ("Взять диалог" — the person responsible
 * for the conversation), pass it to a colleague, close and reopen it, mark
 * it read. Every operation checks the employee's access (ChatAccess).
 */
class ChatDialogs
{
    private ChatAccess $access;

    public function __construct(CmsUser $user)
    {
        $this->access = new ChatAccess($user);
    }

    public function access(): ChatAccess
    {
        return $this->access;
    }

    /**
     * "Взять диалог": the employee answers the client from now on. A dialog
     * taken by a colleague is passed with transfer(), not taken over silently.
     */
    public function take(CmsCommConversation $conversation): void
    {
        $this->requireRead($conversation);
        $me = (int)$this->access->user()->id;
        if ($conversation->assigned_user_id && (int)$conversation->assigned_user_id !== $me) {
            throw new \DomainException('Диалог уже ведёт '.($conversation->assignedUser ? $conversation->assignedUser->shortDisplayName : 'другой сотрудник').'. Попросите передать его вам.');
        }
        $this->assign($conversation, $me);
    }

    /**
     * Passes the dialog to a colleague (null — to nobody: back to "Новые").
     * The colleague must see the chat; they get a notification.
     */
    public function transfer(CmsCommConversation $conversation, ?int $userId): void
    {
        $this->requireRead($conversation);
        if ($userId !== null) {
            $user = CmsUser::find()->andWhere(['id' => $userId, 'is_active' => 1, 'is_worker' => 1])->one();
            if (!$user || !(new ChatAccess($user))->canRead($conversation)) {
                throw new \DomainException('Этот сотрудник не видит чат: сначала выдайте ему доступ к боту.');
            }
        }
        $this->assign($conversation, $userId);
        if ($userId !== null && $userId !== (int)$this->access->user()->id) {
            $notify = new CmsWebNotify();
            $notify->cms_user_id = $userId;
            $notify->name = mb_substr('Вам передан диалог «'.$conversation->asText().'»', 0, 250);
            $notify->model_code = CmsCommConversation::class;
            $notify->model_id = (int)$conversation->id;
            $notify->save();
        }
    }

    /** The dialog is done; a new message of the client opens it again. */
    public function close(CmsCommConversation $conversation): void
    {
        $this->requireRead($conversation);
        $conversation->closed_at = time();
        $conversation->closed_by = (int)$this->access->user()->id;
        $conversation->waiting_since = null;
        $conversation->save(false, ['closed_at', 'closed_by', 'waiting_since', 'updated_at']);
    }

    public function reopen(CmsCommConversation $conversation): void
    {
        $this->requireRead($conversation);
        $conversation->closed_at = null;
        $conversation->closed_by = null;
        $conversation->save(false, ['closed_at', 'closed_by', 'updated_at']);
    }

    /** Messages up to the last one are read by the employee; their notifications too. */
    public function markRead(CmsCommConversation $conversation): void
    {
        $last = (int)CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->max('id');
        if (!$last) {
            return;
        }
        $userId = (int)$this->access->user()->id;
        \Yii::$app->db->createCommand()->upsert('{{%cms_comm_conversation_read}}', [
            'cms_comm_conversation_id' => (int)$conversation->id,
            'cms_user_id' => $userId,
            'last_message_id' => $last,
            'read_at' => time(),
        ], ['last_message_id' => $last, 'read_at' => time()])->execute();
        CmsWebNotify::updateAll(['is_read' => 1], [
            'cms_user_id' => $userId,
            'model_code' => CmsCommConversation::class,
            'model_id' => (int)$conversation->id,
            'is_read' => 0,
        ]);
    }

    private function assign(CmsCommConversation $conversation, ?int $userId): void
    {
        $conversation->assigned_user_id = $userId;
        $conversation->assigned_at = $userId ? time() : null;
        $conversation->assigned_by = $userId ? (int)$this->access->user()->id : null;
        if ($userId && $conversation->closed_at) {
            $conversation->closed_at = null;
            $conversation->closed_by = null;
        }
        $conversation->save(false, ['assigned_user_id', 'assigned_at', 'assigned_by', 'closed_at', 'closed_by', 'updated_at']);
    }

    private function requireRead(CmsCommConversation $conversation): void
    {
        if (!$this->access->canRead($conversation)) {
            throw new \DomainException('Чат не найден или у вас нет к нему доступа.');
        }
    }
}
