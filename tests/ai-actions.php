<?php
/**
 * Action tools of AI clients (AI-2: drafts with an offer, campaigns of
 * personal letters, "Подготовлено ИИ"), against a local application with
 * cms-mcp and cms-proposal installed, in a transaction that is rolled back:
 * AI_ACTIONS_TEST=local php tests/ai-actions.php. Tools run through
 * McpComponent::executeTool() like REST and MCP, with an unsaved token of an
 * existing OAuth client. Identities: TEST_ADMIN_ID (1), TEST_WORKER_ID
 * (1663); TEST_OAUTH_CLIENT_ID — an OAuth client (the first one by default);
 * TEST_PRESENTATION_ID — a presentation for everybody (10); TEST_TOPIC_ID (1).
 * Letters are only queued inside the transaction: nothing leaves.
 */
if (getenv('AI_ACTIONS_TEST') !== 'local') {
    throw new RuntimeException('Set AI_ACTIONS_TEST=local.');
}
define('ROOT_DIR', getenv('TEST_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null, [Yiisoft\Config\Modifier\RecursiveMerge::groups('web', 'web-'.ENV, 'params', 'params-web-'.ENV)], 'params-web-'.ENV);
$c = $config->get($config->has('web-'.ENV) ? 'web-'.ENV : 'web');
$c['components']['request']['scriptFile'] = ROOT_DIR.'/frontend/web/index.php';
$c['components']['request']['scriptUrl'] = '/index.php';
$c['components']['request']['hostInfo'] = getenv('TEST_HOST_INFO') ?: 'http://localhost';
$c['components']['user']['enableSession'] = false;
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();

use skeeks\cms\communication\campaign\MailboxCampaignChannel;
use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommDraft;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\AiActions;
use skeeks\cms\communication\services\AiMark;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\communication\services\MailDrafts;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;
use skeeks\cms\oauth2\models\CmsOauthClient;

if (!Yii::$app->has('cmsMcp') || !Yii::$app->communication->getSendableSource('proposal')) {
    echo "SKIP: skeeks/cms-mcp or skeeks/cms-proposal is not installed\n";
    return;
}
$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function rejects(callable $callback, string $needle, $message) {
    try { $callback(); } catch (Throwable $e) {
        if ($needle !== '' && mb_strpos($e->getMessage(), $needle) === false) { throw new RuntimeException($message.': '.$e->getMessage()); }
        ++$GLOBALS['checks']; return;
    }
    throw new RuntimeException($message);
}
function run(string $tool, array $arguments) {
    global $client;
    $token = new CmsOauthAccessToken(['client_id' => (int)$client->id, 'scopes' => json_encode(array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']))]);
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}
function testLead(string $email, int $executor): CmsLead {
    $lead = new CmsLead(['name' => 'Тест ИИ '.$email, 'executor_id' => $executor]);
    check($lead->save(false), 'Test lead saved');
    (new CmsLeadEmail(['cms_lead_id' => (int)$lead->id, 'value' => $email]))->save(false);
    return $lead;
}
function row(array $result, int $i = 0): array { return $result['items'][$i]; }

$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    $worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
    $client = CmsOauthClient::findOne((int)(getenv('TEST_OAUTH_CLIENT_ID') ?: 0)) ?: CmsOauthClient::find()->orderBy(['id' => SORT_DESC])->one();
    $presentation = (int)(getenv('TEST_PRESENTATION_ID') ?: 10);
    $topic = (int)(getenv('TEST_TOPIC_ID') ?: 1);
    check($admin && $worker && $client, 'Local test identities and an OAuth client exist');
    Yii::$app->user->setIdentity($admin);
    $accountId = (int)(new MailComposeService($admin, $siteId))->defaultAccountId();
    check($accountId > 0, 'The administrator may send from a mailbox');
    $mcpClient = 'mcp:'.$client->name;

    // 1. Registration: preparing needs the write scope; only cms_comm_draft_send sends, with its own scope; nothing launches.
    foreach (['cms_comm_draft_create', 'cms_comm_campaign_create', 'cms_comm_campaign_recipient_add'] as $name) {
        $tool = Yii::$app->cmsMcp->getTool($name);
        check($tool->getRequiredScope() === 'cms.communication.write', $name.' needs cms.communication.write');
    }
    check(Yii::$app->cmsMcp->getTool('cms_comm_draft_send')->getRequiredScope() === 'cms.communication.mail_send', 'Sending needs its own scope');
    check(mb_strpos(Yii::$app->cmsMcp->getTool('cms_comm_campaign_create')->getDescription(), 'ЗАПУСТИТЬ КАМПАНИЮ ЭТИМ ИЛИ ДРУГИМ ИНСТРУМЕНТОМ НЕЛЬЗЯ') !== false, 'The campaign tool says it cannot launch');
    foreach (array_keys(Yii::$app->cmsMcp->getTools()) as $name) {
        check(!preg_match('/^cms_comm_.*(launch|start|run|send$)/', $name) || $name === 'cms_comm_draft_send', 'No tool launches a campaign; only cms_comm_draft_send sends: '.$name);
    }

    $lead1 = testLead('test-ai-unit-1@skeeks.com', (int)$admin->id);
    $lead2 = testLead('test-ai-unit-2@skeeks.com', (int)$admin->id);
    $lead3 = testLead('test-ai-unit-3@skeeks.com', (int)$admin->id);
    $body = '<p>Здравствуйте!</p><p>Мы сделали решение для вашей сферы.</p><p>{Ссылка}</p><p>{Отписаться}</p>';

    // 2. Without confirm: only checks, nothing saved.
    $before = (int)CmsCommDraft::find()->count();
    $dry = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic, 'offer' => 'proposal:'.$presentation,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead1->id, 'subject' => 'Предложение', 'body_html' => $body]]]);
    check($dry['requires_confirmation'] === true && row($dry)['status'] === 'ready' && $dry['created'] === 0, 'Without confirm the draft is only checked: '.json_encode(row($dry), JSON_UNESCAPED_UNICODE));
    check((int)CmsCommDraft::find()->count() === $before, 'Without confirm nothing is saved');

    // 3. A draft in the responsible employee's drafts, marked with the OAuth client; spoofed fields change nothing.
    $res = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic, 'offer' => 'proposal:'.$presentation, 'confirm' => true,
        'prepared_by' => 'user', 'by' => 'user', 'client' => 'crm',
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead1->id, 'subject' => 'Предложение', 'body_html' => $body,
            'prepared_by' => 'user', 'text_by' => 'user', 'prepared_client' => 'crm']]]);
    check(row($res)['status'] === 'created' && $res['created'] === 1, 'The draft is created: '.json_encode(row($res), JSON_UNESCAPED_UNICODE));
    $draft = CmsCommDraft::findOne((int)row($res)['draft_id']);
    check($draft && (int)$draft->cms_user_id === (int)$admin->id, 'The draft belongs to the responsible employee');
    check($draft->prepared_by === 'ai' && $draft->prepared_client === $mcpClient && (int)$draft->prepared_user_id === (int)$admin->id, 'The draft is marked by the OAuth client, not by arguments');
    check((int)$draft->cms_comm_topic_id === $topic && $draft->prepared_hash, 'The draft keeps its topic and the AI text fingerprint');
    check($draft->getSendableRefs() === ['proposal:'.$presentation], 'The presentation is put in as "Прикрепить из…"');
    check(strpos((string)$draft->body_html, \skeeks\cms\communication\services\SendService::placeholder('proposal', $presentation)) !== false, 'The offer button stands where {Ссылка} was');
    check((int)$draft->cms_comm_account_id === (int)(new MailComposeService($admin, $siteId))->defaultAccountId(), 'The draft uses the default mailbox of the responsible employee');
    check(in_array((int)$draft->id, array_map(static fn($d) => (int)$d->id, (new MailDrafts($admin, $siteId))->query()->all()), true), 'The draft is in "Черновики" of the employee');

    // 4. Refusals: an AI draft already waits; opted out of the topic; a recent letter; the day limit; the call limit; hidden client.
    $again = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic, 'confirm' => true,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead1->id, 'subject' => 'Ещё', 'body_html' => '<p>Ещё</p>']]]);
    check(row($again)['status'] === 'refused' && row($again)['reason'] === AiActions::REASON_DRAFT_EXISTS, 'A second AI draft for the client is refused');
    MailingConsents::unsubscribe(MailingContacts::get($siteId, 'email', 'test-ai-unit-2@skeeks.com'), $topic, 'test', (int)$admin->id);
    $opted = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic, 'confirm' => true,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead2->id, 'subject' => 'Тема', 'body_html' => '<p>Текст</p>']]]);
    check(row($opted)['status'] === 'refused' && row($opted)['reason'] === 'unsubscribed', 'An opted-out contact is refused: '.json_encode(row($opted), JSON_UNESCAPED_UNICODE));
    $dup = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead3->id, 'subject' => 'А', 'body_html' => '<p>А</p>'],
            ['record_type' => 'lead', 'record_id' => (int)$lead3->id, 'subject' => 'Б', 'body_html' => '<p>Б</p>']]]);
    check(row($dup, 0)['status'] === 'ready' && row($dup, 1)['status'] === 'refused', 'One letter per client in a call');
    $hidden = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic,
        'items' => [['record_type' => 'lead', 'record_id' => 999999999, 'subject' => 'А', 'body_html' => '<p>А</p>']]]);
    check(row($hidden)['reason'] === AiActions::REASON_NOT_FOUND, 'An unknown client is refused');
    Yii::$app->communication->aiLimits = ['perCall' => 2];
    rejects(fn() => run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic, 'items' => [
        ['record_type' => 'lead', 'record_id' => 1, 'subject' => 'А', 'body_html' => '<p>А</p>'],
        ['record_type' => 'lead', 'record_id' => 2, 'subject' => 'А', 'body_html' => '<p>А</p>'],
        ['record_type' => 'lead', 'record_id' => 3, 'subject' => 'А', 'body_html' => '<p>А</p>']]]), 'Не больше 2', 'More rows than perCall are refused as a whole');
    $used = (new AiActions($admin, $client->name))->usedToday();
    check($used >= 1, 'The draft counts for today');
    Yii::$app->communication->aiLimits = ['perDay' => $used];
    $limited = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead3->id, 'subject' => 'А', 'body_html' => '<p>А</p>']]]);
    check(row($limited)['reason'] === AiActions::REASON_DAY_LIMIT, 'The day limit refuses more drafts');
    Yii::$app->communication->aiLimits = [];
    rejects(fn() => run('cms_comm_draft_create', ['items' => [['record_type' => 'lead', 'record_id' => (int)$lead3->id]]]), 'purpose', 'A draft needs a purpose or a mailing topic');

    // 5. The employee sends the draft as in the mail screen: the letter carries the mark, the topic, the opt-out link, the send of the offer.
    $form = new ComposeForm(['service' => new MailComposeService($admin, $siteId)]);
    (new MailDrafts($admin, $siteId))->fill($form, $draft);
    (new MailDrafts($admin, $siteId))->applyAiDraft($form);
    check($form->aiMark && $form->aiMark['edited'] === false && (int)$form->cms_comm_topic_id === $topic, 'The unchanged AI draft gives its mark and topic to the letter');
    check($form->validate(), 'The letter from the AI draft is valid: '.json_encode($form->getFirstErrors(), JSON_UNESCAPED_UNICODE));
    $message = (new MailComposeService($admin, $siteId))->queue($form);
    $message->refresh();
    check($message->prepared_by === 'ai' && $message->prepared_client === $mcpClient && !$message->prepared_edited && (int)$message->sent_by_user_id === (int)$admin->id,
        'The letter is "Подготовлено ИИ" and sent by the employee');
    check((int)$message->cms_comm_topic_id === $topic && $message->unsubscribe_url, 'The letter goes with the personal opt-out link of the topic');
    $sent = CmsLog::find()->andWhere(['log_type' => 'proposal', 'model_id' => (int)$lead1->id])->orderBy(['id' => SORT_DESC])->one();
    check($sent && !empty($sent->data['ai']['client']) && (int)$sent->data['ai']['sent_by'] === (int)$admin->id, 'The feed entry of the send says AI prepared it and who sent it');
    $html = (new \skeeks\cms\communication\log\MessageLogRenderer())->render(CmsLog::find()->andWhere(['log_type' => CmsCommMessage::LOG_TYPE])->andWhere(['like', 'data', '"id":'.(int)$message->id])->one() ?: new CmsLog(['data' => ['id' => (int)$message->id]]));
    check(mb_strpos($html, 'Подготовлено ИИ') !== false, 'The card feed shows "Подготовлено ИИ"');
    $read = run('cms_comm_message_get', ['id' => (int)$message->id]);
    check(($read['prepared']['client'] ?? '') === $client->name, 'The read tool returns the mark');
    check(AiActions::lastLetterAt($lead1, time() - 3600) !== null, 'The letter counts as a recent letter');
    MailDrafts::delete($draft);
    Yii::$app->communication->aiLimits = [];
    $recent = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead1->id, 'subject' => 'А', 'body_html' => '<p>А</p>']]]);
    check(row($recent)['reason'] === AiActions::REASON_RECENT_LETTER, 'A client written to within 30 days is refused');
    $source = Yii::$app->communication->getSendableSource('proposal');
    check($source instanceof \skeeks\cms\communication\send\SendHistoryInterface && $source->lastSentAt($presentation, $lead1) !== null, 'The proposal knows it was sent to the client');

    // An edited AI draft: the mark stays, "edited".
    $res3 = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic, 'offer' => 'proposal:'.$presentation, 'confirm' => true,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead3->id, 'subject' => 'Предложение', 'body_html' => $body]]]);
    $draft3 = CmsCommDraft::findOne((int)row($res3)['draft_id']);
    $form = new ComposeForm(['service' => new MailComposeService($admin, $siteId)]);
    (new MailDrafts($admin, $siteId))->fill($form, $draft3);
    $form->body_html .= '<p>Добавил менеджер.</p>';
    (new MailDrafts($admin, $siteId))->applyAiDraft($form);
    check($form->aiMark && $form->aiMark['edited'] === true, 'A changed AI draft is marked as changed by the employee');
    // Another employee cannot use this draft id to borrow the mark.
    $form2 = new ComposeForm(['draft_id' => (int)$draft3->id, 'subject' => 'x', 'body_html' => '<p>x</p>']);
    (new MailDrafts($worker, $siteId))->applyAiDraft($form2);
    check($form2->aiMark === null, 'The mark comes only from an own draft');

    // 6. Campaign of personal letters: a draft, never launched by a tool; recipients by AI; an employee's change keeps the mark.
    $camp = run('cms_comm_campaign_create', ['name' => 'Тест ИИ', 'cms_comm_topic_id' => $topic, 'offer' => 'proposal:'.$presentation, 'confirm' => true,
        'status' => 'running', 'prepared_by' => 'user']);
    $campaign = CmsCommCampaign::findOne((int)$camp['campaign']['id']);
    check($campaign && $campaign->isDraft && $campaign->audience_type === CmsCommCampaign::AUDIENCE_MANUAL, 'The campaign is a draft of added recipients');
    check($campaign->prepared_by === 'ai' && $campaign->prepared_client === $mcpClient && $camp['campaign']['can_launch_here'] === false, 'The campaign is marked and cannot be launched by the tool');
    MailDrafts::delete($draft3);
    $lead4 = testLead('test-ai-unit-4@skeeks.com', (int)$admin->id);
    $add = run('cms_comm_campaign_recipient_add', ['cms_comm_campaign_id' => (int)$campaign->id, 'confirm' => true, 'items' => [
        ['record_type' => 'lead', 'record_id' => (int)$lead3->id, 'subject' => 'Лично вам', 'body_html' => '<p>Здравствуйте, {Имя}!</p><p>Текст ИИ.</p>', 'text_by' => 'user'],
        ['record_type' => 'lead', 'record_id' => (int)$lead4->id, 'subject' => 'Лично вам 2', 'body_html' => '<p>Текст ИИ 2.</p>'],
        ['record_type' => 'lead', 'record_id' => (int)$lead2->id, 'subject' => 'Отписан', 'body_html' => '<p>Текст</p>'],
    ]]);
    check($add['created'] === 2 && row($add, 2)['reason'] === 'unsubscribed', 'Two recipients added, the opted-out one refused: '.json_encode($add['items'], JSON_UNESCAPED_UNICODE));
    $recipient = CmsCommCampaignRecipient::findOne((int)row($add, 0)['recipient_id']);
    check($recipient->text_by === 'ai' && $recipient->text_client === $mcpClient && strpos((string)$recipient->body_html, '{Отписаться}') !== false
        && strpos((string)$recipient->body_html, '{Ссылка}') !== false, 'The personal letter is AI-marked, the offer and opt-out places were added');
    $draftLead = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead4->id, 'subject' => 'А', 'body_html' => '<p>А</p>']]]);
    check(row($draftLead)['reason'] === AiActions::REASON_IN_CAMPAIGN, 'A client waiting in a campaign gets no draft');
    (new Campaigns())->setText($recipient, 'Лично вам', '<p>Поправил менеджер.</p><p>{Ссылка}</p><p>{Отписаться}</p>', $admin);
    $recipient->refresh();
    check($recipient->text_by === 'ai' && (int)$recipient->text_edited_by === (int)$admin->id && mb_strpos($recipient->textLabel, 'изменён сотрудником') !== false,
        'An employee changing the AI text keeps the mark and is named');
    check(AiMark::ofRecipient($recipient)['edited'] === true, 'The letter of the recipient will carry the changed mark');
    // The worker has no campaign right and does not see the administrator's lead.
    Yii::$app->user->setIdentity($worker);
    rejects(fn() => run('cms_comm_campaign_create', ['name' => 'x', 'cms_comm_topic_id' => $topic]), '', 'The campaign tools need the campaign right (or the tool is closed to the worker)');
    $workerDraft = null;
    try {
        $workerDraft = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topic,
            'items' => [['record_type' => 'lead', 'record_id' => (int)$lead4->id, 'subject' => 'А', 'body_html' => '<p>А</p>']]]);
    } catch (Throwable $e) {
    }
    check(!$workerDraft || row($workerDraft)['status'] === 'refused', 'The worker gets nothing for a client they do not see');
    Yii::$app->user->setIdentity($admin);

    // The campaign letter itself (as the sending job makes it): marked, sent by the sender.
    $campaign->status = CmsCommCampaign::STATUS_RUNNING;
    $recipient->status = CmsCommCampaignRecipient::STATUS_PENDING;
    $recipient->cms_comm_account_id = $accountId;
    $letter = (new Campaigns())->letter($campaign, $recipient, $lead3, $admin, true);
    $campaignMessage = (new MailboxCampaignChannel())->deliver($letter);
    $campaignMessage->refresh();
    check($campaignMessage->prepared_by === 'ai' && (bool)$campaignMessage->prepared_edited && (int)$campaignMessage->cms_comm_campaign_id === (int)$campaign->id,
        'The campaign letter is "Подготовлено ИИ", changed by the employee');
} finally {
    $tx->rollBack();
}
echo "OK: {$checks} checks\n";
