<?php
/**
 * The full cycle of a personal letter through MCP/REST: mailing topics and
 * presentations by API, a draft in the own mailbox, the check without side
 * effects, the send with a check token and an idempotency key, the status
 * that never calls a queued letter delivered. Against a local application
 * with cms-mcp and cms-proposal, in a transaction that is rolled back:
 * AI_SEND_TEST=local php tests/ai-send.php. Letters are only queued inside the
 * transaction: nothing leaves. Identities: TEST_ADMIN_ID (1), TEST_WORKER_ID
 * (1663), TEST_OAUTH_CLIENT_ID (an OAuth client).
 */
if (getenv('AI_SEND_TEST') !== 'local') {
    throw new RuntimeException('Set AI_SEND_TEST=local.');
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

use skeeks\cms\communication\models\CmsCommApiSend;
use skeeks\cms\communication\models\CmsCommDraft;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\AiMark;
use skeeks\cms\communication\services\AiSend;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;
use skeeks\cms\oauth2\models\CmsOauthClient;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalView;

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
function run(string $tool, array $arguments, ?array $scopes = null) {
    global $client;
    $scopes = $scopes ?? array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']);
    $token = new CmsOauthAccessToken(['client_id' => (int)$client->id, 'scopes' => json_encode($scopes)]);
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}
function testLead(string $email, int $executor): CmsLead {
    $lead = new CmsLead(['name' => 'Тест API '.$email, 'executor_id' => $executor, 'cms_site_id' => (int)Yii::$app->skeeks->site->id]);
    check($lead->save(false), 'Test lead saved');
    (new CmsLeadEmail(['cms_lead_id' => (int)$lead->id, 'value' => $email]))->save(false);
    return $lead;
}
function proposalSends(): int { return (int)CmsProposalSend::find()->count(); }

$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    $worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
    $client = CmsOauthClient::findOne((int)(getenv('TEST_OAUTH_CLIENT_ID') ?: 0)) ?: CmsOauthClient::find()->orderBy(['id' => SORT_DESC])->one();
    check($admin && $worker && $client, 'Local identities and an OAuth client exist');
    Yii::$app->user->setIdentity($admin);
    $accountId = (int)(new MailComposeService($admin, $siteId))->defaultAccountId();
    check($accountId > 0, 'The administrator may send from a mailbox');
    $suffix = bin2hex(random_bytes(3));

    // 1. Scopes: preparing and sending are separate grants; the status is a read.
    $scope = static fn($name) => Yii::$app->cmsMcp->getTool($name)->getRequiredScope();
    check($scope('cms_comm_draft_check') === 'cms.communication.write' && $scope('cms_comm_draft_send') === 'cms.communication.mail_send'
        && $scope('cms_comm_send_status') === 'cms.communication.read' && $scope('cms_comm_topic_create') === 'cms.communication.write'
        && $scope('cms_proposal_presentation_create') === 'cms.proposal.write', 'Scopes of the cycle');
    $withoutSend = array_values(array_diff(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes'], ['cms.communication.mail_send']));
    rejects(fn() => run('cms_comm_draft_send', ['draft_id' => 1, 'check_token' => 'x', 'idempotency_key' => 'test-key-0001', 'confirm' => true], $withoutSend),
        'cms.communication.mail_send', 'A token that prepares drafts cannot send');

    // 2. Topics by API: administrator only, unique name, partial update; an empty list says what to set up.
    $dry = run('cms_comm_topic_create', ['name' => 'Тест API '.$suffix, 'description' => 'Описание']);
    check($dry['requires_confirmation'] && !$dry['saved'] && !CmsCommTopic::find()->andWhere(['name' => 'Тест API '.$suffix])->exists(), 'A topic is only checked without confirm');
    $created = run('cms_comm_topic_create', ['name' => 'Тест API '.$suffix, 'description' => 'Описание', 'confirm' => true]);
    $topicId = (int)$created['topic']['id'];
    check($created['saved'] && $topicId > 0, 'A topic is created');
    check(!run('cms_comm_topic_create', ['name' => 'Тест API '.$suffix, 'confirm' => true])['success'], 'A topic with the same name is refused');
    $updated = run('cms_comm_topic_update', ['id' => $topicId, 'description' => 'Новое', 'confirm' => true]);
    check($updated['topic']['name'] === 'Тест API '.$suffix && $updated['topic']['description'] === 'Новое' && $updated['changed'] === ['description'], 'Partial topic update');
    Yii::$app->user->setIdentity($worker);
    rejects(fn() => run('cms_comm_topic_create', ['name' => 'x'.$suffix, 'confirm' => true]), '', 'A worker cannot manage topics');
    Yii::$app->user->setIdentity($admin);
    CmsCommTopic::updateAll(['is_active' => 0], ['cms_site_id' => $siteId]);
    $empty = run('cms_comm_topic_list', ['active_only' => true]);
    check(!$empty['items'] && mb_strpos((string)($empty['setup_required'] ?? ''), 'cms_comm_topic_create') !== false, 'An empty topic list says how to set it up');
    CmsCommTopic::updateAll(['is_active' => 1], ['id' => $topicId]);

    // 3. Presentations by API: shared, in the library, duplicate check, partial update.
    $url = 'https://skeeks.com/solutions/plitka-test-'.$suffix;
    $p = run('cms_proposal_presentation_create', ['name' => 'Тест API презентация', 'target_url' => $url, 'message' => '<p>Вводный текст</p>', 'confirm' => true]);
    $presentationId = (int)$p['presentation']['id'];
    check($p['saved'] && $presentationId && $p['presentation']['is_shared'] && $p['presentation']['status'] === 'draft' && !empty($p['presentation']['url']), 'A shared presentation is created');
    $library = run('cms_proposal_library_get', []);
    check(in_array($presentationId, array_column($library['presentations'] ?? [], 'id'), true), 'It is in the library');
    $dup = run('cms_proposal_presentation_create', ['name' => 'Ещё', 'target_url' => $url.'/', 'confirm' => true]);
    check(!$dup['success'] && (int)($dup['duplicates'][0]['id'] ?? 0) === $presentationId, 'The same page for everybody is a duplicate');
    $upd = run('cms_proposal_presentation_update', ['id' => $presentationId, 'valid_until' => date('Y-m-d', strtotime('+30 days')), 'confirm' => true]);
    $reread = \skeeks\cms\proposal\models\CmsProposal::findOne($presentationId);
    check($upd['saved'] && $reread->name === 'Тест API презентация' && $reread->target_url === $url && mb_strpos((string)$reread->message, 'Вводный') !== false
        && $reread->valid_until > time(), 'Editing one field keeps the others');
    rejects(fn() => run('cms_proposal_presentation_update', ['id' => 999999999, 'name' => 'x']), 'не найдена', 'An unknown presentation is refused');

    // 4. A draft in the own mailbox with the presentation and a personal text.
    $lead1 = testLead('test-ai-send-1@skeeks.com', (int)$worker->id);
    $lead2 = testLead('test-ai-send-2@skeeks.com', (int)$admin->id);
    $body = '<p>Здравствуйте!</p><p>Посмотрели ваш каталог плитки.</p><p>{Ссылка}</p><p>{Отписаться}</p>';
    $draft = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topicId, 'offer' => 'proposal:'.$presentationId, 'cms_comm_account_id' => $accountId, 'confirm' => true,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead1->id, 'address' => 'test-ai-send-1@skeeks.com', 'subject' => 'Тема для лида 1', 'body_html' => $body]]]);
    $draftId = (int)($draft['items'][0]['draft_id'] ?? 0);
    check($draftId && (int)CmsCommDraft::findOne($draftId)->cms_user_id === (int)$admin->id, 'The draft is in the own drafts (own mailbox): '.json_encode($draft['items'], JSON_UNESCAPED_UNICODE));

    // 5. The check sends nothing, makes no link and no opening; the preview has a dead button.
    $sendsBefore = proposalSends();
    $messagesBefore = (int)CmsCommMessage::find()->count();
    $c1 = run('cms_comm_draft_check', ['draft_id' => $draftId]);
    check($c1['ok'] && strlen($c1['check_token']) === 64 && !$c1['problems'], 'The check passes: '.json_encode($c1['problems'], JSON_UNESCAPED_UNICODE));
    check($c1['recipient']['address'] === 'test-ai-send-1@skeeks.com' && (int)$c1['recipient']['client']['id'] === (int)$lead1->id
        && (int)$c1['sender']['cms_comm_account_id'] === $accountId && (int)$c1['topic']['id'] === $topicId && $c1['offers'][0]['ref'] === 'proposal:'.$presentationId, 'The check names recipient, sender, topic, offer');
    check(mb_strpos($c1['preview']['html'], '#offer-preview') !== false && mb_strpos($c1['preview']['html'], '/~kp/') === false && $c1['preview']['subject'] === 'Тема для лида 1', 'The preview has a dead button');
    check(proposalSends() === $sendsBefore && (int)CmsCommMessage::find()->count() === $messagesBefore, 'The check creates no send and no letter');
    check(run('cms_comm_draft_check', ['draft_id' => $draftId])['check_token'] === $c1['check_token'], 'The token is stable');

    // 6. Send: a key is required; without confirm nothing; a change after the check refuses.
    rejects(fn() => run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $c1['check_token'], 'idempotency_key' => 'x', 'confirm' => true]), 'idempotency_key', 'A short key is refused');
    $key = 'test-ai-send-'.$suffix;
    $noConfirm = run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $c1['check_token'], 'idempotency_key' => $key]);
    check($noConfirm['requires_confirmation'] && !$noConfirm['sent'] && !CmsCommApiSend::find()->andWhere(['idempotency_key' => $key])->exists(), 'Without confirm nothing is sent and the key is free');
    CmsCommDraft::updateAll(['subject' => 'Тема изменена'], ['id' => $draftId]);
    $changed = run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $c1['check_token'], 'idempotency_key' => $key, 'confirm' => true]);
    check($changed['state'] === 'refused' && $changed['problems'][0]['reason'] === AiSend::REASON_CHANGED && (int)CmsCommMessage::find()->count() === $messagesBefore, 'A change after the check refuses the send');
    $c2 = run('cms_comm_draft_check', ['draft_id' => $draftId]);
    check($c2['ok'] && $c2['check_token'] !== $c1['check_token'], 'A new check gives a new token');

    // 7. The send: queued, not delivered; the offer send exists, not opened; the draft is gone; the mark names the API.
    $sent = run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $c2['check_token'], 'idempotency_key' => $key, 'confirm' => true]);
    $messageId = (int)($sent['message']['id'] ?? 0);
    check($sent['sent'] && !$sent['repeated'] && $sent['state'] === CmsCommMessage::STATUS_QUEUED && $sent['delivery']['state'] === 'not_confirmed', 'The letter is queued, delivery not confirmed: '.json_encode($sent, JSON_UNESCAPED_UNICODE));
    check(!CmsCommDraft::find()->andWhere(['id' => $draftId])->exists(), 'The sent draft is removed');
    $offer = $sent['offers'][0] ?? [];
    check((int)($offer['proposal_id'] ?? 0) === $presentationId && !empty($offer['send_id']) && $offer['opened'] === false && (int)$offer['views_count'] === 0, 'The offer send is linked, not opened');
    $send = CmsProposalSend::findOne((int)$offer['send_id']);
    check($send && (int)$send->cms_comm_message_id === $messageId && (int)$send->cms_lead_id === (int)$lead1->id, 'Letter, client and offer send are linked');
    $message = CmsCommMessage::findOne($messageId);
    check($message->subject === 'Тема изменена' && mb_strpos((string)$message->body_html, '/~kp/') !== false && $message->unsubscribe_url && (int)$message->sent_by_user_id === (int)$admin->id,
        'The letter has the personal button, the opt-out, the sender');
    $mark = AiMark::of($message);
    check($mark && mb_strpos(AiMark::text($mark), 'отправлено ИИ через API') !== false && mb_strpos(AiMark::text($mark), 'Подготовлено ИИ') !== false, 'The mark: prepared and sent by AI through the API');
    $feed = \skeeks\cms\models\CmsLog::find()->andWhere(['log_type' => 'proposal', 'model_id' => (int)$lead1->id])->orderBy(['id' => SORT_DESC])->one();
    check($feed && !empty($feed->data['ai']['sent_via']), 'The feed entry of the offer send says AI sent it through the API');

    // 8. Idempotency: the same key and data returns the same letter; other data with the key is refused; the status by key after a timeout.
    $count = (int)CmsCommMessage::find()->count();
    $again = run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $c2['check_token'], 'idempotency_key' => $key, 'confirm' => true]);
    check($again['repeated'] && (int)$again['message']['id'] === $messageId && (int)CmsCommMessage::find()->count() === $count, 'A repeated request makes no second letter');
    $other = run('cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => str_repeat('a', 64), 'idempotency_key' => $key, 'confirm' => true]);
    check($other['state'] === 'refused' && $other['problems'][0]['reason'] === AiSend::REASON_KEY_REUSED, 'The key with other data is refused');
    $status = run('cms_comm_send_status', ['idempotency_key' => $key]);
    check($status['found'] && (int)$status['message']['id'] === $messageId && $status['state'] === 'queued', 'The status by the key');
    check(run('cms_comm_send_status', ['cms_comm_message_id' => $messageId])['idempotency_key'] === $key, 'The status by the letter');
    check(run('cms_comm_send_status', ['idempotency_key' => 'never-used-'.$suffix])['state'] === 'not_found', 'An unknown key is not found, not guessed');

    // 9. States as mail tells them: sent is not delivered; failed and unknown; a bounce comes back.
    CmsCommMessage::updateAll(['status' => CmsCommMessage::STATUS_SENT], ['id' => $messageId]);
    $st = run('cms_comm_send_status', ['idempotency_key' => $key]);
    check($st['state'] === 'sent' && $st['delivery']['state'] === 'not_confirmed' && mb_strpos($st['state_text'], 'не подтверждение доставки') !== false, 'Sent is not delivered');
    CmsCommMessage::updateAll(['status' => CmsCommMessage::STATUS_UNKNOWN], ['id' => $messageId]);
    check(mb_strpos(run('cms_comm_send_status', ['idempotency_key' => $key])['state_text'], 'Повторно не отправляется') !== false, 'Unknown is never resent');
    CmsCommMessage::updateAll(['status' => CmsCommMessage::STATUS_FAILED, 'error' => 'SMTP 550'], ['id' => $messageId]);
    $failed = run('cms_comm_send_status', ['idempotency_key' => $key]);
    check($failed['state'] === 'failed' && $failed['error'] === 'SMTP 550', 'A failed send with its error');
    $bounce = new CmsCommMessage(['cms_site_id' => $siteId, 'cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id, 'channel' => 'email',
        'direction' => CmsCommMessage::DIRECTION_IN, 'status' => CmsCommMessage::STATUS_RECEIVED, 'external_id' => 'bounce-'.$suffix,
        'from_address' => 'mailer-daemon@yandex.ru', 'subject' => 'Undelivered Mail Returned to Sender', 'is_auto' => 1, 'message_at' => time() + 1]);
    $bounce->save(false);
    check(run('cms_comm_send_status', ['idempotency_key' => $key])['delivery']['state'] === 'bounced', 'A bounce is reported');
    check((int)CmsProposalView::find()->andWhere(['cms_proposal_send_id' => (int)$send->id])->count() === 0 && (int)$send->refresh() >= 0 && (int)$send->views_count === 0,
        'Reading the status opens nothing');

    // 10. Refusals of the check: a recent letter, not an own draft, no topic, an expired offer, the day limit; the client of a sent presentation stays.
    CmsCommMessage::updateAll(['status' => CmsCommMessage::STATUS_SENT, 'error' => null], ['id' => $messageId]); // a failed letter is not a recent letter
    $draftAgain = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topicId, 'cms_comm_account_id' => $accountId,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead1->id, 'subject' => 'Ещё', 'body_html' => '<p>Ещё</p>']]]);
    check($draftAgain['items'][0]['reason'] === 'recent_letter', 'A client written to just now gets no new draft');
    $foreign = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$worker->id, 'to_list' => 'test-ai-send-2@skeeks.com', 'subject' => 'x', 'body_html' => '<p>x</p>']);
    $foreign->save(false);
    $notOwn = run('cms_comm_draft_check', ['draft_id' => (int)$foreign->id]);
    check(!$notOwn['ok'] && $notOwn['problems'][0]['reason'] === AiSend::REASON_NOT_OWN, 'A draft of another employee cannot be sent by API');
    $noTopic = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$admin->id, 'cms_comm_account_id' => $accountId, 'to_list' => 'test-ai-send-2@skeeks.com',
        'subject' => 'x', 'body_html' => '<p>x</p>', 'context_model_code' => CmsLead::class, 'context_model_id' => (int)$lead2->id]);
    $noTopic->save(false);
    check(in_array(AiSend::REASON_NO_TOPIC, array_column(run('cms_comm_draft_check', ['draft_id' => (int)$noTopic->id])['problems'], 'reason'), true), 'A draft without a topic is refused');
    $expired = run('cms_proposal_presentation_create', ['name' => 'Истекла', 'target_url' => $url.'-old', 'confirm' => true]);
    $expiredId = (int)$expired['presentation']['id'];
    $d2 = run('cms_comm_draft_create', ['cms_comm_topic_id' => $topicId, 'offer' => 'proposal:'.$expiredId, 'cms_comm_account_id' => $accountId, 'confirm' => true,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead2->id, 'subject' => 'Тема 2', 'body_html' => '<p>Текст 2</p><p>{Ссылка}</p>']]]);
    $draft2 = (int)$d2['items'][0]['draft_id'];
    \skeeks\cms\proposal\models\CmsProposal::updateAll(['valid_until' => time() - 86400], ['id' => $expiredId]);
    $exp = run('cms_comm_draft_check', ['draft_id' => $draft2]);
    check(!$exp['ok'] && in_array('offer', array_column($exp['problems'], 'reason'), true), 'An expired presentation is refused before sending');
    \skeeks\cms\proposal\models\CmsProposal::updateAll(['valid_until' => null], ['id' => $expiredId]);
    Yii::$app->communication->aiLimits = ['sendPerDay' => (new AiSend($admin, 'x'))->sentToday()];
    check(in_array(AiSend::REASON_SEND_LIMIT, array_column(run('cms_comm_draft_check', ['draft_id' => $draft2])['problems'], 'reason'), true), 'The day limit of sends');
    Yii::$app->communication->aiLimits = [];
    check(run('cms_comm_draft_check', ['draft_id' => $draft2])['ok'], 'Another client with the same presentation passes');
    $clientChange = run('cms_proposal_presentation_update', ['id' => $presentationId, 'client' => ['type' => 'lead', 'id' => (int)$lead2->id], 'confirm' => true]);
    check(!$clientChange['success'] && isset($clientChange['errors']['client']), 'The client of a sent presentation stays');
} finally {
    $tx->rollBack();
}
echo "OK: {$checks} checks\n";
