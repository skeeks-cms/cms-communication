<?php
/**
 * Lists of mailing recipients, stage 4D step 1, on an isolated SQLite memory
 * database: adding records (lead, company, client; not an employee), the
 * history of a member (who, when, from where), removing and bringing back,
 * many at once; addresses of many records as one record gives them;
 * companies of records; counters (stop list, no email); the CSV; the
 * campaign audience "Списки" with an excluding list (a company in it
 * excludes its people and leads); the rules of every mailing — the frequency
 * per contact over all campaigns, "не мешать менеджеру" (a letter or a chat
 * in the feed, not a mailing letter), one letter per company (the recipient
 * added one by one, then the company, then the first of its people); the
 * audience of the campaign form. No site schema, no network.
 *
 * Usage: php tests/lists.php [vendor/autoload.php]
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

use skeeks\cms\communication\campaign\MailboxCampaignChannel;
use skeeks\cms\communication\CommunicationComponent;
use skeeks\cms\communication\components\MailingSettings;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommListMember;
use skeeks\cms\communication\models\CmsCommListRule;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\communication\services\ListRules;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\communication\services\RecipientLists;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;

function listsExpect($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Every employee of the test is an administrator: forManager() sees everything. */
class ListsTestAuth extends \yii\base\Component
{
    public function checkAccess($userId, $permission, $params = [])
    {
        return true;
    }
}

class ListsTestJobs extends \yii\base\Component
{
    public function push($type, array $payload = [], array $options = [])
    {
        return null;
    }
}

new \yii\console\Application([
    'id' => 'cms-communication-lists',
    'basePath' => sys_get_temp_dir(),
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'formatter' => ['class' => \yii\i18n\Formatter::class, 'timeZone' => 'Europe/Moscow'],
        // Validation messages of core models: the message itself.
        'i18n' => ['translations' => ['skeeks/*' => ['class' => \yii\i18n\PhpMessageSource::class, 'basePath' => sys_get_temp_dir()]]],
        'skeeks' => ['class' => \skeeks\cms\Skeeks::class],
        'jobs' => ['class' => ListsTestJobs::class],
        'authManager' => ['class' => ListsTestAuth::class],
        'communication' => [
            'class' => CommunicationComponent::class,
            'campaignChannels' => ['mailbox' => MailboxCampaignChannel::class],
        ],
    ],
]);

$db = Yii::$app->db;
$create = static fn(string $table, array $columns) => $db->createCommand()->createTable($table, $columns)->execute();
$create('cms_comm_topic', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'name' => 'string', 'description' => 'text', 'is_active' => 'integer default 1', 'priority' => 'integer default 100']);
$create('cms_comm_contact', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'kind' => 'string', 'value' => 'string', 'code' => 'string']);
$create('cms_comm_contact_topic', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_contact_id' => 'integer', 'cms_comm_topic_id' => 'integer',
    'status' => 'string', 'basis' => 'string', 'consent_at' => 'integer', 'consent_by' => 'integer', 'unsubscribed_at' => 'integer', 'unsubscribed_by' => 'integer',
    'unsubscribe_source' => 'string', 'cms_comm_message_id' => 'integer', 'note' => 'string']);
$create('cms_comm_suppression', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_contact_id' => 'integer',
    'reason' => 'string', 'source' => 'string', 'cms_comm_message_id' => 'integer', 'note' => 'string']);
$create('cms_comm_account', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'cms_comm_connection_id' => 'integer', 'channel' => 'string', 'address' => 'string', 'name' => 'string', 'reply_to' => 'string',
    'is_active' => 'integer default 1', 'is_expose_linked' => 'integer default 1', 'mailing_settings' => 'text']);
$create('cms_comm_account_access', ['id' => 'pk', 'cms_comm_account_id' => 'integer', 'cms_user_id' => 'integer', 'read_level' => 'string', 'send_level' => 'string',
    'is_default' => 'integer default 0']);
$create('cms_comm_message', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer',
    'cms_comm_account_id' => 'integer', 'channel' => 'string', 'direction' => 'string', 'status' => 'string', 'external_id' => 'string', 'cms_comm_topic_id' => 'integer',
    'cms_comm_campaign_id' => 'integer', 'is_internal' => 'integer default 0', 'message_at' => 'integer', 'to_list' => 'text', 'subject' => 'string']);
$create('cms_comm_link', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_message_id' => 'integer', 'model_code' => 'string',
    'model_id' => 'integer', 'link_type' => 'string', 'cms_log_id' => 'integer']);
$create('cms_comm_conversation_link', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_conversation_id' => 'integer',
    'model_code' => 'string', 'model_id' => 'integer']);
$create('cms_comm_campaign', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'name' => 'string', 'status' => 'string', 'cms_comm_topic_id' => 'integer', 'audience_type' => 'string', 'audience' => 'text',
    'channel' => 'string', 'sender_mode' => 'string', 'cms_comm_account_id' => 'integer', 'subject' => 'string', 'body_html' => 'text', 'offer_kind' => 'string',
    'offer_type' => 'string', 'offer_id' => 'integer', 'task_on_open' => 'integer default 1', 'task_on_reply' => 'integer default 1', 'one_per_company' => 'integer default 0',
    'quiet_days' => 'integer', 'launched_at' => 'integer', 'launched_by' => 'integer', 'finished_at' => 'integer']);
$create('cms_comm_campaign_recipient', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_campaign_id' => 'integer',
    'record_type' => 'string', 'record_id' => 'integer', 'address' => 'string', 'name' => 'string', 'subject' => 'string', 'body_html' => 'text',
    'text_by' => 'string', 'text_client' => 'string', 'text_user_id' => 'integer', 'text_at' => 'integer', 'text_edited_by' => 'integer', 'cms_comm_account_id' => 'integer',
    'sender_user_id' => 'integer', 'status' => 'string', 'reason' => 'string', 'error' => 'string', 'cms_comm_message_id' => 'integer', 'send_ref' => 'string',
    'sent_at' => 'integer', 'opened_at' => 'integer', 'replied_at' => 'integer', 'accepted_at' => 'integer', 'unsubscribed_at' => 'integer']);
$create('cms_comm_list', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'name' => 'string', 'description' => 'text']);
$create('cms_comm_list_member', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_list_id' => 'integer', 'record_type' => 'string',
    'record_id' => 'integer', 'added_by' => 'integer', 'added_via' => 'string', 'added_ref' => 'string', 'removed_at' => 'integer', 'removed_by' => 'integer']);
$create('cms_comm_list_rule', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_list_id' => 'integer', 'section' => 'string',
    'filter_values' => 'text', 'title' => 'string', 'user_id' => 'integer', 'is_active' => 'integer default 1', 'last_run_at' => 'integer', 'last_found' => 'integer',
    'last_added' => 'integer', 'added_total' => 'integer default 0', 'last_error' => 'string']);
$db->createCommand()->createIndex('member_unique', 'cms_comm_list_member', ['cms_comm_list_id', 'record_type', 'record_id'], true)->execute();
$create('cms_lead', ['id' => 'pk', 'cms_site_id' => 'integer', 'name' => 'string', 'status' => 'string', 'source_type' => 'string', 'executor_id' => 'integer',
    'cms_company_id' => 'integer', 'cms_user_id' => 'integer']);
$create('cms_company', ['id' => 'pk', 'name' => 'string']);
$create('cms_lead_email', ['id' => 'pk', 'cms_lead_id' => 'integer', 'value' => 'string', 'name' => 'string', 'sort' => 'integer default 100']);
$create('cms_company_email', ['id' => 'pk', 'cms_company_id' => 'integer', 'value' => 'string', 'name' => 'string', 'sort' => 'integer default 100']);
$create('cms_company2manager', ['id' => 'pk', 'cms_company_id' => 'integer', 'cms_user_id' => 'integer']);
$create('cms_company2user', ['id' => 'pk', 'cms_company_id' => 'integer', 'cms_user_id' => 'integer', 'sort' => 'integer default 100']);
$create('cms_user2manager', ['id' => 'pk', 'client_id' => 'integer', 'worker_id' => 'integer']);
$create('cms_user_email', ['id' => 'pk', 'cms_site_id' => 'integer', 'cms_user_id' => 'integer', 'value' => 'string']);
$create('cms_user', ['id' => 'pk', 'username' => 'string', 'first_name' => 'string', 'last_name' => 'string', 'patronymic' => 'string', 'is_company' => 'integer default 0',
    'company_name' => 'string', 'alias' => 'string', 'email' => 'string', 'phone' => 'string', 'image_id' => 'integer', 'work_shedule' => 'text', 'is_worker' => 'integer',
    'is_active' => 'integer default 1']);

// The CRM: manager 7 with a mailbox; company 15 «Плитка-Лаб» with its email and person Ольга (client 30),
// its lead 3; company 16 «Действующий» with lead 4; leads 1, 2 without a company; lead 5 without email.
$insert = static fn(string $table, array $columns, array $rows) => $db->createCommand()->batchInsert($table, $columns, $rows)->execute();
$insert('cms_user', ['id', 'username', 'first_name', 'is_worker'], [[7, 'manager', 'Анна', 1], [30, 'olga', 'Ольга', 0], [31, 'petr', 'Пётр', 0]]);
$insert('cms_company', ['id', 'name'], [[15, 'Плитка-Лаб'], [16, 'Действующий']]);
$insert('cms_company_email', ['cms_company_id', 'value'], [[15, 'info@plitka.test'], [16, 'info@client.test']]);
$insert('cms_company2user', ['cms_company_id', 'cms_user_id'], [[15, 30]]);
$insert('cms_user_email', ['cms_user_id', 'value'], [[30, 'olga@plitka.test'], [31, 'petr@example.test']]);
$insert('cms_company2manager', ['cms_company_id', 'cms_user_id'], [[15, 7], [16, 7]]);
$insert('cms_user2manager', ['client_id', 'worker_id'], [[30, 7], [31, 7]]);
$insert('cms_lead', ['id', 'cms_site_id', 'name', 'status', 'executor_id', 'cms_company_id'], [
    [1, 1, 'Лид 1', 'new', 7, null], [2, 1, 'Лид 2', 'new', 7, null], [3, 1, 'Плитка-Лаб — Иван', 'new', 7, 15], [4, 1, 'Действующий — Олег', 'new', 7, 16],
    [5, 1, 'Без почты', 'new', 7, null],
]);
$insert('cms_lead_email', ['cms_lead_id', 'value', 'name'], [[1, 'one@example.test', 'Иван'], [2, 'two@example.test', null], [3, 'ivan@plitka.test', 'Иван'],
    [4, 'oleg@client.test', 'Олег']]);
$account = new CmsCommAccount(['cms_site_id' => 1, 'channel' => 'email', 'address' => 'manager@example.test', 'cms_comm_connection_id' => 5, 'is_active' => 1]);
$account->save(false);
(new CmsCommAccountAccess(['cms_comm_account_id' => $account->id, 'cms_user_id' => 7, 'read_level' => 'all', 'send_level' => 'any']))->save(false);
$topic = new CmsCommTopic(['cms_site_id' => 1, 'name' => 'Новости', 'is_active' => 1]);
$topic->save(false);

$employee = CmsUser::findOne(7);
$lists = new RecipientLists();
$company = CmsCompany::findOne(15);
$olga = CmsUser::findOne(30);

// A list: records of the CRM with the history of each member.
$plitka = $lists->create(1, 'Плитка без оплат', $employee, 'Компании сферы Плитка');
listsExpect($plitka->id && $plitka->name === 'Плитка без оплат' && (int)$plitka->created_by === 7, 'A list is made with its author.');
try {
    $lists->create(1, '   ', $employee);
    listsExpect(false, 'A list needs a name.');
} catch (\DomainException $e) {
}
$member = $lists->add($plitka, $company, $employee, CmsCommListMember::VIA_SECTION, 'companies');
listsExpect($member->record_type === 'company' && (int)$member->record_id === 15 && (int)$member->added_by === 7 && $member->getViaText() === 'из раздела «Компании»',
    'A company is a member with who and from where.');
listsExpect((int)$lists->add($plitka, $company, $employee)->id === (int)$member->id, 'Adding again keeps the member and its history.');
try {
    $lists->add($plitka, $employee, $employee);
    listsExpect(false, 'An employee is never a member.');
} catch (\DomainException $e) {
}
$result = $lists->addMany($plitka, [CmsLead::findOne(1), CmsLead::findOne(3), CmsLead::findOne(5), $olga, $company, $employee], $employee,
    CmsCommListMember::VIA_IMPORT, 'import:7');
listsExpect($result['added'] === 4 && $result['already'] === 1 && count($result['refused']) === 1, 'Many at once: new, already there, refused with the reason.');
listsExpect(CmsCommListMember::findOne(['record_type' => 'lead', 'record_id' => 1])->getViaText() === 'импорт №7', 'An import member says which import.');
listsExpect(array_keys(RecipientLists::members([$plitka->id])) === ['company:15', 'lead:1', 'lead:3', 'lead:5', 'client:30'], 'Members once each, in the order added.');
listsExpect(array_map(static fn($l) => $l->name, RecipientLists::listsOf(CmsLead::findOne(1), 1)) === ['Плитка без оплат'], 'The card knows the lists of a record.');

$lead5 = CmsCommListMember::findOne(['cms_comm_list_id' => $plitka->id, 'record_type' => 'lead', 'record_id' => 5]);
listsExpect($lists->remove($lead5, $employee) && !$lists->remove(CmsCommListMember::findOne($lead5->id), $employee), 'A member is removed once.');
listsExpect(!isset(RecipientLists::members([$plitka->id])['lead:5']) && CmsCommListMember::findOne($lead5->id)->removed_at, 'A removed member stays as history.');
$back = $lists->add($plitka, CmsLead::findOne(5), $employee);
listsExpect((int)$back->id === (int)$lead5->id && !$back->removed_at && $back->added_via === CmsCommListMember::VIA_MANUAL, 'Adding a removed member brings it back, by hand now.');

// Addresses of many records as Campaigns::recordAddresses() gives them for one; companies of records.
$many = RecipientLists::addresses('company', [15, 16]);
listsExpect($many['addresses'][15] === Campaigns::recordAddresses($company) && $many['addresses'][15] === ['info@plitka.test' => '', 'olga@plitka.test' => 'Ольга'],
    'A company: its emails, then those of its people, with names.');
listsExpect(RecipientLists::addresses('lead', [1, 5])['addresses'] === [1 => ['one@example.test' => 'Иван']], 'A lead without email has no address.');
$companies = RecipientLists::companies(['lead:3' => ['lead', 3], 'client:30' => ['client', 30], 'company:16' => ['company', 16], 'lead:1' => ['lead', 1]]);
listsExpect($companies === ['lead:3' => [15], 'client:30' => [15], 'company:16' => [16], 'lead:1' => []], 'A lead\'s company, a person\'s companies, a company itself.');

// Counters: a contact opted out of everything, a member without email.
MailingConsents::suppress(MailingContacts::get(1, 'email', 'one@example.test'), \skeeks\cms\communication\models\CmsCommSuppression::REASON_ALL, 'manager');
$stats = RecipientLists::stats($plitka);
listsExpect($stats['members'] === 5 && $stats['email'] === 3 && $stats['stopped'] === 1 && $stats['noEmail'] === 1
    && $stats['byType'] === ['company' => 1, 'lead' => 3, 'client' => 1], 'Counters: may get a letter, on the stop list, without email.');
$csv = iterator_to_array(RecipientLists::exportRows($plitka), false);
listsExpect(count($csv) === 6 && $csv[0][3] === 'Email' && $csv[1][0] === 'Компания' && $csv[1][3] === 'info@plitka.test' && $csv[2][5] === 'да',
    'The CSV: a header and a row per member with its email and the stop list mark.');

// The campaign audience "Списки": an excluding list of a company excludes its people and leads.
$active = $lists->create(1, 'Действующие клиенты', $employee);
$lists->add($active, CmsCompany::findOne(16), $employee);
$second = $lists->create(1, 'Ещё лиды', $employee);
$lists->addMany($second, [CmsLead::findOne(2), CmsLead::findOne(4), CmsLead::findOne(1)], $employee, CmsCommListMember::VIA_MCP, 'mcp:claude');
listsExpect(CmsCommListMember::findOne(['cms_comm_list_id' => $second->id, 'record_id' => 2])->getViaText() === 'ИИ (claude)', 'A member added by AI says through which client.');

$campaign = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Плитка', 'cms_comm_topic_id' => $topic->id, 'subject' => 'Тема', 'audience_type' => CmsCommCampaign::AUDIENCE_LISTS]);
listsExpect((int)$campaign->one_per_company === 1 && (int)$campaign->quiet_days === CmsCommCampaign::QUIET_DAYS_DEFAULT, 'A new campaign protects by default.');
$campaign->audienceLists = [$plitka->id, $second->id];
$campaign->audienceExcludeLists = [$active->id];
$campaign->one_per_company = 0;
$campaign->quiet_days = 0;
$campaign->save(false);
$campaign = CmsCommCampaign::findOne($campaign->id);
listsExpect($campaign->getAudienceArray() === ['lists' => [$plitka->id, $second->id], 'exclude_lists' => [$active->id]]
    && $campaign->audienceLists === [$plitka->id, $second->id] && $campaign->audienceExcludeLists === [$active->id], 'Lists and excluding lists are kept in the audience.');
$campaign->audienceExcludeLists = [$plitka->id];
listsExpect(!$campaign->validate(['audienceExcludeLists']), 'A list cannot be in the audience and among the excluded ones.');
$campaign->audienceExcludeLists = [$active->id];

$service = new Campaigns();
MailingSettings::reset(1, 0);
$byKey = static function (array $recipients): array {
    $result = [];
    foreach ($recipients as $r) {
        $result[$r->record_type.':'.$r->record_id] = $r;
    }

    return $result;
};
$r = $byKey($service->candidates($campaign, $employee));
listsExpect(array_keys($r) === ['company:15', 'lead:1', 'lead:3', 'lead:5', 'client:30', 'lead:2', 'lead:4'], 'Members of both lists, once each.');
listsExpect($r['company:15']->status === 'pending' && $r['company:15']->address === 'info@plitka.test' && (int)$r['company:15']->sender_user_id === 7,
    'A company writes to its first email from its manager\'s mailbox.');
listsExpect($r['client:30']->address === 'olga@plitka.test' && $r['client:30']->name === 'Ольга' && $r['lead:3']->name === 'Иван', 'People get their names.');
listsExpect($r['lead:4']->reason === CmsCommCampaignRecipient::REASON_EXCLUDED_LIST, 'The lead of a company in the excluding list is excluded.');
listsExpect($r['lead:1']->reason === \skeeks\cms\communication\services\MailingPolicy::REASON_UNSUBSCRIBED_ALL && $r['lead:5']->reason === 'invalid',
    'The stop list and no email still exclude, with their reasons.');

// The frequency per contact: a letter of another campaign three days ago, a letter waiting in a running one.
$other = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Прошлая', 'status' => CmsCommCampaign::STATUS_DONE, 'cms_comm_topic_id' => $topic->id]);
$other->save(false);
(new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $other->id, 'record_type' => 'lead', 'record_id' => 3, 'address' => 'ivan@plitka.test',
    'status' => 'sent', 'sent_at' => time() - 3 * 86400]))->save(false);
(new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $other->id, 'record_type' => 'lead', 'record_id' => 2, 'address' => 'two@example.test',
    'status' => 'sent', 'sent_at' => time() - 30 * 86400]))->save(false);
$running = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Идёт', 'status' => CmsCommCampaign::STATUS_RUNNING, 'cms_comm_topic_id' => $topic->id]);
$running->save(false);
(new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $running->id, 'record_type' => 'client', 'record_id' => 30, 'address' => 'olga@plitka.test',
    'status' => 'pending']))->save(false);
MailingSettings::reset(1, 7);
$r = $byKey($service->candidates($campaign, $employee));
listsExpect($r['lead:3']->reason === CmsCommCampaignRecipient::REASON_RECENT && $r['client:30']->reason === CmsCommCampaignRecipient::REASON_RECENT
    && $r['lead:2']->status === 'pending', 'A letter in 7 days or waiting in a running campaign excludes; a month ago does not.');
listsExpect(Campaigns::recentAddresses(1, ['olga@plitka.test', 'ivan@plitka.test'], (int)$campaign->id, time() - 7 * 86400, false) === ['ivan@plitka.test' => true],
    'Before a letter only letters that left or are leaving count.');
MailingSettings::reset(1, 0);

// "Не мешать менеджеру": a letter in the lead's feed two days ago; a mailing letter does not count; a chat of the company does.
$message = static function (array $attributes) {
    $m = new CmsCommMessage($attributes + ['cms_site_id' => 1, 'cms_comm_conversation_id' => 900, 'channel' => 'email', 'direction' => 'in', 'status' => 'received',
            'message_at' => time() - 2 * 86400, 'to_list' => '{}']);
    $m->save(false);

    return $m;
};
$feed = $message([]);
$db->createCommand()->insert('cms_comm_link', ['cms_comm_message_id' => $feed->id, 'model_code' => CmsLead::class, 'model_id' => 2])->execute();
$mailing = $message(['cms_comm_campaign_id' => $other->id, 'direction' => 'out', 'status' => 'sent']);
$db->createCommand()->insert('cms_comm_link', ['cms_comm_message_id' => $mailing->id, 'model_code' => CmsLead::class, 'model_id' => 1])->execute();
$message(['cms_comm_conversation_id' => 901, 'channel' => 'telegram']);
$db->createCommand()->insert('cms_comm_conversation_link', ['cms_comm_conversation_id' => 901, 'model_code' => CmsCompany::class, 'model_id' => 15])->execute();
$campaign->quiet_days = 14;
$r = $byKey($service->candidates($campaign, $employee));
listsExpect($r['lead:2']->reason === CmsCommCampaignRecipient::REASON_QUIET && $r['company:15']->reason === CmsCommCampaignRecipient::REASON_QUIET
    && $r['lead:3']->reason === CmsCommCampaignRecipient::REASON_QUIET && $r['client:30']->reason === CmsCommCampaignRecipient::REASON_QUIET,
    'A letter of the lead, a chat of the company: the lead, the company, its lead and its person are left to the manager.');
$campaign->quiet_days = 1;
listsExpect($byKey($service->candidates($campaign, $employee))['lead:2']->status === 'pending', 'Older talks than the quiet days do not count.');
$campaign->quiet_days = 0;
$db->createCommand()->delete('cms_comm_conversation_link')->execute();

// One letter per company: the company itself before its lead and its person; a recipient added one by one before them all.
$campaign->one_per_company = 1;
$r = $byKey($service->candidates($campaign, $employee));
listsExpect($r['company:15']->status === 'pending' && $r['lead:3']->reason === CmsCommCampaignRecipient::REASON_SAME_COMPANY
    && $r['client:30']->reason === CmsCommCampaignRecipient::REASON_SAME_COMPANY && $r['lead:2']->status === 'pending',
    'The company gets the letter; its lead and its person do not; records without a company are not touched.');
$summary = Campaigns::summarize(array_values($r));
listsExpect(($summary['reasons'][CmsCommCampaignRecipient::REASON_SAME_COMPANY] ?? 0) === 2, 'The summary counts "Письмо получит другой контакт этой компании".');
$service->addRecipient($campaign, $olga, $employee, ['address' => 'olga@plitka.test']);
$r = $byKey($service->candidates($campaign, $employee));
listsExpect($r['client:30']->status === 'pending' && $r['company:15']->reason === CmsCommCampaignRecipient::REASON_SAME_COMPANY,
    'A contact chosen by hand wins over the company itself.');
$campaign->one_per_company = 0;
$r = $byKey($service->candidates($campaign, $employee));
listsExpect($r['company:15']->status === 'pending' && $r['lead:3']->status === 'pending' && $r['client:30']->status === 'pending', 'Switched off — everybody.');

// The excluding list works for any audience: leads by a filter.
$filter = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Фильтр', 'cms_comm_topic_id' => $topic->id, 'subject' => 'Тема',
    'audience_type' => CmsCommCampaign::AUDIENCE_LEADS, 'one_per_company' => 0, 'quiet_days' => 0]);
$filter->audienceExcludeLists = [$active->id, $second->id];
$filter->save(false);
$filter = CmsCommCampaign::findOne($filter->id);
listsExpect($filter->getAudienceArray() === ['exclude_lists' => [$active->id, $second->id]], 'A filter audience keeps its excluding lists.');
$r = $byKey($service->candidates($filter, $employee));
listsExpect($r['lead:4']->reason === CmsCommCampaignRecipient::REASON_EXCLUDED_LIST && $r['lead:2']->reason === CmsCommCampaignRecipient::REASON_EXCLUDED_LIST
    && $r['lead:3']->status === 'pending', 'Members of excluding lists are excluded from leads by a filter too.');
// Rules (step 2): the filter of a section page, adding only what never was in the list.
listsExpect(ListRules::clean(['q' => ' ', 'categories' => ['39', ''], 'custom' => 'no_payments', 'status' => null, 'x' => []])
    === ['categories' => ['39'], 'custom' => 'no_payments'], 'Empty filter values are no filter.');
listsExpect(ListRules::valuesFromPage('?sx-bs=&findex%5Bq%5D=&findex%5Bcategories%5D%5B0%5D=39&findex%5Bcustom%5D=no_payments&page=2')
    === ['categories' => ['39'], 'custom' => 'no_payments'], 'The filter is read from the address of the section page.');
listsExpect(ListRules::valuesFromPage('?findex%5Bstatus%5D%5Bmode%5D=eq&findex%5Bstatus%5D%5Bvalue%5D%5B0%5D%5B%5D=new&findex%5Bstatus%5D%5Bvalue%5D%5B1%5D='
    .'&findex%5Bcms_lead_stage_id%5D%5Bmode%5D=eq&findex%5Bcms_lead_stage_id%5D%5Bvalue%5D%5B1%5D=&findex%5Bnext_step%5D%5Bmode%5D=empty')
    === ['next_step' => ['mode' => 'empty', 'value' => []], 'status' => ['mode' => 'eq', 'value' => [['new'], '']]],
    'A field with a mode keeps the page shape; a mode without a value is no filter, except empty and not empty.');

$tile = $lists->create(1, 'Плиточные сайты', $employee);
$lists->add($tile, CmsLead::findOne(2), $employee);
$lists->remove(CmsCommListMember::findOne(['cms_comm_list_id' => $tile->id, 'record_id' => 2]), $employee);
$rule = new CmsCommListRule(['cms_comm_list_id' => $tile->id, 'section' => 'leads', 'filter_values' => '{"status":["new"]}', 'title' => 'Лиды: новые',
    'user_id' => 7, 'is_active' => 1]);
$rule->save(false);
$rules = new ListRules();
$found = [1, 2, 3];
listsExpect($rules->run($rule, static fn() => $found) === 2 && (int)$rule->last_found === 3 && (int)$rule->last_added === 2 && !$rule->last_error,
    'A rule adds what it finds; a lead removed by hand is not added back.');
listsExpect(array_keys(RecipientLists::members([$tile->id])) === ['lead:1', 'lead:3']
    && CmsCommListMember::findOne(['cms_comm_list_id' => $tile->id, 'record_id' => 3])->getViaText() === 'по правилу №'.(int)$rule->id,
    'Members of a rule say which rule added them.');
$found = [1, 3, 4];
listsExpect($rules->run($rule, static fn() => $found) === 1 && (int)$rule->added_total === 3, 'The next count adds only the new record.');
listsExpect(isset(RecipientLists::members([$tile->id])['lead:3']), 'A record that stops matching stays in the list.');
$rules->run($rule, static function () {
    throw new \DomainException('Раздел недоступен.');
});
listsExpect($rule->last_error === 'Раздел недоступен.' && (int)$rule->last_added === 0, 'An error stays on the rule.');
$db->createCommand()->update('cms_user', ['is_active' => 0], ['id' => 7])->execute();
listsExpect($rules->run($rule, static fn() => [5]) === 0 && strpos((string)$rule->last_error, 'отключён') !== false, 'A rule of a dismissed employee stops.');
$db->createCommand()->update('cms_user', ['is_active' => 1], ['id' => 7])->execute();


echo "lists: OK\n";
