<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\actions\BackendModelAction;
use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\chat\ChatAccess;
use skeeks\cms\communication\chat\ChatDialogs;
use skeeks\cms\communication\chat\ChatFolders;
use skeeks\cms\communication\chat\ChatIdentities;
use skeeks\cms\communication\chat\ChatLinks;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\send\SendFile;
use skeeks\cms\communication\services\MailFiles;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\CmsUser;
use skeeks\cms\rbac\CmsManager;
use yii\helpers\ArrayHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * "Чаты": dialogs and group chats of the site's bots (Telegram, MAX) the
 * employee sees (chat\ChatAccess). The list of chats on the left, the open
 * chat on the right: messages, "Взять диалог", "Передать", "Закрыть", the
 * reply with files. New messages come by polling while the page is open.
 * The same chat opens in the backend panel from a notification (`view`).
 */
class AdminCmsCommChatController extends BackendModelStandartController
{
    /** Chats per list; messages per chat page. */
    public const LIST_SIZE = 50;
    public const MESSAGES = 60;

    public function init()
    {
        $this->name = 'Чаты';
        $this->modelShowAttribute = 'title';
        $this->modelClassName = CmsCommConversation::class;
        $this->generateAccessActions = false;
        // As "Почта": every employee with admin access; the list shows only chats they may read.
        $this->permissionNames = [CmsManager::PERMISSION_ADMIN_ACCESS => 'Доступ к системе администрирования'];
        $this->defaultAction = 'chats';
        $this->modelHeader = function () {
            return \skeeks\cms\backend\widgets\BackendModelHeader::widget([
                'model' => $this->model,
                'title' => $this->model->asText(),
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
        $actions = parent::actions();
        unset($actions['create'], $actions['update'], $actions['delete'], $actions['index']);
        $hidden = static fn(callable $callback) => ['class' => \skeeks\cms\backend\ViewBackendAction::class, 'isVisible' => false, 'callback' => $callback];
        $hiddenModel = static fn(callable $callback) => ['class' => BackendModelAction::class, 'isVisible' => false, 'callback' => $callback];

        return ArrayHelper::merge($actions, [
            'chats' => [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Чаты',
                'icon' => 'far fa-comments',
                'priority' => 1,
                'callback' => [$this, 'chats'],
            ],
            // The chat in the backend panel (a notification opens it).
            'view' => [
                'class' => BackendModelAction::class,
                'name' => 'Чат',
                'icon' => 'far fa-comments',
                'priority' => 1,
                'callback' => [$this, 'view'],
            ],
            'list' => $hidden([$this, 'listJson']),
            'pane' => $hiddenModel(fn() => $this->renderPane($this->model)),
            'messages' => $hiddenModel([$this, 'messagesJson']),
            'send' => $hiddenModel([$this, 'send']),
            'dialog' => $hiddenModel([$this, 'dialog']),
            'file' => $hidden([$this, 'file']),
            // The picture of a chat (a group icon, the person's photo): only for a chat the employee sees.
            'photo' => $hiddenModel([$this, 'photo']),
            // ?pk=<chat>&identity=<id>: the photo of a person of this chat.
            'person-photo' => $hiddenModel([$this, 'personPhoto']),
            // "Привязать…": GET — the window, POST — link the chat (or its person) to a record, JSON.
            'link' => [
                'class' => BackendModelAction::class,
                'name' => 'Привязать чат',
                'icon' => 'fas fa-link',
                'isVisible' => false,
                'callback' => [$this, 'linkRecord'],
            ],
            'unlink' => $hiddenModel([$this, 'unlinkRecord']),
            // "Пригласить в бота" from a CRM card: ?record=type:id — the link that opens the bot.
            'invite' => [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Пригласить в бота',
                'icon' => 'far fa-comments',
                'isVisible' => false,
                'callback' => [$this, 'invite'],
            ],
        ]);
    }

    /**
     * GET: the window "Привязать" (?person=1 — the person of a private chat).
     * POST type, id, person: links and answers JSON.
     */
    public function linkRecord()
    {
        $request = \Yii::$app->request;
        /** @var CmsCommConversation $conversation */
        $conversation = $this->model;
        $person = (bool)$request->get('person', $request->post('person'));
        $identity = $person ? ChatIdentities::ofChat($conversation) : null;
        if (!$request->isPost) {
            return $this->render('@skeeks/cms/communication/views/chat/link', [
                'conversation' => $conversation,
                'identity' => $identity,
                'siteId' => $this->siteId(),
            ]);
        }
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $type = (string)$request->post('type');
        $id = (int)$request->post('id');
        $user = \Yii::$app->user->identity;
        try {
            if ($identity) {
                if (!in_array($type, [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT], true)) {
                    throw new \DomainException('Собеседника можно привязать к лиду, компании или клиенту.');
                }
                $record = CrmRecords::find($type, $id, $user);
                if (!$record || ($type === CrmRecords::CLIENT && (int)$record->is_worker === 1)) {
                    throw new \DomainException('Запись не найдена или у вас нет к ней доступа.');
                }
                ChatIdentities::linkTo($identity, $type, $id, CmsCommIdentity::LINK_MANUAL, (int)$user->id);
            } else {
                $record = (new ChatLinks($user))->link($conversation, $type, $id);
            }
        } catch (\DomainException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Привязано: '.CrmRecords::name($record), 'links' => $this->renderLinks($conversation)];
    }

    /** POST code, id: the chat is no longer the record's (its feed entries go too). */
    public function unlinkRecord()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $request = \Yii::$app->request;
        /** @var CmsCommConversation $conversation */
        $conversation = $this->model;
        if (!$request->isPost) {
            return ['success' => false, 'message' => 'Нужен POST.'];
        }
        $code = (string)$request->post('code');
        $id = (int)$request->post('id');
        try {
            $links = new ChatLinks(\Yii::$app->user->identity);
            $links->unlink($conversation, $code, $id);
            // The person stays linked to a client only while their chat is: unlinking the client unlinks the person.
            $identity = ChatIdentities::ofChat($conversation);
            if ($identity && $code === CmsUser::class && (int)$identity->cms_user_id === $id) {
                ChatIdentities::unlink($identity, $links);
            }
        } catch (\DomainException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'links' => $this->renderLinks($conversation)];
    }

    /** "Пригласить в бота": a one-time link for a lead, company or client (?record=type:id, &bot=connection id). */
    public function invite()
    {
        $request = \Yii::$app->request;
        $user = \Yii::$app->user->identity;
        [$type, $id] = array_pad(explode(':', (string)$request->get('record'), 2), 2, '');
        $record = in_array($type, [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT], true) ? CrmRecords::find($type, (int)$id, $user) : null;
        if (!$record) {
            throw new NotFoundHttpException('Запись не найдена.');
        }
        $bots = [];
        foreach ($this->access()->bots($this->siteId()) as $account) {
            $row = $this->access()->rowOfAccount((int)$account->id);
            if ($row && $row->send_level !== CmsCommAccountAccess::SEND_NONE && $account->connection && (int)$account->connection->is_active) {
                $bots[(int)$account->cms_comm_connection_id] = $account;
            }
        }
        $result = null;
        $botId = (int)$request->get('bot', $bots ? array_key_first($bots) : 0);
        if ($request->get('make') && isset($bots[$botId])) {
            $result = ChatIdentities::invite($bots[$botId]->connection, $type, (int)$record->primaryKey, (int)$user->id);
        }

        return $this->render('@skeeks/cms/communication/views/chat/invite', [
            'record' => $record,
            'type' => $type,
            'bots' => $bots,
            'botId' => $botId,
            'result' => $result,
        ]);
    }

    public function renderLinks(CmsCommConversation $conversation): string
    {
        $user = \Yii::$app->user->identity;
        $links = new ChatLinks($user);
        $identity = ChatIdentities::ofChat($conversation);

        return $this->renderPartial('@skeeks/cms/communication/views/chat/_links', [
            'conversation' => $conversation,
            'records' => $links->records($conversation),
            'suggestions' => $links->suggest($conversation),
            'identity' => $identity,
            'canAskPhone' => $identity && !$identity->phone && !$identity->is_blocked && $this->access()->canSend($conversation),
        ]);
    }

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // A chat the employee may not read does not exist for them.
        if ($action instanceof BackendModelAction && $this->model && !$this->access()->canRead($this->model)) {
            throw new NotFoundHttpException('Чат не найден.');
        }

        return true;
    }

    public function chats()
    {
        // A full screen of its own: no controller tabs (see "Почта").
        $this->_actions = [];
        $request = \Yii::$app->request;
        $folders = $this->folders();
        $folder = (string)$request->get('folder', ChatFolders::NEW);
        $folderList = $folders->folders();
        if (!in_array($folder, array_column($folderList, 'key'), true)) {
            $folder = ChatFolders::ALL;
        }
        $search = mb_substr(trim((string)$request->get('q', '')), 0, 100);
        $current = null;
        if ($id = (int)$request->get('id')) {
            $current = $this->access()->query($this->siteId())->andWhere([CmsCommConversation::tableName().'.id' => $id])->one();
        }

        return $this->render('@skeeks/cms/communication/views/chat/chats', [
            'folders' => $folderList,
            'folder' => $folder,
            'search' => $search,
            'listHtml' => $this->renderList($folders, $folder, $search, $current ? (int)$current->id : 0),
            'paneHtml' => $current ? $this->renderPane($current) : '',
            'hasBots' => (bool)$this->access()->bots($this->siteId()),
        ]);
    }

    public function view()
    {
        return $this->render('@skeeks/cms/communication/views/chat/panel', ['paneHtml' => $this->renderPane($this->model)]);
    }

    public function listJson()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $request = \Yii::$app->request;
        $folders = $this->folders();
        $folder = (string)$request->get('folder', ChatFolders::ALL);

        return [
            'html' => $this->renderList($folders, $folder, mb_substr(trim((string)$request->get('q', '')), 0, 100), (int)$request->get('id')),
            'folders' => array_column($folders->folders(), 'unread', 'key'),
        ];
    }

    /** New messages after `after` and the current state of recent outgoing ones. */
    public function messagesJson()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        /** @var CmsCommConversation $conversation */
        $conversation = $this->model;
        if ($before = (int)\Yii::$app->request->get('before')) {
            // Older messages, when the employee scrolls up to the top of the chat.
            $older = array_reverse(CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->andWhere(['<', 'id', $before])
                ->with(['identity', 'files', 'sentBy'])->orderBy(['id' => SORT_DESC])->limit(self::MESSAGES + 1)->all());
            $more = count($older) > self::MESSAGES;
            if ($more) {
                array_shift($older);
            }

            return [
                'html' => $older ? $this->renderPartial('@skeeks/cms/communication/views/chat/_messages', ['messages' => $older, 'conversation' => $conversation]) : '',
                'more' => $more,
            ];
        }
        $after = (int)\Yii::$app->request->get('after');
        $messages = CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->andWhere(['>', 'id', $after])
            ->with(['identity', 'files', 'sentBy'])->orderBy(['id' => SORT_ASC])->limit(self::MESSAGES)->all();
        // Outgoing messages still on their way, and files still downloading, are drawn again.
        $changing = CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->andWhere(['<=', 'id', $after])
            ->andWhere(['>', 'updated_at', time() - 600])->with(['identity', 'files', 'sentBy'])->orderBy(['id' => SORT_DESC])->limit(30)->all();
        if ($messages) {
            $this->dialogs()->markRead($conversation);
        }
        $conversation->refresh();

        return [
            'html' => $messages ? $this->renderPartial('@skeeks/cms/communication/views/chat/_messages', ['messages' => $messages, 'conversation' => $conversation]) : '',
            'replace' => array_map(fn(CmsCommMessage $m) => [
                'id' => (int)$m->id,
                'html' => $this->renderPartial('@skeeks/cms/communication/views/chat/_messages', ['messages' => [$m], 'conversation' => $conversation]),
            ], $changing),
            'last' => $messages ? (int)end($messages)->id : $after,
            'bar' => $this->renderPartial('@skeeks/cms/communication/views/chat/_bar', $this->barParams($conversation)),
            'links' => $this->renderLinks($conversation),
        ];
    }

    /** POST: the reply from the CRM, with files. */
    public function send()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $request = \Yii::$app->request;
        if (!$request->isPost) {
            return ['success' => false, 'error' => 'Нужен POST.'];
        }
        /** @var CmsCommConversation $conversation */
        $conversation = $this->model;
        if (!$this->access()->canSend($conversation)) {
            return ['success' => false, 'error' => 'Писать в этот чат вам нельзя: нужен доступ к боту на отправку.'];
        }
        $files = [];
        foreach (UploadedFile::getInstancesByName('files') as $upload) {
            if ($upload->error !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'Файл «'.$upload->name.'» не загрузился.'];
            }
            if ($upload->size > MailFiles::MAX_SIZE) {
                return ['success' => false, 'error' => 'Файл «'.$upload->name.'» больше 25 МБ.'];
            }
            $files[] = SendFile::path($upload->tempName, $upload->name, $upload->type ?: null);
        }
        $replyTo = null;
        if ($replyId = (int)$request->post('reply_to')) {
            $replyTo = CmsCommMessage::find()->andWhere(['id' => $replyId, 'cms_comm_conversation_id' => (int)$conversation->id])->one();
        }
        try {
            $message = BotSend::queue($conversation, (string)$request->post('text', ''), [
                'user' => \Yii::$app->user->identity,
                'files' => $files,
                'replyTo' => $replyTo,
            ]);
        } catch (SendException $e) {
            return ['success' => false, 'error' => implode(' ', array_map('strval', (array)$e->errors))];
        }
        $this->dialogs()->markRead($conversation);

        return ['success' => true, 'id' => (int)$message->id];
    }

    /** POST: take, transfer (user_id), close, reopen. */
    public function dialog()
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $request = \Yii::$app->request;
        /** @var CmsCommConversation $conversation */
        $conversation = $this->model;
        if (!$request->isPost) {
            return ['success' => false, 'error' => 'Нужен POST.'];
        }
        $dialogs = $this->dialogs();
        try {
            switch ((string)$request->post('do')) {
                case 'take':
                    $dialogs->take($conversation);
                    break;
                case 'transfer':
                    $userId = (int)$request->post('user_id');
                    $dialogs->transfer($conversation, $userId ?: null);
                    break;
                case 'close':
                    $dialogs->close($conversation);
                    break;
                case 'reopen':
                    $dialogs->reopen($conversation);
                    break;
                case 'ask-phone':
                    // A message with the messenger's own "share my phone" button: the reply carries the verified phone.
                    if (!$this->access()->canSend($conversation) || $conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE) {
                        throw new \DomainException('Попросить номер можно только в личном диалоге, куда вам можно писать.');
                    }
                    try {
                        BotSend::queue($conversation, 'Чтобы мы узнали вас, нажмите кнопку «Поделиться номером» ниже.', [
                            'user' => \Yii::$app->user->identity,
                            'sign' => false,
                            'buttons' => [[['text' => 'Поделиться номером', 'contact' => true]]],
                        ]);
                    } catch (SendException $e) {
                        throw new \DomainException(implode(' ', array_map('strval', (array)$e->errors)));
                    }
                    break;
                case 'link-to':
                    $record = (new ChatLinks(\Yii::$app->user->identity))->link($conversation, (string)$request->post('type'), (int)$request->post('id'));
                    break;
                case 'worker':
                    // "Это наш сотрудник": a member of the chat is an employee, their messages are ours.
                    if (!$this->access()->canSend($conversation)) {
                        throw new \DomainException('Отмечать сотрудников может тот, кому можно писать в этот чат.');
                    }
                    $identityId = (int)$request->post('identity');
                    $member = \skeeks\cms\communication\models\CmsCommParticipant::find()
                        ->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'cms_comm_identity_id' => $identityId])->exists()
                        || CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'cms_comm_identity_id' => $identityId])->exists();
                    $identity = $member ? CmsCommIdentity::findOne($identityId) : null;
                    if (!$identity) {
                        throw new \DomainException('Этого человека нет в чате.');
                    }
                    ChatIdentities::markWorker($identity, (int)$request->post('user_id') ?: null, (int)\Yii::$app->user->id);
                    break;
                case 'task-notify':
                    if (!$this->access()->canSend($conversation)) {
                        throw new \DomainException('Менять настройку может тот, кому можно писать в этот чат.');
                    }
                    $conversation->updateAttributes(['task_notify' => (int)(bool)$request->post('value')]);
                    break;
                default:
                    return ['success' => false, 'error' => 'Неизвестное действие.'];
            }
        } catch (\DomainException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        $conversation->refresh();

        return [
            'success' => true,
            'bar' => $this->renderPartial('@skeeks/cms/communication/views/chat/_bar', $this->barParams($conversation)),
            'links' => $this->renderLinks($conversation),
            'info' => $this->renderInfo($conversation),
        ];
    }

    /** The side panel of a chat: members, "Это наш сотрудник", the task setting. */
    public function renderInfo(CmsCommConversation $conversation): string
    {
        $canSend = $this->access()->canSend($conversation);
        $workers = [];
        if ($canSend) {
            foreach (CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.is_active' => 1])->all() as $user) {
                $workers[(int)$user->id] = (string)$user->shortDisplayName;
            }
            asort($workers);
        }

        return $this->renderPartial('@skeeks/cms/communication/views/chat/_info', [
            'conversation' => $conversation,
            'canEdit' => $canSend,
            'workers' => $workers,
        ]);
    }

    public function photo()
    {
        return $this->sendPhoto(\skeeks\cms\communication\bot\BotChats::photo($this->model));
    }

    /** The photo of a person who wrote in this chat or is its member (the chat is readable: beforeAction). */
    public function personPhoto()
    {
        /** @var CmsCommConversation $conversation */
        $conversation = $this->model;
        $id = (int)\Yii::$app->request->get('identity');
        $belongs = CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'cms_comm_identity_id' => $id])->exists()
            || \skeeks\cms\communication\models\CmsCommParticipant::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'cms_comm_identity_id' => $id])->exists();
        $identity = $belongs ? CmsCommIdentity::findOne($id) : null;

        return $this->sendPhoto($identity ? \skeeks\cms\communication\bot\BotChats::identityPhotoFile($identity) : null);
    }

    private function sendPhoto(?CmsStorageFile $storage)
    {
        if (!$storage) {
            throw new NotFoundHttpException('Фото нет.');
        }
        $response = \Yii::$app->response->sendFile((string)$storage->rootSrc, 'chat.'.($storage->extension ?: 'jpg'), [
            'mimeType' => (string)($storage->mime_type ?: 'image/jpeg'),
            'inline' => true,
        ]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // The address changes with the picture (?v=), so the browser may keep it.
        $response->headers->set('Cache-Control', 'private, max-age=86400');

        return $response;
    }

    /** A file of a chat message: only from a chat the employee sees; images inline, the rest as a download. */
    public function file()
    {
        $file = CmsCommMessageFile::findOne((int)\Yii::$app->request->get('id'));
        $message = $file ? $file->message : null;
        $conversation = $message ? $message->conversation : null;
        if (!$conversation || !$conversation->isChat() || !$this->access()->canRead($conversation)) {
            throw new NotFoundHttpException('Файл не найден.');
        }
        $storage = $file->storage_file_id ? CmsStorageFile::findOne((int)$file->storage_file_id) : null;
        if (!$storage || !is_file((string)$storage->rootSrc)) {
            throw new NotFoundHttpException($file->fetch_error ?: 'Файл ещё загружается из мессенджера.');
        }
        $inline = MailFiles::isInlineType($storage->mime_type ? (string)$storage->mime_type : null);
        $response = \Yii::$app->response->sendFile((string)$storage->rootSrc, (string)$file->name, [
            'mimeType' => $inline ? (string)$storage->mime_type : 'application/octet-stream',
            'inline' => $inline,
        ]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function renderPane(CmsCommConversation $conversation): string
    {
        $messages = CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])
            ->with(['identity', 'files', 'sentBy'])->orderBy(['id' => SORT_DESC])->limit(self::MESSAGES)->all();
        $messages = array_reverse($messages);
        $this->dialogs()->markRead($conversation);

        return $this->renderPartial('@skeeks/cms/communication/views/chat/_pane', [
            'conversation' => $conversation,
            'messages' => $messages,
            'more' => count($messages) === self::MESSAGES,
            'canSend' => $this->access()->canSend($conversation),
            'bar' => $this->barParams($conversation),
            'linksHtml' => $this->renderLinks($conversation),
            'infoHtml' => $this->renderInfo($conversation),
        ]);
    }

    private function renderList(ChatFolders $folders, string $folder, string $search, int $currentId): string
    {
        $conversations = $folders->query($folder, $search)->with(['assignedUser', 'connection'])->limit(self::LIST_SIZE)->all();
        $ids = array_map(static fn($c) => (int)$c->id, $conversations);
        $last = [];
        if ($ids) {
            $lastIds = CmsCommMessage::find()->select(['MAX(id)'])->andWhere(['cms_comm_conversation_id' => $ids])->groupBy('cms_comm_conversation_id')->column();
            foreach (CmsCommMessage::find()->andWhere(['id' => $lastIds ?: [0]])->all() as $m) {
                $last[(int)$m->cms_comm_conversation_id] = $m;
            }
        }

        return $this->renderPartial('@skeeks/cms/communication/views/chat/_list', [
            'conversations' => $conversations,
            'last' => $last,
            'unread' => $folders->unreadCounts($ids),
            'currentId' => $currentId,
            'search' => $search,
            'folder' => $folder,
        ]);
    }

    /** What the dialog bar needs: who took it, who it can be passed to, may the employee act. */
    private function barParams(CmsCommConversation $conversation): array
    {
        $accountId = (int)CmsCommAccount::find()->select('id')->andWhere(['cms_comm_connection_id' => (int)$conversation->cms_comm_connection_id])->orderBy(['id' => SORT_ASC])->scalar();
        $colleagues = [];
        if ($accountId) {
            $userIds = CmsCommAccountAccess::find()->select('cms_user_id')->andWhere(['cms_comm_account_id' => $accountId])
                ->andWhere(['!=', 'read_level', CmsCommAccountAccess::READ_NONE])->column();
            foreach (CmsUser::find()->andWhere(['id' => $userIds ?: [0], 'is_active' => 1, 'is_worker' => 1])->all() as $user) {
                if ((int)$user->id !== (int)\Yii::$app->user->id) {
                    $colleagues[(int)$user->id] = (string)$user->shortDisplayName;
                }
            }
            asort($colleagues);
        }

        return ['conversation' => $conversation, 'colleagues' => $colleagues, 'me' => (int)\Yii::$app->user->id];
    }

    private ?ChatAccess $_access = null;

    private function access(): ChatAccess
    {
        return $this->_access ??= new ChatAccess(\Yii::$app->user->identity);
    }

    private function dialogs(): ChatDialogs
    {
        return new ChatDialogs(\Yii::$app->user->identity);
    }

    private function folders(): ChatFolders
    {
        return new ChatFolders(\Yii::$app->user->identity, $this->siteId());
    }

    private function siteId(): int
    {
        return (int)\Yii::$app->skeeks->site->id;
    }
}
