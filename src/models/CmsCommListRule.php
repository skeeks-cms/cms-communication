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
use yii\helpers\Json;

/**
 * A rule of a list of recipients: the filter of a CRM section saved from
 * that section. services\ListRules counts it with the section's own code on
 * behalf of `user_id` and adds what it finds.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_comm_list_id
 * @property string      $section companies, leads, clients
 * @property string      $filter_values JSON
 * @property string|null $title
 * @property int|null    $user_id
 * @property int         $is_active
 * @property int|null    $last_run_at
 * @property int|null    $last_found
 * @property int|null    $last_added
 * @property int         $added_total
 * @property string|null $last_error
 *
 * @property CmsCommList  $list
 * @property CmsUser|null $user
 */
class CmsCommListRule extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_list_rule}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    /** @return array field => value */
    public function getValues(): array
    {
        $values = $this->filter_values ? Json::decode((string)$this->filter_values) : [];

        return is_array($values) ? $values : [];
    }

    public function getList()
    {
        return $this->hasOne(CmsCommList::class, ['id' => 'cms_comm_list_id']);
    }

    public function getUser()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'user_id']);
    }
}
