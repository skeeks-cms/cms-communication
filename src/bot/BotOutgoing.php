<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\send\SendFile;

/**
 * One message a driver sends: a part of text within the channel limit,
 * optionally a file and buttons. BotSend splits longer texts and sends files
 * one by one.
 *
 * Buttons: rows of ['text' => …, 'url' => …] or ['text' => …, 'callback' => …];
 * a driver draws what its messenger can.
 */
final class BotOutgoing
{
    public string $chatId;
    /** Plain text; `html` is the same text with b, i, a tags when the employee formatted it. */
    public string $text = '';
    public ?string $html = null;
    public ?string $replyToMessageId = null;
    public ?SendFile $file = null;
    public array $buttons = [];
    /** Silent message: no sound on the recipient's device. */
    public bool $silent = false;

    public function __construct(string $chatId)
    {
        $this->chatId = $chatId;
    }
}
