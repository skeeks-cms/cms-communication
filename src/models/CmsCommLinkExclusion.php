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
 * A CRM record an employee unlinked from a whole conversation: automatic
 * linking (by address, by the thread, from the card) does not bring it back
 * to later letters. Linking it by hand again removes the row.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $created_by
 * @property int         $cms_comm_conversation_id
 * @property string      $model_code
 * @property int         $model_id
 */
class CmsCommLinkExclusion extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_link_exclusion}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }
}
