<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\drivers\ConnectionDriver;

/**
 * Transport of a messenger bot (packages cms-communication-telegram,
 * cms-communication-max). A driver only speaks the messenger protocol: it
 * turns raw updates into BotEvent objects, sends one BotOutgoing, downloads a
 * file, manages the webhook and polls. Storing updates, the order of
 * applying them, conversations, identities, files, notifications, links and
 * rights are the package's (BotUpdates, BotIngest, BotSend, BotFiles).
 *
 * Settings: the token and the webhook secret are encrypted secrets of the
 * connection; `apiUrl` and `proxy` let a site reach the messenger API through
 * another address (MAX moved its API, Telegram may be unreachable from
 * Russian servers). A proxy URL may hold a password, so it is a secret too.
 *
 * Errors: a refusal of the API (nothing was sent) is BotException; any other
 * exception means the outcome is unknown, and a send is never repeated then.
 */
abstract class MessengerDriver extends ConnectionDriver
{
    public const MODE_WEBHOOK = 'webhook';
    public const MODE_POLL = 'poll';

    /** @var string bot token */
    public $token;
    /** @var string secret the messenger sends back with every webhook request */
    public $webhookSecret;
    /** @var string webhook (production) or poll (a site without public HTTPS) */
    public $mode = self::MODE_WEBHOOK;
    /** @var string|null API address instead of the default one */
    public $apiUrl;
    /** @var string|null proxy for API requests, for example http://user:pass@host:3128 */
    public $proxy;
    /**
     * @var string|null address the messenger delivers the webhook to instead of
     * the site: a relay that passes /~comm-bot/<id> to the site unchanged, with
     * the secret header (Telegram cannot reach Russian servers)
     */
    public $webhookBaseUrl;
    /** @var bool first line of a reply from the CRM is the employee's name */
    public $signManager = true;
    /** @var string|null added to the employee's name: "Имя (SkeekS):" */
    public $signName;
    /** @var string|null id of the bot itself, filled by "Проверить подключение" (getMe) */
    public $botId;
    /** @var string|null username of the bot, for links t.me/<name>, max.ru/<name> */
    public $botUsername;

    public function rules()
    {
        return [
            [['token', 'webhookSecret', 'apiUrl', 'proxy', 'webhookBaseUrl', 'signName', 'botId', 'botUsername'], 'filter', 'filter' => static fn($value) => $value === null ? null : trim((string)$value)],
            [['webhookBaseUrl'], 'url', 'validSchemes' => ['https'], 'skipOnEmpty' => true],
            [['token'], 'required', 'when' => fn() => $this->requireSecrets],
            [['mode'], 'in', 'range' => [self::MODE_WEBHOOK, self::MODE_POLL]],
            [['apiUrl'], 'url', 'validSchemes' => ['http', 'https', 'socks5'], 'skipOnEmpty' => true],
            // A proxy may carry a login and a password: http://user:pass@host:3128.
            [['proxy'], 'url', 'validSchemes' => ['http', 'https', 'socks5', 'socks5h'], 'skipOnEmpty' => true,
                'pattern' => '/^{schemes}:\/\/([^\s\/@:]+(:[^\s\/@]*)?@)?(([A-Z0-9][A-Z0-9_-]*)(\.[A-Z0-9][A-Z0-9_-]*)+)(?::\d{1,5})?\/?$/i'],
            [['signManager'], 'boolean'],
            [['signName'], 'string', 'max' => 64],
            [['botId', 'botUsername'], 'string', 'max' => 255],
            [['webhookSecret'], 'match', 'pattern' => '/^[A-Za-z0-9_-]{16,128}$/', 'skipOnEmpty' => true],
        ];
    }

    public function attributeLabels()
    {
        return [
            'token' => 'Токен бота',
            'mode' => 'Как получать сообщения',
            'apiUrl' => 'Адрес API',
            'proxy' => 'Прокси для запросов к API',
            'webhookBaseUrl' => 'Адрес приёма вебхука',
            'signManager' => 'Подписывать ответы именем сотрудника',
            'signName' => 'Подпись после имени',
            'botUsername' => 'Имя бота',
        ];
    }

    public function attributeHints()
    {
        return [
            'token' => 'Хранится зашифрованным. Пустое поле оставляет сохранённый токен.',
            'mode' => 'Вебхук — сообщения приходят сразу (нужен сайт по HTTPS). Опрос — для сайтов без HTTPS, с задержкой до минуты.',
            'apiUrl' => 'Пусто — адрес по умолчанию.',
            'proxy' => 'Если сервер не видит API мессенджера напрямую.',
            'webhookBaseUrl' => 'Пусто — адрес сайта. Если мессенджер не достаёт до сайта напрямую, укажите узел-посредник по HTTPS, например https://узел:8443: он пересылает /~comm-bot/<номер бота> на сайт без изменений, вместе с заголовком секрета.',
            'signName' => 'Например, название компании: «Анна (SkeekS):».',
        ];
    }

    public function secretAttributes(): array
    {
        return ['token', 'webhookSecret', 'proxy'];
    }

    /** Fields of the bot form; the bot id and name are filled from the messenger, not typed. */
    public function getConfigFormFields()
    {
        return [
            'token',
            'mode' => ['class' => \skeeks\yii2\form\fields\SelectField::class, 'items' => self::modes(), 'allowNull' => false],
            'signManager' => ['class' => \skeeks\yii2\form\fields\BoolField::class, 'allowNull' => false],
            'signName',
            'apiUrl',
            'proxy',
            'webhookBaseUrl',
        ];
    }

    public static function modes(): array
    {
        return [self::MODE_WEBHOOK => 'Вебхук', self::MODE_POLL => 'Опрос'];
    }

    /** Longest text of one message. */
    abstract public function textLimit(): int;

    /** Seconds between two messages to one chat (the messenger's limit). */
    abstract public function chatInterval(string $chatType): float;

    /** Header the messenger puts the webhook secret into. */
    abstract public function webhookSecretHeader(): string;

    /** Who the bot is (checks the token). Never throws the token in a message. */
    abstract public function getMe(): BotUser;

    /**
     * The key of a raw update: a repeated delivery of the same update gives
     * the same key (unique per connection), different updates never share one.
     * Null — the update is not understood and is not stored.
     */
    abstract public function updateKey(array $update): ?string;

    /** Type of a raw update for the journal. */
    abstract public function updateType(array $update): ?string;

    /**
     * The update in the common form. An empty list — nothing to apply
     * (the update is marked skipped).
     *
     * @return BotEvent[]
     */
    abstract public function parseUpdate(array $update): array;

    /** Subscribes the bot to the webhook URL with the secret. */
    abstract public function setWebhook(string $url, string $secret): void;

    abstract public function deleteWebhook(): void;

    /**
     * The current subscription: ['url' => string|null, 'error' => string|null]
     * (the last delivery error the messenger reports, if any).
     */
    abstract public function getWebhook(): array;

    /** One poll with a timeout in seconds; never used while a webhook is set. */
    abstract public function poll(?string $cursor, int $timeout): BotPollResult;

    /**
     * Sends one message.
     *
     * @return string id of the sent message in its chat
     * @throws BotException the API refused, nothing was sent
     */
    abstract public function send(BotOutgoing $message): string;

    /**
     * Downloads a file of an incoming message into a local temporary file.
     *
     * @return array{path: string, name: string|null, type: string|null}
     * @throws BotException the file is not available (too large, expired)
     */
    abstract public function downloadFile(string $ref): array;

    /**
     * Reactions the bot may put on a message from the CRM, in the order of
     * the picker. Empty — the messenger gives bots no reactions (MAX).
     *
     * @return string[] emoji
     */
    public function reactionChoices(): array
    {
        return [];
    }

    /**
     * The bot's reaction on a message (null — remove it). A messenger with
     * reactions overrides it; a refusal is a BotException.
     */
    public function react(string $chatId, string $messageId, ?string $emoji): void
    {
        throw new BotException('Мессенджер не даёт ботам ставить реакции.');
    }

    /**
     * The current subscription (getWebhook()) lacks something this driver
     * now asks for (new kinds of updates): the bot is subscribed again.
     */
    public function webhookOutdated(array $current): bool
    {
        return false;
    }

    /**
     * Answers a button press (removes the "loading" state in the messenger).
     * Messengers that need no answer do nothing.
     */
    public function answerCallback(string $callbackId, ?string $text = null): void
    {
    }

    /**
     * What the messenger tells about a chat: its title (MAX messages name
     * only the chat id) and the reference of its picture — a group's icon or
     * the photo of the person of a private dialog, downloadable with
     * downloadFile(). Null — the messenger tells nothing.
     *
     * @return array{title: string|null, photo: string|null}|null
     */
    public function chatInfo(string $chatId): ?array
    {
        return null;
    }

    /**
     * Members of a group with their photos, as far as the messenger gives
     * them (MAX: the bot must be an administrator). Empty — not known.
     *
     * @return array[] each: user (BotUser), photo (reference for downloadFile() or null)
     */
    public function members(string $chatId): array
    {
        return [];
    }

    /**
     * Messages of a chat's history (newest first or not — the caller sorts),
     * for an import (bot\BotImport). Up to $count a call, going back in time:
     * $from — the newest moment (null — now), $to — the oldest one, in
     * seconds. Empty — the messenger gives no history to bots.
     *
     * @return BotEvent[]
     */
    public function history(string $chatId, int $count = 100, ?int $from = null, ?int $to = null): array
    {
        return [];
    }

    /**
     * Facts of the bot for its card, as the last getMe() told them (Telegram:
     * what the bot sees in groups). Empty — nothing to add.
     *
     * @return array label => text
     */
    public function describeBot(): array
    {
        return [];
    }

    /** A link that opens the bot with a start parameter, or null if unknown. */
    public function startLink(string $payload): ?string
    {
        return null;
    }

    /** The secret of the webhook, created once when missing. */
    public function ensureWebhookSecret(): string
    {
        if ((string)$this->webhookSecret === '') {
            $this->webhookSecret = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        }

        return (string)$this->webhookSecret;
    }
}
