<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mail;

/**
 * An open connection to a mail server: folders, UIDs and raw letters.
 * Reading never marks letters as read.
 */
interface MailSessionInterface
{
    /**
     * @return array[] each: name (server name), role ('inbox', 'sent' or null)
     */
    public function folders(): array;

    /**
     * Opens a folder read-only.
     *
     * @return array uidvalidity (int|null), uidnext (int|null)
     */
    public function select(string $folder): array;

    /** @return int[] UIDs greater than $uid, ascending */
    public function uidsAfter(int $uid): array;

    /** @return int[] UIDs of letters received on or after $timestamp, ascending */
    public function uidsSince(int $timestamp): array;

    /** @return string[] uid => raw RFC 822 message */
    public function fetch(array $uids): array;

    /** Whether a letter with this Message-ID (without angle brackets) is in the folder. */
    public function hasMessageId(string $folder, string $messageId): bool;

    /** Stores a sent letter in a folder, marked as read. */
    public function append(string $folder, string $raw): void;

    public function close(): void;
}
