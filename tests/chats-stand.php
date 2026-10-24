<?php
/**
 * "Чаты" (stage 5.3) against a local application, in a transaction that is
 * rolled back: CHATS_STAND_TEST=local php tests/chats-stand.php
 *
 * A fake messenger (tests/support/FakeMessengerDriver.php) is registered for
 * the run. Two employees: TEST_ADMIN_ID (1) reads all chats of the bot and
 * gets notifications, TEST_WORKER_ID (1663) has no access at first. Checks:
 * who sees a chat, notifications (one per chat and employee), folders,
 * "Взять диалог", passing a dialog, closing and reopening, "ждёт ответа",
 * read marks, groups, the right to write. No real messenger is called.
 */
if (getenv('CHATS_STAND_TEST') !== 'local') {
    throw new RuntimeException('Set CHATS_STAND_TEST=local.');
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
use skeeks\cms\communication\chat\ChatDialogs;
use skeeks\cms\communication\chat\ChatFolders;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\tests\FakeMessengerDriver;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function rejects(callable $callback, string $needle, string $message) {
    try { $callback(); } catch (Throwable $e) {
        if (mb_strpos($e->getMessage(), $needle) === false) { throw new RuntimeException($message.': '.$e->getMessage()); }
        ++$GLOBALS['checks']; return;
    }
    throw new RuntimeException($message);
}

$component = Yii::$app->communication;
$component->drivers['testbot'] = FakeMessengerDriver::class;
$component->allowedChats = ['testbot:7001', 'testbot:-7002'];
$admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
$worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
check($admin && $worker && (int)$worker->is_worker === 1 && (int)$worker->is_active === 1, 'Two active employees are needed (TEST_ADMIN_ID, TEST_WORKER_ID).');

$db = Yii::$app->db;
$transaction = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $driver = $component->createDriver('testbot');
    $driver->setAttributes(['token' => '1:chats', 'botId' => '999', 'botUsername' => 'chats_test_bot', 'signName' => 'SkeekS'], false);
    $connection = new CmsCommConnection(['cms_site_id' => $siteId, 'name' => 'Чаты: тестовый бот', 'driver' => 'testbot', 'is_active' => 1]);
    $connection->applyDriver($driver);
    $connection->save(false);
    $account = Bots::account($connection);
    (new CmsCommAccountAccess(['cms_comm_account_id' => (int)$account->id, 'cms_user_id' => (int)$admin->id,
        'read_level' => CmsCommAccountAccess::READ_ALL, 'send_level' => CmsCommAccountAccess::SEND_ANY, 'is_notify' => 1]))->save(false);
    $ingest = new BotIngest($connection, Bots::driver($connection));
    $event = static function (string $chat, string $type, string $id, string $text, array $from, int $date) {
        $e = new BotEvent(BotEvent::MESSAGE, $chat, $type);
        $e->sender = new BotUser($from[0], $from[1]);
        $e->messageId = $id;
        $e->text = $text;
        $e->date = $date;
        return $e;
    };
    $notifies = static fn(int $userId, int $conversationId) => (int)CmsWebNotify::find()->andWhere(['cms_user_id' => $userId,
        'model_code' => CmsCommConversation::class, 'model_id' => $conversationId, 'is_read' => 0])->count();
    $now = time();

    // A new private dialog: the admin reads it and is notified once, the worker sees nothing.
    $ingest->applyEvent($event('7001', 'private', '1', 'Здравствуйте, нужен сайт', ['7001', 'Клиент Чатов'], $now - 300));
    $ingest->applyEvent($event('7001', 'private', '2', 'Для магазина плитки', ['7001', 'Клиент Чатов'], $now - 240));
    $dialog = CmsCommConversation::find()->andWhere(['thread_key' => (int)$connection->id.':7001'])->one();
    check($dialog && (int)$dialog->waiting_since === $now - 300, 'The client waits since the first unanswered message.');
    check($notifies((int)$admin->id, (int)$dialog->id) === 1 && $notifies((int)$worker->id, (int)$dialog->id) === 0, 'One notification for the employee who reads the bot, none for others.');
    check(strpos((string)CmsWebNotify::find()->andWhere(['cms_user_id' => (int)$admin->id, 'model_id' => (int)$dialog->id, 'model_code' => CmsCommConversation::class])->one()->name, 'Новые сообщения (2)') === 0,
        'A second message updates the same notification.');
    $adminAccess = new ChatAccess($admin);
    $workerAccess = new ChatAccess($worker);
    check($adminAccess->canRead($dialog) && !$workerAccess->canRead($dialog) && $adminAccess->canSend($dialog) && !$workerAccess->canSend($dialog), 'Visibility and writing follow the access rows.');
    $adminFolders = new ChatFolders($admin, $siteId);
    check(in_array((int)$dialog->id, array_map('intval', $adminFolders->query(ChatFolders::NEW)->select(CmsCommConversation::tableName().'.id')->column()), true)
        && $adminFolders->unreadCounts([(int)$dialog->id]) === [(int)$dialog->id => 2], '"Новые" holds the dialog with two unread messages.');
    check($adminFolders->query(ChatFolders::NEW, 'плитки')->andWhere([CmsCommConversation::tableName().'.id' => (int)$dialog->id])->exists()
        && !$adminFolders->query(ChatFolders::NEW, 'кирпич')->andWhere([CmsCommConversation::tableName().'.id' => (int)$dialog->id])->exists(), 'Search finds a chat by its messages.');

    // Reading: the unread count and the notification go away.
    $adminDialogs = new ChatDialogs($admin);
    $adminDialogs->markRead($dialog);
    check($adminFolders->unreadCounts([(int)$dialog->id]) === [] && $notifies((int)$admin->id, (int)$dialog->id) === 0, 'Opening the chat reads it and its notification.');

    // "Взять диалог": the dialog moves to "Мои"; another employee cannot take it over; the next message notifies only the taker.
    $adminDialogs->take($dialog);
    $dialog->refresh();
    check((int)$dialog->assigned_user_id === (int)$admin->id && $adminFolders->query(ChatFolders::MINE)->andWhere([CmsCommConversation::tableName().'.id' => (int)$dialog->id])->exists()
        && !$adminFolders->query(ChatFolders::NEW)->andWhere([CmsCommConversation::tableName().'.id' => (int)$dialog->id])->exists(), 'A taken dialog is in "Мои", not in "Новые".');
    rejects(fn() => (new ChatDialogs($worker))->take($dialog), 'не найден', 'An employee without access cannot take the dialog.');

    // Passing: only to someone who sees the chat; they are notified.
    rejects(fn() => $adminDialogs->transfer($dialog, (int)$worker->id), 'не видит чат', 'A dialog is not passed to someone who cannot see it.');
    $row = new CmsCommAccountAccess(['cms_comm_account_id' => (int)$account->id, 'cms_user_id' => (int)$worker->id,
        'read_level' => CmsCommAccountAccess::READ_LINKED, 'send_level' => CmsCommAccountAccess::SEND_OWN, 'is_notify' => 1]);
    $row->save(false);
    $workerAccess = new ChatAccess($worker);
    check(!$workerAccess->canRead($dialog), '"Linked" reading does not show a chat of another client.');
    $row->read_level = CmsCommAccountAccess::READ_ALL;
    $row->save(false);
    $workerAccess = new ChatAccess($worker);
    rejects(fn() => (new ChatDialogs($worker))->take($dialog), 'уже ведёт', 'A dialog taken by a colleague is not taken over silently.');
    $adminDialogs->transfer($dialog, (int)$worker->id);
    $dialog->refresh();
    check((int)$dialog->assigned_user_id === (int)$worker->id && $notifies((int)$worker->id, (int)$dialog->id) === 1, 'The colleague gets the dialog and a notification.');
    check($workerAccess->canSend($dialog), '"Own" writing covers a dialog the employee leads.');
    $ingest->applyEvent($event('7001', 'private', '3', 'Ещё вопрос', ['7001', 'Клиент Чатов'], $now - 100));
    check($notifies((int)$admin->id, (int)$dialog->id) === 0, 'The admin is not notified about a dialog a colleague leads.');

    // An answer from the CRM ends the waiting; closing and a new message of the client.
    $reply = BotSend::queue($dialog, 'Добрый день! Ответим сегодня.', ['user' => $worker]);
    $dialog->refresh();
    check($reply->status === 'queued' && $dialog->waiting_since === null && strpos((string)$reply->body_text, ':') !== false, 'A reply ends the waiting and is signed.');
    (new ChatDialogs($worker))->close($dialog);
    $dialog->refresh();
    check($dialog->closed_at && (new ChatFolders($worker, $siteId))->query(ChatFolders::CLOSED)->andWhere([CmsCommConversation::tableName().'.id' => (int)$dialog->id])->exists()
        && !(new ChatFolders($worker, $siteId))->query(ChatFolders::MINE)->andWhere([CmsCommConversation::tableName().'.id' => (int)$dialog->id])->exists(), 'A closed dialog leaves "Мои".');
    $ingest->applyEvent($event('7001', 'private', '4', 'Забыл спросить про доставку', ['7001', 'Клиент Чатов'], $now - 10));
    $dialog->refresh();
    check(!$dialog->closed_at && (int)$dialog->waiting_since === $now - 10 && (int)$dialog->assigned_user_id === (int)$worker->id, 'A new message of the client opens the dialog for the same employee.');

    // A group: no bell unless somebody leads it; messages of our employees are ours.
    $ingest->applyEvent($event('-7002', 'group', '1', 'Поправьте баннер', ['7001', 'Клиент Чатов'], $now - 50));
    $group = CmsCommConversation::find()->andWhere(['thread_key' => (int)$connection->id.':-7002'])->one();
    check($group && $notifies((int)$admin->id, (int)$group->id) === 0 && (new ChatFolders($admin, $siteId))->query(ChatFolders::GROUPS)->andWhere([CmsCommConversation::tableName().'.id' => (int)$group->id])->exists(),
        'A group message lands in "Группы" without a notification.');
    $identity = \skeeks\cms\communication\models\CmsCommIdentity::find()->andWhere(['channel' => 'testbot', 'external_id' => '555'])->one()
        ?: new \skeeks\cms\communication\models\CmsCommIdentity(['cms_site_id' => $siteId, 'channel' => 'testbot', 'external_id' => '555', 'name' => 'Сотрудник']);
    $identity->cms_user_id = (int)$admin->id;
    $identity->save(false);
    $ingest->applyEvent($event('-7002', 'group', '2', 'Сделаем', ['555', 'Сотрудник'], $now - 40));
    $group->refresh();
    check($group->waiting_since === null, 'An employee answering from their own account ends the waiting.');

    // "Own" writing: not in a group the employee neither leads nor sees through a client.
    $row->send_level = CmsCommAccountAccess::SEND_OWN;
    $row->save(false);
    check(!(new ChatAccess($worker))->canSend($group), '"Own" writing does not cover any group.');

    // A chat linked to a record the employee sees is "theirs" for "linked" reading.
    $row->read_level = CmsCommAccountAccess::READ_LINKED;
    $row->save(false);
    $leadId = (int)\skeeks\cms\models\CmsLead::find()->forManager($worker)->select(\skeeks\cms\models\CmsLead::tableName().'.id')->limit(1)->scalar();
    if ($leadId) {
        (new CmsCommConversationLink(['cms_comm_conversation_id' => (int)$group->id, 'model_code' => \skeeks\cms\models\CmsLead::class, 'model_id' => $leadId]))->save(false);
        check((new ChatAccess($worker))->canRead($group) && (new ChatAccess($worker))->canSend($group), 'A chat of the employee\'s lead is visible and writable with "linked" and "own".');
    }
} finally {
    $transaction->rollBack();
}

echo "OK: {$checks} checks\n";
