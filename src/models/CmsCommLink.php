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
 * Link of a message to a CRM entity (lead, company, client, ...). Its feed
 * entry (cms_log of type `message`) is referenced by cms_log_id.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $created_by
 * @property int         $cms_comm_message_id
 * @property string      $model_code
 * @property int         $model_id
 * @property string      $link_type
 * @property int|null    $cms_log_id
 */
class CmsCommLink extends ActiveRecord
{
    /** Exact match of an address with a lead, company or client email. */
    public const TYPE_AUTO = 'auto';
    /** The card the letter was written from. */
    public const TYPE_CONTEXT = 'context';
    /** Inherited from earlier letters of the conversation. */
    public const TYPE_THREAD = 'thread';
    /** Linked by an employee ("Привязать"). */
    public const TYPE_MANUAL = 'manual';
    /** The record was created from this letter ("Создать задачу / лид / сделку"). */
    public const TYPE_CREATED = 'created';

    public static function tableName()
    {
        return '{{%cms_comm_link}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    /** The linked record, resolved through the CMS model registry. */
    public function getLinkedModel()
    {
        $class = $this->model_code;
        if (!is_string($class) || !class_exists($class) || !method_exists($class, 'findOne')) {
            return null;
        }

        return $class::findOne((int)$this->model_id);
    }
}
