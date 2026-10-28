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
 * The mailing state of a CRM record (services\Audience): where a card made
 * by a mailing import came from and whether it is cold or warm. Consent and
 * opt-out are not here: they belong to the contact (CmsCommContactTopic).
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_site_id
 * @property string      $record_type company, client, lead (services\CrmRecords)
 * @property int         $record_id
 * @property string|null $source_name «Рассылка: база …»
 * @property int|null    $cms_comm_import_id the import that made the card
 * @property string      $state STATE_*
 * @property int|null    $warm_at
 * @property string|null $warm_by WARM_*
 * @property int|null    $warm_campaign_id
 * @property int|null    $warm_message_id
 *
 * @property CmsCommCampaign|null $warmCampaign
 * @property CmsCommImport|null   $import
 */
class CmsCommAudience extends ActiveRecord
{
    public const STATE_COLD = 'cold';
    public const STATE_WARM = 'warm';

    public const WARM_VISIT = 'visit';
    public const WARM_PRESENTATION = 'presentation';
    public const WARM_OFFER = 'offer';

    public static function tableName()
    {
        return '{{%cms_comm_audience}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public static function states(): array
    {
        return [self::STATE_COLD => 'Холодный', self::STATE_WARM => 'Тёплый'];
    }

    public static function warmReasons(): array
    {
        return [
            self::WARM_VISIT => 'перешёл на сайт по ссылке из письма',
            self::WARM_PRESENTATION => 'смотрел Презентацию',
            self::WARM_OFFER => 'открыл КП',
        ];
    }

    public function getWarmCampaign()
    {
        return $this->hasOne(CmsCommCampaign::class, ['id' => 'warm_campaign_id']);
    }

    public function getImport()
    {
        return $this->hasOne(CmsCommImport::class, ['id' => 'cms_comm_import_id']);
    }

    public function isWarm(): bool
    {
        return $this->state === self::STATE_WARM;
    }
}
