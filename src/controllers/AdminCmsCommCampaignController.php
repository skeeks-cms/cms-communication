<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\actions\BackendModelAction;
use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\backend\ViewBackendAction;
use skeeks\cms\base\Component;
use skeeks\cms\communication\assets\MailingAsset;
use skeeks\cms\communication\components\MailingSettings;
use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\helpers\RequestResponse;
use skeeks\cms\rbac\CmsManager;
use skeeks\yii2\form\fields\NumberField;
use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MailboxPace;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\grid\DateTimeColumnData;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadStage;
use skeeks\cms\models\CmsUser;
use skeeks\cms\widgets\formInputs\richText\RichTextInputWidget;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\FieldSet;
use skeeks\yii2\form\fields\HtmlBlock;
use skeeks\yii2\form\fields\SelectField;
use skeeks\yii2\form\fields\WidgetField;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * "Рассылки": mailing campaigns (services\Campaigns). A draft is edited in the
 * standard form; its card shows the summary of recipients before launch
 * (who is excluded and why), the preview of the letter on a chosen lead, a
 * test letter to oneself and "Запустить"; a launched campaign — the
 * progress, pause, resume, cancel and the report by recipients.
 *
 * Creating and launching campaigns is a right of its own
 * (cmsCommunication/admin-cms-comm-campaign, given to administrators by
 * default). The audience is what the employee sees in the CRM.
 */
class AdminCmsCommCampaignController extends BackendModelStandartController
{
    public const PERMISSION = 'cmsCommunication/admin-cms-comm-campaign';

    public function init()
    {
        $this->name = 'Рассылки';
        $this->modelShowAttribute = 'asText';
        $this->modelClassName = CmsCommCampaign::class;
        $this->modelDefaultAction = 'view';
        $this->generateAccessActions = false;
        $this->permissionName = self::PERMISSION;

        parent::init();
    }

    public function actions()
    {
        $isDraft = fn() => $this->model && $this->model->isDraft;

        return ArrayHelper::merge(parent::actions(), [
            'index' => [
                'name' => 'Кампании',
                'emptyState' => [
                    'title' => 'Кампаний пока нет',
                    'description' => 'Кампания — письмо по теме рассылки группе получателей: из ящиков менеджеров, с паузами и только в рабочие часы, '
                        .'с презентацией или КП каждому. Перед запуском видно, кто получит письмо и кто исключён.',
                    'icon' => 'far fa-paper-plane',
                    'action' => ['backendAction' => 'create', 'label' => 'Создать кампанию'],
                ],
                'filters' => false,
                'grid' => [
                    'on init' => static function (Event $event) {
                        $event->sender->dataProvider->query->andWhere(['cms_site_id' => (int)\Yii::$app->skeeks->site->id]);
                    },
                    'defaultOrder' => ['id' => SORT_DESC],
                    'visibleColumns' => ['checkbox', 'actions', 'id', 'name', 'status', 'cms_comm_topic_id', 'progress', 'launched_at'],
                    'columns' => [
                        'name' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsCommunication/admin-cms-comm-campaign',
                        ],
                        'status' => ['value' => static fn(CmsCommCampaign $c) => $c->getStatusName()],
                        'cms_comm_topic_id' => ['value' => static fn(CmsCommCampaign $c) => $c->topic ? $c->topic->name : '—'],
                        'progress' => [
                            'label' => 'Отправлено · открыли · ответили',
                            'format' => 'raw',
                            'value' => static function (CmsCommCampaign $c) {
                                if ($c->isDraft) {
                                    return '<span style="color: var(--sx-color-text-muted, #6f7484)">не запущена</span>';
                                }
                                $s = Campaigns::stats($c);

                                return Html::encode($s['sent'].' из '.($s['total'] - $s['excluded']).' · '.$s['opened'].' · '.$s['replied']);
                            },
                        ],
                        'launched_at' => ['class' => DateTimeColumnData::class],
                    ],
                ],
            ],
            // Lists of recipients (stage 4D): the standard list of AdminCmsCommListController in a tab of this section.
            'lists' => [
                'class' => ViewBackendAction::class,
                'name' => 'Списки',
                'icon' => 'fas fa-list-ul',
                'priority' => 20,
                'callback' => [AdminCmsCommListController::class, 'renderCampaignsTab'],
            ],
            // Rules of every mailing of the site; the administrators' tab.
            'settings' => [
                'class' => ViewBackendAction::class,
                'name' => 'Настройки',
                'icon' => 'fas fa-sliders-h',
                'priority' => 30,
                'accessCallback' => static fn() => \Yii::$app->user->can(CmsManager::PERMISSION_ROLE_ADMIN_ACCESS),
                'callback' => [$this, 'settings'],
            ],
            'create' => [
                'fields' => [$this, 'updateFields'],
                'on beforeValidate' => [$this, 'beforeValidateCampaign'],
            ],
            'update' => [
                'fields' => [$this, 'updateFields'],
                'name' => 'Письмо и аудитория',
                'accessCallback' => $isDraft,
                'on beforeValidate' => [$this, 'beforeValidateCampaign'],
            ],
            'view' => [
                'class' => BackendModelAction::class,
                'name' => 'Кампания',
                'icon' => 'far fa-paper-plane',
                'priority' => 10,
                'callback' => [$this, 'view'],
            ],
            'recipient' => [
                'class' => BackendModelAction::class,
                'name' => 'Получатель',
                'isVisible' => false,
                'callback' => [$this, 'recipient'],
            ],
            'delete' => ['accessCallback' => $isDraft],
            'delete-multi' => ['isVisible' => false, 'accessCallback' => static fn() => false],
        ]);
    }

    /** The offer of the form: the document for everybody or the template, by the chosen kind. */
    public function beforeValidateCampaign($event)
    {
        /** @var CmsCommCampaign $model */
        $model = $event->sender->model;
        $post = (array)\Yii::$app->request->post('offer', []);
        if ($model->offer_kind === CmsCommCampaign::OFFER_SHARED) {
            $model->offer_id = (int)($post['shared'] ?? 0) ?: null;
        } elseif ($model->offer_kind === CmsCommCampaign::OFFER_EACH) {
            $model->offer_id = (int)($post['template'] ?? 0) ?: null;
        }
        if (!$model->channel) {
            $model->channel = 'mailbox';
        }
    }

    public function updateFields($action)
    {
        /** @var CmsCommCampaign $model */
        $model = $action->model;
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $user = \Yii::$app->user->identity;
        $campaigns = new Campaigns();
        $source = Campaigns::defaultOfferType() ? Campaigns::offerSources()[Campaigns::defaultOfferType()] : null;
        $shared = $source ? $source->sharedItems($user, $siteId) : [];
        $templates = $source ? $source->templates($siteId) : [];
        $accounts = ArrayHelper::map((new MailComposeService($user, $siteId))->sendableAccounts(), 'id', static fn($a) => $a->asText());
        $lists = CmsCommList::forSite($siteId);
        $frequency = MailingSettings::frequencyDays($siteId);
        $executors = [];
        foreach (CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.is_active' => 1])->all() as $worker) {
            $executors[(int)$worker->id] = (string)$worker->shortDisplayName;
        }
        asort($executors);
        $placeholders = '';
        foreach (CmsCommCampaign::PLACEHOLDERS as $key => $text) {
            $placeholders .= Html::tag('li', Html::tag('code', Html::encode($key)).' — '.Html::encode($text));
        }
        $channels = array_map(static fn($c) => $c->title(), \Yii::$app->communication->getCampaignChannels());
        $channelHints = '';
        foreach (\Yii::$app->communication->getCampaignChannels() as $channel) {
            $channelHints .= Html::tag('p', Html::encode($channel->hint()), ['class' => 'form-group sx-campaign-form__hint']);
        }
        $offerSelects = Html::tag('div',
            Html::tag('label', 'Документ для всех', ['class' => 'control-label'])
            .Html::dropDownList('offer[shared]', $model->offer_kind === CmsCommCampaign::OFFER_SHARED ? $model->offer_id : null, $shared,
                ['class' => 'form-control', 'prompt' => $shared ? '— выберите —' : 'Нет общих презентаций: создайте её в «Предложениях»'])
            .Html::tag('div', 'Каждый получатель получит свою личную ссылку: в ленте сделки видно, открыл ли он документ.', ['class' => 'hint-block']),
            ['class' => 'form-group', 'data-sx-campaign-offer' => CmsCommCampaign::OFFER_SHARED])
            .Html::tag('div',
                Html::tag('label', 'Шаблон КП', ['class' => 'control-label'])
                .Html::dropDownList('offer[template]', $model->offer_kind === CmsCommCampaign::OFFER_EACH ? $model->offer_id : null, $templates,
                    ['class' => 'form-control', 'prompt' => $templates ? '— выберите —' : 'Нет шаблонов КП']).
                Html::tag('div', 'КП каждому получателю создаётся из шаблона в момент отправки его письма; ответственный — отправитель письма.', ['class' => 'hint-block']),
                ['class' => 'form-group', 'data-sx-campaign-offer' => CmsCommCampaign::OFFER_EACH]);
        // Fields of the other audience or offer kind stay hidden.
        MailingAsset::register(\Yii::$app->view);
        \Yii::$app->view->registerJs(<<<JS
(function () {
    function toggle() {
        var audience = $('#cmscommcampaign-audience_type').val();
        $('.field-cmscommcampaign-audiencestatuses, .field-cmscommcampaign-audiencestages, .field-cmscommcampaign-audiencesources, .field-cmscommcampaign-audienceexecutors').toggle(audience === 'leads');
        $('.field-cmscommcampaign-audienceimportid').toggle(audience === 'import');
        $('.field-cmscommcampaign-audiencelists').toggle(audience === 'lists');
        var offer = $('#cmscommcampaign-offer_kind').val();
        $('[data-sx-campaign-offer]').each(function () { $(this).toggle($(this).data('sx-campaign-offer') === offer); });
        $('.field-cmscommcampaign-cms_comm_account_id').toggle($('#cmscommcampaign-sender_mode').val() === 'account');
    }
    $(document).on('change', '#cmscommcampaign-audience_type, #cmscommcampaign-offer_kind, #cmscommcampaign-sender_mode', toggle);
    $(toggle);
    setTimeout(toggle, 50);
})();
JS);
        $toggle = '';

        return [
            'main' => [
                'class' => FieldSet::class,
                'name' => 'Кампания',
                'fields' => [
                    'name',
                    'cms_comm_topic_id' => [
                        'class' => SelectField::class,
                        'items' => ArrayHelper::map(CmsCommTopic::forSite($siteId, true), 'id', 'name'),
                        'allowNull' => true,
                        'nullLabel' => '— выберите тему —',
                    ],
                ],
            ],
            'audience' => [
                'class' => FieldSet::class,
                'name' => 'Кому',
                'fields' => [
                    'audience_type' => ['class' => SelectField::class, 'items' => CmsCommCampaign::audienceTypes(), 'allowNull' => false,
                        'hint' => 'Получатели, добавленные по одному (в карточке кампании или ИИ), со своим личным письмом, входят в любую аудиторию.'],
                    'audienceStatuses' => ['class' => SelectField::class, 'multiple' => true, 'allowNull' => false, 'items' => CmsLead::statuses(),
                        'elementOptions' => ['size' => 4]],
                    'audienceStages' => ['class' => SelectField::class, 'multiple' => true, 'allowNull' => false,
                        'items' => ArrayHelper::map(CmsLeadStage::find()->andWhere(['cms_site_id' => $siteId])->orderBy(['sort' => SORT_ASC])->all(), 'id', 'name'),
                        'elementOptions' => ['size' => 5]],
                    'audienceSources' => ['class' => SelectField::class, 'multiple' => true, 'allowNull' => false, 'items' => CmsLead::sources(),
                        'elementOptions' => ['size' => 6]],
                    'audienceExecutors' => ['class' => SelectField::class, 'multiple' => true, 'allowNull' => false, 'items' => $executors,
                        'elementOptions' => ['size' => 6]],
                    'audienceImportId' => ['class' => SelectField::class, 'items' => $campaigns->imports($user, $siteId), 'allowNull' => true,
                        'nullLabel' => '— выберите импорт —'],
                    'audienceLists' => ['class' => SelectField::class, 'multiple' => true, 'allowNull' => false, 'items' => $lists,
                        'hint' => $lists ? 'Участники всех выбранных списков, каждый один раз.' : 'Списков пока нет: создайте их на вкладке «Списки».'],
                    'audienceExcludeLists' => ['class' => SelectField::class, 'multiple' => true, 'allowNull' => false, 'items' => $lists],
                    'hint' => ['class' => HtmlBlock::class, 'content' => Html::tag('p',
                        'Получатели — только записи, которые вы видите. Каждой записи — одно письмо на её первый email (у компании — её email, иначе '
                        .'email её людей). Точный список и исключённых с причиной покажет карточка кампании перед запуском.', ['class' => 'form-group sx-campaign-form__hint'])],
                ],
            ],
            'protect' => [
                'class' => FieldSet::class,
                'name' => 'Бережная рассылка',
                'fields' => [
                    'one_per_company' => ['class' => BoolField::class, 'allowNull' => false],
                    'quiet_days' => ['class' => NumberField::class],
                    'frequency' => ['class' => HtmlBlock::class, 'content' => Html::tag('p', Html::encode($frequency > 0
                        ? 'Для всех кампаний сайта: одному контакту — не больше одной рассылки за '.$frequency.' дн. (вкладка «Настройки»).'
                        : 'Частота писем одному контакту не ограничена (вкладка «Настройки»).'), ['class' => 'form-group sx-campaign-form__hint'])],
                ],
            ],
            'letter' => [
                'class' => FieldSet::class,
                'name' => 'Письмо',
                'fields' => [
                    'subject',
                    'body_html' => [
                        'class' => WidgetField::class,
                        'widgetClass' => RichTextInputWidget::class,
                        'widgetConfig' => [
                            'preset' => ComposeForm::EDITOR_PRESET,
                            // No pictures: a campaign letter keeps no uploads.
                            'tools' => array_values(array_diff(ComposeForm::EDITOR_TOOLS, ['image'])),
                            'minHeight' => 220,
                        ],
                    ],
                    'placeholders' => ['class' => HtmlBlock::class, 'content' => Html::tag('div',
                        Html::tag('strong', 'Подстановки').Html::tag('ul', $placeholders), ['class' => 'form-group sx-campaign-form__hint'])],
                ],
            ],
            'offer' => [
                'class' => FieldSet::class,
                'name' => 'Предложение',
                'fields' => [
                    'offer_kind' => ['class' => SelectField::class, 'items' => CmsCommCampaign::offerKinds(), 'allowNull' => false],
                    'offerSelects' => ['class' => HtmlBlock::class, 'content' => $offerSelects.$toggle],
                ],
            ],
            'sender' => [
                'class' => FieldSet::class,
                'name' => 'Отправитель',
                'fields' => array_filter([
                    'channel' => count($channels) > 1 ? ['class' => SelectField::class, 'items' => $channels, 'allowNull' => false] : null,
                    'sender_mode' => ['class' => SelectField::class, 'items' => CmsCommCampaign::senderModes(), 'allowNull' => false],
                    'cms_comm_account_id' => ['class' => SelectField::class, 'items' => $accounts, 'allowNull' => true, 'nullLabel' => '— выберите ящик —'],
                    'channelHint' => ['class' => HtmlBlock::class, 'content' => $channelHints],
                    'task_on_open' => ['class' => BoolField::class, 'allowNull' => false],
                    'task_on_reply' => ['class' => BoolField::class, 'allowNull' => false],
                ]),
            ],
        ];
    }

    /** "Настройки": rules of every mailing of the site, at the level the administration edits (component-settings.md). */
    public function settings()
    {
        $site = \Yii::$app->skeeks->site;
        $component = new MailingSettings();
        if ($site->is_default) {
            $component->overridePath = [Component::OVERRIDE_DEFAULT];
        } else {
            $component->overridePath = [Component::OVERRIDE_DEFAULT, Component::OVERRIDE_SITE];
            $component->cmsSite = $site;
        }
        $component->refresh();

        $request = \Yii::$app->request;
        if ($request->isPost && !$request->post(RequestResponse::DYNAMIC_RELOAD_NOT_SUBMIT)) {
            if ($component->load($request->post()) && $component->validate()) {
                if ($site->is_default) {
                    $component->override = Component::OVERRIDE_DEFAULT;
                } else {
                    $component->override = Component::OVERRIDE_SITE;
                    $component->cmsSite = $site;
                }
                $saved = $component->save();
                MailingSettings::reset();
                \Yii::$app->session->setFlash($saved ? 'success' : 'error', $saved ? 'Сохранено' : 'Не удалось сохранить');
            } else {
                \Yii::$app->session->setFlash('error', implode(' ', $component->getFirstErrors()) ?: 'Не удалось сохранить');
            }
        }

        return $this->render('@skeeks/cms/communication/views/campaign/settings', ['component' => $component]);
    }

    /** The card: the summary, preview and launch of a draft; progress and the report of a launched campaign. */
    public function view()
    {
        /** @var CmsCommCampaign $campaign */
        $campaign = $this->model;
        $request = \Yii::$app->request;
        $user = \Yii::$app->user->identity;
        $service = new Campaigns();
        $session = \Yii::$app->session;

        if ($request->isPost) {
            $do = (string)$request->post('do');
            try {
                if ($do === 'launch') {
                    $count = $service->launch($campaign, $user);
                    $session->setFlash('success', 'Кампания запущена: писем к отправке — '.$count.'. Они уйдут постепенно, по скорости ящиков.');
                } elseif ($do === 'pause') {
                    $service->pause($campaign);
                } elseif ($do === 'resume') {
                    $service->resume($campaign);
                } elseif ($do === 'cancel') {
                    $service->cancel($campaign);
                    $session->setFlash('success', 'Кампания отменена: неотправленные письма не уйдут.');
                } elseif ($do === 'test') {
                    $sample = $this->sample($campaign, $service, (string)$request->post('lead'));
                    if (!$sample) {
                        throw new \DomainException('Нет получателя для тестового письма.');
                    }
                    $message = $service->sendTest($campaign, $sample, $user);
                    $session->setFlash('success', 'Тестовое письмо отправлено на '.implode(', ', array_keys($message->getTo())).'.');
                }
            } catch (\DomainException $e) {
                $session->setFlash('error', $e->getMessage());
            }

            return $this->redirect(Url::current(['lead' => $request->post('lead') ?: $request->get('lead')]));
        }

        $summary = null;
        $summaryError = null;
        $stats = null;
        $report = [];
        $reportTotal = 0;
        $filter = (string)$request->get('state', '');
        $page = max(1, (int)$request->get('page', 1));
        if ($campaign->isDraft) {
            try {
                // Lists with rules: recount what was not counted in the last quarter of an hour.
                $service->refreshLists($campaign, 900);
                $summary = $service->summary($campaign, $user);
            } catch (\DomainException $e) {
                $summaryError = $e->getMessage();
            }
        } else {
            $service->refresh($campaign);
            $stats = Campaigns::stats($campaign);
            $query = CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id])->orderBy(['id' => SORT_ASC]);
            $states = self::reportFilters();
            if (isset($states[$filter])) {
                $query->andWhere($states[$filter]['where']);
            }
            $reportTotal = (int)(clone $query)->count();
            $report = $query->offset(($page - 1) * 100)->limit(100)->with(['account', 'senderUser'])->all();
        }

        $sample = $this->sample($campaign, $service, (string)$request->get('lead'), $summary);
        $preview = null;
        $previewError = null;
        if ($sample) {
            try {
                $preview = $service->preview($campaign, $sample, $user);
            } catch (\Throwable $e) {
                $previewError = $e->getMessage();
            }
        }

        MailingAsset::register($this->view);

        return $this->render('@skeeks/cms/communication/views/campaign/view', [
            'campaign' => $campaign,
            'summary' => $summary,
            'summaryError' => $summaryError,
            'launchErrors' => $campaign->isDraft ? $service->launchErrors($campaign, $user) : [],
            'stats' => $stats,
            'report' => $report,
            'reportTotal' => $reportTotal,
            'filter' => $filter,
            'page' => $page,
            'sample' => $sample,
            'preview' => $preview,
            'previewError' => $previewError,
            'samples' => $this->samples($campaign, $summary),
        ]);
    }

    /**
     * A recipient: without `record` — the choice of a lead, company or client
     * to add; with `record=lead:12` — its address, name and personal letter.
     */
    public function recipient()
    {
        /** @var CmsCommCampaign $campaign */
        $campaign = $this->model;
        $request = \Yii::$app->request;
        $user = \Yii::$app->user->identity;
        $service = new Campaigns();
        $recordRef = (string)$request->get('record', '');
        $parsed = \skeeks\cms\communication\services\SendService::parseRef($recordRef);
        $record = $parsed && in_array($parsed[0], Campaigns::ADDABLE, true) ? CrmRecords::find($parsed[0], $parsed[1], $user) : null;
        $error = null;
        if ($recordRef !== '' && !$record) {
            $error = 'Запись CRM не найдена или вам не видна.';
        }
        $recipient = $record ? CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id,
            'record_type' => $parsed[0], 'record_id' => $parsed[1]])->one() : null;
        $canEdit = $record && ($campaign->isDraft || ($recipient && $recipient->status === CmsCommCampaignRecipient::STATUS_PENDING && $campaign->isActive));
        $addresses = $record ? Campaigns::recordAddresses($record) : [];
        $values = [
            'address' => $recipient ? (string)$recipient->address : (string)(array_key_first($addresses) ?? ''),
            'name' => $recipient ? (string)$recipient->name : (string)($addresses[array_key_first($addresses) ?? ''] ?? ''),
            'subject' => $recipient ? (string)$recipient->subject : '',
            'body_html' => $recipient && trim((string)$recipient->body_html) !== '' ? (string)$recipient->body_html : (string)$campaign->body_html,
        ];

        if ($canEdit && $request->isPost) {
            $do = (string)$request->post('do');
            $posted = [
                'address' => (string)$request->post('address', $values['address']),
                'name' => (string)$request->post('name', $values['name']),
                'subject' => (string)$request->post('subject', ''),
                'body_html' => (string)$request->post('body_html', ''),
            ];
            if ($do === 'template') {
                $posted['subject'] = '';
                $posted['body_html'] = '';
            }
            try {
                if ($do === 'remove') {
                    if ($recipient) {
                        $service->removeRecipient($recipient);
                    }
                    \Yii::$app->session->setFlash('success', 'Получатель убран из кампании.');

                    return $this->redirect(['view', 'pk' => (int)$campaign->id]);
                }
                // The template itself saved as a personal letter is no personal letter.
                if (trim($posted['subject']) === '' && trim((string)$posted['body_html']) === trim((string)$campaign->body_html)) {
                    $posted['body_html'] = '';
                }
                if ($campaign->isDraft) {
                    $service->addRecipient($campaign, $record, $user, $posted + ['by' => CmsCommCampaignRecipient::TEXT_BY_USER, 'client' => 'crm']);
                } else {
                    $service->setText($recipient, $posted['subject'], (string)$posted['body_html'], $user);
                }
                \Yii::$app->session->setFlash('success', 'Сохранено.');

                return $this->redirect(['recipient', 'pk' => (int)$campaign->id, 'record' => $recordRef]);
            } catch (\DomainException $e) {
                $error = $e->getMessage();
                $values = $posted;
            }
        }

        $preview = null;
        if ($record) {
            $sample = $recipient ?: new CmsCommCampaignRecipient(['record_type' => $parsed[0], 'record_id' => $parsed[1]]);
            $sample->address = $values['address'];
            $sample->name = $values['name'];
            $sample->subject = $values['subject'];
            $sample->body_html = $values['body_html'];
            $sample->populateRelation('record', $record);
            if (!$sample->cms_comm_account_id && $campaign->sender_mode === CmsCommCampaign::SENDER_ACCOUNT) {
                $sample->cms_comm_account_id = (int)$campaign->cms_comm_account_id;
            }
            try {
                $preview = $service->preview($campaign, $sample, $user);
            } catch (\Throwable $e) {
                $error = $error ?? $e->getMessage();
            }
        }

        return $this->render('@skeeks/cms/communication/views/campaign/recipient', [
            'campaign' => $campaign,
            'record' => $record,
            'recordRef' => $recordRef,
            'recipient' => $recipient,
            'addresses' => $addresses,
            'values' => $values,
            'error' => $error,
            'preview' => $preview,
            'canEdit' => $canEdit,
        ]);
    }

    /** @return array state => label, where: filters of the report */
    public static function reportFilters(): array
    {
        $r = CmsCommCampaignRecipient::class;
        // Delivery by a mailing service's events (stage 4C), when the columns are there.
        $delivery = (new CmsCommCampaignRecipient())->hasAttribute('delivered_at') ? [
            'delivered' => ['label' => 'Доставлено', 'where' => ['not', ['delivered_at' => null]]],
            'bounced' => ['label' => 'Не доставлено', 'where' => ['not', ['bounced_at' => null]]],
        ] : [];

        return [
            'sent' => ['label' => 'Отправлено', 'where' => ['status' => $r::STATUS_SENT]],
        ] + $delivery + [
            'pending' => ['label' => 'Ждут отправки', 'where' => ['status' => [$r::STATUS_PENDING, $r::STATUS_SENDING]]],
            'failed' => ['label' => 'Ошибка', 'where' => ['status' => $r::STATUS_FAILED]],
            'opened' => ['label' => 'Открыли предложение', 'where' => ['not', ['opened_at' => null]]],
            'visited' => ['label' => 'Перешли на сайт', 'where' => ['not', ['visited_at' => null]]],
            'replied' => ['label' => 'Ответили', 'where' => ['not', ['replied_at' => null]]],
            'accepted' => ['label' => 'Приняли КП', 'where' => ['not', ['accepted_at' => null]]],
            'unsubscribed' => ['label' => 'Отписались', 'where' => ['not', ['unsubscribed_at' => null]]],
            'excluded' => ['label' => 'Исключены', 'where' => ['status' => $r::STATUS_EXCLUDED]],
            'personal' => ['label' => 'Личный текст', 'where' => ['not', ['body_html' => null]]],
            'cancelled' => ['label' => 'Не отправлены', 'where' => ['status' => $r::STATUS_CANCELLED]],
        ];
    }

    /**
     * The recipient of the preview: the chosen one ("company:15"; a bare
     * number is a lead, as links made before lists), otherwise the first one
     * to get the letter.
     */
    private function sample(CmsCommCampaign $campaign, Campaigns $service, string $ref, ?array $summary = null): ?CmsCommCampaignRecipient
    {
        $ref = ctype_digit($ref) ? CrmRecords::LEAD.':'.$ref : $ref;
        $is = static fn(CmsCommCampaignRecipient $r) => $r->record_type.':'.(int)$r->record_id === $ref;
        if (!$campaign->isDraft) {
            $query = CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id])
                ->andWhere(['not', ['status' => CmsCommCampaignRecipient::STATUS_EXCLUDED]]);
            if ($parsed = \skeeks\cms\communication\services\SendService::parseRef($ref)) {
                $query->andWhere(['record_type' => $parsed[0], 'record_id' => $parsed[1]]);
            }

            return $query->orderBy(['id' => SORT_ASC])->one();
        }
        if ($summary === null) {
            try {
                $summary = $service->summary($campaign, \Yii::$app->user->identity);
            } catch (\DomainException $e) {
                return null;
            }
        }
        $first = null;
        foreach ($summary['recipients'] as $recipient) {
            if ($recipient->status !== CmsCommCampaignRecipient::STATUS_PENDING) {
                continue;
            }
            if ($ref === '' || $is($recipient)) {
                return $recipient;
            }
            $first = $first ?? $recipient;
        }

        return $first;
    }

    /** @return string[] "type:id" => "name <email>": recipients to preview on */
    private function samples(CmsCommCampaign $campaign, ?array $summary): array
    {
        $recipients = $summary !== null ? $summary['recipients'] : CmsCommCampaignRecipient::find()
            ->andWhere(['cms_comm_campaign_id' => (int)$campaign->id])
            ->andWhere(['not', ['status' => CmsCommCampaignRecipient::STATUS_EXCLUDED]])->orderBy(['id' => SORT_ASC])->limit(300)->all();
        $result = [];
        foreach ($recipients as $recipient) {
            if ($recipient->status === CmsCommCampaignRecipient::STATUS_EXCLUDED) {
                continue;
            }
            $result[$recipient->record_type.':'.(int)$recipient->record_id] = trim(($recipient->name ? $recipient->name.' ' : '').'<'.$recipient->address.'>');
            if (count($result) >= 300) {
                break;
            }
        }

        return $result;
    }

    /** Only campaigns of the current site open by pk. */
    public function getModel()
    {
        $model = parent::getModel();
        if ($model && (int)$model->cms_site_id !== (int)\Yii::$app->skeeks->site->id) {
            throw new NotFoundHttpException('Кампания не найдена.');
        }

        return $model;
    }

    /** The pace of a mailbox as text, for the card. */
    public static function paceText(?\skeeks\cms\communication\models\CmsCommAccount $account): string
    {
        return $account ? MailboxPace::describe(MailboxPace::settings($account)) : '';
    }
}
