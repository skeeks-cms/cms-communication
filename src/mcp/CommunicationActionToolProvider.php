<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\communication\services\AiActions;
use skeeks\cms\mcp\services\ApiLogService;
use skeeks\cms\mcp\tools\CallbackTool;
use skeeks\cms\mcp\tools\McpToolProviderInterface;
use yii\base\Component;

/**
 * MCP/REST action tools of mail and mailings for AI clients (AI-2):
 * personal letters to clients with an offer, as drafts for the responsible
 * employee or as personal letters of a draft campaign. Thin adapters over
 * services\AiActions: the OAuth user, their rights and forManager(), the
 * "Подготовлено ИИ" mark with the OAuth client of the running tool (never an
 * argument), confirm=true to save. No tool sends a letter or launches a
 * campaign. Registered through Yii::$app->params['cmsMcp']['toolProviders'].
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
            'address' => ['type' => 'string', 'description' => 'Email клиента из его карточки; по умолчанию первый.'],
            'subject' => ['type' => 'string', 'maxLength' => 500],
            'body_html' => ['type' => 'string', 'description' => 'Текст письма в HTML общего редактора (<p>, <br>, <b>, <i>, <ul>, <a>). {Ссылка} — место кнопки предложения, {Отписаться} — место ссылки отписки; без них встанут в конце.'],
        ];
        $confirm = ['confirm' => ['type' => 'boolean', 'description' => 'true — сохранить. Без него — только проверка: что будет сделано и кому откажут (requires_confirmation). Сохраняйте после согласия человека.']];
        $rules = ' Проверки по каждому клиенту: виден ли он сотруднику, есть ли email, MailingPolicy по теме (отписан от темы или от всего, жалоба, недоставка), '
            .'письмо ему за последние '.$limits['recentDays'].' дней, это предложение ему за '.$limits['recentDays'].' дней, черновик ИИ или кампания, уже ждущие этого клиента. '
            .'Отказ — status=refused с reason и message, остальные строки выполняются. Не больше '.$limits['perCall'].' писем за вызов и '.$limits['perDay'].' черновиков и получателей в день на сотрудника. '
            .'Всё сделанное отмечено «Подготовлено ИИ» с названием OAuth-клиента — снять отметку нельзя.';

        return [
            $this->tool('cms_comm_draft_create',
                'Черновики личных писем клиентам с приложенным предложением — режим по умолчанию. Черновик появляется в «Черновиках» почты у ответственного за клиента сотрудника '
                .'(его ящик по умолчанию) или в указанном ящике cms_comm_account_id, если вам можно с него отправлять. Сотрудник правит и отправляет сам: инструмент ничего не отправляет. '
                .'Письмо уйдёт как рассылка темы cms_comm_topic_id (cms_comm_topic_list) одному получателю со ссылкой «Отписаться». '
                .'offer — предложение «proposal:<id>»: общая Презентация или КП этого клиента (cms_proposal_library_get, cms_proposal_list); личная ссылка создаётся при отправке, открытие видно в ленте и ставит задачу по правилам предложения.'
                .$rules.' Пример: {"cms_comm_topic_id":1,"offer":"proposal:10","items":[{"record_type":"lead","record_id":51,"subject":"Сайт для магазина плитки","body_html":"<p>Здравствуйте!</p><p>…</p><p>{Ссылка}</p>"}],"confirm":true}.',
                'cms.communication.send', [$this, 'draftCreate'], $this->object([
                    'cms_comm_topic_id' => ['type' => 'integer'],
                    'offer' => ['type' => 'string', 'description' => 'Предложение для всех писем вызова: proposal:<id>. У строки может быть своё offer.'],
                    'cms_comm_account_id' => ['type' => 'integer', 'description' => 'Ваш ящик (cms_comm_account_list с правом отправки). Без него — ящик ответственного за клиента.'],
                    'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => $limits['perCall'], 'items' => $this->object($record + [
                        'offer' => ['type' => 'string'],
                    ], ['record_type', 'record_id', 'subject', 'body_html'])],
                ] + $confirm, ['cms_comm_topic_id', 'items'])),
            $this->tool('cms_comm_campaign_create',
                'Черновик кампании рассылки с личными письмами: тема рассылки, отправитель (ящики ответственных или ваш cms_comm_account_id), предложение — offer «proposal:<id>» (общая Презентация всем) '
                .'или offer_template_id (каждому своё КП из шаблона), задачи при открытии и ответе. Аудитория — только добавленные получатели (cms_comm_campaign_recipient_add). '
                .'ЗАПУСТИТЬ КАМПАНИЮ ЭТИМ ИЛИ ДРУГИМ ИНСТРУМЕНТОМ НЕЛЬЗЯ: её проверяет и запускает только сотрудник в разделе «Рассылки» (сводка, предпросмотр, «Запустить»). '
                .'Нужно право на раздел «Рассылки». Кампания отмечена «Подготовлено ИИ». Пример: {"name":"Плитка: личные предложения","cms_comm_topic_id":1,"offer":"proposal:10","confirm":true}.',
                'cms.communication.send', [$this, 'campaignCreate'], $this->object([
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
                'cms.communication.send', [$this, 'recipientAdd'], $this->object([
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

    /** The action for the OAuth user and client; the journal entry of what was done. */
    private function run(string $action, array $arguments, callable $callback): array
    {
        $user = \Yii::$app->user->identity;
        if (!$user) {
            throw new \DomainException('Нужен пользователь CRM из OAuth-входа.');
        }
        // cms-mcp before 1.0.14 does not know the client of the call: the mark says "mcp:клиент".
        $mcp = \Yii::$app->has('cmsMcp') ? \Yii::$app->cmsMcp : null;
        $client = $mcp && method_exists($mcp, 'getCurrentClientName') ? (string)$mcp->getCurrentClientName() : '';
        $apply = ($arguments['confirm'] ?? false) === true || ($arguments['confirm'] ?? null) === 'true' || ($arguments['confirm'] ?? null) === 1;
        $result = $callback(new AiActions($user, $client), $apply);

        ApiLogService::info('ai.action', [
            'action' => $action,
            'applied' => $apply,
            'oauth_client' => $client,
            'created' => $result['created'] ?? null,
            'refused' => $result['refused'] ?? null,
            'campaign_id' => $result['campaign']['id'] ?? ($arguments['cms_comm_campaign_id'] ?? null),
            'ids' => array_values(array_filter(array_map(static fn($r) => $r['draft_id'] ?? $r['recipient_id'] ?? null, (array)($result['items'] ?? [])))),
            'reasons' => array_values(array_filter(array_map(static fn($r) => $r['reason'] ?? null, (array)($result['items'] ?? [])))),
        ], self::logCategory(), true);

        return $result;
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
