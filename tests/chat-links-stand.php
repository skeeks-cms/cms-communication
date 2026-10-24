<?php
/**
 * Whose a chat is (stage 5.4) against a local application, in a transaction
 * that is rolled back: CHAT_LINKS_STAND_TEST=local php tests/chat-links-stand.php
 *
 * A fake messenger is registered for the run; a lead, a company with a site
 * and a manager are made inside the transaction. Checks: a private dialog in
 * the lead's feed (every message), a group in the company's feed (one
 * summary per day, updated), unlinking, suggestions by the site in the chat
 * title, an invitation link (`?start=`), the person's own phone, a new chat
 * of a linked person, notifications to the company's manager.
 */
if (getenv('CHAT_LINKS_STAND_TEST') !== 'local') {
    throw new RuntimeException('Set CHAT_LINKS_STAND_TEST=local.');
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
use skeeks\cms\communication\chat\ChatFeed;
use skeeks\cms\communication\chat\ChatIdentities;
use skeeks\cms\communication\chat\ChatLinks;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommLinkToken;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\tests\FakeMessengerDriver;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2manager;
use skeeks\cms\models\CmsCompanyLink;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }

$component = Yii::$app->communication;
$component->drivers['testbot'] = FakeMessengerDriver::class;
$component->allowedChats = ['testbot:8001', 'testbot:8002', 'testbot:-8003'];
$admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
$worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
Yii::$app->user->setIdentity($admin);

$db = Yii::$app->db;
$transaction = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $driver = $component->createDriver('testbot');
    $driver->setAttributes(['token' => '1:links', 'botId' => '999', 'botUsername' => 'links_test_bot'], false);
    $connection = new CmsCommConnection(['cms_site_id' => $siteId, 'name' => 'Привязки: тестовый бот', 'driver' => 'testbot', 'is_active' => 1]);
    $connection->applyDriver($driver);
    $connection->save(false);
    $account = Bots::account($connection);
    (new CmsCommAccountAccess(['cms_comm_account_id' => (int)$account->id, 'cms_user_id' => (int)$admin->id,
        'read_level' => CmsCommAccountAccess::READ_ALL, 'send_level' => CmsCommAccountAccess::SEND_ANY, 'is_notify' => 0]))->save(false);
    $ingest = new BotIngest($connection, Bots::driver($connection));
    $msg = static function (string $chat, string $type, string $id, string $text, array $from, int $date, ?string $title = null) use ($ingest) {
        $e = new BotEvent(BotEvent::MESSAGE, $chat, $type);
        $e->sender = new BotUser($from[0], $from[1]);
        $e->messageId = $id;
        $e->text = $text;
        $e->date = $date;
        $e->chatTitle = $title;
        return $ingest->applyEvent($e);
    };
    $now = time();
    $today = ChatFeed::dayStart($now);

    // Records of the test: a lead with a phone, a company with a site and a manager.
    $lead = new CmsLead(['cms_site_id' => $siteId, 'name' => 'Тестовый лид чатов', 'executor_id' => (int)$admin->id]);
    $lead->save(false);
    $leadPhone = '79990008877';
    $db->createCommand()->insert('{{%cms_lead_phone}}', ['cms_lead_id' => (int)$lead->id, 'value' => '+7 999 000-88-77', 'created_at' => $now])->execute();
    $company = new CmsCompany(['name' => 'Тест Плитка Чатов']);
    $company->save(false);
    (new CmsCompanyLink(['cms_company_id' => (int)$company->id, 'url' => 'https://plitka-chatov-test.ru', 'link_type' => 'site']))->save(false);
    (new CmsCompany2manager(['cms_company_id' => (int)$company->id, 'cms_user_id' => (int)$worker->id]))->save(false);

    // A private dialog linked to the lead: every message in the lead's feed, also the next ones.
    $msg('8001', 'private', '1', 'Здравствуйте', ['8001', 'Покупатель'], $now - 600);
    $dialog = CmsCommConversation::find()->andWhere(['thread_key' => (int)$connection->id.':8001'])->one();
    $links = new ChatLinks($admin);
    $links->link($dialog, CrmRecords::LEAD, (int)$lead->id);
    $feed = static fn() => (int)CmsLog::find()->andWhere(['model_code' => CmsLead::class, 'model_id' => (int)$lead->id, 'log_type' => CmsCommMessage::LOG_TYPE])->count();
    check($feed() === 1 && CmsCommLink::find()->andWhere(['model_code' => CmsLead::class, 'model_id' => (int)$lead->id])->count() == 1, 'Linking puts the earlier message into the lead\'s feed.');
    $msg('8001', 'private', '2', 'Сколько стоит?', ['8001', 'Покупатель'], $now - 500);
    check($feed() === 2, 'A new message of a linked dialog goes to the feed by itself.');
    check(in_array(CrmRecords::LEAD, array_column($links->records($dialog), 'type'), true), 'The chat shows the lead it belongs to.');
    $links->unlink($dialog, CmsLead::class, (int)$lead->id);
    check($feed() === 0 && !CmsCommLink::find()->andWhere(['model_code' => CmsLead::class, 'model_id' => (int)$lead->id])->exists(), 'Unlinking takes the messages out of the feed.');

    // A group with the company's site in its title: suggested, linked, one summary per day.
    $msg('-8003', 'group', '1', 'Поправьте баннер', ['8002', 'Анна Клиентова'], $now - 400, 'Тест Плитка / plitka-chatov-test.ru');
    $group = CmsCommConversation::find()->andWhere(['thread_key' => (int)$connection->id.':-8003'])->one();
    check(ChatLinks::domains($group->title) === ['plitka-chatov-test.ru'], 'The site is read from the title.');
    $suggested = array_map(static fn($c) => (int)$c->id, $links->suggest($group));
    check($suggested === [(int)$company->id], 'The company with that site is suggested.');
    $links->link($group, CrmRecords::COMPANY, (int)$company->id);
    check($links->suggest($group) === [], 'A linked company is not suggested again.');
    $digests = static fn() => ChatFeed::digestLogs($group, CmsCompany::class, (int)$company->id);
    check(count($digests()) === 1 && (int)$digests()[0]->data['count'] === 1, 'Linking a group writes the summary of its day.');
    $msg('-8003', 'group', '2', 'И цены в каталоге', ['8002', 'Анна Клиентова'], $now - 300);
    $msg('-8003', 'group', '3', 'Хорошо, сделаем', ['8004', 'Иван'], $now - 200);
    $all = $digests();
    check(count($all) === 1 && (int)$all[0]->data['count'] === 3 && array_keys($all[0]->data['authors']) === ['Анна Клиентова', 'Иван']
        && (int)$all[0]->created_at === $now - 200 && (int)$all[0]->data['day'] === $today && (int)$all[0]->cms_company_id === (int)$company->id,
        'Later messages of the day update the same summary.');
    check(!CmsCommLink::find()->andWhere(['model_code' => CmsCompany::class, 'model_id' => (int)$company->id])->exists(), 'Group messages are not put into the feed one by one.');
    $html = (new \skeeks\cms\communication\log\ChatDigestLogRenderer())->render($all[0]);
    check(strpos($html, '3 сообщения') !== false && strpos($html, 'Открыть чат') !== false, 'The summary is readable in the feed.');

    // The company's manager is notified about the group (no one leads it); they see it through the company.
    check((int)CmsWebNotify::find()->andWhere(['cms_user_id' => (int)$worker->id, 'model_code' => CmsCommConversation::class, 'model_id' => (int)$group->id])->count() === 1,
        'The manager of the linked company gets one notification about the group.');

    // An invitation: the person who opens it is the lead's, with their chat; the key works once.
    $invite = ChatIdentities::invite($connection, CrmRecords::LEAD, (int)$lead->id, (int)$admin->id);
    check(strlen((string)$invite['token']->token) >= 32 && preg_match('/^[A-Za-z0-9_-]+$/', (string)$invite['token']->token) && $invite['url'] === null,
        'An invitation key is short, safe for links and carries no data (the fake messenger has no links).');
    $start = new BotEvent(BotEvent::STARTED, '8002', 'private');
    $start->sender = new BotUser('8002', 'Анна Клиентова');
    $start->payload = (string)$invite['token']->token;
    $start->date = $now - 100;
    FakeMessengerDriver::$sent = [];
    $jobs = Yii::$app->jobs;
    $ingest->applyEvent($start);
    $anna = CmsCommIdentity::find()->andWhere(['channel' => 'testbot', 'external_id' => '8002'])->one();
    $annaChat = CmsCommConversation::find()->andWhere(['thread_key' => (int)$connection->id.':8002'])->one();
    $token = CmsCommLinkToken::findOne((int)$invite['token']->id);
    check($anna->link_method === 'start' && $token->used_at && (int)$token->used_identity_id === (int)$anna->id
        && in_array(CmsLead::class.':'.(int)$lead->id, array_map(static fn($l) => $l->model_code.':'.$l->model_id, ChatFeed::links($annaChat)), true),
        'The invitation links the person and their chat to the lead.');
    check(CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$annaChat->id, 'direction' => 'out'])->andWhere(['like', 'body_text', 'Чат подключён'])->exists(),
        'The person is greeted.');
    check(ChatIdentities::acceptInvite($anna, $connection, (string)$invite['token']->token) === null, 'An invitation works once.');

    // The person's own phone links them to the record with that phone.
    $ivan = new BotEvent(BotEvent::MESSAGE, '8001', 'private');
    $ivan->sender = new BotUser('8001', 'Покупатель');
    $ivan->messageId = '3';
    $ivan->text = 'Мой номер';
    $ivan->date = $now - 50;
    $ivan->contact = ['phone' => $leadPhone, 'userId' => '8001', 'verified' => true, 'name' => 'Покупатель'];
    $ingest->applyEvent($ivan);
    $buyer = CmsCommIdentity::find()->andWhere(['channel' => 'testbot', 'external_id' => '8001'])->one();
    check($buyer->phone === $leadPhone && $buyer->phone_verified_at && $buyer->link_method === 'contact'
        && in_array(CmsLead::class.':'.(int)$lead->id, array_map(static fn($l) => $l->model_code.':'.$l->model_id, ChatFeed::links($dialog)), true),
        'The person\'s own phone links their chat to the lead with that phone.');
    $foreign = new BotEvent(BotEvent::MESSAGE, '8001', 'private');
    $foreign->sender = new BotUser('8001', 'Покупатель');
    $foreign->messageId = '4';
    $foreign->date = $now - 40;
    $foreign->contact = ['phone' => '79990000000', 'userId' => '9999', 'verified' => true];
    $ingest->applyEvent($foreign);
    check(CmsCommIdentity::findOne((int)$buyer->id)->phone === $leadPhone, 'A forwarded contact of someone else changes nothing.');

    // Linking the person by hand to a client keeps the client on the identity; unlinking clears it.
    $client = CmsUser::find()->andWhere(['is_worker' => 0, 'is_active' => 1])->orderBy(['id' => SORT_DESC])->one();
    if ($client) {
        ChatIdentities::linkTo($buyer, CrmRecords::CLIENT, (int)$client->id, CmsCommIdentity::LINK_MANUAL, (int)$admin->id);
        $buyer->refresh();
        check((int)$buyer->cms_user_id === (int)$client->id && in_array(CmsUser::class.':'.(int)$client->id, array_map(static fn($l) => $l->model_code.':'.$l->model_id, ChatFeed::links($dialog)), true),
            'A person linked to a client: the client is on the identity and on their chats.');
        // A new chat of a linked person (another bot of the site) gets the same records.
        $driver2 = $component->createDriver('testbot');
        $driver2->setAttributes(['token' => '2:links', 'botId' => '998', 'botUsername' => 'links_test_bot2'], false);
        $connection2 = new CmsCommConnection(['cms_site_id' => $siteId, 'name' => 'Привязки: второй бот', 'driver' => 'testbot', 'is_active' => 1]);
        $connection2->applyDriver($driver2);
        $connection2->save(false);
        $ingest2 = new BotIngest($connection2, Bots::driver($connection2));
        $e2 = new BotEvent(BotEvent::MESSAGE, '8001', 'private');
        $e2->sender = new BotUser('8001', 'Покупатель');
        $e2->messageId = '1';
        $e2->text = 'Пишу второму боту';
        $e2->date = $now - 10;
        $ingest2->applyEvent($e2);
        $second = CmsCommConversation::find()->andWhere(['thread_key' => (int)$connection2->id.':8001'])->one();
        $codes = array_map(static fn($l) => $l->model_code.':'.$l->model_id, ChatFeed::links($second));
        check(in_array(CmsUser::class.':'.(int)$client->id, $codes, true) && in_array(CmsLead::class.':'.(int)$lead->id, $codes, true),
            'A new chat of a linked person is linked to the same records.');
        ChatIdentities::unlink($buyer, $links);
        $buyer->refresh();
        check(!$buyer->cms_user_id && !in_array(CmsUser::class.':'.(int)$client->id, array_map(static fn($l) => $l->model_code.':'.$l->model_id, ChatFeed::links($dialog)), true),
            'Unlinking the person removes the client from their chats.');
    }
} finally {
    $transaction->rollBack();
}

echo "OK: {$checks} checks\n";
