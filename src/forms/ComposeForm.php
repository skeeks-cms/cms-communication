<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\forms;

use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\helpers\RichTextHtml;
use yii\base\Model;

/**
 * A letter being written: "Написать", "Ответить", "Ответить всем",
 * "Переслать". The service validates the sender mailbox, the recipients
 * against the employee's rights and the stand allowlist.
 *
 * The text comes either as HTML from the letter editor (body_html) or as plain
 * text (body, for API callers). The answered or forwarded letter is not part
 * of the text: the service appends it when queueing (include_quote).
 */
class ComposeForm extends Model
{
    /**
     * Feature set of the letter editor (RichTextInputWidget) and of the server
     * cleaning: the standard one plus the font family, used only in letters.
     */
    public const EDITOR_PRESET = ['preset' => 'standard', 'fonts' => true];

    /** Toolbar of the letter editor: font choice, no headings. */
    public const EDITOR_TOOLS = ['undo', 'redo', '|', 'bold', 'italic', 'underline', 'strike', '|', 'font', 'size', 'color', 'background', '|',
        'clear', '|', 'emoji', 'link', 'unlink', 'image', 'quote', '|', 'align', 'ul', 'ol', 'fullscreen'];

    public $cms_comm_account_id;
    public $to = '';
    public $cc = '';
    public $bcc = '';
    public $subject = '';
    public $body = '';
    public $body_html = '';

    /** The card the letter is written from: model class and id. */
    public $context_model_code = '';
    public $context_model_id;

    /** The letter being answered. */
    public $reply_to_message_id;

    /** The letter being forwarded: always attached below the text. */
    public $forward_message_id;

    /** Quote the answered letter below the reply. */
    public $include_quote = 1;

    /** "Ответить всем": kept with a draft, so the draft opens as it was started. */
    public $reply_all = 0;

    /** The draft this form continues (MailDrafts): removed when the letter is sent. */
    public $draft_id;

    /** @var int[] attachments kept from the forwarded letter: its storage file ids */
    public $file_ids = [];

    /** @var string[] attachments uploaded into the editor: MailFiles::storeTemp() tokens */
    public $uploads = [];

    /**
     * @var string[] things of other packages put in with "Прикрепить из…" or
     * sent from "Отправить клиенту": "type:id" (send\SendableInterface). Each
     * goes as its personal link (a button) and its files.
     */
    public $sendables = [];

    /**
     * The topic of an advertising letter (CmsCommTopic id): set by campaigns
     * in code, never loaded from a request. Such a letter goes to one
     * recipient that services\MailingPolicy allows, with the personal opt-out
     * link (services\Unsubscribe::PLACEHOLDER in the text or a line at the
     * end) and the List-Unsubscribe headers. Personal letters have none; an
     * individual offer keeps the topic for the opt-out checks only (withOptOut()).
     */
    public $cms_comm_topic_id;

    /** The mailing campaign of the letter (CmsCommCampaign id): set in code, never loaded from a request. */
    public $cms_comm_campaign_id;

    /**
     * The CRM record of the recipient, set in code by campaigns (the record
     * was checked when the campaign was launched): the letter is linked to it
     * and its sends belong to it. Never loaded from a request.
     *
     * @var \yii\db\ActiveRecord|null
     */
    public $record;

    /**
     * @var \skeeks\cms\communication\send\SendableInterface[] "type:id" => the document prepared in
     * code (campaigns: a document made from a template for this recipient), used instead of finding
     * it for the employee. Never loaded from a request.
     */
    public $sendableInstances = [];

    /**
     * "Подготовлено ИИ" of the letter (services\AiMark): taken from the
     * employee's own draft made by AI (MailDrafts::applyAiDraft()) or from a
     * campaign recipient whose letter AI wrote. Set in code, never loaded
     * from a request.
     *
     * @var array|null
     */
    public $aiMark;

    /**
     * The purpose of a letter AI prepared (services\LetterPurpose: advertising,
     * individual_offer, personal, service), from its draft (MailDrafts::applyAiDraft()); null for
     * a letter an employee writes. Its rules are checked here, so the mail
     * screen and the API send it under the same conditions. Set in code, never
     * loaded from a request.
     *
     * @var string|null
     */
    public $purpose;

    /** @var MailComposeService|null set by the controller for validation */
    public $service;

    public function rules()
    {
        return [
            [['to', 'cc', 'bcc', 'subject', 'body', 'context_model_code'], 'filter', 'filter' => static fn($v) => trim((string)$v)],
            [['body_html'], 'filter', 'filter' => static fn($v) => RichTextHtml::clean((string)$v, self::EDITOR_PRESET)],
            [['cms_comm_account_id', 'to'], 'required'],
            [['cms_comm_account_id', 'context_model_id', 'reply_to_message_id', 'forward_message_id', 'draft_id'], 'integer'],
            [['include_quote', 'reply_all'], 'boolean'],
            [['file_ids'], 'filter', 'filter' => static fn($v) => array_values(array_unique(array_filter(array_map('intval', (array)$v)))), 'skipOnEmpty' => false],
            [['uploads'], 'filter', 'filter' => static fn($v) => array_values(array_unique(array_filter(array_map('strval', (array)$v)))), 'skipOnEmpty' => false],
            [['sendables'], 'filter', 'filter' => static fn($v) => array_values(array_unique(array_filter(array_map(static fn($r) => trim((string)$r), (array)$v)))), 'skipOnEmpty' => false],
            [['sendables'], 'validateSendables', 'skipOnEmpty' => false],
            [['file_ids'], 'validateFiles', 'skipOnEmpty' => false],
            [['subject'], 'string', 'max' => 500],
            [['body', 'body_html'], 'string', 'max' => 500000],
            [['context_model_code'], 'string', 'max' => 190],
            [['to', 'cc', 'bcc'], 'validateAddresses'],
            [['cms_comm_account_id'], 'validateAccount'],
            [['body'], 'validateText', 'skipOnEmpty' => false],
            [['purpose'], 'validatePurpose', 'skipOnEmpty' => true],
            [['context_model_id'], 'validateContext', 'skipOnEmpty' => false],
        ];
    }

    /**
     * A letter from a company or a person card (core CrmAccess, owner's decision 29.10.2026): «Свои сделки»
     * and "edit" write only from their deal — the deal is the context, so the reply follows it.
     */
    public function validateContext($attribute)
    {
        $access = \skeeks\cms\crm\CrmAccess::class;
        if (!method_exists($access, 'clientActionError')) {
            return;
        }
        $record = $this->record instanceof \yii\db\ActiveRecord ? $this->record
            : ($this->service && $this->context_model_code && $this->context_model_id
                ? $this->service->contextRecord((string)$this->context_model_code, (int)$this->context_model_id) : null);
        $error = null;
        if ($record instanceof \skeeks\cms\models\CmsCompany) {
            $error = $access::instance()->clientActionError((int)$record->id, null, null);
        } elseif ($record instanceof \skeeks\cms\models\CmsUser && !$record->is_worker) {
            $error = $access::instance()->clientActionError(null, (int)$record->id, null);
        }
        if ($error) {
            $this->addError($attribute, $error);
        }
    }

    /** A letter AI prepared: what its purpose needs (a topic, a dialog, cooperation; no complaint or dead address). */
    public function validatePurpose($attribute)
    {
        $record = $this->record instanceof \yii\db\ActiveRecord ? $this->record
            : ($this->service && $this->context_model_code && $this->context_model_id
                ? $this->service->contextRecord((string)$this->context_model_code, (int)$this->context_model_id) : null);
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $check = \skeeks\cms\communication\services\LetterPurpose::check((string)$this->purpose, $this->cms_comm_topic_id ? (int)$this->cms_comm_topic_id : null,
            $record, (string)($this->getToAddresses()[0] ?? ''), $siteId, $this->reply_to_message_id ? (int)$this->reply_to_message_id : null);
        foreach ($check['problems'] as $problem) {
            $this->addError($attribute, (string)$problem['message']);
        }
        if ($this->purpose === \skeeks\cms\communication\services\LetterPurpose::INDIVIDUAL_OFFER && $this->cms_comm_campaign_id) {
            $this->addError($attribute, 'Индивидуальное предложение не уходит кампанией: письма рассылки всегда со строкой «Отписаться».');
        }
    }

    /**
     * Does the letter carry the personal opt-out line and List-Unsubscribe: a letter with a topic, except an
     * individual offer (LetterPurpose::INDIVIDUAL_OFFER) outside a campaign — its topic only checks the opt-outs.
     */
    public function withOptOut(): bool
    {
        return (bool)$this->cms_comm_topic_id
            && ($this->purpose !== \skeeks\cms\communication\services\LetterPurpose::INDIVIDUAL_OFFER || $this->cms_comm_campaign_id);
    }

    public function attributeLabels()
    {
        return [
            'cms_comm_account_id' => 'От кого',
            'to' => 'Кому',
            'cc' => 'Копия',
            'bcc' => 'Скрытая копия',
            'subject' => 'Тема',
            'body' => 'Текст письма',
            'body_html' => 'Текст письма',
        ];
    }

    public function attributeHints()
    {
        return [
            'to' => 'Несколько адресов — через запятую.',
        ];
    }

    /** Text of the letter as plain text, whichever way it was written. */
    public function getText(): string
    {
        return $this->body_html !== '' ? RichTextHtml::toText($this->body_html) : (string)$this->body;
    }

    /** Text of the letter as HTML, whichever way it was written. */
    public function getHtml(): string
    {
        return $this->body_html !== '' ? $this->body_html : RichTextHtml::fromText((string)$this->body);
    }

    /** @return string[] */
    public function getToAddresses(): array
    {
        return self::splitAddresses($this->to);
    }

    /** @return string[] */
    public function getCcAddresses(): array
    {
        return self::splitAddresses($this->cc);
    }

    /** @return string[] */
    public function getBccAddresses(): array
    {
        return self::splitAddresses($this->bcc);
    }

    /**
     * "a@b.ru, Иван <c@d.ru>; e@f.ru" → addresses in lower case. Names in
     * angle-bracket form are dropped: the recipients are addresses.
     */
    public static function splitAddresses(?string $value): array
    {
        $value = (string)$value;
        $addresses = [];
        if (preg_match_all('/<([^<>\s]+@[^<>\s]+)>/', $value, $m)) {
            $addresses = $m[1];
            // Quoted names may contain separators: "ООО, Ромашка" <info@romashka.ru>.
            $value = (string)preg_replace(['/"[^"]*"/', '/[^,;<>]*<[^<>]*>/'], ' ', $value);
        }
        $addresses = array_merge($addresses, preg_split('/[,;\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY));

        return array_values(array_unique(array_filter(array_map(static fn($a) => mb_strtolower(trim($a, " <>\"'\t")), $addresses))));
    }

    public function validateAddresses($attribute)
    {
        $addresses = self::splitAddresses($this->$attribute);
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, "Адрес «{$address}» указан неверно.");
                return;
            }
        }
        if ($this->service && $addresses && ($error = $this->service->recipientsError($this, $addresses))) {
            $this->addError($attribute, $error);
        }
    }

    public function validateAccount($attribute)
    {
        if ($this->service && !$this->service->canSendFrom((int)$this->cms_comm_account_id)) {
            $this->addError($attribute, 'С этого ящика вам нельзя отправлять письма.');
        }
    }

    /** Attachments and images of the text: only files this employee may send. */
    public function validateFiles($attribute)
    {
        if (count($this->file_ids) + count($this->uploads) > 30) {
            $this->addError($attribute, 'Не больше 30 вложений в одном письме.');
            return;
        }
        if ($this->service && ($error = $this->service->filesError($this))) {
            $this->addError($attribute, $error);
        }
    }

    /** Things of other packages: each must be found for this employee. */
    public function validateSendables($attribute)
    {
        if (count($this->sendables) > 5) {
            $this->addError($attribute, 'Не больше 5 документов в одном письме.');
            return;
        }
        if ($this->service && ($error = $this->service->sendablesError($this))) {
            $this->addError($attribute, $error);
        }
    }

    /** @return string[] upload tokens of the images inside the text */
    public function getImageUploads(): array
    {
        return RichTextHtml::uploadTokens((string)$this->body_html);
    }

    /** A letter needs some text; a forwarded letter is text enough. */
    public function validateText($attribute)
    {
        if (!$this->forward_message_id && !$this->file_ids && !$this->uploads && !$this->sendables && !$this->getImageUploads()
            && trim($this->getText()) === '') {
            $this->addError($attribute, 'Напишите текст письма.');
        }
    }
}
