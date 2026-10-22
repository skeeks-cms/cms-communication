<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\models\CmsUser;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A conversation: a chain of letters (thread_key is the Message-ID of its
 * first letter), later a messenger dialog or a group chat.
 *
 * @property int              $id
 * @property int|null         $created_at
 * @property int|null         $updated_at
 * @property int              $cms_site_id
 * @property string           $channel
 * @property string           $thread_key
 * @property string|null      $subject
 * @property int|null         $last_message_at
 * @property int|null         $assigned_user_id the person responsible for the conversation
 * @property int|null         $assigned_at
 * @property int|null         $assigned_by
 *
 * @property CmsCommMessage[] $messages
 * @property CmsUser|null     $assignedUser
 */
class CmsCommConversation extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_conversation}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function getMessages()
    {
        return $this->hasMany(CmsCommMessage::class, ['cms_comm_conversation_id' => 'id'])
            ->orderBy(['message_at' => SORT_ASC, 'id' => SORT_ASC]);
    }

    public function getAssignedUser()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'assigned_user_id']);
    }
}
