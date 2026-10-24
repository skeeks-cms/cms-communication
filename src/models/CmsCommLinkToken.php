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
 * A one-time key of a bot link (`?start=<token>`): carries no personal data,
 * only points at what the link does (for example, links the person who
 * opened it to a CRM record). Used once, then kept for the history.
 *
 * Telegram passes up to 64 characters A-Za-z0-9_-, MAX up to 128: tokens
 * are 32 such characters.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $created_by
 * @property int         $cms_site_id
 * @property int|null    $cms_comm_connection_id
 * @property string      $token
 * @property string      $purpose
 * @property string|null $model_code
 * @property int|null    $model_id
 * @property int         $expires_at
 * @property int|null    $used_at
 * @property int|null    $used_identity_id
 */
class CmsCommLinkToken extends ActiveRecord
{
    public const PURPOSE_LINK_RECORD = 'link_record';

    public static function tableName()
    {
        return '{{%cms_comm_link_token}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public function isUsable(?int $now = null): bool
    {
        return !$this->used_at && (int)$this->expires_at > ($now ?? time());
    }
}
