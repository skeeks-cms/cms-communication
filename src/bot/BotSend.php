<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\send\SendFile;
use skeeks\cms\communication\services\MailFiles;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\CmsUser;

/**
 * A message of the bot to a chat: queued in the CRM (the message and the job
 * comm.bot.send in one transaction), then delivered by the driver. The bot
 * receives no updates about its own messages, so whatever the bot says must
 * go through here, or it is missing from the conversation.
 *
 * Delivery: a text longer than the channel limit goes in parts, files one by
 * one after it; the messenger's pace per chat is kept. A refusal of the API
 * (nothing sent) fails the message with the reason; "too many requests"
 * waits in the queue and goes on with the parts not sent yet; any other
 * error leaves the outcome unknown and is never repeated.
 */
class BotSend
{
    /**
     * @param array $options user (CmsUser — who sends; signs the text), files (SendFile[]),
     *                       replyTo (CmsCommMessage of the chat), buttons, html, sign (bool, default true)
     * @throws SendException the message cannot go to this chat
     */
    public static function queue(CmsCommConversation $conversation, string $text, array $options = []): CmsCommMessage
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        /** @var SendFile[] $files */
        $files = array_values(array_filter((array)($options['files'] ?? []), static fn($f) => $f instanceof SendFile));
        /** @var CmsUser|null $user */
        $user = $options['user'] ?? null;
        $connection = $conversation->isChat() ? $conversation->connection : null;
        if (!$connection || !Bots::isBot($connection)) {
            throw new SendException(['conversation' => 'Это не чат бота.']);
        }
        if (!(int)$connection->is_active) {
            throw new SendException(['conversation' => 'Бот «'.$connection->name.'» выключен.']);
        }
        if ($conversation->bot_status === CmsCommConversation::BOT_LEFT) {
            throw new SendException(['conversation' => 'Бота нет в этом чате.']);
        }
        $driver = Bots::driver($connection);
        if (!Bots::isChatAllowed($driver::channel(), (string)$conversation->external_chat_id)) {
            throw new SendException(['conversation' => 'Чат не разрешён настройкой allowedChats.']);
        }
        if ($text === '' && !$files) {
            throw new SendException(['text' => 'Напишите сообщение.']);
        }
        foreach ($files as $file) {
            if ($file->size() > MailFiles::MAX_SIZE) {
                throw new SendException(['files' => 'Файл «'.$file->name.'» больше 25 МБ.']);
            }
        }

        $html = isset($options['html']) && trim((string)$options['html']) !== '' ? (string)$options['html'] : null;
        if ($user && $driver->signManager && ($options['sign'] ?? true)) {
            $signature = self::signature($user, (string)$driver->signName);
            $text = $signature."\n".$text;
            $html = $html !== null ? htmlspecialchars($signature, ENT_QUOTES).'<br>'.$html : null;
        }
        /** @var CmsCommMessage|null $replyTo */
        $replyTo = $options['replyTo'] ?? null;

        $transaction = CmsCommMessage::getDb()->beginTransaction();
        try {
            $message = new CmsCommMessage([
                'cms_site_id' => (int)$conversation->cms_site_id,
                'cms_comm_conversation_id' => (int)$conversation->id,
                'cms_comm_account_id' => (int)Bots::account($connection)->id,
                'channel' => $driver::channel(),
                'direction' => CmsCommMessage::DIRECTION_OUT,
                'status' => CmsCommMessage::STATUS_QUEUED,
                'external_id' => 'out.'.bin2hex(random_bytes(10)),
                'from_address' => $driver->botUsername ? '@'.ltrim((string)$driver->botUsername, '@') : null,
                'from_name' => (string)$connection->name,
                'subject' => $conversation->title,
                'body_text' => $text,
                'body_html' => $html,
                'attachments' => $files ? json_encode(array_map(static fn(SendFile $f) => ['name' => $f->name, 'size' => $f->size()], $files), JSON_UNESCAPED_UNICODE) : null,
                'message_at' => time(),
                'sent_by_user_id' => $user ? (int)$user->id : null,
                'reply_to_message_id' => $replyTo && (int)$replyTo->cms_comm_conversation_id === (int)$conversation->id ? (int)$replyTo->id : null,
            ]);
            if (!empty($options['buttons'])) {
                $message->provider_ref = json_encode(['buttons' => array_values((array)$options['buttons'])], JSON_UNESCAPED_UNICODE);
            }
            $message->save(false);
            foreach ($files as $i => $file) {
                $stored = $file->storageFile ?: MailFiles::storeReceived((string)file_get_contents((string)$file->path), $file->name, $file->type, (int)$conversation->cms_site_id);
                MailFiles::attach($message, $stored, $file->name, $file->type, $file->size() ?: null, null, false, 100, $i);
            }
            $conversation->last_message_at = time();
            BotIngest::dialogState($conversation, true, time());
            $conversation->save(false);
            \skeeks\cms\communication\chat\ChatFeed::onMessage($message, $conversation);
            \Yii::$app->jobs->push('comm.bot.send', ['messageId' => (int)$message->id, 'connectionId' => (int)$connection->id], ['siteId' => (int)$conversation->cms_site_id]);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $message;
    }

    /** "Анна Петрова (SkeekS):" */
    public static function signature(CmsUser $user, string $signName = ''): string
    {
        $name = trim(trim((string)$user->first_name).' '.trim((string)$user->last_name));
        if ($name === '') {
            $name = trim((string)$user->shortDisplayName);
        }
        $signName = trim($signName);

        return $name.($signName !== '' ? ' ('.$signName.')' : '').':';
    }

    /**
     * Delivers one queued message.
     *
     * @return array{0: string|null, 1: string|int|null} [status, error]; [null, null] — nothing to do;
     *                                                  ['wait', seconds] — wait in the queue and call again
     */
    public static function deliver(int $messageId, ?MessengerDriver $driver = null): array
    {
        $message = CmsCommMessage::findOne($messageId);
        if (!$message || $message->direction !== CmsCommMessage::DIRECTION_OUT) {
            return [null, null];
        }
        $progress = json_decode((string)$message->provider_ref, true);
        $progress = is_array($progress) ? $progress : [];
        if ($message->status === CmsCommMessage::STATUS_QUEUED) {
            // Outcome marker: from here on a crash leaves the message visibly uncertain.
            if (!CmsCommMessage::updateAll(['status' => CmsCommMessage::STATUS_SENDING, 'updated_at' => time()], ['id' => $messageId, 'status' => CmsCommMessage::STATUS_QUEUED])) {
                return [null, null];
            }
        } elseif ($message->status === CmsCommMessage::STATUS_SENDING && !empty($progress['wait'])) {
            // Waited for the messenger's limit: the parts sent so far are known.
            unset($progress['wait']);
            if (!CmsCommMessage::updateAll(['provider_ref' => self::encode($progress), 'updated_at' => time()], ['id' => $messageId, 'status' => CmsCommMessage::STATUS_SENDING, 'provider_ref' => $message->provider_ref])) {
                return [null, null];
            }
        } else {
            return [null, null];
        }

        $conversation = $message->conversation;
        $connection = $conversation ? $conversation->connection : null;
        if (!$connection) {
            return self::finish($message, CmsCommMessage::STATUS_FAILED, 'Чат или бот удалён.', $progress);
        }
        $driver = $driver ?? Bots::driver($connection);
        $chatId = (string)$conversation->external_chat_id;
        if (!Bots::isChatAllowed($driver::channel(), $chatId)) {
            return self::finish($message, CmsCommMessage::STATUS_FAILED, 'Чат не разрешён настройкой allowedChats.', $progress);
        }

        try {
            $items = self::items($message, $driver);
        } catch (BotException $e) {
            return self::finish($message, CmsCommMessage::STATUS_FAILED, $e->getMessage(), $progress);
        }
        $sent = array_values((array)($progress['sent'] ?? []));
        $replyTo = $message->reply_to_message_id ? CmsCommMessage::findOne((int)$message->reply_to_message_id) : null;
        $replyToId = $replyTo ? self::externalMessageId((string)$replyTo->external_id) : null;
        $interval = $driver->chatInterval((string)$conversation->chat_type);

        foreach ($items as $i => $outgoing) {
            if ($i < count($sent)) {
                continue;
            }
            $outgoing->replyToMessageId = $i === 0 ? $replyToId : null;
            self::pace($conversation->id, $interval);
            try {
                $sent[] = $driver->send($outgoing);
            } catch (BotException $e) {
                if ($e->retryAfter !== null) {
                    $progress['sent'] = $sent;
                    $progress['wait'] = 1;
                    CmsCommMessage::updateAll(['provider_ref' => self::encode($progress), 'updated_at' => time()], ['id' => $messageId]);

                    return ['wait', $e->retryAfter];
                }
                if ($e->blocked) {
                    self::markBlocked($conversation);
                }
                $error = $sent ? 'Отправлена только часть сообщения: '.$e->getMessage() : $e->getMessage();

                return self::finish($message, CmsCommMessage::STATUS_FAILED, $error, ['sent' => $sent] + $progress, $chatId);
            } catch (\Throwable $e) {
                \Yii::error('Сообщение бота #'.$messageId.': исход неизвестен: '.$e->getMessage(), self::class);

                return self::finish($message, CmsCommMessage::STATUS_UNKNOWN, 'Нет ответа мессенджера, неизвестно, доставлено ли: '.$e->getMessage(), ['sent' => $sent] + $progress, $chatId);
            } finally {
                self::$lastSent[(int)$conversation->id] = microtime(true);
            }
        }

        return self::finish($message, CmsCommMessage::STATUS_SENT, null, ['sent' => $sent] + $progress, $chatId);
    }

    /**
     * Parts of a text within the limit: cut at a paragraph, a line or a word
     * break, never inside a word if it can be helped.
     *
     * @return string[]
     */
    public static function split(string $text, int $limit): array
    {
        $text = trim($text);
        $parts = [];
        while (mb_strlen($text) > $limit) {
            $chunk = mb_substr($text, 0, $limit);
            $cut = false;
            foreach (["\n\n", "\n", ' '] as $separator) {
                $position = mb_strrpos($chunk, $separator);
                if ($position !== false && $position > $limit / 2) {
                    $cut = $position;
                    break;
                }
            }
            $cut = $cut === false ? $limit : $cut;
            $parts[] = rtrim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $parts[] = $text;
        }

        return $parts;
    }

    /** @var float[] conversation id => time of the last message sent by this process */
    private static array $lastSent = [];

    /** Keeps the messenger's pace inside one process (jobs of one bot run one at a time). */
    private static function pace(int $conversationId, float $interval): void
    {
        $last = self::$lastSent[$conversationId] ?? 0.0;
        $wait = $interval - (microtime(true) - $last);
        if ($last > 0 && $wait > 0) {
            usleep((int)ceil($wait * 1000000));
        }
    }

    /** @return BotOutgoing[] text parts, then one message per file */
    private static function items(CmsCommMessage $message, MessengerDriver $driver): array
    {
        $chatId = (string)$message->conversation->external_chat_id;
        $items = [];
        $text = (string)$message->body_text;
        $parts = $text !== '' ? self::split($text, $driver->textLimit()) : [];
        $meta = json_decode((string)$message->provider_ref, true);
        $buttons = is_array($meta) ? (array)($meta['buttons'] ?? []) : [];
        foreach ($parts as $i => $part) {
            $outgoing = new BotOutgoing($chatId);
            $outgoing->text = $part;
            // Formatting survives only when the text fits one message.
            $outgoing->html = count($parts) === 1 ? $message->body_html : null;
            $outgoing->buttons = $i === count($parts) - 1 ? $buttons : [];
            $items[] = $outgoing;
        }
        /** @var CmsCommMessageFile[] $files */
        $files = CmsCommMessageFile::find()->andWhere(['cms_comm_message_id' => (int)$message->id])->orderBy(['part_index' => SORT_ASC, 'id' => SORT_ASC])->all();
        foreach ($files as $file) {
            $storage = $file->storage_file_id ? CmsStorageFile::findOne((int)$file->storage_file_id) : null;
            if (!$storage || !is_file((string)$storage->rootSrc)) {
                throw new BotException('Файл «'.$file->name.'» не найден в хранилище.');
            }
            $outgoing = new BotOutgoing($chatId);
            $outgoing->file = SendFile::path((string)$storage->rootSrc, (string)$file->name, $file->mime_type);
            if (!$parts && $buttons && $file === end($files)) {
                $outgoing->buttons = $buttons;
            }
            $items[] = $outgoing;
        }

        return $items;
    }

    private static function finish(CmsCommMessage $message, string $status, ?string $error, array $progress, ?string $chatId = null): array
    {
        unset($progress['wait']);
        $attributes = [
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'provider_ref' => self::encode($progress),
            'updated_at' => time(),
        ];
        $first = $progress['sent'][0] ?? null;
        if ($status === CmsCommMessage::STATUS_SENT) {
            $attributes['message_at'] = time();
        }
        if ($first !== null && $chatId !== null) {
            // The id the messenger gave: replies and edits of the client find this message.
            $attributes['external_id'] = BotIngest::key($message->conversation->connection, $chatId, (string)$first);
        }
        CmsCommMessage::updateAll($attributes, ['id' => (int)$message->id]);

        return [$status, $error];
    }

    private static function markBlocked(CmsCommConversation $conversation): void
    {
        if ($conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE) {
            return;
        }
        // The person of a dialog is the author of its incoming messages (a MAX dialog id is not the user id).
        $ids = CmsCommMessage::find()->select('cms_comm_identity_id')->distinct()
            ->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'direction' => CmsCommMessage::DIRECTION_IN])
            ->andWhere(['not', ['cms_comm_identity_id' => null]])->column();
        if ($ids) {
            CmsCommIdentity::updateAll(['is_blocked' => 1, 'updated_at' => time()], ['id' => $ids]);
        }
    }

    private static function externalMessageId(string $externalId): ?string
    {
        $position = strrpos($externalId, ':');

        return $position === false || str_starts_with($externalId, 'out.') ? null : substr($externalId, $position + 1);
    }

    private static function encode(array $progress): ?string
    {
        return $progress ? json_encode($progress, JSON_UNESCAPED_UNICODE) : null;
    }
}
