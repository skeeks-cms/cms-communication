<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\campaign;

use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\models\User;

/**
 * How the letters of a campaign leave: managers' mailboxes (small personal
 * letters with a pace per mailbox, MailboxCampaignChannel) or a mailing
 * service through its API (a driver package, later). Registered in
 * `communication.campaignChannels`:
 *
 * ```php
 * 'components' => ['communication' => ['campaignChannels' => ['unisender' => UnisenderCampaignChannel::class]]],
 * ```
 *
 * The campaign (services\Campaigns) owns the audience, the check of consent
 * by policyMode(), the letter of each recipient (placeholders, the offer,
 * the opt-out link) and the report; a channel only decides who sends, when
 * and how the letter physically leaves.
 */
interface CampaignChannelInterface
{
    /** "Ящики менеджеров". */
    public function title(): string;

    /** A line for the campaign form: what the channel is for. */
    public function hint(): string;

    /** The kind of advertising for services\MailingPolicy: MODE_MANAGER (cold contacts allowed) or MODE_SERVICE (consent only). */
    public function policyMode(): string;

    /** Why the campaign cannot be launched through this channel now, or null. */
    public function unavailableReason(CmsCommCampaign $campaign, User $user): ?string;

    /**
     * At launch: the sender of every recipient still to be sent
     * (cms_comm_account_id, sender_user_id) or its exclusion
     * (status excluded, reason).
     *
     * @param CmsCommCampaignRecipient[] $recipients unsaved, status pending
     */
    public function assignSenders(CmsCommCampaign $campaign, array $recipients, User $launcher): void;

    /** Starts or continues sending the pending recipients (pushes its jobs). */
    public function start(CmsCommCampaign $campaign): void;

    /**
     * Sends one prepared letter and returns its message (queued, not yet
     * delivered). Called inside the transaction of the recipient.
     */
    public function deliver(CampaignLetter $letter): CmsCommMessage;
}
