<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

/**
 * One messenger update in the common form: a driver turns its update into
 * zero or more events, BotIngest applies them the same way for every
 * messenger. Ids are strings (Telegram and MAX ids exceed 32 bits).
 */
final class BotEvent
{
    /** A new message; also a message of the chat history (BotIngest::apply(..., history: true)). */
    public const MESSAGE = 'message';
    /** A message changed in the messenger: carries the whole new message. */
    public const EDITED = 'edited';
    /** A message removed in the messenger: messageId only. */
    public const REMOVED = 'removed';
    /** A button of the bot pressed: payload, sender, messageId of the message with the button. */
    public const CALLBACK = 'callback';
    /** A person opened the bot (/start, a link with `?start=`): payload is the start parameter. */
    public const STARTED = 'started';
    /** A person stopped or blocked the bot. */
    public const STOPPED = 'stopped';
    /** People joined a group: members. */
    public const MEMBERS_JOINED = 'members_joined';
    /** People left a group: members. */
    public const MEMBERS_LEFT = 'members_left';
    /** The bot was added to a chat or its rights changed: botStatus, botPermissions. */
    public const BOT_STATUS = 'bot_status';
    /** The group title changed: chatTitle. */
    public const CHAT_TITLE = 'chat_title';

    public string $type;
    public string $chatId;
    /** private, group, channel (CmsCommConversation::CHAT_*) */
    public string $chatType;
    public ?string $chatTitle = null;
    public ?BotUser $sender = null;
    public ?string $messageId = null;
    public string $text = '';
    public ?string $replyToMessageId = null;
    /** @var BotFile[] */
    public array $files = [];
    /** Unix time of the message or event. */
    public int $date = 0;
    /** Start parameter, button payload. */
    public ?string $payload = null;
    /** An id the messenger wants answered (a button press), passed back to the driver. */
    public ?string $callbackId = null;
    /** A shared contact: phone digits, the messenger user it belongs to, verified by the driver. */
    public ?array $contact = null;
    /** @var BotUser[] */
    public array $members = [];
    /** member, admin, left (CmsCommConversation::BOT_*) */
    public ?string $botStatus = null;
    /** Rights of the bot in the chat, as the messenger names them. */
    public ?array $botPermissions = null;
    /** A message forwarded from elsewhere: who wrote it originally, as text. */
    public ?string $forwardedFrom = null;

    public function __construct(string $type, string $chatId, string $chatType)
    {
        $this->type = $type;
        $this->chatId = $chatId;
        $this->chatType = $chatType;
    }
}
