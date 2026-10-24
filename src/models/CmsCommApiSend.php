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
 * A send of a checked draft through MCP/REST (services\AiSend), by its
 * idempotency key: the letter it made, which request made it. Written in the
 * transaction of the letter.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_site_id
 * @property int         $cms_user_id
 * @property string|null $client "mcp:<OAuth client>"
 * @property string      $idempotency_key
 * @property string      $request_hash
 * @property int|null    $cms_comm_draft_id
 * @property int|null    $cms_comm_message_id
 *
 * @property CmsCommMessage|null $message
 */
class CmsCommApiSend extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_api_send}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function getMessage()
    {
        return $this->hasOne(CmsCommMessage::class, ['id' => 'cms_comm_message_id']);
    }
}
