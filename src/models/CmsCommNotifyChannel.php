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
 * Where a person receives notifications in a messenger: the private chat with
 * a bot of the site (notify\MessengerNotifyChannel).
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_user_id
 * @property string      $channel
 * @property int|null    $cms_comm_connection_id
 * @property int|null    $cms_comm_conversation_id
 * @property int|null    $cms_comm_identity_id
 * @property int         $is_active
 * @property int|null    $last_ok_at
 * @property int|null    $last_error_at
 * @property string|null $last_error
 *
 * @property CmsCommConversation|null $conversation
 * @property CmsCommIdentity|null     $identity
 * @property CmsCommConnection|null   $connection
 */
class CmsCommNotifyChannel extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_notify_channel}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function getConversation()
    {
        return $this->hasOne(CmsCommConversation::class, ['id' => 'cms_comm_conversation_id']);
    }

    public function getIdentity()
    {
        return $this->hasOne(CmsCommIdentity::class, ['id' => 'cms_comm_identity_id']);
    }

    public function getConnection()
    {
        return $this->hasOne(CmsCommConnection::class, ['id' => 'cms_comm_connection_id']);
    }
}
