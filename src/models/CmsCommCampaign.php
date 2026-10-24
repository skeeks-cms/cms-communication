<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\base\ActiveRecord;
use skeeks\cms\communication\campaign\MailboxCampaignChannel;
use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\helpers\RichTextHtml;
use skeeks\cms\models\CmsUser;
use skeeks\cms\query\CmsActiveQuery;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;

/**
 * A mailing campaign: one topic, an audience, a letter (subject and text with
 * placeholders) with an offer, a channel and the report. The recipients are
 * fixed at launch (CmsCommCampaignRecipient); every letter is an ordinary
 * message in the feed of its lead with the campaign mark. The work is done
 * by services\Campaigns and the channel of the campaign
 * (CommunicationComponent::$campaignChannels).
 *
 * @property int         $id
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_site_id
 * @property string      $name
 * @property string      $status
 * @property int|null    $cms_comm_topic_id
 * @property string      $audience_type
 * @property string|null $audience JSON, see getAudienceArray()
 * @property string      $channel
 * @property string      $sender_mode
 * @property int|null    $cms_comm_account_id
 * @property string|null $subject
 * @property string|null $body_html
 * @property string      $offer_kind
 * @property string|null $offer_type
 * @property int|null    $offer_id
 * @property int         $task_on_open
 * @property int         $task_on_reply
 * @property int|null    $launched_at
 * @property int|null    $launched_by
 * @property int|null    $finished_at
 * @property string|null $prepared_by "ai" when AI created the campaign (services\AiActions)
 * @property string|null $prepared_client mcp:<OAuth client>
 * @property int|null    $prepared_user_id the CRM user AI worked for
 * @property int|null    $prepared_at
 *
 * @property CmsCommTopic|null           $topic
 * @property CmsCommAccount|null         $account
 * @property CmsCommCampaignRecipient[]  $recipients
 * @property CmsUser|null                $createdBy
 * @property CmsUser|null                $launchedBy
 */
class CmsCommCampaign extends ActiveRecord
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    /** Leads by a filter of the leads section. */
    public const AUDIENCE_LEADS = 'leads';
    /** Leads of an import of recipients (stage 4A): created and found duplicate leads. */
    public const AUDIENCE_IMPORT = 'import';
    /** Only the recipients added one by one (by hand or by AI, Campaigns::addRecipient()). */
    public const AUDIENCE_MANUAL = 'manual';

    /** The mailbox of the lead's responsible employee. */
    public const SENDER_RESPONSIBLE = 'responsible';
    /** One chosen mailbox. */
    public const SENDER_ACCOUNT = 'account';

    public const OFFER_NONE = 'none';
    /** One document for everybody (a presentation for everybody): every recipient gets their own link. */
    public const OFFER_SHARED = 'shared';
    /** Every recipient gets their own document from a template (a КП from a template), made when the letter leaves. */
    public const OFFER_EACH = 'each';

    /** Placeholders of the subject and the text. */
    public const PLACEHOLDERS = [
        '{Имя}' => 'имя контакта (если неизвестно — убирается вместе с запятой перед ним)',
        '{Компания}' => 'компания лида',
        '{Менеджер}' => 'имя менеджера, от которого письмо',
        '{Ссылка}' => 'кнопка с личной ссылкой на предложение (без неё кнопка встанет в конце)',
        '{Отписаться}' => 'ссылка отписки (без неё строка отписки встанет в конце)',
    ];

    /* Audience conditions in the form */
    public $audienceStatuses = [];
    public $audienceStages = [];
    public $audienceSources = [];
    public $audienceExecutors = [];
    public $audienceImportId;

    public static function tableName()
    {
        return '{{%cms_comm_campaign}}';
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Черновик',
            self::STATUS_RUNNING => 'Идёт',
            self::STATUS_PAUSED => 'На паузе',
            self::STATUS_DONE => 'Завершена',
            self::STATUS_CANCELLED => 'Отменена',
        ];
    }

    public static function audienceTypes(): array
    {
        return [
            self::AUDIENCE_LEADS => 'Лиды по фильтру',
            self::AUDIENCE_IMPORT => 'Лиды из импорта',
            self::AUDIENCE_MANUAL => 'Только добавленные получатели',
        ];
    }

    public static function senderModes(): array
    {
        return [
            self::SENDER_RESPONSIBLE => 'Ящик ответственного за лида',
            self::SENDER_ACCOUNT => 'Выбранный ящик',
        ];
    }

    public static function offerKinds(): array
    {
        return [
            self::OFFER_NONE => 'Без предложения',
            self::OFFER_SHARED => 'Один документ всем (например, общая презентация)',
            self::OFFER_EACH => 'Каждому своё КП из шаблона',
        ];
    }

    public function init()
    {
        parent::init();
        // Defaults of a new campaign; values given to the constructor (code, tests) stay.
        if ($this->isNewRecord) {
            $defaults = [
                'status' => self::STATUS_DRAFT,
                'audience_type' => self::AUDIENCE_LEADS,
                'channel' => MailboxCampaignChannel::ID,
                'sender_mode' => self::SENDER_RESPONSIBLE,
                'offer_kind' => self::OFFER_NONE,
                'task_on_open' => 1,
                'task_on_reply' => 1,
                'subject' => '',
                'body_html' => '<p>Здравствуйте, {Имя}!</p><p></p><p>{Ссылка}</p><p>С уважением,<br>{Менеджер}</p>',
            ];
            foreach ($defaults as $attribute => $value) {
                if ($this->hasAttribute($attribute) && $this->getAttribute($attribute) === null) {
                    $this->setAttribute($attribute, $value);
                }
            }
        }
    }

    public function afterFind()
    {
        parent::afterFind();
        $audience = $this->getAudienceArray();
        $this->audienceStatuses = (array)($audience['statuses'] ?? []);
        $this->audienceStages = (array)($audience['stages'] ?? []);
        $this->audienceSources = (array)($audience['sources'] ?? []);
        $this->audienceExecutors = (array)($audience['executors'] ?? []);
        $this->audienceImportId = $audience['import'] ?? null;
    }

    public function rules()
    {
        $list = static fn($v) => array_values(array_filter(array_map('strval', (array)$v), static fn($x) => $x !== ''));

        return ArrayHelper::merge(parent::rules(), [
            [['cms_site_id'], 'default', 'value' => static fn() => \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : null],
            [['name', 'subject'], 'filter', 'filter' => static fn($v) => trim((string)$v)],
            [['body_html'], 'filter', 'filter' => static fn($v) => RichTextHtml::clean((string)$v, ComposeForm::EDITOR_PRESET)],
            [['name', 'cms_site_id', 'audience_type', 'channel', 'sender_mode', 'offer_kind'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['subject'], 'string', 'max' => 500],
            [['body_html'], 'string', 'max' => 500000],
            [['cms_site_id', 'cms_comm_topic_id', 'cms_comm_account_id', 'offer_id'], 'integer'],
            [['task_on_open', 'task_on_reply'], 'boolean'],
            [['audience_type'], 'in', 'range' => array_keys(self::audienceTypes())],
            [['sender_mode'], 'in', 'range' => array_keys(self::senderModes())],
            [['offer_kind'], 'in', 'range' => array_keys(self::offerKinds())],
            [['channel'], 'in', 'range' => static fn() => array_keys(\Yii::$app->communication->getCampaignChannels())],
            [['offer_type'], 'string', 'max' => 64],
            [['offer_type', 'offer_id', 'cms_comm_account_id', 'cms_comm_topic_id'], 'default', 'value' => null],
            [['cms_comm_topic_id'], 'exist', 'skipOnEmpty' => true, 'targetClass' => CmsCommTopic::class, 'targetAttribute' => ['cms_comm_topic_id' => 'id', 'cms_site_id' => 'cms_site_id'],
                'message' => 'Тема не найдена.'],
            [['cms_comm_account_id'], 'required', 'when' => static fn(self $m) => $m->sender_mode === self::SENDER_ACCOUNT,
                'whenClient' => "function () { return $('#cmscommcampaign-sender_mode').val() === '".self::SENDER_ACCOUNT."'; }",
                'message' => 'Выберите ящик.'],
            // The document or template is chosen in the block of the offer kind: the error is shown at the kind.
            [['offer_kind'], function ($attribute) {
                if ($this->offer_kind !== self::OFFER_NONE && !$this->offer_id) {
                    $this->addError($attribute, $this->offer_kind === self::OFFER_EACH ? 'Выберите шаблон КП.' : 'Выберите документ для всех.');
                }
            }],
            [['audienceStatuses', 'audienceStages', 'audienceSources', 'audienceExecutors'], 'filter', 'filter' => $list, 'skipOnEmpty' => false],
            [['audienceImportId'], 'integer'],
            [['audienceImportId'], 'required', 'when' => static fn(self $m) => $m->audience_type === self::AUDIENCE_IMPORT,
                'whenClient' => "function () { return $('#cmscommcampaign-audience_type').val() === '".self::AUDIENCE_IMPORT."'; }",
                'message' => 'Выберите импорт.'],
            [['status'], 'validateEditable'],
        ]);
    }

    /** A launched campaign keeps its letter and audience: the recipients are fixed already. */
    public function validateEditable($attribute)
    {
        if (!$this->isNewRecord && $this->getOldAttribute('status') !== self::STATUS_DRAFT
            && array_intersect(array_keys($this->getDirtyAttributes()), ['audience_type', 'audience', 'subject', 'body_html', 'offer_kind',
                'offer_type', 'offer_id', 'sender_mode', 'cms_comm_account_id', 'cms_comm_topic_id', 'channel'])) {
            $this->addError($attribute, 'Запущенную кампанию нельзя изменить: получатели и письмо уже зафиксированы.');
        }
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'id' => '№',
            'name' => 'Название',
            'status' => 'Состояние',
            'cms_comm_topic_id' => 'Тема рассылки',
            'audience_type' => 'Аудитория',
            'channel' => 'Канал',
            'sender_mode' => 'Отправитель',
            'cms_comm_account_id' => 'Ящик',
            'subject' => 'Тема письма',
            'body_html' => 'Текст письма',
            'offer_kind' => 'Предложение',
            'offer_id' => 'Документ или шаблон',
            'task_on_open' => 'Задача менеджеру, когда клиент открыл предложение или перешёл на сайт',
            'task_on_reply' => 'Задача менеджеру, когда клиент ответил',
            'launched_at' => 'Запущена',
            'launched_by' => 'Запустил',
            'finished_at' => 'Завершена',
            'audienceStatuses' => 'Статусы лидов',
            'audienceStages' => 'Этапы',
            'audienceSources' => 'Источники',
            'audienceExecutors' => 'Ответственные',
            'audienceImportId' => 'Импорт',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'cms_comm_topic_id' => 'Клиент может отписаться от темы; отписанные от неё и от всех рассылок не получат письмо.',
            'sender_mode' => 'Письмо уходит как обычное личное письмо из почтового ящика: ответ приходит туда же и попадает в ленту лида.',
            'audienceStatuses' => 'Пусто — новые и в работе.',
        ]);
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        if ($this->audience_type === self::AUDIENCE_IMPORT) {
            $audience = ['import' => (int)$this->audienceImportId];
        } else {
            $strings = static fn($v) => array_values(array_filter(array_map('strval', (array)$v), static fn($x) => $x !== ''));
            $ids = static fn($v) => array_values(array_filter(array_map('intval', (array)$v)));
            $audience = array_filter([
                'statuses' => $strings($this->audienceStatuses),
                'stages' => $ids($this->audienceStages),
                'sources' => $strings($this->audienceSources),
                'executors' => $ids($this->audienceExecutors),
            ]);
        }
        $this->audience = $audience ? Json::encode($audience) : null;
        if ($this->offer_kind === self::OFFER_NONE) {
            $this->offer_type = null;
            $this->offer_id = null;
        } elseif (!$this->offer_type) {
            $this->offer_type = Campaigns::defaultOfferType();
        }
        if ($this->sender_mode !== self::SENDER_ACCOUNT) {
            $this->cms_comm_account_id = null;
        }

        return true;
    }

    /** @return array statuses, stages, sources, executors (leads) or import (an import id) */
    public function getAudienceArray(): array
    {
        $value = $this->audience ? Json::decode((string)$this->audience) : [];

        return is_array($value) ? $value : [];
    }

    public function getTopic()
    {
        return $this->hasOne(CmsCommTopic::class, ['id' => 'cms_comm_topic_id']);
    }

    public function getAccount()
    {
        return $this->hasOne(CmsCommAccount::class, ['id' => 'cms_comm_account_id']);
    }

    public function getRecipients()
    {
        return $this->hasMany(CmsCommCampaignRecipient::class, ['cms_comm_campaign_id' => 'id']);
    }

    public function getCreatedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'created_by']);
    }

    public function getLaunchedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'launched_by']);
    }

    public function getStatusName(): string
    {
        return self::statuses()[$this->status] ?? (string)$this->status;
    }

    public function getIsDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Running or paused: has letters to send. */
    public function getIsActive(): bool
    {
        return in_array($this->status, [self::STATUS_RUNNING, self::STATUS_PAUSED], true);
    }

    /** "type:id" of the offer document or template, or null. */
    public function getOfferRef(): ?string
    {
        return $this->offer_kind !== self::OFFER_NONE && $this->offer_type && $this->offer_id ? $this->offer_type.':'.(int)$this->offer_id : null;
    }

    public function asText()
    {
        return 'Кампания №'.(int)$this->id.($this->name ? ' «'.$this->name.'»' : '');
    }

    /** @return CmsActiveQuery */
    public static function find()
    {
        return new CmsActiveQuery(static::class);
    }
}
