<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A raw update of a bot as the messenger delivered it: stored first by the
 * webhook or a poll (one row per update key — a repeated delivery is not
 * written), applied later by the job comm.bot.apply (bot\BotIngest). The
 * journal of the bot card; rows older than 30 days are removed.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int         $cms_comm_connection_id
 * @property string      $update_key
 * @property string|null $update_type
 * @property string      $payload JSON as received
 * @property string      $status new, applied, skipped, failed
 * @property int         $attempts
 * @property string|null $error
 * @property int|null    $applied_at
 * @property int|null    $cms_comm_message_id the message the update created or changed
 */
class CmsCommBotUpdate extends ActiveRecord
{
    public const STATUS_NEW = 'new';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    /** A failing update is tried this many times, then stays failed in the journal. */
    public const MAX_ATTEMPTS = 5;

    public static function tableName()
    {
        return '{{%cms_comm_bot_update}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_NEW => 'Ждёт обработки',
            self::STATUS_APPLIED => 'Обработано',
            self::STATUS_SKIPPED => 'Пропущено',
            self::STATUS_FAILED => 'Ошибка',
        ];
    }

    public function getPayloadData(): array
    {
        $data = json_decode((string)$this->payload, true);

        return is_array($data) ? $data : [];
    }
}
