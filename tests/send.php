<?php
/**
 * "Отправить клиенту" on an isolated SQLite memory database with the test
 * sample (tests/support/SampleSendable.php) instead of a real package:
 * registries of sendables and channels, phone limits of a stand, phone
 * formats, the personal link inside a letter, an SMS send (message,
 * conversation of the phone, job, the same context in createSendLink() and
 * afterSend(), rollback when the link fails) and its delivery to a fake SMS
 * provider; link checks of mail services that open a personal link
 * (LinkChecks). No site schema, no network, no real SMS.
 *
 * Usage: php tests/send.php [vendor/autoload.php]
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
require_once __DIR__.'/support/SampleSendable.php';

use skeeks\cms\communication\CommunicationComponent;
use skeeks\cms\communication\jobs\SmsSendJobHandler;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\EmailSendChannel;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\send\SmsSendChannel;
use skeeks\cms\communication\services\LinkChecks;
use skeeks\cms\communication\services\SendContacts;
use skeeks\cms\communication\services\SendService;
use skeeks\cms\communication\tests\SampleSendable;
use skeeks\cms\communication\tests\SampleSendableSource;
use skeeks\cms\models\CmsSmsMessage;
use skeeks\cms\models\CmsUser;

function sendExpect($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Jobs are only recorded: nothing runs, nothing leaves. */
class SendTestJobs extends \yii\base\Component
{
    public array $pushed = [];

    public function push(string $type, array $payload = [], array $options = [])
    {
        $this->pushed[] = ['type' => $type, 'payload' => $payload, 'options' => $options];
        return true;
    }
}

/** The part of the CMS component the SMS providers read. */
class SendTestCms extends \yii\base\Component
{
    public $smsHandlers = [];
}

/** A provider that remembers what it was asked to send. */
class SendTestSmsHandler extends \skeeks\cms\sms\SmsHandler
{
    public static array $sent = [];
    public static bool $fail = false;

    public function send($phone, $text, $sender = null)
    {
        if (self::$fail) {
            throw new \RuntimeException('Провайдер отказал');
        }
        self::$sent[] = [$phone, $text];
        return 'provider-'.count(self::$sent);
    }

    public function status($message_id)
    {
        return 'delivered';
    }
}

new \yii\console\Application([
    'id' => 'cms-communication-send',
    'basePath' => sys_get_temp_dir(),
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'skeeks' => ['class' => \skeeks\cms\Skeeks::class],
        'cms' => ['class' => SendTestCms::class, 'smsHandlers' => ['test' => ['class' => SendTestSmsHandler::class]]],
        'jobs' => ['class' => SendTestJobs::class],
        'communication' => [
            'class' => CommunicationComponent::class,
            'allowedRecipients' => ['@skeeks.com'],
            'channels' => ['email' => EmailSendChannel::class, 'sms' => SmsSendChannel::class],
            'sendables' => ['sample' => ['class' => SampleSendableSource::class, 'finder' => static fn(int $id) => $id === 404 ? null : new SampleSendable($id)]],
        ],
    ],
]);

$db = Yii::$app->db;
$db->createCommand()->createTable('cms_user', ['id' => 'pk', 'username' => 'string', 'first_name' => 'string', 'last_name' => 'string', 'email' => 'string', 'phone' => 'string', 'is_worker' => 'integer', 'is_active' => 'integer not null default 1'])->execute();
$db->createCommand()->createTable('cms_comm_conversation', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'channel' => 'string', 'thread_key' => 'string', 'subject' => 'string', 'last_message_at' => 'integer', 'assigned_user_id' => 'integer', 'assigned_at' => 'integer', 'assigned_by' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_message', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer', 'cms_comm_account_id' => 'integer', 'channel' => 'string', 'direction' => 'string', 'status' => 'string', 'external_id' => 'string', 'provider_ref' => 'string', 'in_reply_to' => 'string', 'references_list' => 'text', 'from_address' => 'string', 'from_name' => 'string', 'to_list' => 'text', 'cc_list' => 'text', 'subject' => 'string', 'body_text' => 'text', 'body_html' => 'text', 'attachments' => 'text', 'is_internal' => 'integer default 0', 'is_auto' => 'integer default 0', 'is_history' => 'integer default 0', 'message_at' => 'integer', 'sent_by_user_id' => 'integer', 'error' => 'text'])->execute();
$db->createCommand()->createTable('cms_comm_link', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_message_id' => 'integer', 'model_code' => 'string', 'model_id' => 'integer', 'link_type' => 'string', 'cms_log_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_link_exclusion', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_conversation_id' => 'integer', 'model_code' => 'string', 'model_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_sms_provider', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'name' => 'string', 'priority' => 'integer default 100', 'is_main' => 'integer', 'component' => 'string', 'component_config' => 'text'])->execute();
$db->createCommand()->createTable('cms_sms_message', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer', 'user_ip' => 'string', 'cms_site_id' => 'integer', 'cms_sms_provider_id' => 'integer', 'phone' => 'string', 'message' => 'text', 'status' => 'string', 'error_message' => 'text', 'provider_status' => 'string', 'provider_message_id' => 'string'])->execute();
$db->createCommand()->insert('cms_user', ['id' => 1, 'username' => 'semenov', 'is_worker' => 1])->execute();
$user = CmsUser::findOne(1);
$component = Yii::$app->communication;

// Registries: known types and channels only; a wrong class is a configuration error.
sendExpect($component->getSendableSource('sample') instanceof SampleSendableSource, 'A registered sendable source must be found by its type.');
sendExpect($component->getSendableSource('nope') === null && $component->getSendableSource('Sample') === null, 'Unknown types must give nothing.');
sendExpect(array_keys($component->getChannels()) === ['email', 'sms'], 'Mail and SMS must be the channels of the package.');
$bad = new CommunicationComponent(['sendables' => ['bad' => \stdClass::class]]);
try {
    $bad->getSendableSource('bad');
    sendExpect(false, 'A source that does not implement the contract must fail.');
} catch (\yii\base\InvalidConfigException $e) {
}
sendExpect(SendService::parseRef('sample:12') === ['sample', 12] && SendService::parseRef('sample:x') === null && SendService::parseRef('1x:2') === null,
    'References are "type:id".');

// Phones of a stand: a stand that limits letters sends no SMS until its own numbers are listed.
sendExpect(!$component->isPhoneAllowed('+7 900 000-00-01'), 'A stand with allowedRecipients and no allowedPhones must refuse every phone.');
$component->allowedPhones = ['8 (900) 000-00-01'];
sendExpect($component->isPhoneAllowed('+7 900 000-00-01') && $component->isPhoneAllowed('79000000001'), 'A listed phone must pass in any notation.');
sendExpect(!$component->isPhoneAllowed('+7 900 000-00-02'), 'Other phones must be refused.');
$open = new CommunicationComponent();
sendExpect($open->isPhoneAllowed('+7 900 000-00-02') && !$open->isPhoneAllowed(''), 'Without limits every phone passes, an empty one never.');

// Phone formats of contacts.
sendExpect(SendContacts::normalizePhone('8 (999) 123-45-67') === '79991234567' && SendContacts::normalizePhone('9991234567') === '79991234567'
    && SendContacts::normalizePhone('+44 20 7946 0958') === '442079460958' && SendContacts::normalizePhone('123') === null, 'Phones must become digits with the country code.');
sendExpect(SendContacts::formatPhone('79991234567') === '+7 999 123-45-67', 'Russian phones are shown as +7 999 123-45-67.');

// The personal link in a letter: the placeholder becomes a button, the plain text gets the URL.
$placeholder = SendService::placeholder('sample', 7);
[$html, $text] = SendService::applyLink('<div>Добрый день</div><div><a href="'.$placeholder.'">Смотреть</a></div><div>-- подпись</div>',
    "Добрый день\nСмотреть (".$placeholder.")\n-- подпись", 'sample', 7, 'https://site.test/o/abc', 'Открыть');
sendExpect(strpos($html, 'href="https://site.test/o/abc"') !== false && strpos($html, '>Смотреть</a>') !== false && strpos($html, '<table') !== false
    && strpos($html, 'sx-send.invalid') === false && strpos($html, '-- подпись') > strpos($html, '<table'),
    'The placeholder must become a button with the personal URL and the employee\'s label, where the employee left it.');
sendExpect(strpos($text, 'https://site.test/o/abc') !== false && strpos($text, 'sx-send.invalid') === false, 'The plain text must carry the personal URL.');
[$html, $text] = SendService::applyLink('<div>Без ссылки</div>', 'Без ссылки', 'sample', 7, 'https://site.test/o/abc', 'Открыть');
sendExpect(substr_count($html, 'https://site.test/o/abc') === 1 && strpos($text, 'Открыть: https://site.test/o/abc') !== false,
    'A letter whose link was removed must still get the button at the end.');

// SMS: refused for a phone the stand does not allow; nothing is created.
$service = new SendService($user, 1);
$db->createCommand()->insert('cms_sms_provider', ['id' => 3, 'cms_site_id' => 1, 'name' => 'Тестовый', 'is_main' => 1, 'component' => 'test', 'component_config' => serialize([])])->execute();
$request = $service->request('sample:12', ['channel' => 'sms', 'recipient' => '+7 900 000-00-02', 'text' => 'Ваше предложение готово']);
try {
    $service->send($request);
    sendExpect(false, 'An SMS to a phone outside allowedPhones must be refused.');
} catch (SendException $e) {
    sendExpect(isset($e->errors['recipient']), 'The refusal must point at the recipient.');
}
sendExpect((int)CmsCommMessage::find()->count() === 0 && !SampleSendable::$sends && !Yii::$app->jobs->pushed, 'A refused SMS must create nothing.');
try {
    $service->request('sample:404');
    sendExpect(false, 'A sendable the employee may not send must be refused.');
} catch (SendException $e) {
    sendExpect(isset($e->errors['sendable']), 'The refusal must point at the sendable.');
}

// SMS to an allowed phone: the message, the conversation of the phone, the link, the job, the callback.
$request = $service->request('sample:12', ['channel' => 'sms', 'recipient' => '8 900 000 00 01', 'text' => "Ваше предложение готово\r\n"]);
$message = $service->send($request);
$send = SampleSendable::$sends[0] ?? null;
sendExpect($message->channel === 'sms' && $message->status === CmsCommMessage::STATUS_QUEUED && $message->direction === CmsCommMessage::DIRECTION_OUT
    && array_map('strval', array_keys($message->getTo())) === ['79000000001'] && (int)$message->sent_by_user_id === 1, 'The SMS must be a queued outgoing message to the normalized phone.');
sendExpect($send && $send['channel'] === 'sms' && $send['recipient'] === '79000000001' && $send['messageId'] === (int)$message->id
    && $send['code'] !== '' && $send['url'] === 'https://example.test/offer/'.$send['code'],
    'afterSend() must get the same context as createSendLink(): channel, recipient, URL, the package\'s own code.');
sendExpect($message->body_text === "Ваше предложение готово\n".$send['url'] && $message->subject === 'Тестовое предложение №12',
    'The SMS text must be the employee\'s text with the personal link on its own line.');
$conversation = CmsCommConversation::findOne((int)$message->cms_comm_conversation_id);
sendExpect($conversation && $conversation->channel === 'sms' && $conversation->thread_key === 'sms:79000000001', 'An SMS must go into the conversation of its phone.');
$job = Yii::$app->jobs->pushed[0] ?? null;
sendExpect($job && $job['type'] === 'comm.sms.send' && $job['payload']['messageId'] === (int)$message->id && $job['payload']['providerId'] === 3,
    'The SMS must be handed to its job with the provider.');
$second = $service->send($service->request('sample:12', ['channel' => 'sms', 'recipient' => '+79000000001']));
sendExpect((int)$second->cms_comm_conversation_id === (int)$message->cms_comm_conversation_id && $second->body_text !== $message->body_text
    && strpos((string)$second->body_text, 'Здравствуйте!') === 0, 'A second SMS to the phone joins its conversation with its own link and the default text.');

// A failing link rolls the whole send back.
SampleSendable::$failLink = true;
$before = (int)CmsCommMessage::find()->count();
try {
    $service->send($service->request('sample:12', ['channel' => 'sms', 'recipient' => '+79000000001']));
    sendExpect(false, 'A failing createSendLink() must stop the send.');
} catch (\RuntimeException $e) {
    sendExpect(!$e instanceof SendException, 'The failure of the package must come through.');
}
SampleSendable::$failLink = false;
sendExpect((int)CmsCommMessage::find()->count() === $before && count(Yii::$app->jobs->pushed) === 2, 'Nothing of a failed send may stay.');

// Delivery: the provider gets the text, its record is kept, the status follows.
[$status] = SmsSendJobHandler::deliver((int)$message->id, 3, '10.0.0.1');
$message->refresh();
$sms = CmsSmsMessage::findOne((int)$message->provider_ref);
sendExpect($status === CmsCommMessage::STATUS_SENT && $message->status === CmsCommMessage::STATUS_SENT
    && SendTestSmsHandler::$sent === [['79000000001', $message->body_text]], 'The provider must get the phone and the whole text.');
sendExpect($sms && $sms->phone === '79000000001' && $sms->provider_message_id === 'provider-1' && $sms->user_ip === '10.0.0.1' && (int)$sms->cms_sms_provider_id === 3,
    'The provider record must be kept with the message.');
sendExpect(SmsSendJobHandler::deliver((int)$message->id, 3) === [null, null] && count(SendTestSmsHandler::$sent) === 1, 'A sent SMS is never sent again.');

// A phone removed from the list meanwhile is not sent; a failing provider leaves the reason.
$component->allowedPhones = [];
[$status, $error] = SmsSendJobHandler::deliver((int)$second->id, 3);
sendExpect($status === CmsCommMessage::STATUS_FAILED && strpos((string)$error, 'allowedPhones') !== false && count(SendTestSmsHandler::$sent) === 1,
    'The job must check the phone again and refuse it before the provider.');
$component->allowedPhones = ['+79000000001'];
$third = $service->send($service->request('sample:12', ['channel' => 'sms', 'recipient' => '+79000000001']));
SendTestSmsHandler::$fail = true;
[$status, $error] = SmsSendJobHandler::deliver((int)$third->id, 3);
$third->refresh();
sendExpect($status === CmsCommMessage::STATUS_FAILED && $third->error === 'Провайдер отказал' && CmsSmsMessage::findOne((int)$third->provider_ref)->status === CmsSmsMessage::STATUS_ERROR,
    'A provider error must fail the message with its reason.');

// A message the client wrote on a page of the site ("Задать вопрос" of a proposal): incoming, without a mailbox.
$site = new \skeeks\cms\communication\services\SiteMessages();
$asked = $site->receive(1, 'proposal:7', 'Вопрос по КП №7', "Можно ли оплатить частями?\r\n", 'Анна', 'Anna@Example.com', []);
sendExpect($asked->channel === 'site' && $asked->direction === CmsCommMessage::DIRECTION_IN && $asked->status === CmsCommMessage::STATUS_RECEIVED
    && $asked->cms_comm_account_id === null && $asked->from_address === 'anna@example.com' && $asked->from_name === 'Анна' && $asked->body_text === 'Можно ли оплатить частями?',
    'A site message is incoming, has no mailbox and keeps the contact.');
$again = $site->receive(1, 'proposal:7', 'Вопрос по КП №7', 'И ещё вопрос', '', '+7 999 000-00-01', []);
sendExpect((int)$again->cms_comm_conversation_id === (int)$asked->cms_comm_conversation_id && $again->from_address === '+7 999 000-00-01'
    && CmsCommConversation::findOne((int)$asked->cms_comm_conversation_id)->thread_key === 'site:proposal:7',
    'Messages of one page share a conversation; a phone is kept as typed.');
try {
    $site->receive(1, 'proposal:7', 'Вопрос', "  \n ", '', '', []);
    sendExpect(false, 'An empty site message must be refused.');
} catch (\InvalidArgumentException $e) {
}

// Link checks: an opening of a personal link that is not the client.
$now = 1800000000;
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
sendExpect(LinkChecks::inNetworks('40.107.22.5', LinkChecks::NETWORKS) && LinkChecks::inNetworks('77.88.21.11', ['77.88.0.0/18'])
    && !LinkChecks::inNetworks('77.88.64.1', ['77.88.0.0/18']) && LinkChecks::inNetworks('2a02:6b8::1', ['2a02:6b8::/29'])
    && !LinkChecks::inNetworks('2a00:1450::1', ['2a02:6b8::/29']) && !LinkChecks::inNetworks('not-an-ip', ['0.0.0.0/0'])
    && !LinkChecks::inNetworks('10.0.0.1', ['2a02:6b8::/29']) && LinkChecks::inNetworks('10.0.0.7', ['10.0.0.7']), 'Networks are matched by CIDR, IPv4 and IPv6.');
sendExpect(LinkChecks::reason($now - 5, '95.31.18.1', $chrome, [], $now) === LinkChecks::REASON_EARLY, 'Seconds after sending is a check.');
sendExpect(LinkChecks::reason($now - 600, '40.107.22.5', $chrome, [], $now) === LinkChecks::REASON_NETWORK, 'Exchange Online Protection is a check.');
sendExpect(LinkChecks::reason($now - 600, '95.31.18.1', $chrome, ['webdriver' => '1'], $now) === LinkChecks::REASON_BROWSER
    && LinkChecks::reason($now - 600, '95.31.18.1', $chrome, ['languages' => '0'], $now) === LinkChecks::REASON_BROWSER
    && LinkChecks::reason($now - 600, '95.31.18.1', $chrome.' BingPreview/1.0b', [], $now) === LinkChecks::REASON_BROWSER, 'An automated browser is a check.');
sendExpect(LinkChecks::reason($now - 600, '95.31.18.1', $chrome, ['webdriver' => '0', 'languages' => '2', 'width' => '1920', 'height' => '1080'], $now) === null
    && LinkChecks::reason(0, '95.31.18.1', $chrome, [], $now) === null, 'A person later from a home network is the client.');
Yii::$app->communication->linkCheckNetworks = ['95.31.0.0/16'];
sendExpect(LinkChecks::reason($now - 600, '95.31.18.1', $chrome, [], $now) === LinkChecks::REASON_NETWORK, 'The site may add networks.');
Yii::$app->communication->linkCheckNetworks = [];
sendExpect(LinkChecks::reasonName(LinkChecks::REASON_EARLY) !== LinkChecks::REASON_EARLY, 'Reasons have names for people.');

echo "OK: send\n";
