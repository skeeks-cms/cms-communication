<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\campaign;

use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\send\SendableInterface;
use skeeks\cms\models\User;
use yii\db\ActiveRecord;

/**
 * The letter of one recipient of a campaign, made by services\Campaigns:
 * the subject and the HTML with the placeholders filled in, the offer with
 * its link placeholder in the text (SendService::placeholder()), the opt-out
 * placeholder (Unsubscribe::PLACEHOLDER) or none.
 */
final class CampaignLetter
{
    public CmsCommCampaign $campaign;
    public CmsCommCampaignRecipient $recipient;

    /** The CRM record of the recipient (a lead). */
    public ActiveRecord $record;

    /** The employee the letter is from. */
    public User $user;

    public string $subject = '';
    public string $html = '';

    /** Registered type of the offer ("proposal") and its document, or null without an offer. */
    public ?string $sendableType = null;
    public ?int $sendableId = null;
    public ?SendableInterface $sendable = null;

    public function __construct(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, ActiveRecord $record, User $user)
    {
        $this->campaign = $campaign;
        $this->recipient = $recipient;
        $this->record = $record;
        $this->user = $user;
    }

    /** "type:id" of the offer document, or null. */
    public function sendableRef(): ?string
    {
        return $this->sendableType && $this->sendableId ? $this->sendableType.':'.$this->sendableId : null;
    }
}
