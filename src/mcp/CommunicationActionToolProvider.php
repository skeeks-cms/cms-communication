<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\communication\services\AiActions;
use skeeks\cms\communication\services\AiSend;
use skeeks\cms\communication\services\DraftOwners;
use skeeks\cms\communication\services\MailDrafts;
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
 *   a checked draft with an idempotency key (AiSend);
 *
 * Drafts are the user's own or of an employee he manages (DraftOwners: his
 * subordinates by departments, every employee for an administrator): such a
 * draft is read, changed, checked and sent as its owner's (his mailbox,
 * signature, clients, day limit), the letter records who actually sent it.
 * A draft of anyone else is refused with not_own.
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
            'record_type' => ['type' => 'string', 'enum' => ['lead', 'company', 'client'], 'description' => 'Клиент CRM: сделка (продажа, lead), компания или клиент-человек.'],
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
        $purpose = ['purpose' => ['type' => 'string', 'enum' => array_keys(\skeeks\cms\communication\services\LetterPurpose::names()),
            'description' => 'Назначение письма. advertising — предложение без запроса клиента: нужна тема cms_comm_topic_id, проверяется отписка, в письме будет «Отписаться». '
                .'personal — рабочая переписка: клиент писал нам за последние 90 дней (или ответ на его письмо); без темы и блока отписки, жалоба и недоставка запрещают. '
                .'service — служебное письмо: у клиента действующая услуга или оплаченный счёт за год. '
                .'individual_offer — индивидуальное предложение одному человеку, только явно: своя тема и текст, обязательно offer (Презентация или КП), вложения и картинки; '
                .'тема cms_comm_topic_id обязательна и хранится внутри — по ней проверяются отказы, жалобы, недоставка и стоп-лист, повтор письма и предложения; '
                .'строки «Отписаться» и заголовков List-Unsubscribe нет. Не для кампаний и не для массовых писем. Индивидуальный текст, один получатель и приложенная Презентация '
                .'сами назначение не меняют. Без purpose и с темой — advertising.']];
        $testMode = ['test_mode' => ['type' => 'boolean', 'description' => 'Тестовый режим: только администратор и только для тестовых получателей из настройки сайта '
            .'(клиент + точный адрес, сейчас company:712 + asdasd@skeeks.com). Снимает только проверки повторного письма и повторного предложения; права, адрес, отписка, '
            .'вложения, лимиты, check_token, idempotency_key и подтверждение остаются. Передавайте одинаково в черновик, проверку и отправку.']];
        $attachments = ['attachments' => ['type' => 'array', 'maxItems' => \skeeks\cms\communication\services\AiDraftFiles::MAX_FILES,
            'description' => 'Файлы, загруженные вами через cms_storage_file_upload: [{"cms_storage_file_id":N,"inline":false}] — вложение; inline=true — картинка в тексте на месте «{Картинка}» (или в конце). '
                .'PNG, JPEG, GIF, WEBP, PDF по содержимому файла, до '.(\skeeks\cms\communication\services\AiDraftFiles::MAX_FILE_SIZE / 1048576).' МБ каждый, до '
                .(\skeeks\cms\communication\services\AiDraftFiles::MAX_TOTAL / 1048576).' МБ вместе. В письмо идёт копия: ваш файл не меняется и не удаляется.',
            'items' => ['type' => ['object', 'integer'], 'properties' => ['cms_storage_file_id' => ['type' => 'integer'], 'inline' => ['type' => 'boolean']]]]];
        // A campaign is a mailing: its letters always carry the opt-out line; an individual offer is refused.
        $campaignPurpose = ['purpose' => ['type' => 'string', 'description' => 'Только advertising (или не передавать): письма кампании всегда со строкой «Отписаться» и заголовками отписки. '
            .'individual_offer для кампаний запрещён — такие письма готовятся черновиками cms_comm_draft_create и отправляются по одному.']];
        $topicFields = [
            'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Название темы: его видит клиент на странице отписки.'],
            'description' => ['type' => ['string', 'null'], 'maxLength' => 2000, 'description' => 'Описание для клиента на странице отписки.'],
            'is_active' => ['type' => 'boolean'],
            'priority' => ['type' => 'integer'],
        ];

        return [
            $this->tool('cms_comm_draft_create',
                'Черновики личных писем клиентам: своя тема и текст каждому, предложение, файлы. Черновик появляется в «Черновиках» почты у ответственного за клиента сотрудника '
                .'(его ящик по умолчанию) или в вашем ящике cms_comm_account_id, если вам можно с него отправлять. Сам черновик ничего не отправляет: его отправляет сотрудник в «Почте», '
                .'а через API — после cms_comm_draft_check и cms_comm_draft_send (отдельное право) ваш черновик или черновик сотрудника, которым вы руководите (администратор — любого). '
                .'purpose — назначение: advertising (тема рассылки, «Отписаться»), individual_offer (индивидуальное предложение: тема только для проверки отказов, offer обязателен, без «Отписаться»), personal (рабочая переписка), service (служебное). '
                .'offer — предложение «proposal:<id>»: общая Презентация или КП этого клиента (cms_proposal_library_get, cms_proposal_list); личная ссылка создаётся при отправке, открытие видно в ленте и ставит задачу по правилам предложения. '
                .'attachments — ваши загруженные файлы: вложения или картинки в тексте. '
                .'Подпись не пишите: под текстом один раз добавится подпись отправителя для этого ящика из CRM (cms_comm_account_list → signature_html), как в «Почте».'
                .$rules.' Пример: {"purpose":"advertising","cms_comm_topic_id":1,"offer":"proposal:1","cms_comm_account_id":1,"items":[{"record_type":"company","record_id":712,"address":"asdasd@skeeks.com",'
                .'"subject":"…","body_html":"<p>Здравствуйте!</p><p>…</p><p>{Картинка}</p><p>{Ссылка}</p>","attachments":[{"cms_storage_file_id":501,"inline":true}]}],"confirm":true}.',
                'cms.communication.write', [$this, 'draftCreate'], $this->object($purpose + [
                    'cms_comm_topic_id' => ['type' => 'integer', 'description' => 'Тема рассылки: для advertising (и ссылка «Отписаться») и individual_offer (только проверка отказов, в письмо не выводится); у personal и service — нет.'],
                    'offer' => ['type' => 'string', 'description' => 'Предложение для всех писем вызова: proposal:<id>. У строки может быть своё offer.'],
                    'cms_comm_account_id' => ['type' => 'integer', 'description' => 'Ваш ящик (cms_comm_account_list с правом отправки). Без него — ящик ответственного за клиента.'],
                    'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => $limits['perCall'], 'items' => $this->object($record + [
                        'offer' => ['type' => 'string'],
                    ] + $attachments, ['record_type', 'record_id', 'subject', 'body_html'])],
                ] + $testMode + $confirm, ['items'])),
            $this->tool('cms_comm_draft_list',
                'Черновики писем из «Почты»: ваши или сотрудника, которым вы руководите по отделам (администратору — любого сотрудника), cms_user_id — чьи. '
                .'Возвращает employees — чьи черновики вам доступны (id, имя, сколько черновиков), и drafts: id, владелец, ящик, получатели, клиент CRM, тема, начало текста, '
                .'назначение, отметка ИИ, когда изменён. Черновик сотрудника читается, правится, проверяется и отправляется так же, как ваш: письмо уйдёт из его ящика, '
                .'с его подписью и в счёт его дневного лимита, а в ленте клиента будет видно, кто фактически отправил («по поручению»). '
                .'Пример: {"cms_user_id":12,"q":"плитка"}.',
                'cms.communication.read', [$this, 'draftList'], $this->object([
                    'cms_user_id' => ['type' => 'integer', 'description' => 'Чьи черновики: вы (по умолчанию) или доступный сотрудник из employees.'],
                    'q' => ['type' => 'string', 'description' => 'Поиск по теме, получателям и тексту.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Сколько черновиков, по умолчанию 50; сначала свежие.'],
                ])),
            $this->tool('cms_comm_draft_get',
                'Черновик — ваш или сотрудника, которым вы руководите (cms_comm_draft_list): владелец, получатель, клиент, ящик, назначение, будут ли строка и заголовки отписки (unsubscribe), тема рассылки и её роль, тема и полный текст письма (body_html), предложение, фактический состав файлов '
                .'(id, имя, MIME, размер, картинка в тексте или вложение, итог и лимиты), отметка ИИ. Пример: {"draft_id":43}.',
                'cms.communication.read', [$this, 'draftGet'], $this->object(['draft_id' => ['type' => 'integer']], ['draft_id'])),
            $this->tool('cms_comm_draft_update',
                'Изменить черновик ИИ — ваш или сотрудника, которым вы руководите (проверки — как у владельца: его ящик и клиенты): только переданные поля — subject, body_html, address (другой адрес из карточки клиента), purpose, cms_comm_topic_id, offer. '
                .'Файлы остаются (новый текст получает картинки на местах «{Картинка}» или в конце). Те же проверки, что при создании. После изменения нужна новая cms_comm_draft_check. '
                .'Без confirm=true — только проверка. Пример: {"draft_id":43,"subject":"Новая тема","confirm":true}.',
                'cms.communication.write', [$this, 'draftUpdate'], $this->object(['draft_id' => ['type' => 'integer'], 'subject' => ['type' => 'string'], 'body_html' => ['type' => 'string'],
                    'address' => ['type' => 'string'], 'cms_comm_topic_id' => ['type' => ['integer', 'null']], 'offer' => ['type' => 'string']] + $purpose + $testMode + $confirm, ['draft_id'])),
            $this->tool('cms_comm_draft_attachment_add',
                'Добавить к черновику (вашему или сотрудника, которым вы руководите) файл, загруженный вами через cms_storage_file_upload: вложение или (inline=true) картинку в тексте на месте «{Картинка}» или в конце. '
                .'Проверяется владелец файла, фактический тип по содержимому (PNG, JPEG, GIF, WEBP, PDF; SVG, HTML, архивы и программы — нет), размер и количество. '
                .'Тот же файл повторно не добавляется (вернётся уже добавленный). В письмо идёт копия, ваш файл в хранилище не меняется. '
                .'После добавления нужна новая cms_comm_draft_check. Пример: {"draft_id":43,"cms_storage_file_id":501,"inline":true}.',
                'cms.communication.write', [$this, 'attachmentAdd'], $this->object(['draft_id' => ['type' => 'integer'], 'cms_storage_file_id' => ['type' => 'integer'],
                    'inline' => ['type' => 'boolean']], ['draft_id', 'cms_storage_file_id'])),
            $this->tool('cms_comm_draft_attachment_remove',
                'Убрать файл из черновика (вашего или сотрудника, которым вы руководите) по id из cms_comm_draft_get (картинка уходит и из текста). Загруженный вами файл в хранилище остаётся. '
                .'Пример: {"draft_id":43,"attachment_id":7}.',
                'cms.communication.write', [$this, 'attachmentRemove'], $this->object(['draft_id' => ['type' => 'integer'], 'attachment_id' => ['type' => 'integer']],
                    ['draft_id', 'attachment_id'])),
            $this->tool('cms_comm_draft_check',
                'Проверка черновика перед отправкой через API: вашего или сотрудника, которым вы руководите по отделам (администратору — любого; чужой — отказ not_own). '
                .'Черновик сотрудника проверяется как его: его ящик и право отправки, его клиенты, его подпись и дневной лимит (draft.owner, draft.acting_for). '
                .'Ничего не отправляет, не создаёт личную ссылку и не засчитывает открытие. '
                .'Проверяет то же, что форма письма в «Почте» (ящик и право отправки с него, адреса, «только свои клиенты», MailingPolicy темы, документ), и правила ИИ: '
                .'одному адресу из карточки клиента CRM, без копий, нет письма клиенту и отправки этого предложения за '.$limits['recentDays'].' дней, предложение можно отправить (срок, адрес страницы), '
                .'дневной лимит отправок ('.$limits['sendPerDay'].'). Возвращает получателя, отправителя, тему, предложение, предпросмотр (тема, текст, HTML с неработающей кнопкой) и problems. '
                .'Назначение письма — по правилам purpose (у personal и service нет темы и блока отписки, у advertising — тема и «Отписаться», у individual_offer — тема для проверки отказов и предложение, без «Отписаться»); '
                .'unsubscribe — будут ли строка отписки и заголовки List-Unsubscribe (входит в check_token); attachments — фактические файлы '
                .'(id, имя, MIME, размер, итог, лимиты); signature — подпись ящика из CRM и есть ли она в письме; warnings — предупреждения (например, адрес отписан от рекламы). '
                .'Пройденная проверка даёт check_token — отпечаток получателя, отправителя, назначения, темы, текста, предложения, файлов (по содержимому) и тестового режима; '
                .'он нужен для cms_comm_draft_send. Пример: {"draft_id":43}.',
                'cms.communication.write', [$this, 'draftCheck'], $this->object([
                    'draft_id' => ['type' => 'integer', 'description' => 'Черновик: ваш или доступного сотрудника (cms_comm_draft_create возвращает draft_id, cms_comm_draft_list — список).'],
                ] + $testMode, ['draft_id'])),
            $this->tool('cms_comm_draft_send',
                'Отправить проверенный черновик клиенту: только после cms_comm_draft_check и явного согласия человека на этого получателя, отправителя, текст и предложение. '
                .'Нужны draft_id, check_token из проверки, idempotency_key и confirm=true. Если черновик изменился после проверки — отказ changed_after_check, ничего не отправлено. '
                .'Черновик сотрудника, которым вы руководите, уходит из его ящика с его подписью и в счёт его лимита; в письме и ленте клиента записано, что отправили вы по его поручению. '
                .'idempotency_key — новый уникальный ключ на каждое письмо (8–128 символов): повтор с тем же ключом и теми же данными вернёт прежний результат без второго письма, '
                .'с другими данными — отказ idempotency_key_reused. Если ответ потерян (тайм-аут), не отправляйте заново, а узнайте результат по ключу: cms_comm_send_status. '
                .'Отказ api_send_disabled — отправка через API выключена в настройках сайта («Настройки» → «Настройки почты» → «Работа ИИ через API»): черновик остаётся, его отправляют из «Почты». '
                .'Письмо уходит штатно: файлы вложениями (картинки в тексте — внутри письма), личная ссылка на предложение, «Отписаться» только у advertising, лента клиента, '
                .'отметка «Отправлено ИИ через API». test_mode — как в проверке. '
                .'Результат: state — queued (в очереди), sending, sent (принято почтовым сервером отправителя, это не доставка), failed, unknown (неизвестно, повторно не отправляется); '
                .'delivery — not_confirmed или bounced; id письма и отправки предложения. Без confirm=true — только проверка. '
                .'cms_comm_campaign_id — кампания потока (П2д): письмо и получатель записываются в неё, ответ, просмотр и переход создают сделку по её настройкам; клиент под защитой у другого менеджера — отказ. '
                .'Пример: {"draft_id":43,"check_token":"…","idempotency_key":"offer-712-20261024-1","confirm":true}.',
                'cms.communication.mail_send', [$this, 'draftSend'], $this->object([
                    'draft_id' => ['type' => 'integer'],
                    'check_token' => ['type' => 'string'],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
                    'cms_comm_campaign_id' => ['type' => 'integer'],
                    'confirm' => ['type' => 'boolean'],
                ] + $testMode, ['draft_id', 'check_token', 'idempotency_key'])),
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
                    'task_on_open' => ['type' => 'boolean', 'description' => 'Задача «Позвонить», когда просмотр или переход не создаёт сделку.'],
                    'task_on_reply' => ['type' => 'boolean'],
                    'lead_on_view' => ['type' => 'boolean', 'description' => 'Сделка при просмотре Презентации или КП (ответ создаёт сделку всегда).'],
                    'lead_on_visit' => ['type' => 'boolean', 'description' => 'Сделка при переходе по ссылке из письма.'],
                    'new_card_owner' => ['type' => 'string', 'enum' => ['sender', 'worker'], 'description' => 'Ответственный за новые компании потока: sender — вы (по умолчанию), worker — new_card_worker_id.'],
                    'new_card_worker_id' => ['type' => 'integer'],
                    'lead_to' => ['type' => 'string', 'enum' => ['unsorted', 'sender', 'worker'], 'description' => 'Куда сделка: «Неразобранное», отправитель письма, сотрудник lead_worker_id.'],
                    'lead_worker_id' => ['type' => 'integer'],
                    'lead_pipeline_id' => ['type' => 'integer', 'description' => 'Воронка сделок; пусто — «Исходящие продажи».'],
                ] + $campaignPurpose + $confirm, ['name', 'cms_comm_topic_id'])),
            $this->tool('cms_comm_campaign_recipient_add',
                'Получатели черновика кампании с личной темой и текстом каждому (повторный вызов для того же клиента заменяет текст). {Ссылка} и {Отписаться} обязательны в письме — без них добавятся в конце; '
                .'{Имя}, {Компания}, {Менеджер} подставятся при отправке. Кампания остаётся черновиком: запускает её только сотрудник в интерфейсе, инструмента запуска нет.'
                .$rules.' Пример: {"cms_comm_campaign_id":4,"items":[{"record_type":"company","record_id":15,"subject":"…","body_html":"<p>…</p><p>{Ссылка}</p><p>{Отписаться}</p>"}],"confirm":true}.',
                'cms.communication.write', [$this, 'recipientAdd'], $this->object([
                    'cms_comm_campaign_id' => ['type' => 'integer'],
                    'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => $limits['perCall'], 'items' => $this->object($record + [
                        'name' => ['type' => 'string', 'description' => 'Имя для {Имя}.'],
                    ], ['record_type', 'record_id', 'subject', 'body_html'])],
                ] + $campaignPurpose + $confirm, ['cms_comm_campaign_id', 'items'])),
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
        $testMode = !empty($arguments['test_mode']);
        $result = $this->sender()->check((int)($arguments['draft_id'] ?? 0), $testMode);
        $this->log('draft_check', ['draft_id' => (int)($arguments['draft_id'] ?? 0), 'ok' => $result['ok'], 'test_mode' => $testMode,
            'purpose' => $result['purpose']['code'] ?? null, 'reasons' => array_column($result['problems'], 'reason')]);

        return $result;
    }

    public function draftSend(array $arguments): array
    {
        $testMode = !empty($arguments['test_mode']);
        $result = $this->sender()->send((int)($arguments['draft_id'] ?? 0), (string)($arguments['check_token'] ?? ''),
            (string)($arguments['idempotency_key'] ?? ''), self::confirmed($arguments), $testMode,
            (int)($arguments['cms_comm_campaign_id'] ?? 0) ?: null);
        $this->log('draft_send', [
            'draft_id' => (int)($arguments['draft_id'] ?? 0),
            'test_mode' => $testMode,
            'purpose' => $result['message']['purpose'] ?? null,
            'state' => $result['state'] ?? null,
            'repeated' => $result['repeated'] ?? null,
            'cms_comm_message_id' => $result['message']['id'] ?? null,
            'reasons' => array_column((array)($result['problems'] ?? []), 'reason'),
        ]);

        return $result;
    }

    /** Drafts of the user or of an employee he manages, and whose drafts he may see. */
    public function draftList(array $arguments): array
    {
        $user = $this->user();
        $owners = new DraftOwners($user);
        $ownerId = (int)($arguments['cms_user_id'] ?? 0) ?: (int)$user->id;
        if (!$owners->canManage($ownerId)) {
            return ['success' => false, 'reason' => AiSend::REASON_NOT_OWN,
                'message' => 'Черновики этого сотрудника вам недоступны: вы не его руководитель по отделам и не администратор. Доступные — в employees.',
                'employees' => $this->draftEmployees($owners)];
        }
        $owner = \skeeks\cms\models\CmsUser::findOne($ownerId);
        $limit = max(1, min(100, (int)($arguments['limit'] ?? 50)));
        $drafts = (new MailDrafts($owner))->query(mb_substr((string)($arguments['q'] ?? ''), 0, 100))->with('account')->limit($limit)->all();
        $compose = new \skeeks\cms\communication\services\MailComposeService($owner);

        return [
            'success' => true,
            'owner' => ['id' => (int)$owner->id, 'name' => (string)$owner->shortDisplayName, 'is_me' => (int)$owner->id === (int)$user->id],
            'employees' => $this->draftEmployees($owners),
            'drafts' => array_map(static function ($draft) use ($compose) {
                $record = $draft->context_model_code && $draft->context_model_id ? $compose->contextRecord((string)$draft->context_model_code, (int)$draft->context_model_id) : null;

                return [
                    'id' => (int)$draft->id,
                    'owner_id' => (int)$draft->cms_user_id,
                    'sender' => $draft->account ? ['cms_comm_account_id' => (int)$draft->account->id, 'address' => (string)$draft->account->address] : null,
                    'to' => $draft->getToAddresses(),
                    'client' => $record ? ['type' => \skeeks\cms\communication\services\CrmRecords::typeOfCode(get_class($record)), 'id' => (int)$record->primaryKey,
                        'name' => \skeeks\cms\communication\services\CrmRecords::name($record)] : null,
                    'subject' => (string)$draft->subject,
                    'snippet' => $draft->getSnippet(200),
                    'purpose' => \skeeks\cms\communication\services\LetterPurpose::of($draft->purpose, $draft->cms_comm_topic_id ? (int)$draft->cms_comm_topic_id : null),
                    'offers' => $draft->getSendableRefs(),
                    'prepared' => \skeeks\cms\communication\services\AiMark::forApi(\skeeks\cms\communication\services\AiMark::of($draft)),
                    'updated_at' => $draft->updated_at ? date('c', (int)$draft->updated_at) : null,
                ];
            }, $drafts),
        ];
    }

    /** @return array[] id, name, drafts — employees whose drafts the user may work with, himself first */
    private function draftEmployees(DraftOwners $owners): array
    {
        $counts = \skeeks\cms\communication\models\CmsCommDraft::find()->select(['cnt' => 'COUNT(*)', 'cms_user_id'])
            ->andWhere(['cms_site_id' => (int)\Yii::$app->skeeks->site->id, 'cms_user_id' => $owners->ids()])
            ->groupBy('cms_user_id')->indexBy('cms_user_id')->column();
        $result = [];
        foreach ($owners->employees() as $id => $employee) {
            $result[] = ['id' => (int)$id, 'name' => (string)$employee->shortDisplayName, 'drafts' => (int)($counts[$id] ?? 0)];
        }

        return $result;
    }

    /**
     * A draft of the user or of an employee he manages, with the actions of its owner.
     *
     * @return array [AiActions of the owner (the mark and limits of the user), CmsCommDraft, CmsUser owner]
     * @throws \DomainException not found or not available (not_own)
     */
    private function draftOf(int $draftId): array
    {
        $user = $this->user();
        $draft = (new DraftOwners($user))->find($draftId);
        $owner = $draft ? DraftOwners::owner($draft) : null;
        if (!$draft || !$owner) {
            $other = \skeeks\cms\communication\models\CmsCommDraft::find()->andWhere(['id' => $draftId, 'cms_site_id' => (int)\Yii::$app->skeeks->site->id])->one();
            throw new \DomainException($other ? AiSend::notOwnMessage($other) : 'Черновик не найден: он отправлен, удалён или не существует.');
        }

        return [new AiActions($owner, self::clientName(), null, $user), $draft, $owner];
    }

    public function draftGet(array $arguments): array
    {
        [, $draft, $owner] = $this->draftOf((int)($arguments['draft_id'] ?? 0));
        $account = $draft->account;
        $record = $draft->context_model_code && $draft->context_model_id
            ? (new \skeeks\cms\communication\services\MailComposeService($owner))->contextRecord((string)$draft->context_model_code, (int)$draft->context_model_id) : null;
        $purpose = \skeeks\cms\communication\services\LetterPurpose::of($draft->purpose, $draft->cms_comm_topic_id ? (int)$draft->cms_comm_topic_id : null);
        $topic = $draft->cms_comm_topic_id ? \skeeks\cms\communication\models\CmsCommTopic::findOne((int)$draft->cms_comm_topic_id) : null;

        return [
            'id' => (int)$draft->id,
            'owner' => ['id' => (int)$owner->id, 'name' => (string)$owner->shortDisplayName],
            // A draft of an employee the user manages: it leaves from the employee's mailbox, "по поручению" of the user.
            'acting_for' => (int)$owner->id !== (int)$this->user()->id,
            'to' => $draft->getToAddresses(),
            'client' => $record ? ['type' => \skeeks\cms\communication\services\CrmRecords::typeOfCode(get_class($record)), 'id' => (int)$record->primaryKey,
                'name' => \skeeks\cms\communication\services\CrmRecords::name($record)] : null,
            'sender' => $account ? ['cms_comm_account_id' => (int)$account->id, 'address' => (string)$account->address] : null,
            'purpose' => $purpose,
            // A draft is never a campaign letter: the opt-out line and headers belong to advertising only.
            'unsubscribe' => ['line' => $topic && \skeeks\cms\communication\services\LetterPurpose::optOut($purpose),
                'headers' => $topic && \skeeks\cms\communication\services\LetterPurpose::optOut($purpose)],
            'topic' => $topic ? ['id' => (int)$topic->id, 'name' => (string)$topic->name,
                'role' => \skeeks\cms\communication\services\LetterPurpose::optOut($purpose) ? 'рассылка: ссылка «Отписаться» и заголовки отписки' : 'только проверка отказов получателя, в письмо не выводится'] : null,
            'subject' => (string)$draft->subject,
            'snippet' => $draft->getSnippet(500),
            'body_html' => (string)$draft->body_html,
            'signature' => \skeeks\cms\communication\services\AiSend::signature((int)$owner->id, (int)$draft->cms_comm_account_id, (string)$draft->body_html),
            'offers' => $draft->getSendableRefs(),
            'attachments' => \skeeks\cms\communication\services\AiDraftFiles::describe($draft),
            'prepared' => \skeeks\cms\communication\services\AiMark::forApi(\skeeks\cms\communication\services\AiMark::of($draft)),
            'updated_at' => $draft->updated_at ? date('c', (int)$draft->updated_at) : null,
        ];
    }

    public function draftUpdate(array $arguments): array
    {
        [$actions] = $this->draftOf((int)($arguments['draft_id'] ?? 0));

        return $this->run('draft_update', $arguments, static fn(AiActions $ai, bool $apply) => $ai->updateDraft($arguments, $apply), $actions);
    }

    public function attachmentAdd(array $arguments): array
    {
        $user = $this->user();
        [, $draft] = $this->draftOf((int)($arguments['draft_id'] ?? 0));
        // The file is the user's own upload, whoever owns the draft.
        $files = new \skeeks\cms\communication\services\AiDraftFiles($user, (int)\Yii::$app->skeeks->site->id);
        try {
            $file = $files->add($draft, (int)($arguments['cms_storage_file_id'] ?? 0), !empty($arguments['inline']));
        } catch (\skeeks\cms\communication\services\AiRefusal $e) {
            return ['success' => false, 'reason' => $e->reason, 'message' => $e->getMessage()];
        }
        $this->log('draft_attachment_add', ['draft_id' => (int)$draft->id, 'attachment_id' => (int)$file->id, 'inline' => (bool)$file->is_inline]);

        return ['success' => true, 'attachment_id' => (int)$file->id, 'attachments' => \skeeks\cms\communication\services\AiDraftFiles::describe($draft),
            'note' => 'Перед отправкой снова cms_comm_draft_check.'];
    }

    public function attachmentRemove(array $arguments): array
    {
        $user = $this->user();
        [, $draft] = $this->draftOf((int)($arguments['draft_id'] ?? 0));
        $removed = (new \skeeks\cms\communication\services\AiDraftFiles($user, (int)\Yii::$app->skeeks->site->id))->remove($draft, (int)($arguments['attachment_id'] ?? 0));
        $this->log('draft_attachment_remove', ['draft_id' => (int)$draft->id, 'attachment_id' => (int)($arguments['attachment_id'] ?? 0), 'removed' => $removed]);

        return ['success' => $removed, 'message' => $removed ? null : 'Такого файла в черновике нет.', 'attachments' => \skeeks\cms\communication\services\AiDraftFiles::describe($draft)];
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
        // A reply to a chat sent through the API (cms_comm_chat_reply_send) shares the keys: its own status.
        if (!$row->cms_comm_draft_id && $row->cms_comm_message_id
            && \skeeks\cms\communication\models\CmsCommMessage::find()->andWhere(['id' => (int)$row->cms_comm_message_id])->andWhere(['!=', 'channel', 'email'])->exists()) {
            return ['found' => true] + (new ChatToolService($this->user(), self::clientName(), (int)\Yii::$app->skeeks->site->id))->sendStatus($row);
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

    /** The action for the OAuth user and client (or the given actions: a draft of an employee); the journal entry of what was done. */
    private function run(string $action, array $arguments, callable $callback, ?AiActions $actions = null): array
    {
        $apply = self::confirmed($arguments);
        $result = $callback($actions ?? new AiActions($this->user(), self::clientName()), $apply);

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
