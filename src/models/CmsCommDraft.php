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
 * A letter being written, saved by the letter form while the employee types
 * (services\MailDrafts). Recipients are kept as typed; the letter is checked
 * only when it is sent. A reply draft remembers the answered letter, so
 * "Ответить" on that letter continues it. Files live in the storage with the
 * draft (CmsCommDraftFile). Sending the letter removes the draft.
 *
 * @property int                 $id
 * @property int|null            $created_at
 * @property int|null            $updated_at
 * @property int                 $cms_site_id
 * @property int                 $cms_user_id
 * @property int|null            $cms_comm_account_id
 * @property string|null         $to_list
 * @property string|null         $cc_list
 * @property string|null         $bcc_list
 * @property string|null         $subject
 * @property string|null         $body_html cleaned with ComposeForm::EDITOR_PRESET
 * @property int|null            $reply_to_message_id
 * @property int|null            $forward_message_id
 * @property int                 $is_reply_all
 * @property int                 $include_quote
 * @property string|null         $context_model_code
 * @property int|null            $context_model_id
 * @property string|null         $file_ids JSON: storage file ids kept from the forwarded letter
 * @property string|null         $sendables JSON: "type:id" put in with "Прикрепить из…"
 *
 * @property CmsCommDraftFile[]  $files
 * @property CmsCommAccount|null $account
 * @property CmsCommMessage|null $replyTo
 * @property CmsCommMessage|null $forwarded
 */
class CmsCommDraft extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_draft}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function getFiles()
    {
        return $this->hasMany(CmsCommDraftFile::class, ['cms_comm_draft_id' => 'id'])->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC]);
    }

    public function getAccount()
    {
        return $this->hasOne(CmsCommAccount::class, ['id' => 'cms_comm_account_id']);
    }

    public function getReplyTo()
    {
        return $this->hasOne(CmsCommMessage::class, ['id' => 'reply_to_message_id']);
    }

    public function getForwarded()
    {
        return $this->hasOne(CmsCommMessage::class, ['id' => 'forward_message_id']);
    }

    /** @return int[] */
    public function getFileIds(): array
    {
        $ids = $this->file_ids ? Json::decode((string)$this->file_ids) : [];

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public function setFileIds(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $this->file_ids = $ids ? Json::encode($ids) : null;
    }

    /** @return string[] "type:id" of things of other packages put into the letter */
    public function getSendableRefs(): array
    {
        $refs = $this->hasAttribute('sendables') && $this->sendables ? Json::decode((string)$this->sendables) : [];

        return is_array($refs) ? array_values(array_map('strval', $refs)) : [];
    }

    public function setSendableRefs(array $refs): void
    {
        if ($this->hasAttribute('sendables')) {
            $refs = array_values(array_unique(array_filter(array_map('strval', $refs))));
            $this->sendables = $refs ? Json::encode($refs) : null;
        }
    }

    /** Addresses typed into "Кому", as a list for the drafts folder. */
    public function getToAddresses(): array
    {
        return \skeeks\cms\communication\forms\ComposeForm::splitAddresses((string)$this->to_list);
    }

    /** Beginning of the text for the drafts folder. */
    public function getSnippet(int $length = 200): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', \skeeks\cms\helpers\RichTextHtml::toText((string)$this->body_html)));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }
}
