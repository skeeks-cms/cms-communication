<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\chat\ChatAccess;
use skeeks\cms\communication\chat\ChatFeed;
use skeeks\cms\communication\chat\ChatFolders;
use skeeks\cms\communication\chat\ChatTasks;
use skeeks\cms\communication\components\AiApiSettings;
use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommApiSend;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommParticipant;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\services\AiActions;
use skeeks\cms\communication\services\AiMark;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MessageRecordFactory;
use skeeks\cms\communication\source\MessageRecordSource;
use skeeks\cms\mcp\crm\CrmDate;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;

/**
 * Chats of messenger bots for AI clients (cms-mcp, stage 5.6), with the
 * rights of the OAuth employee (chat\ChatAccess, as in "Чаты"):
 *
 * - reading (cms.communication.read): chats by folder, client, search;
 *   a chat with its people and messages; phones and emails in texts are
 *   masked unless include_contacts=true;
 * - a reply (prepare: cms.communication.write; send: cms.communication.send,
 *   never implied by preparing): the check returns the text the client gets
 *   and a check_token; the send needs the same token, a new idempotency key
 *   and confirm=true after a person agreed. The message carries the mark
 *   "Подготовлено ИИ" with the OAuth client; the switch and the daily limit
 *   of sends through the API are those of letters (AiApiSettings);
 * - a task from a chat message (cms.task.write), as "Создать задачу" in
 *   "Чаты".
 */
class ChatToolService
{
    public const REASON_NO_ACCESS = 'no_access';
    public const REASON_BOT_LEFT = 'bot_left';
    public const REASON_NOT_ALLOWED = 'chat_not_allowed';
    public const REASON_EMPTY = 'empty_text';
    public const REASON_TOO_LONG = 'too_long';
    public const REASON_REPLY_TO = 'reply_to_not_in_chat';

    public const MAX_TEXT = 3000;

    private CmsUser $user;
    private string $clientName;
    private int $siteId;

    public function __construct(CmsUser $user, string $clientName, int $siteId)
    {
        $this->user = $user;
        $this->clientName = $clientName;
        $this->siteId = $siteId;
    }

    /* Reading */

    public function chatList(array $a): array
    {
        $folder = (string)($a['folder'] ?? ChatFolders::ALL);
        $folders = new ChatFolders($this->user, $this->siteId);
        if (!$folders->isKnown($folder)) {
            throw new \DomainException('Неизвестная папка «'.$folder.'»: new, mine, groups, all, closed или bot:<id бота>.');
        }
        $query = $folders->query($folder, (string)($a['q'] ?? ''));
        if (!empty($a['record_type'])) {
            $code = CrmRecords::codeOf((string)$a['record_type']);
            $id = (int)($a['record_id'] ?? 0);
            if (!$code || !CrmRecords::isVisible($code, $id, $this->user)) {
                throw new \DomainException('Клиент не найден или недоступен.');
            }
            $ids = CmsCommConversationLink::find()->select('cms_comm_conversation_id')->andWhere(['model_code' => $code, 'model_id' => $id])->column();
            if ($a['record_type'] === CrmRecords::CLIENT) {
                $people = CmsCommIdentity::find()->select('id')->andWhere(['cms_user_id' => $id]);
                $ids = array_merge($ids, CmsCommMessage::find()->select('cms_comm_conversation_id')->distinct()->andWhere(['cms_comm_identity_id' => $people])->column());
            }
            $query->andWhere([CmsCommConversation::tableName().'.id' => $ids ?: [0]]);
        }
        $limit = max(1, min(100, (int)($a['limit'] ?? 30)));
        $offset = max(0, (int)($a['offset'] ?? 0));
        $total = (int)(clone $query)->count();
        $chats = $query->with(['assignedUser', 'connection'])->limit($limit)->offset($offset)->all();
        $unread = $folders->unreadCounts(array_map(static fn($c) => (int)$c->id, $chats));
        $context = $this->context($a);

        return [
            'total' => $total,
            'items' => array_map(fn(CmsCommConversation $c) => $this->chatData($c, $context) + ['unread' => (int)($unread[(int)$c->id] ?? 0)], $chats),
        ];
    }

    public function chatGet(array $a): array
    {
        $chat = $this->visibleChat((int)($a['id'] ?? 0));
        $context = $this->context($a);
        $limit = max(1, min(200, (int)($a['limit'] ?? 50)));
        $length = max(200, min(10000, (int)($a['max_length'] ?? 2000)));
        $query = CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$chat->id])->with(['identity', 'identity.cmsUser', 'files', 'sentBy']);
        // By the time of the messages: an imported history is not numbered in time order.
        if (!empty($a['before_id']) && ($pivot = CmsCommMessage::find()->andWhere(['id' => (int)$a['before_id'], 'cms_comm_conversation_id' => (int)$chat->id])->one())) {
            $query->andWhere(['or', ['<', 'message_at', (int)$pivot->message_at], ['and', ['message_at' => (int)$pivot->message_at], ['<', 'id', (int)$pivot->id]]]);
        }
        $messages = array_reverse($query->orderBy(['message_at' => SORT_DESC, 'id' => SORT_DESC])->limit($limit + 1)->all());
        $more = count($messages) > $limit;
        if ($more) {
            array_shift($messages);
        }
        $tasks = [];
        foreach (CmsCommLink::find()->andWhere(['cms_comm_message_id' => array_map(static fn($m) => (int)$m->id, $messages) ?: [0],
            'model_code' => CmsTask::class, 'link_type' => CmsCommLink::TYPE_CREATED])->all() as $link) {
            $tasks[(int)$link->cms_comm_message_id][] = (int)$link->model_id;
        }
        $people = [];
        if ($chat->chat_type !== CmsCommConversation::CHAT_PRIVATE) {
            foreach ($chat->getParticipants()->andWhere(['left_at' => null])->with(['identity', 'identity.cmsUser'])->all() as $p) {
                /** @var CmsCommParticipant $p */
                if ($p->identity) {
                    $people[] = $this->personData($p->identity, $context) + ['role' => (string)$p->role];
                }
            }
        }

        return $this->chatData($chat, $context) + [
            'people' => $people,
            'messages' => array_map(fn(CmsCommMessage $m) => $this->messageData($m, $context, $length) + ['tasks' => $tasks[(int)$m->id] ?? []], $messages),
            'has_older' => $more,
            'can_send' => (new ChatAccess($this->user))->canSend($chat),
        ];
    }

    /* A reply */

    /**
     * The reply as the client will get it, and whether it may go. Nothing is
     * saved or sent.
     */
    public function replyCheck(array $a): array
    {
        $prepared = $this->prepareReply($a);
        $result = [
            'ok' => !$prepared['problems'],
            'chat' => $prepared['chat'] ? ['id' => (int)$prepared['chat']->id, 'title' => $prepared['chat']->asText(), 'channel' => ChatUi::channelName($prepared['chat'])] : null,
            'reply_to_message_id' => $prepared['replyTo'] ? (int)$prepared['replyTo']->id : null,
            'preview' => $prepared['preview'],
            'problems' => $prepared['problems'],
        ];
        if (!$prepared['problems']) {
            $result['check_token'] = $prepared['token'];
            $result['next'] = 'Покажите человеку предпросмотр. Отправка — cms_comm_chat_reply_send с этим check_token, новым idempotency_key и confirm=true после его согласия.';
        }

        return $result;
    }

    public function replySend(array $a, bool $confirm): array
    {
        $key = trim((string)($a['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:\-]{8,128}$/', $key)) {
            throw new \DomainException('idempotency_key обязателен: 8–128 символов (латиница, цифры, . _ : -), новый для каждого сообщения.');
        }
        $token = (string)($a['check_token'] ?? '');
        $hash = hash('sha256', 'chat|'.$token);
        $existing = $this->byKey($key);
        if ($existing) {
            if ($existing->request_hash !== $hash) {
                return ['sent' => false, 'state' => 'refused', 'repeated' => true, 'problems' => [['reason' => \skeeks\cms\communication\services\AiSend::REASON_KEY_REUSED,
                    'message' => 'Этот idempotency_key уже использован для другого сообщения. Для нового сообщения нужен новый ключ.']]];
            }

            return ['repeated' => true] + $this->sendStatus($existing);
        }
        $prepared = $this->prepareReply($a);
        if ($prepared['problems']) {
            return ['sent' => false, 'state' => 'refused', 'repeated' => false, 'problems' => $prepared['problems'], 'next' => 'Ничего не отправлено.'];
        }
        if (!hash_equals($prepared['token'], $token)) {
            return ['sent' => false, 'state' => 'refused', 'repeated' => false, 'problems' => [['reason' => \skeeks\cms\communication\services\AiSend::REASON_CHANGED,
                'message' => 'Чат, текст или сообщение, на которое отвечаете, отличаются от проверенных (или check_token от другой проверки).']],
                'next' => 'Ничего не отправлено. Повторите cms_comm_chat_reply_check, покажите текст человеку и отправьте с новым check_token.'];
        }
        if (!$confirm) {
            return ['sent' => false, 'state' => 'not_sent', 'requires_confirmation' => true, 'repeated' => false,
                'next' => 'Проверка пройдена, ничего не отправлено. Отправка — тот же вызов с confirm=true после явного согласия человека.'];
        }

        $row = null;
        try {
            CmsCommApiSend::getDb()->transaction(function () use ($prepared, $key, $hash, &$row) {
                $row = new CmsCommApiSend([
                    'cms_site_id' => $this->siteId,
                    'cms_user_id' => (int)$this->user->id,
                    'client' => mb_substr('mcp:'.($this->clientName !== '' ? $this->clientName : 'клиент'), 0, 190),
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ]);
                $row->save(false);
                $message = BotSend::queue($prepared['chat'], $prepared['text'], ['user' => $this->user, 'replyTo' => $prepared['replyTo']]);
                AiMark::apply($message, AiMark::make($this->clientName, (int)$this->user->id));
                $message->save(false, ['prepared_by', 'prepared_client', 'prepared_user_id', 'prepared_at', 'prepared_edited']);
                $row->cms_comm_message_id = (int)$message->id;
                $row->save(false, ['cms_comm_message_id', 'updated_at']);
            });
        } catch (SendException $e) {
            return ['sent' => false, 'state' => 'refused', 'repeated' => false,
                'problems' => array_map(static fn($m) => ['reason' => self::REASON_NOT_ALLOWED, 'message' => (string)$m], array_values($e->errors))];
        } catch (\yii\db\IntegrityException $e) {
            $existing = $this->byKey($key);
            if ($existing && $existing->request_hash === $hash) {
                return ['repeated' => true] + $this->sendStatus($existing);
            }
            throw $e;
        }

        return ['repeated' => false] + $this->sendStatus($row);
    }

    /** What is known about a reply sent through the API. */
    public function sendStatus(CmsCommApiSend $row): array
    {
        $message = $row->cms_comm_message_id ? CmsCommMessage::findOne((int)$row->cms_comm_message_id) : null;
        if (!$message) {
            return ['sent' => false, 'state' => 'unknown', 'state_text' => 'Сообщение этой отправки не найдено.'];
        }
        $states = [
            CmsCommMessage::STATUS_QUEUED => 'Сообщение в очереди: бот отправит его в ближайшие секунды.',
            CmsCommMessage::STATUS_SENDING => 'Сообщение отправляется.',
            CmsCommMessage::STATUS_SENT => 'Мессенджер принял сообщение.',
            CmsCommMessage::STATUS_FAILED => 'Мессенджер отказал: сообщение не отправлено.',
            CmsCommMessage::STATUS_UNKNOWN => 'Неизвестно, ушло ли сообщение (мессенджер не ответил). Повторно не отправляется.',
        ];

        return [
            'sent' => true,
            'idempotency_key' => (string)$row->idempotency_key,
            'state' => (string)$message->status,
            'state_text' => $states[$message->status] ?? (string)$message->status,
            'error' => $message->error ? mb_substr((string)$message->error, 0, 300) : null,
            'cms_comm_message_id' => (int)$message->id,
            'cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id,
        ];
    }

    /** The reply of this user by its key or message, or null. */
    public function findSend(?string $key, ?int $messageId): ?CmsCommApiSend
    {
        if ($key !== null && trim($key) !== '') {
            return $this->byKey(trim($key));
        }

        return $messageId ? CmsCommApiSend::find()->andWhere(['cms_site_id' => $this->siteId, 'cms_user_id' => (int)$this->user->id, 'cms_comm_message_id' => $messageId])->one() : null;
    }

    /* A task */

    /**
     * A task from a chat message: without confirm — what will be created;
     * with confirm — the task, linked to the message as from "Создать задачу".
     */
    public function taskCreate(array $a, bool $confirm): array
    {
        $message = MessageRecordSource::visible((int)($a['cms_comm_message_id'] ?? 0));
        if (!$message || !$message->conversation || !$message->conversation->isChat()) {
            throw new \DomainException('Сообщение чата не найдено или недоступно (cms_comm_chat_get).');
        }
        $chat = $message->conversation;
        $executor = !empty($a['executor_id'])
            ? CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => (int)$a['executor_id'], CmsUser::tableName().'.is_active' => 1])->one()
            : (ChatTasks::executor($chat, ChatTasks::company($chat), $this->user) ?: $this->user);
        if (!$executor) {
            throw new \DomainException('Исполнитель не найден среди действующих сотрудников.');
        }
        $factory = new MessageRecordFactory($this->user);
        $draft = new CmsTask();
        $factory->prefill($message, $draft);
        $name = trim((string)($a['name'] ?? '')) ?: (string)$draft->name;
        $note = 'Поставлено ИИ ('.AiMark::clientName(mb_substr('mcp:'.($this->clientName !== '' ? $this->clientName : 'клиент'), 0, 190)).') по запросу '.trim((string)$this->user->shortDisplayName).'.';
        $preview = [
            'name' => mb_substr($name, 0, 255),
            'executor' => ['id' => (int)$executor->id, 'name' => (string)$executor->shortDisplayName],
            'cms_company_id' => $draft->cms_company_id ? (int)$draft->cms_company_id : null,
            'cms_lead_id' => $draft->cms_lead_id ? (int)$draft->cms_lead_id : null,
            'cms_user_id' => $draft->cms_user_id ? (int)$draft->cms_user_id : null,
            'source' => $factory->describe($message),
        ];
        if (!$confirm) {
            return ['created' => false, 'requires_confirmation' => true, 'task' => $preview,
                'next' => 'Ничего не создано. Создание — тот же вызов с confirm=true после согласия человека.'];
        }
        $attributes = ['name' => $preview['name'], 'executor_id' => (int)$executor->id, 'created_by' => (int)$this->user->id];
        if (!empty($a['description'])) {
            $attributes['description'] = (string)$a['description'];
        }
        /** @var CmsTask $task */
        $task = $factory->create($message, CrmRecords::TASK, $attributes);
        $task->updateAttributes(['description' => rtrim((string)$task->description)."\n\n".$note]);
        if ((int)$task->created_by !== (int)$this->user->id) {
            $task->updateAttributes(['created_by' => (int)$this->user->id]);
        }

        return ['created' => true, 'task' => ['id' => (int)$task->id, 'status' => (string)$task->status] + $preview];
    }

    /* Data */

    private function chatData(CmsCommConversation $c, CrmReadContext $context): array
    {
        $links = [];
        foreach (ChatFeed::links($c) as $link) {
            $type = CrmRecords::typeOfCode((string)$link->model_code);
            if ($type && CrmRecords::isVisible((string)$link->model_code, (int)$link->model_id, $this->user)) {
                $record = ChatFeed::record((string)$link->model_code, (int)$link->model_id);
                $links[] = ['type' => $type, 'id' => (int)$link->model_id, 'name' => $record ? CrmRecords::name($record) : null];
            }
        }
        $person = null;
        if ($c->chat_type === CmsCommConversation::CHAT_PRIVATE && ($identity = \skeeks\cms\communication\chat\ChatIdentities::ofChat($c))) {
            $person = $this->personData($identity, $context);
        }

        return [
            'id' => (int)$c->id,
            'title' => $context->text($c->asText(), 300),
            'channel' => (string)$c->channel,
            'channel_name' => ChatUi::channelName($c),
            'chat_type' => (string)$c->chat_type,
            'bot' => $c->connection ? (string)$c->connection->name : null,
            'bot_in_chat' => $c->bot_status !== CmsCommConversation::BOT_LEFT,
            'last_message_at' => CrmDate::format((int)$c->last_message_at),
            'waiting_since' => $c->waiting_since ? CrmDate::format((int)$c->waiting_since) : null,
            'closed' => (bool)$c->closed_at,
            'assigned' => $c->assignedUser ? ['id' => (int)$c->assigned_user_id, 'name' => (string)$c->assignedUser->shortDisplayName] : null,
            'links' => $links,
            'person' => $person,
            'task_notify' => (bool)$c->task_notify,
        ];
    }

    private function personData(CmsCommIdentity $identity, CrmReadContext $context): array
    {
        return array_filter([
            'identity_id' => (int)$identity->id,
            'name' => $context->text($identity->asText(), 200),
            'username' => $identity->username ? (string)$identity->username : null,
            'is_employee' => $identity->isWorker(),
            'employee' => $identity->isWorker() ? ['id' => (int)$identity->cms_user_id, 'name' => (string)$identity->cmsUser->shortDisplayName] : null,
            'client_id' => !$identity->isWorker() && $identity->cms_user_id ? (int)$identity->cms_user_id : null,
            'phone' => $identity->phone ? $context->phone((string)$identity->phone) : null,
            'is_bot' => (bool)$identity->is_bot,
        ], static fn($v) => $v !== null);
    }

    private function messageData(CmsCommMessage $m, CrmReadContext $context, int $length): array
    {
        $ours = $m->direction === CmsCommMessage::DIRECTION_OUT || ($m->identity && $m->identity->isWorker());
        $text = trim(ChatUi::body($m));
        $data = [
            'id' => (int)$m->id,
            'date' => CrmDate::format((int)$m->message_at),
            'direction' => (string)$m->direction,
            'ours' => $ours,
            'author' => $context->text(ChatUi::author($m), 200),
            'text' => $context->text($text, $length),
            'text_is_cut' => mb_strlen($text) > $length,
            'files' => array_map(static fn($f) => (string)$f->name, $m->files),
            'reply_to_message_id' => $m->reply_to_message_id ? (int)$m->reply_to_message_id : null,
            'edited' => (bool)$m->edited_at,
            'removed' => (bool)$m->removed_at,
        ];
        if ($m->direction === CmsCommMessage::DIRECTION_OUT && !$m->is_history) {
            $data['status'] = (string)$m->status;
            if ($m->error) {
                $data['error'] = mb_substr((string)$m->error, 0, 300);
            }
        }
        if ($prepared = AiMark::forApi(AiMark::of($m))) {
            $data['prepared'] = $prepared;
        }

        return $data;
    }

    /** @return array chat, replyTo, text (as sent, with the signature), preview, token, problems */
    private function prepareReply(array $a): array
    {
        $problems = [];
        $problem = static function (string $reason, string $message) use (&$problems) {
            $problems[] = ['reason' => $reason, 'message' => $message];
        };
        $chat = $this->visibleChat((int)($a['id'] ?? 0));
        $access = new ChatAccess($this->user);
        if (!$access->canSend($chat)) {
            $problem(self::REASON_NO_ACCESS, 'Вам нельзя писать в этот чат: нужен доступ к боту на отправку (или чат должен быть вашим).');
        }
        if ($chat->bot_status === CmsCommConversation::BOT_LEFT) {
            $problem(self::REASON_BOT_LEFT, 'Бота нет в этом чате.');
        }
        if (!Bots::isChatAllowed((string)$chat->channel, (string)$chat->external_chat_id)) {
            $problem(self::REASON_NOT_ALLOWED, 'Чат не разрешён настройкой allowedChats (на стенде с копией боевой базы бот пишет только в разрешённые чаты).');
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string)($a['text'] ?? '')));
        if ($text === '') {
            $problem(self::REASON_EMPTY, 'Напишите текст ответа.');
        } elseif (mb_strlen($text) > self::MAX_TEXT) {
            $problem(self::REASON_TOO_LONG, 'Слишком длинный ответ: не больше '.self::MAX_TEXT.' символов.');
        }
        $replyTo = null;
        if (!empty($a['reply_to_message_id'])) {
            $replyTo = CmsCommMessage::find()->andWhere(['id' => (int)$a['reply_to_message_id'], 'cms_comm_conversation_id' => (int)$chat->id])->one();
            if (!$replyTo) {
                $problem(self::REASON_REPLY_TO, 'Сообщение, на которое вы отвечаете, не из этого чата.');
            }
        }
        if (!AiApiSettings::forSite($this->siteId)['sendEnabled']) {
            $problem(\skeeks\cms\communication\services\AiSend::REASON_SEND_DISABLED, 'Отправка через API на этом сайте выключена («Настройки почты» → «Работа ИИ через API»).');
        }
        $limit = AiActions::limits()['sendPerDay'];
        if ($this->sentToday() >= $limit) {
            $problem(\skeeks\cms\communication\services\AiSend::REASON_SEND_LIMIT, 'Дневной лимит отправок через API исчерпан: '.$limit.' в день (письма и сообщения чатов вместе).');
        }
        $driver = $chat->connection ? $chat->connection->createDriver(false) : null;
        $sent = $driver && $driver->signManager ? BotSend::signature($this->user, (string)$driver->signName)."\n".$text : $text;

        return [
            'chat' => $chat,
            'replyTo' => $replyTo,
            'text' => $text,
            'preview' => ['text' => $sent, 'reply_to' => $replyTo ? mb_substr(trim(preg_replace('/\s+/u', ' ', ChatUi::body($replyTo))), 0, 120) : null],
            'token' => hash('sha256', implode('|', [(int)$this->user->id, (int)$chat->id, $replyTo ? (int)$replyTo->id : 0, $text])),
            'problems' => $problems,
        ];
    }

    private function visibleChat(int $id): CmsCommConversation
    {
        $chat = $id ? (new ChatAccess($this->user))->query($this->siteId)->andWhere([CmsCommConversation::tableName().'.id' => $id])->one() : null;
        if (!$chat) {
            throw new \DomainException('Чат #'.$id.' не найден или недоступен (cms_comm_chat_list).');
        }

        return $chat;
    }

    private function byKey(string $key): ?CmsCommApiSend
    {
        return CmsCommApiSend::find()->andWhere(['cms_site_id' => $this->siteId, 'cms_user_id' => (int)$this->user->id, 'idempotency_key' => $key])->one();
    }

    /** Sends of the user through the API today, letters and chat messages together. */
    private function sentToday(): int
    {
        $tz = new \DateTimeZone(\Yii::$app->formatter->timeZone ?: date_default_timezone_get());

        return (int)CmsCommApiSend::find()->andWhere(['cms_site_id' => $this->siteId, 'cms_user_id' => (int)$this->user->id])
            ->andWhere(['not', ['cms_comm_message_id' => null]])
            ->andWhere(['>=', 'created_at', (new \DateTime('today', $tz))->getTimestamp()])->count();
    }

    private function context(array $a): CrmReadContext
    {
        return new CrmReadContext(['includeContacts' => !empty($a['include_contacts'])]);
    }
}
