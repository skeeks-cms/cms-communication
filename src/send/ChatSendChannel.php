<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\chat\ChatAccess;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\SendContacts;
use skeeks\cms\communication\services\SendService;
use skeeks\cms\models\User;

/**
 * A chat of a bot (Telegram, MAX): the text, the personal link as a button
 * under it and the files (the PDF) as files of the messenger. Recipients are
 * chats linked to the client's records and private chats of people linked to
 * the client (SendContacts::CHAT) the employee may write to. The message is
 * an ordinary message of the chat (bot\BotSend): it is in "Чаты", in the feed
 * of the records and goes by the job comm.bot.send; chats of a stand are
 * limited by communication.allowedChats.
 */
class ChatSendChannel implements SendChannelInterface
{
    public const CHANNEL = 'chat';

    /** Longest text the employee may write; a messenger splits longer ones. */
    public const MAX_TEXT = 3000;

    public function title(): string
    {
        return 'Мессенджер';
    }

    public function icon(): string
    {
        return 'message';
    }

    public function contactKind(): string
    {
        return SendContacts::CHAT;
    }

    public function unavailableReason(User $user, int $siteId): ?string
    {
        return (new ChatAccess($user))->bots($siteId) ? null : 'Нет доступа ни к одному боту мессенджера (Настройки → Боты мессенджеров).';
    }

    public function renderForm(SendRequest $request, array $contacts): string
    {
        return \Yii::$app->view->render('@skeeks/cms/communication/views/message/_send-chat', [
            'request' => $request,
            'contacts' => $contacts,
            'text' => $request->text !== '' ? $request->text : $request->sendable->getSendText(),
            'maxText' => self::MAX_TEXT,
        ]);
    }

    public function send(SendRequest $request): CmsCommMessage
    {
        $conversation = ctype_digit($request->recipient) ? CmsCommConversation::find()
            ->andWhere(['id' => (int)$request->recipient, 'cms_site_id' => $request->siteId])->andWhere(['not', ['chat_type' => null]])->one() : null;
        if (!$conversation || !(new ChatAccess($request->user))->canSend($conversation)) {
            throw new SendException(['recipient' => 'Выберите чат, в который вам можно писать.']);
        }
        $text = trim(str_replace("\r", '', $request->text !== '' ? $request->text : $request->sendable->getSendText()));
        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new SendException(['text' => 'Слишком длинный текст: не больше '.self::MAX_TEXT.' символов.']);
        }

        $sends = new SendService($request->user, $request->siteId);
        $context = new SendContext($request->type, $request->id, self::CHANNEL, (string)$conversation->id, $request->user);
        $context->record = $request->record;
        $context->recipientName = $conversation->asText();

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $context->url = $request->sendable->createSendLink($context);
            try {
                $message = BotSend::queue($conversation, $text, [
                    'user' => $request->user,
                    'files' => $request->sendable->getSendFiles(),
                    'buttons' => [[['text' => $request->sendable->getSendButton(), 'url' => $context->url]]],
                ]);
            } catch (SendException $e) {
                // The chat's own reasons (not allowed on a stand, the bot left) belong to the recipient.
                throw new SendException(isset($e->errors['conversation']) ? ['recipient' => $e->errors['conversation']] + $e->errors : $e->errors);
            }
            $message->updateAttributes(['subject' => mb_substr($request->sendable->getSendTitle(), 0, 500)]);
            $sends->link($message, $request);
            $request->sendable->afterSend($context, $message);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $message;
    }
}
