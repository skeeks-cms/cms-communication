<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication;

use skeeks\cms\communication\drivers\ConnectionDriver;
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
