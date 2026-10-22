<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\communication\models\queries\CmsCommMessageQuery;
use skeeks\cms\models\CmsUser;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\helpers\Json;

/**
 * One letter (later a messenger message). Stored once even when it lies in
 * several mailboxes; copies are CmsCommMessageMailbox rows.
 *
 * @property int                     $id
 * @property int|null                $created_at
 * @property int|null                $updated_at
 * @property int                     $cms_site_id
 * @property int                     $cms_comm_conversation_id
 * @property int|null                $cms_comm_account_id
 * @property string                  $channel
 * @property string                  $direction
 * @property string                  $status
 * @property string                  $external_id
 * @property string|null             $in_reply_to
 * @property string|null             $references_list
 * @property string|null             $from_address
 * @property string|null             $from_name
 * @property string|null             $to_list
 * @property string|null             $cc_list
 * @property string|null             $bcc_list
 * @property string|null             $subject
 * @property string|null             $body_text
 * @property string|null             $body_html
 * @property string|null             $attachments
 * @property int                     $is_internal
 * @property int                     $is_auto
 * @property int                     $is_history  collected from the mailbox history: counts as read
 * @property string|null             $box_state   trash or junk: in the trash or spam of all its mailboxes (services\MailboxActions::refreshState())
 * @property int|null                $message_at
 * @property int|null                $sent_by_user_id
 * @property string|null             $error
 *
 * @property CmsCommConversation     $conversation
 * @property CmsCommAccount|null     $account
 * @property CmsCommMessageMailbox[] $mailboxCopies
 * @property CmsCommLink[]           $links
 * @property CmsUser|null            $sentBy
 * @property CmsCommMessageFile[]    $files attachments and images inside the text
 * @property CmsCommLabel[]          $labels
 */
class CmsCommMessage extends ActiveRecord
{
    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    /** The server may or may not have accepted it: never resent automatically. */
    public const STATUS_UNKNOWN = 'unknown';

    /** Type of the activity feed entries (cms_log.log_type). */
    public const LOG_TYPE = 'message';

    public static function tableName()
    {
        return '{{%cms_comm_message}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    /** Attributes that the search line of the letter is made of. */
    private const SEARCH_ATTRIBUTES = ['cms_comm_conversation_id', 'from_address', 'from_name', 'to_list', 'cc_list', 'bcc_list', 'subject', 'body_text', 'body_html'];

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        // Every writer (receive, send, history) keeps the conversation search up to date.
        if ($insert || array_intersect(array_keys($changedAttributes), self::SEARCH_ATTRIBUTES)) {
            \skeeks\cms\communication\services\MailSearch::index($this);
        }
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_RECEIVED => 'Получено',
            self::STATUS_QUEUED => 'В очереди',
            self::STATUS_SENDING => 'Отправляется',
            self::STATUS_SENT => 'Отправлено',
            self::STATUS_FAILED => 'Не отправлено',
            self::STATUS_UNKNOWN => 'Неизвестно, отправлено ли',
        ];
    }

    public function attributeLabels()
    {
        return [
            'direction' => 'Направление',
            'status' => 'Статус',
            'from_address' => 'От кого',
            'to_list' => 'Кому',
            'subject' => 'Тема',
            'message_at' => 'Дата',
            'cms_comm_account_id' => 'Ящик',
            'sent_by_user_id' => 'Отправил',
        ];
    }

    /** @return array address => name */
    public function getTo(): array
    {
        return $this->decodeList($this->to_list);
    }

    /** @return array address => name */
    public function getCc(): array
    {
        return $this->decodeList($this->cc_list);
    }

    /** @return array address => name; blind copies of letters sent from the CRM */
    public function getBcc(): array
    {
        return $this->hasAttribute('bcc_list') ? $this->decodeList($this->bcc_list) : [];
    }

    /** @return array[] each: name, size, type */
    public function getAttachmentList(): array
    {
        return $this->decodeList($this->attachments);
    }

    public function getFromAsText(): string
    {
        return $this->from_name ? $this->from_name.' <'.$this->from_address.'>' : (string)$this->from_address;
    }

    public function getToAsText(): string
    {
        $parts = [];
        foreach ($this->getTo() as $address => $name) {
            $parts[] = $name ? $name.' <'.$address.'>' : $address;
        }

        return implode(', ', $parts);
    }

    /** Short plain-text preview for feeds and lists. */
    public function getSnippet(int $length = 300): string
    {
        $text = (string)$this->body_text;
        if (trim($text) === '' && $this->body_html) {
            // Styles, scripts and the head are not text.
            $html = preg_replace('~<(style|script|head|title)\b[^>]*>.*?</\1>~is', ' ', (string)$this->body_html);
            $html = preg_replace('~<br\s*/?>|</p>|</div>|</tr>~i', ' ', (string)$html);
            $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // Drop the quoted history of replies.
        $text = preg_replace('/^>.*$/m', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }

    /**
     * The letter as a record name (skeeks->modelsConfig consumers: the
     * notification center, entity links): its subject.
     */
    public function getAsText(): string
    {
        return trim((string)$this->subject) !== '' ? (string)$this->subject : '(без темы)';
    }

    public function getStatusName(): string
    {
        return self::statuses()[$this->status] ?? (string)$this->status;
    }

    public function getConversation()
    {
        return $this->hasOne(CmsCommConversation::class, ['id' => 'cms_comm_conversation_id']);
    }

    public function getAccount()
    {
        return $this->hasOne(CmsCommAccount::class, ['id' => 'cms_comm_account_id']);
    }

    public function getMailboxCopies()
    {
        return $this->hasMany(CmsCommMessageMailbox::class, ['cms_comm_message_id' => 'id']);
    }

    public function getLinks()
    {
        return $this->hasMany(CmsCommLink::class, ['cms_comm_message_id' => 'id']);
    }

    public function getFiles()
    {
        return $this->hasMany(CmsCommMessageFile::class, ['cms_comm_message_id' => 'id'])
            ->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC]);
    }

    /** @return CmsCommMessageFile[] attachments shown under the letter */
    public function getAttachmentFiles(): array
    {
        return array_values(array_filter($this->files, static fn(CmsCommMessageFile $f) => !$f->is_inline));
    }

    public function getLabels()
    {
        return $this->hasMany(CmsCommLabel::class, ['id' => 'cms_comm_label_id'])
            ->viaTable('{{%cms_comm_message_label}}', ['cms_comm_message_id' => 'id'])
            ->orderBy(['priority' => SORT_ASC, 'name' => SORT_ASC]);
    }

    public function getSentBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'sent_by_user_id']);
    }

    private function decodeList($value): array
    {
        if (!$value) {
            return [];
        }
        $decoded = Json::decode((string)$value);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return CmsCommMessageQuery */
    public static function find()
    {
        return new CmsCommMessageQuery(static::class);
    }
}
