<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use yii\db\ActiveRecord;
use yii\helpers\Json;

/**
 * An incoming message written by a client on a page of the site, without a
 * mailbox: "Задать вопрос" on the page of a proposal, later other client
 * pages and forms. Stored like any message (channel "site"), in a
 * conversation of its own key, linked to the CRM records so it is in their
 * feeds. The caller decides who may write (anti-spam, limits) and whom to
 * notify; the caller owns the transaction.
 */
class SiteMessages
{
    public const CHANNEL = 'site';

    public const MAX_TEXT = 5000;

    /**
     * @param string         $threadKey conversation of the page, e.g. "proposal:12" (prefixed with "site:")
     * @param string         $contact   how to reach the client: an email or a phone as typed
     * @param ActiveRecord[] $records   lead, company, client, deal
     */
    public function receive(int $siteId, string $threadKey, string $subject, string $text, string $fromName, string $contact, array $records): CmsCommMessage
    {
        $text = trim(str_replace("\r", '', $text));
        if ($text === '') {
            throw new \InvalidArgumentException('Пустое сообщение.');
        }
        $key = 'site:'.$threadKey;
        $conversation = CmsCommConversation::find()->andWhere(['cms_site_id' => $siteId, 'channel' => self::CHANNEL, 'thread_key' => $key])->one()
            ?: new CmsCommConversation(['cms_site_id' => $siteId, 'channel' => self::CHANNEL, 'thread_key' => $key]);
        $conversation->subject = mb_substr($subject, 0, 500);
        $conversation->last_message_at = time();
        $conversation->save(false);

        $contact = trim($contact);
        $email = filter_var($contact, FILTER_VALIDATE_EMAIL) ? mb_strtolower($contact) : null;
        $message = new CmsCommMessage([
            'cms_site_id' => $siteId,
            'cms_comm_conversation_id' => $conversation->id,
            'channel' => self::CHANNEL,
            'direction' => CmsCommMessage::DIRECTION_IN,
            'status' => CmsCommMessage::STATUS_RECEIVED,
            'external_id' => 'site.'.bin2hex(random_bytes(10)),
            'from_address' => $email ?: ($contact !== '' ? mb_substr($contact, 0, 255) : null),
            'from_name' => mb_substr(trim($fromName), 0, 255) ?: null,
            'to_list' => Json::encode([]),
            'subject' => mb_substr($subject, 0, 500),
            'body_text' => mb_substr($text, 0, self::MAX_TEXT),
            'is_internal' => 0,
            'is_auto' => 0,
            'message_at' => time(),
        ]);
        if (!$message->save(false)) {
            throw new \RuntimeException('Не удалось сохранить сообщение.');
        }
        $linker = new MessageLinker();
        $linker->link($message, array_filter($records), CmsCommLink::TYPE_CONTEXT);
        $linker->inheritFromConversation($message);
        InboundLeads::route($message, 'написал');

        return $message;
    }
}
