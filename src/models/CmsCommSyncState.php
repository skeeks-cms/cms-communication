<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Cursor of one folder of a connection.
 *
 * Receive folders (inbox, sent): the last received UID, committed in the
 * same transaction as the messages it covers. Every other folder of the
 * mailbox is watched (services\MailServerSync): `last_uid` is the last UID
 * looked at for letters moved there in the mail service itself; these rows
 * also give the list of server folders for "В папку".
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_comm_connection_id
 * @property string      $folder
 * @property string      $folder_role inbox, sent (received); trash, junk, archive, drafts, other (watched)
 * @property string|null $delimiter   of nested folder names
 * @property int|null    $uidvalidity
 * @property int|null    $uidnext
 * @property int         $last_uid
 * @property int|null    $last_success_at
 * @property string|null $last_error
 * @property int|null    $last_error_at
 */
class CmsCommSyncState extends ActiveRecord
{
    public const ROLE_INBOX = 'inbox';
    public const ROLE_SENT = 'sent';

    /** Folders the receive job takes letters from. */
    public const RECEIVE_ROLES = [self::ROLE_INBOX, self::ROLE_SENT];

    public static function tableName()
    {
        return '{{%cms_comm_sync_state}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public static function roles(): array
    {
        return [self::ROLE_INBOX => 'Входящие', self::ROLE_SENT => 'Отправленные'];
    }

    /** The folder name as people read it: decoded, nested parts joined by " / ". */
    public function getTitle(): string
    {
        return self::folderTitle((string)$this->folder, $this->delimiter);
    }

    public static function folderTitle(string $folder, ?string $delimiter = null): string
    {
        if (strcasecmp($folder, 'INBOX') === 0) {
            return 'Входящие';
        }
        $decoded = @mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP');
        $decoded = is_string($decoded) && $decoded !== '' ? $decoded : $folder;
        if ($delimiter !== null && $delimiter !== '') {
            $decoded = implode(' / ', explode($delimiter, $decoded));
        }

        return $decoded;
    }
}
