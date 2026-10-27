<?php
/**
 * Notifications of employees (step У4a) on the stand database, rolled back:
 * kinds and categories, the bell (groups, bursts, actions), settings "что и
 * куда", connecting a messenger by a one-time link, delivery through the
 * fake messenger (one message per entry, quiet hours, read in the CRM,
 * fallback after a refusal, no fallback after an unknown outcome).
 *
 * NOTIFY_STAND_TEST=local php tests/notify-stand.php (TEST_WORKER_ID — an active employee)
 */
if (getenv('NOTIFY_STAND_TEST') !== 'local') {
    throw new RuntimeException('Set NOTIFY_STAND_TEST=local.');
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
// The test bot is the only messenger channel; quiet hours and the stand mail stay out of the way.
$c['components']['notifications']['channels'] = ['push' => null,
    'messengers' => ['class' => \skeeks\cms\communication\notify\MessengerNotifyChannels::class, 'only' => ['testbot']],
    'email' => \skeeks\cms\communication\notify\EmailNotifyChannel::class];
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();
require_once __DIR__.'/support/FakeMessengerDriver.php';

use skeeks\cms\communication\bot\BotEvent;
use skeeks\cms\communication\bot\BotIngest;
use skeeks\cms\communication\bot\BotUser;
use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\chat\ChatAccess;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommLinkToken;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommNotifyChannel;
use skeeks\cms\communication\models\CmsCommNotifyDelivery;
use skeeks\cms\communication\notify\NotifyDeliveries;
use skeeks\cms\communication\notify\NotifyLinks;
use skeeks\cms\communication\tests\FakeMessengerDriver;
use skeeks\cms\models\CmsNotifySetting;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;
use skeeks\cms\notify\Notifications;
use skeeks\cms\notify\NotifyList;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }

$component = Yii::$app->communication;
$component->drivers['testbot'] = FakeMessengerDriver::class;
$component->allowedChats = ['testbot:7301'];
/** @var Notifications $n */
$n = Yii::$app->notifications;
$worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
check($worker && (int)$worker->is_worker === 1 && (int)$worker->is_active === 1, 'An active employee is needed (TEST_WORKER_ID).');
$uid = (int)$worker->id;
$task = \skeeks\cms\models\CmsTask::find()->orderBy(['id' => SORT_DESC])->one();

$db = Yii::$app->db;
$transaction = $db->beginTransaction();
try {
    CmsWebNotify::deleteAll(['cms_user_id' => $uid]);
    CmsNotifySetting::deleteAll(['cms_user_id' => $uid]);
    \skeeks\cms\models\CmsNotifyProfile::deleteAll(['cms_user_id' => $uid]);
    CmsCommNotifyChannel::deleteAll(['cms_user_id' => $uid]);
    CmsCommNotifyDelivery::deleteAll(['cms_user_id' => $uid]);

    // The registry: core, communication and proposal kinds; packages extend the categories of the core.
    foreach (['task.created', 'lead.new', 'mail.new', 'chat.message', 'link.visit', 'proposal.opened', 'proposal.accepted'] as $kind) {
        check($n->kind($kind) !== null, 'Kind '.$kind.' is registered.');
    }
    check($n->kind('proposal.accepted')['importance'] === 1 && $n->kind('proposal.accepted')['category'] === 'clients', 'A kind keeps its category and importance.');
    check(isset($n->settingsTree(Notifications::AUDIENCE_WORKER)['messages']) && !isset($n->settingsTree(Notifications::AUDIENCE_WORKER)['tasks']['kinds']['lead.partner_update']),
        'Settings of an employee show employee kinds only.');

    // By default everything is in the CRM and nowhere else.
    $rows = $n->notify($uid, 'task.created', ['name' => 'Вам поставлена новая задача', 'model' => $task, 'eventKey' => 'test:1']);
    check(count($rows) === 1 && !$rows[0]->is_read && $rows[0]->category === 'tasks' && $rows[0]->kind === 'task.created', 'A notification of a kind is stored with its category.');
    check(!$n->notify($uid, 'task.created', ['name' => 'Повтор', 'model' => $task, 'eventKey' => 'test:1']), 'One event is stored once per recipient.');
    check(!CmsCommNotifyDelivery::find()->andWhere(['cms_user_id' => $uid])->exists(), 'Nothing goes outside the CRM by default.');

    // A legacy row created directly gets the category of its record.
    $legacy = new CmsWebNotify(['cms_user_id' => $uid, 'name' => 'Старое уведомление', 'model_code' => \skeeks\cms\models\CmsTask::class, 'model_id' => (int)$task->id]);
    check($legacy->save() && $legacy->category === 'tasks' && $legacy->kind === null, 'A row without a kind still works and gets a category.');

    // Connecting the messenger: a one-time link, "Старт", the chat becomes the channel.
    $driver = $component->createDriver('testbot');
    $driver->setAttributes(['token' => '1:notify', 'botId' => '999', 'botUsername' => 'notify_test_bot'], false);
    $connection = new CmsCommConnection(['cms_site_id' => (int)Yii::$app->skeeks->site->id, 'name' => 'Уведомления: тестовый бот', 'driver' => 'testbot', 'is_active' => 1]);
    $connection->applyDriver($driver);
    $connection->save(false);
    $n->resetChannels();
    $channel = $n->channel('testbot');
    check($channel && $channel->isAvailable() && !$channel->isConnected($uid), 'The messenger channel is available and not connected.');
    $link = NotifyLinks::connect($uid, $connection);
    check($link['token']->purpose === NotifyLinks::PURPOSE && (int)$link['token']->expires_at <= time() + NotifyLinks::TTL, 'A short-lived key of its own purpose.');
    $ingest = new BotIngest($connection, Bots::driver($connection));
    $start = new BotEvent(BotEvent::STARTED, '7301', 'private');
    $start->sender = new BotUser('7301', 'Сотрудник Тестов');
    $start->payload = (string)$link['token']->token;
    $start->date = time();
    FakeMessengerDriver::$sent = [];
    $ingest->applyEvent($start);
    $identity = CmsCommIdentity::find()->andWhere(['channel' => 'testbot', 'external_id' => '7301'])->one();
    $row = CmsCommNotifyChannel::find()->andWhere(['cms_user_id' => $uid, 'channel' => 'testbot'])->one();
    check($identity && (int)$identity->cms_user_id === $uid && $identity->isWorker(), '"Старт" makes the person this employee.');
    check($row && $row->is_active && $row->conversation && $channel->isConnected($uid), 'The private chat is the channel.');
    check(CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$row->cms_comm_conversation_id, 'direction' => CmsCommMessage::DIRECTION_OUT])
        ->andWhere(['like', 'body_text', 'уведомления CRM будут приходить сюда'])->exists(), 'The bot says the channel is connected.');
    $ingest->applyEvent($start);
    check(CmsCommLinkToken::find()->andWhere(['token' => (string)$link['token']->token])->one()->used_at > 0, 'The key is used once.');
    check((new ChatAccess($worker))->canRead($row->conversation), 'The employee sees their own notification chat.');

    // Tasks go to the messenger too: one job in DELAY seconds, the message carries the link to the CRM.
    $n->saveModes($uid, ['tasks' => ['testbot' => Notifications::MODE_ALL]]);
    check($n->mode($uid, 'task.comment', 'testbot') === Notifications::MODE_ALL && $n->mode($uid, 'lead.new', 'testbot') === Notifications::MODE_OFF, 'A category mode covers its kinds only.');
    [$note] = $n->notify($uid, 'task.comment', ['name' => 'Добавлен новый комментарий к задаче', 'model' => $task, 'eventKey' => 'test:2']);
    $delivery = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$note->id])->one();
    check($delivery && $delivery->status === CmsCommNotifyDelivery::STATUS_QUEUED && $delivery->channel === 'testbot' && (int)$delivery->scheduled_at >= time() + NotifyDeliveries::DELAY - 2,
        'The notification is queued for the messenger with a short delay.');
    NotifyDeliveries::run((int)$delivery->id);
    $delivery->refresh();
    $message = CmsCommMessage::findOne((int)$delivery->cms_comm_message_id);
    check($delivery->status === CmsCommNotifyDelivery::STATUS_SENDING && $message && $message->status === CmsCommMessage::STATUS_QUEUED, 'The message goes through the CRM send queue.');
    check(mb_strpos((string)$message->body_text, 'Добавлен новый комментарий к задаче') !== false && mb_strpos((string)$message->body_text, '/~n/'.(int)$note->id) !== false
        && mb_strpos((string)$message->body_text, 'Задачи') !== false, 'A short text with the category and the link to the CRM.');
    NotifyDeliveries::onBotResult((int)$message->id, CmsCommMessage::STATUS_SENT, null);
    $delivery->refresh();
    $row->refresh();
    check($delivery->status === CmsCommNotifyDelivery::STATUS_SENT && $row->last_ok_at > 0, 'The send job outcome reaches the journal and the channel.');

    // An entry of related events is one message: "клиент открыл" and the task that followed.
    $n->saveModes($uid, ['clients' => ['testbot' => Notifications::MODE_ALL]]);
    $group = $n->group('test-send:1', static function () use ($n, $uid, $task) {
        return array_merge(
            $n->notify($uid, 'proposal.opened', ['name' => 'Плитка: клиент открыл презентацию №1 (письмо)', 'eventKey' => 'test:3']),
            $n->notify($uid, 'task.created', ['name' => 'Вам поставлена новая задача', 'model' => $task, 'eventKey' => 'test:4'])
        );
    });
    check(count($group) === 2 && $group[0]->group_key === 'test-send:1' && $group[1]->group_key === 'test-send:1', 'Notifications inside group() share the key.');
    $first = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$group[0]->id])->one();
    $second = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$group[1]->id])->one();
    NotifyDeliveries::run((int)$first->id);
    NotifyDeliveries::run((int)$second->id);
    $first->refresh();
    $second->refresh();
    $text = (string)CmsCommMessage::findOne((int)$first->cms_comm_message_id)->body_text;
    check(mb_strpos($text, 'клиент открыл презентацию') !== false && mb_strpos($text, '+ Вам поставлена новая задача') !== false, 'One message tells the event and what followed.');
    check($second->status === CmsCommNotifyDelivery::STATUS_SKIPPED && !$second->cms_comm_message_id, 'The second event of the entry is not sent again.');
    $list = new NotifyList($uid);
    $entries = $list->page()['entries'];
    $entry = null;
    foreach ($entries as $rows) {
        if ($rows[0]->group_key === 'test-send:1') {
            $entry = $rows;
        }
    }
    check($entry && count($entry) === 2 && (int)$entry[0]->id === (int)$group[0]->id, 'The bell shows the group as one entry, the cause first.');
    check(strpos(NotifyList::render($entry), 'sx-notify-entry__child') !== false, 'The entry renders what followed under the event.');

    // A burst: one row, its count grows; the messenger is not told again within BURST_INTERVAL.
    $n->saveModes($uid, ['messages' => ['testbot' => Notifications::MODE_ALL]]);
    $n->notify($uid, 'chat.message', ['name' => 'Новое сообщение от Клиента', 'eventKey' => 'test:chat', 'collapse' => true]);
    [$burst] = $n->notify($uid, 'chat.message', ['name' => 'Новые сообщения (2) от Клиента', 'eventKey' => 'test:chat', 'collapse' => true]);
    check((int)$burst->count === 2 && $burst->name === 'Новые сообщения (2) от Клиента', 'A burst updates one row.');
    check((int)CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$burst->id])->count() === 1, 'A burst is not sent twice within the interval.');

    // Read in the CRM before the job: not sent.
    [$read] = $n->notify($uid, 'task.ready', ['name' => 'Ваша задача готова', 'model' => $task, 'eventKey' => 'test:5']);
    $list->act((int)$read->id, 'read');
    $readDelivery = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$read->id])->one();
    NotifyDeliveries::run((int)$readDelivery->id);
    $readDelivery->refresh();
    check($readDelivery->status === CmsCommNotifyDelivery::STATUS_SKIPPED && $readDelivery->reason === 'Прочитано в CRM', 'A notification read in the CRM is not sent.');

    // A category off in the CRM: the row is silent (read), the messenger still gets it.
    $n->saveModes($uid, ['tasks' => ['crm' => Notifications::MODE_OFF]]);
    [$silent] = $n->notify($uid, 'task.check', ['name' => 'Вам необходимо проверить задачу', 'model' => $task, 'eventKey' => 'test:6']);
    $silentDelivery = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$silent->id])->one();
    check($silent->is_read && $silent->is_seen && $silentDelivery && !$silentDelivery->skip_if_read, 'A silent CRM row is read, the messenger still gets it.');
    NotifyDeliveries::run((int)$silentDelivery->id);
    $silentDelivery->refresh();
    check($silentDelivery->status === CmsCommNotifyDelivery::STATUS_SENDING, 'A silent row is sent outside the CRM.');
    $n->saveModes($uid, ['tasks' => ['crm' => Notifications::MODE_ALL]]);

    // Quiet hours postpone; urgent never waits.
    $profile = $n->profile($uid);
    $tz = new DateTimeZone(Yii::$app->timeZone);
    $minute = (int)(new DateTime('now', $tz))->format('G') * 60 + (int)(new DateTime('now', $tz))->format('i');
    $profile->setAttributes(['is_quiet' => 1, 'quiet_from' => ($minute + 1440 - 60) % 1440, 'quiet_to' => ($minute + 60) % 1440], false);
    $profile->save(false);
    check($n->quietEndsAt($uid) !== null, 'Now is inside the quiet hours.');
    [$quiet] = $n->notify($uid, 'task.canceled', ['name' => 'Задача отменена', 'model' => $task, 'eventKey' => 'test:7']);
    $quietDelivery = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$quiet->id])->one();
    $result = NotifyDeliveries::run((int)$quietDelivery->id);
    $quietDelivery->refresh();
    check($result[0] === 'requeue' && $result[1] > 0 && $quietDelivery->status === CmsCommNotifyDelivery::STATUS_POSTPONED, 'Quiet hours postpone the message.');
    [$urgent] = $n->notify($uid, 'task.canceled', ['name' => 'Срочно: сайт клиента недоступен', 'importance' => Notifications::IMPORTANCE_URGENT, 'eventKey' => 'test:8']);
    $urgentDelivery = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$urgent->id])->one();
    NotifyDeliveries::run((int)$urgentDelivery->id);
    $urgentDelivery->refresh();
    check($urgentDelivery->status === CmsCommNotifyDelivery::STATUS_SENDING, 'An urgent notification ignores quiet hours.');
    $profile->is_quiet = 0;
    $profile->save(false);

    // A refusal tries the next connected channel; an unknown outcome does not.
    NotifyDeliveries::onBotResult((int)$urgentDelivery->cms_comm_message_id, CmsCommMessage::STATUS_FAILED, 'Бот заблокирован');
    $urgentDelivery->refresh();
    $fallback = CmsCommNotifyDelivery::find()->andWhere(['fallback_of_id' => (int)$urgentDelivery->id])->one();
    $emailConnected = $n->channel('email') && $n->channel('email')->isConnected($uid);
    check($urgentDelivery->status === CmsCommNotifyDelivery::STATUS_FAILED && ($emailConnected ? ($fallback && $fallback->channel === 'email' && !$fallback->skip_if_read) : !$fallback),
        'A refusal starts the fallback channel when the person has one.');
    NotifyDeliveries::onBotResult((int)$silentDelivery->cms_comm_message_id, CmsCommMessage::STATUS_UNKNOWN, 'Нет ответа');
    $silentDelivery->refresh();
    check($silentDelivery->status === CmsCommNotifyDelivery::STATUS_UNKNOWN
        && !CmsCommNotifyDelivery::find()->andWhere(['fallback_of_id' => (int)$silentDelivery->id])->exists(), 'An unknown outcome is not replaced by another channel.');

    // "Не уведомлять о таком": the kind is gone everywhere; an urgent one cannot be muted.
    check($list->act((int)$note->id, 'mute') && $n->isMuted($uid, 'task.comment') && !CmsWebNotify::find()->andWhere(['id' => (int)$note->id])->exists(), 'Mute removes the entry and switches the kind off.');
    check(!$n->notify($uid, 'task.comment', ['name' => 'Ещё комментарий', 'model' => $task, 'eventKey' => 'test:9']), 'A muted kind is not notified.');
    check(count($n->notify($uid, 'task.comment', ['name' => 'Срочный комментарий', 'importance' => Notifications::IMPORTANCE_URGENT, 'eventKey' => 'test:10'])) === 1, 'Urgent comes even when muted.');
    $n->mute($uid, 'task.comment', false);
    check(!$n->isMuted($uid, 'task.comment'), '"Вернуть" switches the kind on.');

    // Ready variants and "как в CRM".
    $n->copyCrm($uid, 'testbot');
    check($n->categoryMode($uid, 'system', 'testbot') === Notifications::MODE_ALL, '"Как в CRM" repeats the CRM column in the channel.');
    check(!$n->applyPreset($uid, 'important_max'), 'A ready variant of a channel the site does not have is not offered.');
    check($n->applyPreset($uid, 'crm') && $n->categoryMode($uid, 'tasks', 'testbot') === Notifications::MODE_OFF && $n->profile($uid)->preset === 'crm', '"Всё только в CRM" turns other channels off.');

    // The bell: badge, opening, read all, clear a section.
    $list->markSeen();
    check($list->badge() === 0 && $list->query()->andWhere(['is_read' => 0])->exists(), 'Opening the window resets the badge, unread entries stay highlighted.');
    $list->readAll('tasks');
    check(!$list->query()->andWhere(['is_read' => 0, 'category' => 'tasks'])->exists(), '"Прочитать всё" of a section.');
    $list->clear('messages');
    check(!$list->query()->andWhere(['category' => 'messages'])->exists() && $list->query()->andWhere(['category' => 'tasks'])->exists(), '"Очистить" removes the open section only.');

    // The link of a message opens the record of the notification, never another site.
    check(strpos($legacy->getOpenUrl(), 'admin-cms-task/view') !== false && strpos($legacy->getOpenUrl(), 'pk='.(int)$task->id) !== false, '/~n/<id> leads to the record page.');
    $outside = new CmsWebNotify(['cms_user_id' => $uid, 'name' => 'Внешняя ссылка', 'url' => 'https://example.org/x']);
    $inside = new CmsWebNotify(['cms_user_id' => $uid, 'name' => 'Своя ссылка', 'url' => '/~sxx/cms/admin-profile/update']);
    check(strpos($outside->getOpenUrl(), 'example.org') === false && $inside->getOpenUrl() === '/~sxx/cms/admin-profile/update'
        && !CmsWebNotify::isLocalUrl('//example.org/x') && CmsWebNotify::isLocalUrl('http://localhost/x'), 'The short link does not redirect to another site.');

    // Review fixes (27.10.2026).
    $n->applyPreset($uid, 'crm');
    CmsWebNotify::deleteAll(['cms_user_id' => $uid]);
    CmsCommNotifyDelivery::deleteAll(['cms_user_id' => $uid]);

    // A group whose later job runs first: the earlier one still sends both events in one message.
    $n->saveModes($uid, ['clients' => ['testbot' => Notifications::MODE_ALL], 'tasks' => ['testbot' => Notifications::MODE_ALL]]);
    $pair = $n->group('test-send:2', static fn() => array_merge(
        $n->notify($uid, 'proposal.opened', ['name' => 'Клиент открыл КП №2', 'eventKey' => 'r:1']),
        $n->notify($uid, 'task.created', ['name' => 'Вам поставлена новая задача', 'model' => $task, 'eventKey' => 'r:2'])
    ));
    $d1 = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$pair[0]->id])->one();
    $d2 = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$pair[1]->id])->one();
    NotifyDeliveries::run((int)$d2->id);
    NotifyDeliveries::run((int)$d1->id);
    $d1->refresh();
    $d2->refresh();
    $text = (string)CmsCommMessage::findOne((int)$d1->cms_comm_message_id)->body_text;
    check(mb_strpos($text, 'Клиент открыл КП №2') !== false && mb_strpos($text, '+ Вам поставлена новая задача') !== false
        && $d2->status === CmsCommNotifyDelivery::STATUS_SKIPPED && mb_strpos((string)$d2->reason, 'одним сообщением') !== false, 'Jobs of a group in reverse order still give one full message.');

    // A burst of a category off in the CRM keeps collapsing (silent rows are read from the start).
    $n->saveModes($uid, ['messages' => ['crm' => Notifications::MODE_OFF, 'testbot' => Notifications::MODE_ALL]]);
    $n->notify($uid, 'chat.message', ['name' => 'Сообщение 1', 'eventKey' => 'r:chat', 'collapse' => true]);
    $n->notify($uid, 'chat.message', ['name' => 'Сообщения (2)', 'eventKey' => 'r:chat', 'collapse' => true]);
    $silentRows = CmsWebNotify::find()->andWhere(['cms_user_id' => $uid, 'kind' => 'chat.message'])->all();
    check(count($silentRows) === 1 && (int)$silentRows[0]->count === 2
        && (int)CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$silentRows[0]->id])->count() === 1, 'A silent burst is one row and one message.');

    // The own mode of a kind is shown and kept even when it equals the category.
    $n->saveModes($uid, ['tasks' => ['testbot' => Notifications::MODE_ALL]], ['task.ready' => ['testbot' => Notifications::MODE_ALL]]);
    check($n->kindMode($uid, 'task.ready', 'testbot') === Notifications::MODE_ALL, 'An explicit kind mode equal to its category is kept.');
    $n->saveModes($uid, ['tasks' => ['testbot' => Notifications::MODE_OFF]], ['task.ready' => ['testbot' => Notifications::MODE_ALL]]);
    check($n->mode($uid, 'task.ready', 'testbot') === Notifications::MODE_ALL && $n->mode($uid, 'task.check', 'testbot') === Notifications::MODE_OFF, 'Changing the category keeps the kind.');

    // "Как в CRM" copies kinds too.
    $n->saveModes($uid, ['tasks' => ['crm' => Notifications::MODE_ALL]], ['task.order' => ['crm' => Notifications::MODE_OFF, 'testbot' => Notifications::MODE_IMPORTANT]]);
    $n->copyCrm($uid, 'testbot');
    check($n->kindMode($uid, 'task.order', 'testbot') === Notifications::MODE_OFF && $n->kindMode($uid, 'task.ready', 'testbot') === null
        && $n->categoryMode($uid, 'tasks', 'testbot') === Notifications::MODE_ALL, '"Как в CRM" repeats kinds of the CRM and clears others.');

    // A mute removed elsewhere is seen at once by a long process.
    $n->mute($uid, 'task.resumed');
    check(!$n->notify($uid, 'task.resumed', ['name' => 'Задача возобновлена', 'eventKey' => 'r:m1']), 'Muted.');
    CmsNotifySetting::deleteAll(['cms_user_id' => $uid, 'code' => 'task.resumed', 'channel' => '*']);
    check(count($n->notify($uid, 'task.resumed', ['name' => 'Задача возобновлена', 'eventKey' => 'r:m2'])) === 1, 'A mute removed by another request no longer applies.');

    // Pages of the bell go by entries: a group never comes again.
    CmsWebNotify::deleteAll(['cms_user_id' => $uid]);
    $n->applyPreset($uid, 'crm');
    $n->group('page:G', static fn() => $n->notify($uid, 'task.created', ['name' => 'G1', 'eventKey' => 'p:g1']));
    $n->notify($uid, 'task.created', ['name' => 'X', 'eventKey' => 'p:x']);
    $n->group('page:G', static fn() => $n->notify($uid, 'task.created', ['name' => 'G2', 'eventKey' => 'p:g2']));
    $n->notify($uid, 'task.created', ['name' => 'Y', 'eventKey' => 'p:y']);
    CmsWebNotify::updateAll(['updated_at' => new \yii\db\Expression('id')], ['cms_user_id' => $uid]);
    $seen = [];
    $after = null;
    do {
        $page = $list->page(null, $after, 1);
        foreach ($page['entries'] as $rows) {
            $seen[] = implode('+', array_map(static fn($r) => $r->name, $rows));
        }
        $after = $page['next'];
    } while ($after);
    check($seen === ['Y', 'G1+G2', 'X'], 'Pages give every entry once: '.implode(', ', $seen));

    // One chat serves one employee; a chat of a client is not taken by an employee's link.
    $other = CmsUser::find()->andWhere(['is_worker' => 1, 'is_active' => 1])->andWhere(['<>', 'id', $uid])->one();
    check($other !== null, 'A second employee is needed.');
    $otherLink = NotifyLinks::connect((int)$other->id, $connection);
    $start2 = new BotEvent(BotEvent::STARTED, '7301', 'private');
    $start2->sender = new BotUser('7301', 'Сотрудник Тестов');
    $start2->payload = (string)$otherLink['token']->token;
    $start2->date = time();
    $ingest->applyEvent($start2);
    check(!CmsCommNotifyChannel::find()->andWhere(['cms_user_id' => $uid, 'channel' => 'testbot'])->exists()
        && CmsCommNotifyChannel::find()->andWhere(['cms_user_id' => (int)$other->id, 'channel' => 'testbot', 'is_active' => 1])->exists(), 'The chat moves to the second employee, the first loses it.');
    $client = CmsUser::find()->andWhere(['is_worker' => 0])->one();
    $clientPerson = new CmsCommIdentity(['cms_site_id' => (int)$identity->cms_site_id, 'channel' => 'testbot', 'external_id' => '7302', 'name' => 'Клиент', 'is_bot' => 0, 'cms_user_id' => (int)$client->id]);
    $clientPerson->save(false);
    $clientLink = NotifyLinks::connect($uid, $connection);
    $start3 = new BotEvent(BotEvent::STARTED, '7302', 'private');
    $start3->sender = new BotUser('7302', 'Клиент');
    $start3->payload = (string)$clientLink['token']->token;
    $start3->date = time();
    $ingest->applyEvent($start3);
    $clientPerson->refresh();
    check((int)$clientPerson->cms_user_id === (int)$client->id && !CmsCommNotifyChannel::find()->andWhere(['cms_user_id' => $uid, 'channel' => 'testbot'])->exists()
        && CmsCommLinkToken::findOne((int)$clientLink['token']->id)->used_at === null, 'A person of a client stays the client; the key is not spent.');

    // Second review (27.10.2026).
    check(!CmsWebNotify::isLocalUrl('http://example.org\\@localhost/') && !CmsWebNotify::isLocalUrl("/\t/example.org")
        && !CmsWebNotify::isLocalUrl('/\\example.org'), 'Backslashes and control characters never pass as a local link.');

    // A refused message of an entry: the fallback channel gets every event of it.
    CmsCommNotifyChannel::deleteAll(['cms_user_id' => $uid]);
    $ownLink = NotifyLinks::connect($uid, $connection);
    $start4 = new BotEvent(BotEvent::STARTED, '7301', 'private');
    $start4->sender = new BotUser('7301', 'Сотрудник Тестов');
    $start4->payload = (string)$ownLink['token']->token;
    $start4->date = time();
    $ingest->applyEvent($start4);
    $n->applyPreset($uid, 'crm');
    $n->saveModes($uid, ['clients' => ['testbot' => Notifications::MODE_ALL], 'tasks' => ['testbot' => Notifications::MODE_ALL]]);
    $fb = $n->group('test-send:3', static fn() => array_merge(
        $n->notify($uid, 'proposal.opened', ['name' => 'Клиент открыл КП №3', 'eventKey' => 'f:1']),
        $n->notify($uid, 'task.created', ['name' => 'Вам поставлена новая задача', 'model' => $task, 'eventKey' => 'f:2'])
    ));
    $fbMain = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$fb[0]->id, 'channel' => 'testbot'])->one();
    NotifyDeliveries::run((int)$fbMain->id);
    $fbMain->refresh();
    NotifyDeliveries::onBotResult((int)$fbMain->cms_comm_message_id, CmsCommMessage::STATUS_FAILED, 'Бот заблокирован');
    $fallbackRows = CmsCommNotifyDelivery::find()->andWhere(['fallback_of_id' => (int)$fbMain->id])->orderBy(['id' => SORT_ASC])->all();
    if ($n->channel('email') && $n->channel('email')->isConnected($uid)) {
        check(count($fallbackRows) === 2 && (int)$fallbackRows[0]->cms_web_notify_id === (int)$fb[0]->id && (int)$fallbackRows[1]->cms_web_notify_id === (int)$fb[1]->id,
            'The fallback channel gets the event and what followed it.');
    } else {
        check(!$fallbackRows, 'No fallback without another channel.');
    }

    // Settings changed while a message waited: the settings of the moment of sending decide.
    [$late] = $n->notify($uid, 'task.ready', ['name' => 'Ваша задача готова', 'model' => $task, 'eventKey' => 'l:1']);
    $lateDelivery = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$late->id, 'channel' => 'testbot'])->one();
    $n->applyPreset($uid, 'crm');
    NotifyDeliveries::run((int)$lateDelivery->id);
    $lateDelivery->refresh();
    check($lateDelivery->status === CmsCommNotifyDelivery::STATUS_SKIPPED && $lateDelivery->reason === 'Выключено в настройках уведомлений', 'A channel switched off before sending is not used.');
    [$test] = $n->notify($uid, 'notify.test', ['name' => 'Проверка канала', 'eventKey' => 't:1']);
    $testDelivery = NotifyDeliveries::queueTest($test, 'testbot');
    NotifyDeliveries::run((int)$testDelivery->id);
    $testDelivery->refresh();
    check($testDelivery->status === CmsCommNotifyDelivery::STATUS_SENDING, '"Проверить" sends whatever the settings say.');

    // Third review: every event of an entry follows the settings; "Проверить" with a row already queued.
    $n->saveModes($uid, ['clients' => ['testbot' => Notifications::MODE_ALL], 'tasks' => ['testbot' => Notifications::MODE_ALL]]);
    $g4 = $n->group('test-send:4', static fn() => array_merge(
        $n->notify($uid, 'proposal.opened', ['name' => 'Клиент открыл КП №4', 'eventKey' => 'g4:1']),
        $n->notify($uid, 'task.created', ['name' => 'Задача по КП №4', 'model' => $task, 'eventKey' => 'g4:2'])
    ));
    $n->saveModes($uid, [], ['task.created' => ['testbot' => Notifications::MODE_OFF]]);
    $g4main = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$g4[0]->id, 'channel' => 'testbot'])->one();
    $g4child = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$g4[1]->id, 'channel' => 'testbot'])->one();
    NotifyDeliveries::run((int)$g4main->id);
    $g4main->refresh();
    $g4child->refresh();
    $g4text = (string)CmsCommMessage::findOne((int)$g4main->cms_comm_message_id)->body_text;
    check(mb_strpos($g4text, 'Клиент открыл КП №4') !== false && mb_strpos($g4text, 'Задача по КП №4') === false
        && $g4child->reason === 'Выключено в настройках уведомлений', 'An event switched off in the settings is left out of the entry message.');

    $n->saveModes($uid, ['system' => ['testbot' => Notifications::MODE_ALL]]);
    $profile = $n->profile($uid);
    $profile->setAttributes(['is_quiet' => 1, 'quiet_from' => ($minute + 1440 - 60) % 1440, 'quiet_to' => ($minute + 60) % 1440], false);
    $profile->save(false);
    [$test2] = $n->notify($uid, 'notify.test', ['name' => 'Проверка канала 2', 'eventKey' => 't:2']);
    check(CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$test2->id, 'channel' => 'testbot'])->exists(), 'The settings queued the test notification too.');
    $test2Delivery = NotifyDeliveries::queueTest($test2, 'testbot');
    $result2 = NotifyDeliveries::run((int)$test2Delivery->id);
    $test2Delivery->refresh();
    check($result2[0] === 'done' && $test2Delivery->status === CmsCommNotifyDelivery::STATUS_SENDING, '"Проверить" turns the queued row into a test and sends it at once, quiet hours or not.');
    $profile->is_quiet = 0;
    $profile->save(false);

    echo "notify-stand: OK ({$checks} checks)\n";
} finally {
    $transaction->rollBack();
}
