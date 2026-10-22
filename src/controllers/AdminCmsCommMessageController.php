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
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\communication\services\MailDrafts;
use skeeks\cms\communication\services\MailFileFetcher;
use skeeks\cms\communication\services\MailFiles;
use skeeks\cms\communication\services\MailFolders;
use skeeks\cms\communication\services\MailNotifier;
use skeeks\cms\communication\services\MailTemplates;
use skeeks\cms\grid\DateTimeColumnData;
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
        // Every employee may open the section: the list shows only letters they may read.
        // An empty list, not a null name: BackendController::init() turns null into the route,
        // and THasPermissions creates that permission for administrators only.
        $this->permissionNames = [];
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
            // POST ids[], read=1|0: personal read marks of the selected letters.
            'mark' => $hidden([$this, 'mark']),
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
        $ids = array_slice(array_map('intval', (array)$request->post('ids', [])), 0, 500);
        $messages = CmsCommMessage::find()->cmsSite()->forManager()->andWhere([CmsCommMessage::tableName().'.id' => $ids ?: [0]])->all();
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

        $query = $folders->query($folder, $search)->with(['links']);
        if ($unreadOnly) {
            $query->andWhere($folders->unreadCondition());
        }
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

        return $this->render('@skeeks/cms/communication/views/message/mail', [
            'folders' => $folders->folders(),
            'folder' => $folder,
            'search' => $search,
            'unreadOnly' => $unreadOnly,
            'messages' => $messages,
            'readIds' => $readIds,
            'hasMore' => $hasMore,
            'canSend' => (bool)$this->composeService()->sendableAccounts(),
        ]);
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

        return $this->render('@skeeks/cms/communication/views/message/view', [
            'model' => $this->model,
            'canReply' => $canReply,
            // "Ответить" continues an answer started earlier.
            'replyDraft' => $canReply && $this->drafts()->findFor((int)$this->model->id),
            'showImages' => $showImages,
            'alwaysShowImages' => (bool)$settings->showImages,
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
            $message = $service->queue($form);
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
        ];

        return $params['inline']
            ? $this->renderPartial('@skeeks/cms/communication/views/message/_compose', $params)
            : $this->render('@skeeks/cms/communication/views/message/compose', ['params' => $params]);
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
