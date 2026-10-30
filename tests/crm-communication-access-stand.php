<?php
/**
 * The communication of a client for «Свои сделки» and "edit" (owner's decision 29.10.2026, platform-plan crm/links-contract.md
 * п. 11): letters and chats only through the employee's deals, the old correspondence of the client — only
 * "full"; writing to a chat — only through an open deal he works on. Against a local application in a
 * transaction that is rolled back, with a fake messenger (nothing reaches a real one):
 * CRM_COMM_ACCESS_TEST=local php tests/crm-communication-access-stand.php
 *
 * Identities: TEST_ADMIN_ID (1, the main responsible), TEST_WORKER_ID (1663), TEST_LEADER_ID (110, made the
 * worker's department head for the run). Records are marked «Проверка:».
 */
if (getenv('CRM_COMM_ACCESS_TEST') !== 'local') {
    throw new RuntimeException('Set CRM_COMM_ACCESS_TEST=local.');
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
use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\bot\BotUser;
use skeeks\cms\communication\chat\ChatAccess;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\tests\FakeMessengerDriver;
use skeeks\cms\crm\CrmAccess;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2manager;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsUser2manager;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException('FAIL: '.$message); } ++$checks; }
function run(string $tool, array $arguments) {
    $token = new CmsOauthAccessToken(['scopes' => json_encode(array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']))]);
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}

$component = Yii::$app->communication;
$component->drivers['testbot'] = FakeMessengerDriver::class;
$component->allowedChats = ['testbot:-7101', 'testbot:-7102', 'testbot:-7103', 'testbot:-7104'];
FakeMessengerDriver::reset();
$admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
$worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
$leader = CmsUser::findOne((int)(getenv('TEST_LEADER_ID') ?: 110));
$auth = Yii::$app->authManager;
if (!$admin || !$worker || !$leader || $auth->checkAccess((int)$worker->id, \skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)
    || $auth->checkAccess((int)$leader->id, \skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
    throw new RuntimeException('The stand needs an administrator TEST_ADMIN_ID and non-administrators TEST_WORKER_ID, TEST_LEADER_ID.');
}
Yii::$app->user->setIdentity($admin);
$access = CrmAccess::instance();

$db = Yii::$app->db;
$transaction = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $mark = 'Проверка: связь '.mt_rand(100000, 999999);

    // --- a bot whose linked chats are open to employees by their CRM rights ---
    $driver = $component->createDriver('testbot');
    $driver->setAttributes(['token' => '1:access', 'botId' => '998', 'botUsername' => 'access_test_bot', 'signManager' => true, 'signName' => 'SkeekS'], false);
    $connection = new CmsCommConnection(['cms_site_id' => $siteId, 'name' => $mark.' бот', 'driver' => 'testbot', 'is_active' => 1]);
    $connection->applyDriver($driver);
    $connection->save(false);
    $account = Bots::account($connection);
    $account->is_expose_linked = 1;
    $account->save(false);
    foreach ([$admin, $worker, $leader] as $u) {
        (new CmsCommAccountAccess(['cms_comm_account_id' => (int)$account->id, 'cms_user_id' => (int)$u->id,
            'read_level' => CmsCommAccountAccess::READ_LINKED, 'send_level' => CmsCommAccountAccess::SEND_OWN, 'is_notify' => 0]))->save(false);
    }
    $ingest = new BotIngest($connection, Bots::driver($connection));
    $chatOf = static function (string $chatId, string $title) use ($ingest) {
        $e = new BotEvent(BotEvent::MESSAGE, $chatId, 'group');
        $e->sender = new BotUser('77'.ltrim($chatId, '-'), 'Клиент');
        $e->messageId = '1';
        $e->text = 'Проверка: сообщение клиента';
        $e->date = time() - 60;
        $e->chatTitle = $title;
        return $ingest->applyEvent($e)->conversation;
    };
    $linkChat = static function (CmsCommConversation $chat, string $code, int $id) {
        (new CmsCommConversationLink(['cms_comm_conversation_id' => (int)$chat->id, 'model_code' => $code, 'model_id' => $id, 'created_at' => time()]))->save(false);
    };

    // --- the client: company A (the admin is the main one), a person P without a company ---
    $company = new CmsCompany(['name' => $mark.' компания А']);
    $company->save(false);
    CmsCompany2manager::deleteAll(['cms_company_id' => $company->id]);
    $person = new CmsUser(['first_name' => $mark.' клиент П', 'cms_site_id' => $siteId, 'auth_key' => Yii::$app->security->generateRandomString()]);
    $person->save(false);
    CmsUser2manager::deleteAll(['client_id' => $person->id]);
    $link = static function (string $class, string $column, string $workerColumn, int $recordId, int $userId, string $level, int $main) {
        $row = new $class([$column => $recordId, $workerColumn => $userId]);
        $row->save(false);
        $class::updateAll(['access_level' => $level, 'is_main' => $main], [$column => $recordId, $workerColumn => $userId]);
    };
    $link(CmsCompany2manager::class, 'cms_company_id', 'cms_user_id', (int)$company->id, (int)$admin->id, CrmAccess::LEVEL_FULL, 1);
    $link(CmsCompany2manager::class, 'cms_company_id', 'cms_user_id', (int)$company->id, (int)$worker->id, CrmAccess::LEVEL_DEAL, 0);
    $link(CmsUser2manager::class, 'client_id', 'worker_id', (int)$person->id, (int)$admin->id, CrmAccess::LEVEL_FULL, 1);
    $link(CmsUser2manager::class, 'client_id', 'worker_id', (int)$person->id, (int)$worker->id, CrmAccess::LEVEL_DEAL, 0);
    $setLevel = static function (string $level) use ($company, $person, $worker) {
        CmsCompany2manager::updateAll(['access_level' => $level], ['cms_company_id' => $company->id, 'cms_user_id' => $worker->id]);
        CmsUser2manager::updateAll(['access_level' => $level], ['client_id' => $person->id, 'worker_id' => $worker->id]);
    };

    $lead = static function (string $what, int $executorId, string $status) use ($company, $siteId, $mark) {
        $model = new CmsLead(['name' => $mark.' '.$what, 'cms_site_id' => $siteId, 'cms_company_id' => (int)$company->id,
            'executor_id' => $executorId, 'status' => $status]);
        $model->save(false);
        // The status as is: the model's own rules (a reason of a refusal) are not the subject here.
        $model->updateAttributes(['status' => $status]);
        return $model;
    };
    $ownOpen = $lead('своя открытая сделка', (int)$worker->id, CmsLead::STATUS_IN_WORK);
    $ownClosed = $lead('своя закрытая сделка', (int)$worker->id, CmsLead::STATUS_REJECTED);
    $foreign = $lead('чужая сделка', (int)$admin->id, CmsLead::STATUS_IN_WORK);

    // --- letters: only to the company, only to the person, to the own deal, to a foreign deal ---
    $db->createCommand()->insert('cms_comm_conversation', ['cms_site_id' => $siteId, 'channel' => 'email', 'thread_key' => 'check-access-'.uniqid(),
        'subject' => $mark.' переписка', 'created_at' => time(), 'updated_at' => time(), 'last_message_at' => time()])->execute();
    $conversationId = (int)$db->getLastInsertID();
    $letter = static function (string $what, string $code, int $id) use ($db, $siteId, $conversationId, $mark) {
        $db->createCommand()->insert('cms_comm_message', ['cms_site_id' => $siteId, 'cms_comm_conversation_id' => $conversationId, 'channel' => 'email',
            'direction' => 'in', 'status' => 'received', 'external_id' => 'check-access-'.uniqid(), 'from_address' => 'nobody@example.test',
            'subject' => $mark.' '.$what, 'body_text' => $mark.' '.$what, 'created_at' => time(), 'updated_at' => time(), 'message_at' => time()])->execute();
        $messageId = (int)$db->getLastInsertID();
        $db->createCommand()->insert('cms_comm_link', ['cms_comm_message_id' => $messageId, 'model_code' => $code, 'model_id' => $id, 'link_type' => 'manual', 'created_at' => time()])->execute();
        return $messageId;
    };
    $letters = [
        'company' => $letter('письмо компании', CmsCompany::class, (int)$company->id),
        'person' => $letter('письмо клиенту-человеку', CmsUser::class, (int)$person->id),
        'own' => $letter('письмо своей сделки', CmsLead::class, (int)$ownOpen->id),
        'foreign' => $letter('письмо чужой сделки', CmsLead::class, (int)$foreign->id),
    ];
    $sees = static function (CmsUser $user, string $key) use ($letters) {
        return CmsCommMessage::find()->forManager($user)->andWhere([CmsCommMessage::tableName().'.id' => $letters[$key]])->exists();
    };

    // --- chats: linked to the company, to the own open deal, to the own closed deal, to a foreign deal ---
    $chats = [
        'company' => $chatOf('-7101', $mark.' чат компании'),
        'own' => $chatOf('-7102', $mark.' чат своей сделки'),
        'closed' => $chatOf('-7103', $mark.' чат закрытой сделки'),
        'foreign' => $chatOf('-7104', $mark.' чат чужой сделки'),
    ];
    $linkChat($chats['company'], CmsCompany::class, (int)$company->id);
    $linkChat($chats['own'], CmsLead::class, (int)$ownOpen->id);
    $linkChat($chats['closed'], CmsLead::class, (int)$ownClosed->id);
    $linkChat($chats['foreign'], CmsLead::class, (int)$foreign->id);

    // ============ «Свои сделки» and "edit": only through his deals ============
    foreach ([CrmAccess::LEVEL_DEAL, CrmAccess::LEVEL_EDIT] as $level) {
        $setLevel($level);
        Yii::$app->user->setIdentity($worker);
        check(!$sees($worker, 'company'), "$level: a letter linked only to the company is hidden.");
        check(!$sees($worker, 'person'), "$level: a letter linked only to the person is hidden.");
        check($sees($worker, 'own'), "$level: a letter of his deal is seen.");
        check(!$sees($worker, 'foreign'), "$level: a letter of a foreign deal is hidden.");
        $apiHidden = static function (int $id) { try { run('cms_comm_message_get', ['id' => $id]); return false; } catch (Throwable $e) { return true; } };
        check($apiHidden($letters['company']) && $apiHidden($letters['person']), "$level: REST/MCP does not open the letters of the client itself.");
        $chatAccess = new ChatAccess($worker);
        check(!$chatAccess->canRead($chats['company']) && !$chatAccess->canSend($chats['company']), "$level: the chat of the company is closed.");
        check(!$chatAccess->canRead($chats['foreign']) && !$chatAccess->canSend($chats['foreign']), "$level: the chat of a foreign deal is closed.");
        check($chatAccess->canRead($chats['closed']), "$level: the chat of his closed deal is read.");
        check(!$chatAccess->canSend($chats['closed']), "$level: no writing to the chat of his closed deal.");
        check($chatAccess->canRead($chats['own']) && $chatAccess->canSend($chats['own']), "$level: the chat of his open deal is his.");
        $noLead = run('cms_comm_chat_list', []);
        $listed = array_map('intval', array_column((array)($noLead['items'] ?? []), 'id'));
        check(in_array((int)$chats['own']->id, $listed, true) && !in_array((int)$chats['company']->id, $listed, true),
            "$level: the list has the chat of his deal and not the chat of the company.");
    }

    // The reply check and the send: refused without his open deal, queued (fake messenger) with it.
    $setLevel(CrmAccess::LEVEL_DEAL);
    $refused = static function (string $key) use ($chats) {
        try { $r = run('cms_comm_chat_reply_check', ['id' => (int)$chats[$key]->id, 'text' => 'Проверка: ответ']); } catch (Throwable $e) { return true; }
        return !$r['ok'] && empty($r['check_token']);
    };
    check($refused('closed'), 'deal: the reply check is refused for his closed deal.');
    check($refused('company') && $refused('foreign'), 'deal: the reply check is refused without his deal and for a foreign deal.');
    $checked = run('cms_comm_chat_reply_check', ['id' => (int)$chats['own']->id, 'text' => 'Проверка: ответ']);
    check($checked['ok'] && !empty($checked['check_token']), 'deal: the reply check passes for his open deal.');
    $sent = run('cms_comm_chat_reply_send', ['id' => (int)$chats['own']->id, 'text' => 'Проверка: ответ', 'check_token' => $checked['check_token'],
        'idempotency_key' => 'check-access-0001', 'confirm' => true]);
    check(!empty($sent['sent']) && $sent['state'] === 'queued', 'deal: the reply to his open deal is queued.');
    // A repeat with the same token after the deal was closed: the check right before the queue refuses it.
    $check2 = run('cms_comm_chat_reply_check', ['id' => (int)$chats['own']->id, 'text' => 'Проверка: второй ответ']);
    $ownOpen->updateAttributes(['status' => CmsLead::STATUS_REJECTED]);
    $late = run('cms_comm_chat_reply_send', ['id' => (int)$chats['own']->id, 'text' => 'Проверка: второй ответ', 'check_token' => $check2['check_token'],
        'idempotency_key' => 'check-access-0002', 'confirm' => true]);
    check(empty($late['sent']) && $late['state'] === 'refused', 'deal: a send by an old token after the deal was closed is refused.');
    $direct = null;
    try { BotSend::queue($chats['foreign'], 'Проверка: мимо проверки', ['user' => $worker]); } catch (SendException $e) { $direct = $e; }
    check($direct !== null, 'deal: the queue itself refuses a chat of a foreign deal (UI and channels go through it).');
    $ownOpen->updateAttributes(['status' => CmsLead::STATUS_IN_WORK]);

    // ============ "full": the whole client ============
    $setLevel(CrmAccess::LEVEL_FULL);
    check($sees($worker, 'company') && $sees($worker, 'person') && $sees($worker, 'foreign'), 'full: every letter of the client is seen.');
    $chatAccess = new ChatAccess($worker);
    check($chatAccess->canSend($chats['company']) && $chatAccess->canSend($chats['foreign']), 'full: writes to the chats of the client and of its deals.');

    // ============ a head of department: the level of his best subordinate ============
    $db->createCommand()->insert('cms_department', ['name' => $mark.' отдел', 'worker_id' => (int)$leader->id, 'pid' => null])->execute();
    $departmentId = (int)$db->getLastInsertID();
    $db->createCommand()->insert('cms_department2worker', ['cms_department_id' => $departmentId, 'worker_id' => (int)$worker->id])->execute();
    check(in_array((int)$worker->id, \skeeks\cms\models\CmsDepartment::subordinateIds((int)$leader->id), true), 'The head sees the worker as a subordinate.');
    $setLevel(CrmAccess::LEVEL_DEAL);
    check(!$sees($leader, 'company') && $sees($leader, 'own') && !$sees($leader, 'foreign'), 'head of a "deal" subordinate: the letters of the subordinate deal only.');
    check((new ChatAccess($leader))->canSend($chats['own']) && !(new ChatAccess($leader))->canSend($chats['company']), 'head of a "deal" subordinate: writes through the subordinate open deal only.');
    $setLevel(CrmAccess::LEVEL_FULL);
    check($sees($leader, 'company') && $sees($leader, 'foreign'), 'head of a "full" subordinate: the whole client.');

    // ============ an administrator: the whole client (his own access rows decide the bot) ============
    check($sees($admin, 'company') && $sees($admin, 'person') && $sees($admin, 'foreign'), 'admin: every letter.');
    check((new ChatAccess($admin))->canSend($chats['company']) && (new ChatAccess($admin))->canSend($chats['foreign']), 'admin: writes to the chats of the client.');

    // A full access to the mailbox (read all) stays a right of its own.
    $db->createCommand()->update('cms_comm_message', ['cms_comm_account_id' => (int)$account->id], ['id' => $letters['company']])->execute();
    $setLevel(CrmAccess::LEVEL_DEAL);
    CmsCommAccountAccess::updateAll(['read_level' => CmsCommAccountAccess::READ_ALL], ['cms_comm_account_id' => (int)$account->id, 'cms_user_id' => (int)$worker->id]);
    check($sees($worker, 'company'), 'deal with the whole account readable: the letters of the account are seen.');

    echo "OK crm-communication-access-stand: $checks checks\n";
} finally {
    $transaction->rollBack();
}
