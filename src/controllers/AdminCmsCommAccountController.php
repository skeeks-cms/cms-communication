<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\actions\BackendGridModelRelatedAction;
use skeeks\cms\backend\actions\BackendModelAction;
use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\communication\forms\MailboxConnectionForm;
use skeeks\cms\communication\forms\MailHistoryForm;
use skeeks\cms\communication\helpers\ConnectionDiagnostics;
use skeeks\cms\communication\mail\MailDriverInterface;
use skeeks\cms\communication\mail\MailingServiceInterface;
use skeeks\cms\communication\mail\MailingServices;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\services\MailboxPace;
use skeeks\cms\grid\BooleanColumn;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\helpers\RequestResponse;
use skeeks\cms\rbac\CmsManager;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\FieldSet;
use skeeks\yii2\form\fields\HtmlBlock;
use skeeks\yii2\form\fields\PasswordField;
use skeeks\yii2\form\fields\SelectField;
use yii\base\Event;
use yii\db\Expression;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * Mailboxes of the site and employee access to them. Managed by administrators;
 * per-mailbox management rights (can_manage) are applied in a later stage.
 */
class AdminCmsCommAccountController extends BackendModelStandartController
{
    public function init()
    {
        $this->name = 'Почтовые ящики';
        $this->modelShowAttribute = 'asText';
        $this->modelClassName = CmsCommAccount::class;
        // Whoever manages mail ("Почта: управлять почтовыми ящиками и «Настройками почты»").
        $this->permissionName = AdminCmsCommSettingsController::PERMISSION;
        $this->generateAccessActions = false;

        parent::init();
    }

    public function actions()
    {
        return ArrayHelper::merge(parent::actions(), [
            'index' => [
                'emptyState' => [
                    'title' => 'Почтовых ящиков пока нет',
                    'description' => 'Подключите ящик, например info@ или личный ящик менеджера, и выдайте сотрудникам права: кто читает все письма, кто только связанные со своими клиентами, кто может отправлять.',
                    'icon' => 'far fa-envelope',
                    'action' => ['backendAction' => 'create', 'label' => 'Добавить ящик'],
                ],
                'filters' => false,
                'grid' => [
                    'on init' => static function (Event $event) {
                        $query = $event->sender->dataProvider->query;
                        $query->cmsSite();
                        $query->with('connection');
                        $query->addSelect([
                            CmsCommAccount::tableName().'.*',
                            'countAccesses' => CmsCommAccountAccess::find()
                                ->select([new Expression('count(1)')])
                                ->andWhere(['cms_comm_account_id' => new Expression(CmsCommAccount::tableName().'.id')]),
                        ]);
                    },
                    'defaultOrder' => ['address' => SORT_ASC],
                    'visibleColumns' => ['checkbox', 'actions', 'address', 'cms_comm_connection_id', 'countAccesses', 'is_active'],
                    'columns' => [
                        'address' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsCommunication/admin-cms-comm-account',
                            'attribute' => 'address',
                            'value' => static fn(CmsCommAccount $model) => $model->asText(),
                        ],
                        'cms_comm_connection_id' => [
                            'value' => static fn(CmsCommAccount $model) => $model->connection ? $model->connection->name : '—',
                        ],
                        'countAccesses' => [
                            'label' => 'Сотрудников с доступом',
                            'format' => 'raw',
                            'value' => static fn(CmsCommAccount $model) => Html::tag('strong',
                                \Yii::$app->formatter->asInteger((int)$model->raw_row['countAccesses'])),
                        ],
                        'is_active' => [
                            'class' => BooleanColumn::class,
                            'trueValue' => 1,
                            'falseValue' => 0,
                        ],
                    ],
                ],
            ],
            'create' => [
                'fields' => [$this, 'updateFields'],
                'on initFormModels' => [$this, 'initMailboxForm'],
                'on beforeValidate' => [$this, 'beforeValidateMailbox'],
                'on beforeSave' => [$this, 'saveMailbox'],
            ],
            'update' => [
                'fields' => [$this, 'updateFields'],
                'on initFormModels' => [$this, 'initMailboxForm'],
                'on beforeValidate' => [$this, 'beforeValidateMailbox'],
                'on beforeSave' => [$this, 'saveMailbox'],
            ],

            'check' => [
                'class' => BackendModelAction::class,
                'name' => 'Проверить подключение',
                'icon' => 'fas fa-plug',
                'priority' => 150,
                'callback' => fn() => $this->renderContent(ConnectionDiagnostics::check($this->model->connection)),
            ],
            'poll' => [
                'class' => BackendModelAction::class,
                'name' => 'Получить письма',
                'icon' => 'fas fa-inbox',
                'priority' => 160,
                'callback' => fn() => $this->renderContent(ConnectionDiagnostics::poll($this->model->connection)),
            ],

            'access' => [
                'class' => BackendGridModelRelatedAction::class,
                'name' => 'Доступ сотрудников',
                'icon' => 'fas fa-user-lock',
                'priority' => 200,
                'controllerRoute' => '/cmsCommunication/admin-cms-comm-account-access',
                'relation' => ['cms_comm_account_id' => 'id'],
                'on gridInit' => static function (Event $e) {
                    $action = $e->sender;
                    $action->relatedIndexAction->backendShowings = false;
                    $action->relatedIndexAction->filters = false;
                    $visibleColumns = $action->relatedIndexAction->grid['visibleColumns'];
                    ArrayHelper::removeValue($visibleColumns, 'cms_comm_account_id');
                    $action->relatedIndexAction->grid['visibleColumns'] = $visibleColumns;
                },
            ],

            'history' => [
                'class' => BackendModelAction::class,
                'name' => 'Сбор писем',
                'icon' => 'fas fa-history',
                'priority' => 210,
                'callback' => [$this, 'history'],
            ],

            'mailing' => [
                'class' => BackendModelAction::class,
                'name' => 'Рассылки',
                'icon' => 'far fa-paper-plane',
                'priority' => 220,
                'callback' => [$this, 'mailing'],
            ],
        ]);
    }

    /**
     * "Рассылки": how fast campaign letters leave the mailbox
     * (services\MailboxPace) and what is waiting to leave.
     */
    public function mailing()
    {
        /** @var CmsCommAccount $account */
        $account = $this->model;
        $request = \Yii::$app->request;
        $errors = [];
        $saved = false;
        if ($request->isPost) {
            $errors = MailboxPace::save($account, (array)$request->post('pace', []));
            $saved = !$errors;
        }
        $now = time();

        return $this->render('@skeeks/cms/communication/views/account/mailing', [
            'account' => $account,
            'settings' => $errors ? MailboxPace::DEFAULTS + (array)$request->post('pace', []) : MailboxPace::settings($account),
            'errors' => $errors,
            'saved' => $saved,
            'sentToday' => MailboxPace::sentToday((int)$account->id, $now),
            'nextSlot' => MailboxPace::nextSlotFor($account, $now),
            'pending' => (int)\skeeks\cms\communication\models\CmsCommCampaignRecipient::find()
                ->andWhere(['cms_comm_account_id' => (int)$account->id, 'status' => \skeeks\cms\communication\models\CmsCommCampaignRecipient::STATUS_PENDING])->count(),
        ]);
    }

    /**
     * "Сбор писем": collects letters of an earlier period from the mailbox
     * (the first receiving takes CommunicationComponent::$initialDays) as the
     * comm.mail.history job. Collected letters count as read.
     */
    public function history()
    {
        /** @var CmsCommAccount $account */
        $account = $this->model;
        $connection = $account->connection;
        $form = new MailHistoryForm();
        $run = null;
        $request = \Yii::$app->request;
        if ($connection && $request->isPost && $form->load($request->post()) && $form->validate()) {
            $since = $form->since();
            $run = \Yii::$app->jobs->push('comm.mail.history', [
                'connectionId' => (int)$connection->id,
                'accountId' => (int)$account->id,
                'since' => $since,
            ], [
                'siteId' => (int)$account->cms_site_id,
                'title' => 'Сбор писем '.$account->address.' '.MailHistoryForm::describe($since),
                'triggerType' => 'manual',
            ]);
            if (!$run) {
                \Yii::$app->getSession()->setFlash('warning', 'Сбор писем этого ящика уже идёт: дождитесь его окончания или остановите его.');
            }
        }

        return $this->render('@skeeks/cms/communication/views/account/history', [
            'account' => $account,
            'form' => $form,
            'run' => $run,
            'runs' => $connection ? CmsJobRun::find()->andWhere([
                'job_type' => 'comm.mail.history',
                'dedup_key' => 'comm.mail.history:'.(int)$connection->id,
            ])->orderBy(['id' => SORT_DESC])->limit(5)->all() : [],
            'initialDays' => (int)CmsCommConnection::component()->initialDays,
        ]);
    }

    /**
     * One form for the mailbox and its login. Usually a mailbox has its own
     * connection, created and updated here; the connections section is only
     * needed when several addresses arrive into one login.
     */
    public function initMailboxForm(Event $event)
    {
        $action = $event->sender;
        /** @var CmsCommAccount $account */
        $account = $action->model;
        $siteId = (int)($account->cms_site_id ?: (\Yii::$app->skeeks->site ? \Yii::$app->skeeks->site->id : 0));
        $own = $this->ownConnection($account);

        $conn = new MailboxConnectionForm();
        $conn->sharedIds = array_keys(MailboxConnectionForm::sharedOptions($siteId, $own ? (int)$own->id : null));
        if ($account->cms_comm_connection_id && !$own) {
            $conn->mode = MailboxConnectionForm::MODE_SHARED;
            $conn->shared_connection_id = (int)$account->cms_comm_connection_id;
        } elseif ($own && MailingServices::isServiceDriver((string)$own->driver)) {
            $conn->mode = MailboxConnectionForm::MODE_SERVICE.$own->driver;
        }
        $post = \Yii::$app->request->post($conn->formName());
        if (is_array($post) && isset($post['mode'])) {
            $conn->mode = (string)$post['mode'];
        }
        $action->formModels['conn'] = $conn;
        $action->formModels['files'] = \skeeks\cms\communication\forms\MailboxFileSettingsForm::fromAccount($account);

        if ($conn->mode === MailboxConnectionForm::MODE_OWN || $conn->serviceDriver()) {
            $driverId = $this->mailDriverId($conn);
            $same = $own && (string)$own->driver === $driverId;
            $driver = $same ? $own->createDriver(false) : CmsCommConnection::component()->createDriver($driverId);
            $driver->requireSecrets = !($same && $own->hasSecret());
            $action->formModels['driver'] = $driver;
        }
    }

    public function beforeValidateMailbox(Event $event)
    {
        $action = $event->sender;
        if (isset($action->formModels['driver']) && $action->model->address) {
            $action->formModels['driver']->applyDefaultsForAddress((string)$action->model->address);
        }
    }

    public function saveMailbox(Event $event)
    {
        $action = $event->sender;
        $action->isSaveFormModels = false;
        /** @var CmsCommAccount $account */
        $account = $action->model;
        /** @var MailboxConnectionForm $conn */
        $conn = $action->formModels['conn'];
        $own = $this->ownConnection($account);

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            if ($conn->mode === MailboxConnectionForm::MODE_OWN || $conn->serviceDriver()) {
                $driverId = $this->mailDriverId($conn);
                $connection = $own ?? new CmsCommConnection([
                    'cms_site_id' => $account->cms_site_id ?: (\Yii::$app->skeeks->site ? \Yii::$app->skeeks->site->id : null),
                    'driver' => $driverId,
                ]);
                if ((string)$connection->driver !== $driverId) {
                    // Another kind of connection: the secrets of the old one mean nothing to it.
                    $connection->driver = $driverId;
                    $connection->secret = null;
                }
                $connection->name = (string)$account->address;
                $connection->is_active = 1;
                $connection->applyDriver($action->formModels['driver']);
                if (!$connection->save()) {
                    throw new \RuntimeException(implode(' ', $connection->getFirstErrors()));
                }
                $account->cms_comm_connection_id = $connection->id;
            } else {
                $account->cms_comm_connection_id = (int)$conn->shared_connection_id;
            }
            if (isset($action->formModels['files'])) {
                $account->setFileSettings($action->formModels['files']->values());
            }
            if (!$account->save()) {
                throw new \RuntimeException(implode(' ', $account->getFirstErrors()));
            }
            // The mailbox switched to another login: its own one is no longer used.
            if ($own && (int)$account->cms_comm_connection_id !== (int)$own->id && !$own->getAccounts()->exists()) {
                $own->delete();
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    public function updateFields($action)
    {
        /** @var CmsCommAccount $account */
        $account = $action->model;
        /** @var MailboxConnectionForm $conn */
        $conn = $action->formModels['conn'];
        $siteId = (int)($account->cms_site_id ?: (\Yii::$app->skeeks->site ? \Yii::$app->skeeks->site->id : 0));
        $own = $this->ownConnection($account);

        $connectionFields = [
            'conn.mode' => [
                'class' => SelectField::class,
                'items' => MailboxConnectionForm::modes(),
                'allowNull' => false,
                'elementOptions' => [RequestResponse::DYNAMIC_RELOAD_FIELD_ELEMENT => 'true'],
            ],
        ];
        if (isset($action->formModels['driver'])) {
            $driver = $action->formModels['driver'];
            if (!CmsCommConnection::component()->hasSecretKey()) {
                $connectionFields['secretKeyWarning'] = [
                    'class' => HtmlBlock::class,
                    'content' => '<div class="alert alert-warning">Не задан ключ шифрования паролей: params[\'cms-communication\'][\'secretKey\'] или переменная окружения CMS_COMMUNICATION_SECRET_KEY.</div>',
                ];
            }
            foreach ($driver->getConfigFormFields() as $key => $config) {
                if (is_int($key)) {
                    $key = $config;
                    $config = [];
                }
                if (in_array($key, $driver->secretAttributes(), true)) {
                    $config = array_merge($config, [
                        'class' => PasswordField::class,
                        'elementOptions' => ['autocomplete' => 'new-password', 'value' => ''],
                        'hint' => $own && $own->hasSecret() ? 'Сохранён. Оставьте пустым, чтобы не менять.' : $driver->getAttributeHint($key),
                    ]);
                }
                if ($key === 'username') {
                    $config['hint'] = 'Если пусто — адрес ящика.';
                }
                if (in_array($key, ['imap_host', 'smtp_host'], true)) {
                    $config['hint'] = 'Если пусто — подставится сам для Яндекса, Mail.ru, Gmail и доменов на их почте.';
                }
                // Buttons of common mail services above the server fields: one click fills them all.
                if ($key === 'imap_host' && method_exists($driver, 'presetButtons')) {
                    $connectionFields['serverPresets'] = [
                        'class' => HtmlBlock::class,
                        'content' => $this->serverPresetsHtml($driver),
                    ];
                }
                $connectionFields['driver.'.$key] = $config;
            }
        } else {
            $connectionFields['conn.shared_connection_id'] = [
                'class' => SelectField::class,
                'items' => MailboxConnectionForm::sharedOptions($siteId, $own ? (int)$own->id : null),
                'allowNull' => false,
            ];
        }

        // A mailing service only sends: no files to keep, and "Ответы направлять на" matters.
        $service = (bool)$conn->serviceDriver();

        return array_filter([
            'main' => [
                'class' => FieldSet::class,
                'name' => 'Ящик',
                'fields' => [
                    'address',
                    'name',
                    'is_active' => ['class' => BoolField::class, 'allowNull' => false],
                ],
            ],
            'connection' => [
                'class' => FieldSet::class,
                'name' => $service ? 'Сервис рассылок' : 'Вход на почтовый сервер',
                'fields' => $connectionFields,
            ],
            'files' => $service ? null : [
                'class' => FieldSet::class,
                'name' => 'Хранение файлов',
                'elementOptions' => ['isOpen' => false],
                // "Как в настройках почты" shows only the choice; other modes their values.
                'fields' => \skeeks\cms\communication\components\MailFileSettings::formFields(
                    (string)$action->formModels['files']->mode, 'files.', \skeeks\cms\communication\forms\MailboxFileSettingsForm::modes(),
                    (bool)$action->formModels['files']->storeImages, (bool)$action->formModels['files']->storeAttachments,
                    (int)$action->formModels['files']->maxStoreSizeMb),
            ],
            'more' => [
                'class' => FieldSet::class,
                'name' => 'Дополнительно',
                'elementOptions' => ['isOpen' => $service],
                'fields' => [
                    'reply_to' => $service ? ['hint' => 'Общий рабочий ящик для ответов клиентов, например info@skeeks.com. Если в настройках сервиса выбран '
                        .'ящик сотрудника, сюда уходят ответы на письма тех, у кого своего ящика нет.'] : [],
                    'is_expose_linked' => ['class' => BoolField::class, 'allowNull' => false],
                ],
            ],
        ]);
    }

    /** "Яндекс", "Mail.ru", "Gmail"…: buttons that fill the server, port and encryption fields of the driver form. */
    private function serverPresetsHtml($driver): string
    {
        $ids = [];
        foreach (['imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption'] as $attribute) {
            $ids[$attribute] = Html::getInputId($driver, $attribute);
        }
        $buttons = '';
        foreach ($driver::presetButtons() as $title => $values) {
            $buttons .= Html::button(Html::encode($title), [
                'type' => 'button',
                'class' => 'btn btn-default btn-sm',
                'data-sx-mail-preset' => \yii\helpers\Json::encode($values),
            ]).' ';
        }
        $buttons .= Html::button('Свой сервер', ['type' => 'button', 'class' => 'btn btn-default btn-sm', 'data-sx-mail-preset' => '{}',
            'title' => 'Очистить поля серверов и ввести их вручную']);

        $this->view->registerJs('(function ($) {
            var ids = '.\yii\helpers\Json::encode($ids).';
            $(document).off("click.sxMailPreset").on("click.sxMailPreset", "[data-sx-mail-preset]", function () {
                var values = $(this).data("sx-mail-preset") || {};
                var manual = $.isEmptyObject(values);
                $.each(ids, function (attribute, id) {
                    var $input = $("#" + id);
                    if (!$input.length) {
                        return;
                    }
                    if (manual) {
                        if ($input.is("input[type=text], input:not([type])")) {
                            $input.val("").trigger("change");
                        }
                        return;
                    }
                    $input.val(String(values[attribute])).trigger("change");
                });
                $("#" + ids.imap_host).trigger("focus");
            });
        })(jQuery);');

        \skeeks\cms\communication\assets\MailClientAsset::register($this->view);

        return Html::tag('div',
            Html::tag('div', 'Почтовая служба', ['class' => 'sx-mail-presets__label'])
            .Html::tag('div', $buttons, ['class' => 'sx-mail-presets__buttons'])
            .Html::tag('div', 'Нажмите, чтобы заполнить серверы, порты и шифрование. Для почты на своём домене у Яндекса выберите «Яндекс и Яндекс 360».',
                ['class' => 'sx-mail-presets__hint']),
            // form-group: the same paddings as the fields around it.
            ['class' => 'form-group sx-mail-presets']);
    }

    /** The connection used by this mailbox alone, or null (none or shared). */
    private function ownConnection(CmsCommAccount $account): ?CmsCommConnection
    {
        if (!$account->cms_comm_connection_id || !($connection = $account->connection)) {
            return null;
        }
        $shared = CmsCommAccount::find()
            ->andWhere(['cms_comm_connection_id' => $connection->id])
            ->andWhere(['!=', 'id', (int)$account->id])
            ->exists();

        return $shared ? null : $connection;
    }

    /** The driver of the mailbox's own connection: the mailing service of the mode, otherwise the mail server driver. */
    private function mailDriverId(?MailboxConnectionForm $conn = null): string
    {
        if ($conn && ($service = $conn->serviceDriver()) && MailingServices::isServiceDriver($service)) {
            return $service;
        }
        foreach (CmsCommConnection::component()->drivers as $id => $config) {
            $class = is_array($config) ? ($config['class'] ?? null) : $config;
            if (is_string($class) && is_subclass_of($class, MailDriverInterface::class) && !is_subclass_of($class, MailingServiceInterface::class)) {
                return (string)$id;
            }
        }
        throw new \yii\base\InvalidConfigException('Не установлен драйвер почты (skeeks/cms-communication-imap).');
    }
}
