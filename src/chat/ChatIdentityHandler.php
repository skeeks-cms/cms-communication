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
use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\send\SendException;

/**
 * Bot events that tell who the person is (chat\ChatIdentities):
 * - a start with an invitation key links the person to the record of the
 *   key and greets them;
 * - the person's own phone ("Поделиться номером", verified by the driver)
 *   links them to the one lead, company or client with that phone;
 * - a new private chat of a person already linked gets the same records.
 * Registered before the notifications, so the first message of a linked
 * person already reaches the record's people.
 */
class ChatIdentityHandler implements BotEventHandlerInterface
{
    public const GREETING = 'Здравствуйте! Чат подключён: пишите сюда, вам ответит менеджер.';

    public function handle(BotEventContext $context): void
    {
        $identity = $context->identity;
        $conversation = $context->conversation;
        $event = $context->event;
        if (!$identity || $conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE || $identity->isWorker()) {
            return;
        }

        if ($event->type === BotEvent::STARTED && $event->payload !== null && $event->payload !== '') {
            if (ChatIdentities::acceptInvite($identity, $context->connection, $event->payload, $conversation)) {
                $context->handled = true;
                try {
                    BotSend::queue($conversation, self::GREETING, ['sign' => false]);
                } catch (SendException $e) {
                    // A stand or a chat the bot may not write to: linking is enough.
                }
                return;
            }
        }

        if ($event->type === BotEvent::MESSAGE && $event->contact && !empty($event->contact['verified'])
            && (string)($event->contact['userId'] ?? '') === (string)$identity->external_id && !empty($event->contact['phone'])) {
            ChatIdentities::acceptPhone($identity, (string)$event->contact['phone'], $conversation);
        }

        // A linked person writing to another bot of the site: the new chat is theirs too.
        if (in_array($event->type, [BotEvent::MESSAGE, BotEvent::STARTED], true)
            && !CmsCommConversationLink::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->exists()) {
            $records = [];
            if ($identity->cms_user_id) {
                $records[\skeeks\cms\models\CmsUser::class.':'.(int)$identity->cms_user_id] = [\skeeks\cms\models\CmsUser::class, (int)$identity->cms_user_id];
            }
            foreach (ChatIdentities::privateChats($identity) as $other) {
                if ((int)$other->id === (int)$conversation->id) {
                    continue;
                }
                foreach (ChatFeed::links($other) as $link) {
                    $records[$link->model_code.':'.(int)$link->model_id] = [$link->model_code, (int)$link->model_id];
                }
            }
            foreach ($records as [$code, $id]) {
                ChatLinks::attach($conversation, $code, $id, null);
            }
        }
    }
}
