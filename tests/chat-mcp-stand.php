<?php
/**
 * MCP/REST tools of chats (stage 5.6) through McpComponent::executeTool(),
 * against a local application in a transaction that is rolled back:
 * CHAT_MCP_TEST=local php tests/chat-mcp-stand.php
 *
 * A fake messenger is registered for the run. Checks: the list and a chat
 * by the OAuth employee's rights, masked phones, a reply check and send
 * (token, key, repeat, changed text, confirm, the AI mark, scopes), the
 * status, a task from a message. Identities: TEST_ADMIN_ID (1),
 * TEST_WORKER_ID (1663). No real messenger is called.
 */
if (getenv('CHAT_MCP_TEST') !== 'local') {
    throw new RuntimeException('Set CHAT_MCP_TEST=local.');
}
define('ROOT_DIR', getenv('TEST_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null, [Yiisoft\Config\Modifier\RecursiveMerge::groups('web', 'web-'.ENV, 'params', 'params-web-'.ENV)], 'params-web-'.ENV);
$c = $config->get($config->has('web-'.ENV) ? 'web-'.ENV : 'web');
$c['components']['request']['scriptFile'] = ROOT_DIR.'/frontend/web/index.php';
$c['components']['request']['scriptUrl'] = '/index.php';
$c['components']['request']['hostInfo'] = 'http://localhost';
$c['components']['user']['enableSession'] = false;
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();
require_once __DIR__.'/support/FakeMessengerDriver.php';

use skeeks\cms\communication\bot\BotEvent;
use skeeks\cms\communication\bot\BotIngest;
use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotUser;
use skeeks\cms\communication\chat\ChatLinks;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\tests\FakeMessengerDriver;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;

if (!Yii::$app->has('cmsMcp')) {
    echo "SKIP: skeeks/cms-mcp is not installed\n";
    return;
}
$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function rejects(callable $callback, $message) { try { $callback(); } catch (Throwable $e) { ++$GLOBALS['checks']; return $e; } throw new RuntimeException($message); }
function run(string $tool, array $arguments, ?array $scopes = null) {
    $all = array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']);
    $token = new CmsOauthAccessToken(['scopes' => json_encode($scopes ?? $all)]);
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}

$component = Yii::$app->communication;
$component->drivers['testbot'] = FakeMessengerDriver::class;
$component->allowedChats = ['testbot:-7001'];
FakeMessengerDriver::reset();
$admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
$worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
Yii::$app->user->setIdentity($admin);

$db = Yii::$app->db;
$transaction = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $driver = $component->createDriver('testbot');
    $driver->setAttributes(['token' => '1:mcp', 'botId' => '999', 'botUsername' => 'mcp_test_bot', 'signManager' => true, 'signName' => 'SkeekS'], false);
    $connection = new CmsCommConnection(['cms_site_id' => $siteId, 'name' => 'MCP: тестовый бот', 'driver' => 'testbot', 'is_active' => 1]);
    $connection->applyDriver($driver);
    $connection->save(false);
    (new CmsCommAccountAccess(['cms_comm_account_id' => (int)Bots::account($connection)->id, 'cms_user_id' => (int)$admin->id,
        'read_level' => CmsCommAccountAccess::READ_ALL, 'send_level' => CmsCommAccountAccess::SEND_ANY, 'is_notify' => 0]))->save(false);
    $ingest = new BotIngest($connection, Bots::driver($connection));
    $now = time();
    $msg = static function (string $chat, string $id, string $text, array $from, ?string $title = null) use ($ingest, $now) {
        $e = new BotEvent(BotEvent::MESSAGE, $chat, 'group');
        $e->sender = new BotUser($from[0], $from[1]);
        $e->messageId = $id;
        $e->text = $text;
        $e->date = $now - 100 + (int)$id;
        $e->chatTitle = $title;
        return $ingest->applyEvent($e);
    };
    $company = new CmsCompany(['name' => 'Тест MCP Чатов']);
    $company->save(false);
    $first = $msg('-7001', '1', 'Добрый день! Перезвоните мне: +7 999 123-45-67', ['7101', 'Ольга Клиентова'], 'Тест MCP / mcp-chat.test');
    $chat = $first->conversation;
    (new ChatLinks($admin))->link($chat, CrmRecords::COMPANY, (int)$company->id);
    $closed = $msg('-7002', '1', 'Чужой чат', ['7201', 'Посторонний'], 'Без разрешения');

    // Reading.
    $list = run('cms_comm_chat_list', ['record_type' => 'company', 'record_id' => (int)$company->id]);
    check($list['total'] === 1 && $list['items'][0]['id'] === (int)$chat->id && $list['items'][0]['links'][0]['id'] === (int)$company->id
        && $list['items'][0]['waiting_since'] !== null && $list['items'][0]['unread'] === 1, 'The chats of the company with their state.');
    check(in_array((int)$chat->id, array_column(run('cms_comm_chat_list', ['q' => 'Перезвоните', 'limit' => 100])['items'], 'id'), true), 'Search by text.');
    $got = run('cms_comm_chat_get', ['id' => (int)$chat->id]);
    check($got['messages'][0]['author'] === 'Ольга Клиентова' && strpos($got['messages'][0]['text'], '123-45-67') === false && $got['can_send'] === true,
        'A chat with its messages; the phone in a text is masked.');
    check(strpos(run('cms_comm_chat_get', ['id' => (int)$chat->id, 'include_contacts' => true])['messages'][0]['text'], '123-45-67') !== false, 'include_contacts shows it.');
    Yii::$app->user->setIdentity($worker);
    rejects(static fn() => run('cms_comm_chat_get', ['id' => (int)$chat->id]), 'An employee without access to the bot does not see the chat.');
    Yii::$app->user->setIdentity($admin);

    // A reply: check, then send with the same token.
    $reply = ['id' => (int)$chat->id, 'text' => 'Здравствуйте! Перезвоним через 10 минут.', 'reply_to_message_id' => (int)$first->id];
    $checked = run('cms_comm_chat_reply_check', $reply);
    $name = trim(trim((string)$admin->first_name).' '.trim((string)$admin->last_name)) ?: (string)$admin->shortDisplayName;
    check($checked['ok'] && $checked['check_token'] && mb_strpos($checked['preview']['text'], $name) === 0
        && mb_strpos($checked['preview']['text'], 'Перезвоним через 10 минут') !== false, 'The check shows the text with the signature and gives a token.');
    $refused = run('cms_comm_chat_reply_check', ['id' => (int)$closed->conversation->id, 'text' => 'Привет']);
    check(!$refused['ok'] && in_array('chat_not_allowed', array_column($refused['problems'], 'reason'), true), 'A chat the site may not write to is refused.');
    rejects(static fn() => run('cms_comm_chat_reply_send', $reply + ['check_token' => $checked['check_token'], 'idempotency_key' => 'chat-test-0001', 'confirm' => true],
        ['cms.communication.read', 'cms.communication.write']), 'Preparing does not give the right to send.');
    $noConfirm = run('cms_comm_chat_reply_send', $reply + ['check_token' => $checked['check_token'], 'idempotency_key' => 'chat-test-0001']);
    check($noConfirm['sent'] === false && !empty($noConfirm['requires_confirmation']), 'Without confirm nothing is sent.');
    $changed = run('cms_comm_chat_reply_send', ['text' => 'Другой текст'] + $reply + ['check_token' => $checked['check_token'], 'idempotency_key' => 'chat-test-0002', 'confirm' => true]);
    check($changed['sent'] === false && $changed['problems'][0]['reason'] === 'changed_after_check', 'A changed text is refused.');
    $sent = run('cms_comm_chat_reply_send', $reply + ['check_token' => $checked['check_token'], 'idempotency_key' => 'chat-test-0003', 'confirm' => true]);
    $message = CmsCommMessage::findOne((int)$sent['cms_comm_message_id']);
    check($sent['sent'] === true && $sent['state'] === 'queued' && $message && (int)$message->reply_to_message_id === (int)$first->id
        && $message->prepared_by === 'ai' && (int)$message->sent_by_user_id === (int)$admin->id, 'The reply is queued, answers the message and carries the AI mark.');
    $again = run('cms_comm_chat_reply_send', $reply + ['check_token' => $checked['check_token'], 'idempotency_key' => 'chat-test-0003', 'confirm' => true]);
    check($again['repeated'] === true && (int)$again['cms_comm_message_id'] === (int)$message->id
        && (int)CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$chat->id, 'direction' => 'out'])->count() === 1, 'A repeat with the key sends nothing new.');
    \skeeks\cms\communication\bot\BotSend::deliver((int)$message->id);
    $status = run('cms_comm_chat_send_status', ['idempotency_key' => 'chat-test-0003']);
    check($status['found'] && $status['state'] === 'sent' && mb_strpos(implode("
", array_column(FakeMessengerDriver::$sent, 'text')), $name.' (SkeekS):') === 0
        && FakeMessengerDriver::$sent[0]['reply'] === '1', 'The status follows the delivery; the messenger got the signed text as an answer (the fake one splits it in parts).');
    $mark = run('cms_comm_chat_get', ['id' => (int)$chat->id])['messages'];
    check(!empty(end($mark)['prepared']) && end($mark)['ours'] === true, 'The chat shows the reply as ours, prepared by AI.');

    // A task from a message.
    $preview = run('cms_comm_chat_task_create', ['cms_comm_message_id' => (int)$first->id]);
    check($preview['created'] === false && $preview['task']['cms_company_id'] === (int)$company->id && !CmsTask::find()->andWhere(['cms_company_id' => (int)$company->id])->exists(),
        'Without confirm only the preview.');
    rejects(static fn() => run('cms_comm_chat_task_create', ['cms_comm_message_id' => (int)$first->id, 'confirm' => true], ['cms.communication.read']), 'A task needs cms.task.write.');
    $created = run('cms_comm_chat_task_create', ['cms_comm_message_id' => (int)$first->id, 'name' => 'Перезвонить Ольге', 'confirm' => true]);
    $task = CmsTask::findOne((int)$created['task']['id']);
    check($task && $task->name === 'Перезвонить Ольге' && (int)$task->cms_company_id === (int)$company->id && mb_strpos((string)$task->description, 'Поставлено ИИ') !== false
        && run('cms_comm_chat_get', ['id' => (int)$chat->id])['messages'][0]['tasks'] === [(int)$task->id], 'The task is created and linked to the message.');

    // The client history: the day of the group and the message that became a task, both pointing to the chat.
    $history = run('cms_crm_history_get', ['record_type' => 'company', 'record_id' => (int)$company->id, 'limit' => 50]);
    $items = $history['items'] ?? [];
    $subtypes = array_column($items, 'subtype');
    $refs = array_column(array_filter(array_column($items, 'ref')), 'tool');
    check(in_array('chat_digest', $subtypes, true) && in_array('chat_in', $subtypes, true) && !in_array('email_in', $subtypes, true)
        && in_array('cms_comm_chat_get', $refs, true), 'The client history shows the chat day and the message, not as letters.');
} finally {
    $transaction->rollBack();
}

echo "OK: {$checks} checks\n";
