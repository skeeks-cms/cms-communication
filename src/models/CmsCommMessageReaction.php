<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\models\CmsUser;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A reaction on a chat message (chat\ChatReactions): of a person of the
 * messenger or of an employee in the CRM.
 *
 * @property int                  $id
 * @property int|null             $created_at
 * @property int                  $cms_comm_message_id
 * @property int|null             $cms_comm_identity_id
 * @property int|null             $cms_user_id
 * @property string               $emoji
 *
 * @property CmsCommIdentity|null $identity
 * @property CmsUser|null         $cmsUser
 */
class CmsCommMessageReaction extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_message_reaction}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    public function getIdentity()
    {
        return $this->hasOne(CmsCommIdentity::class, ['id' => 'cms_comm_identity_id']);
    }

    public function getCmsUser()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'cms_user_id']);
    }
}
