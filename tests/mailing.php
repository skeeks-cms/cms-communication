<?php
/**
 * Mailings, stage 4A, on an isolated SQLite memory database: contacts and
 * their normalization, consent and opt-out by topic (an import never undoes
 * an opt-out nor weakens a consent), the stop list, the check "можно ли
 * отправить рекламу" for a manager's mailbox and for a mailing service, the
 * warning of personal letters, the personal opt-out link in a letter and the
 * List-Unsubscribe headers of the letter that leaves, the opt-out page and
 * one click, parsing of imported tables and the duplicates of the CRM.
 * No site schema, no network.
 *
 * Usage: php tests/mailing.php [vendor/autoload.php]
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

use skeeks\cms\communication\CommunicationComponent;
use skeeks\cms\communication\jobs\MailSendJobHandler;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\CrmDuplicates;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\LeadImport;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\communication\services\MailingPolicy;
use skeeks\cms\communication\services\Unsubscribe;

function mailingExpect($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

new \yii\console\Application([
    'id' => 'cms-communication-mailing',
    'basePath' => sys_get_temp_dir(),
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'skeeks' => ['class' => \skeeks\cms\Skeeks::class],
        'communication' => ['class' => CommunicationComponent::class],
    ],
]);

$db = Yii::$app->db;
$create = static fn(string $table, array $columns) => $db->createCommand()->createTable($table, $columns)->execute();
$create('cms_comm_topic', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'name' => 'string', 'description' => 'text', 'is_active' => 'integer default 1', 'priority' => 'integer default 100']);
$create('cms_comm_contact', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'kind' => 'string', 'value' => 'string', 'code' => 'string']);
$db->createCommand()->createIndex('contact_unique', 'cms_comm_contact', ['cms_site_id', 'kind', 'value'], true)->execute();
$create('cms_comm_contact_topic', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_contact_id' => 'integer', 'cms_comm_topic_id' => 'integer',
    'status' => 'string', 'basis' => 'string', 'consent_at' => 'integer', 'consent_by' => 'integer', 'unsubscribed_at' => 'integer', 'unsubscribed_by' => 'integer',
    'unsubscribe_source' => 'string', 'cms_comm_message_id' => 'integer', 'note' => 'string']);
$db->createCommand()->createIndex('contact_topic_unique', 'cms_comm_contact_topic', ['cms_comm_contact_id', 'cms_comm_topic_id'], true)->execute();
$create('cms_comm_suppression', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_contact_id' => 'integer',
    'reason' => 'string', 'source' => 'string', 'cms_comm_message_id' => 'integer', 'note' => 'string']);
$db->createCommand()->createIndex('suppression_unique', 'cms_comm_suppression', ['cms_comm_contact_id', 'reason'], true)->execute();
$create('cms_comm_account', ['id' => 'pk', 'cms_site_id' => 'integer', 'address' => 'string', 'name' => 'string', 'reply_to' => 'string']);
$create('cms_comm_message', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer',
    'cms_comm_account_id' => 'integer', 'channel' => 'string', 'direction' => 'string', 'status' => 'string', 'external_id' => 'string', 'provider_ref' => 'string',
    'cms_comm_topic_id' => 'integer', 'unsubscribe_url' => 'string', 'in_reply_to' => 'string', 'references_list' => 'text', 'from_address' => 'string',
    'from_name' => 'string', 'to_list' => 'text', 'cc_list' => 'text', 'bcc_list' => 'text', 'subject' => 'string', 'body_text' => 'text', 'body_html' => 'text',
    'attachments' => 'text', 'is_internal' => 'integer default 0', 'is_auto' => 'integer default 0', 'message_at' => 'integer', 'sent_by_user_id' => 'integer', 'error' => 'text']);
$create('cms_comm_message_search', ['cms_comm_message_id' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer', 'search_text' => 'text']);
$create('cms_comm_message_file', ['id' => 'pk', 'cms_comm_message_id' => 'integer', 'priority' => 'integer']);
$create('cms_comm_import', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'file_name' => 'string', 'status' => 'string', 'columns' => 'text', 'mapping' => 'text']);
// CRM records for the duplicates: leads, companies, clients with contacts and requisites.
$create('cms_user', ['id' => 'pk', 'username' => 'string', 'first_name' => 'string', 'last_name' => 'string', 'is_worker' => 'integer', 'is_active' => 'integer default 1']);
$create('cms_lead', ['id' => 'pk', 'cms_site_id' => 'integer', 'name' => 'string']);
$create('cms_lead_email', ['id' => 'pk', 'cms_lead_id' => 'integer', 'value' => 'string']);
$create('cms_lead_phone', ['id' => 'pk', 'cms_lead_id' => 'integer', 'value' => 'string']);
$create('cms_company', ['id' => 'pk', 'name' => 'string']);
$create('cms_company_email', ['id' => 'pk', 'cms_company_id' => 'integer', 'value' => 'string']);
$create('cms_company_phone', ['id' => 'pk', 'cms_company_id' => 'integer', 'value' => 'string']);
$create('cms_user_email', ['id' => 'pk', 'cms_site_id' => 'integer', 'cms_user_id' => 'integer', 'value' => 'string']);
$create('cms_user_phone', ['id' => 'pk', 'cms_site_id' => 'integer', 'cms_user_id' => 'integer', 'value' => 'string']);
$create('cms_contractor', ['id' => 'pk', 'inn' => 'string']);
$create('cms_lead2contractor', ['id' => 'pk', 'cms_lead_id' => 'integer', 'cms_contractor_id' => 'integer']);
$create('cms_company2contractor', ['id' => 'pk', 'cms_company_id' => 'integer', 'cms_contractor_id' => 'integer']);
$create('cms_contractor_map', ['id' => 'pk', 'cms_user_id' => 'integer', 'cms_contractor_id' => 'integer']);

// Contacts: normalized, masked on a public page.
mailingExpect(CmsCommContact::normalize('email', ' <Info@Romashka.RU> ') === 'info@romashka.ru' && CmsCommContact::normalize('email', 'not-an-email') === null,
    'An email is kept in lower case, a wrong one is not a contact.');
mailingExpect(CmsCommContact::normalize('phone', '8 (900) 555-00-01') === '79005550001' && CmsCommContact::normalize('phone', '12') === null
    && CmsCommContact::normalize('chat', 'x') === null, 'A phone is kept as digits with the country code; unknown kinds are not contacts yet.');
$email = MailingContacts::get(1, 'email', 'Client@Example.com');
$again = MailingContacts::get(1, 'email', 'client@example.com');
mailingExpect((int)$email->id === (int)$again->id && strlen((string)$email->code) >= 16 && $email->masked() === 'c*****@example.com',
    'One contact per site, kind and value, with a personal code; the page shows it masked.');
$phone = MailingContacts::get(1, 'phone', '+7 900 555-00-01');
mailingExpect($phone->masked() === '+7 900 ***-**-01' && $phone->display() === '+7 900 555-00-01', 'A phone is masked in the middle.');
mailingExpect(MailingContacts::get(2, 'email', 'client@example.com')->id !== $email->id, 'Another site has its own contact.');
try {
    MailingContacts::get(1, 'email', 'nope');
    mailingExpect(false, 'A wrong value must not become a contact.');
} catch (\InvalidArgumentException $e) {
}

foreach (CmsCommTopic::DEFAULTS as $i => $default) {
    (new CmsCommTopic($default + ['cms_site_id' => 1, 'is_active' => 1, 'priority' => ($i + 1) * 10]))->save(false);
}
$promo = CmsCommTopic::findOne(['name' => 'Акции и скидки']);
$news = CmsCommTopic::findOne(['name' => 'Новости студии']);
mailingExpect(count(CmsCommTopic::forSite(1)) === 4 && CmsCommTopic::addDefaults(1) === 0, 'The initial topics are added once.');

// Consent and opt-out by topic.
mailingExpect(MailingConsents::subscribe($email, (int)$promo->id, CmsCommContactTopic::BASIS_COLD, CmsCommContactTopic::SOURCE_IMPORT, 1, 'Импорт №1'),
    'A cold mark is recorded.');
mailingExpect(MailingConsents::subscribe($email, (int)$promo->id, CmsCommContactTopic::BASIS_VERBAL, CmsCommContactTopic::SOURCE_MANAGER, 1),
    'A real consent replaces a cold mark.');
mailingExpect(!MailingConsents::subscribe($email, (int)$promo->id, CmsCommContactTopic::BASIS_COLD, CmsCommContactTopic::SOURCE_IMPORT, 1)
    && MailingConsents::state($email)['topics'][(int)$promo->id]->basis === CmsCommContactTopic::BASIS_VERBAL, 'A cold mark never weakens a consent.');
mailingExpect(MailingConsents::unsubscribe($email, (int)$promo->id, CmsCommContactTopic::SOURCE_LINK, null, 77)
    && !MailingConsents::unsubscribe($email, (int)$promo->id, CmsCommContactTopic::SOURCE_LINK), 'An opt-out is recorded once.');
$state = MailingConsents::state($email)['topics'][(int)$promo->id];
mailingExpect($state->status === CmsCommContactTopic::STATUS_UNSUBSCRIBED && $state->unsubscribe_source === 'link' && (int)$state->cms_comm_message_id === 77
    && $state->basis === CmsCommContactTopic::BASIS_VERBAL, 'The opt-out knows where it came from; the consent history stays.');
mailingExpect(!MailingConsents::subscribe($email, (int)$promo->id, CmsCommContactTopic::BASIS_SITE, CmsCommContactTopic::SOURCE_IMPORT, 1)
    && !MailingConsents::state($email)['topics'][(int)$promo->id]->isSubscribed(), 'An import never undoes an opt-out of the client.');
mailingExpect(MailingConsents::subscribe($email, (int)$promo->id, CmsCommContactTopic::BASIS_VERBAL, CmsCommContactTopic::SOURCE_MANAGER, 1, 'Звонок', true)
    && MailingConsents::state($email)['topics'][(int)$promo->id]->note === 'Звонок', 'A manager may mark a new consent explicitly.');
try {
    MailingConsents::subscribe($email, 999, CmsCommContactTopic::BASIS_COLD, CmsCommContactTopic::SOURCE_IMPORT);
    mailingExpect(false, 'A topic of no site must be refused.');
} catch (\InvalidArgumentException $e) {
}

// The check of advertising: manager's mailbox (cold allowed) and a mailing service (consent only).
$cold = MailingContacts::get(1, 'email', 'cold@example.com');
MailingConsents::subscribe($cold, (int)$promo->id, CmsCommContactTopic::BASIS_COLD, CmsCommContactTopic::SOURCE_IMPORT);
$result = MailingPolicy::checkMany(1, 'email', ['client@example.com', 'COLD@example.com', 'new@example.com', 'bad'], (int)$promo->id);
mailingExpect($result['client@example.com']['allowed'] && $result['COLD@example.com']['allowed'] && $result['new@example.com']['allowed']
    && $result['bad']['reason'] === MailingPolicy::REASON_INVALID, 'A manager may write to consented, cold and unmarked contacts.');
$service = MailingPolicy::checkMany(1, 'email', ['client@example.com', 'cold@example.com', 'new@example.com'], (int)$promo->id, MailingPolicy::MODE_SERVICE);
mailingExpect($service['client@example.com']['allowed'] && $service['cold@example.com']['reason'] === MailingPolicy::REASON_NO_CONSENT
    && $service['new@example.com']['reason'] === MailingPolicy::REASON_NO_CONSENT, 'A mailing service needs a real consent.');
MailingConsents::unsubscribe($cold, (int)$promo->id, CmsCommContactTopic::SOURCE_ONE_CLICK);
mailingExpect(MailingPolicy::check(1, 'email', 'cold@example.com', (int)$promo->id)['reason'] === MailingPolicy::REASON_UNSUBSCRIBED
    && MailingPolicy::check(1, 'email', 'cold@example.com', (int)$news->id)['allowed'], 'An opt-out of a topic stops only that topic.');
mailingExpect(MailingConsents::suppress($cold, CmsCommSuppression::REASON_BOUNCE, CmsCommSuppression::SOURCE_PROVIDER)
    && MailingConsents::suppress($cold, CmsCommSuppression::REASON_ALL, CmsCommContactTopic::SOURCE_LINK)
    && !MailingConsents::suppress($cold, CmsCommSuppression::REASON_ALL, CmsCommContactTopic::SOURCE_LINK), 'The stop list keeps one row per reason.');
mailingExpect(MailingPolicy::check(1, 'email', 'cold@example.com', (int)$news->id)['reason'] === MailingPolicy::REASON_UNSUBSCRIBED_ALL,
    'The stop list stops every topic; the client\'s own word is the reason shown first.');
$news->is_active = 0;
$news->save(false);
mailingExpect(MailingPolicy::check(1, 'email', 'client@example.com', (int)$news->id)['reason'] === MailingPolicy::REASON_TOPIC
    && MailingPolicy::check(1, 'telegram', 'x', (int)$promo->id)['reason'] === MailingPolicy::REASON_INVALID, 'An inactive topic or an unknown channel sends nothing.');
$news->is_active = 1;
$news->save(false);
MailingConsents::subscribe($phone, (int)$promo->id, CmsCommContactTopic::BASIS_SITE, CmsCommContactTopic::SOURCE_LINK);
mailingExpect(MailingPolicy::check(1, 'sms', '8 900 555 00 01', (int)$promo->id, MailingPolicy::MODE_SERVICE)['allowed']
    && MailingPolicy::check(1, 'sms', '8 900 555 00 01', (int)$promo->id, MailingPolicy::MODE_SERVICE)['consent'] === CmsCommContactTopic::BASIS_SITE,
    'SMS are checked by the phone in any notation.');
$warnings = MailingPolicy::warnings(1, 'email', ['Cold@Example.com', 'client@example.com']);
mailingExpect(array_keys($warnings) === ['Cold@Example.com'] && strpos($warnings['Cold@Example.com'], 'Отписан от всех') !== false
    && strpos($warnings['Cold@Example.com'], 'не доставляются') !== false, 'A personal letter only warns about contacts on the stop list.');
mailingExpect(in_array('Отписан от всех рекламных рассылок', MailingConsents::summary($cold, CmsCommTopic::forSite(1)), true),
    'The card shows the stop list first.');

// The personal link in a campaign letter and the headers of the letter that leaves.
$url = Unsubscribe::url($email, (int)$promo->id, 15, 'https://site.test/');
mailingExpect($url === 'https://site.test/~unsub/'.$email->code.'?t='.$promo->id.'&m=15', 'The link carries the code, the topic and the letter.');
[$html, $text] = Unsubscribe::apply('<p>Акция!</p><p><a href="'.Unsubscribe::PLACEHOLDER.'">Отписаться</a></p>', 'Акция! Отписаться: '.Unsubscribe::PLACEHOLDER, $url);
mailingExpect(strpos($html, 'href="'.htmlspecialchars($url, ENT_QUOTES).'"') !== false && strpos($html, 'sx-unsubscribe.invalid') === false
    && substr_count($html, 'Отписаться') === 1 && strpos($text, $url) !== false && strpos($text, 'invalid') === false,
    'The placeholder of the template becomes the personal link where the author put it.');
[$html, $text] = Unsubscribe::apply('<p>Акция!</p>', 'Акция!', $url);
mailingExpect(strpos($html, 'Отписаться от рассылки') !== false && strpos($text, "Отписаться от рассылки: ".$url) !== false,
    'A letter without the placeholder gets the opt-out line at the end.');
mailingExpect(Unsubscribe::headers($url) === ['List-Unsubscribe' => '<'.$url.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'], 'RFC 8058 headers.');

$db->createCommand()->insert('cms_comm_account', ['id' => 1, 'cms_site_id' => 1, 'address' => 'manager@example.com', 'name' => 'Менеджер'])->execute();
$letter = new CmsCommMessage(['cms_site_id' => 1, 'cms_comm_conversation_id' => 1, 'cms_comm_account_id' => 1, 'channel' => 'email', 'direction' => 'out',
    'status' => 'queued', 'external_id' => 'sx.test@example.com', 'to_list' => '{"client@example.com":""}', 'subject' => 'Акция', 'body_text' => 'Акция',
    'message_at' => 1800000000, 'cms_comm_topic_id' => (int)$promo->id, 'unsubscribe_url' => $url]);
$letter->save(false);
$headers = (new MailSendJobHandler())->buildEmail($letter)->getHeaders();
mailingExpect($headers->get('List-Unsubscribe') && $headers->get('List-Unsubscribe')->getBodyAsString() === '<'.$url.'>'
    && $headers->get('List-Unsubscribe-Post')->getBodyAsString() === 'List-Unsubscribe=One-Click', 'An advertising letter leaves with the opt-out headers.');
$personal = new CmsCommMessage($letter->getAttributes(null, ['id', 'cms_comm_topic_id', 'unsubscribe_url']));
$personal->save(false);
mailingExpect(!(new MailSendJobHandler())->buildEmail($personal)->getHeaders()->has('List-Unsubscribe'), 'A personal letter has no opt-out headers.');

// The page and one click.
mailingExpect(Unsubscribe::contactByCode((string)$email->code)->id === $email->id && Unsubscribe::contactByCode('short') === null
    && Unsubscribe::topicOf($email, $promo->id)->id === $promo->id && Unsubscribe::topicOf($email, 999) === null, 'The code and the topic of a link.');
mailingExpect(Unsubscribe::messageOf($email, $letter->id) === (int)$letter->id && Unsubscribe::messageOf($cold, $letter->id) === null,
    'A letter counts only if it went to this contact.');
Unsubscribe::oneClick($email, $promo, (int)$letter->id);
mailingExpect(MailingConsents::state($email)['topics'][(int)$promo->id]->unsubscribe_source === CmsCommContactTopic::SOURCE_ONE_CLICK,
    'One click opts out of the topic of the letter.');
$fresh = MailingContacts::get(1, 'email', 'fresh@example.com');
Unsubscribe::oneClick($fresh, null, null);
mailingExpect(isset(MailingConsents::state($fresh)['suppressions'][CmsCommSuppression::REASON_ALL]), 'One click without a topic opts out of all advertising.');
$message = Unsubscribe::save($fresh, [(int)$news->id], false, false, null);
$freshState = MailingConsents::state($fresh);
mailingExpect(!isset($freshState['suppressions'][CmsCommSuppression::REASON_ALL]) && !isset($freshState['topics'][(int)$news->id])
    && $freshState['topics'][(int)$promo->id]->status === CmsCommContactTopic::STATUS_UNSUBSCRIBED && $message !== '',
    'Checking a topic again lifts "отписан от всех"; unchecked topics are opted out; unmarked checked ones stay unmarked.');
Unsubscribe::save($fresh, [(int)$promo->id], false, false, null);
mailingExpect(MailingConsents::state($fresh)['topics'][(int)$promo->id]->basis === CmsCommContactTopic::BASIS_SITE, 'The client subscribed again on the site.');

// Imported tables: encodings, separators, quotes; columns by their headers; values of a row.
$rows = LeadImport::parse(mb_convert_encoding("Компания;E-mail;Телефон\r\n\"ООО \"\"Ромашка\"\"; филиал\";A@B.RU, c@d.ru;8 900 555 00 01\r\n\r\n;;\r\n", 'Windows-1251', 'UTF-8'));
mailingExpect(count($rows) === 2 && $rows[1][0] === 'ООО "Ромашка"; филиал' && $rows[1][1] === 'A@B.RU, c@d.ru', 'A Windows CSV with semicolons and quotes.');
$rows = LeadImport::parse("\xEF\xBB\xBFИмя\tПочта\nИван\tivan@example.com\n");
mailingExpect(count($rows) === 2 && $rows[0] === ['Имя', 'Почта'] && $rows[1][1] === 'ivan@example.com', 'A table pasted from Excel (tabs, UTF-8 with BOM).');
mailingExpect(LeadImport::guessField('E-mail') === 'email' && LeadImport::guessField('Телефон 2') === 'phone' && LeadImport::guessField('ИНН') === 'inn'
    && LeadImport::guessField('Название организации') === 'company' && LeadImport::guessField('ФИО') === 'name' && LeadImport::guessField('Сайт') === 'website'
    && LeadImport::guessField('Что-то') === '', 'Columns are recognized by their headers.');
$import = new \skeeks\cms\communication\models\CmsCommImport(['columns' => '["Компания","Почта","Телефон","Город"]', 'mapping' => '["company","email","phone",""]']);
$data = LeadImport::rowData($import, ['ООО Ромашка', 'A@B.ru; nope, c@d.ru', '8 900 555-00-01 / 8 900 555-00-02', 'Тула']);
mailingExpect($data['company'] === 'ООО Ромашка' && $data['emails'] === ['a@b.ru', 'c@d.ru'] && $data['bad'] === ['nope']
    && $data['phones'] === ['79005550001', '79005550002'] && $data['values']['Город'] === 'Тула', 'One cell may hold several contacts; wrong ones are reported.');

// Duplicates in the CRM: email, phone in any notation, INN, name without quotes; the strongest evidence wins.
$db->createCommand()->batchInsert('cms_lead', ['id', 'cms_site_id', 'name'], [[1, 1, 'ООО «Ромашка»'], [2, 2, 'Чужой сайт']])->execute();
$db->createCommand()->insert('cms_lead_email', ['cms_lead_id' => 2, 'value' => 'other@example.com'])->execute();
$db->createCommand()->insert('cms_company', ['id' => 5, 'name' => 'Василёк'])->execute();
$db->createCommand()->insert('cms_company_phone', ['cms_company_id' => 5, 'value' => '+7 (900) 555-00-09'])->execute();
$db->createCommand()->insert('cms_company_email', ['cms_company_id' => 5, 'value' => 'Office@Vasilek.ru'])->execute();
$db->createCommand()->insert('cms_user', ['id' => 7, 'is_worker' => 0])->execute();
$db->createCommand()->insert('cms_user', ['id' => 8, 'is_worker' => 1])->execute();
$db->createCommand()->insert('cms_user_email', ['cms_site_id' => 1, 'cms_user_id' => 7, 'value' => 'person@example.com'])->execute();
$db->createCommand()->insert('cms_user_email', ['cms_site_id' => 1, 'cms_user_id' => 8, 'value' => 'worker@example.com'])->execute();
$db->createCommand()->insert('cms_contractor', ['id' => 3, 'inn' => '7701234567'])->execute();
$db->createCommand()->insert('cms_company2contractor', ['cms_company_id' => 5, 'cms_contractor_id' => 3])->execute();
$found = (new CrmDuplicates(1))->findMany([
    'a' => ['emails' => ['office@vasilek.ru'], 'phones' => ['79005550009']],
    'b' => ['emails' => ['x@example.com'], 'phones' => ['89005550009']],
    'c' => ['emails' => [], 'phones' => [], 'inn' => '7701 234 567'],
    'd' => ['emails' => [], 'phones' => [], 'name' => 'ооо  "Ромашка"'],
    'e' => ['emails' => ['PERSON@example.com']],
    'f' => ['emails' => ['worker@example.com', 'other@example.com']],
]);
mailingExpect($found['a'] === ['type' => CrmRecords::COMPANY, 'id' => 5, 'by' => 'email', 'value' => 'office@vasilek.ru'], 'An email match comes first.');
mailingExpect($found['b']['by'] === 'phone' && $found['b']['id'] === 5 && $found['c']['by'] === 'inn' && $found['c']['type'] === CrmRecords::COMPANY,
    'A phone in another notation and an INN with spaces match.');
mailingExpect($found['d'] === ['type' => CrmRecords::LEAD, 'id' => 1, 'by' => 'name', 'value' => 'ооо  "Ромашка"'], 'A name matches without quotes and extra spaces.');
mailingExpect($found['e']['type'] === CrmRecords::CLIENT && !isset($found['f']), 'Clients match; employees and leads of another site do not.');

echo "OK: mailing\n";
