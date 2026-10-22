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
$db->createCommand()->createTable('cms_user', ['id' => 'pk', 'username' => 'string', 'first_name' => 'string', 'last_name' => 'string', 'email' => 'string', 'phone' => 'string', 'is_worker' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_connection', $common + ['cms_site_id' => 'integer not null', 'name' => 'string not null', 'driver' => 'string not null', 'driver_config' => 'text', 'secret' => 'text', 'is_active' => 'integer not null default 1'])->execute();
$db->createCommand()->createTable('cms_comm_account', $common + ['cms_site_id' => 'integer not null', 'cms_comm_connection_id' => 'integer', 'channel' => "string not null default 'email'", 'address' => 'string not null', 'name' => 'string', 'reply_to' => 'string', 'is_expose_linked' => 'integer not null default 1', 'is_active' => 'integer not null default 1'])->execute();
$db->createCommand()->createTable('cms_comm_account_access', $common + ['cms_comm_account_id' => 'integer not null', 'cms_user_id' => 'integer not null', 'read_level' => "string not null default 'linked'", 'send_level' => "string not null default 'none'", 'can_manage' => 'integer not null default 0', 'can_manage_messages' => 'integer not null default 0', 'is_default' => 'integer not null default 0', 'is_responsible' => 'integer not null default 0'])->execute();
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

echo "cms-communication accounts: OK\n";
