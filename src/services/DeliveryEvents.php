<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\mail\DeliveryEvent;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageEvent;
use skeeks\cms\communication\models\CmsCommSuppression;
use yii\db\IntegrityException;
use yii\helpers\Json;

/**
 * The only writer of delivery events of mailing services (stage 4C). A
 * driver parses the webhook of its service into mail\DeliveryEvent; this
 * service:
 * - stores the event once (provider + key: a repeated webhook is a no-op);
 * - keeps the latest delivery state on the letter (`delivery_status`):
 *   "delivered" only by the service's confirmation, a later "deferred" never
 *   undoes it, a bounce or a complaint is final;
 * - marks the campaign recipient (`delivered_at`, `bounced_at`);
 * - writes the consequences through services\MailingConsents: a hard bounce
 *   of a dead address and a complaint go to the stop list with the reason,
 *   an opt-out through the service's link opts the contact out of the topic
 *   of the letter (of all advertising for a letter without a topic).
 *
 * Consequences reach a contact only when the event's address is a recipient
 * of our letter: the metadata is ours, the address is the service's. The
 * state of the letter follows its first "To" address only: a copy to a
 * colleague that bounced does not make the letter undelivered.
 */
class DeliveryEvents
{
    /** Delivery state of a letter by event; a state never goes down in rank. */
    private const STATE = [
        CmsCommMessageEvent::EVENT_DEFERRED => CmsCommMessage::DELIVERY_DEFERRED,
        CmsCommMessageEvent::EVENT_DELIVERED => CmsCommMessage::DELIVERY_DELIVERED,
        // An opening, a click and an opt-out come only after delivery.
        CmsCommMessageEvent::EVENT_OPENED => CmsCommMessage::DELIVERY_DELIVERED,
        CmsCommMessageEvent::EVENT_CLICKED => CmsCommMessage::DELIVERY_DELIVERED,
        CmsCommMessageEvent::EVENT_UNSUBSCRIBED => CmsCommMessage::DELIVERY_DELIVERED,
        CmsCommMessageEvent::EVENT_BOUNCED => CmsCommMessage::DELIVERY_BOUNCED,
        CmsCommMessageEvent::EVENT_COMPLAINED => CmsCommMessage::DELIVERY_COMPLAINED,
    ];

    private const RANK = [
        CmsCommMessage::DELIVERY_DEFERRED => 1,
        CmsCommMessage::DELIVERY_DELIVERED => 2,
        CmsCommMessage::DELIVERY_BOUNCED => 3,
        CmsCommMessage::DELIVERY_COMPLAINED => 3,
    ];

    /**
     * Records one event of the service.
     *
     * @return CmsCommMessageEvent|null the new row; null when this event was recorded before
     */
    public static function record(DeliveryEvent $event, int $siteId): ?CmsCommMessageEvent
    {
        if ($event->provider === '' || $event->key === '' || !isset(CmsCommMessageEvent::events()[$event->event])) {
            throw new \InvalidArgumentException('Событие доставки без сервиса, ключа или с неизвестным видом.');
        }
        $key = mb_substr($event->key, 0, 64);
        if (CmsCommMessageEvent::find()->andWhere(['provider' => $event->provider, 'event_key' => $key])->exists()) {
            return null;
        }
        $message = $event->messageId ? CmsCommMessage::findOne(['id' => $event->messageId, 'cms_site_id' => $siteId]) : null;
        $address = $event->address !== null ? (CmsCommContact::normalize(CmsCommContact::KIND_EMAIL, $event->address) ?? mb_strtolower(trim($event->address))) : null;

        return CmsCommMessageEvent::getDb()->transaction(function () use ($event, $siteId, $message, $address, $key) {
            $row = new CmsCommMessageEvent([
                'created_at' => time(),
                'cms_site_id' => $siteId,
                'cms_comm_message_id' => $message ? (int)$message->id : null,
                'cms_comm_connection_id' => $event->connectionId ?: null,
                'provider' => $event->provider,
                'event_key' => $key,
                'event' => $event->event,
                'event_at' => $event->at ?: time(),
                'address' => $address !== null ? mb_substr($address, 0, 255) : null,
                'detail' => $event->detail !== null && trim($event->detail) !== '' ? mb_substr(trim($event->detail), 0, 1000) : null,
                'data' => $event->data ? Json::encode($event->data) : null,
            ]);
            try {
                $row->save(false);
            } catch (IntegrityException $e) {
                // Recorded by a parallel run a moment ago.
                return null;
            }
            if ($message && $address !== null && self::isRecipient($message, $address)) {
                if ($address === self::primary($message)) {
                    self::applyToMessage($message, $row, $event->refused);
                }
                self::applyToContact($message, $row, $address, $event->addressDead);
            }

            return $row;
        });
    }

    /** The letter's delivery state and its campaign recipient. */
    private static function applyToMessage(CmsCommMessage $message, CmsCommMessageEvent $row, bool $refused): void
    {
        $state = $refused ? CmsCommMessage::DELIVERY_BOUNCED : (self::STATE[$row->event] ?? null);
        if (!$state || !$message->hasAttribute('delivery_status')) {
            return;
        }
        $current = (string)$message->delivery_status;
        if ($current === '' || self::RANK[$state] > (self::RANK[$current] ?? 0)) {
            CmsCommMessage::updateAll(['delivery_status' => $state, 'delivery_at' => (int)$row->event_at, 'updated_at' => time()], ['id' => (int)$message->id]);
            $message->delivery_status = $state;
            $message->delivery_at = (int)$row->event_at;
        }
        if (!$message->cms_comm_campaign_id) {
            return;
        }
        $condition = ['cms_comm_message_id' => (int)$message->id];
        if ($state === CmsCommMessage::DELIVERY_DELIVERED || $state === CmsCommMessage::DELIVERY_COMPLAINED) {
            CmsCommCampaignRecipient::updateAll(['delivered_at' => (int)$row->event_at, 'updated_at' => time()], $condition + ['delivered_at' => null]);
        } elseif ($state === CmsCommMessage::DELIVERY_BOUNCED) {
            CmsCommCampaignRecipient::updateAll(['bounced_at' => (int)$row->event_at, 'updated_at' => time()], $condition + ['bounced_at' => null]);
        }
    }

    /** The stop list and opt-outs of the contact the letter went to. */
    private static function applyToContact(CmsCommMessage $message, CmsCommMessageEvent $row, string $address, bool $addressDead): void
    {
        $contact = null;
        $get = static function () use (&$contact, $message, $address) {
            return $contact ?? ($contact = MailingContacts::get((int)$message->cms_site_id, CmsCommContact::KIND_EMAIL, $address));
        };
        $note = $row->detail ? mb_substr((string)$row->detail, 0, 500) : null;
        switch ($row->event) {
            case CmsCommMessageEvent::EVENT_BOUNCED:
                if ($addressDead) {
                    MailingConsents::suppress($get(), CmsCommSuppression::REASON_BOUNCE, CmsCommSuppression::SOURCE_PROVIDER, null, (int)$message->id, $note);
                }
                break;
            case CmsCommMessageEvent::EVENT_COMPLAINED:
                MailingConsents::suppress($get(), CmsCommSuppression::REASON_COMPLAINT, CmsCommSuppression::SOURCE_PROVIDER, null, (int)$message->id, $note);
                break;
            case CmsCommMessageEvent::EVENT_UNSUBSCRIBED:
                if ($message->hasAttribute('cms_comm_topic_id') && $message->cms_comm_topic_id) {
                    MailingConsents::unsubscribe($get(), (int)$message->cms_comm_topic_id, CmsCommContactTopic::SOURCE_PROVIDER, null, (int)$message->id);
                } else {
                    MailingConsents::suppress($get(), CmsCommSuppression::REASON_ALL, CmsCommSuppression::SOURCE_PROVIDER, null, (int)$message->id, $note);
                }
                break;
        }
    }

    /** The first "To" address of the letter, normalized. */
    private static function primary(CmsCommMessage $message): ?string
    {
        $to = array_keys($message->getTo());

        return $to ? mb_strtolower(trim((string)$to[0])) : null;
    }

    private static function isRecipient(CmsCommMessage $message, string $address): bool
    {
        foreach (array_keys($message->getTo() + $message->getCc() + $message->getBcc()) as $recipient) {
            if (mb_strtolower(trim((string)$recipient)) === $address) {
                return true;
            }
        }

        return false;
    }

    /**
     * Events of a letter for its card, the earlier first.
     *
     * @return CmsCommMessageEvent[]
     */
    public static function ofMessage(int $messageId): array
    {
        if (!CmsCommMessageEvent::getDb()->getTableSchema(CmsCommMessageEvent::tableName())) {
            return [];
        }

        return CmsCommMessageEvent::find()->andWhere(['cms_comm_message_id' => $messageId])
            ->orderBy(['event_at' => SORT_ASC, 'id' => SORT_ASC])->limit(50)->all();
    }
}
