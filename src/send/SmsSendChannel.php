<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\MessageLinker;
use skeeks\cms\communication\services\SendContacts;
use skeeks\cms\communication\services\SendService;
use skeeks\cms\models\CmsSmsProvider;
use skeeks\cms\models\User;
use yii\helpers\Json;

/**
 * SMS: short text and the personal link, through the SMS providers of the
 * site (`skeeks/cms`, "Настройки → SMS провайдеры"; the main one, else the
 * first). Stored as a message of channel "sms" in a conversation of the
 * phone, so it is in the feed like a letter; the job comm.sms.send hands it
 * to the provider. Phones are limited by communication.allowedPhones.
 */
class SmsSendChannel implements SendChannelInterface
{
    public const CHANNEL = 'sms';

    /** Longest text the employee may write; the link is added to it. */
    public const MAX_TEXT = 500;

    public function title(): string
    {
        return 'СМС';
    }

    public function icon(): string
    {
        return 'phone';
    }

    public function contactKind(): string
    {
        return SendContacts::PHONE;
    }

    public function unavailableReason(User $user, int $siteId): ?string
    {
        return self::provider($siteId) ? null : 'На сайте не подключён провайдер СМС (Настройки → SMS провайдеры).';
    }

    /** The provider SMS of the site go through: the main one, else the first by priority. */
    public static function provider(int $siteId): ?CmsSmsProvider
    {
        return CmsSmsProvider::find()->andWhere(['cms_site_id' => $siteId])
            ->orderBy(['is_main' => SORT_DESC, 'priority' => SORT_ASC, 'id' => SORT_ASC])->one();
    }

    /** The SMS as the client gets it: the text and the link on its own line. */
    public static function compose(string $text, string $url): string
    {
        $text = trim($text);

        return $text === '' ? $url : $text."\n".$url;
    }

    public function renderForm(SendRequest $request, array $contacts): string
    {
        return \Yii::$app->view->render('@skeeks/cms/communication/views/message/_send-sms', [
            'request' => $request,
            'contacts' => $contacts,
            'phone' => (string)($contacts[0]['display'] ?? ''),
            'text' => $request->text !== '' ? $request->text : $request->sendable->getSendText(),
            'maxText' => self::MAX_TEXT,
        ]);
    }

    public function send(SendRequest $request): CmsCommMessage
    {
        $phone = SendContacts::normalizePhone($request->recipient);
        if (!$phone) {
            throw new SendException(['recipient' => 'Укажите номер телефона.']);
        }
        if (!\Yii::$app->communication->isPhoneAllowed($phone)) {
            throw new SendException(['recipient' => 'Отправка СМС на номер '.SendContacts::formatPhone($phone)
                .' запрещена настройкой allowedPhones (на стенде с копией боевой базы СМС уходят только на номера из этого списка).']);
        }
        $text = trim(str_replace("\r", '', $request->text !== '' ? $request->text : $request->sendable->getSendText()));
        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new SendException(['text' => 'Слишком длинный текст: не больше '.self::MAX_TEXT.' символов.']);
        }
        $provider = self::provider($request->siteId);
        if (!$provider) {
            throw new SendException(['channel' => 'На сайте не подключён провайдер СМС.']);
        }

        $sends = new SendService($request->user, $request->siteId);
        $context = new SendContext($request->type, $request->id, self::CHANNEL, $phone, $request->user);
        $context->record = $request->record;
        foreach ($sends->contacts($request->sendable, $request->record) as $contact) {
            if ($contact['kind'] === SendContacts::PHONE && $contact['value'] === $phone) {
                $context->recipientName = $contact['name'] !== '' ? $contact['name'] : $contact['record'];
                break;
            }
        }

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $context->url = $request->sendable->createSendLink($context);
            $body = self::compose($text, $context->url);

            // One conversation per phone: later answers and messengers of the number join it.
            $conversation = CmsCommConversation::find()->andWhere(['cms_site_id' => $request->siteId, 'channel' => self::CHANNEL, 'thread_key' => 'sms:'.$phone])->one()
                ?: new CmsCommConversation(['cms_site_id' => $request->siteId, 'channel' => self::CHANNEL, 'thread_key' => 'sms:'.$phone]);
            $conversation->subject = mb_substr($request->sendable->getSendTitle(), 0, 500);
            $conversation->last_message_at = time();
            $conversation->save(false);

            $message = new CmsCommMessage([
                'cms_site_id' => $request->siteId,
                'cms_comm_conversation_id' => $conversation->id,
                'channel' => self::CHANNEL,
                'direction' => CmsCommMessage::DIRECTION_OUT,
                'status' => CmsCommMessage::STATUS_QUEUED,
                'external_id' => 'sms.'.bin2hex(random_bytes(10)),
                'from_name' => (string)$provider->name,
                'to_list' => Json::encode([$phone => $context->recipientName]),
                'subject' => mb_substr($request->sendable->getSendTitle(), 0, 500),
                'body_text' => $body,
                'is_internal' => 0,
                'is_auto' => 0,
                'message_at' => time(),
                'sent_by_user_id' => (int)$request->user->id,
            ]);
            $message->save(false);

            $sends->link($message, $request);
            (new MessageLinker())->inheritFromConversation($message);
            $request->sendable->afterSend($context, $message);

            \Yii::$app->jobs->push('comm.sms.send', [
                'messageId' => (int)$message->id,
                'providerId' => (int)$provider->id,
                'userIp' => mb_substr($request->userIp, 0, 20),
            ], ['siteId' => $request->siteId]);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $message;
    }
}
