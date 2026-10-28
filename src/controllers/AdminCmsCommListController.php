<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\actions\BackendModelAction;
use skeeks\cms\backend\BackendAction;
use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\assets\MailingAsset;
use skeeks\cms\communication\forms\ListAddForm;
use skeeks\cms\communication\helpers\ListUi;
use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommListMember;
use skeeks\cms\communication\models\CmsCommListRule;
use skeeks\cms\communication\services\ListRules;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\RecipientLists;
use skeeks\cms\communication\services\SendService;
use skeeks\cms\grid\DateTimeColumnData;
use skeeks\yii2\form\fields\TextareaField;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * "Рассылки → Списки": lists of recipients (services\RecipientLists). The
 * list of lists is the tab of the campaigns section (renderCampaignsTab());
 * a list card shows the counters and the members with who, when and from
 * where added them, "Добавить" (a lead, company or client), "убрать" and the
 * CSV. "add-record" is the window of "Добавить в список" from CRM cards.
 *
 * The same right as campaigns: cmsCommunication/admin-cms-comm-campaign.
 */
class AdminCmsCommListController extends BackendModelStandartController
{
    public function init()
    {
        $this->name = 'Списки рассылки';
        $this->modelShowAttribute = 'asText';
        $this->modelClassName = CmsCommList::class;
        $this->modelDefaultAction = 'view';
        $this->generateAccessActions = false;
        $this->permissionName = AdminCmsCommCampaignController::PERMISSION;

        parent::init();
    }

    public function actions()
    {
        $create = [
            'label' => 'Создать список',
            'icon' => 'fas fa-plus',
            'url' => Url::to(['/cmsCommunication/admin-cms-comm-list/create']),
            'options' => ['data-pjax' => 0, 'onclick' => ListUi::windowOnClick(['/cmsCommunication/admin-cms-comm-list/create'])],
        ];
        $stats = [];
        $statsOf = static function (CmsCommList $list) use (&$stats) {
            return $stats[(int)$list->id] ??= RecipientLists::stats($list);
        };

        return ArrayHelper::merge(parent::actions(), [
            // Shown as the tab "Списки" of the campaigns section; its own tab stays hidden.
            'index' => [
                'isVisible' => false,
                'emptyState' => [
                    'title' => 'Списков пока нет',
                    'description' => 'Список — компании, сделки и клиенты для рассылки: добавляйте их из карточек, массовым действием из разделов CRM '
                        .'(по фильтру — например, компании сферы «Плитка» без оплат) или сразу при импорте. Кампания отправляет по одному или нескольким спискам '
                        .'и может исключить другие (например, «Действующие клиенты»).',
                    'icon' => 'fas fa-list-ul',
                    'action' => $create,
                ],
                'pageHeader' => ['title' => 'Списки рассылки', 'actions' => [$create]],
                'filters' => false,
                'grid' => [
                    'on init' => static function (Event $event) {
                        $event->sender->dataProvider->query->andWhere(['cms_site_id' => (int)\Yii::$app->skeeks->site->id]);
                    },
                    'defaultOrder' => ['id' => SORT_DESC],
                    'visibleColumns' => ['actions', 'name', 'members', 'email', 'stopped', 'noEmail', 'created_at', 'created_by'],
                    'columns' => [
                        'name' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsCommunication/admin-cms-comm-list',
                        ],
                        'members' => ['label' => 'Получателей', 'value' => static fn(CmsCommList $l) => $statsOf($l)['members']],
                        'email' => ['label' => 'Можно отправить письмом', 'value' => static fn(CmsCommList $l) => $statsOf($l)['email']],
                        'stopped' => ['label' => 'Отписались / стоп-лист', 'value' => static fn(CmsCommList $l) => $statsOf($l)['stopped']],
                        'noEmail' => ['label' => 'Без email', 'value' => static fn(CmsCommList $l) => $statsOf($l)['noEmail']],
                        'created_at' => ['class' => DateTimeColumnData::class],
                        'created_by' => ['value' => static fn(CmsCommList $l) => $l->createdBy ? $l->createdBy->shortDisplayName : ''],
                    ],
                ],
            ],
            'create' => [
                'fields' => [$this, 'updateFields'],
                'isVisible' => false,
            ],
            'update' => [
                'fields' => [$this, 'updateFields'],
                'name' => 'Название и описание',
            ],
            'view' => [
                'class' => BackendModelAction::class,
                'name' => 'Список',
                'icon' => 'fas fa-list-ul',
                'priority' => 10,
                'callback' => [$this, 'view'],
            ],
            'add' => [
                'class' => BackendModelAction::class,
                'name' => 'Добавить',
                'isVisible' => false,
                'callback' => [$this, 'add'],
            ],
            // "Добавить адреса": pasted emails, phones or lines become contacts of the list (services\AudienceImport::fromLines()).
            'add-addresses' => [
                'class' => BackendModelAction::class,
                'name' => 'Добавить адреса',
                'isVisible' => false,
                'callback' => [$this, 'addAddresses'],
            ],
            'export' => [
                'class' => BackendModelAction::class,
                'name' => 'Выгрузить CSV',
                'isVisible' => false,
                'callback' => [$this, 'export'],
            ],
            // "Добавить в список" of a lead, company or client card: ?record=lead:12.
            'add-record' => [
                'class' => BackendAction::class,
                'name' => 'Добавить в список',
                'isVisible' => false,
                'callback' => [$this, 'addRecord'],
            ],
            'delete-multi' => new \yii\helpers\UnsetArrayValue(),
        ]);
    }

    public function updateFields($action)
    {
        return [
            'name',
            'description' => ['class' => TextareaField::class],
        ];
    }

    /**
     * The standard list of lists as the tab "Списки" of the campaigns section
     * (AdminCmsCommCampaignController), the way core shows a list of another
     * controller in its own tab.
     */
    public static function renderCampaignsTab(BackendAction $tab)
    {
        $created = \Yii::$app->createController('/cmsCommunication/admin-cms-comm-list');
        if (!$created) {
            return $tab->controller->renderContent('');
        }
        /** @var self $controller */
        $controller = $created[0];
        $controller->actionsMap = [
            'index' => ['configKey' => $tab->uniqueId, 'backendShowingKey' => $tab->uniqueId],
        ];
        $index = $controller->actions['index'];
        $index->url = $tab->urlData;
        $index->grid['columns']['actions']['isOpenNewWindow'] = true;

        return $index->run();
    }

    /** The card: counters, members with their history, remove. */
    public function view()
    {
        /** @var CmsCommList $list */
        $list = $this->model;
        $request = \Yii::$app->request;
        $user = \Yii::$app->user->identity;

        if ($request->isPost && in_array($request->post('do'), ['rule-run', 'rule-toggle', 'rule-delete'], true)) {
            $rule = CmsCommListRule::find()->andWhere(['id' => (int)$request->post('rule'), 'cms_comm_list_id' => (int)$list->id])->one();
            if ($rule && $request->post('do') === 'rule-run') {
                $added = (new ListRules())->run($rule);
                $rule->last_error ? \Yii::$app->session->setFlash('error', $rule->last_error)
                    : \Yii::$app->session->setFlash('success', 'Правило пересчитано: найдено '.(int)$rule->last_found.', добавлено '.$added.'.');
            } elseif ($rule && $request->post('do') === 'rule-toggle') {
                $rule->is_active = $rule->is_active ? 0 : 1;
                $rule->save(false, ['is_active', 'updated_at']);
            } elseif ($rule) {
                // The members it added stay: they are the list now.
                $rule->delete();
                \Yii::$app->session->setFlash('success', 'Правило удалено. Записи, которые оно добавило, остались в списке.');
            }

            return $this->redirect(Url::current());
        }
        if ($request->isPost && in_array($request->post('do'), ['remove', 'restore'], true)) {
            $member = CmsCommListMember::find()->andWhere(['id' => (int)$request->post('member'), 'cms_comm_list_id' => (int)$list->id])->one();
            if ($member && $request->post('do') === 'remove' && (new RecipientLists())->remove($member, $user)) {
                \Yii::$app->session->setFlash('success', 'Убран из списка. В истории он остаётся; добавить снова можно в любой момент.');
            } elseif ($member && $request->post('do') === 'restore') {
                // Back in the list: the same check of the record as adding it.
                $record = CrmRecords::find((string)$member->record_type, (int)$member->record_id, $user);
                try {
                    if (!$record) {
                        throw new \DomainException('Запись CRM не найдена или вам не видна.');
                    }
                    (new RecipientLists())->add($list, $record, $user);
                    \Yii::$app->session->setFlash('success', 'Снова в списке.');
                } catch (\DomainException $e) {
                    \Yii::$app->session->setFlash('error', $e->getMessage());
                }
            }

            return $this->redirect(Url::current());
        }

        $state = (string)$request->get('state', '');
        $query = CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$list->id])->with(['addedBy', 'removedBy'])->orderBy(['id' => SORT_DESC]);
        $state === 'removed' ? $query->andWhere(['not', ['removed_at' => null]]) : $query->andWhere(['removed_at' => null]);
        if (isset(CrmRecords::types()[$state])) {
            $query->andWhere(['record_type' => $state]);
        }
        $page = max(1, (int)$request->get('page', 1));
        $total = (int)(clone $query)->count();
        /** @var CmsCommListMember[] $members */
        $members = $query->offset(($page - 1) * 100)->limit(100)->all();
        $byType = [];
        foreach ($members as $member) {
            $byType[$member->record_type][] = (int)$member->record_id;
        }
        $data = [];
        $first = [];
        foreach ($byType as $type => $ids) {
            $data[$type] = RecipientLists::addresses($type, $ids);
            foreach ($data[$type]['addresses'] as $addresses) {
                $first[] = (string)array_key_first($addresses);
            }
        }

        MailingAsset::register($this->view);

        return $this->render('@skeeks/cms/communication/views/list/view', [
            'list' => $list,
            'stats' => RecipientLists::stats($list),
            'members' => $members,
            'data' => $data,
            'stopped' => RecipientLists::stoppedAddresses((int)$list->cms_site_id, $first),
            'total' => $total,
            'page' => $page,
            'state' => $state,
            'rules' => CmsCommListRule::find()->andWhere(['cms_comm_list_id' => (int)$list->id])->with(['user'])->orderBy(['id' => SORT_ASC])->all(),
            'removedCount' => (int)CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$list->id])->andWhere(['not', ['removed_at' => null]])->count(),
        ]);
    }

    /** "Добавить": the choice of a lead, company or client; a post with record=lead:12 adds it. */
    public function add()
    {
        /** @var CmsCommList $list */
        $list = $this->model;
        $request = \Yii::$app->request;
        $user = \Yii::$app->user->identity;
        $error = null;
        // Added by a post only: a link must not change a list.
        $recordRef = $request->isPost ? (string)$request->post('record', '') : '';
        if ($recordRef !== '') {
            $parsed = SendService::parseRef($recordRef);
            $record = $parsed && in_array($parsed[0], RecipientLists::ADDABLE, true) ? CrmRecords::find($parsed[0], $parsed[1], $user) : null;
            try {
                if (!$record) {
                    throw new \DomainException('Запись CRM не найдена или вам не видна.');
                }
                (new RecipientLists())->add($list, $record, $user);
                \Yii::$app->session->setFlash('success', CrmRecords::name($record).' — в списке.');

                return $this->redirect((string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-list/add', 'pk' => (int)$list->id])
                    ->setBackendParamsByCurrentRequest()->url);
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            }
        }

        MailingAsset::register($this->view);

        return $this->render('@skeeks/cms/communication/views/list/add', [
            'list' => $list,
            'error' => $error,
            'recent' => CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$list->id, 'removed_at' => null])
                ->andWhere(['>=', 'created_at', time() - 3600])->andWhere(['added_by' => (int)$user->id])->orderBy(['id' => SORT_DESC])->limit(10)->all(),
        ]);
    }

    /**
     * "Добавить адреса": a line per recipient — an email, a phone or
     * «Ромашка, Иван, info@…, +7 …». The lines become an audience import of
     * the list: the same check by the CRM, the same preview and exclusions;
     * a new address gets a contact card (and a company when the line names
     * one), so a list never holds a bare address.
     */
    public function addAddresses()
    {
        /** @var CmsCommList $list */
        $list = $this->model;
        $request = \Yii::$app->request;
        $error = null;
        $text = (string)$request->post('lines', '');
        if ($request->isPost) {
            try {
                if (!\skeeks\cms\communication\models\CmsCommImport::audienceReady()) {
                    throw new \DomainException('Добавление адресов станет доступно после обновления базы данных.');
                }
                $import = \skeeks\cms\communication\services\AudienceImport::fromLines($list, $text, \Yii::$app->user->identity);

                // The preview at once: the import card shows new, found and excluded addresses before anything is added.
                return $this->redirect((string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-import/view', 'pk' => (int)$import->id, 'preview' => 1])
                    ->setBackendParamsByCurrentRequest()->url);
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            }
        }
        MailingAsset::register($this->view);

        return $this->render('@skeeks/cms/communication/views/list/add-addresses', ['list' => $list, 'error' => $error, 'text' => $text]);
    }

    /** The members as CSV (Excel opens it: UTF-8 with BOM, semicolons). */
    public function export()
    {
        /** @var CmsCommList $list */
        $list = $this->model;
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach (RecipientLists::exportRows($list) as $row) {
            fputcsv($out, $row, ';');
        }
        rewind($out);
        $content = (string)stream_get_contents($out);
        fclose($out);
        $name = 'spisok-'.(int)$list->id.'-'.date('Y-m-d').'.csv';

        return \Yii::$app->response->sendContentAsFile($content, $name, ['mimeType' => 'text/csv; charset=UTF-8']);
    }

    /** "Добавить в список" of a CRM card: the chosen or a new list. */
    public function addRecord()
    {
        $request = \Yii::$app->request;
        $user = \Yii::$app->user->identity;
        $recordRef = (string)$request->get('record', '');
        $parsed = SendService::parseRef($recordRef);
        $record = $parsed && in_array($parsed[0], RecipientLists::ADDABLE, true) ? CrmRecords::find($parsed[0], $parsed[1], $user) : null;
        if (!$record || !RecipientLists::recordType($record)) {
            throw new NotFoundHttpException('Запись CRM не найдена или вам не видна.');
        }
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $form = new ListAddForm();
        $done = null;
        $service = new RecipientLists();
        if ($request->isPost) {
            try {
                if ($request->post('do') === 'remove') {
                    $member = CmsCommListMember::find()->andWhere(['id' => (int)$request->post('member'), 'record_type' => $parsed[0], 'record_id' => $parsed[1]])->one();
                    if ($member && $member->list && (int)$member->list->cms_site_id === $siteId) {
                        $service->remove($member, $user);
                        $done = 'Убран из списка «'.$member->list->name.'».';
                    }
                } elseif ($form->load($request->post()) && $form->validate()) {
                    $list = $form->resolve($user);
                    $service->add($list, $record, $user);
                    $done = 'Добавлен в список «'.$list->name.'».';
                    $form = new ListAddForm();
                }
            } catch (\DomainException $e) {
                $form->addError('list', $e->getMessage());
            }
        }

        MailingAsset::register($this->view);

        return $this->render('@skeeks/cms/communication/views/list/add-record', [
            'record' => $record,
            'recordRef' => $recordRef,
            'form' => $form,
            'done' => $done,
            'memberships' => CmsCommListMember::find()->alias('m')->innerJoinWith(['list l'])->andWhere(['l.cms_site_id' => $siteId,
                'm.record_type' => $parsed[0], 'm.record_id' => $parsed[1], 'm.removed_at' => null])->orderBy(['l.name' => SORT_ASC])->all(),
        ]);
    }

    /** Only lists of the current site open by pk. */
    public function getModel()
    {
        $model = parent::getModel();
        if ($model && (int)$model->cms_site_id !== (int)\Yii::$app->skeeks->site->id) {
            throw new NotFoundHttpException('Список не найден.');
        }

        return $model;
    }
}
