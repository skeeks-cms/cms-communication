<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\communication\models\CmsCommTemplate;
use skeeks\cms\communication\services\MailTemplates;
use skeeks\cms\rbac\CmsManager;
use skeeks\cms\widgets\formInputs\richText\RichTextInputWidget;
use skeeks\yii2\form\fields\FieldSet;
use skeeks\yii2\form\fields\HtmlBlock;
use skeeks\yii2\form\fields\SelectField;
use skeeks\yii2\form\fields\WidgetField;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\web\NotFoundHttpException;

/**
 * Quick reply templates of the letter form, in the standard backend windows:
 * an employee adds and changes their personal templates and the shared ones
 * of mailboxes they manage (MailTemplates::canEdit()). Opened from "Мои
 * настройки почты" and from the "Шаблоны" menu of the letter form.
 */
class AdminCmsCommTemplateController extends BackendModelStandartController
{
    public function init()
    {
        $this->name = 'Шаблоны ответов';
        $this->modelShowAttribute = 'name';
        $this->modelClassName = CmsCommTemplate::class;
        $this->generateAccessActions = false;
        // Every employee with access to the administration: the list and each record are checked
        // below (see AdminCmsCommMessageController: an empty list would let a guest in).
        $this->permissionNames = [CmsManager::PERMISSION_ADMIN_ACCESS => 'Доступ к системе администрирования'];

        parent::init();
    }

    public function actions()
    {
        $canEdit = fn() => $this->model && $this->templates()->canEdit($this->model);

        return ArrayHelper::merge(parent::actions(), [
            'index' => [
                'emptyState' => [
                    'title' => 'Шаблонов пока нет',
                    'description' => 'Готовые тексты ответов вставляются в письмо кнопкой «Шаблоны».',
                    'icon' => 'far fa-file-alt',
                    'action' => ['backendAction' => 'create', 'label' => 'Добавить шаблон'],
                ],
                'filters' => false,
                'grid' => [
                    'on init' => function (Event $event) {
                        $ids = array_map(static fn($t) => (int)$t->id, array_filter($this->templates()->templates(), [$this->templates(), 'canEdit']));
                        $event->sender->dataProvider->query->andWhere(['id' => $ids ?: [0]])->with('account');
                    },
                    'defaultOrder' => ['priority' => SORT_ASC, 'name' => SORT_ASC],
                    'visibleColumns' => ['checkbox', 'actions', 'name', 'cms_comm_account_id'],
                    'columns' => [
                        'name' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsCommunication/admin-cms-comm-template',
                        ],
                        'cms_comm_account_id' => [
                            'value' => static fn(CmsCommTemplate $t) => $t->cms_user_id ? 'Личный' : 'Общий: '.($t->account ? $t->account->address : ''),
                        ],
                    ],
                ],
            ],
            'create' => [
                'accessCallback' => fn() => (bool)$this->templates()->sendableAccounts(),
                'fields' => [$this, 'updateFields'],
            ],
            'update' => ['accessCallback' => $canEdit, 'fields' => [$this, 'updateFields']],
            'delete' => ['accessCallback' => $canEdit],
            'delete-multi' => ['isVisible' => false, 'accessCallback' => static fn() => false],
        ]);
    }

    public function updateFields($action)
    {
        /** @var CmsCommTemplate $model */
        $model = $action->model;
        $manageable = $this->templates()->manageableAccounts();

        return [
            'main' => [
                'class' => FieldSet::class,
                'name' => 'Шаблон',
                'fields' => [
                    'name',
                    // Shared templates only for mailboxes the employee manages; otherwise a personal one.
                    'scope' => $manageable ? [
                        'class' => SelectField::class,
                        'items' => [CmsCommTemplate::SCOPE_OWN => 'Только мне (личный)'] + ArrayHelper::map($manageable, 'id', static fn($a) => 'Всем, кто пишет с '.$a->address),
                        'allowNull' => false,
                    ] : [
                        'class' => HtmlBlock::class,
                        'content' => Html::tag('p', 'Личный шаблон: виден только вам.', ['class' => 'text-muted']),
                    ],
                    'body_html' => [
                        'class' => WidgetField::class,
                        'widgetClass' => RichTextInputWidget::class,
                        'widgetConfig' => [
                            'preset' => MailTemplates::EDITOR_PRESET,
                            'tools' => MailTemplates::EDITOR_TOOLS,
                            'paragraph' => 'div',
                            'minHeight' => 160,
                        ],
                    ],
                ],
            ],
        ];
    }

    /** Only templates the employee may change open by pk. */
    public function getModel()
    {
        if (\Yii::$app->controller !== $this) {
            return null;
        }
        if ($this->_model === null && ($pk = \Yii::$app->request->get($this->requestPkParamName))) {
            $this->_model = $this->templates()->editable((int)$pk);
            if (!$this->_model) {
                throw new NotFoundHttpException('Шаблон не найден или у вас нет к нему доступа.');
            }
        }

        return $this->_model;
    }

    private ?MailTemplates $_templates = null;

    private function templates(): MailTemplates
    {
        return $this->_templates ??= new MailTemplates(\Yii::$app->user->identity);
    }
}
