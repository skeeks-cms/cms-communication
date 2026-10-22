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
 * Signature of one employee in letters from one mailbox. The letter form
 * puts it below the text (above the quote) and swaps it when "От кого"
 * changes. Saved and read through services\MailTemplates.
 *
 * @property int            $id
 * @property int|null       $created_at
 * @property int|null       $updated_at
 * @property int            $cms_comm_account_id
 * @property int            $cms_user_id
 * @property string         $body_html cleaned with MailTemplates::EDITOR_PRESET
 *
 * @property CmsCommAccount $account
 */
class CmsCommSignature extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_signature}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function getAccount()
    {
        return $this->hasOne(CmsCommAccount::class, ['id' => 'cms_comm_account_id']);
    }
}
