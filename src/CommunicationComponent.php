<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication;

use skeeks\cms\communication\campaign\CampaignChannelInterface;
use skeeks\cms\communication\drivers\ConnectionDriver;
use skeeks\cms\communication\send\SendableSourceInterface;
use skeeks\cms\communication\send\SendChannelInterface;
use skeeks\cms\helpers\PhoneHelper;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Registry of connection drivers and encryption of their secrets.
 *
 * Driver packages register themselves:
 *
 * ```php
 * 'components' => ['communication' => ['drivers' => ['imap' => ImapDriver::class]]],
 * ```
 *
 * Mailboxes are connected by administrators in the UI, so their passwords are
 * stored in the database, encrypted with a key that the project supplies from
 * protected configuration (`params['cms-communication']['secretKey']` or the
 * `CMS_COMMUNICATION_SECRET_KEY` environment variable). The key is write-only:
 * it is never returned, logged or placed into Yii::$app->params.
 *
 * @property-write string|null $secretKey
 */
class CommunicationComponent extends Component
{
    /** @var array driver id => class name or Yii object config of a ConnectionDriver */
    public $drivers = [];

    /** Days of history taken when a mailbox folder is received for the first time. */
    public $initialDays = 30;

    /**
     * Allowed recipients of outgoing letters: exact addresses or `@domain`.
     * Empty allows everyone. Local stands with a copy of the production
     * database set it so that test letters never reach real clients.
     *
     * @var string[]
     */
    public $allowedRecipients = [];

    /**
     * "Ответственный" of a conversation in "Почта": the menu in the letter,
     * "Назначить меня" in the row menu, the folder "Мне назначено" and the
     * list filter. Hidden by default (decision of the owner, 23.10.2026): a
     * letter that needs work becomes a task. The data and the service
     * (MessageSorting::assign()) stay; a project may switch it on.
     */
    public $showAssignee = false;

    /**
     * The list filter "Привязка" (linked to the CRM / not sorted). Hidden by
     * default: the folders "Мои клиенты" and "Без привязки" do the same.
     */
    public $showLinkedFilter = false;

    /**
     * Allowed phones of outgoing SMS and messages to phone numbers: numbers in
     * any notation, compared by digits. Empty allows everyone, unless
     * allowedRecipients is set: a stand that restricts letters sends no SMS
     * until its own test numbers are listed here.
     *
     * @var string[]
     */
    public $allowedPhones = [];

    /**
     * Things packages send to clients ("Отправить клиенту", "Прикрепить
     * из…"): type => class name or Yii object config of a
     * send\SendableSourceInterface. Registrations of several packages merge.
     *
     * @var array
     */
    public $sendables = [];

    /**
     * Channels of "Отправить клиенту": channel id => class name or Yii object
     * config of a send\SendChannelInterface. Mail and SMS come with this
     * package (config/common.php); messenger drivers add theirs.
     *
     * @var array
     */
    public $channels = [];

    /**
     * Address of the site for personal links made outside a web request
     * (jobs: campaign letters, API), e.g. "https://skeeks.com". Null — the
     * main domain of the site. A local stand with a copy of the production
     * database sets its own address, so the links of its letters lead to
     * the stand, not to production. In a web request links always use the
     * host of the request.
     */
    public $baseUrl;

    /**
     * Channels of mailing campaigns: channel id => class name or Yii object
     * config of a campaign\CampaignChannelInterface. The managers' mailboxes
     * come with this package (config/common.php); mailing service drivers
     * (Unisender Go and the like) add theirs.
     *
     * @var array
     */
    public $campaignChannels = [];

    /**
     * More networks (CIDR, IPv4 or IPv6) whose openings of a personal link
     * look like link checks of mail services and security systems, beside
     * services\LinkChecks::NETWORKS.
     *
     * @var string[]
     */
    public $linkCheckNetworks = [];

    /**
     * Limits of AI actions through MCP/REST (services\AiActions): perCall —
     * drafts or campaign recipients in one call; perDay — of them per CRM
     * user and day (CRM time zone); recentDays — no new offer to a client
     * who got a letter or this offer within these days. Missing keys keep
     * AiActions::LIMITS (20, 100, 30).
     *
     * @var array
     */
    public $aiLimits = [];

    /** @var SendableSourceInterface[] */
    private $_sendableSources = [];

    /** @var SendChannelInterface[] */
    private $_channels = [];

    /** @var CampaignChannelInterface[] */
    private $_campaignChannels = [];

    public function isRecipientAllowed(string $address): bool
    {
        if (!$this->allowedRecipients) {
            return true;
        }
        $address = mb_strtolower(trim($address));
        foreach ($this->allowedRecipients as $allowed) {
            $allowed = mb_strtolower(trim((string)$allowed));
            if ($allowed === $address || ($allowed !== '' && $allowed[0] === '@' && str_ends_with($address, $allowed))) {
                return true;
            }
        }

        return false;
    }

    public function isPhoneAllowed(string $phone): bool
    {
        $digits = PhoneHelper::digits($phone);
        if ($digits === '') {
            return false;
        }
        if (!$this->allowedPhones) {
            return !$this->allowedRecipients;
        }
        foreach ($this->allowedPhones as $allowed) {
            if (PhoneHelper::searchDigits((string)$allowed) === PhoneHelper::searchDigits($digits)) {
                return true;
            }
        }

        return false;
    }

    /** The registered kind of sendable things, or null. */
    public function getSendableSource(string $type): ?SendableSourceInterface
    {
        if (!isset($this->sendables[$type]) || !preg_match('/^[a-z][a-z0-9_-]*$/', $type)) {
            return null;
        }
        if (!isset($this->_sendableSources[$type])) {
            $source = \Yii::createObject($this->sendables[$type]);
            if (!$source instanceof SendableSourceInterface) {
                throw new InvalidConfigException("Sendable source «{$type}» must implement ".SendableSourceInterface::class);
            }
            $this->_sendableSources[$type] = $source;
        }

        return $this->_sendableSources[$type];
    }

    /** The channel of "Отправить клиенту", or null. */
    public function getChannel(string $id): ?SendChannelInterface
    {
        if (!isset($this->channels[$id])) {
            return null;
        }
        if (!isset($this->_channels[$id])) {
            $channel = \Yii::createObject($this->channels[$id]);
            if (!$channel instanceof SendChannelInterface) {
                throw new InvalidConfigException("Send channel «{$id}» must implement ".SendChannelInterface::class);
            }
            $this->_channels[$id] = $channel;
        }

        return $this->_channels[$id];
    }

    /** @return SendChannelInterface[] channel id => channel */
    public function getChannels(): array
    {
        $result = [];
        foreach (array_keys($this->channels) as $id) {
            $result[(string)$id] = $this->getChannel((string)$id);
        }

        return $result;
    }

    /**
     * "https://site.ru" for personal links of the site: the host of the web
     * request, otherwise $baseUrl, otherwise the main domain of the site.
     */
    public function siteUrl(?int $siteId = null): string
    {
        if (\Yii::$app instanceof \yii\web\Application && \Yii::$app->request->hostInfo) {
            return rtrim((string)\Yii::$app->request->hostInfo, '/');
        }
        if (is_string($this->baseUrl) && trim($this->baseUrl) !== '') {
            return rtrim(trim($this->baseUrl), '/');
        }
        $site = $siteId ? \skeeks\cms\models\CmsSite::findOne($siteId) : null;

        return rtrim($site ? (string)$site->url : '', '/');
    }

    /** The channel of mailing campaigns, or null. */
    public function getCampaignChannel(string $id): ?CampaignChannelInterface
    {
        if (!isset($this->campaignChannels[$id])) {
            return null;
        }
        if (!isset($this->_campaignChannels[$id])) {
            $channel = \Yii::createObject($this->campaignChannels[$id]);
            if (!$channel instanceof CampaignChannelInterface) {
                throw new InvalidConfigException("Campaign channel «{$id}» must implement ".CampaignChannelInterface::class);
            }
            $this->_campaignChannels[$id] = $channel;
        }

        return $this->_campaignChannels[$id];
    }

    /** @return CampaignChannelInterface[] channel id => channel */
    public function getCampaignChannels(): array
    {
        $result = [];
        foreach (array_keys($this->campaignChannels) as $id) {
            $result[(string)$id] = $this->getCampaignChannel((string)$id);
        }

        return $result;
    }

    /** @var string|null */
    private $_secretKey;

    public function setSecretKey($key): void
    {
        $key = is_string($key) ? trim($key) : '';
        $this->_secretKey = $key === '' ? null : $key;
    }

    public function hasSecretKey(): bool
    {
        return $this->_secretKey !== null;
    }

    public function encryptSecret(string $plain): string
    {
        return base64_encode(\Yii::$app->security->encryptByKey($plain, $this->requireKey()));
    }

    /**
     * @throws InvalidConfigException when the key is missing
     * @throws \RuntimeException when the value cannot be decrypted (wrong key or damaged data)
     */
    public function decryptSecret(string $encrypted): string
    {
        $raw = base64_decode($encrypted, true);
        $plain = $raw === false ? false : \Yii::$app->security->decryptByKey($raw, $this->requireKey());
        if ($plain === false) {
            throw new \RuntimeException('Не удалось расшифровать секрет подключения: ключ шифрования изменился или данные повреждены.');
        }

        return $plain;
    }

    /**
     * @return array driver id => title, for selects
     */
    public function getDriversForSelect(): array
    {
        $result = [];
        foreach ($this->drivers as $id => $config) {
            $class = is_array($config) ? ($config['class'] ?? null) : $config;
            $result[$id] = is_string($class) && is_subclass_of($class, ConnectionDriver::class) ? $class::title() : (string)$id;
        }

        return $result;
    }

    public function createDriver(string $id): ConnectionDriver
    {
        if (!isset($this->drivers[$id])) {
            throw new InvalidConfigException("Драйвер подключения «{$id}» не установлен.");
        }
        $driver = \Yii::createObject($this->drivers[$id]);
        if (!$driver instanceof ConnectionDriver) {
            throw new InvalidConfigException("Драйвер «{$id}» должен наследовать ".ConnectionDriver::class);
        }

        return $driver;
    }

    private function requireKey(): string
    {
        if ($this->_secretKey === null) {
            throw new InvalidConfigException('Не задан ключ шифрования коммуникаций: params[\'cms-communication\'][\'secretKey\'] или переменная окружения CMS_COMMUNICATION_SECRET_KEY.');
        }

        return $this->_secretKey;
    }
}
