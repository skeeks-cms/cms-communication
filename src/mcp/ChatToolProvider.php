<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\mcp\services\ApiLogService;
use skeeks\cms\mcp\tools\CallbackTool;
use skeeks\cms\mcp\tools\McpToolProviderInterface;
use yii\base\Component;

/**
 * MCP/REST tools of messenger chats (stage 5.6): thin adapters over
 * ChatToolService. Reading — cms.communication.read; preparing a reply —
 * cms.communication.write; sending it — cms.communication.send (like SMS,
 * never implied by preparing); a task from a message — cms.task.write.
 * Registered through Yii::$app->params['cmsMcp']['toolProviders'].
 */
class ChatToolProvider extends Component implements McpToolProviderInterface
{
    public function getTools(): array
    {
        $contacts = ['include_contacts' => ['type' => 'boolean', 'description' => 'Телефоны и адреса в текстах полностью. По умолчанию замаскированы: данные могут уйти во внешнюю модель.']];
        $confirm = ['confirm' => ['type' => 'boolean', 'description' => 'true — выполнить. Без него — только проверка. Выполняйте после явного согласия человека.']];

        return [
            $this->tool('cms_comm_chat_list',
                'Чаты ботов мессенджеров (MAX, Telegram), видимые вам, как в разделе «Чаты»: личные диалоги и группы клиентов, новые сверху. '
                .'folder: new (личные диалоги, которые никто не ведёт), mine (ведёте вы), groups, all (по умолчанию), closed, bot:<id>. q — поиск по названию и текстам. '
                .'record_type + record_id — чаты клиента (привязанные к нему и личные диалоги его людей). У чата: название, мессенджер, вид, кто ведёт, сколько ждёт клиент (waiting_since), '
                .'непрочитанные, привязки к клиентам CRM, собеседник личного диалога. Переписка — cms_comm_chat_get. Пример: {"folder":"new","limit":20}.',
                'cms.communication.read', [$this, 'chatList'], $this->object([
                    'folder' => ['type' => 'string'], 'q' => ['type' => 'string'],
                    'record_type' => ['type' => 'string', 'enum' => ['lead', 'company', 'client', 'deal']], 'record_id' => ['type' => 'integer'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0],
                ] + $contacts)),
            $this->tool('cms_comm_chat_get',
                'Чат и его сообщения (последние limit, по умолчанию 50, старые — before_id): автор, наше ли (ours: ответ из CRM или сотрудник в группе), текст до max_length, '
                .'названия файлов, на какое сообщение ответ, статус отправки наших, задачи из сообщения (tasks), отметка ИИ. Для группы — участники с ролями и отметкой «сотрудник». '
                .'can_send — можно ли вам писать в чат. Пример: {"id":1935,"limit":30}.',
                'cms.communication.read', [$this, 'chatGet'], $this->object([
                    'id' => ['type' => 'integer'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200], 'before_id' => ['type' => 'integer'],
                    'max_length' => ['type' => 'integer', 'minimum' => 200, 'maximum' => 10000],
                ] + $contacts, ['id'])),
            $this->tool('cms_comm_chat_reply_check',
                'Проверка ответа в чат перед отправкой. Ничего не отправляет. Проверяет: вам можно писать в чат, бот в чате, чат разрешён сайтом, текст не пустой и до 3000 символов, '
                .'reply_to_message_id из этого чата, отправка через API включена и не исчерпан дневной лимит (общий с письмами). Возвращает preview.text — точно то, что получит клиент '
                .'(первая строка — ваша подпись «Имя Фамилия (Компания):», её добавит бот; не пишите её сами), problems и check_token. Пример: {"id":1757,"text":"Здравствуйте! …","reply_to_message_id":2401}.',
                'cms.communication.write', [$this, 'replyCheck'], $this->object([
                    'id' => ['type' => 'integer', 'description' => 'Чат (cms_comm_chat_list).'],
                    'text' => ['type' => 'string', 'maxLength' => 3000, 'description' => 'Текст ответа, обычный текст без HTML.'],
                    'reply_to_message_id' => ['type' => 'integer', 'description' => 'Сообщение клиента, на которое отвечаете (цитата в мессенджере).'],
                ], ['id', 'text'])),
            $this->tool('cms_comm_chat_reply_send',
                'Отправить проверенный ответ в чат от бота с вашей подписью. Нужны те же id, text и reply_to_message_id, check_token из cms_comm_chat_reply_check, новый idempotency_key '
                .'и confirm=true после явного согласия человека на этот текст. Изменённый текст — отказ changed_after_check. Повтор с тем же ключом возвращает прежний результат. '
                .'Сообщение отмечено «Подготовлено ИИ» с названием OAuth-клиента, видно в «Чатах» и в ленте клиента. state: queued, sending, sent (мессенджер принял), failed, unknown. '
                .'Статус позже — cms_comm_chat_send_status. Пример: {"id":1757,"text":"…","check_token":"…","idempotency_key":"chat-1757-20261024-1","confirm":true}.',
                'cms.communication.send', [$this, 'replySend'], $this->object([
                    'id' => ['type' => 'integer'], 'text' => ['type' => 'string'], 'reply_to_message_id' => ['type' => 'integer'],
                    'check_token' => ['type' => 'string'], 'idempotency_key' => ['type' => 'string'],
                ] + $confirm, ['id', 'text', 'check_token', 'idempotency_key'])),
            $this->tool('cms_comm_chat_send_status',
                'Состояние ответа в чат, отправленного через API: по idempotency_key или cms_comm_message_id. state: queued, sending, sent, failed (с ошибкой), unknown (повторно не отправляется). '
                .'Пример: {"idempotency_key":"chat-1757-20261024-1"}.',
                'cms.communication.read', [$this, 'sendStatus'], $this->object(['idempotency_key' => ['type' => 'string'], 'cms_comm_message_id' => ['type' => 'integer']])),
            $this->tool('cms_comm_chat_task_create',
                'Задача из сообщения чата — как «Создать задачу» в «Чатах»: название из первой строки сообщения (или name), описание — сообщение, автор, чат, '
                .'компания и лид из привязок чата, клиент из собеседника; задача привязана к сообщению (в чате под ним «Задача №N», в карточке задачи — «Создано из сообщения»). '
                .'Исполнитель — executor_id, иначе ведущий чат, иначе менеджер компании, иначе вы. В описание добавляется «Поставлено ИИ (клиент) по запросу …». '
                .'Без confirm=true — только что будет создано. Пример: {"cms_comm_message_id":2407,"confirm":true}.',
                'cms.task.write', [$this, 'taskCreate'], $this->object([
                    'cms_comm_message_id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'maxLength' => 255],
                    'description' => ['type' => 'string', 'description' => 'Своё описание вместо текста сообщения.'], 'executor_id' => ['type' => 'integer'],
                ] + $confirm, ['cms_comm_message_id'])),
        ];
    }

    public function chatList(array $arguments): array
    {
        return $this->service()->chatList($arguments);
    }

    public function chatGet(array $arguments): array
    {
        return $this->service()->chatGet($arguments);
    }

    public function replyCheck(array $arguments): array
    {
        $result = $this->service()->replyCheck($arguments);
        $this->log('chat_reply_check', ['chat_id' => (int)($arguments['id'] ?? 0), 'ok' => $result['ok'], 'reasons' => array_column($result['problems'], 'reason')]);

        return $result;
    }

    public function replySend(array $arguments): array
    {
        $result = $this->service()->replySend($arguments, self::confirmed($arguments));
        $this->log('chat_reply_send', ['chat_id' => (int)($arguments['id'] ?? 0), 'state' => $result['state'] ?? null, 'repeated' => $result['repeated'] ?? null,
            'cms_comm_message_id' => $result['cms_comm_message_id'] ?? null, 'reasons' => array_column((array)($result['problems'] ?? []), 'reason')]);

        return $result;
    }

    public function sendStatus(array $arguments): array
    {
        $service = $this->service();
        $row = $service->findSend(isset($arguments['idempotency_key']) ? (string)$arguments['idempotency_key'] : null,
            isset($arguments['cms_comm_message_id']) ? (int)$arguments['cms_comm_message_id'] : null);
        if (!$row) {
            return ['found' => false, 'sent' => false, 'state' => 'not_found', 'state_text' => 'Отправки с таким ключом у вас нет: сообщение не отправлялось через API.'];
        }

        return ['found' => true] + $service->sendStatus($row);
    }

    public function taskCreate(array $arguments): array
    {
        $apply = self::confirmed($arguments);
        $result = $this->service()->taskCreate($arguments, $apply);
        $this->log('chat_task_create', ['applied' => $apply, 'cms_comm_message_id' => (int)($arguments['cms_comm_message_id'] ?? 0), 'task_id' => $result['task']['id'] ?? null]);

        return $result;
    }

    private function service(): ChatToolService
    {
        $user = \Yii::$app->user->identity;
        if (!$user) {
            throw new \DomainException('Нужен пользователь CRM из OAuth-входа.');
        }

        return new ChatToolService($user, self::clientName(), (int)\Yii::$app->skeeks->site->id);
    }

    private function log(string $action, array $data): void
    {
        $transport = (string)(ApiLogService::context()['transport'] ?? '');
        $category = $transport === 'rest' ? ApiLogService::CATEGORY_REST : ($transport === 'mcp' ? ApiLogService::CATEGORY_MCP : 'skeeks.cms.api.tool');
        ApiLogService::info('ai.action', ['action' => $action, 'oauth_client' => self::clientName()] + $data, $category, true);
    }

    private static function clientName(): string
    {
        $mcp = \Yii::$app->has('cmsMcp') ? \Yii::$app->cmsMcp : null;

        return $mcp && method_exists($mcp, 'getCurrentClientName') ? (string)$mcp->getCurrentClientName() : '';
    }

    private static function confirmed(array $arguments): bool
    {
        return ($arguments['confirm'] ?? false) === true || ($arguments['confirm'] ?? null) === 'true' || ($arguments['confirm'] ?? null) === 1;
    }

    protected function tool(string $name, string $description, string $scope, $callback, array $schema): CallbackTool
    {
        return new CallbackTool(['name' => $name, 'description' => $description, 'requiredScope' => $scope, 'callback' => $callback, 'inputSchema' => $schema]);
    }

    protected function object(array $properties = [], array $required = []): array
    {
        $s = ['type' => 'object', 'properties' => $properties ?: new \stdClass(), 'additionalProperties' => true];
        if ($required) {
            $s['required'] = $required;
        }

        return $s;
    }
}
