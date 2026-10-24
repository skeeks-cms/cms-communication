<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mail;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommConnection;

/**
 * Which installed connection drivers are mailing services
 * (MailingServiceInterface), read from the driver registry without creating
 * drivers or decrypting secrets.
 */
final class MailingServices
{
    /** @return string[] ids of the registered mailing service drivers */
    public static function driverIds(): array
    {
        $ids = [];
        foreach (CmsCommConnection::component()->drivers as $id => $config) {
            $class = is_array($config) ? ($config['class'] ?? null) : $config;
            if (is_string($class) && is_subclass_of($class, MailingServiceInterface::class)) {
                $ids[] = (string)$id;
            }
        }

        return $ids;
    }

    public static function isServiceDriver(string $driverId): bool
    {
        return in_array($driverId, self::driverIds(), true);
    }

    /** The mailbox sends through a mailing service. */
    public static function isService(?CmsCommAccount $account): bool
    {
        $connection = $account ? $account->connection : null;

        return $connection && self::isServiceDriver((string)$connection->driver);
    }

    /** Condition on cms_comm_account: mailboxes that do not send through a mailing service. */
    public static function notServiceCondition(): array
    {
        $ids = self::driverIds();
        if (!$ids) {
            return [];
        }
        $a = CmsCommAccount::tableName();

        return ['not in', $a.'.cms_comm_connection_id', CmsCommConnection::find()->select('id')->andWhere(['driver' => $ids])];
    }
}
