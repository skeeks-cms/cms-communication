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
 * @property int         $lead_on_view П2д: a deal when a recipient views the presentation or offer (CampaignTasks::onView())
 * @property int         $lead_on_visit П2д: a deal when a recipient comes to the site by a link of the letter
 * @property string      $new_card_owner the responsible of new companies of the flow: OWNER_SENDER | OWNER_WORKER (OWNER_NONE — only campaigns before п. 13а, migrated)
 * @property int|null    $new_card_worker_id
 * @property string      $lead_to who gets a deal: LEAD_TO_UNSORTED | OWNER_SENDER | OWNER_WORKER
 * @property int|null    $lead_worker_id
 * @property int|null    $lead_pipeline_id the pipeline of its deals; null — «Исходящие продажи» (core OutboundSales)
 * @property int         $task_on_reply
 * @property int         $one_per_company one letter per company: the company and its people get one letter
 * @property int|null    $quiet_days "не мешать менеджеру": no letter to whom we talked to in so many days; null or 0 — off
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
    /** Members of lists of recipients (stage 4D, services\RecipientLists). */
    public const AUDIENCE_LISTS = 'lists';

    /** "Не мешать менеджеру" of a new campaign, days. */
    public const QUIET_DAYS_DEFAULT = 14;

    /** The mailbox of the lead's responsible employee. */
    public const SENDER_RESPONSIBLE = 'responsible';
    /** One chosen mailbox. */
    public const SENDER_ACCOUNT = 'account';

    /** The flow (П2д): who gets new companies and deals; the same strings as core CrmSettings. */
    public const OWNER_NONE = 'none';
    public const OWNER_SENDER = 'sender';
    public const OWNER_WORKER = 'worker';
    public const LEAD_TO_UNSORTED = 'unsorted';

    public const OFFER_NONE = 'none';
    /** One document for everybody (a presentation for everybody): every recipient gets their own link. */
    public const OFFER_SHARED = 'shared';
    /** Every recipient gets their own document from a template (a КП from a template), made when the letter leaves. */
    public const OFFER_EACH = 'each';

    /** Placeholders of the subject and the text. */
    public const PLACEHOLDERS = [
        '{Имя}' => 'имя контакта (если неизвестно — убирается вместе с запятой перед ним)',
        '{Компания}' => 'компания получателя',
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
    public $audienceLists = [];
    /** Lists whose members (and the people and leads of companies in them) get no letter; for every audience. */
    public $audienceExcludeLists = [];

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
            self::AUDIENCE_LISTS => 'Списки рассылки',
            self::AUDIENCE_LEADS => 'Сделки по фильтру',
            self::AUDIENCE_IMPORT => 'Сделки из импорта',
            self::AUDIENCE_MANUAL => 'Только добавленные получатели',
        ];
    }

    public static function senderModes(): array
    {
        return [
            self::SENDER_RESPONSIBLE => 'Ящик ответственного за получателя',
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
                // The new model (П5): an audience is a list of companies and contacts, not deals.
                'audience_type' => self::AUDIENCE_LISTS,
                'channel' => MailboxCampaignChannel::ID,
                'sender_mode' => self::SENDER_RESPONSIBLE,
                'offer_kind' => self::OFFER_NONE,
                // The flow (П2д): «Исходящие продажи» of the CRM settings, the campaign may change them.
                'task_on_open' => (int)(bool)self::crmDefault('outgoingTaskOnView', 1),
                'lead_on_view' => (int)(bool)self::crmDefault('outgoingLeadOnView', 1),
                'lead_on_visit' => (int)(bool)self::crmDefault('outgoingLeadOnVisit', 0),
                'new_card_owner' => (string)self::crmDefault('outgoingNewCardOwner', self::OWNER_SENDER),
                'new_card_worker_id' => self::crmDefault('outgoingNewCardWorkerId', null),
                'lead_to' => (string)self::crmDefault('outgoingLeadTo', self::OWNER_SENDER),
                'lead_worker_id' => self::crmDefault('outgoingLeadWorkerId', null),
                'lead_pipeline_id' => self::crmDefault('outboundPipelineId', null),
                'task_on_reply' => 1,
                'one_per_company' => 1,
                'quiet_days' => self::QUIET_DAYS_DEFAULT,
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

    /** A default of a new campaign from the core CRM settings; an older core without the setting — $fallback. */
    private static function crmDefault(string $property, $fallback)
    {
        $app = \Yii::$app;
        if ($app && $app->has('crmSettings') && property_exists($app->crmSettings, $property)) {
            $value = $app->crmSettings->{$property};

            return $value === '' ? null : $value;
        }

        return $fallback;
    }

    /** "Ответственный за новые компании" of the flow: the sender or an employee, never none (п. 13а; the strings of core CrmSettings::OWNER_*). */
    public static function newCardOwners(): array
    {
        return [self::OWNER_SENDER => 'Отправитель', self::OWNER_WORKER => 'Сотрудник'];
    }

    /** "Куда сделка". */
    public static function leadReceivers(): array
    {
        return [self::LEAD_TO_UNSORTED => '«Неразобранное»', self::OWNER_SENDER => 'Отправитель', self::OWNER_WORKER => 'Сотрудник'];
    }

    /** The flow columns are migrated (m261109_110000). */
    public function hasFlow(): bool
    {
        return $this->hasAttribute('lead_to');
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
        $this->audienceLists = (array)($audience['lists'] ?? []);
        $this->audienceExcludeLists = (array)($audience['exclude_lists'] ?? []);
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
            [['task_on_open', 'task_on_reply', 'one_per_company', 'lead_on_view', 'lead_on_visit'], 'boolean'],
            [['new_card_owner'], 'in', 'range' => array_keys(self::newCardOwners())],
            [['lead_to'], 'in', 'range' => array_keys(self::leadReceivers())],
            [['new_card_worker_id', 'lead_worker_id', 'lead_pipeline_id'], 'default', 'value' => null],
            [['new_card_worker_id', 'lead_worker_id', 'lead_pipeline_id'], 'integer'],
            [['new_card_worker_id'], 'required', 'when' => static fn(self $m) => $m->hasFlow() && $m->new_card_owner === self::OWNER_WORKER,
                'whenClient' => "function () { return $('#cmscommcampaign-new_card_owner').val() === '".self::OWNER_WORKER."'; }"],
            [['lead_worker_id'], 'required', 'when' => static fn(self $m) => $m->hasFlow() && $m->lead_to === self::OWNER_WORKER,
                'whenClient' => "function () { return $('#cmscommcampaign-lead_to').val() === '".self::OWNER_WORKER."'; }"],
            [['quiet_days'], 'integer', 'min' => 0, 'max' => 365],
            [['quiet_days'], 'default', 'value' => 0],
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
            [['audienceLists', 'audienceExcludeLists'], 'filter', 'filter' => static fn($v) => array_values(array_unique(array_filter(array_map('intval', (array)$v)))),
                'skipOnEmpty' => false],
            [['audienceLists'], 'required', 'when' => static fn(self $m) => $m->audience_type === self::AUDIENCE_LISTS,
                'whenClient' => "function () { return $('#cmscommcampaign-audience_type').val() === '".self::AUDIENCE_LISTS."'; }",
                'message' => 'Выберите один или несколько списков.'],
            [['audienceLists', 'audienceExcludeLists'], function ($attribute) {
                $ids = (array)$this->$attribute;
                if ($ids && (int)CmsCommList::find()->andWhere(['id' => $ids, 'cms_site_id' => (int)$this->cms_site_id])->count() !== count($ids)) {
                    $this->addError($attribute, 'Список не найден.');
                }
                if ($attribute === 'audienceExcludeLists' && $this->audience_type === self::AUDIENCE_LISTS && array_intersect($ids, (array)$this->audienceLists)) {
                    $this->addError($attribute, 'Один и тот же список не может быть и в аудитории, и в исключениях.');
                }
            }],
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
                'offer_type', 'offer_id', 'sender_mode', 'cms_comm_account_id', 'cms_comm_topic_id', 'channel', 'one_per_company', 'quiet_days'])) {
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
            'task_on_open' => 'Задача «Позвонить: смотрел Презентацию», когда просмотр или переход по ссылке не создаёт сделку',
            'task_on_reply' => 'Ответ клиента — в сделку: задача в открытой сделке или новая сделка',
            'lead_on_view' => 'Сделка при просмотре Презентации или КП',
            'lead_on_visit' => 'Сделка при переходе по ссылке из письма',
            'new_card_owner' => 'Ответственный за новые компании',
            'new_card_worker_id' => 'Сотрудник для новых компаний',
            'lead_to' => 'Куда сделка',
            'lead_worker_id' => 'Сотрудник для сделок',
            'lead_pipeline_id' => 'Воронка сделок',
            'launched_at' => 'Запущена',
            'launched_by' => 'Запустил',
            'finished_at' => 'Завершена',
            'audienceStatuses' => 'Статусы сделок',
            'audienceStages' => 'Этапы',
            'audienceSources' => 'Источники',
            'audienceExecutors' => 'Ответственные',
            'audienceImportId' => 'Импорт',
            'audienceLists' => 'Списки',
            'audienceExcludeLists' => 'Исключить списки',
            'one_per_company' => 'Одна компания — одно письмо',
            'quiet_days' => 'Не мешать менеджеру: не писать тем, с кем общались за последние, дней',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'cms_comm_topic_id' => 'Клиент может отписаться от темы; отписанные от неё и от всех рассылок не получат письмо.',
            'sender_mode' => 'Письмо уходит как обычное личное письмо из почтового ящика: ответ приходит туда же и попадает в ленту компании или контакта.',
            'task_on_reply' => 'Ответ или заявка из письма (вопрос, «Принять» на странице КП): если у клиента есть открытая сделка — задача в ней; иначе новая сделка — по настройке «Куда сделка».',
            'lead_on_view' => 'Ответ клиента создаёт сделку всегда. Отметьте, если и просмотр — повод вести сделку; иначе по просмотру ставится задача «Позвонить» (если она включена). Открытие письма сделку не создаёт.',
            'new_card_owner' => 'Для компаний, которых ещё нет в CRM (их создаёт бот этой кампании). Существующие компании не меняются. Отправитель — тот, под чьей авторизацией бот создаёт компанию.',
            'lead_to' => 'Отправитель — тот, от чьего имени ушло письмо. Если у компании нет основного ответственного, он им становится. Открытая сделка клиента дополняется, новая не создаётся.',
            'lead_pipeline_id' => 'Пусто — «Исходящие продажи». Ответ ставит этап «Ответил», просмотр — «Смотрел».',
            'audienceStatuses' => 'Пусто — новые и в работе.',
            'audienceExcludeLists' => 'Например, «Действующие клиенты». Если в исключающем списке компания, письмо не получат и её люди, и её сделки.',
            'one_per_company' => 'Если в аудитории компания и её люди или сделки, письмо получит один контакт: добавленный по одному со своим адресом, '
                .'иначе сама компания (её первый email), иначе первый из её людей и сделок.',
            'quiet_days' => 'Письма и сообщения в ленте за этот срок (кроме писем рассылок): с такими клиентами менеджер уже общается. 0 — не проверять.',
        ]);
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $ids = static fn($v) => array_values(array_unique(array_filter(array_map('intval', (array)$v))));
        if ($this->audience_type === self::AUDIENCE_IMPORT) {
            $audience = ['import' => (int)$this->audienceImportId];
        } elseif ($this->audience_type === self::AUDIENCE_LISTS) {
            $audience = ['lists' => $ids($this->audienceLists)];
        } elseif ($this->audience_type === self::AUDIENCE_MANUAL) {
            $audience = [];
        } else {
            $strings = static fn($v) => array_values(array_filter(array_map('strval', (array)$v), static fn($x) => $x !== ''));
            $audience = array_filter([
                'statuses' => $strings($this->audienceStatuses),
                'stages' => $ids($this->audienceStages),
                'sources' => $strings($this->audienceSources),
                'executors' => $ids($this->audienceExecutors),
            ]);
        }
        if ($exclude = $ids($this->audienceExcludeLists)) {
            $audience['exclude_lists'] = $exclude;
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

    /** @return array statuses, stages, sources, executors (leads), import (an import id) or lists; exclude_lists for any audience */
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
