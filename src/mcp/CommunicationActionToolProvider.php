<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\communication\services\AiActions;
use skeeks\cms\communication\services\AiSend;
use skeeks\cms\communication\services\MailingTopics;
use skeeks\cms\mcp\services\ApiLogService;
use skeeks\cms\mcp\tools\CallbackTool;
use skeeks\cms\mcp\tools\McpToolProviderInterface;
use yii\base\Component;

/**
 * MCP/REST action tools of mail and mailings for AI clients: personal
 * letters to clients with an offer. Thin adapters over services:
 *
 * - preparing (scope cms.communication.write): drafts for the responsible
 *   employee or in the user's own mailbox, draft campaigns and their
 *   personal letters (AiActions), mailing topics (MailingTopics, the
 *   administrator's right);
 * - sending (scope cms.communication.mail_send, never implied by preparing):
 *   a checked own draft with an idempotency key (AiSend);
 * - the result of a send (cms.communication.read).
 *
 * The OAuth user, their rights and forManager() apply; the "Подготовлено
 * ИИ" mark and the client of a send come from the OAuth client of the
 * running tool, never from arguments; saving and sending need confirm=true.
 * No tool launches a campaign. Registered through
 * Yii::$app->params['cmsMcp']['toolProviders'].
 */
class CommunicationActionToolProvider extends Component implements McpToolProviderInterface
{
    /** The journal entry goes with the other entries of the call: the category of its transport, as McpComponent::execute(). */
    private static function logCategory(): string
    {
        $transport = (string)(ApiLogService::context()['transport'] ?? '');

        return $transport === 'rest' ? ApiLogService::CATEGORY_REST : ($transport === 'mcp' ? ApiLogService::CATEGORY_MCP : 'skeeks.cms.api.tool');
    }

    public function getTools(): array
    {
        $limits = AiActions::limits();
        $record = [
            'record_type' => ['type' => 'string', 'enum' => ['lead', 'company', 'client'], 'description' => 'Клиент CRM: лид, компания или клиент-человек.'],
            'record_id' => ['type' => 'integer'],
            'address' => ['type' => 'string', 'description' => 'Email клиента из его карточки (компании — и её людей); по умолчанию первый. Выбирайте явно, если адресов несколько.'],
            'subject' => ['type' => 'string', 'maxLength' => 500],
            'body_html' => ['type' => 'string', 'description' => 'Текст письма в HTML общего редактора (<p>, <br>, <b>, <i>, <ul>, <a>). {Ссылка} — место кнопки предложения, {Отписаться} — место ссылки отписки; без них встанут в конце.'],
        ];
        $confirm = ['confirm' => ['type' => 'boolean', 'description' => 'true — сохранить. Без него — только проверка: что будет сделано и кому откажут (requires_confirmation). Сохраняйте после согласия человека.']];
        $rules = ' Проверки по каждому клиенту: виден ли он сотруднику, есть ли email, MailingPolicy по теме (отписан от темы или от всего, жалоба, недоставка), '
            .'письмо ему за последние '.$limits['recentDays'].' дней, это предложение ему за '.$limits['recentDays'].' дней, черновик ИИ или кампания, уже ждущие этого клиента. '
            .'Отказ — status=refused с reason и message, остальные строки выполняются. Не больше '.$limits['perCall'].' писем за вызов и '.$limits['perDay'].' черновиков и получателей в день на сотрудника. '
            .'Всё сделанное отмечено «Подготовлено ИИ» с названием OAuth-клиента — снять отметку нельзя.';
        $topicFields = [
            'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Название темы: его видит клиент на странице отписки.'],
            'description' => ['type' => ['string', 'null'], 'maxLength' => 2000, 'description' => 'Описание для клиента на странице отписки.'],
            'is_active' => ['type' => 'boolean'],
            'priority' => ['type' => 'integer'],
        ];

        return [
            $this->tool('cms_comm_draft_create',
                'Черновики личных писем клиентам с приложенным предложением. Черновик появляется в «Черновиках» почты у ответственного за клиента сотрудника '
                .'(его ящик по умолчанию) или в вашем ящике cms_comm_account_id, если вам можно с него отправлять. Сам черновик ничего не отправляет: его отправляет сотрудник в «Почте», '
                .'а через API — только черновик в вашем ящике после cms_comm_draft_check и cms_comm_draft_send (отдельное право). '
                .'Письмо уйдёт как рассылка темы cms_comm_topic_id (cms_comm_topic_list) одному получателю со ссылкой «Отписаться». '
                .'offer — предложение «proposal:<id>»: общая Презентация или КП этого клиента (cms_proposal_library_get, cms_proposal_list); личная ссылка создаётся при отправке, открытие видно в ленте и ставит задачу по правилам предложения.'
                .$rules.' Пример: {"cms_comm_topic_id":1,"offer":"proposal:10","cms_comm_account_id":1,"items":[{"record_type":"company","record_id":712,"address":"asdasd@skeeks.com","subject":"…","body_html":"<p>Здравствуйте!</p><p>…</p><p>{Ссылка}</p>"}],"confirm":true}.',
                'cms.communication.write', [$this, 'draftCreate'], $this->object([
                    'cms_comm_topic_id' => ['type' => 'integer'],
                    'offer' => ['type' => 'string', 'description' => 'Предложение для всех писем вызова: proposal:<id>. У строки может быть своё offer.'],
                    'cms_comm_account_id' => ['type' => 'integer', 'description' => 'Ваш ящик (cms_comm_account_list с правом отправки). Без него — ящик ответственного за клиента.'],
                    'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => $limits['perCall'], 'items' => $this->object($record + [
                        'offer' => ['type' => 'string'],
                    ], ['record_type', 'record_id', 'subject', 'body_html'])],
                ] + $confirm, ['cms_comm_topic_id', 'items'])),
            $this->tool('cms_comm_draft_check',
                'Проверка вашего черновика перед отправкой через API. Ничего не отправляет, не создаёт личную ссылку и не засчитывает открытие. '
                .'Проверяет то же, что форма письма в «Почте» (ящик и право отправки с него, адреса, «только свои клиенты», MailingPolicy темы, документ), и правила ИИ: '
                .'одному адресу из карточки клиента CRM, без копий, нет письма клиенту и отправки этого предложения за '.$limits['recentDays'].' дней, предложение можно отправить (срок, адрес страницы), '
                .'дневной лимит отправок ('.$limits['sendPerDay'].'). Возвращает получателя, отправителя, тему, предложение, предпросмотр (тема, текст, HTML с неработающей кнопкой) и problems. '
                .'Пройденная проверка даёт check_token — отпечаток получателя, отправителя, темы, текста и предложения; он нужен для cms_comm_draft_send. Пример: {"draft_id":43}.',
                'cms.communication.write', [$this, 'draftCheck'], $this->object([
                    'draft_id' => ['type' => 'integer', 'description' => 'Черновик в ваших «Черновиках» (cms_comm_draft_create возвращает draft_id).'],
                ], ['draft_id'])),
            $this->tool('cms_comm_draft_send',
                'Отправить проверенный черновик клиенту: только после cms_comm_draft_check и явного согласия человека на этого получателя, отправителя, текст и предложение. '
                .'Нужны draft_id, check_token из проверки, idempotency_key и confirm=true. Если черновик изменился после проверки — отказ changed_after_check, ничего не отправлено. '
                .'idempotency_key — новый уникальный ключ на каждое письмо (8–128 символов): повтор с тем же ключом и теми же данными вернёт прежний результат без второго письма, '
                .'с другими данными — отказ idempotency_key_reused. Если ответ потерян (тайм-аут), не отправляйте заново, а узнайте результат по ключу: cms_comm_send_status. '
                .'Письмо уходит штатно: личная ссылка на предложение, «Отписаться», лента клиента, отметка «Отправлено ИИ через API». '
                .'Результат: state — queued (в очереди), sending, sent (принято почтовым сервером отправителя, это не доставка), failed, unknown (неизвестно, повторно не отправляется); '
                .'delivery — not_confirmed или bounced; id письма и отправки предложения. Без confirm=true — только проверка. '
                .'Пример: {"draft_id":43,"check_token":"…","idempotency_key":"offer-712-20261024-1","confirm":true}.',
                'cms.communication.mail_send', [$this, 'draftSend'], $this->object([
                    'draft_id' => ['type' => 'integer'],
                    'check_token' => ['type' => 'string'],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
                    'confirm' => ['type' => 'boolean'],
                ], ['draft_id', 'check_token', 'idempotency_key'])),
            $this->tool('cms_comm_send_status',
                'Результат отправки через API по idempotency_key (или cms_comm_message_id вашей отправки): state (queued, sending, sent — принято сервером отправителя, failed, unknown), '
                .'ошибка, delivery (not_confirmed — почта доставку не подтверждает; bounced — пришло сообщение о недоставке или адрес отмечен недоставляемым), '
                .'предложение (send_id, opened — честное открытие клиентом, views_count, link_checks_count — проверки ссылок почтовыми службами, не открытия), ответы клиента. '
                .'Чтение статуса не открывает ссылку и не меняет статистику. Открытие — не согласие и не покупка. Пример: {"idempotency_key":"offer-712-20261024-1"}.',
                'cms.communication.read', [$this, 'sendStatus'], $this->object([
                    'idempotency_key' => ['type' => 'string'],
                    'cms_comm_message_id' => ['type' => 'integer'],
                ])),
            $this->tool('cms_comm_topic_create',
                'Создать тему рассылки (её видит клиент на странице отписки; письма с предложением требуют тему). Только администратор, как в разделе «Настройки → Темы рассылок». '
                .'Название уникально на сайте: существующее — отказ. Без confirm=true — только проверка. Начальные темы сайта: «Новые решения и услуги», «Акции и скидки», «Новости студии», «Новости платформы». '
                .'Пример: {"name":"Новые решения и услуги","description":"Рассказываем о новых готовых решениях и услугах.","confirm":true}.',
                'cms.communication.write', [$this, 'topicCreate'], $this->object($topicFields + $confirm, ['name'])),
            $this->tool('cms_comm_topic_update',
                'Изменить тему рассылки: только переданные поля (name, description, is_active, priority). Только администратор. Выключенная тема не годится для новых писем, '
                .'отписки от неё сохраняются. Пример: {"id":1,"description":"…","confirm":true}.',
                'cms.communication.write', [$this, 'topicUpdate'], $this->object(['id' => ['type' => 'integer']] + $topicFields + $confirm, ['id'])),
            $this->tool('cms_comm_campaign_create',
                'Черновик кампании рассылки с личными письмами: тема рассылки, отправитель (ящики ответственных или ваш cms_comm_account_id), предложение — offer «proposal:<id>» (общая Презентация всем) '
                .'или offer_template_id (каждому своё КП из шаблона), задачи при открытии и ответе. Аудитория — только добавленные получатели (cms_comm_campaign_recipient_add). '
                .'ЗАПУСТИТЬ КАМПАНИЮ ЭТИМ ИЛИ ДРУГИМ ИНСТРУМЕНТОМ НЕЛЬЗЯ: её проверяет и запускает только сотрудник в разделе «Рассылки» (сводка, предпросмотр, «Запустить»). '
                .'Нужно право на раздел «Рассылки». Кампания отмечена «Подготовлено ИИ». Пример: {"name":"Плитка: личные предложения","cms_comm_topic_id":1,"offer":"proposal:10","confirm":true}.',
                'cms.communication.write', [$this, 'campaignCreate'], $this->object([
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'cms_comm_topic_id' => ['type' => 'integer'],
                    'offer' => ['type' => 'string'],
                    'offer_template_id' => ['type' => 'integer'],
                    'cms_comm_account_id' => ['type' => 'integer'],
                    'subject' => ['type' => 'string', 'description' => 'Тема шаблона для получателей без личной темы; по умолчанию название.'],
                    'body_html' => ['type' => 'string', 'description' => 'Шаблон для получателей без личного текста: {Имя}, {Компания}, {Менеджер}, {Ссылка}, {Отписаться}.'],
                    'task_on_open' => ['type' => 'boolean'], 'task_on_reply' => ['type' => 'boolean'],
                ] + $confirm, ['name', 'cms_comm_topic_id'])),
            $this->tool('cms_comm_campaign_recipient_add',
                'Получатели черновика кампании с личной темой и текстом каждому (повторный вызов для того же клиента заменяет текст). {Ссылка} и {Отписаться} обязательны в письме — без них добавятся в конце; '
                .'{Имя}, {Компания}, {Менеджер} подставятся при отправке. Кампания остаётся черновиком: запускает её только сотрудник в интерфейсе, инструмента запуска нет.'
                .$rules.' Пример: {"cms_comm_campaign_id":4,"items":[{"record_type":"company","record_id":15,"subject":"…","body_html":"<p>…</p><p>{Ссылка}</p><p>{Отписаться}</p>"}],"confirm":true}.',
                'cms.communication.write', [$this, 'recipientAdd'], $this->object([
                    'cms_comm_campaign_id' => ['type' => 'integer'],
                    'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => $limits['perCall'], 'items' => $this->object($record + [
                        'name' => ['type' => 'string', 'description' => 'Имя для {Имя}.'],
                    ], ['record_type', 'record_id', 'subject', 'body_html'])],
                ] + $confirm, ['cms_comm_campaign_id', 'items'])),
        ];
    }

    public function draftCreate(array $arguments): array
    {
        return $this->run('draft_create', $arguments, static fn(AiActions $ai, bool $apply) => $ai->createDrafts($arguments, $apply));
    }

    public function campaignCreate(array $arguments): array
    {
        return $this->run('campaign_create', $arguments, static fn(AiActions $ai, bool $apply) => $ai->createCampaign($arguments, $apply));
    }

    public function recipientAdd(array $arguments): array
    {
        return $this->run('campaign_recipient_add', $arguments, static fn(AiActions $ai, bool $apply) => $ai->addRecipients($arguments, $apply));
    }

    public function draftCheck(array $arguments): array
    {
        $result = $this->sender()->check((int)($arguments['draft_id'] ?? 0));
        $this->log('draft_check', ['draft_id' => (int)($arguments['draft_id'] ?? 0), 'ok' => $result['ok'],
            'reasons' => array_column($result['problems'], 'reason')]);

        return $result;
    }

    public function draftSend(array $arguments): array
    {
        $result = $this->sender()->send((int)($arguments['draft_id'] ?? 0), (string)($arguments['check_token'] ?? ''),
            (string)($arguments['idempotency_key'] ?? ''), self::confirmed($arguments));
        $this->log('draft_send', [
            'draft_id' => (int)($arguments['draft_id'] ?? 0),
            'state' => $result['state'] ?? null,
            'repeated' => $result['repeated'] ?? null,
            'cms_comm_message_id' => $result['message']['id'] ?? null,
            'reasons' => array_column((array)($result['problems'] ?? []), 'reason'),
        ]);

        return $result;
    }

    public function sendStatus(array $arguments): array
    {
        $key = isset($arguments['idempotency_key']) ? (string)$arguments['idempotency_key'] : null;
        $messageId = isset($arguments['cms_comm_message_id']) ? (int)$arguments['cms_comm_message_id'] : null;
        if (($key === null || trim($key) === '') && !$messageId) {
            throw new \DomainException('Передайте idempotency_key отправки или cms_comm_message_id.');
        }
        $sender = $this->sender();
        $row = $sender->find($key, $messageId);
        if (!$row) {
            return ['found' => false, 'sent' => false, 'state' => 'not_found',
                'state_text' => 'Отправки с таким ключом у вас нет: письмо не отправлялось (или отправлено не через API). Можно проверить черновик и отправить с этим ключом.'];
        }

        return ['found' => true] + $sender->status($row);
    }

    public function topicCreate(array $arguments): array
    {
        return $this->topic(null, $arguments);
    }

    public function topicUpdate(array $arguments): array
    {
        $topics = new MailingTopics($this->user());
        $topic = $topics->find((int)($arguments['id'] ?? 0));
        if (!$topic) {
            throw new \DomainException('Тема рассылки не найдена (cms_comm_topic_list).');
        }

        return $this->topic($topic, $arguments);
    }

    private function topic($topic, array $arguments): array
    {
        $apply = self::confirmed($arguments);
        $result = (new MailingTopics($this->user()))->save($topic, $arguments, $apply);
        if ($result['errors']) {
            return ['success' => false, 'saved' => false, 'errors' => $result['errors'], 'message' => implode(' ', $result['errors'])];
        }
        $this->log($topic ? 'topic_update' : 'topic_create', ['applied' => $apply, 'id' => $result['model']->id, 'changed' => $result['changed']]);

        return ['success' => true, 'saved' => $apply && (bool)$result['changed'], 'requires_confirmation' => !$apply,
            'changed' => $result['changed'], 'topic' => MailingTopics::data($result['model'])];
    }

    /** The action for the OAuth user and client; the journal entry of what was done. */
    private function run(string $action, array $arguments, callable $callback): array
    {
        $apply = self::confirmed($arguments);
        $client = self::clientName();
        $result = $callback(new AiActions($this->user(), $client), $apply);

        $this->log($action, [
            'applied' => $apply,
            'created' => $result['created'] ?? null,
            'refused' => $result['refused'] ?? null,
            'campaign_id' => $result['campaign']['id'] ?? ($arguments['cms_comm_campaign_id'] ?? null),
            'ids' => array_values(array_filter(array_map(static fn($r) => $r['draft_id'] ?? $r['recipient_id'] ?? null, (array)($result['items'] ?? [])))),
            'reasons' => array_values(array_filter(array_map(static fn($r) => $r['reason'] ?? null, (array)($result['items'] ?? [])))),
        ]);

        return $result;
    }

    private function log(string $action, array $data): void
    {
        ApiLogService::info('ai.action', ['action' => $action, 'oauth_client' => self::clientName()] + $data, self::logCategory(), true);
    }

    private function sender(): AiSend
    {
        return new AiSend($this->user(), self::clientName());
    }

    private function user()
    {
        $user = \Yii::$app->user->identity;
        if (!$user) {
            throw new \DomainException('Нужен пользователь CRM из OAuth-входа.');
        }

        return $user;
    }

    /** The OAuth client of the running tool; cms-mcp before 1.0.14 does not know it ("mcp:клиент"). */
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
