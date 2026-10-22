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
 * Receive cursor of one folder of a connection. It is committed in the same
 * transaction as the messages it covers.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_comm_connection_id
 * @property string      $folder
 * @property string      $folder_role
 * @property int|null    $uidvalidity
 * @property int         $last_uid
 * @property int|null    $last_success_at
 * @property string|null $last_error
 * @property int|null    $last_error_at
 */
class CmsCommSyncState extends ActiveRecord
{
    public const ROLE_INBOX = 'inbox';
    public const ROLE_SENT = 'sent';

    public static function tableName()
    {
        return '{{%cms_comm_sync_state}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public static function roles(): array
    {
        return [self::ROLE_INBOX => 'Входящие', self::ROLE_SENT => 'Отправленные'];
    }
}
