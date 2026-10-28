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
use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\helpers\ListUi;
use skeeks\cms\communication\helpers\MailingUi;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommImport;
use skeeks\cms\communication\models\CmsCommImportRow;
use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\AudienceImport;
use skeeks\cms\communication\services\LeadImport;
use skeeks\cms\communication\services\RecipientLists;
use skeeks\cms\grid\DateTimeColumnData;
use skeeks\cms\models\CmsUser;
use skeeks\cms\rbac\CmsManager;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;

/**
 * Imports of a CSV file or a pasted table in two modes chosen at the upload:
 * - a base into a list of recipients (CmsCommImport::MODE_AUDIENCE,
 *   services\AudienceImport): companies and contacts, no deals — the tab
 *   «Импорт» of «Компании», «Импорт в список» and «Добавить адреса»
 *   of a list;
 * - real requests into deals (MODE_LEADS, services\LeadImport): a deal in
 *   «Неразобранное» or an addition to an open deal — the tab «Импорт» of
 *   «Сделки» (cms/admin-cms-lead/comm-import).
 * The steps: "Загрузить" (create), then in the import card the columns and
 * settings → "Предпросмотр" → "Импортировать"; a finished import shows its
 * report row by row.
 *
 * For employees who work with leads (cms/admin-lead). An employee sees and
 * runs own imports and gives the new leads to himself; an administrator
 * sees all imports of the site and chooses any responsible employee.
 */
class AdminCmsCommImportController extends BackendModelStandartController
{
    /** The list of imports as a tab of a CRM section shows one mode (CmsCommImport::MODE_*); null — all. */
    public ?string $importMode = null;

    public function init()
    {
        $this->name = 'Импорт';
        $this->modelShowAttribute = 'asText';
        $this->modelClassName = CmsCommImport::class;
        $this->modelDefaultAction = 'view';
        $this->generateAccessActions = false;
        $this->permissionName = 'cms/admin-lead';

        parent::init();
    }

    public function actions()
    {
        $isMine = fn() => $this->model && $this->canSee($this->model);
        // Every step runs in the standard backend window over the list (MailingUi).
        $mode = $this->importMode;
        $upload = [
            'label' => $mode === CmsCommImport::MODE_AUDIENCE ? 'Импорт базы в список' : 'Загрузить таблицу',
            'icon' => 'fas fa-file-import',
            'url' => Url::to(['/cmsCommunication/admin-cms-comm-import/create'] + ($mode ? ['mode' => $mode] : [])),
            'options' => ['data-pjax' => 0, 'onclick' => MailingUi::importUploadOnClick($mode ? ['mode' => $mode] : [])],
        ];
        $open = static fn(string $text, CmsCommImport $i) => Html::a($text, Url::to(['/cmsCommunication/admin-cms-comm-import/view', 'pk' => (int)$i->id]),
            ['data-pjax' => 0, 'onclick' => MailingUi::importOnClick((int)$i->id)]);

        return ArrayHelper::merge(parent::actions(), [
            // The list is shown as the tab "Импорт" of the leads section (renderLeadsTab()); its own tab
            // stays hidden, so the upload window has no tabs.
            'index' => [
                'isVisible' => false,
                'emptyState' => [
                    'title' => 'Импортов пока нет',
                    'description' => $mode === CmsCommImport::MODE_AUDIENCE
                        ? 'Загрузите базу для рассылки (CSV или таблицу из Excel): компании и контакты попадут в список рассылки без сделок. Кого CRM уже знает — найдётся сам.'
                        : 'Загрузите таблицу заявок (CSV или скопированную из Excel): каждая строка станет сделкой в «Неразобранном» или дополнит открытую сделку клиента. Холодную базу загружайте в список рассылки.',
                    'icon' => 'fas fa-file-import',
                    'action' => $upload,
                ],
                'pageHeader' => ['title' => $mode === CmsCommImport::MODE_AUDIENCE ? 'Импорт базы в списки рассылки' : 'Загруженные таблицы', 'actions' => [$upload]],
                'filters' => false,
                'grid' => [
                    'on init' => function (Event $event) use ($mode) {
                        $query = $event->sender->dataProvider->query;
                        $query->andWhere(['cms_site_id' => (int)\Yii::$app->skeeks->site->id]);
                        if ($mode && CmsCommImport::getTableSchema()->getColumn('mode')) {
                            $query->andWhere(['mode' => $mode]);
                        }
                        if (!$this->isAdmin()) {
                            $query->andWhere(['created_by' => (int)\Yii::$app->user->id]);
                        }
                    },
                    'defaultOrder' => ['id' => SORT_DESC],
                    'visibleColumns' => $mode === CmsCommImport::MODE_AUDIENCE
                        ? ['actions', 'id', 'file_name', 'list', 'created_at', 'created_by', 'status', 'row_count', 'created_count', 'duplicate_count', 'excluded_count']
                        : ['actions', 'id', 'file_name', 'created_at', 'created_by', 'status', 'row_count', 'created_count', 'duplicate_count', 'skipped_count'],
                    'columns' => [
                        'id' => [
                            'format' => 'raw',
                            'value' => static fn(CmsCommImport $i) => $open('№'.(int)$i->id, $i),
                        ],
                        'file_name' => [
                            'format' => 'raw',
                            'value' => static fn(CmsCommImport $i) => $open(Html::encode($i->file_name ?: 'Вставленная таблица'), $i),
                        ],
                        'created_at' => ['class' => DateTimeColumnData::class],
                        'created_by' => ['value' => static fn(CmsCommImport $i) => $i->createdBy ? $i->createdBy->shortDisplayName : ''],
                        'status' => ['value' => static fn(CmsCommImport $i) => $i->getStatusName()],
                        'list' => ['label' => 'Список', 'value' => static fn(CmsCommImport $i) => $i->list ? $i->list->name : ''],
                        'created_count' => $mode === CmsCommImport::MODE_AUDIENCE ? ['label' => 'Новых'] : [],
                        'duplicate_count' => $mode === CmsCommImport::MODE_AUDIENCE ? ['label' => 'Найдено в CRM'] : ['label' => 'Дополнено сделок'],
                    ],
                ],
            ],
            'create' => [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Загрузить таблицу',
                'icon' => 'fas fa-file-import',
                'isVisible' => false,
                'callback' => [$this, 'upload'],
            ],
            'view' => [
                'class' => BackendModelAction::class,
                'name' => 'Импорт',
                'icon' => 'fas fa-file-import',
                'priority' => 10,
                'accessCallback' => $isMine,
                'callback' => [$this, 'view'],
            ],
            'update' => ['isVisible' => false, 'accessCallback' => static fn() => false],
            'delete' => ['accessCallback' => fn() => $this->model && $this->canSee($this->model) && !in_array($this->model->status,
                [CmsCommImport::STATUS_QUEUED, CmsCommImport::STATUS_RUNNING], true)],
            'delete-multi' => ['isVisible' => false, 'accessCallback' => static fn() => false],
        ]);
    }

    /**
     * The standard list of imports as the tab "Импорт" of the core leads
     * section (CommunicationBootstrap adds the tab through actionsMap): the
     * way core shows a list of another controller in its own tab
     * (AdminCmsCompanyController::documents()). Settings of the grid are
     * kept under the tab's key.
     */
    public static function renderLeadsTab(BackendAction $tab)
    {
        return self::renderTab($tab, CmsCommImport::MODE_LEADS);
    }

    /** The tab "Импорт в список" of the core section "Компании": imports of bases into lists. */
    public static function renderCompaniesTab(BackendAction $tab)
    {
        return self::renderTab($tab, CmsCommImport::MODE_AUDIENCE);
    }

    private static function renderTab(BackendAction $tab, string $mode)
    {
        $created = \Yii::$app->createController('/cmsCommunication/admin-cms-comm-import');
        if (!$created) {
            return $tab->controller->renderContent('');
        }
        /** @var self $controller */
        $controller = $created[0];
        $controller->importMode = $mode;
        $controller->actionsMap = [
            'index' => ['configKey' => $tab->uniqueId, 'backendShowingKey' => $tab->uniqueId],
        ];
        $index = $controller->actions['index'];
        $index->url = $tab->urlData;
        $index->grid['columns']['actions']['isOpenNewWindow'] = true;

        return $index->run();
    }

    /** Step 1: a CSV file or a pasted table. */
    public function upload()
    {
        $request = \Yii::$app->request;
        $error = null;
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $listId = (int)($request->post('list') ?: $request->get('list'));
        $list = $listId && ListUi::canImportAudience() ? CmsCommList::find()->andWhere(['id' => $listId, 'cms_site_id' => $siteId])->one() : null;
        $mode = (string)($request->post('mode') ?: $request->get('mode'));
        if ($list) {
            $mode = CmsCommImport::MODE_AUDIENCE;
        } elseif (!isset(CmsCommImport::modes()[$mode])) {
            $mode = ListUi::canImportAudience() ? CmsCommImport::MODE_AUDIENCE : CmsCommImport::MODE_LEADS;
        }
        if ($request->isPost) {
            $file = UploadedFile::getInstanceByName('file');
            $text = (string)$request->post('table');
            try {
                if ($mode === CmsCommImport::MODE_AUDIENCE && !ListUi::canImportAudience()) {
                    throw new \DomainException('Загрузка базы в список рассылки доступна сотрудникам с доступом к рассылкам.');
                }
                if ($file && $file->error === UPLOAD_ERR_OK) {
                    if ($file->size > 20 * 1024 * 1024) {
                        throw new \DomainException('Файл больше 20 МБ. Разделите его на части.');
                    }
                    if (!in_array(strtolower((string)$file->extension), ['csv', 'txt', 'tsv'], true)) {
                        throw new \DomainException('Нужен файл CSV. В Excel: «Файл → Сохранить как → CSV (разделители — запятые)», или скопируйте таблицу и вставьте её в поле ниже.');
                    }
                    $import = LeadImport::create((int)\Yii::$app->skeeks->site->id, (string)file_get_contents($file->tempName), $file->name, (bool)$request->post('header'));
                } elseif (trim($text) !== '') {
                    $import = LeadImport::create((int)\Yii::$app->skeeks->site->id, $text, null, (bool)$request->post('header'));
                } else {
                    throw new \DomainException('Выберите файл или вставьте таблицу.');
                }
                // New cards of a base are the importing manager's; requests go to «Неразобранное» unless a responsible is chosen.
                $import->executor_id = $mode === CmsCommImport::MODE_AUDIENCE ? (int)\Yii::$app->user->id : null;
                if (CmsCommImport::audienceReady()) {
                    $import->mode = $mode;
                }
                $import->cms_comm_list_id = $list ? (int)$list->id : null;
                $import->save(false, CmsCommImport::audienceReady() ? ['executor_id', 'mode', 'cms_comm_list_id'] : ['executor_id', 'cms_comm_list_id']);

                // The next step in the same place: a backend window stays a window.
                return $this->redirect((string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-import/view', 'pk' => (int)$import->id])
                    ->setBackendParamsByCurrentRequest()->url);
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('@skeeks/cms/communication/views/import/upload', ['error' => $error, 'mode' => $mode, 'list' => $list]);
    }

    /** Steps 2–4 of a draft import, the progress of a running one, the report of a finished one. */
    public function view()
    {
        /** @var CmsCommImport $import */
        $import = $this->model;
        if ($import->isAudience()) {
            return $this->audienceView($import);
        }
        $request = \Yii::$app->request;
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $preview = null;
        $errors = [];

        if ($request->isPost && in_array($import->status, [CmsCommImport::STATUS_DRAFT, CmsCommImport::STATUS_FAILED], true)) {
            // A stopped import goes on with the settings it started with.
            if ($import->status === CmsCommImport::STATUS_DRAFT) {
                $errors = $this->loadSettings($import, $request->post(), $siteId);
                if (!$errors) {
                    $import->save(false, ['mapping', 'executor_id', 'cms_comm_topic_id', 'consent_basis', 'cms_comm_list_id', 'updated_at']);
                }
            }
            if (!$errors && $request->post('start') && $import->listChoice === 'new') {
                // A new list of recipients is made only when the import starts, not at every preview.
                $list = (new \skeeks\cms\communication\services\RecipientLists())->create($siteId,
                    mb_substr('Импорт №'.(int)$import->id.($import->file_name ? ' · '.$import->file_name : ''), 0, 255), \Yii::$app->user->identity);
                $import->cms_comm_list_id = (int)$list->id;
                $import->save(false, ['cms_comm_list_id', 'updated_at']);
            }
            if (!$errors && $request->post('start')) {
                if ((int)$import->row_count - (int)CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id])
                        ->andWhere(['not', ['status' => null]])->count() <= LeadImport::SYNC_LIMIT) {
                    LeadImport::run($import);
                } else {
                    $run = \Yii::$app->jobs->push('comm.import.leads', ['importId' => (int)$import->id], [
                        'siteId' => $siteId,
                        'title' => 'Импорт в сделки №'.(int)$import->id,
                        'triggerType' => 'manual',
                    ]);
                    if ($run) {
                        $import->status = CmsCommImport::STATUS_QUEUED;
                        $import->cms_job_run_id = (int)$run->id;
                        $import->save(false, ['status', 'cms_job_run_id', 'updated_at']);
                    } else {
                        \Yii::$app->session->setFlash('warning', 'Этот импорт уже идёт.');
                    }
                }

                return $this->redirect((string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-import/view', 'pk' => (int)$import->id])
                    ->setBackendParamsByCurrentRequest()->url);
            }
            if (!$errors) {
                $preview = LeadImport::preview($import);
            }
        }

        $status = (string)$request->get('status', '');
        $rowsQuery = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id])->orderBy(['row_num' => SORT_ASC]);
        if (isset(CmsCommImportRow::statuses()[$status])) {
            $rowsQuery->andWhere(['status' => $status]);
        }
        $page = max(1, (int)$request->get('page', 1));

        return $this->render('@skeeks/cms/communication/views/import/view', [
            'import' => $import,
            'preview' => $preview,
            'errors' => $errors,
            'sample' => CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id])->orderBy(['row_num' => SORT_ASC])->limit(3)->all(),
            'executors' => $this->executors(),
            'topics' => CmsCommTopic::forSite($siteId, true),
            'rows' => (clone $rowsQuery)->offset(($page - 1) * 100)->limit(100)->all(),
            'rowsTotal' => (int)$rowsQuery->count(),
            'page' => $page,
            'statusFilter' => $status,
        ]);
    }

    /**
     * A base into a list (services\AudienceImport): the columns, the list,
     * the responsible for new cards, the topic and basis; the preview with
     * new, found and excluded rows and why; the import; the report.
     */
    private function audienceView(CmsCommImport $import)
    {
        $request = \Yii::$app->request;
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $preview = null;
        $errors = [];
        if ($request->isPost && in_array($import->status, [CmsCommImport::STATUS_DRAFT, CmsCommImport::STATUS_FAILED], true)) {
            if ($import->status === CmsCommImport::STATUS_DRAFT) {
                $errors = $this->loadSettings($import, $request->post(), $siteId);
                $choice = (string)$request->post('cms_comm_list_id', '');
                $listName = trim((string)$request->post('list_name', ''));
                $import->listChoice = $choice === 'new' ? 'new' : (isset(CmsCommList::forSite($siteId)[(int)$choice]) ? (string)(int)$choice : '');
                $import->cms_comm_list_id = ctype_digit($import->listChoice) ? (int)$import->listChoice : null;
                if ($import->listChoice === '') {
                    $errors['cms_comm_list_id'] = 'Выберите список или создайте новый: база загружается в список рассылки.';
                }
                if (!$errors) {
                    $import->save(false, ['mapping', 'executor_id', 'cms_comm_topic_id', 'consent_basis', 'cms_comm_list_id', 'updated_at']);
                }
            }
            if (!$errors && $request->post('start') && $import->listChoice === 'new') {
                $list = (new RecipientLists())->create($siteId, mb_substr($listName !== '' ? $listName
                    : ($import->file_name ? pathinfo($import->file_name, PATHINFO_FILENAME) : 'База №'.(int)$import->id), 0, 255), \Yii::$app->user->identity,
                    'Загружен импортом №'.(int)$import->id.'.');
                $import->cms_comm_list_id = (int)$list->id;
                $import->save(false, ['cms_comm_list_id', 'updated_at']);
            }
            if (!$errors && $request->post('start')) {
                $this->start($import, $siteId, 'Импорт в список №'.(int)$import->id, [AudienceImport::class, 'run']);

                return $this->redirect((string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-import/view', 'pk' => (int)$import->id])
                    ->setBackendParamsByCurrentRequest()->url);
            }
            if (!$errors) {
                $preview = AudienceImport::preview($import);
            }
        } elseif ($request->get('preview') && $import->status === CmsCommImport::STATUS_DRAFT && $import->cms_comm_list_id) {
            // "Добавить адреса" of a list: the columns are known, the preview comes at once.
            $preview = AudienceImport::preview($import);
        }

        $status = (string)$request->get('status', '');
        $rowsQuery = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id])->orderBy(['row_num' => SORT_ASC]);
        if (isset(CmsCommImportRow::statuses(CmsCommImport::MODE_AUDIENCE)[$status])) {
            $rowsQuery->andWhere(['status' => $status]);
        }
        $page = max(1, (int)$request->get('page', 1));

        return $this->render('@skeeks/cms/communication/views/import/audience', [
            'import' => $import,
            'preview' => $preview,
            'errors' => $errors,
            'listName' => (string)$request->post('list_name', ''),
            'sample' => CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id])->orderBy(['row_num' => SORT_ASC])->limit(3)->all(),
            'executors' => $this->executors(),
            'topics' => CmsCommTopic::forSite($siteId, true),
            'lists' => CmsCommList::forSite($siteId),
            'rows' => (clone $rowsQuery)->offset(($page - 1) * 100)->limit(100)->all(),
            'rowsTotal' => (int)$rowsQuery->count(),
            'page' => $page,
            'statusFilter' => $status,
        ]);
    }

    /** Runs the import in the request for a small file, otherwise in the job comm.import.leads. */
    private function start(CmsCommImport $import, int $siteId, string $title, callable $run): void
    {
        if ((int)$import->row_count - (int)CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id])
                ->andWhere(['not', ['status' => null]])->count() <= LeadImport::SYNC_LIMIT) {
            $run($import);
            return;
        }
        $run = \Yii::$app->jobs->push('comm.import.leads', ['importId' => (int)$import->id], [
            'siteId' => $siteId,
            'title' => $title,
            'triggerType' => 'manual',
        ]);
        if ($run) {
            $import->status = CmsCommImport::STATUS_QUEUED;
            $import->cms_job_run_id = (int)$run->id;
            $import->save(false, ['status', 'cms_job_run_id', 'updated_at']);
        } else {
            \Yii::$app->session->setFlash('warning', 'Этот импорт уже идёт.');
        }
    }

    /**
     * The columns and settings of the form into the import.
     *
     * @return string[] field => error
     */
    private function loadSettings(CmsCommImport $import, array $post, int $siteId): array
    {
        $errors = [];
        $fields = LeadImport::fields();
        $mapping = [];
        foreach (array_keys($import->getColumnTitles()) as $i) {
            $field = (string)($post['mapping'][$i] ?? '');
            $mapping[$i] = isset($fields[$field]) ? $field : '';
        }
        if (!array_intersect($mapping, ['email', 'phone'])) {
            $errors['mapping'] = $import->isAudience()
                ? 'Укажите колонку с email или телефоном: без них получателю некуда писать.'
                : 'Укажите колонку с email или телефоном: без них по сделке некуда писать.';
        }
        $import->mapping = Json::encode($mapping);

        $executor = (int)($post['executor_id'] ?? 0);
        if ($executor && !isset($this->executors()[$executor])) {
            $errors['executor_id'] = 'Выберите ответственного.';
        } elseif (!$executor && $import->isAudience()) {
            // New cards of a base are the importing manager's.
            $executor = (int)\Yii::$app->user->id;
        }
        // Requests without a responsible go to «Неразобранное».
        $import->executor_id = $executor ?: null;

        $topicId = (int)($post['cms_comm_topic_id'] ?? 0);
        if ($topicId && !CmsCommTopic::find()->andWhere(['id' => $topicId, 'cms_site_id' => $siteId])->exists()) {
            $topicId = 0;
        }
        $import->cms_comm_topic_id = $topicId ?: null;
        $basis = (string)($post['consent_basis'] ?? '');
        $import->consent_basis = isset(CmsCommContactTopic::bases()[$basis]) ? $basis : CmsCommContactTopic::BASIS_COLD;

        // Lists of recipients are of the campaigns right: without it the field is not shown and nothing changes.
        if (!$import->isAudience() && ListUi::canUse() && array_key_exists('cms_comm_list_id', $post)) {
            $choice = (string)$post['cms_comm_list_id'];
            $import->listChoice = $choice === 'new' ? 'new' : (isset(\skeeks\cms\communication\models\CmsCommList::forSite($siteId)[(int)$choice]) ? (string)(int)$choice : '');
            $import->cms_comm_list_id = ctype_digit($import->listChoice) ? (int)$import->listChoice : null;
        }

        return $errors;
    }

    /** @return string[] id => name: the responsible employees the importer may choose */
    private function executors(): array
    {
        $me = \Yii::$app->user->identity;
        if (!$this->isAdmin()) {
            return [(int)$me->id => (string)$me->shortDisplayName];
        }
        $result = [];
        foreach (CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.is_active' => 1])->cmsSite((int)\Yii::$app->skeeks->site->id)->all() as $worker) {
            if (\Yii::$app->authManager->checkAccess($worker->id, 'cms/admin-lead')) {
                $result[(int)$worker->id] = (string)$worker->shortDisplayName;
            }
        }
        asort($result);

        return $result;
    }

    private function isAdmin(): bool
    {
        return \Yii::$app->user->can(CmsManager::PERMISSION_ROLE_ADMIN_ACCESS);
    }

    private function canSee(CmsCommImport $import): bool
    {
        return (int)$import->cms_site_id === (int)\Yii::$app->skeeks->site->id
            && ($this->isAdmin() || (int)$import->created_by === (int)\Yii::$app->user->id);
    }

    public function getModel()
    {
        $model = parent::getModel();
        if ($model && \Yii::$app->controller === $this && !$this->canSee($model)) {
            throw new NotFoundHttpException('Импорт не найден или у вас нет к нему доступа.');
        }

        return $model;
    }
}
