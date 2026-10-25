<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommBotUpdate;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\models\CmsCommParticipant;
use skeeks\cms\communication\services\SendContacts;

/**
 * Applies bot events the same way for every messenger: the identity of the
 * person, the conversation of the chat (`<connection id>:<chat id>`), group
 * participants, the message (`<connection id>:<chat id>:<message id>`, one per site
 * channel — a repeated delivery or an imported history never doubles it),
 * its files (downloaded by the job comm.bot.fetch-file), edits and removals,
 * the state of the bot in the chat; then the registered handlers
 * (`communication.botHandlers`).
 *
 * One stored update is applied in one transaction with its journal row
 * (apply()); a failing update is tried again up to CmsCommBotUpdate::MAX_ATTEMPTS.
 */
class BotIngest
{
    private CmsCommConnection $connection;
    private MessengerDriver $driver;
    private ?CmsCommAccount $account = null;
    /** @var int[] messages whose files wait for download */
    private array $filesPending = [];
    /** @var int[] new chats whose picture waits for download */
    private array $infoPending = [];

    public function __construct(CmsCommConnection $connection, ?MessengerDriver $driver = null)
    {
        $this->connection = $connection;
        $this->driver = $driver ?? Bots::driver($connection);
    }

    public function account(): CmsCommAccount
    {
        return $this->account ??= Bots::account($this->connection);
    }

    /**
     * Applies one stored update and records the outcome in its row.
     *
     * @return string the new status of the row
     */
    public function apply(CmsCommBotUpdate $row): string
    {
        $this->filesPending = [];
        $this->infoPending = [];
        $transaction = CmsCommBotUpdate::getDb()->beginTransaction();
        try {
            $events = $this->driver->parseUpdate($row->getPayloadData());
            $messageId = null;
            foreach ($events as $event) {
                $message = $this->applyEvent($event);
                $messageId = $message ? (int)$message->id : $messageId;
            }
            $row->status = $events ? CmsCommBotUpdate::STATUS_APPLIED : CmsCommBotUpdate::STATUS_SKIPPED;
            $row->applied_at = time();
            $row->error = null;
            $row->attempts = (int)$row->attempts + 1;
            $row->cms_comm_message_id = $messageId;
            $row->save(false, ['status', 'applied_at', 'error', 'attempts', 'cms_comm_message_id']);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $row->attempts = (int)$row->attempts + 1;
            $row->error = mb_substr(get_class($e).': '.$e->getMessage(), 0, 2000);
            $row->status = $row->attempts >= CmsCommBotUpdate::MAX_ATTEMPTS ? CmsCommBotUpdate::STATUS_FAILED : CmsCommBotUpdate::STATUS_NEW;
            $row->save(false, ['status', 'error', 'attempts']);
            \Yii::error('Событие бота #'.(int)$row->id.' не обработано: '.$e->getMessage(), self::class);

            return (string)$row->status;
        }
        $this->queueFiles();

        return (string)$row->status;
    }

    /**
     * Events of the chat history (an import through the messenger API): stored
     * like received messages but as history — read, no handlers, no
     * notifications. Messages already known are left as they are.
     *
     * @param BotEvent[] $events
     * @return int new messages
     */
    public function applyHistory(array $events): int
    {
        $this->filesPending = [];
        $this->infoPending = [];
        $created = 0;
        $transaction = CmsCommMessage::getDb()->beginTransaction();
        try {
            foreach ($events as $event) {
                if ($event->type !== BotEvent::MESSAGE) {
                    continue;
                }
                $known = $this->messageKey($event) !== null && $this->findMessage($this->messageKey($event));
                if (!$known && $this->applyEvent($event, true)) {
                    $created++;
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        $this->queueFiles();

        return $created;
    }

    /** Applies one event inside the caller's transaction; returns the message it wrote, if any. */
    public function applyEvent(BotEvent $event, bool $history = false): ?CmsCommMessage
    {
        $conversation = $this->conversation($event);
        $identity = $event->sender ? $this->identity($event->sender, $event->date) : null;
        $message = null;

        switch ($event->type) {
            case BotEvent::MESSAGE:
            case BotEvent::EDITED:
                $message = $this->message($conversation, $identity, $event, $history);
                break;
            case BotEvent::REMOVED:
                $message = $this->messageKey($event) !== null ? $this->findMessage($this->messageKey($event)) : null;
                if ($message && !$message->removed_at) {
                    $message->removed_at = $event->date ?: time();
                    $message->save(false, ['removed_at', 'updated_at']);
                }
                break;
            case BotEvent::REACTION:
                // The reactions of the person on the message now; the message itself is not changed.
                $reacted = $this->messageKey($event) !== null ? $this->findMessage($this->messageKey($event)) : null;
                if ($reacted && $identity) {
                    \skeeks\cms\communication\chat\ChatReactions::replace($reacted, $identity, $event->reactions);
                }
                break;
            case BotEvent::MEMBERS_JOINED:
            case BotEvent::MEMBERS_LEFT:
                $this->members($conversation, $event);
                break;
            case BotEvent::BOT_STATUS:
                $conversation->bot_status = $event->botStatus;
                if ($event->botPermissions !== null) {
                    $conversation->bot_permissions = json_encode(array_values($event->botPermissions), JSON_UNESCAPED_UNICODE);
                }
                $conversation->save(false);
                break;
            case BotEvent::CHAT_TITLE:
                if ($event->chatTitle !== null && $event->chatTitle !== '') {
                    $conversation->title = mb_substr($event->chatTitle, 0, 255);
                    $conversation->subject = $conversation->title;
                    $conversation->save(false);
                }
                break;
            case BotEvent::STARTED:
            case BotEvent::STOPPED:
                if ($identity) {
                    $identity->is_blocked = (int)($event->type === BotEvent::STOPPED);
                    $identity->save(false, ['is_blocked', 'updated_at']);
                }
                break;
        }

        if ($identity && !$this->isBotItself($event->sender) && $conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE && in_array($event->type, [BotEvent::MESSAGE, BotEvent::EDITED], true)) {
            $this->participant($conversation, $identity, null, $event->date, false, true);
        }

        if (!$history) {
            $this->handle(new BotEventContext($event, $this->connection, $this->account(), $conversation, $identity, $message));
        }

        return $message;
    }

    /**
     * The key of a chat message: `<connection id>:<chat id>:<message id>`.
     * With the bot in it: Telegram numbers messages per bot, and a private
     * chat id is the person's id, so two bots of one site get equal chat and
     * message ids from the same person.
     */
    public static function key(CmsCommConnection $connection, string $chatId, string $messageId): string
    {
        return (int)$connection->id.':'.$chatId.':'.$messageId;
    }

    public function messageKey(BotEvent $event): ?string
    {
        return $event->messageId !== null ? self::key($this->connection, $event->chatId, $event->messageId) : null;
    }

    public function findMessage(string $key): ?CmsCommMessage
    {
        return CmsCommMessage::find()->andWhere([
            'cms_site_id' => (int)$this->connection->cms_site_id,
            'channel' => $this->driver::channel(),
            'external_id' => $key,
        ])->one();
    }

    /** The conversation of a chat of this bot, created on its first event. */
    public function conversation(BotEvent $event): CmsCommConversation
    {
        $key = (int)$this->connection->id.':'.$event->chatId;
        $conversation = CmsCommConversation::find()->andWhere([
            'cms_site_id' => (int)$this->connection->cms_site_id,
            'channel' => $this->driver::channel(),
            'thread_key' => $key,
        ])->one();
        $title = $event->chatType === CmsCommConversation::CHAT_PRIVATE
            ? ($event->sender && !$this->isBotItself($event->sender) ? $this->displayName($event->sender) : null)
            : $event->chatTitle;
        if (!$conversation) {
            $conversation = new CmsCommConversation([
                'cms_site_id' => (int)$this->connection->cms_site_id,
                'channel' => $this->driver::channel(),
                'thread_key' => $key,
                'cms_comm_connection_id' => (int)$this->connection->id,
                'chat_type' => $event->chatType,
                'external_chat_id' => $event->chatId,
                'bot_status' => CmsCommConversation::BOT_MEMBER,
            ]);
        }
        if ($conversation->isNewRecord && ($title === null || $title === '') && $event->chatType !== CmsCommConversation::CHAT_PRIVATE) {
            // MAX messages carry only the chat id: the driver asks the messenger once.
            try {
                $title = ($this->driver->chatInfo($event->chatId) ?? [])['title'] ?? null;
            } catch (\Throwable $e) {
                \Yii::warning('Название чата '.$event->chatId.' не получено: '.$e->getMessage(), self::class);
            }
        }
        if ($title !== null && $title !== '' && ($conversation->isNewRecord || $conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE || !$conversation->title)) {
            $conversation->title = mb_substr($title, 0, 255);
            $conversation->subject = $conversation->title;
        }
        $new = $conversation->isNewRecord;
        if ($conversation->isNewRecord || $conversation->getDirtyAttributes()) {
            $conversation->save(false);
        }
        if ($new) {
            // The picture of the chat (a group icon, the person's photo) is fetched by a job.
            $this->infoPending[] = (int)$conversation->id;
        }

        return $conversation;
    }

    public function identity(BotUser $user, int $date = 0): CmsCommIdentity
    {
        $identity = CmsCommIdentity::find()->andWhere([
            'cms_site_id' => (int)$this->connection->cms_site_id,
            'channel' => $this->driver::channel(),
            'external_id' => $user->id,
        ])->one() ?: new CmsCommIdentity([
            'cms_site_id' => (int)$this->connection->cms_site_id,
            'channel' => $this->driver::channel(),
            'external_id' => $user->id,
        ]);
        $seen = $date ?: time();
        // Names change; only a newer event updates them (history goes back in time).
        if ($identity->isNewRecord || $seen >= (int)$identity->last_seen_at) {
            if ($user->name !== '') {
                $identity->name = mb_substr($user->name, 0, 255);
            }
            $identity->username = $user->username !== null ? mb_substr($user->username, 0, 255) : $identity->username;
            $identity->last_seen_at = $seen;
        }
        $identity->is_bot = (int)$user->isBot;
        if ($identity->isNewRecord || $identity->getDirtyAttributes()) {
            $identity->save(false);
        }

        return $identity;
    }

    private function message(CmsCommConversation $conversation, ?CmsCommIdentity $identity, BotEvent $event, bool $history): ?CmsCommMessage
    {
        $key = $this->messageKey($event);
        if ($key === null) {
            return null;
        }
        $text = $this->text($event);
        $message = $this->findMessage($key);
        if ($message) {
            if ($event->type === BotEvent::EDITED && (string)$message->body_text !== $text) {
                $message->body_text = $text;
                $message->edited_at = $event->date ?: time();
                $message->save(false, ['body_text', 'edited_at', 'updated_at']);
            }

            return $message;
        }

        $out = $event->sender && $this->isBotItself($event->sender);
        $date = $event->date ?: time();
        $replyTo = $event->replyToMessageId !== null ? $this->findMessage(self::key($this->connection, $event->chatId, $event->replyToMessageId)) : null;
        $message = new CmsCommMessage([
            'cms_site_id' => (int)$this->connection->cms_site_id,
            'cms_comm_conversation_id' => (int)$conversation->id,
            'cms_comm_account_id' => (int)$this->account()->id,
            'channel' => $this->driver::channel(),
            'direction' => $out ? CmsCommMessage::DIRECTION_OUT : CmsCommMessage::DIRECTION_IN,
            'status' => $out ? CmsCommMessage::STATUS_SENT : CmsCommMessage::STATUS_RECEIVED,
            'external_id' => $key,
            'from_address' => $event->sender && $event->sender->username ? '@'.$event->sender->username : null,
            'from_name' => $event->sender ? mb_substr($this->displayName($event->sender), 0, 255) : ($conversation->title ?: null),
            'subject' => $conversation->title,
            'body_text' => $text,
            'attachments' => $event->files ? json_encode(array_map(static fn(BotFile $f) => ['name' => $f->name, 'size' => $f->size], $event->files), JSON_UNESCAPED_UNICODE) : null,
            'message_at' => $date,
            'is_history' => (int)$history,
            'cms_comm_identity_id' => $identity ? (int)$identity->id : null,
            'reply_to_message_id' => $replyTo ? (int)$replyTo->id : null,
            'edited_at' => $event->type === BotEvent::EDITED ? $date : null,
        ]);
        $message->save(false);

        foreach (array_values($event->files) as $i => $file) {
            $row = new CmsCommMessageFile([
                'cms_comm_message_id' => (int)$message->id,
                'name' => mb_substr($file->name, 0, 255),
                'mime_type' => $file->mime ? mb_substr(strtolower($file->mime), 0, 255) : null,
                'size' => $file->size,
                'is_inline' => 0,
                'priority' => 100,
                'part_index' => $i,
                'external_ref' => mb_substr($file->ref, 0, 1000),
            ]);
            $row->save(false);
        }
        if ($event->files) {
            $this->filesPending[] = (int)$message->id;
        }

        if ((int)$conversation->last_message_at < $date) {
            $conversation->last_message_at = $date;
        }
        if (!$history && $conversation->chat_type !== CmsCommConversation::CHAT_CHANNEL) {
            self::dialogState($conversation, $out || ($identity && $identity->isWorker()), $date);
        }
        if ($conversation->getDirtyAttributes()) {
            $conversation->save(false);
        }
        // Feeds of the records the chat is linked to: the message (a dialog) or the day's summary (a group).
        \skeeks\cms\communication\chat\ChatFeed::onMessage($message, $conversation);

        return $message;
    }

    /**
     * The dialog after a message: a message of the client opens a closed
     * dialog and starts "ждёт ответа" (from its first unanswered message);
     * a message of ours (from the CRM or an employee in a group) answers it.
     * The caller saves the conversation.
     */
    public static function dialogState(CmsCommConversation $conversation, bool $ours, int $date): void
    {
        if ($ours) {
            $conversation->waiting_since = null;
            return;
        }
        if (!$conversation->waiting_since) {
            $conversation->waiting_since = $date ?: time();
        }
        if ($conversation->closed_at) {
            $conversation->closed_at = null;
            $conversation->closed_by = null;
        }
    }

    /** Text of a message as employees read it: forwarded author and a shared contact are written out. */
    private function text(BotEvent $event): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $event->text);
        if ($event->contact && !empty($event->contact['phone'])) {
            $phone = SendContacts::formatPhone((string)$event->contact['phone']);
            $text = trim($text."\nКонтакт: ".$phone.(!empty($event->contact['name']) ? ', '.$event->contact['name'] : ''));
        }
        if ($event->forwardedFrom !== null && $event->forwardedFrom !== '') {
            $text = 'Переслано от '.$event->forwardedFrom.":\n".$text;
        }

        return $text;
    }

    private function members(CmsCommConversation $conversation, BotEvent $event): void
    {
        $left = $event->type === BotEvent::MEMBERS_LEFT;
        foreach ($event->members as $member) {
            if ($this->isBotItself($member)) {
                $conversation->bot_status = $left ? CmsCommConversation::BOT_LEFT : CmsCommConversation::BOT_MEMBER;
                $conversation->save(false, ['bot_status', 'updated_at']);
                continue;
            }
            $this->participant($conversation, $this->identity($member, $event->date), $member->role, $event->date, $left, false);
        }
    }

    private function participant(CmsCommConversation $conversation, CmsCommIdentity $identity, ?string $role, int $date, bool $left, bool $wrote): void
    {
        $date = $date ?: time();
        $participant = CmsCommParticipant::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'cms_comm_identity_id' => (int)$identity->id])->one()
            ?: new CmsCommParticipant(['cms_comm_conversation_id' => (int)$conversation->id, 'cms_comm_identity_id' => (int)$identity->id, 'joined_at' => $date]);
        if ($role !== null) {
            $participant->role = $role;
        }
        if ($left) {
            $participant->left_at = $date;
        } elseif ($participant->left_at && $date >= (int)$participant->left_at) {
            // Came back, or wrote after the bot saw them leave.
            $participant->left_at = null;
            $participant->joined_at = $date;
        }
        if ($wrote && $date > (int)$participant->last_message_at) {
            $participant->last_message_at = $date;
        }
        if ($participant->isNewRecord || $participant->getDirtyAttributes()) {
            $participant->save(false);
        }
    }

    private function isBotItself(BotUser $user): bool
    {
        return (string)$this->driver->botId !== '' && $user->id === (string)$this->driver->botId;
    }

    private function displayName(BotUser $user): string
    {
        return $user->name !== '' ? $user->name : ($user->username ? '@'.$user->username : '#'.$user->id);
    }

    private function handle(BotEventContext $context): void
    {
        foreach ((array)\Yii::$app->communication->botHandlers as $id => $config) {
            $handler = \Yii::createObject($config);
            if (!$handler instanceof BotEventHandlerInterface) {
                throw new \yii\base\InvalidConfigException("Обработчик событий бота «{$id}» должен реализовать ".BotEventHandlerInterface::class);
            }
            $handler->handle($context);
        }
    }

    /** Files of new messages are downloaded right away: messenger links do not live long. */
    private function queueFiles(): void
    {
        foreach (array_unique($this->filesPending) as $messageId) {
            try {
                \Yii::$app->jobs->push('comm.bot.fetch-file', ['messageId' => $messageId], ['siteId' => (int)$this->connection->cms_site_id]);
            } catch (\Throwable $e) {
                \Yii::error('Не удалось поставить загрузку файлов сообщения #'.$messageId.': '.$e->getMessage(), self::class);
            }
        }
        $this->filesPending = [];
        foreach (array_unique($this->infoPending) as $conversationId) {
            try {
                \Yii::$app->jobs->push('comm.bot.chat-info', ['conversationId' => $conversationId], ['siteId' => (int)$this->connection->cms_site_id]);
            } catch (\Throwable $e) {
                \Yii::error('Не удалось поставить обновление фото чата #'.$conversationId.': '.$e->getMessage(), self::class);
            }
        }
        $this->infoPending = [];
    }
}
