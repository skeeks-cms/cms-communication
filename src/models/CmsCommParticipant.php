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
 * A member of a group chat, as the bot learns it from messenger events
 * (messages, members joining and leaving). `left_at` — left the chat.
 *
 * @property int                 $id
 * @property int|null            $created_at
 * @property int|null            $updated_at
 * @property int                 $cms_comm_conversation_id
 * @property int                 $cms_comm_identity_id
 * @property string              $role member, admin, creator
 * @property int|null            $joined_at
 * @property int|null            $left_at
 * @property int|null            $last_message_at
 *
 * @property CmsCommIdentity     $identity
 * @property CmsCommConversation $conversation
 */
class CmsCommParticipant extends ActiveRecord
{
    public const ROLE_MEMBER = 'member';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_CREATOR = 'creator';

    public static function tableName()
    {
        return '{{%cms_comm_participant}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function getIdentity()
    {
        return $this->hasOne(CmsCommIdentity::class, ['id' => 'cms_comm_identity_id']);
    }

    public function getConversation()
    {
        return $this->hasOne(CmsCommConversation::class, ['id' => 'cms_comm_conversation_id']);
    }
}
