<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\db\Expression;

/**
 * A copy of a message in a mailbox (folder and UID on the server).
 *
 * `folder_role` is the role of the server folder the copy is in now. An
 * action asked for in the CRM sets `target_role` (and `target_folder` for an
 * ordinary folder) at once, so the screen shows the letter in its new place;
 * a job then moves it on the server and writes the new folder and UID, or
 * clears the target and keeps `sync_error`. The place the employee sees is
 * therefore COALESCE(target_role, folder_role) — see roleExpression().
 *
 * @property int            $id
 * @property int|null       $created_at
 * @property int            $cms_comm_message_id
 * @property int            $cms_comm_account_id
 * @property int|null       $cms_comm_connection_id
 * @property string|null    $folder
 * @property int|null       $uidvalidity
 * @property int|null       $uid
 * @property string|null    $folder_role   inbox, sent, trash, junk, archive, drafts, other, gone (not found on the server)
 * @property string|null    $target_role   the move waiting for the server
 * @property string|null    $target_folder server folder of a move to an ordinary folder
 * @property int|null       $target_by     who asked for the move
 * @property int            $labels_dirty  labels still to write to the server
 * @property string|null    $sync_error
 * @property int|null       $sync_error_at
 *
 * @property CmsCommAccount $account
 * @property CmsCommMessage $message
 */
class CmsCommMessageMailbox extends ActiveRecord
{
    public const ROLE_INBOX = 'inbox';
    public const ROLE_SENT = 'sent';
    public const ROLE_TRASH = 'trash';
    public const ROLE_JUNK = 'junk';
    public const ROLE_ARCHIVE = 'archive';
    public const ROLE_DRAFTS = 'drafts';
    /** An ordinary folder of the mailbox ("Клиенты/Иванов"). */
    public const ROLE_OTHER = 'other';
    /** Gone from its folder and not found in any other: deleted in the mail service. */
    public const ROLE_GONE = 'gone';

    /** Places that take a letter out of the usual folders. */
    public const REMOVED_ROLES = [self::ROLE_TRASH, self::ROLE_JUNK, self::ROLE_GONE];

    /** Places that take a letter out of "Входящие" / "Отправленные" of the mailbox. */
    public const AWAY_ROLES = [self::ROLE_TRASH, self::ROLE_JUNK, self::ROLE_GONE, self::ROLE_ARCHIVE, self::ROLE_OTHER, self::ROLE_DRAFTS];

    public static function tableName()
    {
        return '{{%cms_comm_message_mailbox}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    public function getAccount()
    {
        return $this->hasOne(CmsCommAccount::class, ['id' => 'cms_comm_account_id']);
    }

    public function getMessage()
    {
        return $this->hasOne(CmsCommMessage::class, ['id' => 'cms_comm_message_id']);
    }

    /** The place the employee sees: the asked-for one while the server has not done it yet. */
    public function getRole(): ?string
    {
        return $this->target_role ?: $this->folder_role;
    }

    /** Whether the server place of the copy is known: actions need it. */
    public function hasServerPlace(): bool
    {
        return $this->uid && $this->folder && $this->cms_comm_connection_id && $this->folder_role !== self::ROLE_GONE;
    }

    /** SQL of getRole() for queries; $alias is the table alias or name. */
    public static function roleExpression(?string $alias = null): Expression
    {
        $alias = $alias ?? static::tableName();

        return new Expression('COALESCE('.$alias.'.target_role, '.$alias.'.folder_role)');
    }

    public static function roleTitles(): array
    {
        return [
            self::ROLE_INBOX => 'Входящие',
            self::ROLE_SENT => 'Отправленные',
            self::ROLE_TRASH => 'Корзина',
            self::ROLE_JUNK => 'Спам',
            self::ROLE_ARCHIVE => 'Архив',
            self::ROLE_DRAFTS => 'Черновики',
            self::ROLE_OTHER => 'Папка',
            self::ROLE_GONE => 'Удалено в почтовом ящике',
        ];
    }
}
