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
 * first letter) or a messenger chat of a bot (thread_key
 * `<connection id>:<chat id>`, see bot\BotIngest): a private dialog, a group
 * chat or a channel. "Взять диалог" is the person responsible.
 *
 * @property int                          $id
 * @property int|null                     $created_at
 * @property int|null                     $updated_at
 * @property int                          $cms_site_id
 * @property string                       $channel
 * @property string                       $thread_key
 * @property string|null                  $subject
 * @property int|null                     $last_message_at
 * @property int|null                     $assigned_user_id the person responsible for the conversation
 * @property int|null                     $assigned_at
 * @property int|null                     $assigned_by
 * @property int|null                     $cms_comm_connection_id the bot of a chat
 * @property string|null                  $chat_type private, group, channel
 * @property string|null                  $external_chat_id
 * @property string|null                  $title
 * @property string|null                  $bot_status member, admin, left
 * @property string|null                  $bot_permissions JSON list
 * @property int|null                     $waiting_since the client waits for an answer since (bot\BotIngest::dialogState())
 * @property int|null                     $closed_at the dialog is closed; a message of the client opens it
 * @property int|null                     $closed_by
 * @property int|null                     $photo_storage_file_id the group's icon or the person's photo (bot\BotChats)
 * @property string|null                  $photo_ref where the photo came from
 * @property int|null                     $info_checked_at
 * @property int                          $task_notify the bot tells the chat about tasks asked for in it (chat\ChatTasks)
 *
 * @property CmsCommMessage[]             $messages
 * @property CmsUser|null                 $assignedUser
 * @property CmsCommConnection|null       $connection
 * @property CmsCommParticipant[]         $participants
 * @property CmsCommConversationLink[]    $conversationLinks
 */
class CmsCommConversation extends ActiveRecord
{
    public const CHAT_PRIVATE = 'private';
    public const CHAT_GROUP = 'group';
    public const CHAT_CHANNEL = 'channel';

    public const BOT_MEMBER = 'member';
    public const BOT_ADMIN = 'admin';
    public const BOT_LEFT = 'left';

    public static function chatTypes(): array
    {
        return [self::CHAT_PRIVATE => 'Личный диалог', self::CHAT_GROUP => 'Группа', self::CHAT_CHANNEL => 'Канал'];
    }

    public function isChat(): bool
    {
        return $this->hasAttribute('chat_type') && (string)$this->chat_type !== '';
    }

    /**
     * With cmsSite(): the backend finds a record of a controller (a chat in
     * "Чаты") within the current site.
     *
     * @return \skeeks\cms\query\CmsActiveQuery
     */
    public static function find()
    {
        return new \skeeks\cms\query\CmsActiveQuery(static::class);
    }

    public function asText()
    {
        $title = trim((string)($this->title ?: $this->subject));

        return $title !== '' ? $title : ($this->isChat() ? 'Чат #'.(int)$this->id : '(без темы)');
    }

    /**
     * The asText property: the header notification center and backend links
     * read it as on CMS records.
     */
    public function getAsText(): string
    {
        return $this->asText();
    }

    public function getConnection()
    {
        return $this->hasOne(CmsCommConnection::class, ['id' => 'cms_comm_connection_id']);
    }

    public function getParticipants()
    {
        return $this->hasMany(CmsCommParticipant::class, ['cms_comm_conversation_id' => 'id']);
    }

    public function getConversationLinks()
    {
        return $this->hasMany(CmsCommConversationLink::class, ['cms_comm_conversation_id' => 'id']);
    }

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
