<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\CommunicationComponent;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommConnection;
use yii\base\InvalidConfigException;

/**
 * Bots of the site: which drivers are messengers, the account of a bot
 * connection (its access rows are those of a mailbox), the driver with
 * secrets, the webhook address, and the limits of a stand.
 */
class Bots
{
    /** Messenger drivers among the installed drivers: id => class. */
    public static function drivers(): array
    {
        $result = [];
        $component = \Yii::$app->has('communication') ? \Yii::$app->get('communication') : null;
        foreach ($component ? (array)$component->drivers : [] as $id => $config) {
            $class = is_array($config) ? ($config['class'] ?? null) : $config;
            if (is_string($class) && is_subclass_of($class, MessengerDriver::class)) {
                $result[$id] = $class;
            }
        }

        return $result;
    }

    /** Channels of the installed messengers: channel => title. */
    public static function channels(): array
    {
        $result = [];
        foreach (self::drivers() as $class) {
            $result[$class::channel()] = $class::title();
        }

        return $result;
    }

    public static function isBot(CmsCommConnection $connection): bool
    {
        return isset(self::drivers()[(string)$connection->driver]);
    }

    /** The driver of a bot connection with its secrets. */
    public static function driver(CmsCommConnection $connection): MessengerDriver
    {
        $driver = $connection->createDriver(true);
        if (!$driver instanceof MessengerDriver) {
            throw new InvalidConfigException('Подключение «'.$connection->name.'» — не бот мессенджера.');
        }

        return $driver;
    }

    /**
     * The account of a bot: messages of its chats belong to it, employees get
     * rights on it like on a mailbox. Created with the connection's channel
     * and the bot name as address.
     */
    public static function account(CmsCommConnection $connection): CmsCommAccount
    {
        $account = CmsCommAccount::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id])->orderBy(['id' => SORT_ASC])->one();
        if ($account) {
            return $account;
        }
        $driver = $connection->createDriver(false);
        $channel = $driver::channel();
        $address = $driver instanceof MessengerDriver && $driver->botUsername ? '@'.ltrim((string)$driver->botUsername, '@') : 'bot-'.(int)$connection->id;
        // The same bot connected twice (a mistake or a test): addresses are unique per site and channel.
        if (CmsCommAccount::find()->andWhere(['cms_site_id' => (int)$connection->cms_site_id, 'channel' => $channel, 'address' => mb_strtolower($address)])->exists()) {
            $address = 'bot-'.(int)$connection->id;
        }
        $account = new CmsCommAccount([
            'cms_site_id' => (int)$connection->cms_site_id,
            'cms_comm_connection_id' => (int)$connection->id,
            'channel' => $channel,
            'address' => mb_strtolower($address),
            'name' => (string)$connection->name,
            'is_active' => (int)$connection->is_active,
            'is_expose_linked' => 1,
        ]);
        if (!$account->save(false)) {
            throw new \RuntimeException('Не удалось создать учётную запись бота.');
        }

        return $account;
    }

    /** The address messengers deliver updates of this bot to. */
    public static function webhookUrl(CmsCommConnection $connection): string
    {
        return rtrim(self::component()->siteUrl((int)$connection->cms_site_id), '/').'/~comm-bot/'.(int)$connection->id;
    }

    /**
     * May this site receive updates of bots (set webhooks, poll)? A stand
     * works with production bots and must never take their updates.
     */
    public static function canReceive(): bool
    {
        return (bool)self::component()->botReceive;
    }

    /** May a message go to this chat (a stand sends only to listed chats)? */
    public static function isChatAllowed(string $channel, string $chatId): bool
    {
        $component = self::component();
        if (!$component->allowedChats) {
            return !$component->allowedRecipients;
        }
        foreach ($component->allowedChats as $allowed) {
            $allowed = trim((string)$allowed);
            if ($allowed === $chatId || $allowed === $channel.':'.$chatId) {
                return true;
            }
        }

        return false;
    }

    private static function component(): CommunicationComponent
    {
        return \Yii::$app->get('communication');
    }
}
