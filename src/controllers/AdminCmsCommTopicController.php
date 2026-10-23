<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\BackendAction;
use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\rbac\CmsManager;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\FieldSet;
use skeeks\yii2\form\fields\NumberField;
use skeeks\yii2\form\fields\TextareaField;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\MethodNotAllowedHttpException;

/**
 * "Настройки → Темы рассылок": topics of advertising mailings of the site.
 * Every campaign has one topic; clients opt out of topics on the opt-out
 * page. "Добавить начальные темы" adds the four agreed topics the site does
 * not have yet (the package seeds nothing by itself: topics are site data).
 */
class AdminCmsCommTopicController extends BackendModelStandartController
{
    public function init()
    {
        $this->name = 'Темы рассылок';
        $this->modelShowAttribute = 'name';
        $this->modelClassName = CmsCommTopic::class;
        $this->generateAccessActions = false;
        $this->permissionName = CmsManager::PERMISSION_ROLE_ADMIN_ACCESS;

        parent::init();
    }

    public function actions()
    {
        $addDefaults = [
            'label' => 'Добавить начальные темы',
            'url' => Url::to(['add-defaults']),
            'variant' => 'secondary',
            'options' => ['data-method' => 'post', 'data-pjax' => '0',
                'title' => 'Добавить темы: '.implode(', ', ArrayHelper::getColumn(CmsCommTopic::DEFAULTS, 'name'))],
        ];

        return ArrayHelper::merge(parent::actions(), [
            'index' => [
                'emptyState' => [
                    'title' => 'Тем рассылок пока нет',
                    'description' => 'У каждой рассылки одна тема. Клиент может отписаться от темы, а служебные письма продолжат приходить. '
                        .'Начальные темы: '.implode(', ', ArrayHelper::getColumn(CmsCommTopic::DEFAULTS, 'name')).'.',
                    'icon' => 'far fa-paper-plane',
                    'action' => array_merge($addDefaults, ['variant' => 'primary']),
                ],
                'pageHeader' => ['actions' => ['create', $addDefaults]],
                'filters' => false,
                'grid' => [
                    'on init' => static function (Event $event) {
                        $event->sender->dataProvider->query->andWhere(['cms_site_id' => (int)\Yii::$app->skeeks->site->id]);
                    },
                    'defaultOrder' => ['priority' => SORT_ASC, 'name' => SORT_ASC],
                    'visibleColumns' => ['checkbox', 'actions', 'name', 'subscribers', 'is_active', 'priority'],
                    'columns' => [
                        'name' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsCommunication/admin-cms-comm-topic',
                        ],
                        'subscribers' => [
                            'label' => 'Подписаны / отписаны',
                            'format' => 'raw',
                            'value' => static function (CmsCommTopic $topic) {
                                $counts = CmsCommContactTopic::find()->select(['status', 'c' => 'COUNT(*)'])
                                    ->andWhere(['cms_comm_topic_id' => (int)$topic->id])->groupBy('status')->asArray()->all();
                                $counts = ArrayHelper::map($counts, 'status', 'c');
                                return Html::encode((int)($counts[CmsCommContactTopic::STATUS_SUBSCRIBED] ?? 0).' / '.(int)($counts[CmsCommContactTopic::STATUS_UNSUBSCRIBED] ?? 0));
                            },
                        ],
                        'is_active' => [
                            'value' => static fn(CmsCommTopic $t) => $t->is_active ? 'Да' : 'Нет',
                        ],
                    ],
                ],
            ],
            'create' => ['fields' => [$this, 'updateFields']],
            'update' => ['fields' => [$this, 'updateFields']],
            'add-defaults' => [
                'class' => BackendAction::class,
                'name' => 'Добавить начальные темы',
                'isVisible' => false,
                'callback' => [$this, 'addDefaults'],
            ],
        ]);
    }

    public function updateFields($action)
    {
        return [
            'main' => [
                'class' => FieldSet::class,
                'name' => 'Тема',
                'fields' => [
                    'name',
                    'description' => ['class' => TextareaField::class],
                    'is_active' => ['class' => BoolField::class, 'allowNull' => false],
                    'priority' => ['class' => NumberField::class],
                ],
            ],
        ];
    }

    public function addDefaults()
    {
        if (!\Yii::$app->request->isPost) {
            throw new MethodNotAllowedHttpException();
        }
        $added = CmsCommTopic::addDefaults((int)\Yii::$app->skeeks->site->id);
        \Yii::$app->session->setFlash('success', $added ? "Добавлено тем: {$added}." : 'Все начальные темы уже есть.');

        return $this->redirect(['index']);
    }
}
