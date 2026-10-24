<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use yii\db\ActiveRecord;

/**
 * A delivery event of a letter reported by a mailing service: delivered,
 * deferred (the service keeps trying), bounced, complaint, opt-out. Written
 * only by services\DeliveryEvents, one row per event of the service
 * (provider + event_key).
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int         $cms_site_id
 * @property int|null    $cms_comm_message_id
 * @property int|null    $cms_comm_connection_id
 * @property string      $provider
 * @property string      $event_key
 * @property string      $event
 * @property int|null    $event_at
 * @property string|null $address
 * @property string|null $detail
 * @property string|null $data
 *
 * @property CmsCommMessage|null $message
 */
class CmsCommMessageEvent extends ActiveRecord
{
    public const EVENT_ACCEPTED = 'accepted';
    public const EVENT_SENT = 'sent';
    public const EVENT_DELIVERED = 'delivered';
    /** Delivery failed for now; the service keeps trying (a soft bounce). */
    public const EVENT_DEFERRED = 'deferred';
    /** Not delivered, no more attempts (a hard bounce). */
    public const EVENT_BOUNCED = 'bounced';
    /** The recipient marked the letter as spam. */
    public const EVENT_COMPLAINED = 'complained';
    /** Opted out through the service's own link. */
    public const EVENT_UNSUBSCRIBED = 'unsubscribed';
    public const EVENT_OPENED = 'opened';
    public const EVENT_CLICKED = 'clicked';
    public const EVENT_OTHER = 'other';

    public static function tableName()
    {
        return '{{%cms_comm_message_event}}';
    }

    /** @return string[] event => title */
    public static function events(): array
    {
        return [
            self::EVENT_ACCEPTED => 'Принято сервисом',
            self::EVENT_SENT => 'Отправлено сервисом',
            self::EVENT_DELIVERED => 'Доставлено',
            self::EVENT_DEFERRED => 'Не доставлено пока: сервис пробует ещё',
            self::EVENT_BOUNCED => 'Не доставлено',
            self::EVENT_COMPLAINED => 'Жалоба на спам',
            self::EVENT_UNSUBSCRIBED => 'Отписка через ссылку сервиса',
            self::EVENT_OPENED => 'Открытие по данным сервиса',
            self::EVENT_CLICKED => 'Переход по данным сервиса',
            self::EVENT_OTHER => 'Другое событие',
        ];
    }

    public function getEventName(): string
    {
        return self::events()[$this->event] ?? (string)$this->event;
    }

    public function getMessage()
    {
        return $this->hasOne(CmsCommMessage::class, ['id' => 'cms_comm_message_id']);
    }
}
