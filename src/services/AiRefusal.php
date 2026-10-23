<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

/**
 * One row of an AI action is refused (AiActions): the reason code
 * (AiActions::REASON_*, MailingPolicy::REASON_*) and the text for people.
 */
class AiRefusal extends \DomainException
{
    public string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}
