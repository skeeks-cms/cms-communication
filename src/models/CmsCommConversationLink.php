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
 * A whole chat linked to a CRM record (a company, a deal, a project): the
 * client's working group "Компания / сайт.ru". Messages of the chat get
 * their links and feed entries from it (stage 5.4).
 *
 * @property int      $id
 * @property int|null $created_at
 * @property int|null $created_by
 * @property int      $cms_comm_conversation_id
 * @property string   $model_code
 * @property int      $model_id
 */
class CmsCommConversationLink extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_conversation_link}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }
}
