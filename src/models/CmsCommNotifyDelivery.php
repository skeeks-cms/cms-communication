<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\models\CmsWebNotify;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * The delivery journal of notifications outside the CRM (notify\NotifyDeliveries):
 * one row per notification, channel and event of a burst.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int|null    $cms_web_notify_id
 * @property int         $cms_user_id
 * @property string      $channel
 * @property int         $step
 * @property string      $status
 * @property string|null $reason
 * @property string|null $title
 * @property int|null    $scheduled_at
 * @property int|null    $sent_at
 * @property string|null $error
 * @property int|null    $cms_comm_message_id
 * @property int|null    $fallback_of_id
 * @property int         $skip_if_read  not sent when read in the CRM meanwhile (a silent CRM row is read from the start)
 *
 * @property CmsWebNotify|null $notify
 */
class CmsCommNotifyDelivery extends ActiveRecord
{
    const STATUS_QUEUED = 'queued';
    const STATUS_POSTPONED = 'postponed';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_UNKNOWN = 'unknown';
    const STATUS_SKIPPED = 'skipped';

    public static function tableName()
    {
        return '{{%cms_comm_notify_delivery}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function getNotify()
    {
        return $this->hasOne(CmsWebNotify::class, ['id' => 'cms_web_notify_id']);
    }

    public static function statusNames(): array
    {
        return [
            self::STATUS_QUEUED    => 'В очереди',
            self::STATUS_POSTPONED => 'Отложено',
            self::STATUS_SENDING   => 'Отправляется',
            self::STATUS_SENT      => 'Отправлено',
            self::STATUS_FAILED    => 'Не доставлено',
            self::STATUS_UNKNOWN   => 'Неизвестно, доставлено ли',
            self::STATUS_SKIPPED   => 'Не отправлялось',
        ];
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_UNKNOWN, self::STATUS_SKIPPED], true);
    }
}
