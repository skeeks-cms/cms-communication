<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mail;

/**
 * Sending failed. `uncertain` means the server may already have accepted the
 * letter (the connection broke after the data was sent): such a letter is
 * never resent automatically.
 */
class MailSendException extends \RuntimeException
{
    public bool $uncertain = false;

    public static function failed(string $message): self
    {
        return new self($message);
    }

    public static function uncertain(string $message): self
    {
        $e = new self($message);
        $e->uncertain = true;

        return $e;
    }
}
