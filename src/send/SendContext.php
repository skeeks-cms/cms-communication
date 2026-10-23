<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use skeeks\cms\models\User;
use yii\db\ActiveRecord;

/**
 * One send of a sendable: given to createSendLink() and then, the same
 * object, to afterSend().
 */
final class SendContext
{
    /** Registered type of the sendable ("proposal"). */
    public string $type;

    /** Id of the sendable inside its type. */
    public int $id;

    /** Channel id: "email", "sms", later "telegram", "max". */
    public string $channel;

    /** Address in the channel: an email in lower case, a phone as digits ("79991234567"). */
    public string $recipient;

    /** Name of the recipient when the CRM knows it. */
    public string $recipientName = '';

    /** The CRM record of the recipient (lead, company, client) when the contact came from one. */
    public ?ActiveRecord $record = null;

    /** Employee who sends. */
    public User $user;

    /** Personal URL returned by createSendLink(). */
    public string $url = '';

    /** Free for the sendable: e.g. its pending send row, from createSendLink() to afterSend(). */
    public $ref;

    /** The mailing campaign the send belongs to (CmsCommCampaign id), null for a send by an employee. */
    public ?int $campaignId = null;

    /** "Подготовлено ИИ" of the letter that carries the send (services\AiMark), null when an employee wrote it. */
    public ?array $aiMark = null;

    public function __construct(string $type, int $id, string $channel, string $recipient, User $user)
    {
        $this->type = $type;
        $this->id = $id;
        $this->channel = $channel;
        $this->recipient = $recipient;
        $this->user = $user;
    }
}
