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
 * A file of a draft: an attachment or a picture inside its text, kept in the
 * CMS storage while the draft lives. The letter form works with temporary
 * uploads of its session (TemporaryUploads tokens): `token` is the upload
 * this file is open as now; opening the draft in another session puts the
 * bytes back into the temporary area under a new token. A storage file may be
 * shared with letters and other drafts (same content hash), so it is removed
 * only when nothing refers to it (MailDrafts::forgetFile()).
 *
 * @property int                 $id
 * @property int|null            $created_at
 * @property int                 $cms_comm_draft_id
 * @property int|null            $storage_file_id
 * @property string              $name
 * @property int|null            $size
 * @property string|null         $content_hash
 * @property int                 $is_inline
 * @property string|null         $token
 * @property int                 $priority
 *
 * @property CmsCommDraft        $draft
 * @property CmsStorageFile|null $storageFile
 */
class CmsCommDraftFile extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_draft_file}}';
    }

    public function behaviors()
    {
        return [['class' => \yii\behaviors\TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    public function getDraft()
    {
        return $this->hasOne(CmsCommDraft::class, ['id' => 'cms_comm_draft_id']);
    }

    public function getStorageFile()
    {
        return $this->hasOne(CmsStorageFile::class, ['id' => 'storage_file_id']);
    }
}
