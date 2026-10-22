<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\models\CmsStorageFile;
use yii\db\ActiveRecord;

/**
 * A file of a letter: an attachment or an image inside its text. Several
 * letters may share one storage file (a forwarded attachment, the same logo
 * in every letter of a sender), so a storage file is removed only when no
 * letter file refers to it. A file not kept in the CRM (storage_file_id
 * null: by the storage settings, removed by the cleanup) stays in the
 * mailbox and is fetched from it when someone opens it (part_index).
 *
 * @property int            $id
 * @property int|null       $created_at
 * @property int            $cms_comm_message_id
 * @property int|null       $storage_file_id null — only in the mailbox
 * @property int|null       $part_index position among the letter files (MailParser files)
 * @property int            $is_cached the copy was fetched from the mailbox on demand
 * @property int|null       $last_access_at
 * @property string|null    $fetch_error
 * @property string         $name
 * @property string|null    $mime_type
 * @property int|null       $size
 * @property string|null    $content_hash SHA-256 of the content: one stored copy per site
 * @property string|null    $content_id
 * @property int            $is_inline
 * @property int            $priority
 *
 * @property CmsCommMessage $message
 * @property CmsStorageFile $storageFile
 */
class CmsCommMessageFile extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_message_file}}';
    }

    public function behaviors()
    {
        return [['class' => \yii\behaviors\TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    public function getMessage()
    {
        return $this->hasOne(CmsCommMessage::class, ['id' => 'cms_comm_message_id']);
    }

    public function getStorageFile()
    {
        return $this->hasOne(CmsStorageFile::class, ['id' => 'storage_file_id']);
    }

    public function isImage(): bool
    {
        return strpos((string)$this->mime_type, 'image/') === 0;
    }

    /** The file has a copy in the CRM (otherwise it is fetched from the mailbox). */
    public function isStored(): bool
    {
        return $this->storage_file_id && $this->storageFile && is_file((string)$this->storageFile->rootSrc);
    }
}
