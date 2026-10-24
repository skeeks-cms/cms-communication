<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mail;

/**
 * A mail driver that sends through the API of a mailing service (Unisender Go
 * and the like): only sending, no folders and no receiving. A mailbox of such
 * a connection is the sender address of the service; replies go to a working
 * mailbox through Reply-To.
 *
 * Consequences in this package (MailingServices::isService()):
 * - the receive job and "Сбор писем" skip it, the send job stores no copy in
 *   "Отправленные" (the CRM row is the copy);
 * - a campaign of managers' mailboxes never sends through it: cold contacts
 *   are not for a mailing service (MailboxCampaignChannel);
 * - delivery is confirmed only by the service's events
 *   (services\DeliveryEvents), never by acceptance of the API call.
 */
interface MailingServiceInterface extends MailDriverInterface
{
    /** "Unisender Go". */
    public static function serviceName(): string;
}
