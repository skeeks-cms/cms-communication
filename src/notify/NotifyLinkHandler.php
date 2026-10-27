<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use skeeks\cms\communication\bot\BotEvent;
use skeeks\cms\communication\bot\BotEventContext;
use skeeks\cms\communication\bot\BotEventHandlerInterface;
use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\send\SendException;

/**
 * "Старт" by the link of "Подключить" in the notification settings: the chat
 * becomes the employee's notification channel and the bot says so. Runs
 * before the identity handler: the person is an employee from now on, so the
 * client handlers leave the event alone.
 */
class NotifyLinkHandler implements BotEventHandlerInterface
{
    public const GREETING = 'Готово: уведомления CRM будут приходить сюда. Что присылать — в профиле CRM, раздел «Уведомления».';

    public function handle(BotEventContext $context): void
    {
        $event = $context->event;
        if ($event->type !== BotEvent::STARTED || $event->payload === null || $event->payload === ''
            || !$context->identity || $context->conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE) {
            return;
        }
        try {
            $answer = NotifyLinks::accept($context->identity, $context->connection, $context->conversation, (string)$event->payload) ? self::GREETING : null;
        } catch (\DomainException $e) {
            $answer = $e->getMessage();
        }
        if ($answer === null) {
            return;
        }
        $context->handled = true;
        try {
            BotSend::queue($context->conversation, $answer, ['sign' => false]);
        } catch (SendException $e) {
            // A stand or a chat the bot may not write to: the channel is connected anyway.
        }
    }
}
