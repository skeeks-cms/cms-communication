<?php
/**
 * Work from chats (stage 5.5) against a local application, in a transaction
 * that is rolled back: CHAT_TASKS_STAND_TEST=local php tests/chat-tasks-stand.php
 *
 * A fake messenger is registered for the run; a company with a manager is
 * made inside the transaction. Checks: "Это наш сотрудник", a task from a
 * chat message in the standard window (the source "message"), "/task" of an
 * employee and of a client, "/task" in answer to a message, a mention of the
 * bot, refusals, the bot's answer with the number, the chat hearing about the
 * task's statuses (and not hearing when switched off), "Отправить клиенту"
 * to a chat with the button and the file. Files written to the storage are
 * removed from disk afterwards. No real messenger is called.
 */
if (getenv('CHAT_TASKS_STAND_TEST') !== 'local') {
    throw new RuntimeException('Set CHAT_TASKS_STAND_TEST=local.');
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
require_once __DIR__.'/support/SampleSendable.php';

use skeeks\cms\communication\bot\BotEvent;
use skeeks\cms\communication\bot\BotIngest;
use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotUser;
use skeeks\cms\communication\chat\ChatIdentities;
use skeeks\cms\communication\chat\ChatLinks;
use skeeks\cms\communication\chat\ChatTasks;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommChatTask;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\send\SendRequest;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MessageRecordFactory;
use skeeks\cms\communication\services\SendContacts;
use skeeks\cms\communication\source\MessageRecordSource;
use skeeks\cms\communication\tests\FakeMessengerDriver;
use skeeks\cms\communication\tests\SampleSendable;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2manager;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }

$component = Yii::$app->communication;
$component->drivers['testbot'] = FakeMessengerDriver::class;
$component->allowedChats = ['testbot:-9001', 'testbot:-9002'];
FakeMessengerDriver::reset();
$admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
$worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
Yii::$app->user->setIdentity($admin);

$db = Yii::$app->db;
$transaction = $db->beginTransaction();
$storedFiles = [];
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $driver = $component->createDriver('testbot');
    $driver->setAttributes(['token' => '1:tasks', 'botId' => '999', 'botUsername' => 'tasks_test_bot'], false);
    $connection = new CmsCommConnection(['cms_site_id' => $siteId, 'name' => 'Задачи: тестовый бот', 'driver' => 'testbot', 'is_active' => 1]);
    $connection->applyDriver($driver);
    $connection->save(false);
    $account = Bots::account($connection);
    (new CmsCommAccountAccess(['cms_comm_account_id' => (int)$account->id, 'cms_user_id' => (int)$admin->id,
        'read_level' => CmsCommAccountAccess::READ_ALL, 'send_level' => CmsCommAccountAccess::SEND_ANY, 'is_notify' => 0]))->save(false);
    $ingest = new BotIngest($connection, Bots::driver($connection));
    $now = time();
    $msg = static function (string $chat, string $id, string $text, array $from, ?string $replyTo = null, ?string $title = null) use ($ingest, $now, $connection) {
        $e = new BotEvent(BotEvent::MESSAGE, $chat, 'group');
        $e->sender = new BotUser($from[0], $from[1]);
        $e->messageId = $id;
        $e->text = $text;
        $e->date = $now - 100 + (int)$id;
        $e->chatTitle = $title;
        $e->replyToMessageId = $replyTo;
        $ingest->applyEvent($e);

        return CmsCommMessage::find()->andWhere(['channel' => 'testbot', 'external_id' => BotIngest::key($connection, $chat, $id)])->one();
    };
    $sentTexts = static fn() => array_column(FakeMessengerDriver::$sent, 'text');
    $queued = static fn(CmsCommConversation $chat) => CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$chat->id, 'direction' => CmsCommMessage::DIRECTION_OUT])
        ->orderBy(['id' => SORT_ASC])->all();

    $company = new CmsCompany(['name' => 'Тест Задачи Чатов']);
    $company->save(false);
    (new CmsCompany2manager(['cms_company_id' => (int)$company->id, 'cms_user_id' => (int)$worker->id]))->save(false);

    // A group of the company: the client and an employee who writes from their own account.
    $first = $msg('-9001', '1', 'Добрый день! На главной не открывается форма заявки', ['9101', 'Анна Клиентова'], null, 'Тест Задачи / tasks-chat.test');
    $group = $first->conversation;
    (new ChatLinks($admin))->link($group, CrmRecords::COMPANY, (int)$company->id);
    $staff = $msg('-9001', '2', 'Посмотрим', ['9102', 'Иван Сотрудников']);
    $ivan = $staff->identity;
    check(!$ivan->isWorker() && $staff->direction === CmsCommMessage::DIRECTION_IN, 'An employee writing from their own account is a client until marked.');

    // "Это наш сотрудник".
    ChatIdentities::markWorker($ivan, (int)$admin->id, (int)$admin->id);
    $ivan->refresh();
    check($ivan->isWorker() && (int)$ivan->cms_user_id === (int)$admin->id && $ivan->link_method === CmsCommIdentity::LINK_MANUAL, 'A member is marked as our employee.');
    $thrown = false;
    try {
        ChatIdentities::markWorker($first->identity, 999999999, (int)$admin->id);
    } catch (DomainException $e) {
        $thrown = true;
    }
    check($thrown, 'Only an active employee may be chosen.');

    // A task from a chat message in the standard window (?source=message:ID).
    $source = new MessageRecordSource();
    check($source->find((string)$first->id) !== null, 'An employee who reads the chat may create a task from its message.');
    $factory = new MessageRecordFactory($admin);
    $task = new CmsTask();
    $factory->prefill($first, $task);
    check($task->name === 'Добрый день! На главной не открывается форма заявки' && (int)$task->cms_company_id === (int)$company->id
        && mb_strpos((string)$task->description, 'чат «Тест Задачи / tasks-chat.test»') !== false && mb_strpos((string)$task->description, 'Анна Клиентова') !== false,
        'The task gets the text, the author, the chat and the chat\'s company.');
    check(mb_strpos($factory->describe($first), 'Из сообщения в Тестовый мессенджер, чат «Тест Задачи / tasks-chat.test» от Анна Клиентова') === 0, 'The window says where the message is from.');
    $manual = $factory->create($first, CrmRecords::TASK, ['executor_id' => (int)$admin->id, 'created_by' => (int)$admin->id]);
    check(CmsCommLink::find()->andWhere(['cms_comm_message_id' => (int)$first->id, 'model_code' => CmsTask::class, 'model_id' => (int)$manual->id, 'link_type' => CmsCommLink::TYPE_CREATED])->exists()
        && CmsCommLink::find()->andWhere(['cms_comm_message_id' => (int)$first->id, 'model_code' => CmsCompany::class, 'model_id' => (int)$company->id])->exists(),
        'The message is linked to the task and, being in a group, goes into the company\'s feed on its own.');
    $origins = $source->origins($manual);
    check($origins && $origins[0]['label'] === 'Создано из сообщения' && strpos($origins[0]['html'], 'Тест Задачи / tasks-chat.test') !== false, 'The task card says which chat message it came from.');
    check(!CmsCommChatTask::find()->andWhere(['cms_task_id' => (int)$manual->id])->exists(), 'A task made in the CRM does not report its statuses to the chat.');

    // "/task" of our employee: the task goes to the company's manager, the bot answers with the number.
    $command = $msg('-9001', '3', '/task Починить форму заявки на главной', ['9102', 'Иван Сотрудников']);
    $row = CmsCommChatTask::find()->andWhere(['cms_comm_message_id' => (int)$command->id])->one();
    $byEmployee = $row ? $row->task : null;
    check($byEmployee && $byEmployee->name === 'Починить форму заявки на главной' && (int)$byEmployee->executor_id === (int)$worker->id
        && (int)$byEmployee->created_by === (int)$admin->id && $byEmployee->status === CmsTask::STATUS_NEW && (int)$byEmployee->cms_company_id === (int)$company->id,
        'An employee\'s /task gives the company\'s manager a new task from that employee.');
    $answers = $queued($group);
    $answer = end($answers);
    check($answer && mb_strpos((string)$answer->body_text, 'Задача №'.(int)$byEmployee->id.' «Починить форму заявки на главной» поставлена. Ответственный — ') === 0
        && (int)$answer->reply_to_message_id === (int)$command->id, 'The bot answers the command with the task number.');
    $count = count($answers);
    $msg('-9001', '3', '/task Починить форму заявки на главной', ['9102', 'Иван Сотрудников']);
    check(CmsCommChatTask::find()->andWhere(['cms_comm_message_id' => (int)$command->id])->count() == 1 && count($queued($group)) === $count, 'A repeated event does not make a second task.');

    // The chat hears about the statuses, once; not when switched off.
    Yii::$app->user->setIdentity($worker);
    $byEmployee->status = CmsTask::STATUS_ACCEPTED;
    check($byEmployee->save(), 'The manager accepts the task: '.implode(' ', $byEmployee->getFirstErrors()));
    $told = $queued($group);
    check(count($told) === $count + 1 && end($told)->body_text === 'Задача №'.(int)$byEmployee->id.' «Починить форму заявки на главной» принята в работу.'
        && (int)end($told)->reply_to_message_id === (int)$command->id, 'The chat hears that the task is accepted.');
    $group->updateAttributes(['task_notify' => 0]);
    $byEmployee->status = CmsTask::STATUS_CANCELED;
    check($byEmployee->save(), 'The manager cancels the task.');
    check(count($queued($group)) === $count + 1, 'A chat with the setting off hears nothing.');
    $group->updateAttributes(['task_notify' => 1]);
    Yii::$app->user->setIdentity($admin);

    // "/task" of the client in the company's chat: accepted by the manager at once.
    $msg('-9001', '4', 'Ещё цены в каталоге старые', ['9101', 'Анна Клиентова']);
    $answerTo = $msg('-9001', '5', '/task', ['9101', 'Анна Клиентова'], '4');
    $byClient = CmsCommChatTask::find()->andWhere(['cms_comm_message_id' => (int)$answerTo->id])->one();
    check($byClient && $byClient->task->name === 'Ещё цены в каталоге старые' && (int)$byClient->task->executor_id === (int)$worker->id
        && $byClient->task->status === CmsTask::STATUS_ACCEPTED, '/task in answer to a message makes the task of that message; a client\'s task is the manager\'s own.');

    // A mention of the bot with "задача".
    $mention = $msg('-9001', '6', '@tasks_test_bot поставь задачу: проверить отправку формы', ['9102', 'Иван Сотрудников']);
    $byMention = CmsCommChatTask::find()->andWhere(['cms_comm_message_id' => (int)$mention->id])->one();
    check($byMention && $byMention->task->name === 'проверить отправку формы', 'A mention of the bot with "задача" works as /task.');
    check(ChatTasks::command('/task@tasks_test_bot Сделать', 'tasks_test_bot') === 'Сделать' && ChatTasks::command('Просто текст', 'tasks_test_bot') === null
        && ChatTasks::command('/task', 'tasks_test_bot') === '', 'Commands are recognized.');

    // Refusals: a bare command, a client in a chat of no company.
    $before = (int)CmsCommChatTask::find()->count();
    $msg('-9001', '7', '/task', ['9101', 'Анна Клиентова']);
    $stranger = $msg('-9002', '1', '/task Сделайте сайт', ['9201', 'Незнакомец'], null, 'Посторонняя группа');
    check((int)CmsCommChatTask::find()->count() === $before, 'No task without text or from a client of no company.');
    $refusals = array_map(static fn($m) => (string)$m->body_text, $queued($stranger->conversation));
    check($refusals && mb_strpos(end($refusals), 'Задачи из этого чата ставят сотрудники студии') === 0, 'The bot explains the refusal.');

    // "Отправить клиенту": the company's chat is a contact; the message has the button and the file.
    $contacts = SendContacts::ofKind((new SendContacts($admin))->of([$company]), SendContacts::CHAT);
    check(count($contacts) === 1 && $contacts[0]['value'] === (string)$group->id && $contacts[0]['display'] === 'Тестовый мессенджер: Тест Задачи / tasks-chat.test',
        'The chat of the company is a contact of "Отправить клиенту".');
    $channel = $component->getChannel('chat');
    check($channel && $channel->unavailableReason($admin, $siteId) === null, 'The channel "Мессенджер" is available to an employee with a bot.');
    $sendable = new SampleSendable(77, [$company]);
    $request = new SendRequest('sample', 77, $sendable, $admin, $siteId);
    $request->channel = 'chat';
    $request->recipient = (string)$group->id;
    $request->text = 'Подготовили предложение';
    $sent = $channel->send($request);
    $buttons = json_decode((string)$sent->provider_ref, true)['buttons'] ?? [];
    check($sent->conversation->id == $group->id && mb_strpos((string)$sent->body_text, 'Подготовили предложение') !== false
        && ($buttons[0][0]['text'] ?? null) === 'Открыть предложение' && ($buttons[0][0]['url'] ?? null) === end(SampleSendable::$sends)['url']
        && $sent->subject === 'Тестовое предложение №77', 'The message has the text, the button with the personal link, the title.');
    $file = CmsCommMessageFile::find()->andWhere(['cms_comm_message_id' => (int)$sent->id])->one();
    if ($file && ($storage = CmsStorageFile::findOne((int)$file->storage_file_id))) {
        $storedFiles[] = (string)$storage->rootSrc;
    }
    check($file && $file->name === 'Предложение-77.pdf', 'The PDF goes with the message.');
    check(CmsCommLink::find()->andWhere(['cms_comm_message_id' => (int)$sent->id, 'model_code' => CmsCompany::class, 'model_id' => (int)$company->id])->exists(), 'The send is in the company\'s feed.');
    \skeeks\cms\communication\bot\BotSend::deliver((int)$sent->id);
    $parts = array_slice(FakeMessengerDriver::$sent, -2);
    check(count($parts) === 2 && mb_strpos((string)$parts[0]['text'], 'Подготовили предложение') !== false && ($parts[0]['buttons'][0][0]['text'] ?? null) === 'Открыть предложение'
        && ($parts[1]['file'] ?? null) === 'Предложение-77.pdf', 'The messenger gets the text with the button, then the file.');
    $request->recipient = (string)$stranger->conversation->id;
    $component->allowedChats = ['testbot:-9001'];
    $thrown = null;
    try {
        $channel->send($request);
    } catch (\skeeks\cms\communication\send\SendException $e) {
        $thrown = $e->errors;
    }
    check(isset($thrown['recipient']), 'A chat a stand may not write to is refused for the recipient.');
} finally {
    $transaction->rollBack();
    foreach ($storedFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

echo "OK: {$checks} checks\n";
