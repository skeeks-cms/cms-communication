<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\tests;

use skeeks\cms\communication\bot\BotEvent;
use skeeks\cms\communication\bot\BotException;
use skeeks\cms\communication\bot\BotFile;
use skeeks\cms\communication\bot\BotOutgoing;
use skeeks\cms\communication\bot\BotPollResult;
use skeeks\cms\communication\bot\BotUser;
use skeeks\cms\communication\bot\MessengerDriver;

/**
 * A messenger for tests: updates are plain arrays, nothing leaves the
 * process. Static state lets a test see what was sent and make the next
 * call fail.
 *
 * Update: ['id' => 1, 'type' => 'message'|'edited'|'removed'|'joined'|'left'|'bot'|'title'|'start'|'stop'|'callback',
 *          'chat' => ['id' => '-100', 'type' => 'group', 'title' => '…'], 'from' => ['id' => '7', 'name' => '…', 'username' => '…'],
 *          'message_id' => '10', 'text' => '…', 'date' => 1800000000, 'reply_to' => '9', 'files' => [['ref' => 'f1', 'name' => 'a.pdf', 'size' => 10]],
 *          'members' => [['id' => …]], 'status' => 'admin', 'rights' => [...], 'payload' => '…', 'contact' => [...]]
 */
class FakeMessengerDriver extends MessengerDriver
{
    public static array $sent = [];
    public static array $webhook = ['url' => null, 'secret' => null];
    /** @var \Throwable[] thrown by the next send() calls, in order */
    public static array $failNext = [];
    public static array $files = [];
    public static array $pollQueue = [];
    /** @var array chat id => ['title' => …, 'photo' => …] */
    public static array $chats = [];
    public static int $nextId = 500;
    /** @var array reactions the bot put: [chat, message, emoji|null] */
    public static array $reacted = [];

    public static function title(): string
    {
        return 'Тестовый мессенджер';
    }

    public static function channel(): string
    {
        return 'testbot';
    }

    public static function reset(): void
    {
        self::$sent = [];
        self::$webhook = ['url' => null, 'secret' => null];
        self::$failNext = [];
        self::$files = [];
        self::$pollQueue = [];
        self::$chats = [];
        self::$members = [];
        self::$nextId = 500;
        self::$reacted = [];
    }

    public function reactionChoices(): array
    {
        return ['👍', '🔥'];
    }

    public function react(string $chatId, string $messageId, ?string $emoji): void
    {
        if (self::$failNext) {
            throw array_shift(self::$failNext);
        }
        self::$reacted[] = [$chatId, $messageId, $emoji];
    }

    public function textLimit(): int
    {
        return 50;
    }

    public function chatInterval(string $chatType): float
    {
        return 0.0;
    }

    public function webhookSecretHeader(): string
    {
        return 'X-Test-Bot-Secret';
    }

    public function getMe(): BotUser
    {
        return new BotUser((string)$this->botId, 'Бот', 'test_bot', true);
    }

    public function updateKey(array $update): ?string
    {
        return isset($update['id']) ? 'u'.$update['id'] : null;
    }

    public function updateType(array $update): ?string
    {
        return $update['type'] ?? null;
    }

    public function parseUpdate(array $update): array
    {
        $chat = $update['chat'] ?? [];
        $map = ['message' => BotEvent::MESSAGE, 'edited' => BotEvent::EDITED, 'removed' => BotEvent::REMOVED, 'joined' => BotEvent::MEMBERS_JOINED,
            'left' => BotEvent::MEMBERS_LEFT, 'bot' => BotEvent::BOT_STATUS, 'title' => BotEvent::CHAT_TITLE, 'start' => BotEvent::STARTED,
            'stop' => BotEvent::STOPPED, 'callback' => BotEvent::CALLBACK, 'reaction' => BotEvent::REACTION];
        $type = $map[$update['type'] ?? ''] ?? null;
        if (!$type || !isset($chat['id'])) {
            return [];
        }
        if (($update['fail'] ?? false) === true) {
            throw new \RuntimeException('Тестовый сбой разбора');
        }
        $event = new BotEvent($type, (string)$chat['id'], (string)($chat['type'] ?? 'private'));
        $event->chatTitle = $chat['title'] ?? null;
        $event->sender = isset($update['from']) ? $this->user($update['from']) : null;
        $event->messageId = isset($update['message_id']) ? (string)$update['message_id'] : null;
        $event->text = (string)($update['text'] ?? '');
        $event->date = (int)($update['date'] ?? 1800000000);
        $event->replyToMessageId = isset($update['reply_to']) ? (string)$update['reply_to'] : null;
        $event->payload = $update['payload'] ?? null;
        $event->contact = $update['contact'] ?? null;
        $event->forwardedFrom = $update['forwarded'] ?? null;
        foreach ((array)($update['files'] ?? []) as $file) {
            $event->files[] = new BotFile((string)$file['ref'], (string)($file['name'] ?? ''), BotFile::KIND_FILE, $file['mime'] ?? null, $file['size'] ?? null);
        }
        foreach ((array)($update['members'] ?? []) as $member) {
            $event->members[] = $this->user($member);
        }
        $event->reactions = (array)($update['reactions'] ?? []);
        $event->botStatus = $update['status'] ?? null;
        $event->botPermissions = $update['rights'] ?? null;
        $event->chatTitle = $type === BotEvent::CHAT_TITLE ? ($update['title'] ?? null) : $event->chatTitle;

        return [$event];
    }

    public function chatInfo(string $chatId): ?array
    {
        return self::$chats[$chatId] ?? null;
    }

    /** @var array chat id => [['id' => …, 'name' => …, 'role' => …, 'photo' => …]] */
    public static array $members = [];

    public function members(string $chatId): array
    {
        return array_map(function (array $m) {
            return ['user' => $this->user($m), 'photo' => $m['photo'] ?? null];
        }, self::$members[$chatId] ?? []);
    }

    public function setWebhook(string $url, string $secret): void
    {
        self::$webhook = ['url' => $url, 'secret' => $secret];
    }

    public function deleteWebhook(): void
    {
        self::$webhook = ['url' => null, 'secret' => null];
    }

    public function getWebhook(): array
    {
        return ['url' => self::$webhook['url'], 'error' => null];
    }

    public function poll(?string $cursor, int $timeout): BotPollResult
    {
        $updates = array_values(array_filter(self::$pollQueue, static fn($u) => $cursor === null || (int)$u['id'] >= (int)$cursor));
        $last = $updates ? (int)end($updates)['id'] + 1 : $cursor;

        return new BotPollResult($updates, $last !== null ? (string)$last : null);
    }

    public function send(BotOutgoing $message): string
    {
        if (self::$failNext) {
            throw array_shift(self::$failNext);
        }
        $id = (string)self::$nextId++;
        self::$sent[] = ['id' => $id, 'chat' => $message->chatId, 'text' => $message->text, 'html' => $message->html,
            'file' => $message->file ? $message->file->name : null, 'reply' => $message->replyToMessageId, 'buttons' => $message->buttons];

        return $id;
    }

    public function downloadFile(string $ref): array
    {
        if (!isset(self::$files[$ref])) {
            throw new BotException('Файл недоступен в мессенджере.');
        }
        $path = tempnam(sys_get_temp_dir(), 'fakebot');
        file_put_contents($path, self::$files[$ref]);

        return ['path' => $path, 'name' => null, 'type' => 'text/plain'];
    }

    private function user(array $data): BotUser
    {
        $user = new BotUser((string)$data['id'], (string)($data['name'] ?? ''), $data['username'] ?? null, (bool)($data['bot'] ?? false));
        $user->role = $data['role'] ?? null;

        return $user;
    }
}
