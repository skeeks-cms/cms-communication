<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\actions\BackendModelAction;
use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\communication\components\MailUserSettings;
use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\helpers\MailHtml;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\communication\services\MailDrafts;
use skeeks\cms\communication\services\MailFileFetcher;
use skeeks\cms\communication\services\MailFiles;
use skeeks\cms\communication\services\MailFolders;
use skeeks\cms\communication\services\MailNotifier;
use skeeks\cms\communication\services\MailboxActions;
use skeeks\cms\communication\services\MailTemplates;
use skeeks\cms\communication\services\MessageSorting;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\services\SendContacts;
use skeeks\cms\communication\services\SendService;
use skeeks\cms\grid\DateTimeColumnData;
use skeeks\cms\rbac\CmsManager;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * "Почта": letters the employee may read (CmsCommMessageQuery::forManager),
 * a letter card and the letter editor ("Написать", "Ответить", "Переслать").
 */
class AdminCmsCommMessageController extends BackendModelStandartController
{
    /** Letters per portion of the list ("Показать ещё" loads the next one). */
    public const PAGE_SIZE = 30;

    public function init()
    {
        $this->name = 'Почта';
        $this->modelShowAttribute = 'subject';
        $this->modelClassName = CmsCommMessage::class;
        $this->generateAccessActions = false;
        // Every employee with access to the administration may open the section: the list shows
        // only letters they may read. Not a null name: BackendController::init() turns null into the
        // route, and THasPermissions creates that permission for administrators only. Not an empty
        // list either: then nothing is checked and a guest gets an error instead of the login page.
        $this->permissionNames = [CmsManager::PERMISSION_ADMIN_ACCESS => 'Доступ к системе администрирования'];
        $this->defaultAction = 'mail';
        // Letter panel header: the subject only; no id/date meta, no delete.
        $this->modelHeader = function () {
            return \skeeks\cms\backend\widgets\BackendModelHeader::widget([
                'model' => $this->model,
                'title' => $this->model->subject ?: '(без темы)',
                'imageSrc' => false,
                'renderDefaultMeta' => false,
                'actions' => false,
                'showBackLink' => false,
            ]);
        };

        parent::init();
    }

    public function actions()
    {
        $hidden = static fn(callable $callback) => [
            'class' => \skeeks\cms\backend\ViewBackendAction::class,
            'isVisible' => false,
            'callback' => $callback,
        ];

        return ArrayHelper::merge(parent::actions(), [
            'mail' => [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Почта',
                'icon' => 'far fa-envelope',
                'priority' => 1,
                'callback' => [$this, 'mail'],
            ],
            'index' => [
                'name' => 'Таблица',
                'priority' => 50,
                'emptyState' => [
                    'title' => 'Писем пока нет',
                    'description' => 'Здесь появятся письма ящиков, к которым у вас есть доступ, и письма ваших лидов, компаний и клиентов.',
                    'icon' => 'far fa-envelope',
                    'action' => ['backendAction' => 'create', 'label' => 'Написать письмо'],
                ],
                'filters' => false,
                'grid' => [
                    'on init' => static function (Event $event) {
                        $query = $event->sender->dataProvider->query;
                        $query->cmsSite()->forManager();
                        // ?account=ID: letters of one mailbox (link from the mailbox card).
                        if ($account = (int)\Yii::$app->request->get('account')) {
                            $query->inAccount($account);
                        }
                    },
                    'defaultOrder' => ['message_at' => SORT_DESC],
                    'visibleColumns' => ['actions', 'message_at', 'direction', 'subject', 'from_address', 'to_list', 'status'],
                    'columns' => [
                        'message_at' => ['class' => DateTimeColumnData::class],
                        'direction' => [
                            'format' => 'raw',
                            'value' => static fn(CmsCommMessage $m) => $m->direction === CmsCommMessage::DIRECTION_IN ? '↘ Входящее' : '↗ Исходящее',
                        ],
                        'subject' => [
                            'format' => 'raw',
                            'value' => static fn(CmsCommMessage $m) => Html::a(Html::encode($m->subject ?: '(без темы)'), ['view', 'pk' => $m->id], ['data-pjax' => 0, 'style' => 'font-weight:600'])
                                .'<div class="sx-gray">'.Html::encode($m->getSnippet(120)).'</div>',
                        ],
                        'from_address' => ['value' => static fn(CmsCommMessage $m) => $m->getFromAsText()],
                        'to_list' => ['value' => static fn(CmsCommMessage $m) => $m->getToAsText()],
                        'status' => ['value' => static fn(CmsCommMessage $m) => $m->getStatusName()],
                    ],
                ],
            ],

            // The letter editor. GET: the form (?inline=1 — only the form, for
            // the reply under an open letter); POST: queue the letter, JSON.
            'create' => [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Написать письмо',
                'icon' => 'far fa-envelope',
                'accessCallback' => fn() => (bool)$this->composeService()->sendableAccounts(),
                'callback' => [$this, 'compose'],
            ],

            'update' => ['isVisible' => false, 'accessCallback' => static fn() => false],
            'delete' => ['isVisible' => false, 'accessCallback' => static fn() => false],
            'delete-multi' => ['isVisible' => false, 'accessCallback' => static fn() => false],

            'view' => [
                'class' => BackendModelAction::class,
                'name' => 'Письмо',
                'icon' => 'far fa-envelope-open',
                'priority' => 10,
                'callback' => [$this, 'letter'],
            ],

            'refresh' => $hidden([$this, 'receiveNow']),
            // POST ids[] (or all=1, list[…]), read=1|0: personal read marks of the selected letters.
            'mark' => $hidden([$this, 'mark']),
            // POST action, folder, ids[] (or all=1, list[…]): move letters in their mailboxes (MailboxActions).
            'move' => $hidden([$this, 'move']),
            // GET ids[] (or all=1, list[…]): server folders for "В папку".
            'server-folders' => $hidden([$this, 'serverFolders']),
            // GET ids[]: labels and their state; POST label_id, on, ids[] (or all=1, list[…]): put on / take off.
            'labels' => $hidden([$this, 'labels']),
            // POST id, name, color (or id, delete=1): a label of the site.
            'label-save' => $hidden([$this, 'labelSave']),
            // GET q: recipients for the "Кому" field.
            'contacts' => $hidden([$this, 'contacts']),
            // POST showImages=1|0: the employee's choice for external images.
            'settings' => $hidden([$this, 'saveSettings']),
            // POST file: a file attached or pasted into the letter editor.
            'upload' => $hidden([$this, 'upload']),
            // GET id (letter file) or sf (own upload): the file, after the access check.
            'file' => $hidden([$this, 'file']),
            // POST pk, kind (images|file|all), fileId: fetch files not kept in the CRM from the mailbox.
            'fetch' => $hidden([$this, 'fetch']),
            // GET pk: which files of the letter have a CRM copy now.
            'fetch-status' => $hidden([$this, 'fetchStatus']),
            // POST the letter form: save it as a draft (autosave), JSON.
            'draft' => $hidden([$this, 'saveDraft']),
            // POST id: remove a draft with its files.
            'draft-delete' => $hidden([$this, 'deleteDraft']),
            // GET pk: the window "Привязать письмо" (standard CRM choice with search);
            // POST pk, type, id, conversation=1|0: link, JSON.
            'link' => [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Привязать письмо',
                'icon' => 'fas fa-link',
                'isVisible' => false,
                'callback' => [$this, 'linkRecord'],
            ],
            // POST pk, code, id, conversation=1|0: unlink, JSON.
            'unlink' => $hidden([$this, 'unlinkRecord']),
            // GET pk: who may be responsible, JSON; POST pk, user_id (empty: nobody): assign, JSON.
            'assign' => $hidden([$this, 'assign']),
            // GET sendable=type:id, record=type:id: the window "Отправить клиенту";
            // POST sendable, channel, recipient, text, subject, account, record: send, JSON.
            'send' => [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Отправить клиенту',
                'icon' => 'far fa-paper-plane',
                'isVisible' => false,
                'accessCallback' => static fn() => (bool)\Yii::$app->communication->sendables,
                'callback' => [$this, 'sendToClient'],
            ],
            // GET type, q, context_model_code/_id: things for "Прикрепить из…"; GET ref: one of them for the form, JSON.
            'sendables' => $hidden([$this, 'sendables']),
            // "Мои настройки почты": notifications, signatures, quick reply templates.
            'personal' => [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Мои настройки почты',
                'icon' => 'fas fa-user-cog',
                'isVisible' => false,
                'callback' => [$this, 'personal'],
            ],
        ]);
    }

    /**
     * Autosave of the letter form. A draft removed meanwhile (sent or deleted
     * in another window) is not created again: the form gets "gone".
     */
    public function saveDraft()
    {
        $this->requirePostJson();
        $service = $this->composeService();
        if (!$service->sendableAccounts()) {
            throw new \yii\web\ForbiddenHttpException('Вам нельзя отправлять письма.');
        }
        $form = new ComposeForm(['service' => $service]);
        $form->load(\Yii::$app->request->post());
        $drafts = $this->drafts();
        $draft = $drafts->find((int)$form->draft_id);
        if ($form->draft_id && !$draft) {
            return ['success' => false, 'gone' => true];
        }
        $draft = $drafts->save($form, $service, $draft);

        return [
            'success' => true,
            'id' => (int)$draft->id,
            'savedAt' => \Yii::$app->formatter->asTime($draft->updated_at, 'php:H:i'),
            'counters' => [MailFolders::DRAFTS => $drafts->count()],
        ];
    }

    public function deleteDraft()
    {
        $this->requirePostJson();
        $drafts = $this->drafts();
        if ($draft = $drafts->find((int)\Yii::$app->request->post('id'))) {
            MailDrafts::delete($draft);
        }

        return ['success' => true, 'counters' => [MailFolders::DRAFTS => $drafts->count()]];
    }

    /**
     * The employee's own mail settings: notifications for each mailbox they
     * read, a signature for each mailbox they send from, quick reply
     * templates (personal and shared by mailboxes they manage).
     */
    public function personal()
    {
        $request = \Yii::$app->request;
        $user = \Yii::$app->user->identity;
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $templates = new MailTemplates($user, $siteId);
        $accesses = \skeeks\cms\communication\models\CmsCommAccountAccess::find()
            ->andWhere(['cms_user_id' => (int)$user->id])
            ->andWhere(['!=', 'read_level', \skeeks\cms\communication\models\CmsCommAccountAccess::READ_NONE])
            ->andWhere(['cms_comm_account_id' => \skeeks\cms\communication\models\CmsCommAccount::find()->select('id')->andWhere(['cms_site_id' => $siteId])])
            ->with('account')->all();

        if ($request->isPost) {
            $section = (string)$request->post('section');
            $done = true;
            if ($section === 'notify') {
                $on = array_map('intval', array_keys(array_filter((array)$request->post('notify', []))));
                foreach ($accesses as $access) {
                    $value = (int)in_array((int)$access->cms_comm_account_id, $on, true);
                    if ((int)$access->is_notify !== $value) {
                        $access->is_notify = $value;
                        $access->save(false, ['is_notify', 'updated_at', 'updated_by']);
                    }
                }
            } elseif ($section === 'signatures') {
                foreach ((array)$request->post('signatures', []) as $accountId => $html) {
                    $done = $templates->saveSignature((int)$accountId, (string)$html) && $done;
                }
            }
            if ($done) {
                \Yii::$app->session->setFlash('success', 'Сохранено');
                return $this->redirect(\yii\helpers\Url::current());
            }
            \Yii::$app->session->setFlash('error', 'Не удалось сохранить');
        }

        return $this->render('@skeeks/cms/communication/views/message/personal', [
            'accesses' => $accesses,
            'accounts' => $templates->sendableAccounts(),
            'signatures' => $templates->signatures(),
            'templates' => $templates->templates(),
            'service' => $templates,
        ]);
    }

    /**
     * "Привязать": the window with the standard CRM choice (GET), linking (POST).
     */
    public function linkRecord()
    {
        $message = $this->requireModel();
        $request = \Yii::$app->request;
        if ($request->isPost) {
            \Yii::$app->response->format = Response::FORMAT_JSON;
            $type = (string)$request->post('type');
            $id = (int)$request->post('id');
            if (!$id) {
                return ['success' => false, 'message' => 'Выберите запись.'];
            }
            try {
                $count = $this->sorting()->link($message, $type, $id, (bool)$request->post('conversation'));
            } catch (\DomainException | \InvalidArgumentException $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }

            return ['success' => true, 'count' => $count, 'message' => 'Письмо привязано'];
        }

        return $this->render('@skeeks/cms/communication/views/message/link', [
            'model' => $message,
            'letters' => (int)CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id])->count(),
            'siteId' => (int)\Yii::$app->skeeks->site->id,
        ]);
    }

    public function unlinkRecord()
    {
        $this->requirePostJson();
        $request = \Yii::$app->request;
        try {
            $count = $this->sorting()->unlink($this->requireModel(), (string)$request->post('code'), (int)$request->post('id'), (bool)$request->post('conversation'));
        } catch (\DomainException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'count' => $count, 'message' => 'Письмо отвязано'];
    }

    public function assign()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $message = $this->requireModel();
        $sorting = $this->sorting();
        $request = \Yii::$app->request;
        if (!$request->isPost) {
            $items = [];
            foreach ($sorting->assignableUsers($message) as $user) {
                $items[] = ['id' => (int)$user->id, 'name' => (string)$user->shortDisplayName, 'me' => (int)$user->id === (int)\Yii::$app->user->id];
            }

            return ['success' => true, 'items' => $items, 'current' => (int)($message->conversation->assigned_user_id ?? 0)];
        }
        $userId = (int)$request->post('user_id') ?: null;
        try {
            $sorting->assign($message, $userId);
        } catch (\DomainException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $folders = $this->mailFolders();

        return [
            'success' => true,
            'message' => $userId ? 'Ответственный назначен' : 'Ответственный снят',
            'counters' => [MailFolders::ASSIGNED => (int)$folders->query(MailFolders::ASSIGNED)->andWhere($folders->unreadCondition())->count()],
        ];
    }

    private function requireModel(): CmsCommMessage
    {
        $message = $this->model;
        if (!$message) {
            throw new NotFoundHttpException('Письмо не найдено.');
        }

        return $message;
    }

    private function sorting(): MessageSorting
    {
        return new MessageSorting(\Yii::$app->user->identity);
    }

    private function drafts(): MailDrafts
    {
        return new MailDrafts(\Yii::$app->user->identity, (int)\Yii::$app->skeeks->site->id);
    }

    /**
     * POST: receive new letters now for the mailboxes this employee reads
     * (the schedule does it every two minutes anyway).
     */
    public function receiveNow()
    {
        $this->requirePostJson();
        $started = 0;
        $connectionIds = [];
        foreach ($this->mailFolders()->accounts() as $account) {
            if ($account->is_active && $account->cms_comm_connection_id) {
                $connectionIds[(int)$account->cms_comm_connection_id] = (int)$account->cms_site_id;
            }
        }
        foreach ($connectionIds as $connectionId => $siteId) {
            if (\Yii::$app->jobs->push('comm.mail.poll', ['connectionId' => $connectionId], ['siteId' => $siteId])) {
                $started++;
            }
        }

        return ['success' => true, 'started' => $started];
    }

    public function mark()
    {
        $this->requirePostJson();
        $request = \Yii::$app->request;
        $messages = $this->chosenMessages();
        $folders = $this->mailFolders();
        foreach ($messages as $message) {
            $request->post('read') ? $folders->markRead($message) : $folders->markUnread($message);
        }

        return ['success' => true, 'count' => count($messages), 'counters' => $this->counters($folders)];
    }

    public function contacts()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        return ['items' => $this->composeService()->suggestRecipients(mb_substr((string)\Yii::$app->request->get('q', ''), 0, 100))];
    }

    public function saveSettings()
    {
        $this->requirePostJson();
        $saved = MailUserSettings::saveForCurrentUser(['showImages' => (bool)\Yii::$app->request->post('showImages')]);

        return ['success' => $saved];
    }

    public function upload()
    {
        $this->requirePostJson();
        if (!$this->composeService()->sendableAccounts()) {
            throw new \yii\web\ForbiddenHttpException('Вам нельзя отправлять письма.');
        }
        $file = \yii\web\UploadedFile::getInstanceByName('file');
        if (!$file || $file->error !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Файл не загрузился: возможно, он слишком большой.'];
        }
        if ($file->size > MailFiles::MAX_SIZE) {
            return ['success' => false, 'message' => 'Файл больше '.(MailFiles::MAX_SIZE / 1024 / 1024).' МБ: почтовые серверы такие письма не принимают.'];
        }
        // Into the temporary upload area: the storage gets it only with a sent letter.
        $upload = MailFiles::storeTemp($file);
        $type = (string)\yii\helpers\FileHelper::getMimeType((string)MailFiles::tempPath($upload['token']));

        return [
            'success' => true,
            'token' => $upload['token'],
            'name' => $upload['name'],
            'size' => $upload['size'],
            'sizeText' => \Yii::$app->formatter->asShortSize($upload['size']),
            'image' => MailFiles::isInlineType($type),
            'url' => MailFiles::uploadUrl($upload['token']),
        ];
    }

    /**
     * A letter file for an employee who may read the letter, or a file this
     * session has just uploaded into the editor (?t=token). Only raster images
     * are shown in the page, by the type detected on the server; everything
     * else is downloaded (no HTML or SVG from our origin).
     */
    public function file()
    {
        $request = \Yii::$app->request;
        $name = null;
        $path = null;
        $type = null;
        if ($id = (int)$request->get('id')) {
            $row = \skeeks\cms\communication\models\CmsCommMessageFile::findOne($id);
            $visible = $row && CmsCommMessage::find()->cmsSite()->forManager()
                ->andWhere([CmsCommMessage::tableName().'.id' => (int)$row->cms_comm_message_id])->exists();
            if ($visible && $row->storageFile) {
                $path = (string)$row->storageFile->rootSrc;
                $type = (string)$row->storageFile->mime_type;
                $name = $row->name;
                // A copy fetched from the mailbox lives cacheDays after its last opening.
                if ($row->is_cached && (int)$row->last_access_at < time() - 3600) {
                    $row->last_access_at = time();
                    $row->save(false, ['last_access_at']);
                }
            }
        } elseif (($token = (string)$request->get('t')) !== '' && ($path = MailFiles::tempPath($token))) {
            $type = (string)\yii\helpers\FileHelper::getMimeType($path);
            $name = MailFiles::tempName($token);
        }
        if (!$path || !is_readable($path)) {
            throw new NotFoundHttpException('Файл не найден или у вас нет к нему доступа.');
        }
        $inline = MailFiles::isInlineType($type) && !$request->get('download');
        \Yii::$app->response->headers->set('X-Content-Type-Options', 'nosniff');
        \Yii::$app->response->headers->set('Cache-Control', 'private, max-age=86400');

        return \Yii::$app->response->sendFile($path, $name ?: 'file', [
            'inline' => $inline,
            'mimeType' => $inline ? $type : 'application/octet-stream',
        ]);
    }

    /**
     * Files of the open letter that the CRM does not keep are fetched from the
     * mailbox by a job (all external I/O goes through cms-job); the page polls
     * fetch-status until they are ready.
     */
    public function fetch()
    {
        $this->requirePostJson();
        $message = $this->model;
        if (!$message) {
            throw new NotFoundHttpException('Письмо не найдено.');
        }
        $kind = (string)\Yii::$app->request->post('kind', MailFileFetcher::KIND_ALL);
        if (!in_array($kind, [MailFileFetcher::KIND_IMAGES, MailFileFetcher::KIND_FILE, MailFileFetcher::KIND_ALL], true)) {
            $kind = MailFileFetcher::KIND_ALL;
        }
        $fileId = (int)\Yii::$app->request->post('fileId');
        if ($kind === MailFileFetcher::KIND_FILE && !$fileId) {
            $kind = MailFileFetcher::KIND_ALL;
        }
        // A new attempt clears the old error.
        \skeeks\cms\communication\models\CmsCommMessageFile::updateAll(['fetch_error' => null],
            array_filter(['cms_comm_message_id' => (int)$message->id, 'id' => $kind === MailFileFetcher::KIND_FILE ? $fileId : null]));
        \Yii::$app->jobs->push('comm.mail.fetch-files',
            array_filter(['messageId' => (int)$message->id, 'kind' => $kind, 'fileId' => $kind === MailFileFetcher::KIND_FILE ? $fileId : null]),
            ['siteId' => (int)$message->cms_site_id]);

        return $this->fetchStatus();
    }

    /** @return array files: id => stored, error, url; ready: nothing is waiting any more */
    public function fetchStatus()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $message = $this->model;
        if (!$message) {
            throw new NotFoundHttpException('Письмо не найдено.');
        }
        $files = [];
        foreach (\skeeks\cms\communication\models\CmsCommMessageFile::find()->andWhere(['cms_comm_message_id' => (int)$message->id])->with('storageFile')->all() as $file) {
            $files[(int)$file->id] = [
                'stored' => $file->isStored(),
                'inline' => (bool)$file->is_inline,
                'error' => $file->fetch_error,
                'url' => \yii\helpers\Url::to([MailFiles::ROUTE_FILE, 'id' => $file->id, 'download' => 1]),
            ];
        }

        // A fetch job of this letter is still queued or running.
        $pending = \skeeks\cms\job\models\CmsJobRun::find()
            ->andWhere(['like', 'dedup_key', 'comm.mail.fetch:'.(int)$message->id.':%', false])
            ->andWhere(['status' => [\skeeks\cms\job\models\CmsJobRun::STATUS_QUEUED, \skeeks\cms\job\models\CmsJobRun::STATUS_RUNNING]])
            ->exists();

        return ['success' => true, 'files' => $files, 'pending' => $pending];
    }

    private function requirePostJson(): void
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        if (!\Yii::$app->request->isPost) {
            throw new MethodNotAllowedHttpException('Используйте POST.');
        }
    }

    private function mailFolders(): MailFolders
    {
        return new MailFolders(\Yii::$app->user->identity, (int)\Yii::$app->skeeks->site->id);
    }

    /** @return array folder key => unread letters */
    private function counters(MailFolders $folders): array
    {
        return ArrayHelper::map($folders->folders(), 'key', 'unread');
    }

    /**
     * The mail client: folders, letters of the folder, the open letter.
     * ?portion=N returns only the rows of the N-th portion as JSON ("Показать ещё").
     */
    public function mail()
    {
        $request = \Yii::$app->request;
        // The mail client is a full screen of its own: no controller tabs above it.
        // The layout draws tabs only from a non-empty getActions(); the _sxb[noa]
        // parameter is not used because backend URLs carry it into every link.
        $this->_actions = [];

        $folders = $this->mailFolders();
        $folder = (string)$request->get('folder', MailFolders::ALL);
        if (!$folders->isKnown($folder)) {
            $folder = MailFolders::ALL;
        }
        $search = mb_substr(trim((string)$request->get('q', '')), 0, 100);
        $unreadOnly = (bool)$request->get('unread');
        $portion = max(1, (int)$request->get('portion', 1));

        if ($folder === MailFolders::DRAFTS) {
            $drafts = $this->drafts()->query($search)->with(['files', 'replyTo', 'forwarded'])->limit(200)->all();
            if ($request->get('portion')) {
                \Yii::$app->response->format = Response::FORMAT_JSON;
                return ['html' => '', 'hasMore' => false];
            }

            return $this->render('@skeeks/cms/communication/views/message/mail', [
                'folders' => $folders->folders(),
                'folder' => $folder,
                'search' => $search,
                'unreadOnly' => false,
                'messages' => [],
                'drafts' => $drafts,
                'readIds' => [],
                'hasMore' => false,
                'canSend' => (bool)$this->composeService()->sendableAccounts(),
            ]);
        }

        $assignee = (string)$request->get('assignee', '');
        $linked = (string)$request->get('linked', '');
        $label = (int)$request->get('label');
        $query = $this->listQuery($folders, $request->get())->with(['links', 'conversation.assignedUser', 'labels', 'mailboxCopies']);
        // Letters older than the last shown one: new letters arriving meanwhile do not shift the list.
        $before = (int)$request->get('before');
        if ($before) {
            $t = CmsCommMessage::tableName();
            $last = (int)$request->get('before_id');
            $query->andWhere(['or', ['<', $t.'.message_at', $before], ['and', [$t.'.message_at' => $before], ['<', $t.'.id', $last]]]);
        } else {
            $query->offset(($portion - 1) * self::PAGE_SIZE);
        }
        $messages = $query->limit(self::PAGE_SIZE + 1)->all();
        $hasMore = count($messages) > self::PAGE_SIZE;
        $messages = array_slice($messages, 0, self::PAGE_SIZE);
        $readIds = $folders->readIds(array_map(static fn($m) => (int)$m->id, $messages));

        if ($request->get('portion')) {
            \Yii::$app->response->format = Response::FORMAT_JSON;
            return [
                'html' => $this->renderPartial('@skeeks/cms/communication/views/message/_rows', ['messages' => $messages, 'readIds' => $readIds]),
                'hasMore' => $hasMore,
            ];
        }

        $actions = $this->mailboxActions();
        $labels = $actions->labels();

        return $this->render('@skeeks/cms/communication/views/message/mail', [
            'folders' => $folders->folders(),
            'folder' => $folder,
            'search' => $search,
            'unreadOnly' => $unreadOnly,
            'messages' => $messages,
            'readIds' => $readIds,
            'hasMore' => $hasMore,
            // "Выбрать все письма папки": how many letters the folder has with these filters.
            'total' => $hasMore ? (int)$this->listQuery($folders, $request->get())->count() : count($messages),
            'canSend' => (bool)$this->composeService()->sendableAccounts(),
            'canManage' => $actions->canManageAny(),
            'labels' => $labels,
            'label' => $label,
            'assignee' => $assignee,
            'linked' => $linked,
            'assignees' => $folders->assignees(),
        ]);
    }

    /**
     * Letters of a folder with the list filters: folder, q (search over the
     * conversation), unread, assignee, linked, label.
     */
    private function listQuery(MailFolders $folders, array $params): \skeeks\cms\communication\models\queries\CmsCommMessageQuery
    {
        $folder = (string)($params['folder'] ?? MailFolders::ALL);
        if (!$folders->isKnown($folder) || $folder === MailFolders::DRAFTS) {
            $folder = MailFolders::ALL;
        }
        $query = $folders->filter($folders->query($folder, mb_substr(trim((string)($params['q'] ?? '')), 0, 100)),
            (string)($params['assignee'] ?? ''), (string)($params['linked'] ?? ''));
        if (!empty($params['unread'])) {
            $query->andWhere($folders->unreadCondition());
        }
        if ($label = (int)($params['label'] ?? 0)) {
            $folders->withLabel($query, $label);
        }

        return $query;
    }

    /**
     * Letters an action applies to: ids[] of chosen letters, or all=1 with
     * the list filters ("Выбрать все письма папки"). Only letters the
     * employee may read; at most MailboxActions::MAX_LETTERS.
     *
     * @return CmsCommMessage[]
     */
    private function chosenMessages(): array
    {
        $request = \Yii::$app->request;
        if ($request->post('all')) {
            $query = $this->listQuery($this->mailFolders(), (array)$request->post('list', []));
        } else {
            $ids = array_slice(array_map('intval', (array)$request->post('ids', [])), 0, MailboxActions::MAX_LETTERS);
            $query = CmsCommMessage::find()->cmsSite()->forManager()->andWhere([CmsCommMessage::tableName().'.id' => $ids ?: [0]]);
        }

        return $query->limit(MailboxActions::MAX_LETTERS)->all();
    }

    private function mailboxActions(): MailboxActions
    {
        return new MailboxActions(\Yii::$app->user->identity, (int)\Yii::$app->skeeks->site->id);
    }

    /**
     * POST action (trash, archive, junk, restore, folder), folder, ids[] or
     * all=1 with list[…]: moves letters in their mailboxes. The screen shows
     * them in the new place at once; a job does it on the server.
     */
    public function move()
    {
        $this->requirePostJson();
        $request = \Yii::$app->request;
        $actions = $this->mailboxActions();
        if (!$actions->canManageAny()) {
            throw new \yii\web\ForbiddenHttpException('У вас нет права «Управление письмами».');
        }
        $action = (string)$request->post('action');
        $messages = $this->chosenMessages();
        try {
            $result = $actions->move($messages, $action, $request->post('folder') !== null ? (string)$request->post('folder') : null);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $done = [
            MailboxActions::TRASH => 'Удалено',
            MailboxActions::ARCHIVE => 'Перенесено в архив',
            MailboxActions::JUNK => 'Перенесено в спам',
            MailboxActions::RESTORE => 'Возвращено',
            MailboxActions::FOLDER => 'Перенесено',
        ][$action] ?? 'Готово';
        $text = $result['moved'] ? $done.': '.self::letters($result['moved']) : 'Ничего не перенесено';
        foreach ($result['skipped'] as $reason => $count) {
            $text .= '. '.self::letters($count).' не перенесено: '.$reason;
        }

        return [
            'success' => (bool)$result['moved'],
            'message' => $text,
            'moved' => $result['moved'],
            'ids' => array_map(static fn($m) => (int)$m->id, $messages),
            'counters' => $this->counters($this->mailFolders()),
        ];
    }

    /** GET ids[] or all=1 with list[…]: server folders for "В папку" (letters of one connection). */
    public function serverFolders()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $request = \Yii::$app->request;
        $actions = $this->mailboxActions();
        if ($request->get('all')) {
            $ids = $this->listQuery($this->mailFolders(), (array)$request->get('list', []))
                ->select(CmsCommMessage::tableName().'.id')->limit(MailboxActions::MAX_LETTERS)->column();
        } else {
            $ids = CmsCommMessage::find()->cmsSite()->forManager()->select(CmsCommMessage::tableName().'.id')
                ->andWhere([CmsCommMessage::tableName().'.id' => array_slice(array_map('intval', (array)$request->get('ids', [])), 0, MailboxActions::MAX_LETTERS) ?: [0]])->column();
        }
        $connections = $actions->connectionsOf(array_map('intval', $ids));
        if (count($connections) !== 1) {
            return ['success' => false, 'message' => $connections
                ? 'Письма из разных почтовых ящиков: выберите письма одного ящика.'
                : 'У писем нет места в почтовом ящике, которым вы управляете.'];
        }
        $items = [];
        foreach ($actions->serverFolders($connections[0]) as $folder => $title) {
            $items[] = ['folder' => $folder, 'title' => $title];
        }

        return ['success' => true, 'items' => $items];
    }

    /**
     * GET ids[]: labels of the site and which of them the letters carry
     * (all, some, none). POST label_id, on=1|0, ids[] or all=1 with list[…]:
     * puts the label on or takes it off.
     */
    public function labels()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $request = \Yii::$app->request;
        $actions = $this->mailboxActions();
        if ($request->isPost) {
            $label = \skeeks\cms\communication\models\CmsCommLabel::findOne(['id' => (int)$request->post('label_id'), 'cms_site_id' => (int)\Yii::$app->skeeks->site->id]);
            if (!$label) {
                throw new NotFoundHttpException('Метка не найдена.');
            }
            $result = $actions->label($this->chosenMessages(), $label, (bool)$request->post('on'));
            $text = $result['changed'] ? ($request->post('on') ? 'Метка поставлена' : 'Метка снята') : 'Метки не изменились';
            foreach ($result['skipped'] as $reason => $count) {
                $text .= '. '.self::letters($count).': '.$reason;
            }

            return ['success' => true, 'message' => $text, 'changed' => $result['changed']];
        }

        $ids = $request->get('all')
            ? $this->listQuery($this->mailFolders(), (array)$request->get('list', []))->select(CmsCommMessage::tableName().'.id')->limit(MailboxActions::MAX_LETTERS)->column()
            : CmsCommMessage::find()->cmsSite()->forManager()->select(CmsCommMessage::tableName().'.id')
                ->andWhere([CmsCommMessage::tableName().'.id' => array_slice(array_map('intval', (array)$request->get('ids', [])), 0, MailboxActions::MAX_LETTERS) ?: [0]])->column();
        $counts = $ids ? (new \yii\db\Query())->select(['cms_comm_label_id', 'n' => 'COUNT(*)'])->from('{{%cms_comm_message_label}}')
            ->andWhere(['cms_comm_message_id' => $ids])->groupBy('cms_comm_label_id')->indexBy('cms_comm_label_id')->column() : [];
        $items = [];
        foreach ($actions->labels() as $label) {
            $n = (int)($counts[$label->id] ?? 0);
            $items[] = ['id' => (int)$label->id, 'name' => $label->name, 'color' => $label->color,
                'state' => $n === 0 ? 'none' : ($n >= count($ids) ? 'all' : 'some')];
        }

        return ['success' => true, 'items' => $items, 'canEdit' => $actions->canEditLabels(),
            'colors' => \skeeks\cms\communication\models\CmsCommLabel::COLORS];
    }

    /** POST id (empty: a new label), name, color; or id, delete=1. */
    public function labelSave()
    {
        $this->requirePostJson();
        $request = \Yii::$app->request;
        if (!$this->mailboxActions()->canEditLabels()) {
            throw new \yii\web\ForbiddenHttpException('Метки меняют сотрудники с правом «Управление письмами».');
        }
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $label = $request->post('id')
            ? \skeeks\cms\communication\models\CmsCommLabel::findOne(['id' => (int)$request->post('id'), 'cms_site_id' => $siteId])
            : new \skeeks\cms\communication\models\CmsCommLabel(['cms_site_id' => $siteId]);
        if (!$label) {
            throw new NotFoundHttpException('Метка не найдена.');
        }
        if ($request->post('delete')) {
            // Keywords on the server go with the next writing of labels of these letters.
            $ids = (new \yii\db\Query())->select('cms_comm_message_id')->from('{{%cms_comm_message_label}}')->andWhere(['cms_comm_label_id' => (int)$label->id])->column();
            $label->delete();
            if ($ids) {
                \skeeks\cms\communication\models\CmsCommMessageMailbox::updateAll(['labels_dirty' => 1], ['and', ['cms_comm_message_id' => $ids], ['not', ['uid' => null]]]);
                foreach ($this->mailboxActions()->connectionsOf(array_map('intval', $ids)) as $connectionId) {
                    MailboxActions::pushApply($connectionId, $siteId);
                }
            }

            return ['success' => true, 'message' => 'Метка удалена'];
        }
        $label->name = (string)$request->post('name');
        $label->color = (string)$request->post('color', $label->color);
        if (!$label->save()) {
            return ['success' => false, 'message' => implode(' ', $label->getFirstErrors())];
        }

        return ['success' => true, 'label' => ['id' => (int)$label->id, 'name' => $label->name, 'color' => $label->color]];
    }

    private static function letters(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        $word = $mod10 === 1 && $mod100 !== 11 ? 'письмо' : ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20) ? 'письма' : 'писем');

        return $n.' '.$word;
    }

    /**
     * An open letter. Opening marks it read for this employee only.
     * ?frame=1 returns only the letter document as JSON (images shown or not).
     */
    public function letter()
    {
        $request = \Yii::$app->request;
        $settings = new MailUserSettings();
        $showImages = $request->get('images') !== null ? (bool)$request->get('images') : (bool)$settings->showImages;

        if ($request->get('frame')) {
            \Yii::$app->response->format = Response::FORMAT_JSON;
            $blocked = 0;
            $doc = self::letterDocument($this->model, $showImages, $blocked);
            return ['doc' => $doc, 'blocked' => $blocked];
        }

        $this->mailFolders()->markRead($this->model);
        MailNotifier::markRead((int)\Yii::$app->user->id, (int)$this->model->id);
        $canReply = (bool)$this->composeService()->sendableAccounts();
        $actions = $this->mailboxActions();

        return $this->render('@skeeks/cms/communication/views/message/view', [
            'model' => $this->model,
            'canReply' => $canReply,
            // "Ответить" continues an answer started earlier.
            'replyDraft' => $canReply && $this->drafts()->findFor((int)$this->model->id),
            'showImages' => $showImages,
            'alwaysShowImages' => (bool)$settings->showImages,
            'created' => $this->sorting()->createdRecords($this->model),
            'createActions' => MailUi::createActions((int)$this->model->id),
            'canManage' => $actions->canManage($this->model),
        ]);
    }

    /**
     * The letter as a document for its sandboxed frame. Letters are untrusted:
     * they keep their own styles (like web mail) but run without scripts and
     * forms; MailHtml also strips active content.
     */
    public static function letterDocument(CmsCommMessage $model, bool $showImages, ?int &$blocked = null): string
    {
        $html = MailHtml::sanitize((string)$model->body_html, $showImages, $blocked, MailFiles::cidMap($model));

        return '<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;padding:0;background:#fff;color:#222;font-family:Arial,sans-serif;font-size:14px;height:auto!important;min-height:0!important;overflow-y:hidden}body{overflow-x:auto}img{max-width:100%;height:auto}</style></head><body>'.$html.'</body></html>';
    }

    /**
     * The letter editor: "Написать" (?to, ?context_model_code/_id),
     * "Ответить" (?reply_to), "Ответить всем" (?reply_to&all=1), "Переслать" (?forward).
     */
    public function compose()
    {
        $request = \Yii::$app->request;
        $service = $this->composeService();
        $form = new ComposeForm(['service' => $service]);

        if ($request->isPost) {
            \Yii::$app->response->format = Response::FORMAT_JSON;
            $form->load($request->post());
            if (!$form->validate()) {
                return ['success' => false, 'errors' => $form->getFirstErrors()];
            }
            try {
                $message = $service->queue($form);
            } catch (SendException $e) {
                // A package refused its document ("Прикрепить из…"): nothing was sent.
                return ['success' => false, 'errors' => $e->errors];
            }
            // The letter is on its way: its draft goes. Files the letter took stay with the letter.
            $drafts = $this->drafts();
            if ($draft = $drafts->find((int)$form->draft_id)) {
                MailDrafts::delete($draft);
            }

            return ['success' => true, 'id' => (int)$message->id, 'message' => 'Письмо отправляется',
                'counters' => [MailFolders::DRAFTS => $drafts->count()]];
        }

        // ?draft=ID opens a draft; "Ответить" / "Переслать" continue a draft of that letter.
        $drafts = $this->drafts();
        $draft = $request->get('draft') ? $drafts->find((int)$request->get('draft')) : null;
        if (!$draft) {
            $service->prefill($form,
                $request->get('to'),
                $request->get('context_model_code'),
                $request->get('context_model_id') ? (int)$request->get('context_model_id') : null,
                $request->get('reply_to') ? (int)$request->get('reply_to') : null,
                $request->get('forward') ? (int)$request->get('forward') : null,
                (bool)$request->get('all')
            );
            if ($form->forward_message_id || $form->reply_to_message_id) {
                $draft = $drafts->findFor((int)($form->forward_message_id ?: $form->reply_to_message_id), (bool)$form->forward_message_id);
            }
        }
        $uploads = [];
        if ($draft) {
            $form->cms_comm_account_id = $form->cms_comm_account_id ?: $service->defaultAccountId();
            $uploads = $drafts->fill($form, $draft);
        }
        $templates = new MailTemplates(\Yii::$app->user->identity);
        $params = [
            'uploads' => $uploads,
            'signatures' => $templates->signatures(),
            'templates' => $templates->templatesForForm(),
            'form' => $form,
            'accounts' => $service->sendableAccounts(),
            'contextRecord' => $form->context_model_code && $form->context_model_id
                ? $service->contextRecord($form->context_model_code, (int)$form->context_model_id) : null,
            'original' => $form->reply_to_message_id ? $service->visibleMessage((int)$form->reply_to_message_id)
                : ($form->forward_message_id ? $service->visibleMessage((int)$form->forward_message_id) : null),
            'inline' => (bool)$request->get('inline'),
            'files' => $service->formFiles($form),
            'sendables' => $service->formSendables($form),
        ];

        return $params['inline']
            ? $this->renderPartial('@skeeks/cms/communication/views/message/_compose', $params)
            : $this->render('@skeeks/cms/communication/views/message/compose', ['params' => $params]);
    }

    /**
     * "Отправить клиенту": the client's contacts, the channels with their
     * forms (mail is the letter form with the sendable put in, it posts to
     * "create"); POST sends through a channel (SendService).
     */
    public function sendToClient()
    {
        $request = \Yii::$app->request;
        $service = new SendService(\Yii::$app->user->identity);

        if ($request->isPost) {
            \Yii::$app->response->format = Response::FORMAT_JSON;
            try {
                $send = $service->request((string)$request->post('sendable'), (array)$request->post());
                $message = $service->send($send);
            } catch (SendException $e) {
                return ['success' => false, 'errors' => $e->errors];
            }
            $channel = \Yii::$app->communication->getChannel($send->channel);

            return ['success' => true, 'id' => (int)$message->id, 'message' => ($channel ? $channel->title() : 'Сообщение').' отправляется'];
        }

        try {
            $send = $service->request((string)$request->get('sendable'), ['record' => (string)$request->get('record')]);
        } catch (SendException $e) {
            throw new NotFoundHttpException($e->getMessage());
        }
        $contacts = $service->contacts($send->sendable, $send->record);
        $channels = [];
        foreach (\Yii::$app->communication->getChannels() as $id => $channel) {
            $reason = $channel->unavailableReason(\Yii::$app->user->identity, $send->siteId);
            $send->channel = $id;
            $channels[$id] = [
                'channel' => $channel,
                'reason' => $reason,
                'contacts' => SendContacts::ofKind($contacts, $channel->contactKind()),
                'form' => $reason ? '' : $channel->renderForm($send, SendContacts::ofKind($contacts, $channel->contactKind())),
            ];
        }
        $send->channel = '';

        return $this->render('@skeeks/cms/communication/views/message/send', [
            'send' => $send,
            'contacts' => $contacts,
            'channels' => $channels,
            'active' => (string)$request->get('channel'),
        ]);
    }

    /**
     * "Прикрепить из…": GET type, q — things of that kind the employee may
     * send (those of the card the letter is written from first); GET ref —
     * one thing as the form shows it (title, its link for the text, files).
     */
    public function sendables()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $request = \Yii::$app->request;
        $user = \Yii::$app->user->identity;
        if ($ref = (string)$request->get('ref')) {
            $form = new \skeeks\cms\communication\forms\ComposeForm(['sendables' => [$ref]]);
            $item = $this->composeService()->formSendables($form)[0] ?? null;
            if (!$item) {
                return ['success' => false, 'message' => 'Документ не найден или у вас нет к нему доступа.'];
            }

            return ['success' => true, 'item' => $item, 'chip' => MailUi::sendableChip($item, \yii\helpers\Html::getInputName($form, 'sendables'))];
        }

        $source = \Yii::$app->communication->getSendableSource((string)$request->get('type'));
        if (!$source) {
            return ['success' => false, 'items' => []];
        }
        $record = null;
        if ($request->get('context_model_code') && $request->get('context_model_id')) {
            $record = $this->composeService()->contextRecord((string)$request->get('context_model_code'), (int)$request->get('context_model_id'));
        }
        $items = $source->suggest($user, mb_substr(trim((string)$request->get('q')), 0, 100), $record, 20);

        return ['success' => true, 'items' => array_map(static fn($i) => [
            'ref' => $request->get('type').':'.(int)$i['id'],
            'title' => (string)$i['title'],
            'hint' => (string)($i['hint'] ?? ''),
        ], $items)];
    }

    public function getModel()
    {
        if (\Yii::$app->controller !== $this) {
            return null;
        }
        if ($this->_model === null && ($pk = \Yii::$app->request->get($this->requestPkParamName))) {
            $this->_model = CmsCommMessage::find()->cmsSite()->forManager()
                ->andWhere([CmsCommMessage::tableName().'.id' => (int)$pk])
                ->one();
            if (!$this->_model) {
                throw new NotFoundHttpException('Письмо не найдено или у вас нет к нему доступа.');
            }
        }

        return $this->_model;
    }

    private ?MailComposeService $_composeService = null;

    public function composeService(): MailComposeService
    {
        if ($this->_composeService === null) {
            $this->_composeService = new MailComposeService(\Yii::$app->user->identity);
        }

        return $this->_composeService;
    }
}
