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
 * A person in a messenger (one per site, channel and messenger user id),
 * whatever bots of the site they talk to. Linked to a CMS user — a client or
 * our employee: messages of an employee in a group chat are "от нас".
 * Written by bot\BotIngest; the link is set by the linking flows only.
 *
 * @property int          $id
 * @property int|null     $created_at
 * @property int|null     $updated_at
 * @property int          $cms_site_id
 * @property string       $channel
 * @property string       $external_id
 * @property string|null  $name
 * @property string|null  $username
 * @property int          $is_bot
 * @property string|null  $phone the person's own contact, digits with the country code
 * @property int|null     $phone_verified_at
 * @property int|null     $cms_user_id
 * @property string|null  $link_method start, contact, sms, manual
 * @property int|null     $linked_at
 * @property int|null     $linked_by
 * @property int          $is_blocked stopped or blocked the bot
 * @property int|null     $last_seen_at
 * @property int|null     $photo_storage_file_id the person's photo (bot\BotChats)
 * @property string|null  $photo_ref where the photo came from
 *
 * @property CmsUser|null $cmsUser
 */
class CmsCommIdentity extends ActiveRecord
{
    public const LINK_START = 'start';
    public const LINK_CONTACT = 'contact';
    public const LINK_SMS = 'sms';
    public const LINK_MANUAL = 'manual';

    public static function tableName()
    {
        return '{{%cms_comm_identity}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function getCmsUser()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'cms_user_id']);
    }

    /** Our employee: their messages in a group chat are "от нас". */
    public function isWorker(): bool
    {
        return $this->cms_user_id && $this->cmsUser && (int)$this->cmsUser->is_worker === 1;
    }

    public function asText()
    {
        $name = trim((string)$this->name);
        if ($name === '') {
            $name = $this->username ? '@'.$this->username : '#'.$this->external_id;
        }

        return $name;
    }
}
