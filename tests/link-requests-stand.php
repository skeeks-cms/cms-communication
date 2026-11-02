<?php
/**
 * A request of the site within a visit by a letter link (services\LinkRequests,
 * core crm\LeadOriginEvent) on a local application with its database, in a
 * transaction that is rolled back:
 * LINK_REQUESTS_TEST=local php tests/link-requests-stand.php
 * (TEST_SENDER_ID=1 sends the letters, TEST_MAIN_ID=1663 is the main responsible).
 *
 * - a letter to a company, a visit, a request with another phone — the deal
 *   of that company at its main responsible, the feed entry with the pages
 *   and the letter, the call task of the visit linked, not a second one;
 * - a company without a main responsible — the sender gets the deal;
 * - no cookie, an ended visit — the request is routed as before;
 * - a letter of an open deal — the request is added to that deal;
 * - a campaign letter — the request of the campaign (CampaignTasks), the
 *   form request added to its deal, a second request makes no second deal.
 * Companies, deals, letters and the campaign are made inside the transaction;
 * nothing leaves: the letters only wait in the queue.
 */
if (getenv('LINK_REQUESTS_TEST') !== 'local') {
    throw new RuntimeException('Set LINK_REQUESTS_TEST=local.');
}
define('ROOT_DIR', getenv('TEST_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null, [Yiisoft\Config\Modifier\RecursiveMerge::groups('web', 'web-'.ENV, 'params', 'params-web-'.ENV)], 'params-web-'.ENV);
$c = $config->get($config->has('web-'.ENV) ? 'web-'.ENV : 'web');
$c['components']['request']['scriptFile'] = ROOT_DIR.'/frontend/web/index.php';
$c['components']['request']['scriptUrl'] = '/index.php';
$c['components']['request']['hostInfo'] = getenv('TEST_HOST_INFO') ?: 'http://localhost';
$c['components']['user']['enableSession'] = false;
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();

use skeeks\cms\communication\components\LinkTrackingSettings;
use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTrack;
use skeeks\cms\communication\models\CmsCommTrackVisit;
use skeeks\cms\communication\services\LinkVisits;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\crm\CrmAccess;
use skeeks\cms\crm\InboundQueue;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2manager;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\services\CmsLeadService;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException('FAIL: '.$message); } ++$checks; }

const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const PERSON = ['webdriver' => '0', 'languages' => '2', 'width' => '1920', 'height' => '1080'];

$db = Yii::$app->db;
$siteId = (int)Yii::$app->skeeks->site->id;
$sender = CmsUser::findOne((int)(getenv('TEST_SENDER_ID') ?: 1));
$main = CmsUser::findOne((int)(getenv('TEST_MAIN_ID') ?: 1663));
check($sender && $main && (int)$sender->id !== (int)$main->id, 'The sender and the main responsible exist and differ');
$settings = Yii::$app->crmSettings;
$saved = [$settings->inboundWorkerIds, $settings->inboundDepartmentIds, $settings->inboundMode, $settings->inboundToMain];
$uniq = substr(md5(uniqid('', true)), 0, 6);
$freshPhone = static fn() => '+7 999 '.random_int(100, 999).'-'.random_int(10, 99).'-'.random_int(10, 99);
$ip = 30;

// A letter of the sender to a record (queued, marked "sent" two minutes ago) and a counted visit by its link.
$letter = static function ($record, string $subject, ?int $campaignId = null) use ($sender, $siteId, $uniq, &$ip): array {
    $service = new MailComposeService($sender, $siteId);
    $form = new ComposeForm(['service' => $service]);
    $form->cms_comm_account_id = (int)$service->defaultAccountId();
    $form->to = 'check-requests-'.$uniq.'-'.$ip.'@skeeks.com';
    $form->subject = $subject;
    $form->context_model_code = get_class($record);
    $form->context_model_id = (int)$record->id;
    $form->body_html = '<p>Подробнее: <a href="https://skeeks.com/services/fz-152">исправление сайта по 152-ФЗ</a>.</p>';
    check($form->validate(), 'The letter is valid: '.json_encode($form->getFirstErrors(), JSON_UNESCAPED_UNICODE));
    $message = $service->queue($form);
    $message->updateAttributes(['status' => CmsCommMessage::STATUS_SENT, 'message_at' => time() - 120, 'cms_comm_campaign_id' => $campaignId]);
    $track = CmsCommTrack::find()->andWhere(['cms_comm_message_id' => (int)$message->id])->one();
    check($track && $track->model_code === get_class($record) && (int)$track->model_id === (int)$record->id, 'The letter has a code of its record');
    $track->updateAttributes(['created_at' => time() - 180, 'cms_comm_campaign_id' => $campaignId]);
    $seen = (new LinkVisits())->seen($track, 'https://skeeks.com/services/fz-152?utm_source=crm&utm_medium=email&sx='.$track->code,
        'Сайт по 152-ФЗ', '203.0.113.'.($ip++), CHROME, PERSON);
    check($seen['result'] === LinkVisits::RESULT_FIRST && !empty($seen['token']), 'A person visits the site by the letter: '.json_encode($seen));
    (new LinkVisits())->page($seen['token'], 'https://skeeks.com/works', 'Портфолио');

    return [$message, $track, $seen['token']];
};
// A request of the form "Заказать исправление" (form2, code callback) with a phone the CRM does not know.
$request = static function (string $ref, ?string $phone = null) use ($siteId, $uniq, $freshPhone): CmsLead {
    return (new CmsLeadService())->createFromSource([
        'name' => 'Проверка: форма на сайте «Заказать исправление» '.$uniq.' '.$ref,
        'cms_site_id' => $siteId,
        'source_type' => CmsLead::SOURCE_FORM,
        'source_ref' => 'link-requests-test:'.$uniq.':'.$ref,
        'source_name' => 'Заказать исправление',
        'description' => 'Телефон: '.($phone ?? $freshPhone()),
        'phones' => [$phone ?? $freshPhone()],
        'status' => CmsLead::STATUS_NEW,
    ]);
};
$company = static function (string $name, ?CmsUser $mainUser) use ($uniq): CmsCompany {
    $company = new CmsCompany(['name' => 'Проверка: '.$name.' '.$uniq]);
    $company->save(false);
    CmsCompany2manager::deleteAll(['cms_company_id' => (int)$company->id]);
    if ($mainUser) {
        CrmAccess::instance()->setMain('company', (int)$company->id, (int)$mainUser->id);
    }

    return $company;
};
$callTasks = static fn(CmsCompany $company) => CmsTask::find()->andWhere(['cms_company_id' => (int)$company->id, 'status' => CmsTask::activeStatuses()])
    ->andWhere(['like', 'name', LinkVisits::CALL_TASK_NAME.'%', false])->all();
$feedHas = static fn(CmsLead $lead, string $text) => CmsLog::find()->andWhere(['model_code' => $lead->skeeksModelCode, 'model_id' => (int)$lead->id])
    ->andWhere(['like', 'comment', $text])->exists();

$tx = $db->beginTransaction();
try {
    Yii::$app->user->setIdentity(null);
    LinkTrackingSettings::reset($siteId, ['enabled' => true, 'domains' => ['skeeks.com', 'skeeks-platform.ru'], 'taskOnVisit' => true]);
    // An ordinary queue «видят все из списка», no request goes to a found client by contacts.
    $settings->inboundWorkerIds = [(int)$main->id];
    $settings->inboundDepartmentIds = [];
    $settings->inboundMode = InboundQueue::MODE_ALL;
    $settings->inboundToMain = 0;
    InboundQueue::reset();

    // 1. A letter to a company, a visit, a request with another phone: the deal of the company at its main responsible.
    $client = $company('компания письма', $main);
    [$message, $track, $token] = $letter($client, 'Сайт по 152-ФЗ');
    $tasks = $callTasks($client);
    check(count($tasks) === 1 && (int)$tasks[0]->executor_id === (int)$sender->id && !$tasks[0]->cms_lead_id, 'The visit gave the sender a call task on the company');
    $_COOKIE[LinkVisits::COOKIE] = $token;
    $lead = $request('company');
    check((int)$lead->cms_company_id === (int)$client->id, 'The request is linked to the company of the letter whatever its phone');
    check((int)$lead->executor_id === (int)$main->id && $lead->status === CmsLead::STATUS_IN_WORK, 'The deal is at the main responsible of the company, in work');
    check(CrmAccess::instance()->mainResponsibleId('company', (int)$client->id) === (int)$main->id, 'The main responsible of the company is the same');
    check($feedHas($lead, 'Заявка после перехода из письма от') && $feedHas($lead, '«Сайт по 152-ФЗ»') && $feedHas($lead, 'Портфолио')
        && $feedHas($lead, 'admin-cms-comm-message'), 'The feed: the letter, its subject, the pages and the link to the letter');
    $tasks = $callTasks($client);
    check(count($tasks) === 1 && (int)$tasks[0]->cms_lead_id === (int)$lead->id, 'The call task of the visit is linked to the deal, not made twice');
    check($feedHas($lead, 'привязана к этой сделке'), 'The feed tells the task is linked');
    $entry = CmsLog::find()->andWhere(['model_code' => $lead->skeeksModelCode, 'model_id' => (int)$lead->id])
        ->andWhere(['like', 'comment', 'Заявка после перехода из письма от'])->one();
    check(str_contains(\skeeks\cms\helpers\CommentHtml::render((string)$entry->comment), 'href="http://localhost/~sxx/cmsCommunication/admin-cms-comm-message/view?pk='.(int)$message->id),
        'The feed shows the link to the letter (an absolute one)');
    if (getenv('LINK_REQUESTS_VERBOSE')) {
        foreach (CmsLog::find()->andWhere(['model_code' => $lead->skeeksModelCode, 'model_id' => (int)$lead->id])->orderBy(['id' => SORT_ASC])->all() as $log) {
            echo strip_tags((string)$log->comment, '<a>'), "\n";
        }
    }
    $again = $request('company');
    check((int)$again->id === (int)$lead->id && CmsLead::find()->andWhere(['source_ref' => 'link-requests-test:'.$uniq.':company'])->count() == 1,
        'A repeated event of the same request goes on in its deal');

    // 2. A company without a main responsible: the employee who sent the letter gets the deal.
    $orphan = $company('компания без основного', null);
    [, , $token] = $letter($orphan, 'Сайт по 152-ФЗ');
    $_COOKIE[LinkVisits::COOKIE] = $token;
    $lead = $request('orphan');
    check((int)$lead->cms_company_id === (int)$orphan->id && (int)$lead->executor_id === (int)$sender->id, 'Without a main responsible the sender gets the deal');
    check(CrmAccess::instance()->mainResponsibleId('company', (int)$orphan->id) === null, 'The company still has no main responsible');

    // 3. No cookie, an ended visit, a malformed token: the request is routed as before.
    unset($_COOKIE[LinkVisits::COOKIE]);
    $lead = $request('no-cookie');
    check(!$lead->cms_company_id && $lead->status === CmsLead::STATUS_NEW && !$lead->executor_id, 'No cookie: «Неразобранное» as before');
    check(!$feedHas($lead, 'Заявка после перехода из письма'), 'No cookie: no feed entry');
    CmsCommTrackVisit::updateAll(['last_at' => time() - LinkVisits::IDLE - 60], ['token' => $token]);
    $_COOKIE[LinkVisits::COOKIE] = $token;
    $lead = $request('ended');
    check(!$lead->cms_company_id && $lead->status === CmsLead::STATUS_NEW, 'An ended visit: the request is routed as before');
    $_COOKIE[LinkVisits::COOKIE] = '<script>';
    $lead = $request('bad-token');
    check(!$lead->cms_company_id && $lead->status === CmsLead::STATUS_NEW, 'A malformed token: as before');

    // 4. A letter of an open deal: the request is added to it.
    $dealCompany = $company('компания сделки', $main);
    $deal = new CmsLead(['cms_site_id' => $siteId, 'name' => 'Проверка: открытая сделка '.$uniq, 'executor_id' => (int)$main->id,
        'cms_company_id' => (int)$dealCompany->id, 'status' => CmsLead::STATUS_IN_WORK, 'source_type' => CmsLead::SOURCE_MANUAL]);
    $deal->save(false);
    [, , $token] = $letter($deal, 'Предложение');
    $_COOKIE[LinkVisits::COOKIE] = $token;
    $result = $request('deal');
    check((int)$result->id === (int)$deal->id, 'The request goes on in the open deal of the letter');
    $row = CmsLead::find()->fromSource(CmsLead::SOURCE_FORM, 'link-requests-test:'.$uniq.':deal', $siteId)->one();
    check($row && $row->status === CmsLead::STATUS_REJECTED && $row->triage_result === CmsLead::TRIAGE_MERGED && (int)$row->merged_into_id === (int)$deal->id,
        'The request keeps its row, closed as added to the deal');
    check($feedHas($deal, 'Добавлено обращение') && $feedHas($deal, 'Заявка после перехода из письма от'), 'The deal feed: the request and the visit');
    check(count($callTasks($dealCompany)) <= 1, 'No second call task');

    // 5. A campaign letter: the request of the campaign, no second deal.
    $campaignCompany = $company('компания рассылки', $main);
    $campaign = new CmsCommCampaign(['cms_site_id' => $siteId, 'name' => 'Проверка: рассылка 152-ФЗ '.$uniq,
        'status' => CmsCommCampaign::STATUS_RUNNING, 'audience_type' => CmsCommCampaign::AUDIENCE_MANUAL,
        'task_on_reply' => 1, 'task_on_open' => 1]);
    if ($campaign->hasAttribute('lead_to')) {
        $campaign->lead_to = CmsCommCampaign::OWNER_SENDER;
    }
    $campaign->save(false);
    [$message, $track, $token] = $letter($campaignCompany, 'Сайт по 152-ФЗ', (int)$campaign->id);
    $db->createCommand()->insert('{{%cms_comm_campaign_recipient}}', ['cms_comm_campaign_id' => (int)$campaign->id, 'record_type' => 'company',
        'record_id' => (int)$campaignCompany->id, 'address' => 'check-requests-'.$uniq.'@skeeks.com', 'status' => 'sent',
        'cms_comm_message_id' => (int)$message->id, 'sender_user_id' => (int)$sender->id, 'sent_at' => time() - 120,
        'created_at' => time(), 'updated_at' => time()])->execute();
    $recipientId = (int)$db->getLastInsertID();
    $_COOKIE[LinkVisits::COOKIE] = $token;
    $first = $request('campaign-1');
    check($first->source_type === CmsLead::SOURCE_MAILING && $first->source_ref === 'campaign-request:'.$recipientId,
        'The request of a campaign letter is the request of the campaign: '.$first->source_type.' '.$first->source_ref);
    check((int)$first->cms_company_id === (int)$campaignCompany->id && (int)$first->executor_id === (int)$sender->id, 'By the campaign rules: its company, the sender');
    $row = CmsLead::find()->fromSource(CmsLead::SOURCE_FORM, 'link-requests-test:'.$uniq.':campaign-1', $siteId)->one();
    check($row && (int)$row->merged_into_id === (int)$first->id, 'The form request is added to the campaign deal');
    check($feedHas($first, 'Заявка после перехода из письма от'), 'The campaign deal has the feed entry');
    $second = $request('campaign-2');
    check((int)$second->id === (int)$first->id, 'A second request goes on in the same deal');
    check(CmsLead::find()->andWhere(['source_type' => CmsLead::SOURCE_MAILING, 'source_ref' => 'campaign-request:'.$recipientId])->count() == 1,
        'One campaign deal for the recipient');
    check(CmsTask::find()->andWhere(['cms_lead_id' => (int)$first->id])->andWhere(['like', 'name', 'Связаться:%', false])->exists(),
        'The second request gives the task «Связаться» in the deal');

    echo 'OK link-requests-stand: '.$checks." checks\n";
} finally {
    unset($_COOKIE[LinkVisits::COOKIE]);
    if ($tx->isActive) {
        $tx->rollBack();
    }
    [$settings->inboundWorkerIds, $settings->inboundDepartmentIds, $settings->inboundMode, $settings->inboundToMain] = $saved;
    InboundQueue::reset();
    LinkTrackingSettings::reset($siteId);
}
