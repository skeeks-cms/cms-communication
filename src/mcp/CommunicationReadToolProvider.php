<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\mcp\tools\CallbackTool;
use skeeks\cms\mcp\tools\McpToolProviderInterface;
use yii\base\Component;

/**
 * MCP/REST read tools of mail and mailings (skeeks/cms-mcp). Registered
 * through Yii::$app->params['cmsMcp']['toolProviders'] in this package's
 * config, which is inert when cms-mcp is not installed. Actions (drafts,
 * campaigns) are not here.
 */
class CommunicationReadToolProvider extends Component implements McpToolProviderInterface
{
    public $serviceConfig = MailReadService::class;

    public function getTools(): array
    {
        $service = \Yii::createObject($this->serviceConfig);
        $record = [
            'record_type' => ['type' => 'string', 'enum' => ['lead', 'company', 'client'], 'description' => 'Клиент CRM: сделка (продажа, lead), компания или клиент-человек.'],
            'record_id' => ['type' => 'integer'],
            'cms_lead_id' => ['type' => 'integer'], 'cms_company_id' => ['type' => 'integer'], 'cms_user_id' => ['type' => 'integer'],
        ];
        $contacts = ['include_contacts' => ['type' => 'boolean', 'description' => 'Адреса и телефоны полностью. По умолчанию замаскированы (i***@domain.ru, ***-**-67, [телефон] в тексте): данные могут уйти во внешнюю модель.']];
        return [
            $this->tool('cms_comm_message_list',
                'Письма, СМС и сообщения с сайта, видимые сотруднику (как в разделе «Почта»: его ящики и письма, привязанные к его клиентам), новые первыми. '
                .'Фильтры: клиент (record_type + record_id, include_related=true — со сделками (lead) и услугами (deal) компании), ящик cms_comm_account_id (cms_comm_account_list), переписка cms_comm_conversation_id, '
                .'direction (in/out), channel (email, sms, site), status, is_auto, q — поиск по словам в отправителе, получателях, теме и тексте, date_from/date_to. '
                .'Каждое письмо: kind (email_in, email_out, sms_out…), дата, тема, отправитель и получатели, snippet — начало собственного текста (без цитат и подписи), названия вложений, привязки к клиентам. '
                .'Текст целиком — cms_comm_message_get, вся переписка — cms_comm_conversation_get. Пример: {"record_type":"company","record_id":15,"limit":20}.',
                'cms.communication.read', [$service, 'messageList'], $this->object($record + [
                    'include_related' => ['type' => 'boolean'],
                    'cms_comm_account_id' => ['type' => 'integer'], 'cms_comm_conversation_id' => ['type' => 'integer'],
                    'direction' => ['type' => 'string', 'enum' => ['in', 'out']], 'channel' => ['type' => 'string'], 'status' => ['type' => 'string'], 'is_auto' => ['type' => 'boolean'],
                    'q' => ['type' => 'string'], 'date_from' => ['type' => ['string', 'integer']], 'date_to' => ['type' => ['string', 'integer']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0],
                ] + $contacts)),
            $this->tool('cms_comm_message_get',
                'Одно письмо (СМС, сообщение с сайта): тема, отправитель, получатели и копия, статус отправки, привязки к клиентам, названия вложений (сами файлы не отдаются) '
                .'и text — собственный текст письма без цитат предыдущих писем, подписи и HTML, не длиннее max_length (по умолчанию 4000 символов; text_is_cut=true — часть отрезана). Пример: {"id":1923}.',
                'cms.communication.read', [$service, 'messageGet'], $this->object([
                    'id' => ['type' => 'integer'], 'max_length' => ['type' => 'integer', 'minimum' => 500, 'maximum' => 20000],
                ] + $contacts, ['id'])),
            $this->tool('cms_comm_conversation_get',
                'Переписка целиком: письма одной цепочки в порядке написания (последние limit писем, по умолчанию 50), у каждого — собственный текст без цитат и подписи до max_length символов (по умолчанию 1500). '
                .'Видны только письма, доступные сотруднику. Передайте id переписки или cms_comm_message_id одного из её писем. Пример: {"cms_comm_message_id":1923}.',
                'cms.communication.read', [$service, 'conversationGet'], $this->object([
                    'id' => ['type' => 'integer'], 'cms_comm_message_id' => ['type' => 'integer'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'max_length' => ['type' => 'integer', 'minimum' => 300, 'maximum' => 10000],
                ] + $contacts)),
            $this->tool('cms_comm_account_list',
                'Почтовые ящики, к которым у сотрудника есть доступ: адрес, название, уровни чтения (all — все письма, linked — привязанные к клиентам) и отправки, signature_html — ваша подпись этого ящика (её сами добавляют черновики cms_comm_draft_create под текстом, один раз; не пишите подпись в body_html сами). Без подключений, серверов и паролей.',
                'cms.communication.read', [$service, 'accountList'], $this->object()),
            $this->tool('cms_comm_topic_list',
                'Темы рекламных рассылок сайта (на них клиент подписывается или отписывается): id, название, описание, активна ли. Нужны для cms_comm_mailing_check, '
                .'cms_comm_draft_create и кампаний. Пустой список — тем ещё нет: письмо с предложением создать нельзя, пока администратор не добавит тему (cms_comm_topic_create или «Настройки → Темы рассылок»). '
                .'Не подставляйте выдуманный id.',
                'cms.communication.read', static function (array $a) use ($service) {
                    $result = $service->topicList($a);
                    if (empty($result['items'])) {
                        $result['setup_required'] = 'На сайте нет '.(!empty($a['active_only']) ? 'действующих ' : '').'тем рассылок. Письма с предложением и кампании требуют тему: '
                            .'её добавляет администратор — cms_comm_topic_create (право cms.communication.write) или кнопка «Добавить начальные темы» в «Настройки → Темы рассылок».';
                    }

                    return $result;
                }, $this->object(['active_only' => ['type' => 'boolean']])),
            $this->tool('cms_comm_mailing_check',
                '«Можно ли писать»: проверка MailingPolicy перед рекламным письмом по теме — для всех email и телефонов клиента (record_type + record_id) или для списка values (до 200). '
                .'По каждому контакту: allowed, reason (unsubscribed_all, complaint, bounce, unsubscribed — от этой темы, no_consent, invalid, topic) и message. '
                .'mode=manager (по умолчанию) — личные письма менеджера из своего ящика, холодный контакт допустим; mode=service — сервис рассылок, только с согласием. '
                .'Служебные и личные письма проверка не ограничивает. Пример: {"record_type":"lead","record_id":50,"cms_comm_topic_id":1}.',
                'cms.communication.read', [$service, 'mailingCheck'], $this->object($record + [
                    'cms_comm_topic_id' => ['type' => 'integer', 'description' => 'Тема рассылки (cms_comm_topic_list).'],
                    'mode' => ['type' => 'string', 'enum' => ['manager', 'service']],
                    'channel' => ['type' => 'string', 'enum' => ['email', 'sms'], 'description' => 'Для values по умолчанию email; для клиента без channel — оба.'],
                    'values' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 200],
                ] + $contacts, ['cms_comm_topic_id'])),
            $this->tool('cms_comm_contact_state_get',
                'Состояние подписки контактов клиента (или адресов values): стоп-лист (отписан от всех, жалоба, письма не доставляются) с датами, по темам — подписан или отписан, основание согласия, когда и откуда отписка. '
                .'Контакт без отметок (has_marks=false) — холодный. Пример: {"record_type":"company","record_id":15}.',
                'cms.communication.read', [$service, 'contactState'], $this->object($record + [
                    'values' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 200],
                ] + $contacts)),
        ];
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
