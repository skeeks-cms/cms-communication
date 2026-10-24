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
 * The personal code of a letter and its recipient in links to our sites
 * (`sx=<code>`, services\LinkMarks): one per letter that carries such
 * links. A visit by the code (CmsCommTrackVisit) belongs to the letter, the
 * CRM record of the recipient, the campaign and the offer of the letter.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int         $cms_site_id
 * @property string      $code
 * @property int         $cms_comm_message_id
 * @property string      $channel
 * @property string|null $recipient
 * @property string|null $model_code
 * @property int|null    $model_id
 * @property int|null    $cms_comm_campaign_id
 * @property string|null $offer_ref
 * @property string|null $utm_campaign
 * @property int|null    $first_visit_at
 * @property int|null    $last_visit_at
 * @property int         $visits_count
 *
 * @property CmsCommMessage      $message
 * @property CmsCommTrackVisit[] $visits
 */
class CmsCommTrack extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_track}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    public function getMessage()
    {
        return $this->hasOne(CmsCommMessage::class, ['id' => 'cms_comm_message_id']);
    }

    public function getVisits()
    {
        return $this->hasMany(CmsCommTrackVisit::class, ['cms_comm_track_id' => 'id']);
    }

    /** The CRM record of the recipient (lead, company, client), if it still exists. */
    public function getRecord(): ?ActiveRecord
    {
        // The model code is the class, as in the links of letters (CmsCommLink).
        if (!$this->model_id || !\skeeks\cms\communication\services\CrmRecords::typeOfCode((string)$this->model_code)) {
            return null;
        }
        /** @var ActiveRecord $class */
        $class = (string)$this->model_code;

        return $class::findOne((int)$this->model_id);
    }
}
