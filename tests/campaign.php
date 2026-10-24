<?php
/**
 * Mailing campaigns, stage 4B, on an isolated SQLite memory database: the
 * pace of a mailbox (daily limit with the manager's own letters, pauses,
 * working hours in the CRM time zone, the next working day), placeholders
 * of the letter, the campaign form values, the sender of each recipient
 * (the responsible employee's mailbox), the order of waiting letters,
 * pause, resume, cancel and the end of a campaign, the report (failed
 * letters, replies, opt-outs after the letter), totals with percents, the
 * campaign setting of the "opened" task and replies found by the receive.
 * No site schema, no network.
 *
 * Usage: php tests/campaign.php [vendor/autoload.php]
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
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\communication\services\MailboxPace;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\communication\services\MailingPolicy;

function campaignExpect($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Every employee of the test is an administrator: forManager() sees everything. */
class CampaignTestAuth extends \yii\base\Component
{
    public function checkAccess($userId, $permission, $params = [])
    {
        return true;
    }
}

/** Records pushes instead of a queue. */
class CampaignTestJobs extends \yii\base\Component
{
    public array $pushed = [];

    public function push($type, array $payload = [], array $options = [])
    {
        $this->pushed[] = [$type, $payload, $options];

        return null;
    }
}

new \yii\console\Application([
    'id' => 'cms-communication-campaign',
    'basePath' => sys_get_temp_dir(),
    // A console worker in UTC: days and hours must still be those of the CRM.
    'timeZone' => 'UTC',
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'formatter' => ['class' => \yii\i18n\Formatter::class, 'timeZone' => 'Europe/Moscow'],
        'skeeks' => ['class' => \skeeks\cms\Skeeks::class],
        'jobs' => ['class' => CampaignTestJobs::class],
        'authManager' => ['class' => CampaignTestAuth::class],
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
    'cms_comm_campaign_id' => 'integer', 'provider_ref' => 'string', 'unsubscribe_url' => 'string', 'in_reply_to' => 'string', 'references_list' => 'text',
    'from_address' => 'string', 'from_name' => 'string', 'to_list' => 'text', 'cc_list' => 'text', 'bcc_list' => 'text', 'subject' => 'string', 'body_text' => 'text',
    'body_html' => 'text', 'attachments' => 'text', 'is_internal' => 'integer default 0', 'is_auto' => 'integer default 0', 'is_history' => 'integer default 0',
    'box_state' => 'string', 'message_at' => 'integer', 'sent_by_user_id' => 'integer', 'error' => 'text']);
$create('cms_comm_message_search', ['cms_comm_message_id' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer', 'search_text' => 'text']);
$create('cms_comm_message_mailbox', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_message_id' => 'integer', 'cms_comm_account_id' => 'integer', 'cms_comm_connection_id' => 'integer']);
$create('cms_comm_campaign', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer',
    'cms_site_id' => 'integer', 'name' => 'string', 'status' => 'string', 'cms_comm_topic_id' => 'integer', 'audience_type' => 'string', 'audience' => 'text',
    'channel' => 'string', 'sender_mode' => 'string', 'cms_comm_account_id' => 'integer', 'subject' => 'string', 'body_html' => 'text', 'offer_kind' => 'string',
    'offer_type' => 'string', 'offer_id' => 'integer', 'task_on_open' => 'integer default 1', 'task_on_reply' => 'integer default 1', 'launched_at' => 'integer',
    'launched_by' => 'integer', 'finished_at' => 'integer']);
$create('cms_comm_campaign_recipient', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_comm_campaign_id' => 'integer',
    'record_type' => 'string', 'record_id' => 'integer', 'address' => 'string', 'name' => 'string', 'subject' => 'string', 'body_html' => 'text',
    'text_by' => 'string', 'text_client' => 'string', 'text_user_id' => 'integer', 'text_at' => 'integer', 'text_edited_by' => 'integer', 'cms_comm_account_id' => 'integer', 'sender_user_id' => 'integer',
    'status' => 'string', 'reason' => 'string', 'error' => 'string', 'cms_comm_message_id' => 'integer', 'send_ref' => 'string', 'sent_at' => 'integer',
    'opened_at' => 'integer', 'replied_at' => 'integer', 'accepted_at' => 'integer', 'unsubscribed_at' => 'integer', 'visited_at' => 'integer']);
$db->createCommand()->createIndex('recipient_unique', 'cms_comm_campaign_recipient', ['cms_comm_campaign_id', 'record_type', 'record_id'], true)->execute();
$create('cms_lead', ['id' => 'pk', 'cms_site_id' => 'integer', 'name' => 'string', 'status' => 'string', 'source_type' => 'string', 'executor_id' => 'integer', 'cms_company_id' => 'integer',
    'cms_user_id' => 'integer']);
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

$msk = new DateTimeZone('Europe/Moscow');
$at = static fn(string $time) => (new DateTimeImmutable($time, $msk))->getTimestamp();

// The pace: working hours of the CRM time zone while the worker runs in UTC.
$s = MailboxPace::DEFAULTS;
$fri = $at('2026-10-23 10:00');
campaignExpect(MailboxPace::nextSlot($s, 0, null, $fri) === $fri, 'Within working hours and the limit a letter leaves at once.');
campaignExpect(MailboxPace::nextSlot($s, 0, $fri - 30, $fri) === $fri + 30, 'The pause since the last campaign letter is kept.');
campaignExpect(MailboxPace::nextSlot($s, 0, null, $at('2026-10-23 07:30')) === $at('2026-10-23 09:00'), 'Before working hours — at their start (Moscow, not UTC).');
campaignExpect(MailboxPace::nextSlot($s, 0, null, $at('2026-10-23 18:30')) === $at('2026-10-26 09:00'), 'After Friday hours — on Monday morning.');
campaignExpect(MailboxPace::nextSlot($s, 30, null, $fri) === $at('2026-10-26 09:00'), 'The day limit moves the rest to the next working day.');
$everyDay = ['dailyLimit' => 3, 'pauseMin' => 20, 'pauseMax' => 40, 'hourFrom' => 0, 'hourTo' => 24, 'days' => [1, 2, 3, 4, 5, 6, 7]];
campaignExpect(MailboxPace::nextSlot($everyDay, 3, null, $at('2026-10-23 22:39')) === $at('2026-10-24 00:00'), 'Round the clock the limit waits till midnight of the CRM.');
for ($i = 0; $i < 20; $i++) {
    $p = MailboxPace::pause($everyDay);
    campaignExpect($p >= 20 && $p <= 40, 'A pause is random within its limits.');
}
campaignExpect(MailboxPace::describe(MailboxPace::DEFAULTS) === '30 писем в день, паузы 1–3 мин, пн–пт 9:00–18:00', 'The default pace reads as agreed.');

$account = new CmsCommAccount(['cms_site_id' => 1, 'channel' => 'email', 'address' => 'manager@example.com', 'cms_comm_connection_id' => 5, 'is_active' => 1]);
$account->save(false);
campaignExpect(MailboxPace::settings($account) === MailboxPace::DEFAULTS, 'A mailbox without its own settings has the defaults.');
$errors = MailboxPace::save($account, ['dailyLimit' => 0, 'pauseMin' => 50, 'pauseMax' => 10, 'hourFrom' => 18, 'hourTo' => 9, 'days' => []]);
campaignExpect(isset($errors['dailyLimit'], $errors['pauseMin'], $errors['hourFrom'], $errors['days']), 'Wrong settings are refused field by field.');
campaignExpect(!MailboxPace::save($account, ['dailyLimit' => 2, 'pauseMin' => 0, 'pauseMax' => 0, 'hourFrom' => 0, 'hourTo' => 24, 'days' => [1, 2, 3, 4, 5, 6, 7]])
    && MailboxPace::settings(CmsCommAccount::findOne($account->id))['dailyLimit'] === 2, 'The settings are kept at the mailbox.');

// The day count includes the manager's own letters: from the CRM and from the mail service ("Sent").
$now = time();
$letter = static function (array $attributes) use ($now) {
    $m = new CmsCommMessage($attributes + ['cms_site_id' => 1, 'cms_comm_conversation_id' => 1, 'channel' => 'email', 'direction' => 'out',
            'status' => 'sent', 'external_id' => 'x'.random_int(1, PHP_INT_MAX).'@t', 'message_at' => $now, 'to_list' => '{}']);
    $m->save(false);

    return $m;
};
$letter(['cms_comm_account_id' => $account->id]);
$fromService = $letter([]);
(new \skeeks\cms\communication\models\CmsCommMessageMailbox(['cms_comm_message_id' => $fromService->id, 'cms_comm_account_id' => $account->id]))->save(false);
$letter(['cms_comm_account_id' => $account->id, 'status' => 'failed']);
$letter(['cms_comm_account_id' => $account->id, 'direction' => 'in', 'status' => 'received']);
$letter(['cms_comm_account_id' => $account->id, 'message_at' => $now - 3 * 86400]);
campaignExpect(MailboxPace::sentToday((int)$account->id, $now) === 2, 'Today: sent letters of the mailbox, also from the mail service; not failed, incoming or old ones.');
campaignExpect(MailboxPace::nextSlotFor(CmsCommAccount::findOne($account->id), $now) >= MailboxPace::dayStart($now, 1),
    'With the limit of 2 reached by personal letters the campaign waits for the next day.');

// Placeholders.
$values = ['{Имя}' => '', '{Компания}' => 'ООО «Ромашка»', '{Менеджер}' => 'Анна'];
campaignExpect(Campaigns::fill('Здравствуйте, {Имя}! Пишет {Менеджер}.', $values, false) === 'Здравствуйте! Пишет Анна.', 'An unknown name goes with its comma.');
campaignExpect(Campaigns::fill('{Имя}, решение для {Компания}', $values, false) === 'Решение для ООО «Ромашка»', 'At the start the comma after it goes and the text starts with a capital.');
campaignExpect(Campaigns::fill('<p>{Имя}, добрый день</p><p>{Компания}</p>', $values, true) === '<p>Добрый день</p><p>ООО «Ромашка»</p>',
    'In HTML values are escaped and the paragraph start is found.');
campaignExpect(Campaigns::fill('Здравствуйте, {Имя}!', ['{Имя}' => 'Иван <b>'], true) === 'Здравствуйте, Иван &lt;b&gt;!', 'A name is text, never markup.');
$lead = new \skeeks\cms\models\CmsLead();
$lead->setAttributes(['name' => 'ООО «Тест» — Иван', 'cms_company_id' => null], false);
campaignExpect(Campaigns::companyName($lead, 'Иван') === 'ООО «Тест»' && Campaigns::companyName($lead, '') === 'ООО «Тест» — Иван',
    'The company of an imported lead is its name without the person.');

// The campaign form: audience conditions and the offer.
$topic = new CmsCommTopic(['cms_site_id' => 1, 'name' => 'Акции', 'is_active' => 1]);
$topic->save(false);
$campaign = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Плитка', 'cms_comm_topic_id' => $topic->id]);
campaignExpect($campaign->status === CmsCommCampaign::STATUS_DRAFT && $campaign->channel === MailboxCampaignChannel::ID
    && $campaign->sender_mode === CmsCommCampaign::SENDER_RESPONSIBLE && strpos($campaign->body_html, '{Имя}') !== false, 'A new campaign starts with sensible defaults.');
$campaign->audienceStatuses = ['new', ''];
$campaign->audienceExecutors = ['7'];
$campaign->offer_kind = CmsCommCampaign::OFFER_SHARED;
$campaign->validate(['offer_kind']);
campaignExpect($campaign->hasErrors('offer_kind'), 'An offer kind without its document is shown at the kind.');
$campaign->offer_kind = CmsCommCampaign::OFFER_NONE;
$campaign->offer_id = 9;
$campaign->subject = 'Тема';
$campaign->save(false);
$campaign = CmsCommCampaign::findOne($campaign->id);
campaignExpect($campaign->getAudienceArray() === ['statuses' => ['new'], 'executors' => [7]] && $campaign->audienceStatuses === ['new']
    && $campaign->offer_id === null, 'Audience conditions are stored as JSON; no offer keeps no document.');
$campaign->status = CmsCommCampaign::STATUS_RUNNING;
$campaign->save(false);
$campaign = CmsCommCampaign::findOne($campaign->id);
$campaign->subject = 'Другая тема';
$campaign->validate(['status']);
campaignExpect($campaign->hasErrors('status'), 'A launched campaign keeps its letter.');
$campaign->subject = 'Тема';

// The sender of each recipient: the mailbox of the lead's responsible employee.
(new \skeeks\cms\communication\models\CmsCommAccountAccess(['cms_comm_account_id' => $account->id, 'cms_user_id' => 7, 'read_level' => 'all', 'send_level' => 'any']))->save(false);
$db->createCommand()->batchInsert('cms_lead', ['id', 'cms_site_id', 'name', 'status', 'executor_id'], [
    [1, 1, 'Лид 1', 'new', 7], [2, 1, 'Лид 2', 'new', 8], [3, 1, 'Лид 3', 'new', 7], [4, 1, 'Лид 4', 'new', 7],
])->execute();
$recipients = [];
foreach ([1 => 'one@example.com', 2 => 'two@example.com', 3 => 'three@example.com', 4 => 'four@example.com'] as $leadId => $address) {
    $r = new CmsCommCampaignRecipient(['record_type' => 'lead', 'record_id' => $leadId, 'address' => $address, 'status' => CmsCommCampaignRecipient::STATUS_PENDING]);
    $recipients[$leadId] = $r;
}
(new MailboxCampaignChannel())->assignSenders($campaign, array_values($recipients), new \skeeks\cms\models\CmsUser(['id' => 1]));
campaignExpect((int)$recipients[1]->cms_comm_account_id === (int)$account->id && (int)$recipients[1]->sender_user_id === 7
    && $recipients[2]->status === CmsCommCampaignRecipient::STATUS_EXCLUDED && $recipients[2]->reason === CmsCommCampaignRecipient::REASON_NO_MAILBOX,
    'The letter is from the responsible employee\'s mailbox; without one the lead is excluded with the reason.');
$summary = Campaigns::summarize(array_values($recipients));
campaignExpect($summary['pending'] === 3 && $summary['reasons'] === [CmsCommCampaignRecipient::REASON_NO_MAILBOX => 1]
    && $summary['accounts'] === [(int)$account->id => 3], 'The summary counts who gets the letter, the reasons and the mailboxes.');
foreach ($recipients as $r) {
    $r->cms_comm_campaign_id = $campaign->id;
    $r->save(false);
}
try {
    (new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $campaign->id, 'record_type' => 'lead', 'record_id' => 1, 'status' => 'pending']))->save(false);
    campaignExpect(false, 'A campaign must never write twice to one lead.');
} catch (\yii\db\IntegrityException $e) {
}

// The order of waiting letters, pause, resume, cancel.
$campaign->launched_at = time() - 100;
$campaign->save(false);
$first = MailboxCampaignChannel::nextRecipient((int)$account->id);
campaignExpect($first && (int)$first->record_id === 1, 'Letters go in the order of the recipients.');
$service = new Campaigns();
campaignExpect($service->pause($campaign) && !MailboxCampaignChannel::nextRecipient((int)$account->id), 'A paused campaign sends nothing.');
Yii::$app->jobs->pushed = [];
campaignExpect($service->resume($campaign) && Yii::$app->jobs->pushed[0][0] === MailboxCampaignChannel::JOB
    && Yii::$app->jobs->pushed[0][1] === ['accountId' => (int)$account->id], 'Resuming starts the job of each mailbox.');
CmsCommCampaignRecipient::updateAll(['status' => 'sent', 'sent_at' => time()], ['id' => $recipients[1]->id]);
campaignExpect($service->cancel($campaign) && $campaign->status === CmsCommCampaign::STATUS_CANCELLED
    && (int)CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => $campaign->id, 'status' => 'cancelled'])->count() === 2,
    'Cancelling leaves the sent letters and cancels the waiting ones.');
campaignExpect(!$service->resume($campaign) && !$service->pause($campaign), 'A cancelled campaign cannot go on.');

$done = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Готово', 'status' => CmsCommCampaign::STATUS_RUNNING, 'cms_comm_topic_id' => $topic->id]);
$done->save(false);
(new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $done->id, 'record_type' => 'lead', 'record_id' => 1, 'status' => 'pending']))->save(false);
campaignExpect(!Campaigns::finishIfDone($done), 'A campaign with waiting letters is not finished.');
CmsCommCampaignRecipient::updateAll(['status' => 'sent'], ['cms_comm_campaign_id' => $done->id]);
campaignExpect(Campaigns::finishIfDone($done) && CmsCommCampaign::findOne($done->id)->status === CmsCommCampaign::STATUS_DONE, 'Nothing left — finished.');

// The report: a failed letter, a reply, an opt-out after the letter, totals with percents.
$report = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Отчёт', 'status' => CmsCommCampaign::STATUS_RUNNING, 'cms_comm_topic_id' => $topic->id,
    'task_on_open' => 0, 'task_on_reply' => 0]);
$report->save(false);
$sentAt = time() - 600;
$rows = [];
foreach (['a@example.com' => 'sent', 'b@example.com' => 'failed', 'c@example.com' => 'sent', 'd@example.com' => 'sent'] as $address => $status) {
    $m = $letter(['cms_comm_account_id' => $account->id, 'cms_comm_conversation_id' => 100 + count($rows), 'status' => $status, 'error' => $status === 'failed' ? 'Отказ сервера' : null,
        'cms_comm_campaign_id' => $report->id, 'message_at' => $sentAt]);
    $r = new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $report->id, 'record_type' => 'lead', 'record_id' => 10 + count($rows), 'address' => $address,
        'status' => 'sent', 'cms_comm_message_id' => $m->id, 'sent_at' => $sentAt, 'sender_user_id' => 7]);
    $r->save(false);
    $rows[$address] = [$r, $m];
}
(new CmsCommCampaignRecipient(['cms_comm_campaign_id' => $report->id, 'record_type' => 'lead', 'record_id' => 99, 'status' => 'excluded',
    'reason' => MailingPolicy::REASON_UNSUBSCRIBED]))->save(false);
// "c" replied; "d" opted out of the topic after the letter; "a" had opted out of another topic long ago.
$reply = $letter(['cms_comm_conversation_id' => (int)$rows['c@example.com'][1]->cms_comm_conversation_id, 'direction' => 'in', 'status' => 'received',
    'message_at' => $sentAt + 120]);
MailingConsents::unsubscribe(MailingContacts::get(1, 'email', 'd@example.com'), (int)$topic->id, CmsCommContactTopic::SOURCE_ONE_CLICK, null,
    (int)$rows['d@example.com'][1]->id);
campaignExpect(Campaigns::taskOnOpen((int)$rows['a@example.com'][1]->id) === false && Campaigns::taskOnOpen((int)$fromService->id) === null,
    'A campaign letter follows its campaign\'s task setting; another letter — the package setting.');
campaignExpect($service->onIncoming([(int)$reply->id, (int)$fromService->id]) === 1 && $service->onIncoming([(int)$reply->id]) === 0,
    'A reply in the conversation of a campaign letter is found once.');
$service->refresh($report);
$c = CmsCommCampaignRecipient::findOne($rows['c@example.com'][0]->id);
$b = CmsCommCampaignRecipient::findOne($rows['b@example.com'][0]->id);
$d = CmsCommCampaignRecipient::findOne($rows['d@example.com'][0]->id);
campaignExpect((int)$c->replied_at === $sentAt + 120 && $b->status === 'failed' && $b->error === 'Отказ сервера' && $d->unsubscribed_at,
    'The report knows the reply, the failed letter with its reason and the opt-out.');
$stats = Campaigns::stats($report);
campaignExpect($stats['total'] === 5 && $stats['excluded'] === 1 && $stats['sent'] === 3 && $stats['failed'] === 1 && $stats['replied'] === 1
    && $stats['unsubscribed'] === 1 && $stats['percent']['replied'] === 33.3, 'Totals and percents are of the letters sent.');

// Recipients added one by one (by hand or by AI) with a personal letter: leads, companies, clients.
$db->createCommand()->batchInsert('cms_lead_email', ['cms_lead_id', 'value', 'name'], [[1, 'One@Example.com', 'Иван'], [3, 'three@example.com', null]])->execute();
$db->createCommand()->insert('cms_company', ['id' => 15, 'name' => 'Ромашка'])->execute();
$db->createCommand()->batchInsert('cms_company_email', ['cms_company_id', 'value'], [[15, 'info@romashka.test']])->execute();
$db->createCommand()->batchInsert('cms_user', ['id', 'username', 'first_name', 'is_worker'], [[7, 'manager', 'Анна', 1], [30, 'olga', 'Ольга', 0]])->execute();
$db->createCommand()->batchInsert('cms_company2user', ['cms_company_id', 'cms_user_id'], [[15, 30]])->execute();
$db->createCommand()->batchInsert('cms_user_email', ['cms_user_id', 'value'], [[30, 'olga@romashka.test']])->execute();
$db->createCommand()->batchInsert('cms_company2manager', ['cms_company_id', 'cms_user_id'], [[15, 7]])->execute();
$db->createCommand()->batchInsert('cms_user2manager', ['client_id', 'worker_id'], [[30, 7]])->execute();
$company = \skeeks\cms\models\CmsCompany::findOne(15);
$client = \skeeks\cms\models\CmsUser::findOne(30);
campaignExpect(Campaigns::recordAddresses($company) === ['info@romashka.test' => '', 'olga@romashka.test' => 'Ольга']
    && Campaigns::recordAddresses(\skeeks\cms\models\CmsLead::findOne(1)) === ['one@example.com' => 'Иван'], 'A company gives its emails and those of its people; a lead its own.');
campaignExpect(Campaigns::responsibleOf($company) === 7 && Campaigns::responsibleOf($client) === 7 && Campaigns::responsibleOf(\skeeks\cms\models\CmsLead::findOne(2)) === 8,
    'The responsible employee of a company and of a client is their first manager; of a lead its executor.');

$personal = new CmsCommCampaign(['cms_site_id' => 1, 'name' => 'Личные', 'cms_comm_topic_id' => $topic->id, 'audience_type' => CmsCommCampaign::AUDIENCE_MANUAL,
    'subject' => 'Для {Компания}', 'body_html' => '<p>Шаблон, {Имя}</p>']);
$personal->save(false);
$employee = \skeeks\cms\models\CmsUser::findOne(7);
$errors = Campaigns::textErrors($personal, '', '<p>Без отписки</p>');
campaignExpect(isset($errors['body_html']) && strpos($errors['body_html'], '{Отписаться}') !== false, 'A personal letter needs the opt-out link.');
$personal->offer_kind = CmsCommCampaign::OFFER_SHARED;
campaignExpect(strpos(Campaigns::textErrors($personal, '', '<p>{Отписаться}</p>')['body_html'] ?? '', '{Ссылка}') !== false, 'With an offer it needs the link too.');
$personal->offer_kind = CmsCommCampaign::OFFER_NONE;
try {
    $service->addRecipient($personal, $company, $employee, ['address' => 'nobody@romashka.test']);
    campaignExpect(false, 'An address of no record must be refused.');
} catch (\DomainException $e) {
}
$byAi = $service->addRecipient($personal, $company, $employee, ['address' => 'OLGA@romashka.test', 'subject' => 'Ольга, про ваш сайт',
    'body_html' => '<p>Ольга, у {Компания} устарел сайт.</p><p>{Отписаться}</p><script>x</script>', 'by' => 'ai', 'client' => 'mcp:claude']);
campaignExpect($byAi->status === CmsCommCampaignRecipient::STATUS_DRAFT && $byAi->record_type === 'company' && $byAi->address === 'olga@romashka.test'
    && $byAi->name === 'Ольга' && $byAi->text_by === 'ai' && $byAi->text_client === 'mcp:claude' && (int)$byAi->text_user_id === 7
    && strpos((string)$byAi->body_html, 'script') === false && $byAi->getTextLabel() === 'Личный текст · ИИ (claude)',
    'AI adds a company person with a personal letter; who prepared it is kept, the text is cleaned.');
// An employee changing the AI text keeps "Подготовлено ИИ" and is named; AI writing again clears it.
$service->setText($byAi, 'Ольга, про ваш сайт', '<p>Поправил.</p><p>{Отписаться}</p>', $employee);
$byAi->refresh();
campaignExpect($byAi->text_by === 'ai' && $byAi->text_client === 'mcp:claude' && (int)$byAi->text_edited_by > 0
    && $byAi->getTextLabel() === 'Личный текст · ИИ (claude), изменён сотрудником'
    && \skeeks\cms\communication\services\AiMark::ofRecipient($byAi)['edited'] === true, 'An employee\'s change keeps the AI mark.');
$byAi = $service->addRecipient($personal, $company, $employee, ['address' => 'olga@romashka.test', 'subject' => 'Ольга, про ваш сайт',
    'body_html' => '<p>Ольга, у {Компания} устарел сайт.</p><p>{Отписаться}</p>', 'by' => 'ai', 'client' => 'mcp:claude']);
campaignExpect($byAi->text_edited_by === null, 'A new AI text is not "changed".');
$again = $service->addRecipient($personal, $company, $employee, ['address' => 'olga@romashka.test']);
campaignExpect((int)$again->id === (int)$byAi->id && $again->getHasPersonalText(), 'Adding the record again changes the same recipient and keeps its letter.');
$plain = $service->addRecipient($personal, \skeeks\cms\models\CmsLead::findOne(1), $employee);
campaignExpect($plain->address === 'one@example.com' && $plain->name === 'Иван' && !$plain->getHasPersonalText() && $plain->getTextLabel() === 'Шаблон кампании',
    'A lead is added with its first email and the template.');
$candidates = $service->candidates($personal, $employee);
$byRecord = [];
foreach ($candidates as $r) {
    $byRecord[$r->record_type.':'.$r->record_id] = $r;
}
campaignExpect(count($candidates) === 2 && $byRecord['company:15']->status === CmsCommCampaignRecipient::STATUS_PENDING
    && (int)$byRecord['company:15']->sender_user_id === 7 && (int)$byRecord['company:15']->cms_comm_account_id === (int)$account->id,
    'A campaign of added recipients takes only them; a company writes from its manager\'s mailbox.');
$letterAi = $service->letter($personal, $byRecord['company:15'], $company, $employee, false);
$letterPlain = $service->letter($personal, $byRecord['lead:1'], \skeeks\cms\models\CmsLead::findOne(1), $employee, false);
campaignExpect($letterAi->subject === 'Ольга, про ваш сайт' && strpos($letterAi->html, 'у Ромашка устарел') !== false
    && strpos($letterAi->html, \skeeks\cms\communication\services\Unsubscribe::PLACEHOLDER) !== false
    && $letterPlain->subject === 'Для Лид 1' && strpos($letterPlain->html, 'Шаблон, Иван') !== false, 'Each recipient gets its own letter or the template.');
$service->setText($byAi, '', '', $employee);
$byAi->refresh();
campaignExpect(!$byAi->getHasPersonalText() && $byAi->text_by === null, 'An empty letter returns the recipient to the template.');
campaignExpect($service->removeRecipient($plain) && !CmsCommCampaignRecipient::findOne($plain->id), 'An added recipient can be removed before launch.');
$personal->status = CmsCommCampaign::STATUS_RUNNING;
$personal->save(false);
try {
    $service->addRecipient($personal, $company, $employee);
    campaignExpect(false, 'A launched campaign takes no new recipients.');
} catch (\DomainException $e) {
}

echo "campaign: OK\n";
