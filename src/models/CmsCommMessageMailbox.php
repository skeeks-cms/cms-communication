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
 * A copy of a message in a mailbox (folder and UID on the server).
 *
 * @property int            $id
 * @property int|null       $created_at
 * @property int            $cms_comm_message_id
 * @property int            $cms_comm_account_id
 * @property int|null       $cms_comm_connection_id
 * @property string|null    $folder
 * @property int|null       $uidvalidity
 * @property int|null       $uid
 *
 * @property CmsCommAccount $account
 */
class CmsCommMessageMailbox extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_message_mailbox}}';
    }

    public function behaviors()
    {
        return [['class' => TimestampBehavior::class, 'updatedAtAttribute' => false]];
    }

    public function getAccount()
    {
        return $this->hasOne(CmsCommAccount::class, ['id' => 'cms_comm_account_id']);
    }
}
