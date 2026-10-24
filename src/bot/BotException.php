<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

/**
 * A messenger API refused a request: the message was NOT sent (wrong chat,
 * no rights, the person blocked the bot, too long text). Its message is
 * shown to employees, so it never contains a token.
 *
 * Network failures and timeouts are not this exception: their outcome is
 * unknown and a driver throws any other exception for them.
 */
class BotException extends \RuntimeException
{
    /** The person blocked the bot or deleted the dialog: the identity is marked blocked. */
    public bool $blocked = false;

    /** Too many requests: the messenger asks to wait this many seconds; nothing was sent. */
    public ?int $retryAfter = null;

    public static function blocked(string $message): self
    {
        $e = new static($message);
        $e->blocked = true;

        return $e;
    }

    public static function rateLimited(int $seconds, string $message = 'Слишком много сообщений: мессенджер просит подождать.'): self
    {
        $e = new static($message);
        $e->retryAfter = max(1, $seconds);

        return $e;
    }
}
