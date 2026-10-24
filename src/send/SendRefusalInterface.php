<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

/**
 * Optional for a SendableInterface: why it cannot be sent now (expired,
 * empty, no page), asked before sending — e.g. by the check of a draft
 * through the API — so the answer comes before a letter is made. The
 * package still refuses in createSendLink() (SendException).
 */
interface SendRefusalInterface
{
    /** The reason in words, or null when it can be sent. */
    public function getSendRefusal(): ?string;
}
