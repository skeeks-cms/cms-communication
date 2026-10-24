<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\models\CmsCommConnection;

/**
 * Keeps the webhook of a bot as the site needs it: subscribed to
 * `/~comm-bot/<id>` with its secret in the webhook mode, unsubscribed in the
 * poll mode. Checked when the bot is saved and every hour (comm.bot.maintain):
 * MAX unsubscribes a bot itself after 8 hours of failed deliveries, Telegram
 * keeps updates for 24 hours only.
 *
 * A site that must not receive (a stand: communication.botReceive = false)
 * never touches the subscription — production owns it.
 */
class BotSubscriptions
{
    public const RECEIVE_OFF = 'receive_off';
    public const OK = 'ok';
    public const SUBSCRIBED = 'subscribed';
    public const UNSUBSCRIBED = 'unsubscribed';
    public const POLL = 'poll';

    /**
     * @return array{state: string, url: string|null, error: string|null}
     */
    public static function ensure(CmsCommConnection $connection, ?MessengerDriver $driver = null): array
    {
        if (!Bots::canReceive()) {
            return ['state' => self::RECEIVE_OFF, 'url' => null, 'error' => null];
        }
        $driver = $driver ?? Bots::driver($connection);
        $current = $driver->getWebhook();
        $currentUrl = isset($current['url']) && $current['url'] !== '' ? (string)$current['url'] : null;

        if ($driver->mode === MessengerDriver::MODE_POLL || !(int)$connection->is_active) {
            if ($currentUrl !== null) {
                $driver->deleteWebhook();

                return ['state' => self::UNSUBSCRIBED, 'url' => null, 'error' => null];
            }

            return ['state' => self::POLL, 'url' => null, 'error' => null];
        }

        $hadSecret = (string)$driver->webhookSecret !== '';
        $secret = $driver->ensureWebhookSecret();
        if (!$hadSecret) {
            // The new secret must be stored before the messenger starts sending it.
            $connection->applyDriver($driver);
            $connection->save(false, ['driver_config', 'secret', 'updated_at']);
        }
        $url = Bots::webhookUrl($connection);
        if ($currentUrl !== $url || !$hadSecret) {
            $driver->setWebhook($url, $secret);

            return ['state' => self::SUBSCRIBED, 'url' => $url, 'error' => null];
        }

        return ['state' => self::OK, 'url' => $url, 'error' => isset($current['error']) && $current['error'] !== '' ? (string)$current['error'] : null];
    }
}
