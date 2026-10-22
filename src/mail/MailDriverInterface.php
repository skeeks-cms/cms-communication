<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mail;

use Symfony\Component\Mime\Email;

/**
 * Transport of a mail connection. Receiving logic (cursor, threads, links,
 * feed) belongs to cms-communication; a driver only speaks the protocol.
 * The driver is created with decrypted secrets; it must never put them into
 * exception messages or results.
 */
interface MailDriverInterface
{
    /**
     * Interactive diagnostics: log in to the incoming and outgoing servers.
     *
     * @return array[] each: label, ok (bool), message (text without secrets)
     */
    public function checkConnection(): array;

    public function openSession(): MailSessionInterface;

    /**
     * Sends one letter. Its Message-ID header is set by the caller.
     *
     * @throws MailSendException
     */
    public function send(Email $email): void;
}
