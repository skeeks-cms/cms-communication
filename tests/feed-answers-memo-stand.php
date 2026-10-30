<?php
/**
 * The answers of feed renderers and of the «Доступ» tab check are kept in the request memo of CrmAccess and go
 * with a change of rights in the same process (audit F5-01): the same renderer and controller instances are
 * reused across identity switches, a level lowered and a link removed by real save()/delete(), an explicit
 * resetMemo(), a right granted back, a bot access row revoked; the console keeps nothing. Against a local
 * application in a transaction that is rolled back, with a fake messenger (nothing reaches a real one):
 * FEED_MEMO_TEST=local php tests/feed-answers-memo-stand.php
 *
 * Identities: TEST_ADMIN_ID (1, the main responsible), TEST_WORKER_ID (1663, "deal"), TEST_LEADER_ID (110, "full").
 * Records are marked «Проверка:».
 */
if (getenv('FEED_MEMO_TEST') !== 'local') {
    throw new RuntimeException('Set FEED_MEMO_TEST=local.');
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
require_once '/shared-vendor/skeeks/cms-communication/tests/support/FakeMessengerDriver.php';

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
    $mark = 'Проверка: feed memo '.mt_rand(100000, 999999);

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


    CrmAccess::$keepAnswersInConsole = true;
    $messages = new skeeks\cms\communication\log\MessageLogRenderer();
    $digests = new skeeks\cms\communication\log\ChatDigestLogRenderer();
    $mailLog = new skeeks\cms\models\CmsLog(['data' => ['id' => $letters['company']]]);
    $chatLog = new skeeks\cms\models\CmsLog(['data' => ['conversation_id' => (int)$chats['company']->id, 'count' => 1, 'day' => time(),
        'lines' => [['Клиент', 'FEED_MEMO_SECRET_CHAT']]]]);
    $tabController = new class {
        use \skeeks\cms\controllers\traits\ClientTransferActions;
        public int $recordId = 0;
        protected function transferRecord(): array { return ['company', $this->recordId]; }
    };
    // «Доступ» on a company of the administrator and 110 only: 1663, whose head 110 is, has no way to it.
    $tabCompany = new CmsCompany(['name' => $mark.' компания вкладки']);
    $tabCompany->save(false);
    CmsCompany2manager::deleteAll(['cms_company_id' => $tabCompany->id]);
    $link(CmsCompany2manager::class, 'cms_company_id', 'cms_user_id', (int)$tabCompany->id, (int)$admin->id, CrmAccess::LEVEL_FULL, 1);
    $tabController->recordId = (int)$tabCompany->id;
    $tab = $tabController->clientAccessAction()['accessCallback'];

    // What the reused instances answer must equal what fresh ones and the plain rules answer now.
    $state = static function (string $phase, array $expected) use ($messages, $digests, $mailLog, $chatLog, $tab, $tabController, $letters, $chats, $tabCompany) {
        $user = Yii::$app->user->identity;
        $actual = [
            'mail' => (bool)$messages->entry($mailLog)->summary,
            'chat' => str_contains((string)$digests->entry($chatLog)->body, 'FEED_MEMO_SECRET_CHAT'),
            'tab' => (bool)$tab(),
        ];
        $freshController = new ($tabController::class);
        $freshController->recordId = (int)$tabCompany->id;
        CrmAccess::$keepAnswersInConsole = false;
        $fresh = [
            'mail' => CmsCommMessage::find()->forManager($user)->andWhere([CmsCommMessage::tableName().'.id' => $letters['company']])->exists(),
            'chat' => (new ChatAccess($user))->canRead($chats['company']),
            'tab' => (bool)($freshController->clientAccessAction()['accessCallback'])(),
        ];
        CrmAccess::$keepAnswersInConsole = true;
        check($fresh === $expected, $phase.': the plain rules give '.json_encode($fresh).', the scenario expects '.json_encode($expected));
        check($actual === $fresh, $phase.' (user '.(int)$user->id.'): the reused instances give '.json_encode($actual).', the rules '.json_encode($fresh));
    };
    $as = static function (int $userId) { Yii::$app->user->setIdentity(CmsUser::findOne($userId)); };

    // The level of 110 on both companies: a real insert, save() or delete() (model events), or null — no link.
    $leaderLevel = static function (?string $level) use ($company, $tabCompany, $leader, $link) {
        foreach ([$company, $tabCompany] as $one) {
            $row = CmsCompany2manager::findOne(['cms_company_id' => (int)$one->id, 'cms_user_id' => (int)$leader->id]);
            if ($level === null) {
                $row && $row->delete();
            } elseif (!$row) {
                $link(CmsCompany2manager::class, 'cms_company_id', 'cms_user_id', (int)$one->id, (int)$leader->id, $level, 0);
            } else {
                $row->access_level = $level;
                $row->save(false);
            }
        }
    };
    $leaderLevel(CrmAccess::LEVEL_FULL);
    $access->resetMemo();
    $open = ['mail' => true, 'chat' => true, 'tab' => true];
    $closed = ['mail' => false, 'chat' => false, 'tab' => false];
    // The «Доступ» tab is shown to an employee of the company of any level (read only).
    $deal = ['mail' => false, 'chat' => false, 'tab' => true];

    // 1. Identity switches without any reset: 110 full → 1663 deal → 110.
    $as((int)$leader->id); $state('110 full', $open);
    $as((int)$worker->id); $state('1663 deal, not on the tab company', $closed);
    $as((int)$leader->id); $state('110 again', $open);

    // 2. Lowered to "deal" by a real save(): the model event drops the memo, no explicit reset.
    $leaderLevel(CrmAccess::LEVEL_DEAL);
    $state('110 lowered to deal by save()', $deal);

    // 3. The same after a change that has no model event (updateAll) and an explicit resetMemo().
    CmsCompany2manager::updateAll(['access_level' => CrmAccess::LEVEL_FULL], ['cms_company_id' => [(int)$company->id, (int)$tabCompany->id], 'cms_user_id' => (int)$leader->id]);
    $access->resetMemo();
    $state('110 full again (updateAll + resetMemo)', $open);
    CmsCompany2manager::updateAll(['access_level' => CrmAccess::LEVEL_DEAL], ['cms_company_id' => [(int)$company->id, (int)$tabCompany->id], 'cms_user_id' => (int)$leader->id]);
    $access->resetMemo();
    $state('110 deal (updateAll + resetMemo)', $deal);

    // 4. The link removed by a real delete(): a negative answer for everything, the tab included.
    $leaderLevel(null);
    $state('110 link deleted', $closed);

    // 5. Granted back by a real insert: the negative answers go too.
    $leaderLevel(CrmAccess::LEVEL_FULL);
    $state('110 granted full back', $open);

    // 6. A right of cms-communication, not of the CRM: the bot stops opening linked chats by CRM rights
    // (a real save() of the account, no explicit reset) — the chat closes, the letter and the tab stay.
    $account->is_expose_linked = 0;
    $account->save(false);
    $state('110 without the bot', ['mail' => true, 'chat' => false, 'tab' => true]);

    // 7. The console and workers keep nothing: with keepsAnswers() off every call asks the database.
    CrmAccess::$keepAnswersInConsole = false;
    $calls = 0;
    $access->remember('probe', function () use (&$calls) { return ++$calls; });
    $access->remember('probe', function () use (&$calls) { return ++$calls; });
    check($calls === 2, 'the console keeps no answer of remember()');
    CrmAccess::$keepAnswersInConsole = true;
    $calls = 0;
    $access->remember('probe', function () use (&$calls) { return ++$calls; });
    $access->remember('probe', function () use (&$calls) { return ++$calls; });
    check($calls === 1, 'a web request keeps one answer of remember()');

    echo "OK feed-answers-memo-stand: $checks checks\n";
} finally {
    $transaction->rollBack();
    CrmAccess::$keepAnswersInConsole = false;
    CrmAccess::instance()->resetMemo();
}
