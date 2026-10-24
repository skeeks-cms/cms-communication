<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\models\CmsTask;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A task asked for in a chat by `/task` (chat\ChatTasks): the chat hears its
 * number and, while the chat allows it, its later statuses.
 *
 * @property int                 $id
 * @property int|null            $created_at
 * @property int                 $cms_comm_conversation_id
 * @property int                 $cms_comm_message_id the command message
 * @property int                 $cms_task_id
 * @property string|null         $last_status the status the chat was told about
 *
 * @property CmsCommConversation $conversation
 * @property CmsCommMessage      $message
 * @property CmsTask             $task
 */
class CmsCommChatTask extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_chat_task}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    public function getConversation()
    {
        return $this->hasOne(CmsCommConversation::class, ['id' => 'cms_comm_conversation_id']);
    }

    public function getMessage()
    {
        return $this->hasOne(CmsCommMessage::class, ['id' => 'cms_comm_message_id']);
    }

    public function getTask()
    {
        return $this->hasOne(CmsTask::class, ['id' => 'cms_task_id']);
    }
}
