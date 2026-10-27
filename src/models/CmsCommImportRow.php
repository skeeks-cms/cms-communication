<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\models\CmsLead;
use yii\db\ActiveRecord;
use yii\helpers\Json;

/**
 * One row of an imported table and what became of it: a new lead, a
 * duplicate of a CRM record (which one and by what) or skipped with the
 * reason. The rows of a finished import are its report and later the
 * audience of a campaign.
 *
 * @property int         $id
 * @property int         $cms_comm_import_id
 * @property int         $row_num
 * @property string      $data JSON: values of the columns
 * @property string|null $status
 * @property string|null $reason
 * @property int|null    $cms_lead_id
 * @property string|null $match_code
 * @property int|null    $match_id
 * @property string|null $matched_by
 *
 * @property CmsCommImport $import
 * @property CmsLead|null  $lead
 */
class CmsCommImportRow extends ActiveRecord
{
    public const STATUS_CREATED = 'created';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_SKIPPED = 'skipped';

    public static function tableName()
    {
        return '{{%cms_comm_import_row}}';
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_CREATED => 'Создана сделка',
            self::STATUS_DUPLICATE => 'Уже есть в CRM',
            self::STATUS_SKIPPED => 'Пропущено',
        ];
    }

    /** @return string[] values by column number */
    public function getValues(): array
    {
        return array_values(array_map('strval', (array)Json::decode($this->data)));
    }

    public function getImport()
    {
        return $this->hasOne(CmsCommImport::class, ['id' => 'cms_comm_import_id']);
    }

    public function getLead()
    {
        return $this->hasOne(CmsLead::class, ['id' => 'cms_lead_id']);
    }
}
