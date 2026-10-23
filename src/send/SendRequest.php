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
 * What the employee (or an API caller, a bot, the AI) asks to send: which
 * sendable, through which channel, to whom, with which text. Built by
 * SendService::request(); the channel checks the rest.
 */
final class SendRequest
{
    public string $type;
    public int $id;
    public SendableInterface $sendable;
    public User $user;
    public int $siteId;

    /** Channel id. */
    public string $channel = '';

    /** Address or phone as typed or chosen; the channel normalizes it. */
    public string $recipient = '';

    /** Text: HTML of the letter editor for mail, plain text for SMS. Empty — the sendable's own text. */
    public string $text = '';

    /** Letter subject; empty — the sendable title. */
    public string $subject = '';

    /** Sender inside the channel (mailbox id for mail); null — the employee's default. */
    public ?int $accountId = null;

    /** The card the window was opened from: its contacts first, the message lands in its feed. */
    public ?ActiveRecord $record = null;

    /** Client IP of the web request, kept with SMS sent by a job. */
    public string $userIp = '';

    public function __construct(string $type, int $id, SendableInterface $sendable, User $user, int $siteId)
    {
        $this->type = $type;
        $this->id = $id;
        $this->sendable = $sendable;
        $this->user = $user;
        $this->siteId = $siteId;
    }

    /** "proposal:12": the value of form fields and URLs. */
    public function ref(): string
    {
        return $this->type.':'.$this->id;
    }
}
