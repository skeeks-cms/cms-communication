<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mail;

/**
 * A mail session that can change the mailbox on the server: move letters
 * between folders, set flags, see every folder. Drivers that only read and
 * send implement MailSessionInterface alone; then actions with letters are
 * not available for their mailboxes.
 *
 * Folder names are server names (IMAP: modified UTF-7). Letters are addressed
 * by UID inside a folder; a UID is meaningful only with the folder's
 * UIDVALIDITY, which the caller checks.
 */
interface MailManageSessionInterface extends MailSessionInterface
{
    public const ROLE_INBOX = 'inbox';
    public const ROLE_SENT = 'sent';
    public const ROLE_TRASH = 'trash';
    public const ROLE_JUNK = 'junk';
    public const ROLE_ARCHIVE = 'archive';
    public const ROLE_DRAFTS = 'drafts';

    /**
     * Every folder that can hold letters, with its special role (by the
     * server's SPECIAL-USE marks, else by a well-known name).
     *
     * @return array[] each: name, role (one of ROLE_* or null), delimiter (string|null)
     */
    public function folderList(): array;

    /**
     * Folder counters without opening it.
     *
     * @return array uidvalidity (int|null), uidnext (int|null), messages (int|null)
     */
    public function status(string $folder): array;

    /**
     * Which of these UIDs are still in the folder (opens it read-only).
     *
     * @param int[] $uids
     * @return int[]
     */
    public function existingUids(string $folder, array $uids): array;

    /**
     * UIDs of the folder greater than $uid, ascending (opens it read-only).
     *
     * @return int[]
     */
    public function uidsAfterIn(string $folder, int $uid): array;

    /**
     * UIDs of the folder of letters received on or after $timestamp (opens it read-only).
     *
     * @return int[]
     */
    public function uidsSinceIn(string $folder, int $timestamp): array;

    /**
     * Message-ID of each letter, without angle brackets (opens the folder read-only).
     *
     * @param int[] $uids
     * @return string[] uid => Message-ID
     */
    public function messageIds(string $folder, array $uids): array;

    /**
     * Moves letters to another folder.
     *
     * @param int[] $uids
     * @return array uidvalidity (int|null) of the target folder; uids: old uid => new uid (null: unknown);
     *               missing: UIDs not found in the source folder (moved or deleted meanwhile)
     */
    public function move(string $folder, array $uids, string $target): array;

    /** Creates a folder; does nothing when it exists. */
    public function createFolder(string $name): void;

    /** Whether letters of the folder may carry own keywords (labels). */
    public function supportsKeywords(string $folder): bool;

    /**
     * Flags and keywords of letters.
     *
     * @param int[] $uids
     * @return array[] uid => flags (as the server spells them)
     */
    public function flags(string $folder, array $uids): array;

    /**
     * Adds and removes flags or keywords of letters.
     *
     * @param int[] $uids
     * @param string[] $add
     * @param string[] $remove
     */
    public function storeFlags(string $folder, array $uids, array $add, array $remove = []): void;
}
