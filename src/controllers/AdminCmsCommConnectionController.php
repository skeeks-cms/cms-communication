<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\communication\drivers\ConnectionDriver;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\grid\BooleanColumn;
use skeeks\cms\helpers\RequestResponse;
use skeeks\cms\rbac\CmsManager;
use skeeks\yii2\form\Builder;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\FieldSet;
use skeeks\yii2\form\fields\PasswordField;
use skeeks\yii2\form\fields\SelectField;
use yii\base\Event;
use yii\helpers\ArrayHelper;

/**
 * Logins on mail servers and other services. Only administrators manage them:
 * the form holds passwords. Secrets are write-only: the form never shows them,
 * an empty field keeps the stored value.
 */
class AdminCmsCommConnectionController extends BackendModelStandartController
{
    public function init()
    {
        $this->name = 'Подключения почты';
        $this->modelShowAttribute = 'name';
        $this->modelClassName = CmsCommConnection::class;
        $this->permissionName = CmsManager::PERMISSION_ROLE_ADMIN_ACCESS;
        $this->generateAccessActions = false;

        parent::init();
    }

    public function actions()
    {
        $save = function (Event $e) {
            $action = $e->sender;
            /** @var CmsCommConnection $model */
            $model = $action->model;
            $action->isSaveFormModels = false;

            if (isset($action->formModels['driver'])) {
                $model->applyDriver($action->formModels['driver']);
            }
            if (!$model->save()) {
                throw new \yii\base\Exception('Не удалось сохранить подключение.');
            }
        };

        return ArrayHelper::merge(parent::actions(), [
            'index' => [
                'emptyState' => [
                    'title' => 'Подключений пока нет',
                    'description' => 'Подключение — это логин на почтовом сервере (Яндекс 360, Mail.ru, Gmail, свой сервер). Через него почтовые ящики CRM принимают и отправляют письма.',
                    'icon' => 'far fa-envelope',
                    'action' => ['backendAction' => 'create', 'label' => 'Добавить подключение'],
                ],
                'filters' => false,
                'grid' => [
                    'on init' => static function (Event $event) {
                        $event->sender->dataProvider->query->cmsSite();
                    },
                    'defaultOrder' => ['name' => SORT_ASC],
                    'visibleColumns' => ['checkbox', 'actions', 'name', 'driver', 'is_active'],
                    'columns' => [
                        'name' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsCommunication/admin-cms-comm-connection',
                            'attribute' => 'name',
                        ],
                        'driver' => [
                            'value' => static fn(CmsCommConnection $model) => $model->driverTitle,
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
                'on beforeSave' => $save,
            ],
            'update' => [
                'fields' => [$this, 'updateFields'],
                'on beforeSave' => $save,
            ],
            'check' => [
                'class' => \skeeks\cms\backend\actions\BackendModelAction::class,
                'name' => 'Проверить подключение',
                'icon' => 'fas fa-plug',
                'priority' => 150,
                'callback' => [$this, 'check'],
            ],
            'poll' => [
                'class' => \skeeks\cms\backend\actions\BackendModelAction::class,
                'name' => 'Получить письма',
                'icon' => 'fas fa-inbox',
                'priority' => 160,
                'callback' => [$this, 'poll'],
            ],
        ]);
    }

    public function check()
    {
        return $this->renderContent(\skeeks\cms\communication\helpers\ConnectionDiagnostics::check($this->model));
    }

    public function poll()
    {
        return $this->renderContent(\skeeks\cms\communication\helpers\ConnectionDiagnostics::poll($this->model));
    }

    public function updateFields($action)
    {
        /** @var CmsCommConnection $model */
        $model = $action->model;
        $component = CmsCommConnection::component();

        if ($model->isNewRecord && !$model->driver && ($drivers = $component->getDriversForSelect())) {
            $model->driver = (string)array_key_first($drivers);
        }

        $result = [
            'main' => [
                'class' => FieldSet::class,
                'name' => 'Основное',
                'fields' => [
                    'name',
                    'driver' => [
                        'class' => SelectField::class,
                        'items' => $component->getDriversForSelect(),
                        'allowNull' => false,
                        'elementOptions' => $model->isNewRecord
                            ? [RequestResponse::DYNAMIC_RELOAD_FIELD_ELEMENT => 'true']
                            : ['disabled' => 'disabled'],
                    ],
                    'is_active' => ['class' => BoolField::class, 'allowNull' => false],
                ],
            ],
        ];

        if (!$component->hasSecretKey()) {
            $result['main']['fields']['secretKeyWarning'] = [
                'class' => \skeeks\yii2\form\fields\HtmlBlock::class,
                'content' => '<div class="alert alert-warning">Не задан ключ шифрования паролей: params[\'cms-communication\'][\'secretKey\'] или переменная окружения CMS_COMMUNICATION_SECRET_KEY. Пароль сохранить не получится.</div>',
            ];
        }

        if ($model->driver && isset($component->drivers[$model->driver])) {
            // Without secrets: passwords are never rendered back into the form.
            $driver = $model->isNewRecord ? $component->createDriver($model->driver) : $model->createDriver(false);
            if ($post = \Yii::$app->request->post()) {
                $driver->load($post);
            }
            $action->formModels['driver'] = $driver;

            $driverFields = $this->secretFields($driver, $driver->getConfigFormFields(), $model);
            if ($driverFields) {
                $result['driver'] = [
                    'class' => FieldSet::class,
                    'name' => 'Настройки подключения',
                    'fields' => Builder::setModelToFields($driverFields, $driver),
                ];
            }
        }

        return $result;
    }

    /**
     * Password inputs for secret attributes, always empty.
     */
    private function secretFields(ConnectionDriver $driver, array $fields, CmsCommConnection $model): array
    {
        foreach ($driver->secretAttributes() as $attribute) {
            $fields[$attribute] = ArrayHelper::merge(is_array($fields[$attribute] ?? null) ? $fields[$attribute] : [], [
                'class' => PasswordField::class,
                'elementOptions' => ['autocomplete' => 'new-password', 'value' => ''],
                'hint' => $model->hasSecret() ? 'Сохранён. Оставьте пустым, чтобы не менять.' : null,
            ]);
        }

        return $fields;
    }
}
