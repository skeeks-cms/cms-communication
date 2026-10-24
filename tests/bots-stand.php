<?php
/**
 * Bots against a local application (MariaDB, the real CMS storage, web
 * routing), in a transaction that is rolled back:
 * BOTS_STAND_TEST=local php tests/bots-stand.php
 *
 * A fake messenger (tests/support/FakeMessengerDriver.php) is registered for
 * the run only: the webhook request goes through the URL rules and the
 * controller like a messenger's request (secret header, 200 / 403 / 404),
 * the update is applied, its file goes into the storage, a reply is queued
 * and delivered to the fake messenger. Files written to the storage are
 * removed from disk afterwards. No real messenger is called.
 */
if (getenv('BOTS_STAND_TEST') !== 'local') {
    throw new RuntimeException('Set BOTS_STAND_TEST=local.');
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

use skeeks\cms\communication\bot\BotFiles;
use skeeks\cms\communication\bot\BotIngest;
use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\bot\BotSubscriptions;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommBotUpdate;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\services\MailFolders;
use skeeks\cms\communication\tests\FakeMessengerDriver;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\CmsUser;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }

$component = Yii::$app->communication;
check($component->botReceive === false, 'The stand config must forbid receiving (communication.botReceive = false).');
$component->drivers['testbot'] = FakeMessengerDriver::class;
FakeMessengerDriver::reset();

/** A request of the messenger to the webhook, through the URL rules of the site. */
function webhook(int $connectionId, array $update, ?string $secret): int
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $request = new yii\web\Request(['hostInfo' => 'http://localhost', 'scriptUrl' => '/index.php', 'scriptFile' => ROOT_DIR.'/frontend/web/index.php', 'enableCsrfValidation' => false]);
    $request->setPathInfo('~comm-bot/'.$connectionId);
    $request->setRawBody(json_encode($update));
    if ($secret !== null) {
        $request->headers->set('X-Test-Bot-Secret', $secret);
    }
    Yii::$app->set('request', $request);
    Yii::$app->set('response', new yii\web\Response());
    [$route, $params] = $request->resolve();
    Yii::$app->runAction($route, $params);
    return Yii::$app->response->statusCode;
}

$db = Yii::$app->db;
$transaction = $db->beginTransaction();
$storedFiles = [];
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $driver = $component->createDriver('testbot');
    $driver->setAttributes(['token' => '1:stand', 'botId' => '999', 'botUsername' => 'stand_test_bot'], false);
    $connection = new CmsCommConnection(['cms_site_id' => $siteId, 'name' => 'Тестовый бот стенда', 'driver' => 'testbot', 'is_active' => 1]);
    $connection->applyDriver($driver);
    $connection->save(false);

    // The stand never subscribes; a site that receives does.
    check(BotSubscriptions::ensure($connection)['state'] === BotSubscriptions::RECEIVE_OFF && FakeMessengerDriver::$webhook['url'] === null, 'The stand must not subscribe.');
    check(webhook((int)$connection->id, ['id' => 1], 'x') === 404, 'The webhook of a stand answers 404.');
    $component->botReceive = true;
    BotSubscriptions::ensure($connection);
    $connection->refresh();
    $secret = (string)Bots::driver($connection)->webhookSecret;
    check(FakeMessengerDriver::$webhook['url'] === 'http://localhost/~comm-bot/'.(int)$connection->id && strlen($secret) >= 32, 'A receiving site subscribes the bot.');

    // The webhook: a wrong secret is refused, a right one stores the update once.
    $update = ['id' => 77, 'type' => 'message', 'chat' => ['id' => '-5', 'type' => 'group', 'title' => 'Компания / stand.test'],
        'from' => ['id' => '42', 'name' => 'Клиент Стенда'], 'message_id' => '3', 'text' => 'Пришлите счёт', 'date' => time(),
        'files' => [['ref' => 'stand-file', 'name' => 'реквизиты.txt', 'size' => 18]]];
    check(webhook((int)$connection->id, $update, 'wrong-secret') === 403 && webhook((int)$connection->id, $update, null) === 403, 'A wrong or missing secret is refused.');
    check(webhook(999999999, $update, $secret) === 404, 'An unknown bot is 404.');
    check(webhook((int)$connection->id, $update, $secret) === 200 && webhook((int)$connection->id, $update, $secret) === 200, 'The messenger gets 200, also for a repeat.');
    $rows = CmsCommBotUpdate::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id])->all();
    check(count($rows) === 1 && $rows[0]->status === 'new' && $rows[0]->update_key === 'u77', 'One row for the update and its repeat.');

    // Applying on MariaDB, the file into the real storage.
    $ingest = new BotIngest($connection);
    check($ingest->apply($rows[0]) === 'applied', 'The update applies.');
    $message = CmsCommMessage::find()->andWhere(['cms_site_id' => $siteId, 'channel' => 'testbot', 'external_id' => (int)$connection->id.':-5:3'])->one();
    $conversation = $message ? $message->conversation : null;
    check($message && $conversation && $conversation->chat_type === 'group' && $conversation->title === 'Компания / stand.test' && $message->identity->name === 'Клиент Стенда',
        'The message, its chat and its author are stored.');
    $account = Bots::account($connection);
    check($account->channel === 'testbot' && !CmsCommAccount::findMailboxes()->andWhere(['id' => (int)$account->id])->exists(), 'The bot account is not a mailbox.');
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    // Even with "read all" on the bot, mail does not show it.
    (new \skeeks\cms\communication\models\CmsCommAccountAccess(['cms_comm_account_id' => (int)$account->id, 'cms_user_id' => (int)$admin->id,
        'read_level' => \skeeks\cms\communication\models\CmsCommAccountAccess::READ_ALL, 'send_level' => 'any']))->save(false);
    check(!in_array((int)$account->id, array_map(static fn($a) => (int)$a->id, (new MailFolders($admin, $siteId))->accounts()), true), '"Почта" does not list the bot.');
    FakeMessengerDriver::$files['stand-file'] = 'ИНН 7700000000';
    check(BotFiles::fetch((int)$message->id) === ['stored' => 1, 'failed' => 0], 'The file is stored.');
    $file = CmsCommMessageFile::find()->andWhere(['cms_comm_message_id' => (int)$message->id])->one();
    $storage = $file && $file->storage_file_id ? CmsStorageFile::findOne((int)$file->storage_file_id) : null;
    if ($storage) {
        $storedFiles[] = (string)$storage->rootSrc;
    }
    check($storage && is_file((string)$storage->rootSrc) && file_get_contents((string)$storage->rootSrc) === 'ИНН 7700000000' && $file->content_hash === hash('sha256', 'ИНН 7700000000'),
        'The file is in the CMS storage with its fingerprint.');

    // The picture and the title of the chat from the messenger, once per picture.
    FakeMessengerDriver::$chats['-5'] = ['title' => 'Компания / stand.test (новое)', 'photo' => 'stand-icon'];
    FakeMessengerDriver::$files['stand-icon'] = 'PNG-ICON-BYTES';
    FakeMessengerDriver::$members['-5'] = [
        ['id' => '42', 'name' => 'Клиент Стенда', 'role' => 'creator', 'photo' => 'stand-face-42'],
        ['id' => '43', 'name' => 'Новый Участник', 'role' => 'admin'],
    ];
    FakeMessengerDriver::$files['stand-face-42'] = 'JPG-FACE-42';
    check(\skeeks\cms\communication\bot\BotChats::refresh($conversation) === true, 'The picture of the chat is fetched.');
    $icon = \skeeks\cms\communication\bot\BotChats::photo($conversation);
    if ($icon) {
        $storedFiles[] = (string)$icon->rootSrc;
    }
    check($icon && file_get_contents((string)$icon->rootSrc) === 'PNG-ICON-BYTES' && $conversation->title === 'Компания / stand.test (новое)'
        && $conversation->photo_ref === 'stand-icon' && $conversation->info_checked_at, 'The picture is in the storage, the title follows the messenger.');
    $client = \skeeks\cms\communication\models\CmsCommIdentity::find()->andWhere(['channel' => 'testbot', 'external_id' => '42'])->one();
    $face = $client ? \skeeks\cms\communication\bot\BotChats::identityPhotoFile($client) : null;
    if ($face) {
        $storedFiles[] = (string)$face->rootSrc;
    }
    $newcomer = \skeeks\cms\communication\models\CmsCommIdentity::find()->andWhere(['channel' => 'testbot', 'external_id' => '43'])->one();
    $roles = \yii\helpers\ArrayHelper::map(\skeeks\cms\communication\models\CmsCommParticipant::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->all(), 'cms_comm_identity_id', 'role');
    check($face && file_get_contents((string)$face->rootSrc) === 'JPG-FACE-42' && $newcomer && !$newcomer->photo_storage_file_id
        && ($roles[(int)$client->id] ?? null) === 'creator' && ($roles[(int)$newcomer->id] ?? null) === 'admin',
        'Members of the group come with their photos and roles; a member who never wrote is known too.');
    unset(FakeMessengerDriver::$files['stand-icon'], FakeMessengerDriver::$files['stand-face-42']);
    check(\skeeks\cms\communication\bot\BotChats::refresh($conversation) === false && (int)$conversation->photo_storage_file_id === (int)$icon->id,
        'The same picture is not downloaded again.');

    // A reply from the CRM to the fake messenger.
    $component->allowedChats = ['testbot:-5'];
    $reply = BotSend::queue($conversation, 'Счёт пришлём сегодня', ['user' => $admin, 'replyTo' => $message]);
    check(BotSend::deliver((int)$reply->id)[0] === 'sent', 'The reply is delivered.');
    $reply->refresh();
    check($reply->status === 'sent' && $reply->external_id === (int)$connection->id.':-5:'.FakeMessengerDriver::$sent[0]['id'] && FakeMessengerDriver::$sent[0]['reply'] === '3'
        && strpos(FakeMessengerDriver::$sent[0]['text'], 'Счёт пришлём сегодня') !== false, 'The reply went as an answer to the client\'s message.');
} finally {
    $transaction->rollBack();
    foreach ($storedFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

echo "OK: {$checks} checks\n";
