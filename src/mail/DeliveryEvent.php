<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mail;

/**
 * One delivery event of a mailing service in the common form, made by its
 * driver from a webhook and recorded by services\DeliveryEvents.
 */
final class DeliveryEvent
{
    /** The driver of the service: "unisender". */
    public string $provider = '';

    /** Unique per event of the service: a repeated delivery of the webhook gives the same key. */
    public string $key = '';

    /** models\CmsCommMessageEvent::EVENT_* */
    public string $event = '';

    public ?int $at = null;
    public ?string $address = null;

    /** Why it was not delivered: the classification of the service and the answer of the recipient's server. */
    public ?string $detail = null;

    /**
     * The address itself does not take letters (does not exist, is closed,
     * its domain takes no mail), not our sender or our text: only then a
     * bounce puts the contact on the stop list.
     */
    public bool $addressDead = false;

    /**
     * The service refused the address when the letter was handed over
     * (unsubscribed, complained, unavailable there): the letter did not go to
     * it, the event says why.
     */
    public bool $refused = false;

    /** Our letter: found by our metadata of the send (the message id). */
    public ?int $messageId = null;

    public ?int $connectionId = null;

    /** The event as the service sent it (without secrets), kept for the record. */
    public array $data = [];

    public function __construct(array $values = [])
    {
        foreach ($values as $name => $value) {
            $this->$name = $value;
        }
    }
}
