<?php
/**
 * Bots (stage 5.1) on an isolated SQLite memory database with a fake
 * messenger (tests/support/FakeMessengerDriver.php): the bot account and
 * mailboxes, durable receipt of updates (repeats, poll cursor), the webhook
 * subscription and a stand that must not receive, applying events
 * (conversations, identities, participants, messages, edits, removals,
 * files, the bot in the chat, history, handlers, failures) and sending
 * (stand limits, signature, parts, waiting for the messenger, refusals,
 * unknown outcome). No site schema, no network.
 *
 * Usage: php tests/bots.php [vendor/autoload.php]
 */

$autoloadCandidates = array_filter([$argv[1] ?? null, dirname(__DIR__, 3).'/autoload.php', '/app/vendor/autoload.php']);
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        require_once dirname($autoload).'/yiisoft/yii2/Yii.php';
        break;
    }
}
Yii::setAlias('@skeeks/cms/communication', dirname(__DIR__).'/src');
require_once __DIR__.'/support/FakeMessengerDriver.php';

use skeeks\cms\communication\bot\BotEventContext;
use skeeks\cms\communication\bot\BotEventHandlerInterface;
use skeeks\cms\communication\bot\BotException;
use skeeks\cms\communication\bot\BotFiles;
use skeeks\cms\communication\bot\BotIngest;
use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\bot\BotSubscriptions;
use skeeks\cms\communication\bot\BotUpdates;
use skeeks\cms\communication\bot\MessengerDriver;
use skeeks\cms\communication\CommunicationComponent;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommBotUpdate;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\models\CmsCommParticipant;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\tests\FakeMessengerDriver;
use skeeks\cms\models\CmsUser;

function botExpect($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class BotTestJobs extends \yii\base\Component
{
    public array $pushed = [];

    public function push(string $type, array $payload = [], array $options = [])
    {
        $this->pushed[] = ['type' => $type, 'payload' => $payload];
        return true;
    }

    public function types(): array
    {
        return array_column($this->pushed, 'type');
    }
}

/** Remembers what it saw; may fail on demand. */
class BotTestHandler implements BotEventHandlerInterface
{
    public static array $seen = [];
    public static bool $fail = false;

    public function handle(BotEventContext $context): void
    {
        if (self::$fail) {
            throw new \RuntimeException('Обработчик упал');
        }
        self::$seen[] = [$context->event->type, $context->message ? (int)$context->message->id : null, $context->identity ? $context->identity->external_id : null];
    }
}

new \yii\console\Application([
    'id' => 'cms-communication-bots',
    'basePath' => sys_get_temp_dir(),
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'i18n' => ['translations' => ['*' => ['class' => \yii\i18n\PhpMessageSource::class, 'basePath' => sys_get_temp_dir()]]],
        'skeeks' => ['class' => \skeeks\cms\Skeeks::class],
        'jobs' => ['class' => BotTestJobs::class],
        'communication' => [
            'class' => CommunicationComponent::class,
            'secretKey' => 'test-key-'.bin2hex(random_bytes(8)),
            'drivers' => ['testbot' => FakeMessengerDriver::class],
            'baseUrl' => 'https://site.test',
            'botHandlers' => ['test' => BotTestHandler::class],
        ],
    ],
]);

$db = Yii::$app->db;
$common = ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];
$db->createCommand()->createTable('cms_user', ['id' => 'pk', 'username' => 'string', 'first_name' => 'string', 'last_name' => 'string', 'email' => 'string', 'phone' => 'string', 'is_worker' => 'integer', 'is_active' => 'integer not null default 1', 'is_company' => 'integer default 0', 'company_name' => 'string'])->execute();
$db->createCommand()->createTable('cms_comm_connection', $common + ['cms_site_id' => 'integer not null', 'name' => 'string not null', 'driver' => 'string not null', 'driver_config' => 'text', 'secret' => 'text', 'is_active' => 'integer not null default 1'])->execute();
$db->createCommand()->createTable('cms_comm_account', $common + ['cms_site_id' => 'integer not null', 'cms_comm_connection_id' => 'integer', 'channel' => "string not null default 'email'", 'address' => 'string not null', 'name' => 'string', 'reply_to' => 'string', 'is_expose_linked' => 'integer not null default 1', 'is_active' => 'integer not null default 1', 'file_settings' => 'text', 'mailing_settings' => 'text'])->execute();
$db->createCommand()->createTable('cms_comm_conversation', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'channel' => 'string', 'thread_key' => 'string', 'subject' => 'string', 'last_message_at' => 'integer', 'assigned_user_id' => 'integer', 'assigned_at' => 'integer', 'assigned_by' => 'integer',
    'cms_comm_connection_id' => 'integer', 'chat_type' => 'string', 'external_chat_id' => 'string', 'title' => 'string', 'bot_status' => 'string', 'bot_permissions' => 'text',
    'waiting_since' => 'integer', 'closed_at' => 'integer', 'closed_by' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_message', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer', 'cms_comm_account_id' => 'integer', 'channel' => 'string', 'direction' => 'string', 'status' => 'string', 'external_id' => 'string', 'provider_ref' => 'text', 'in_reply_to' => 'string', 'references_list' => 'text', 'from_address' => 'string', 'from_name' => 'string', 'to_list' => 'text', 'cc_list' => 'text', 'subject' => 'string', 'body_text' => 'text', 'body_html' => 'text', 'attachments' => 'text', 'is_internal' => 'integer default 0', 'is_auto' => 'integer default 0', 'is_history' => 'integer default 0', 'message_at' => 'integer', 'sent_by_user_id' => 'integer', 'error' => 'text',
    'cms_comm_identity_id' => 'integer', 'reply_to_message_id' => 'integer', 'edited_at' => 'integer', 'removed_at' => 'integer'])->execute();
$db->createCommand()->createIndex('msg_unique', 'cms_comm_message', ['cms_site_id', 'channel', 'external_id'], true)->execute();
$db->createCommand()->createTable('cms_comm_message_file', ['id' => 'pk', 'created_at' => 'integer', 'cms_comm_message_id' => 'integer', 'storage_file_id' => 'integer', 'part_index' => 'integer', 'is_cached' => 'integer default 0', 'last_access_at' => 'integer', 'fetch_error' => 'text', 'name' => 'string', 'mime_type' => 'string', 'size' => 'integer', 'content_hash' => 'string', 'content_id' => 'string', 'is_inline' => 'integer default 0', 'priority' => 'integer default 100', 'external_ref' => 'string'])->execute();
$db->createCommand()->createTable('cms_comm_identity', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'channel' => 'string', 'external_id' => 'string', 'name' => 'string', 'username' => 'string', 'is_bot' => 'integer default 0', 'phone' => 'string', 'phone_verified_at' => 'integer', 'cms_user_id' => 'integer', 'link_method' => 'string', 'linked_at' => 'integer', 'linked_by' => 'integer', 'is_blocked' => 'integer default 0', 'last_seen_at' => 'integer'])->execute();
$db->createCommand()->createIndex('identity_unique', 'cms_comm_identity', ['cms_site_id', 'channel', 'external_id'], true)->execute();
$db->createCommand()->createTable('cms_comm_participant', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_conversation_id' => 'integer', 'cms_comm_identity_id' => 'integer', 'role' => "string not null default 'member'", 'joined_at' => 'integer', 'left_at' => 'integer', 'last_message_at' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_conversation_link', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_conversation_id' => 'integer', 'model_code' => 'string', 'model_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_bot_update', ['id' => 'pk', 'created_at' => 'integer', 'cms_comm_connection_id' => 'integer', 'update_key' => 'string', 'update_type' => 'string', 'payload' => 'text', 'status' => "string not null default 'new'", 'attempts' => 'integer not null default 0', 'error' => 'text', 'applied_at' => 'integer', 'cms_comm_message_id' => 'integer'])->execute();
$db->createCommand()->createIndex('update_unique', 'cms_comm_bot_update', ['cms_comm_connection_id', 'update_key'], true)->execute();
$db->createCommand()->createTable('cms_comm_sync_state', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_connection_id' => 'integer',
    'folder' => 'string', 'folder_role' => 'string', 'delimiter' => 'string', 'uidvalidity' => 'integer', 'uidnext' => 'integer',
    'last_uid' => 'integer not null default 0', 'last_success_at' => 'integer', 'last_error' => 'text', 'last_error_at' => 'integer'])->execute();
$db->createCommand()->batchInsert('cms_user', ['id', 'username', 'first_name', 'last_name', 'is_worker'], [[1, 'semenov', 'Александр', 'Семенов', 1]])->execute();
$component = Yii::$app->communication;
$jobs = Yii::$app->jobs;
FakeMessengerDriver::reset();

// Drivers and channels: a messenger driver gives a bot channel, a bot account is no mailbox.
botExpect(Bots::drivers() === ['testbot' => FakeMessengerDriver::class] && Bots::channels() === ['testbot' => 'Тестовый мессенджер'], 'A messenger driver must be found.');
botExpect(array_keys(CmsCommAccount::channels()) === ['email', 'testbot'], 'Accounts accept the channels of installed messengers.');

// A bot connection: the token and the webhook secret are encrypted secrets.
$driver = $component->createDriver('testbot');
$driver->setAttributes(['token' => '123:secret-token', 'botId' => '999', 'botUsername' => 'test_bot', 'signName' => 'SkeekS'], false);
$connection = new CmsCommConnection(['cms_site_id' => 1, 'name' => 'Тестовый бот', 'driver' => 'testbot', 'is_active' => 1]);
$connection->applyDriver($driver);
$connection->save(false);
$cid = (int)$connection->id;
botExpect(strpos((string)$connection->driver_config, 'secret-token') === false && strpos((string)$connection->secret, 'secret-token') === false
    && strpos((string)$connection->driver_config, 'test_bot') !== false, 'The token is never stored in the clear.');
botExpect(Bots::isBot($connection) && Bots::driver($connection)->token === '123:secret-token', 'The driver of a bot gets its token back.');
$account = Bots::account($connection);
botExpect($account->channel === 'testbot' && $account->address === '@test_bot' && (int)$account->cms_comm_connection_id === (int)$connection->id
    && (int)Bots::account($connection)->id === (int)$account->id, 'A bot has one account of its channel named after the bot.');
$mailbox = new CmsCommAccount(['cms_site_id' => 1, 'channel' => 'email', 'address' => 'info@site.test']);
$mailbox->save(false);
botExpect(CmsCommAccount::findMailboxes()->select('id')->column() === [(string)$mailbox->id] || CmsCommAccount::findMailboxes()->select('id')->column() === [(int)$mailbox->id],
    'Mail must not see the bot as a mailbox.');

// The webhook: a stand never touches it; a site subscribes with a stored secret.
$component->botReceive = false;
botExpect(BotSubscriptions::ensure($connection)['state'] === BotSubscriptions::RECEIVE_OFF && FakeMessengerDriver::$webhook['url'] === null,
    'A site that must not receive never subscribes the bot.');
$component->botReceive = true;
$state = BotSubscriptions::ensure($connection);
$connection->refresh();
$stored = Bots::driver($connection);
botExpect($state['state'] === BotSubscriptions::SUBSCRIBED && FakeMessengerDriver::$webhook['url'] === 'https://site.test/~comm-bot/'.(int)$connection->id
    && FakeMessengerDriver::$webhook['secret'] === $stored->webhookSecret && strlen((string)$stored->webhookSecret) >= 32
    && strpos((string)$connection->driver_config, (string)$stored->webhookSecret) === false, 'The bot is subscribed to its address with a secret stored encrypted.');
botExpect(BotSubscriptions::ensure($connection)['state'] === BotSubscriptions::OK, 'A correct subscription is left as it is.');
FakeMessengerDriver::$webhook['url'] = 'https://elsewhere.test/hook';
botExpect(BotSubscriptions::ensure($connection)['state'] === BotSubscriptions::SUBSCRIBED && FakeMessengerDriver::$webhook['url'] === Bots::webhookUrl($connection),
    'A lost or foreign subscription is restored.');
// A relay (a messenger that cannot reach the site): the same path on the relay, then back to the site.
$relayed = Bots::driver($connection);
$relayed->webhookBaseUrl = 'https://relay.test:8443/';
botExpect($relayed->validate(['webhookBaseUrl']), 'A relay address is a valid setting.');
$connection->applyDriver($relayed);
$connection->save(false);
botExpect(BotSubscriptions::ensure($connection)['state'] === BotSubscriptions::SUBSCRIBED
    && FakeMessengerDriver::$webhook['url'] === 'https://relay.test:8443/~comm-bot/'.(int)$connection->id, 'The webhook goes to the relay with the same path.');
botExpect(BotSubscriptions::ensure($connection)['state'] === BotSubscriptions::OK, 'The hourly check keeps the relay.');
$relayed->webhookBaseUrl = 'http://relay.test';
botExpect(!$relayed->validate(['webhookBaseUrl']), 'A relay must be HTTPS.');
$relayed->webhookBaseUrl = null;
$connection->applyDriver($relayed);
$connection->save(false);
botExpect(BotSubscriptions::ensure($connection)['state'] === BotSubscriptions::SUBSCRIBED
    && FakeMessengerDriver::$webhook['url'] === 'https://site.test/~comm-bot/'.(int)$connection->id, 'An empty relay is the site again.');

// Durable receipt: one row per update key, repeats are not written, unknown updates are ignored.
$group = ['id' => '-100', 'type' => 'group', 'title' => 'Компания / site.ru'];
$anna = ['id' => '7', 'name' => 'Анна Клиентова', 'username' => 'anna'];
$ivan = ['id' => '8', 'name' => 'Иван'];
$updates = [
    ['id' => 1, 'type' => 'message', 'chat' => $group, 'from' => $anna, 'message_id' => '10', 'text' => "Поправьте баннер\r\nна главной", 'date' => 1800000000],
    ['id' => 2, 'type' => 'message', 'chat' => $group, 'from' => $ivan, 'message_id' => '11', 'text' => 'И цены', 'date' => 1800000060, 'reply_to' => '10',
        'files' => [['ref' => 'file-1', 'name' => 'прайс.pdf', 'size' => 10], ['ref' => 'file-2', 'name' => 'фото.jpg', 'size' => 99999999]]],
    ['no-id' => true],
];
$jobs->pushed = [];
$result = BotUpdates::store($connection, $stored, $updates);
botExpect($result === ['stored' => 2, 'repeated' => 0, 'ignored' => 1] && $jobs->types() === ['comm.bot.apply'], 'Updates are stored and applying is woken.');
$jobs->pushed = [];
botExpect(BotUpdates::store($connection, $stored, [$updates[0]]) === ['stored' => 0, 'repeated' => 1, 'ignored' => 0] && !$jobs->pushed,
    'A repeated delivery is not written and wakes nothing.');
botExpect((int)CmsCommBotUpdate::find()->count() === 2, 'Two rows in the journal.');

// The poll cursor goes with its updates.
botExpect(BotUpdates::cursor($connection) === null, 'No cursor before the first poll.');
FakeMessengerDriver::$pollQueue = [['id' => 3, 'type' => 'title', 'chat' => $group, 'title' => 'Компания / site.ru (работа)', 'date' => 1800000100]];
$poll = $stored->poll(BotUpdates::cursor($connection), 0);
BotUpdates::store($connection, $stored, $poll->updates, $poll->cursor);
botExpect(BotUpdates::cursor($connection) === '4' && (int)CmsCommBotUpdate::find()->count() === 3, 'The cursor is stored together with the polled updates.');

// Applying: conversation, identities, participants, messages, reply, files.
$ingest = new BotIngest($connection, $stored);
$jobs->pushed = [];
foreach (CmsCommBotUpdate::find()->orderBy(['id' => SORT_ASC])->all() as $row) {
    botExpect($ingest->apply($row) === CmsCommBotUpdate::STATUS_APPLIED, 'Every stored update must apply.');
}
$conversation = CmsCommConversation::find()->andWhere(['thread_key' => (int)$connection->id.':-100'])->one();
botExpect($conversation && $conversation->channel === 'testbot' && $conversation->chat_type === 'group' && $conversation->external_chat_id === '-100'
    && $conversation->title === 'Компания / site.ru (работа)' && (int)$conversation->cms_comm_connection_id === (int)$connection->id
    && (int)$conversation->last_message_at === 1800000060 && $conversation->isChat(), 'A group chat is a conversation of its bot.');
$first = CmsCommMessage::find()->andWhere(['external_id' => $cid.':-100:10'])->one();
$second = CmsCommMessage::find()->andWhere(['external_id' => $cid.':-100:11'])->one();
$annaIdentity = CmsCommIdentity::find()->andWhere(['external_id' => '7'])->one();
botExpect($first && $first->direction === 'in' && $first->status === 'received' && $first->body_text === "Поправьте баннер\nна главной"
    && $first->from_address === '@anna' && $first->from_name === 'Анна Клиентова' && (int)$first->cms_comm_identity_id === (int)$annaIdentity->id
    && (int)$first->cms_comm_account_id === (int)$account->id && (int)$first->is_history === 0, 'A group message is an incoming message of the bot account.');
botExpect($second && (int)$second->reply_to_message_id === (int)$first->id && (int)CmsCommMessageFile::find()->andWhere(['cms_comm_message_id' => $second->id])->count() === 2
    && CmsCommMessageFile::find()->andWhere(['cms_comm_message_id' => $second->id, 'part_index' => 0])->one()->external_ref === 'file-1', 'A reply knows its message, files keep their references.');
botExpect(array_values(array_filter($jobs->pushed, static fn($j) => $j['type'] === 'comm.bot.fetch-file')) === [['type' => 'comm.bot.fetch-file', 'payload' => ['messageId' => (int)$second->id]]]
    && in_array(['type' => 'comm.bot.chat-info', 'payload' => ['conversationId' => (int)$conversation->id]], $jobs->pushed, true),
    'Files are fetched right after the message; a new chat gets its picture by a job.');
botExpect((int)CmsCommParticipant::find()->andWhere(['cms_comm_conversation_id' => $conversation->id])->count() === 2
    && (int)CmsCommParticipant::find()->andWhere(['cms_comm_identity_id' => $annaIdentity->id])->one()->last_message_at === 1800000000, 'Writers of a group are its participants.');
botExpect(count(BotTestHandler::$seen) === 3 && BotTestHandler::$seen[0] === ['message', (int)$first->id, '7'], 'Handlers get every live event with its records.');

// Files: the messenger refuses one, the other is too large for the CRM.
$files = BotFiles::fetch((int)$second->id, $stored);
botExpect($files === ['stored' => 0, 'failed' => 2], 'Unavailable and too large files are not stored.');
botExpect(CmsCommMessageFile::find()->andWhere(['external_ref' => 'file-1'])->one()->fetch_error === 'Файл недоступен в мессенджере.'
    && strpos((string)CmsCommMessageFile::find()->andWhere(['external_ref' => 'file-2'])->one()->fetch_error, '25 МБ') !== false, 'The reason stays with the file.');
botExpect(BotFiles::fetch((int)$second->id, $stored) === ['stored' => 0, 'failed' => 0], 'A file with a reason is not asked again.');

// Edits, removals, an edit of a message never seen, members, the bot in the chat.
$apply = static function (array $update) use ($connection, $stored, $ingest) {
    BotUpdates::store($connection, $stored, [$update]);
    return $ingest->apply(CmsCommBotUpdate::find()->andWhere(['update_key' => 'u'.$update['id']])->one());
};
$apply(['id' => 4, 'type' => 'edited', 'chat' => $group, 'from' => $anna, 'message_id' => '10', 'text' => 'Поправьте баннер', 'date' => 1800000200]);
$first->refresh();
botExpect($first->body_text === 'Поправьте баннер' && (int)$first->edited_at === 1800000200, 'An edit changes the text and is marked.');
$apply(['id' => 5, 'type' => 'edited', 'chat' => $group, 'from' => $ivan, 'message_id' => '12', 'text' => 'Сообщение до бота', 'date' => 1800000300]);
botExpect(($late = CmsCommMessage::find()->andWhere(['external_id' => $cid.':-100:12'])->one()) && (int)$late->edited_at === 1800000300, 'An edit of an unknown message creates it.');
$apply(['id' => 6, 'type' => 'removed', 'chat' => $group, 'message_id' => '11', 'date' => 1800000400]);
$second->refresh();
botExpect((int)$second->removed_at === 1800000400 && $second->body_text === 'И цены', 'A removal is marked, the text is kept.');
$apply(['id' => 7, 'type' => 'left', 'chat' => $group, 'members' => [$ivan], 'date' => 1800000500]);
$ivanIdentity = CmsCommIdentity::find()->andWhere(['external_id' => '8'])->one();
botExpect((int)CmsCommParticipant::find()->andWhere(['cms_comm_identity_id' => $ivanIdentity->id])->one()->left_at === 1800000500, 'A member who left is marked.');
$apply(['id' => 8, 'type' => 'joined', 'chat' => $group, 'members' => [['id' => '9', 'name' => 'Новый', 'role' => 'admin']], 'date' => 1800000600]);
botExpect(CmsCommParticipant::find()->andWhere(['cms_comm_identity_id' => CmsCommIdentity::find()->select('id')->andWhere(['external_id' => '9'])])->one()->role === 'admin',
    'A new member comes with the role.');
$apply(['id' => 9, 'type' => 'bot', 'chat' => $group, 'status' => 'admin', 'rights' => ['read_all_messages', 'write'], 'date' => 1800000700]);
$conversation->refresh();
botExpect($conversation->bot_status === 'admin' && json_decode((string)$conversation->bot_permissions, true) === ['read_all_messages', 'write'], 'The rights of the bot in the chat are kept.');
$apply(['id' => 10, 'type' => 'left', 'chat' => $group, 'members' => [['id' => '999', 'name' => 'Бот', 'bot' => true]], 'date' => 1800000800]);
$conversation->refresh();
botExpect($conversation->bot_status === 'left', 'The bot removed from a chat is marked, not a participant.');

// A private dialog: named after the person; a shared contact and a forward are written out.
$private = ['id' => '7', 'type' => 'private'];
$apply(['id' => 11, 'type' => 'message', 'chat' => $private, 'from' => $anna, 'message_id' => '1', 'text' => 'Мой номер', 'date' => 1800000900,
    'contact' => ['phone' => '79991234567', 'name' => 'Анна']]);
$apply(['id' => 12, 'type' => 'message', 'chat' => $private, 'from' => $anna, 'message_id' => '2', 'text' => 'Смотрите', 'forwarded' => 'Пётр', 'date' => 1800001000]);
$dialog = CmsCommConversation::find()->andWhere(['thread_key' => (int)$connection->id.':7'])->one();
botExpect($dialog && $dialog->chat_type === 'private' && $dialog->title === 'Анна Клиентова'
    && CmsCommMessage::find()->andWhere(['external_id' => $cid.':7:1'])->one()->body_text === "Мой номер\nКонтакт: +7 999 123-45-67, Анна"
    && CmsCommMessage::find()->andWhere(['external_id' => $cid.':7:2'])->one()->body_text === "Переслано от Пётр:\nСмотрите", 'A dialog is named after the person; contact and forward are readable.');
botExpect(!CmsCommParticipant::find()->andWhere(['cms_comm_conversation_id' => $dialog->id])->exists(), 'A dialog has no participants list.');
$apply(['id' => 13, 'type' => 'stop', 'chat' => $private, 'from' => $anna, 'date' => 1800001100]);
botExpect((int)CmsCommIdentity::findOne($annaIdentity->id)->is_blocked === 1, 'A person who stopped the bot is marked.');
$apply(['id' => 14, 'type' => 'start', 'chat' => $private, 'from' => $anna, 'payload' => 'abc', 'date' => 1800001200]);
botExpect((int)CmsCommIdentity::findOne($annaIdentity->id)->is_blocked === 0 && end(BotTestHandler::$seen)[0] === 'started', 'Starting the bot again unblocks; handlers see the start.');

// Failures: an update that breaks is tried again, then stays failed; a handler failure rolls the message back.
BotUpdates::store($connection, $stored, [['id' => 15, 'type' => 'message', 'chat' => $group, 'from' => $anna, 'message_id' => '20', 'fail' => true]]);
$broken = CmsCommBotUpdate::find()->andWhere(['update_key' => 'u15'])->one();
for ($i = 1; $i < CmsCommBotUpdate::MAX_ATTEMPTS; $i++) {
    botExpect($ingest->apply($broken) === CmsCommBotUpdate::STATUS_NEW && (int)$broken->attempts === $i, 'A failing update waits for another try.');
}
botExpect($ingest->apply($broken) === CmsCommBotUpdate::STATUS_FAILED && strpos((string)$broken->error, 'Тестовый сбой') !== false, 'After the last try the update is failed with the reason.');
BotTestHandler::$fail = true;
BotUpdates::store($connection, $stored, [['id' => 16, 'type' => 'message', 'chat' => $group, 'from' => $anna, 'message_id' => '21', 'text' => 'x']]);
botExpect($ingest->apply(CmsCommBotUpdate::find()->andWhere(['update_key' => 'u16'])->one()) === CmsCommBotUpdate::STATUS_NEW
    && !CmsCommMessage::find()->andWhere(['external_id' => $cid.':-100:21'])->exists(), 'A failing handler rolls the whole event back.');
BotTestHandler::$fail = false;
botExpect($ingest->apply(CmsCommBotUpdate::find()->andWhere(['update_key' => 'u16'])->one()) === CmsCommBotUpdate::STATUS_APPLIED
    && CmsCommMessage::find()->andWhere(['external_id' => $cid.':-100:21'])->exists(), 'The next try applies it.');
botExpect($ingest->apply(CmsCommBotUpdate::find()->andWhere(['update_key' => 'u1'])->one()) === CmsCommBotUpdate::STATUS_APPLIED
    && (int)CmsCommMessage::find()->andWhere(['external_id' => $cid.':-100:10'])->count() === 1, 'Applying an update again doubles nothing.');

// History: known messages are left alone, new ones are read history without handlers; the bot's own messages are outgoing.
$seenBefore = count(BotTestHandler::$seen);
$historyEvents = [];
foreach ([
    ['id' => 0, 'type' => 'message', 'chat' => $group, 'from' => $anna, 'message_id' => '10', 'text' => 'старый текст', 'date' => 1790000000],
    ['id' => 0, 'type' => 'message', 'chat' => $group, 'from' => ['id' => '999', 'name' => 'Бот', 'bot' => true], 'message_id' => '5', 'text' => 'Здравствуйте!', 'date' => 1790000001],
    ['id' => 0, 'type' => 'message', 'chat' => $group, 'from' => ['id' => '6', 'name' => 'Старый участник'], 'message_id' => '6', 'text' => 'Привет', 'date' => 1790000002],
] as $update) {
    $historyEvents = array_merge($historyEvents, $stored->parseUpdate($update));
}
botExpect($ingest->applyHistory($historyEvents) === 2 && count(BotTestHandler::$seen) === $seenBefore, 'Only new history messages are added, without handlers.');
$own = CmsCommMessage::find()->andWhere(['external_id' => $cid.':-100:5'])->one();
botExpect($own->direction === 'out' && $own->status === 'sent' && (int)$own->is_history === 1 && CmsCommMessage::find()->andWhere(['external_id' => $cid.':-100:10'])->one()->body_text === 'Поправьте баннер',
    'The bot\'s own history message is outgoing; a known message keeps its text.');
botExpect(CmsCommIdentity::find()->andWhere(['external_id' => '7'])->one()->name === 'Анна Клиентова', 'Old history does not rename a person.');

// Sending: a stand writes only to listed chats; signature; parts within the limit; reply.
$user = CmsUser::findOne(1);
$conversation->bot_status = 'admin';
$conversation->save(false);
$component->allowedRecipients = ['@skeeks.com'];
try {
    BotSend::queue($conversation, 'Сделаем', ['user' => $user]);
    botExpect(false, 'A stand must refuse a chat outside allowedChats.');
} catch (SendException $e) {
    botExpect(isset($e->errors['conversation']), 'The refusal points at the chat.');
}
$component->allowedChats = ['testbot:-100'];
try {
    BotSend::queue($conversation, "  \n ", ['user' => $user]);
    botExpect(false, 'An empty message must be refused.');
} catch (SendException $e) {
}
$mail = new CmsCommConversation(['cms_site_id' => 1, 'channel' => 'email', 'thread_key' => '<x@y>']);
$mail->save(false);
try {
    BotSend::queue($mail, 'Текст');
    botExpect(false, 'A letter thread is not a chat.');
} catch (SendException $e) {
}
$jobs->pushed = [];
$long = 'Добрый день! Баннер поправим сегодня, цены обновим завтра утром, пришлём ссылку.';
$out = BotSend::queue($conversation, $long, ['user' => $user, 'replyTo' => $first]);
botExpect($out->status === 'queued' && $out->direction === 'out' && (int)$out->sent_by_user_id === 1 && (int)$out->reply_to_message_id === (int)$first->id
    && strpos((string)$out->body_text, "Александр Семенов (SkeekS):\nДобрый день!") === 0 && (int)$out->cms_comm_account_id === (int)$account->id,
    'A reply is queued with the employee\'s name first.');
botExpect($jobs->pushed === [['type' => 'comm.bot.send', 'payload' => ['messageId' => (int)$out->id, 'connectionId' => (int)$connection->id]]], 'The send job goes with the message.');

// The second part hits the messenger's limit: the first part is known as sent.
$parts = BotSend::split((string)$out->body_text, 50);
botExpect(count($parts) === 3 && max(array_map('mb_strlen', $parts)) <= 50 && implode(' ', $parts) === preg_replace('/\s+/', ' ', (string)$out->body_text),
    'A long text is cut at word breaks within the limit.');
$sendDriver = Bots::driver($connection);
FakeMessengerDriver::$sent = [];
FakeMessengerDriver::$failNext = [];
class BotTestLimitedDriver extends FakeMessengerDriver
{
    public static int $calls = 0;

    public function send(\skeeks\cms\communication\bot\BotOutgoing $message): string
    {
        self::$calls++;
        if (self::$calls === 2) {
            throw BotException::rateLimited(3);
        }
        return parent::send($message);
    }
}
$limited = new BotTestLimitedDriver();
$limited->setAttributes($sendDriver->getAttributes(), false);
[$status, $wait] = BotSend::deliver((int)$out->id, $limited);
$out->refresh();
botExpect($status === 'wait' && $wait === 3 && $out->status === 'sending' && count(FakeMessengerDriver::$sent) === 1
    && FakeMessengerDriver::$sent[0]['reply'] === '10', 'A limit of the messenger waits; the first part went as a reply.');
botExpect(BotSend::deliver((int)$out->id, Bots::driver($connection))[0] === 'sent', 'After the wait the rest goes.');
$out->refresh();
botExpect(count(FakeMessengerDriver::$sent) === 3 && $out->status === 'sent' && $out->external_id === $cid.':-100:'.FakeMessengerDriver::$sent[0]['id']
    && FakeMessengerDriver::$sent[1]['reply'] === null && json_decode((string)$out->provider_ref, true)['sent'] === array_column(FakeMessengerDriver::$sent, 'id'),
    'All parts are sent once; the message takes the id of its first part.');
botExpect(BotSend::deliver((int)$out->id, Bots::driver($connection)) === [null, null] && count(FakeMessengerDriver::$sent) === 3, 'A sent message is never sent again.');

// A refusal fails the message; a blocked dialog marks the person; an unknown outcome is not repeated.
$component->allowedChats = ['testbot:-100', 'testbot:7'];
$toAnna = BotSend::queue($dialog, 'Ответ', ['user' => $user, 'sign' => false]);
botExpect($toAnna->body_text === 'Ответ', 'A message may go without the signature.');
FakeMessengerDriver::$failNext = [BotException::blocked('Пользователь заблокировал бота.')];
[$status, $error] = BotSend::deliver((int)$toAnna->id, Bots::driver($connection));
botExpect($status === 'failed' && $error === 'Пользователь заблокировал бота.' && (int)CmsCommIdentity::findOne($annaIdentity->id)->is_blocked === 1,
    'A blocked bot fails the message and marks the person.');
$unknown = BotSend::queue($conversation, 'Ещё', ['user' => $user]);
FakeMessengerDriver::$failNext = [new \RuntimeException('timeout')];
[$status] = BotSend::deliver((int)$unknown->id, Bots::driver($connection));
botExpect($status === 'unknown' && BotSend::deliver((int)$unknown->id, Bots::driver($connection)) === [null, null], 'An unknown outcome stays unknown and is not repeated.');
$component->allowedChats = ['testbot:7'];
$refused = BotSend::queue($dialog, 'Позже', []);
$component->allowedChats = [];
$component->allowedRecipients = ['@skeeks.com'];
botExpect(BotSend::deliver((int)$refused->id, Bots::driver($connection))[0] === 'failed', 'The job checks the chat again.');

// The poll mode unsubscribes the webhook.
$pollDriver = Bots::driver($connection);
$pollDriver->mode = MessengerDriver::MODE_POLL;
$connection->applyDriver($pollDriver);
$connection->save(false);
botExpect(BotSubscriptions::ensure($connection)['state'] === BotSubscriptions::UNSUBSCRIBED && FakeMessengerDriver::$webhook['url'] === null, 'The poll mode removes the webhook.');

// Two bots of one messenger side by side: own accounts, secrets, journals, cursors and chats; one person.
$driverB = $component->createDriver('testbot');
$driverB->setAttributes(['token' => '456:other-token', 'botId' => '888', 'botUsername' => 'sales_bot'], false);
$connectionB = new CmsCommConnection(['cms_site_id' => 1, 'name' => 'Бот продаж', 'driver' => 'testbot', 'is_active' => 1]);
$connectionB->applyDriver($driverB);
$connectionB->save(false);
$accountB = Bots::account($connectionB);
botExpect((int)$accountB->id !== (int)$account->id && $accountB->address === '@sales_bot' && Bots::driver($connectionB)->token === '456:other-token'
    && Bots::driver($connection)->token === '123:secret-token', 'Each bot has its own account and token.');
$storedB = Bots::driver($connectionB);
$storedB->mode = MessengerDriver::MODE_WEBHOOK;
botExpect(Bots::webhookUrl($connectionB) === 'https://site.test/~comm-bot/'.(int)$connectionB->id && Bots::webhookUrl($connectionB) !== Bots::webhookUrl($connection),
    'Each bot has its own webhook address.');
BotSubscriptions::ensure($connectionB);
$connectionB->refresh();
$storedB = Bots::driver($connectionB);
botExpect((string)$storedB->webhookSecret !== '' && $storedB->webhookSecret !== Bots::driver($connection)->webhookSecret, 'Each bot has its own webhook secret.');
// The same update number of another bot is another update.
$countA = (int)CmsCommBotUpdate::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id])->count();
$resultB = BotUpdates::store($connectionB, $storedB, [['id' => 1, 'type' => 'message', 'chat' => $private, 'from' => $anna, 'message_id' => '1', 'text' => 'Хочу сайт', 'date' => 1800002000]]);
botExpect($resultB['stored'] === 1 && (int)CmsCommBotUpdate::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id])->count() === $countA,
    'Update keys are unique per bot.');
BotUpdates::store($connectionB, $storedB, [], '77');
botExpect(BotUpdates::cursor($connectionB) === '77' && BotUpdates::cursor($connection) === '4', 'Poll cursors are per bot.');
$ingestB = new BotIngest($connectionB, $storedB);
botExpect($ingestB->apply(CmsCommBotUpdate::find()->andWhere(['cms_comm_connection_id' => (int)$connectionB->id, 'update_key' => 'u1'])->one()) === CmsCommBotUpdate::STATUS_APPLIED,
    'The second bot applies its update.');
$dialogB = CmsCommConversation::find()->andWhere(['thread_key' => (int)$connectionB->id.':7'])->one();
$messageB = CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => $dialogB ? (int)$dialogB->id : 0])->one();
botExpect($dialogB && (int)$dialogB->id !== (int)$dialog->id && (int)$dialogB->cms_comm_connection_id === (int)$connectionB->id
    && $messageB && (int)$messageB->cms_comm_account_id === (int)$accountB->id && (int)$messageB->cms_comm_identity_id === (int)$annaIdentity->id
    && (int)CmsCommIdentity::find()->andWhere(['channel' => 'testbot', 'external_id' => '7'])->count() === 1,
    'One person writing to two bots: one identity, two conversations, each with its own bot.');
// Telegram numbers messages per bot and a private chat id is the person's id: the bot is part of the message key.
botExpect($messageB->external_id === (int)$connectionB->id.':7:1' && $messageB->body_text === 'Хочу сайт'
    && CmsCommMessage::find()->andWhere(['external_id' => $cid.':7:1'])->one()->body_text === "Мой номер\nКонтакт: +7 999 123-45-67, Анна",
    'Equal chat and message ids of two bots are two messages.');

echo "bots: OK\n";
