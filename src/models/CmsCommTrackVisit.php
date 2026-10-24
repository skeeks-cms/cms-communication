<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\helpers\Json;

/**
 * One visit of our site by the personal code of a letter
 * (services\LinkVisits): a counted visit of a person with its pages, or a
 * robot (is_bot) or a link check of a mail service (check_reason) kept
 * for history without counting. A counted visit has a token: the browser
 * keeps it in the cookie `sx_visit` and reports the next pages by it.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_comm_track_id
 * @property string|null $token
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int         $is_bot
 * @property string|null $check_reason
 * @property string|null $host
 * @property string|null $pages
 * @property int         $pages_count
 * @property int|null    $last_at
 *
 * @property CmsCommTrack $track
 */
class CmsCommTrackVisit extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_track_visit}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function getTrack()
    {
        return $this->hasOne(CmsCommTrack::class, ['id' => 'cms_comm_track_id']);
    }

    public function getIsCounted(): bool
    {
        return !$this->is_bot && $this->check_reason === null && $this->token !== null;
    }

    /** @return array[] url, title, at — in the order of the visit */
    public function getPageList(): array
    {
        $pages = $this->pages ? Json::decode((string)$this->pages) : [];

        return is_array($pages) ? array_values(array_filter($pages, 'is_array')) : [];
    }
}
