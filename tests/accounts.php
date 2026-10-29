<?php
/**
 * Connections, encrypted secrets, mailboxes and employee access on an isolated
 * SQLite memory database. No site schema, no network.
 *
 * Usage: php tests/accounts.php [vendor/autoload.php]
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
$imapSrc = dirname(__DIR__, 2).'/cms-communication-imap/src';
Yii::setAlias('@skeeks/cms/communication/imap', $imapSrc);

use skeeks\cms\communication\CommunicationComponent;
use skeeks\cms\communication\imap\ImapDriver;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConnection;

function commExpect($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$key = 'test-key-'.bin2hex(random_bytes(8));
new \yii\console\Application([
    'id' => 'cms-communication-accounts',
    'basePath' => sys_get_temp_dir(),
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'i18n' => ['translations' => ['*' => ['class' => \yii\i18n\PhpMessageSource::class, 'basePath' => sys_get_temp_dir()]]],
        'skeeks' => ['class' => \skeeks\cms\Skeeks::class],
        'communication' => [
            'class' => CommunicationComponent::class,
            'secretKey' => $key,
            'drivers' => ['imap' => ImapDriver::class],
        ],
    ],
]);

$db = Yii::$app->db;
$common = ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];
$db->createCommand()->createTable('cms_user', ['id' => 'pk', 'username' => 'string', 'first_name' => 'string', 'last_name' => 'string', 'email' => 'string', 'phone' => 'string', 'is_worker' => 'integer', 'is_active' => 'integer not null default 1'])->execute();
$db->createCommand()->createTable('cms_comm_connection', $common + ['cms_site_id' => 'integer not null', 'name' => 'string not null', 'driver' => 'string not null', 'driver_config' => 'text', 'secret' => 'text', 'is_active' => 'integer not null default 1'])->execute();
$db->createCommand()->createTable('cms_comm_account', $common + ['cms_site_id' => 'integer not null', 'cms_comm_connection_id' => 'integer', 'channel' => "string not null default 'email'", 'address' => 'string not null', 'name' => 'string', 'reply_to' => 'string', 'is_expose_linked' => 'integer not null default 1', 'is_active' => 'integer not null default 1'])->execute();
$db->createCommand()->createTable('cms_comm_account_access', $common + ['cms_comm_account_id' => 'integer not null', 'cms_user_id' => 'integer not null', 'read_level' => "string not null default 'linked'", 'send_level' => "string not null default 'none'", 'can_manage' => 'integer not null default 0', 'can_manage_messages' => 'integer not null default 0', 'is_default' => 'integer not null default 0', 'is_responsible' => 'integer not null default 0', 'is_notify' => 'integer not null default 1'])->execute();
$db->createCommand()->batchInsert('cms_user', ['id', 'username', 'is_worker'], [[1, 'semenov', 1], [2, 'svetlana', 1]])->execute();

$component = Yii::$app->communication;

// Encryption: round trip, ciphertext differs from the plain value, wrong key fails.
$encrypted = $component->encryptSecret('app-password');
commExpect($encrypted !== '' && strpos($encrypted, 'app-password') === false, 'The stored secret must not contain the plain password.');
commExpect($component->decryptSecret($encrypted) === 'app-password', 'A secret must decrypt with the same key.');
$other = new CommunicationComponent(['secretKey' => 'another-key']);
try {
    $other->decryptSecret($encrypted);
    commExpect(false, 'A secret must not decrypt with another key.');
} catch (\RuntimeException $e) {
    commExpect(strpos($e->getMessage(), 'app-password') === false, 'The error must not reveal the secret.');
}
$noKey = new CommunicationComponent();
try {
    $noKey->encryptSecret('x');
    commExpect(false, 'Encryption without a key must fail.');
} catch (\yii\base\InvalidConfigException $e) {
}
commExpect(!$noKey->hasSecretKey() && $component->hasSecretKey(), 'hasSecretKey() must reflect the configuration.');
commExpect(!isset(Yii::$app->params['cms-communication']), 'The key must not be copied into application params.');

// A connection stores public settings and the encrypted password separately.
$driver = $component->createDriver('imap');
$driver->setAttributes(['username' => 'info@skeeks.com', 'password' => 'app-password', 'imap_host' => 'imap.yandex.ru', 'smtp_host' => 'smtp.yandex.ru']);
commExpect($driver->validate(), 'A complete IMAP driver must validate: '.json_encode($driver->errors, JSON_UNESCAPED_UNICODE));
$connection = new CmsCommConnection(['cms_site_id' => 1, 'name' => 'Яндекс 360', 'driver' => 'imap']);
$connection->applyDriver($driver);
commExpect($connection->save(), 'Connection must save: '.json_encode($connection->errors, JSON_UNESCAPED_UNICODE));
commExpect(strpos((string)$connection->driver_config, 'app-password') === false, 'Public settings must not contain the password.');
commExpect(strpos((string)$connection->secret, 'app-password') === false, 'The secret column must be encrypted.');
commExpect(!array_key_exists('secret', $connection->toArray()), 'toArray()/API must not expose the secret column.');

$connection = CmsCommConnection::findOne($connection->id);
commExpect($connection->createDriver(false)->password === '', 'Forms get a driver without the password.');
commExpect($connection->createDriver(true)->password === 'app-password', 'Transport code gets the decrypted password.');
commExpect($connection->createDriver(false)->imap_host === 'imap.yandex.ru', 'Public settings must be restored.');

// An empty password on update keeps the stored one; a new one replaces it.
$formDriver = $connection->createDriver(false);
$formDriver->imap_port = 143;
$connection->applyDriver($formDriver);
$connection->save();
$connection = CmsCommConnection::findOne($connection->id);
commExpect($connection->createDriver(true)->password === 'app-password' && (int)$connection->createDriver(false)->imap_port === 143,
    'An empty password field must keep the stored password while other settings change.');
$formDriver = $connection->createDriver(false);
$formDriver->password = 'new-password';
$connection->applyDriver($formDriver);
$connection->save();
commExpect(CmsCommConnection::findOne($connection->id)->createDriver(true)->password === 'new-password', 'A new password must replace the stored one.');

$bad = new CmsCommConnection(['cms_site_id' => 1, 'name' => 'X', 'driver' => 'unknown']);
commExpect(!$bad->validate(['driver']), 'An uninstalled driver must be rejected.');

// Mailboxes: normalized, unique per site and channel, connection of the same site.
$info = new CmsCommAccount(['cms_site_id' => 1, 'address' => ' Info@SkeeKs.com ', 'cms_comm_connection_id' => $connection->id]);
commExpect($info->save(), 'Mailbox must save: '.json_encode($info->errors, JSON_UNESCAPED_UNICODE));
commExpect($info->address === 'info@skeeks.com', 'Mailbox address must be normalized.');
$dup = new CmsCommAccount(['cms_site_id' => 1, 'address' => 'INFO@skeeks.com']);
commExpect(!$dup->validate(), 'The same mailbox must not be connected twice on a site.');
$otherSite = new CmsCommAccount(['cms_site_id' => 2, 'address' => 'info@skeeks.com', 'cms_comm_connection_id' => $connection->id]);
commExpect(!$otherSite->validate(['cms_comm_connection_id']), 'A mailbox must not use a connection of another site.');
commExpect(!(new CmsCommAccount(['cms_site_id' => 1, 'address' => 'not an email']))->validate(['address']), 'An email mailbox needs a valid address.');
$personal = new CmsCommAccount(['cms_site_id' => 1, 'address' => 'manager@skeeks.com', 'cms_comm_connection_id' => $connection->id]);
$personal->save();

$deleteBlocked = CmsCommConnection::findOne($connection->id);
commExpect($deleteBlocked->delete() === false, 'A connection used by mailboxes must not be deleted.');

// Access: one row per employee and mailbox, one default mailbox per employee.
$a1 = new CmsCommAccountAccess(['cms_comm_account_id' => $info->id, 'cms_user_id' => 2, 'read_level' => 'all', 'send_level' => 'any', 'is_default' => 1]);
commExpect($a1->save(), 'Access must save: '.json_encode($a1->errors, JSON_UNESCAPED_UNICODE));
$a2 = new CmsCommAccountAccess(['cms_comm_account_id' => $personal->id, 'cms_user_id' => 2, 'read_level' => 'all', 'send_level' => 'any', 'is_default' => 1]);
commExpect($a2->save(), 'Second access must save.');
commExpect((int)CmsCommAccountAccess::findOne($a1->id)->is_default === 0, 'Making another mailbox default must clear the previous default.');
$dupAccess = new CmsCommAccountAccess(['cms_comm_account_id' => $info->id, 'cms_user_id' => 2]);
commExpect(!$dupAccess->validate(['cms_user_id']), 'An employee must not get two access rows on one mailbox.');
$noSend = new CmsCommAccountAccess(['cms_comm_account_id' => $info->id, 'cms_user_id' => 1, 'send_level' => 'none', 'is_default' => 1]);
commExpect(!$noSend->validate(['is_default']), 'A mailbox without sending rights cannot be the default.');
$bogus = new CmsCommAccountAccess(['cms_comm_account_id' => $info->id, 'cms_user_id' => 1, 'read_level' => 'everything']);
commExpect(!$bogus->validate(['read_level']), 'Unknown read levels must be rejected.');

// Stand allowlist of recipients.
$allow = new CommunicationComponent(['allowedRecipients' => ['@skeeks.com', 'me@gmail.com']]);
commExpect($allow->isRecipientAllowed('Info@SkeeKs.com') && $allow->isRecipientAllowed('me@gmail.com'), 'Allowed domains and addresses must pass.');
commExpect(!$allow->isRecipientAllowed('client@ka2.store') && !$allow->isRecipientAllowed('x@notskeeks.com.evil'), 'Other recipients must be refused.');
commExpect((new CommunicationComponent())->isRecipientAllowed('anyone@x.ru'), 'An empty allowlist allows everyone.');
commExpect(\skeeks\cms\communication\forms\ComposeForm::splitAddresses(' A@b.ru; c@d.ru,a@b.ru ') === ['a@b.ru', 'c@d.ru'], 'Recipients are split, normalized and deduplicated.');

// Parsing: encoded headers, threading ids, attachments, automatic letters.
$parsed = (new \skeeks\cms\communication\mail\MailParser())->parse(
    "From: =?UTF-8?B?0JjQstCw0L0=?= <Ivan@Client.RU>\r\nTo: info@skeeks.com\r\nSubject: =?UTF-8?B?0J/RgNC40LLQtdGC?=\r\n"
    ."Message-ID: <abc@client.ru>\r\nIn-Reply-To: <sx.1@skeeks.com>\r\nReferences: <root@skeeks.com> <sx.1@skeeks.com>\r\n"
    ."Auto-Submitted: auto-replied\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=b1\r\n\r\n"
    ."--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nТекст\r\n"
    ."--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"tz.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQK\r\n--b1--\r\n");
commExpect($parsed['message_id'] === 'abc@client.ru' && $parsed['in_reply_to'] === 'sx.1@skeeks.com'
    && $parsed['references'] === ['root@skeeks.com', 'sx.1@skeeks.com'], 'Thread ids must be parsed without brackets.');
commExpect($parsed['from_address'] === 'ivan@client.ru' && $parsed['from_name'] === 'Иван' && $parsed['subject'] === 'Привет', 'Encoded headers must be decoded.');
commExpect($parsed['attachments'][0]['name'] === 'tz.pdf' && $parsed['attachments'][0]['size'] === 9, 'Attachment metadata must be read.');
commExpect($parsed['is_auto'] === true, 'Auto-Submitted letters must be marked automatic.');
commExpect($parsed['files'][0]['part_index'] === 0 && $parsed['files'][0]['content'] === "%PDF-1.4\n", 'File parts must keep their place and content for fetching.');

// An image the text shows is part of the text even when marked as an attachment.
$withImage = (new \skeeks\cms\communication\mail\MailParser())->parse(
    "From: a@x.ru\r\nTo: b@x.ru\r\nSubject: s\r\nMIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=r1\r\n\r\n"
    ."--r1\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<img src=\"cid:pic@x\">\r\n"
    ."--r1\r\nContent-Type: image/png\r\nContent-ID: <pic@x>\r\nContent-Disposition: attachment\r\nContent-Transfer-Encoding: base64\r\n\r\niVBORw0KGgo=\r\n--r1--\r\n");
commExpect($withImage['files'][0]['inline'] === true && $withImage['attachments'] === [], 'An image referenced by cid: must be inline, not an attachment.');

// File storage modes of "Почта" and of a mailbox.
$economy = \skeeks\cms\communication\services\MailFilePolicy::fromValues(['mode' => 'economy', 'maxStoreSizeMb' => 10, 'keepDays' => 365]);
commExpect($economy->keeps(true, 1000) && !$economy->keeps(false, 1000) && !$economy->keeps(true, 11 * 1048576),
    'Economy mode keeps images below the size limit and fetches attachments.');
$minimal = \skeeks\cms\communication\services\MailFilePolicy::fromValues(['mode' => 'minimal']);
$custom = \skeeks\cms\communication\services\MailFilePolicy::fromValues(['mode' => 'custom', 'storeImages' => 0, 'storeAttachments' => 1, 'keepDays' => 30]);
commExpect(!$minimal->keeps(true, 1) && !$minimal->keeps(false, 1) && !$custom->keeps(true, 1) && $custom->keeps(false, 1) && $custom->keepDays === 30,
    'Minimal and custom modes must follow their switches.');

// Letter HTML keeps its styles but loses active content.
$clean = \skeeks\cms\communication\helpers\MailHtml::sanitize(
    '<html><head><style>.card{border-radius:24px;background:#c6ebb5}</style><meta http-equiv="refresh" content="0;url=http://x"></head>'
    .'<body><div class="card" onclick="steal()">Привет</div><script>alert(1)</script><iframe src="http://x"></iframe>'
    .'<a href="javascript:alert(1)">a</a><a href="https://ok.ru">b</a><img src="data:text/html;base64,PHNjcmlwdD4="><form action="http://x"><input></form></body></html>');
commExpect(strpos($clean, '.card{border-radius:24px') !== false && strpos($clean, 'Привет') !== false, 'Letter styles and text must be kept.');
foreach (['<script', '<iframe', 'onclick', 'javascript:', 'refresh', 'data:text/html', '<form'] as $danger) {
    commExpect(stripos($clean, $danger) === false, 'Active content must be removed: '.$danger);
}
commExpect(strpos($clean, 'href="https://ok.ru" target="_blank"') !== false, 'Links must open outside the letter.');

// External images and backgrounds are blocked unless the employee shows them.
$letter = '<style>.h{background:url("https://t.example/bg.png")}</style>'
    .'<img src="https://read.example/1.gif"><img src="data:image/png;base64,iVBORw0KGgo="><td background="//x.example/b.jpg" style="background-image:url(https://x.example/c.jpg);color:red">x</td>';
$blocked = 0;
$safe = \skeeks\cms\communication\helpers\MailHtml::sanitize($letter, false, $blocked);
commExpect($blocked === 4 && strpos($safe, 'example') === false, 'External images, backgrounds and CSS urls must be blocked: '.$blocked);
commExpect(strpos($safe, 'data:image/png') !== false && strpos($safe, 'color:red') !== false, 'Embedded images and other styles must stay.');
$shown = \skeeks\cms\communication\helpers\MailHtml::sanitize($letter, true, $blocked);
commExpect($blocked === 0 && strpos($shown, 'https://read.example/1.gif') !== false, '"Show images" must keep external images.');

// The letter editor: toolbar formatting, safe links and uploaded images only; plain text from it.
$written = \skeeks\cms\helpers\RichTextHtml::clean(
    '<p style="color:red;position:fixed;background:url(https://t.example/x)" class="x" onclick="steal()">Привет, <b>мир</b></p><font face="Arial">шрифт</font>'
    .'<a href="javascript:alert(1)">плохая</a> <a href="https://skeeks.com" target="_blank">ссылка</a><script>alert(1)</script>'
    .'<ul><li>один</li><li>два</li></ul><img src="https://t.example/1.gif"><img src="/x" data-sx-upload="mail-0123456789abcdef0123456789abcdef/скрин.png" onerror="x()">'
    .'<img data-sx-upload="../../etc/passwd">'
    .'<div style="text-align:center;font-size:expression(alert(1))">центр</div>', \skeeks\cms\communication\forms\ComposeForm::EDITOR_PRESET);
commExpect($written === '<p style="color:red">Привет, <b>мир</b></p><span style="font-family:Arial">шрифт</span><a>плохая</a> <a href="https://skeeks.com">ссылка</a>'
    .'<ul><li>один</li><li>два</li></ul><img data-sx-upload="mail-0123456789abcdef0123456789abcdef/скрин.png" alt=""><div style="text-align:center">центр</div>',
    'Editor HTML must keep only allowed tags, styles, safe links and uploaded images: '.$written);
commExpect(\skeeks\cms\helpers\RichTextHtml::uploadTokens($written) === ['mail-0123456789abcdef0123456789abcdef/скрин.png'],
    'Uploaded images of the text must be found.');

// Pictures carried inside a letter (cid:) are shown from its own files, never from outside.
$cidLetter = \skeeks\cms\communication\helpers\MailHtml::sanitize('<p><img src="cid:logo@x"><img src="cid:unknown@x"></p>', false, $blocked, ['logo@x' => '/file?id=7']);
commExpect(strpos($cidLetter, 'src="/file?id=7"') !== false && strpos($cidLetter, 'cid:') === false && $blocked === 0,
    'cid: images must point to the letter files: '.$cidLetter);
$text = \skeeks\cms\helpers\RichTextHtml::toText($written);
commExpect(strpos($text, "Привет, мир\n") === 0 && strpos($text, 'ссылка (https://skeeks.com)') !== false && strpos($text, "• один\n• два") !== false,
    'Plain-text part must keep lines, links and list items: '.$text);
commExpect(\skeeks\cms\helpers\RichTextHtml::fromText("a < b\nc") === 'a &lt; b<br>'."\n".'c', 'Plain text must be escaped with line breaks.');
$quoted = \skeeks\cms\communication\helpers\MailHtml::quoteBody('<html><head><style>p{}</style></head><body><p onclick="x()">Цитата</p></body></html>');
commExpect($quoted === '<p>Цитата</p>', 'Quoted letter must keep only its cleaned body: '.$quoted);

// Recipients typed or pasted in any common form.
commExpect(\skeeks\cms\communication\forms\ComposeForm::splitAddresses('Иван <Ivan@Client.ru>, "ООО, Ромашка" <info@romashka.ru>; petr@x.ru  anna@y.ru')
    === ['ivan@client.ru', 'info@romashka.ru', 'petr@x.ru', 'anna@y.ru'], 'Addresses must be split from names and separators.');

// The sender's "Sent" copy shows the blind copy; the rendered letter does not carry it.
$raw = "From: a@x.ru\r\nTo: b@x.ru\r\n\r\nBcc: not a header\r\n";
$copy = \skeeks\cms\communication\jobs\MailSendJobHandler::withBcc($raw, ['c@x.ru', 'bad']);
commExpect($copy === "Bcc: c@x.ru\r\n".$raw, 'Sent copy must get a Bcc header: '.$copy);
commExpect(\skeeks\cms\communication\jobs\MailSendJobHandler::withBcc($raw, []) === $raw, 'Letters without a blind copy stay unchanged.');

// "Сбор писем": the period of the collection.
$history = new \skeeks\cms\communication\forms\MailHistoryForm(['period' => '365']);
commExpect($history->validate() && $history->since() === strtotime('today') - 365 * 86400, 'A year back from today.');
$history = new \skeeks\cms\communication\forms\MailHistoryForm(['period' => 'all']);
commExpect($history->validate() && $history->since() === 0, 'Every letter of the mailbox: since 0.');
$history = new \skeeks\cms\communication\forms\MailHistoryForm(['period' => 'date']);
commExpect(!$history->validate() && $history->hasErrors('date'), '"С даты…" requires the date.');
$history = new \skeeks\cms\communication\forms\MailHistoryForm(['period' => 'date', 'date' => '2026-03-01']);
commExpect($history->validate() && $history->since() === strtotime('2026-03-01 00:00:00'), 'From the chosen date.');
$history = new \skeeks\cms\communication\forms\MailHistoryForm(['period' => '7']);
commExpect(!$history->validate(), 'Only the offered periods.');

// "Сохранить файлы в CRM": what to keep for good.
$save = new \skeeks\cms\communication\forms\MailFileSaveForm();
commExpect($save->validate() && $save->criteria() === ['accountId' => null, 'since' => 0, 'images' => true, 'attachments' => true, 'maxSizeMb' => 25],
    'By default every letter, images and attachments up to 25 MB: '.json_encode($save->criteria()));
$save = new \skeeks\cms\communication\forms\MailFileSaveForm(['images' => 0, 'attachments' => 0]);
commExpect(!$save->validate() && $save->hasErrors('attachments'), 'Something must be chosen to save.');
$save = new \skeeks\cms\communication\forms\MailFileSaveForm(['accountId' => '3', 'period' => '90', 'images' => 0, 'maxSizeMb' => 0]);
commExpect($save->validate() && $save->criteria()['accountId'] === 3 && $save->criteria()['since'] === strtotime('today') - 90 * 86400
    && !$save->criteria()['images'] && $save->criteria()['maxSizeMb'] === 0, 'One mailbox, 3 months, attachments only, any size.');
commExpect(\skeeks\cms\communication\services\MailFileSave::criteria(['images' => 1]) === ['accountId' => null, 'since' => 0, 'images' => true, 'attachments' => false, 'maxSizeMb' => 0],
    'The job normalizes its payload.');

// Notifications about new letters: who gets them (MailNotifier::recipients()).
$db->createCommand()->createTable('cms_comm_message', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer',
    'cms_comm_account_id' => 'integer', 'direction' => 'string', 'is_history' => 'integer not null default 0', 'from_address' => 'string',
    'from_name' => 'string', 'subject' => 'string', 'message_at' => 'integer', 'sent_by_user_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_message_mailbox', ['id' => 'pk', 'cms_comm_message_id' => 'integer', 'cms_comm_account_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_message_read', ['id' => 'pk', 'cms_comm_message_id' => 'integer', 'cms_user_id' => 'integer', 'read_at' => 'integer'])->execute();
// Main responsibles of linked clients are notified too (MailNotifier::mainResponsibleOf()); these letters are not linked.
$db->createCommand()->createTable('cms_comm_link', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_message_id' => 'integer', 'model_code' => 'string', 'model_id' => 'integer', 'link_type' => 'string', 'cms_log_id' => 'integer'])->execute();
$db->createCommand()->batchInsert('cms_user', ['id', 'username', 'is_worker', 'is_active'], [[3, 'fired', 1, 0], [4, 'muted', 1, 1], [5, 'reader', 1, 1]])->execute();
foreach ([[1, 'all', 1], [3, 'all', 1], [4, 'all', 0], [5, 'all', 1]] as [$userId, $level, $notify]) {
    $db->createCommand()->insert('cms_comm_account_access', ['cms_comm_account_id' => $info->id, 'cms_user_id' => $userId,
        'read_level' => $level, 'send_level' => 'none', 'is_notify' => $notify])->execute();
}
$letter = static function (array $values) use ($db, $info): \skeeks\cms\communication\models\CmsCommMessage {
    $db->createCommand()->insert('cms_comm_message', $values + ['cms_site_id' => 1, 'cms_comm_account_id' => $info->id, 'direction' => 'in',
        'from_address' => 'ivan@client.ru', 'subject' => 'Счёт', 'message_at' => time()])->execute();
    return \skeeks\cms\communication\models\CmsCommMessage::findOne((int)$db->getLastInsertID());
};
$fresh = $letter([]);
$history = $letter(['is_history' => 1]);
$outgoing = $letter(['direction' => 'out']);
$copyOnly = $letter(['cms_comm_account_id' => null]);
$db->createCommand()->insert('cms_comm_message_mailbox', ['cms_comm_message_id' => $copyOnly->id, 'cms_comm_account_id' => $info->id])->execute();
$db->createCommand()->insert('cms_comm_message_read', ['cms_comm_message_id' => $fresh->id, 'cms_user_id' => 5, 'read_at' => time()])->execute();
$recipients = (new \skeeks\cms\communication\services\MailNotifier())->recipients([$fresh, $copyOnly]);
ksort($recipients);
commExpect($recipients === [1 => [$fresh->id, $copyOnly->id], 2 => [$fresh->id, $copyOnly->id], 5 => [$copyOnly->id]],
    'Readers of the mailbox are notified; not inactive employees, not those who switched it off, not about letters they read: '.json_encode($recipients));
// notifyNew() itself skips letters collected from the history and outgoing ones before choosing recipients.
commExpect(\skeeks\cms\communication\models\CmsCommMessage::find()->andWhere(['id' => [$history->id, $outgoing->id]])
    ->andWhere(['direction' => 'in', 'is_history' => 0])->count() == 0, 'History and outgoing letters are never new.');
$info->is_active = 0;
$info->save(false, ['is_active']);
commExpect((new \skeeks\cms\communication\services\MailNotifier())->recipients([$fresh]) === [], 'A switched off mailbox notifies nobody.');
$info->is_active = 1;
$info->save(false, ['is_active']);

// Signatures and templates keep the letter formatting but no pictures (temporary uploads would expire).
$signature = \skeeks\cms\helpers\RichTextHtml::clean('<div><b>Семёнов</b> <img data-sx-upload="mail-0123456789abcdef0123456789abcdef/logo.png"><img src="https://x.ru/l.png"></div><script>x</script>',
    \skeeks\cms\communication\services\MailTemplates::EDITOR_PRESET);
commExpect($signature === '<div><b>Семёнов</b> </div>', 'A signature keeps formatting and drops pictures: '.$signature);

// The letter form carries the draft it continues and "Ответить всем".
$compose = new \skeeks\cms\communication\forms\ComposeForm();
$compose->load(['ComposeForm' => ['draft_id' => '7', 'reply_all' => '1', 'to' => 'a@b.ru']]);
commExpect((int)$compose->draft_id === 7 && (int)$compose->reply_all === 1, 'draft_id and reply_all are form attributes.');

// A draft keeps recipients as typed and forwarded attachments as a list.
$db->createCommand()->createTable('cms_comm_draft', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer',
    'cms_user_id' => 'integer', 'to_list' => 'text', 'body_html' => 'text', 'file_ids' => 'text'])->execute();
$draft = new \skeeks\cms\communication\models\CmsCommDraft(['to_list' => 'Иван <Ivan@Client.ru>, недописан', 'body_html' => '<div>Привет,<br>это <b>черновик</b></div>']);
$draft->setFileIds(['3', 3, 0, '5']);
commExpect($draft->getFileIds() === [3, 5] && $draft->getToAddresses()[0] === 'ivan@client.ru' && $draft->getSnippet() === 'Привет, это черновик',
    'Draft file ids, recipients and snippet: '.json_encode([$draft->getFileIds(), $draft->getToAddresses(), $draft->getSnippet()], JSON_UNESCAPED_UNICODE));
$draft->setFileIds([]);
commExpect($draft->file_ids === null, 'A draft without forwarded attachments stores nothing.');

// Unsent drafts are removed by the daily cleanup after "Хранить неотправленные черновики, дней".
$settings = new \skeeks\cms\communication\components\MailFileSettings();
commExpect((int)$settings->draftKeepDays === 90 && $settings->values()['draftKeepDays'] === 90, 'Drafts are kept 90 days by default.');
$settings->draftKeepDays = -1;
commExpect(!$settings->validate(['draftKeepDays']), 'The draft term cannot be negative.');
commExpect(\skeeks\cms\communication\services\MailDrafts::cleanupExpired(1, 0) === ['removed' => 0, 'freed' => 0], 'A zero term keeps drafts forever.');

// IMAP UID sets: compact ranges both ways.
commExpect(\skeeks\cms\communication\imap\ImapClient::uidSet([10, 2, 3, 1, 7, 9, 3]) === '1:3,7,9:10', 'UID sets are compact ranges.');
commExpect(\skeeks\cms\communication\imap\ImapClient::expandSet('3:5,9') === [3, 4, 5, 9], 'COPYUID sets expand in order.');

// Special folders: SPECIAL-USE marks first, then well-known names of top-level folders.
$listClient = new class('imap.example', 993) extends \skeeks\cms\communication\imap\ImapClient {
    public function listFolders(): array
    {
        return [
            ['name' => 'INBOX', 'flags' => [], 'delimiter' => '.'],
            ['name' => 'INBOX.Archive', 'flags' => [], 'delimiter' => '.'],
            ['name' => 'Sent', 'flags' => ['\\sent'], 'delimiter' => '.'],
            ['name' => mb_convert_encoding('Спам', 'UTF7-IMAP', 'UTF-8'), 'flags' => [], 'delimiter' => '.'],
            ['name' => 'Clients.Trash', 'flags' => [], 'delimiter' => '.'],
            ['name' => 'Deleted Items', 'flags' => [], 'delimiter' => '.'],
            ['name' => 'Drafts.template', 'flags' => ['\\templates'], 'delimiter' => '.'],
            ['name' => 'Clients', 'flags' => ['\\haschildren'], 'delimiter' => '.'],
            ['name' => 'Box', 'flags' => ['\\noselect'], 'delimiter' => '.'],
        ];
    }
};
$roles = array_column((new \skeeks\cms\communication\imap\ImapSession($listClient))->folderList(), 'role', 'name');
commExpect($roles === ['INBOX' => 'inbox', 'INBOX.Archive' => 'archive', 'Sent' => 'sent', mb_convert_encoding('Спам', 'UTF7-IMAP', 'UTF-8') => 'junk',
    'Clients.Trash' => null, 'Deleted Items' => 'trash', 'Drafts.template' => 'drafts', 'Clients' => null],
    'Folder roles by marks and names; nested ordinary folders stay ordinary: '.json_encode($roles, JSON_UNESCAPED_UNICODE));
commExpect(\skeeks\cms\communication\models\CmsCommSyncState::folderTitle(mb_convert_encoding('Клиенты|Иванов', 'UTF7-IMAP', 'UTF-8'), '|') === 'Клиенты / Иванов',
    'Folder titles are decoded with nested parts.');

// "Управление письмами" is off unless given.
commExpect((new CmsCommAccountAccess())->getAttributeLabel('can_manage_messages') === 'Управление письмами' && (int)CmsCommAccountAccess::findOne($a1->id)->can_manage_messages === 0,
    'The right to manage letters has its name and is off by default.');

// Search over the conversation: normalized lines and words.
commExpect(\skeeks\cms\communication\services\MailSearch::normalize("  Счёт\n ЁЖ ") === 'счет еж', 'Search text is lower case, "ё" as "е", single spaces.');
commExpect(\skeeks\cms\communication\services\MailSearch::terms('а  Счёт Info@SkeeKs счёт') === ['счет', 'info@skeeks'], 'One-letter words are ignored, words are unique.');

// Actions in the mailbox (MailboxActions) and the server (MailServerSync) with a server in memory.
foreach (['cms_comm_conversation_id' => 'integer', 'external_id' => 'string', 'box_state' => 'string', 'to_list' => 'text', 'cc_list' => 'text',
    'bcc_list' => 'text', 'body_text' => 'text', 'body_html' => 'text', 'channel' => "string default 'email'"] as $column => $type) {
    $db->createCommand()->addColumn('cms_comm_message', $column, $type)->execute();
}
foreach (['created_at' => 'integer', 'cms_comm_connection_id' => 'integer', 'folder' => 'string', 'uidvalidity' => 'integer', 'uid' => 'integer',
    'folder_role' => 'string', 'target_role' => 'string', 'target_folder' => 'string', 'target_by' => 'integer',
    'labels_dirty' => 'integer not null default 0', 'sync_error' => 'string', 'sync_error_at' => 'integer'] as $column => $type) {
    $db->createCommand()->addColumn('cms_comm_message_mailbox', $column, $type)->execute();
}
$db->createCommand()->createTable('cms_comm_sync_state', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_connection_id' => 'integer',
    'folder' => 'string', 'folder_role' => 'string', 'delimiter' => 'string', 'uidvalidity' => 'integer', 'uidnext' => 'integer',
    'last_uid' => 'integer not null default 0', 'last_success_at' => 'integer', 'last_error' => 'text', 'last_error_at' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_label', $common + ['cms_site_id' => 'integer', 'name' => 'string', 'color' => 'string', 'priority' => 'integer default 100'])->execute();
$db->createCommand()->createTable('cms_comm_message_label', ['cms_comm_message_id' => 'integer', 'cms_comm_label_id' => 'integer', 'created_at' => 'integer', 'created_by' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_message_search', ['cms_comm_message_id' => 'integer primary key', 'cms_site_id' => 'integer',
    'cms_comm_conversation_id' => 'integer', 'search_text' => 'text'])->execute();

$jobs = new class {
    public array $pushed = [];
    public function push($type, $payload, $options = []) { $this->pushed[] = [$type, $payload]; return null; }
};
Yii::$app->set('jobs', $jobs);
Yii::$app->set('authManager', new class {
    public function checkAccess($userId, $permission, $params = []) { return (int)$userId === 1; }
});

/** A mail server in memory: folder => uid => Message-ID. */
$server = new class implements \skeeks\cms\communication\mail\MailManageSessionInterface {
    public array $boxes = ['INBOX' => [], 'Sent' => [], 'Trash' => [], 'Junk' => [], 'Clients' => []];
    public array $roles = ['INBOX' => 'inbox', 'Sent' => 'sent', 'Trash' => 'trash', 'Junk' => 'junk', 'Clients' => null];
    public array $next = [];
    public array $flags = [];
    public bool $keywords = true;
    public function folderList(): array { $r = []; foreach ($this->roles as $n => $role) { $r[] = ['name' => $n, 'role' => $role, 'delimiter' => '/']; } return $r; }
    public function status(string $f): array { return ['uidvalidity' => 7, 'uidnext' => $this->next[$f] ?? (max(array_keys($this->boxes[$f]) ?: [0]) + 1), 'messages' => count($this->boxes[$f] ?? [])]; }
    public function existingUids(string $f, array $uids): array { return array_values(array_intersect(array_map('intval', $uids), array_keys($this->boxes[$f] ?? []))); }
    public function uidsAfterIn(string $f, int $uid): array { return array_values(array_filter(array_keys($this->boxes[$f]), static fn($u) => $u > $uid)); }
    public function uidsSinceIn(string $f, int $t): array { return array_keys($this->boxes[$f]); }
    public function messageIds(string $f, array $uids): array { return array_intersect_key($this->boxes[$f], array_flip($uids)); }
    public function add(string $f, string $id): int { $uid = $this->status($f)['uidnext']; $this->boxes[$f][$uid] = $id; $this->next[$f] = $uid + 1; return $uid; }
    public function move(string $f, array $uids, string $target): array
    {
        $r = ['uidvalidity' => 7, 'uids' => [], 'missing' => []];
        foreach ($uids as $uid) {
            if (!isset($this->boxes[$f][$uid])) { $r['missing'][] = (int)$uid; continue; }
            $r['uids'][(int)$uid] = $this->add($target, $this->boxes[$f][$uid]);
            $this->flags[$target][$r['uids'][(int)$uid]] = $this->flags[$f][$uid] ?? [];
            unset($this->boxes[$f][$uid]);
        }
        return $r;
    }
    public function createFolder(string $name): void { $this->boxes[$name] = $this->boxes[$name] ?? []; $this->roles[$name] = $this->roles[$name] ?? null; }
    public function supportsKeywords(string $f): bool { return $this->keywords; }
    public function flags(string $f, array $uids): array { return array_intersect_key($this->flags[$f] ?? [], array_flip($uids)); }
    public function storeFlags(string $f, array $uids, array $add, array $remove = []): void
    {
        foreach ($uids as $uid) { $this->flags[$f][$uid] = array_values(array_diff(array_unique(array_merge($this->flags[$f][$uid] ?? [], $add)), $remove)); }
    }
    public function folders(): array { return []; }
    public function select(string $folder): array { return $this->status($folder); }
    public function uidsAfter(int $uid): array { return []; }
    public function uidsSince(int $timestamp): array { return []; }
    public function fetch(array $uids): array { return []; }
    public function hasMessageId(string $folder, string $messageId): bool { return false; }
    public function append(string $folder, string $raw): void {}
    public function close(): void {}
};

$mailLetter = static function (string $externalId, ?string $folder, ?int $uid, string $role = 'inbox') use ($db, $info, $connection, $server) {
    $m = new \skeeks\cms\communication\models\CmsCommMessage(['cms_site_id' => 1, 'cms_comm_account_id' => $info->id, 'cms_comm_conversation_id' => 1,
        'direction' => 'in', 'external_id' => $externalId, 'from_address' => 'ivan@client.ru', 'subject' => 'Счёт № '.$externalId, 'message_at' => time()]);
    $m->save(false);
    $db->createCommand()->insert('cms_comm_message_mailbox', ['cms_comm_message_id' => $m->id, 'cms_comm_account_id' => $info->id,
        'cms_comm_connection_id' => $connection->id, 'folder' => $folder, 'uidvalidity' => $folder ? 7 : null, 'uid' => $uid, 'folder_role' => $folder ? $role : null])->execute();
    if ($folder) {
        $server->boxes[$folder][$uid] = $externalId;
    }
    return \skeeks\cms\communication\models\CmsCommMessage::findOne($m->id);
};
$copyOf = static fn($m) => \skeeks\cms\communication\models\CmsCommMessageMailbox::findOne(['cms_comm_message_id' => $m->id]);
$stateOf = static fn($m) => \skeeks\cms\communication\models\CmsCommMessage::findOne($m->id)->box_state;
$m1 = $mailLetter('one@client.ru', 'INBOX', 10);
$m2 = $mailLetter('two@client.ru', 'INBOX', 11);
$unsent = $mailLetter('sx.3@skeeks.com', null, null);
commExpect((new \yii\db\Query())->from('cms_comm_message_search')->where(['cms_comm_message_id' => $m1->id])->scalar() !== false
    && (new \yii\db\Query())->select('search_text')->from('cms_comm_message_search')->where(['cms_comm_message_id' => $m1->id])->scalar() === 'ivan@client.ru счет № one@client.ru',
    'Every saved letter gets its normalized search line.');

// Administrators follow their own access rows in mail, like everyone (user 1 is an administrator here).
commExpect(!(new \skeeks\cms\communication\services\MailboxActions(\skeeks\cms\models\CmsUser::findOne(1), 1))->canManageAny(),
    'An administrator without "Управление письмами" manages no letters.');
commExpect(array_map(static fn($a) => $a->address, (new \skeeks\cms\communication\services\MailComposeService(\skeeks\cms\models\CmsUser::findOne(1), 1))->sendableAccounts()) === [],
    'An administrator without a send right sends from no mailbox.');

$svetlana = \skeeks\cms\models\CmsUser::findOne(2);
$actions = new \skeeks\cms\communication\services\MailboxActions($svetlana, 1);
commExpect(!$actions->canManageAny() && !$actions->canManage($m1), 'Without "Управление письмами" an employee manages nothing.');
$result = $actions->move([$m1], 'trash');
commExpect($result['moved'] === 0 && $copyOf($m1)->target_role === null, 'Without the right nothing moves.');

$db->createCommand()->update('cms_comm_account_access', ['can_manage_messages' => 1], ['id' => $a1->id])->execute();
$actions = new \skeeks\cms\communication\services\MailboxActions($svetlana, 1);
$result = $actions->move([$m1, $unsent], 'trash');
commExpect($result['moved'] === 1 && $result['skipped'] === ['письмо ещё не появилось в почтовом ящике' => 1] && $result['connections'] === [(int)$connection->id],
    'The right moves letters with a place on the server: '.json_encode($result, JSON_UNESCAPED_UNICODE));
commExpect($copyOf($m1)->target_role === 'trash' && $copyOf($m1)->folder === 'INBOX' && $stateOf($m1) === 'trash' && end($jobs->pushed)[0] === 'comm.mail.apply',
    'The letter leaves the usual folders at once; the job of the connection is asked to move it.');
$actions->move([$m1], 'restore');
commExpect($copyOf($m1)->target_role === null && $stateOf($m1) === null, 'Back before the job ran: the move is cancelled.');
commExpect($actions->move([$m1], 'restore')['skipped'] === ['письмо уже там' => 1], 'A letter already in place is not moved.');

$sync = new \skeeks\cms\communication\services\MailServerSync();
$actions->move([$m1], 'trash');
$sync->loadFolders($server, $connection);
$actions = new \skeeks\cms\communication\services\MailboxActions($svetlana, 1);
$actions->move([$m2], 'folder', 'Clients');
unset($server->boxes['INBOX'][11]);
$server->add('Clients', 'two@client.ru');
$applied = $sync->apply($connection, $server);
commExpect($applied['moved'] === 1 && $applied['failed'] === 1, 'One letter moved, one was gone from its folder: '.json_encode($applied));
commExpect($copyOf($m1)->folder === 'Trash' && $copyOf($m1)->folder_role === 'trash' && (int)$copyOf($m1)->uid === array_search('one@client.ru', $server->boxes['Trash'], true)
    && !isset($server->boxes['INBOX'][10]), 'The moved copy gets its new folder and UID from the server.');
commExpect($copyOf($m2)->target_role === null && strpos((string)$copyOf($m2)->sync_error, 'Письма уже нет') === 0 && $stateOf($m2) === null,
    'A letter that is not where the CRM thinks stays, with the reason.');

// Moved in the mail service itself: found by Message-ID in another folder; deleted: "gone".
$found = $sync->reconcile($connection, $server);
commExpect($found === ['moved' => 1, 'gone' => 0] && $copyOf($m2)->folder === 'Clients' && $copyOf($m2)->folder_role === 'other',
    'A letter moved in the mail service takes its new place: '.json_encode($found));
$server->boxes['Trash'] = [];
$found = $sync->reconcile($connection, $server);
commExpect($found === ['moved' => 0, 'gone' => 1] && $copyOf($m1)->folder_role === 'gone' && $stateOf($m1) === 'trash',
    'A letter deleted in the mail service is gone and stays out of the usual folders.');
$addCopy = \Closure::bind(fn(...$args) => $this->addCopy(...$args), new \skeeks\cms\communication\services\MailIngestService(), \skeeks\cms\communication\services\MailIngestService::class);
$addCopy(\skeeks\cms\communication\models\CmsCommMessage::findOne($m1->id), $info, $connection, 'inbox', 'INBOX', 7, 40);
commExpect($copyOf($m1)->folder === 'INBOX' && (int)$copyOf($m1)->uid === 40 && $copyOf($m1)->folder_role === 'inbox' && $stateOf($m1) === null
    && \skeeks\cms\communication\models\CmsCommMessageMailbox::find()->andWhere(['cms_comm_message_id' => $m1->id])->count() == 1,
    'A letter back in "Входящие" is not doubled: its copy takes the new place.');

// Labels: CRM data, written to the server as keywords.
$label = new \skeeks\cms\communication\models\CmsCommLabel(['cms_site_id' => 1, 'name' => ' Счета ', 'color' => '#E5484D']);
commExpect($label->save() && $label->name === 'Счета' && $label->color === '#e5484d', 'A label is trimmed, its color normalized.');
$server->boxes['INBOX'][40] = 'one@client.ru';
$labeled = $actions->label([\skeeks\cms\communication\models\CmsCommMessage::findOne($m1->id)], $label, true);
commExpect($labeled['changed'] === 1 && (int)$copyOf($m1)->labels_dirty === 1, 'A label marks the copies to write.');
$sync->apply($connection, $server);
commExpect(($server->flags['INBOX'][40] ?? []) === ['SkeekS_label_'.$label->id] && (int)$copyOf($m1)->labels_dirty === 0, 'The keyword is on the server.');
$actions->label([\skeeks\cms\communication\models\CmsCommMessage::findOne($m1->id)], $label, false);
$sync->apply($connection, $server);
commExpect(($server->flags['INBOX'][40] ?? null) === [], 'Taken off in the CRM: taken off on the server.');

echo "cms-communication accounts: OK\n";
