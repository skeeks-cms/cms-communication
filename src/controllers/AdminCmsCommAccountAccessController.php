<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\grid\BooleanColumn;
use skeeks\cms\models\CmsUser;
use skeeks\cms\rbac\CmsManager;
use skeeks\cms\widgets\AjaxSelectModel;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\FieldSet;
use skeeks\yii2\form\fields\HtmlBlock;
use skeeks\yii2\form\fields\SelectField;
use skeeks\yii2\form\fields\WidgetField;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * Rights of employees on mailboxes; opened from the "Доступ сотрудников" tab.
 */
class AdminCmsCommAccountAccessController extends BackendModelStandartController
{
    public function init()
    {
        $this->name = 'Доступ к почтовым ящикам';
        $this->modelShowAttribute = 'asText';
        $this->modelClassName = CmsCommAccountAccess::class;
        $this->permissionName = CmsManager::PERMISSION_ROLE_ADMIN_ACCESS;
        $this->generateAccessActions = false;

        parent::init();
    }

    public function actions()
    {
        return ArrayHelper::merge(parent::actions(), [
            'index' => [
                'emptyState' => [
                    'title' => 'Доступ ещё никому не выдан',
                    'description' => 'Добавьте сотрудников и укажите, какие письма им видны и могут ли они писать с этого ящика.',
                    'icon' => 'fas fa-user-lock',
                    'action' => ['backendAction' => 'create', 'label' => 'Выдать доступ'],
                ],
                'filters' => false,
                'grid' => [
                    'on init' => static function (Event $event) {
                        $query = $event->sender->dataProvider->query;
                        // Only mailboxes of the current site.
                        $query->andWhere(['cms_comm_account_id' => CmsCommAccount::find()->cmsSite()->select('id')]);
                        $query->with(['user', 'account']);
                    },
                    'defaultOrder' => ['id' => SORT_ASC],
                    'visibleColumns' => ['checkbox', 'actions', 'cms_user_id', 'cms_comm_account_id', 'read_level', 'send_level', 'can_manage', 'is_default', 'is_responsible'],
                    'columns' => [
                        'cms_user_id' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsCommunication/admin-cms-comm-account-access',
                            'attribute' => 'cms_user_id',
                            'value' => static fn(CmsCommAccountAccess $model) => $model->asText(),
                        ],
                        'cms_comm_account_id' => [
                            'value' => static fn(CmsCommAccountAccess $model) => $model->account ? $model->account->address : '',
                        ],
                        'read_level' => [
                            'value' => static fn(CmsCommAccountAccess $model) => CmsCommAccountAccess::readLevels()[$model->read_level] ?? $model->read_level,
                        ],
                        'send_level' => [
                            'value' => static fn(CmsCommAccountAccess $model) => CmsCommAccountAccess::sendLevels()[$model->send_level] ?? $model->send_level,
                        ],
                        'can_manage' => ['class' => BooleanColumn::class, 'trueValue' => 1, 'falseValue' => 0],
                        'is_default' => ['class' => BooleanColumn::class, 'trueValue' => 1, 'falseValue' => 0],
                        'is_responsible' => ['class' => BooleanColumn::class, 'trueValue' => 1, 'falseValue' => 0],
                    ],
                ],
            ],
            'create' => ['fields' => [$this, 'updateFields']],
            'update' => ['fields' => [$this, 'updateFields']],
        ]);
    }

    public function updateFields($action)
    {
        /** @var CmsCommAccountAccess $model */
        $model = $action->model;
        if ($model->isNewRecord && !$model->cms_comm_account_id) {
            // Prefilled by the "Доступ сотрудников" tab; the model validates the mailbox site.
            $get = (array)\Yii::$app->request->get($model->formName(), []);
            $model->cms_comm_account_id = isset($get['cms_comm_account_id']) ? (int)$get['cms_comm_account_id'] : null;
        }

        return [
            'main' => [
                'class' => FieldSet::class,
                'name' => 'Сотрудник',
                'fields' => [
                    // Opened from a mailbox tab: show the mailbox, keep its id hidden.
                    'cms_comm_account_id' => $model->cms_comm_account_id ? [
                        'class' => HtmlBlock::class,
                        'content' => Html::tag('div',
                            Html::tag('label', 'Ящик', ['class' => 'control-label'])
                            .Html::tag('div', Html::encode($model->account ? $model->account->asText() : '#'.$model->cms_comm_account_id))
                            .Html::activeHiddenInput($model, 'cms_comm_account_id'),
                            ['class' => 'form-group']),
                    ] : [
                        'class' => SelectField::class,
                        'items' => ArrayHelper::map(CmsCommAccount::find()->cmsSite()->orderBy(['address' => SORT_ASC])->all(), 'id', 'address'),
                        'allowNull' => false,
                    ],
                    'cms_user_id' => [
                        'class' => WidgetField::class,
                        'widgetClass' => AjaxSelectModel::class,
                        'widgetConfig' => [
                            'modelClass' => CmsUser::class,
                            'searchQuery' => static function ($word = '') {
                                $query = CmsUser::find()->isWorker()->cmsSite();
                                return $word ? $query->search($word) : $query;
                            },
                        ],
                    ],
                ],
            ],
            'rights' => [
                'class' => FieldSet::class,
                'name' => 'Права',
                'fields' => [
                    'read_level' => [
                        'class' => SelectField::class,
                        'items' => CmsCommAccountAccess::readLevels(),
                        'allowNull' => false,
                    ],
                    'send_level' => [
                        'class' => SelectField::class,
                        'items' => CmsCommAccountAccess::sendLevels(),
                        'allowNull' => false,
                    ],
                    'is_default' => ['class' => BoolField::class, 'allowNull' => false],
                    'can_manage' => ['class' => BoolField::class, 'allowNull' => false],
                    'can_manage_messages' => ['class' => BoolField::class, 'allowNull' => false],
                    'is_responsible' => ['class' => BoolField::class, 'allowNull' => false],
                ],
            ],
        ];
    }
}
