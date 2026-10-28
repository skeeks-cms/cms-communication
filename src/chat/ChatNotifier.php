<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\chat;

use skeeks\cms\communication\bot\BotEvent;
use skeeks\cms\communication\bot\BotEventContext;
use skeeks\cms\communication\bot\BotEventHandlerInterface;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;

/**
 * Notifications about new chat messages (CmsWebNotify, the standard bell),
 * registered as a bot event handler:
 * - a dialog somebody took — only them;
 * - nobody took it — the people of the records the chat is linked to
 *   (managers of a company or a client, the executor of a lead) who can open
 *   the chat; for a private dialog also employees who read all chats of the
 *   bot and keep "Уведомлять" on (the access row, as with mailboxes);
 * - a group chat not linked to anybody's record stays unread in "Чаты"
 *   without a bell: groups are noisy.
 * Messages of our employees and history never notify. One unread
 * notification per employee and chat: later messages update its text.
 */
class ChatNotifier implements BotEventHandlerInterface
{
    public function handle(BotEventContext $context): void
    {
        $message = $context->message;
        if ($context->event->type !== BotEvent::MESSAGE || !$message || $message->direction !== CmsCommMessage::DIRECTION_IN
            || (int)$message->is_history || ($context->identity && $context->identity->isWorker())) {
            return;
        }
        $conversation = $context->conversation;
        foreach ($this->recipients($conversation, (int)$context->account->id) as $userId) {
            $this->notify($userId, $conversation, $message);
        }
    }

    /** @return int[] */
    public function recipients(CmsCommConversation $conversation, int $accountId): array
    {
        if ($conversation->assigned_user_id) {
            $active = CmsUser::find()->andWhere(['id' => (int)$conversation->assigned_user_id, 'is_active' => 1])->exists();
            $assignee = $active ? [(int)$conversation->assigned_user_id] : [];
            // П2а+: the main responsible of the linked client learns about the message too (if he can open the chat).
            $main = array_values(array_diff(self::mainResponsible($conversation), $assignee));

            return array_values(array_unique(array_merge($assignee, $main ? self::readers($main, $conversation) : [])));
        }
        // The people of the linked records: managers of a company or a client, the executor of a lead.
        $ids = self::recordPeople($conversation);
        if ($conversation->chat_type === CmsCommConversation::CHAT_PRIVATE) {
            $ids = array_merge($ids, CmsCommAccountAccess::find()->select('cms_user_id')
                ->andWhere(['cms_comm_account_id' => $accountId, 'read_level' => CmsCommAccountAccess::READ_ALL, 'is_notify' => 1])->column());
        }
        return self::readers($ids, $conversation);
    }

    /**
     * Active employees of the ids who can open the chat.
     *
     * @return int[]
     */
    private static function readers(array $ids, CmsCommConversation $conversation): array
    {
        $users = CmsUser::find()->andWhere(['id' => array_unique(array_map('intval', $ids)) ?: [0], 'is_active' => 1, 'is_worker' => 1])->all();

        // Only those who can open the chat (forManager() of linked records needs the user component in a job).
        return \skeeks\cms\communication\services\MailNotifier::withUserComponent(CmsUser::class, static function () use ($users, $conversation) {
            $result = [];
            foreach ($users as $user) {
                if ((new ChatAccess($user))->canRead($conversation)) {
                    $result[] = (int)$user->id;
                }
            }
            return $result;
        });
    }

    /** @return int[] the main responsible of the companies and people the chat is linked to */
    private static function mainResponsible(CmsCommConversation $conversation): array
    {
        $access = \skeeks\cms\crm\CrmAccess::class;
        if (!class_exists($access) || !method_exists($access, 'mainResponsibleId')) {
            return [];
        }
        $ids = [];
        foreach (ChatFeed::links($conversation) as $link) {
            if ($link->model_code === \skeeks\cms\models\CmsCompany::class || $link->model_code === CmsUser::class) {
                $ids[] = (int)$access::instance()->mainResponsibleId($link->model_code === CmsUser::class ? 'client' : 'company', (int)$link->model_id);
            }
        }

        return array_values(array_filter(array_unique($ids)));
    }

    /** @return int[] */
    private static function recordPeople(CmsCommConversation $conversation): array
    {
        $ids = [];
        foreach (ChatFeed::links($conversation) as $link) {
            switch ($link->model_code) {
                case \skeeks\cms\models\CmsCompany::class:
                    $ids = array_merge($ids, \skeeks\cms\models\CmsCompany2manager::find()->select('cms_user_id')->andWhere(['cms_company_id' => (int)$link->model_id])->column());
                    break;
                case CmsUser::class:
                    $ids = array_merge($ids, \skeeks\cms\models\CmsUser2manager::find()->select('worker_id')->andWhere(['client_id' => (int)$link->model_id])->column());
                    break;
                case \skeeks\cms\models\CmsLead::class:
                    $ids = array_merge($ids, \skeeks\cms\models\CmsLead::find()->select('executor_id')->andWhere(['id' => (int)$link->model_id])->andWhere(['not', ['executor_id' => null]])->column());
                    break;
            }
        }

        return $ids;
    }

    private function notify(int $userId, CmsCommConversation $conversation, CmsCommMessage $message): void
    {
        $from = $message->from_name ?: ($message->from_address ?: 'клиента');
        $where = $conversation->chat_type === CmsCommConversation::CHAT_PRIVATE ? '' : ' в «'.$conversation->asText().'»';
        // One unread notification per chat: later messages update its text (a burst of one entry).
        $eventKey = 'chat:'.(int)$conversation->id;
        $notify = CmsWebNotify::find()->andWhere([
            'cms_user_id' => $userId,
            'model_code' => CmsCommConversation::class,
            'model_id' => (int)$conversation->id,
            'is_read' => 0,
        ])->orderBy(['id' => SORT_DESC])->one();
        if ($notify && $notify->event_key !== $eventKey) {
            // A notification made before kinds of events: it becomes the burst row.
            CmsWebNotify::updateAll(['event_key' => null], ['cms_user_id' => $userId, 'event_key' => $eventKey]);
            $notify->updateAttributes(['event_key' => $eventKey, 'kind' => 'chat.message', 'category' => 'messages']);
        }
        $name = 'Новое сообщение от '.$from.$where.': '.$message->getSnippet(80);
        if ($notify) {
            $count = (int)CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'direction' => CmsCommMessage::DIRECTION_IN, 'is_history' => 0])
                ->andWhere(['>=', 'created_at', (int)$notify->created_at])->count();
            $name = 'Новые сообщения ('.max(2, $count).') от '.$from.$where;
        }
        \Yii::$app->notifications->notify($userId, 'chat.message', [
            'name' => $name,
            'comment' => $notify ? 'Последнее: '.$message->getSnippet(200) : null,
            'model' => $conversation,
            'eventKey' => $eventKey,
            'collapse' => true,
        ]);
    }
}
