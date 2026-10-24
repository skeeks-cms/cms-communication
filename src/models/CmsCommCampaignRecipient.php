<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MailingPolicy;
use skeeks\cms\models\CmsUser;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A recipient of a campaign, fixed at launch, and the report of this
 * recipient: excluded with the reason, waiting, sent (the message), failed;
 * opened the offer, replied, accepted the КП, opted out. One recipient per
 * CRM record and campaign: a campaign never writes twice to one record.
 *
 * Before launch a row exists only for a recipient added by hand or by AI
 * (status "draft", services\Campaigns::addRecipient()), usually with a
 * personal letter: its own subject and text instead of the campaign template,
 * and who prepared it (text_by, text_client, text_user_id).
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_comm_campaign_id
 * @property string      $record_type CrmRecords type: lead, company, client
 * @property int         $record_id
 * @property string|null $address
 * @property string|null $name
 * @property string|null $subject   personal subject; empty — the campaign's
 * @property string|null $body_html personal text; empty — the campaign template
 * @property string|null $text_by   who prepared the personal text: TEXT_BY_USER, TEXT_BY_AI
 * @property string|null $text_client through what: "crm", "mcp:<client>"
 * @property int|null    $text_user_id the CRM user who saved it (an employee or the AI assistant)
 * @property int|null    $text_at
 * @property int|null    $text_edited_by the employee who changed a text written by AI (the mark stays)
 * @property int|null    $cms_comm_account_id
 * @property int|null    $sender_user_id
 * @property string      $status
 * @property string|null $reason
 * @property string|null $error
 * @property int|null    $cms_comm_message_id
 * @property string|null $send_ref
 * @property int|null    $sent_at
 * @property int|null    $opened_at
 * @property int|null    $replied_at
 * @property int|null    $accepted_at
 * @property int|null    $unsubscribed_at
 * @property int|null    $visited_at first came to our site by a link of the letter (services\LinkVisits)
 * @property int|null    $delivered_at a mailing service confirmed delivery (services\DeliveryEvents)
 * @property int|null    $bounced_at a mailing service reported the letter undelivered
 *
 * @property CmsCommCampaign     $campaign
 * @property CmsCommAccount|null $account
 * @property CmsUser|null        $senderUser
 * @property CmsCommMessage|null $message
 */
class CmsCommCampaignRecipient extends ActiveRecord
{
    /** Added to a campaign not launched yet (by hand or by AI). */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_PENDING = 'pending';
    /** The letter is being made now (a claim of the sending job). */
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /* Reasons of exclusion beside MailingPolicy::REASON_* */
    public const REASON_NOT_ALLOWED = 'not_allowed';
    public const REASON_NO_MAILBOX = 'no_mailbox';
    public const REASON_DUPLICATE_ADDRESS = 'duplicate_address';
    public const REASON_HIDDEN = 'hidden';
    public const REASON_CLOSED = 'closed';
    /* Rules that protect every mailing (stage 4D) */
    public const REASON_EXCLUDED_LIST = 'excluded_list';
    public const REASON_RECENT = 'recent';
    public const REASON_QUIET = 'quiet';
    public const REASON_SAME_COMPANY = 'same_company';

    public const TEXT_BY_USER = 'user';
    public const TEXT_BY_AI = 'ai';

    public static function tableName()
    {
        return '{{%cms_comm_campaign_recipient}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Добавлен',
            self::STATUS_EXCLUDED => 'Исключён',
            self::STATUS_PENDING => 'Ждёт отправки',
            self::STATUS_SENDING => 'Отправляется',
            self::STATUS_SENT => 'Отправлено',
            self::STATUS_FAILED => 'Ошибка',
            self::STATUS_CANCELLED => 'Не отправлено: кампания отменена',
        ];
    }

    /** @return string[] reason => text for the summary of excluded recipients */
    public static function reasons(): array
    {
        return MailingPolicy::reasons() + [
            self::REASON_NOT_ALLOWED => 'Адрес не разрешён настройкой отправки (allowedRecipients)',
            self::REASON_NO_MAILBOX => 'Нет ящика, с которого отправить: у ответственного нет своего ящика или нет ответственного',
            self::REASON_DUPLICATE_ADDRESS => 'Этот адрес уже есть у другого получателя кампании',
            self::REASON_HIDDEN => 'Запись не видна запустившему кампанию',
            self::REASON_CLOSED => 'Лид закрыт',
            self::REASON_EXCLUDED_LIST => 'Есть в исключающем списке (сам или его компания)',
            self::REASON_RECENT => 'Недавно получал рассылку: не чаще одной в срок из настроек рассылок',
            self::REASON_QUIET => 'Недавно общались: не мешаем менеджеру',
            self::REASON_SAME_COMPANY => 'Письмо получит другой контакт этой компании',
        ];
    }

    /** Has its own letter instead of the campaign template. */
    public function getHasPersonalText(): bool
    {
        return trim((string)$this->body_html) !== '' || trim((string)$this->subject) !== '';
    }

    /** "Личный текст · ИИ (mcp:claude)", "Шаблон кампании". */
    public function getTextLabel(): string
    {
        if (!$this->getHasPersonalText()) {
            return 'Шаблон кампании';
        }
        $by = $this->text_by === self::TEXT_BY_AI ? 'ИИ' : 'сотрудник';
        $client = $this->text_client && $this->text_client !== 'crm' ? ' ('.\skeeks\cms\communication\services\AiMark::clientName((string)$this->text_client).')' : '';
        $edited = $this->text_by === self::TEXT_BY_AI && $this->text_edited_by ? ', изменён сотрудником' : '';

        return 'Личный текст · '.$by.$client.$edited;
    }

    public function getReasonText(): string
    {
        return self::reasons()[$this->reason] ?? (string)$this->reason;
    }

    public function getStatusName(): string
    {
        return self::statuses()[$this->status] ?? (string)$this->status;
    }

    public function getCampaign()
    {
        return $this->hasOne(CmsCommCampaign::class, ['id' => 'cms_comm_campaign_id']);
    }

    public function getAccount()
    {
        return $this->hasOne(CmsCommAccount::class, ['id' => 'cms_comm_account_id']);
    }

    public function getSenderUser()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'sender_user_id']);
    }

    public function getMessage()
    {
        return $this->hasOne(CmsCommMessage::class, ['id' => 'cms_comm_message_id']);
    }

    /** The CRM record of the recipient (a lead), or null when it was removed. */
    public function getRecord(): ?ActiveRecord
    {
        if ($this->isRelationPopulated('record')) {
            return $this->getRelatedRecords()['record'];
        }
        $type = CrmRecords::type((string)$this->record_type);
        if (!$type) {
            return null;
        }
        /** @var ActiveRecord $class */
        $class = $type['class'];

        return $class::findOne((int)$this->record_id);
    }
}
