<?php
/**
 * Step F on a local application with its database, in a transaction that is
 * rolled back: VISITS_TEST=local php tests/visits-stand.php. A letter of
 * the administrator (TEST_ADMIN_ID, 1) to a deal made inside the transaction
 * («Проверка:», a test address on @skeeks.com) is queued with links in the text and the signature: marks and the
 * code, the manager's marks kept, other links untouched; then visits by the
 * code — a robot, a link check, an employee, a person (feed entry, the
 * pages of the visit, notification, call task once), the next pages, the
 * end of a visit, a reply that quotes the letter, the feed renderer, the
 * client history of AI clients (with skeeks/cms-mcp) and a recipient of a
 * campaign made inside the transaction "перешёл на сайт". No record of the
 * stand database is relied on. Nothing leaves: the letter only waits in
 * the queue inside the transaction.
 */
if (getenv('VISITS_TEST') !== 'local') {
    throw new RuntimeException('Set VISITS_TEST=local.');
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
use skeeks\cms\communication\log\VisitLogRenderer;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTrack;
use skeeks\cms\communication\models\CmsCommTrackVisit;
use skeeks\cms\communication\services\LinkVisits;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }

const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const PERSON = ['webdriver' => '0', 'languages' => '2', 'width' => '1920', 'height' => '1080'];

$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    check((bool)$admin, 'The administrator exists');
    // An own deal with a test address: the stand database is a copy of production, its deals are not relied on.
    $lead = new CmsLead(['cms_site_id' => $siteId, 'name' => 'Проверка: метки ссылок', 'executor_id' => (int)$admin->id]);
    $lead->save(false);
    (new \skeeks\cms\models\CmsLeadEmail(['cms_lead_id' => (int)$lead->id, 'value' => 'check-visits-'.(int)$lead->id.'@skeeks.com']))->save(false);
    $lead->refresh();
    check((bool)$lead->mainEmail, 'The test deal has its email');
    Yii::$app->user->setIdentity($admin);
    $email = (string)$lead->mainEmail->value;
    LinkTrackingSettings::reset($siteId, ['enabled' => true, 'domains' => ['skeeks.com', 'skeeks-platform.ru'], 'taskOnVisit' => true]);
    // An open call task of the administrator on this lead would stop the new one: close them inside the transaction.
    CmsTask::updateAll(['status' => CmsTask::STATUS_READY], ['executor_id' => (int)$admin->id, 'cms_lead_id' => (int)$lead->id, 'status' => CmsTask::activeStatuses()]);

    // 1. A letter from the lead card: links in the text and the signature, a manager's mark, another site, mail.
    $service = new MailComposeService($admin, $siteId);
    $form = new ComposeForm(['service' => $service]);
    $form->cms_comm_account_id = (int)$service->defaultAccountId();
    $form->to = $email;
    $form->subject = 'Проверка меток ссылок';
    $form->context_model_code = CmsLead::class;
    $form->context_model_id = (int)$lead->id;
    $form->body_html = '<p>Решение: <a href="https://skeeks.com/solutions/plitka">плитка</a>, акция: '
        .'<a href="https://skeeks.com/promo?utm_campaign=autumn&amp;utm_source=manager">осень</a>, '
        .'<a href="https://ya.ru/">Яндекс</a>, <a href="mailto:info@skeeks.com">почта</a>.</p>'
        .'<p>--<br>Студия SkeekS, <a href="https://skeeks-platform.ru/">skeeks-platform.ru</a></p>';
    check($form->validate(), 'The letter is valid: '.json_encode($form->getFirstErrors(), JSON_UNESCAPED_UNICODE));
    $message = $service->queue($form);
    $track = CmsCommTrack::find()->andWhere(['cms_comm_message_id' => (int)$message->id])->one();
    check($track && $track->utm_campaign === 'personal' && $track->model_code === CmsLead::class && (int)$track->model_id === (int)$lead->id
        && $track->recipient === mb_strtolower($email), 'The letter has a code of its recipient, the lead');
    $html = (string)$message->body_html;
    $code = $track->code;
    check(str_contains($html, 'https://skeeks.com/solutions/plitka?utm_source=crm&amp;utm_medium=email&amp;utm_campaign=personal&amp;sx='.$code),
        'The link in the text leaves with marks and the code');
    check(str_contains($html, 'https://skeeks-platform.ru/?utm_source=crm&amp;utm_medium=email&amp;utm_campaign=personal&amp;sx='.$code),
        'The link of the signature leaves with marks and the code');
    check(str_contains($html, 'utm_campaign=autumn&amp;utm_source=manager&amp;utm_medium=email&amp;sx='.$code), 'The manager\'s marks are kept');
    check(str_contains($html, 'href="https://ya.ru/"') && str_contains($html, 'href="mailto:info@skeeks.com"'), 'Other sites and mail are untouched');
    check(str_contains((string)$message->body_text, 'sx='.$code), 'The text part carries the same code');
    // The letter left two minutes ago: not a check by time.
    $message->updateAttributes(['status' => CmsCommMessage::STATUS_SENT, 'message_at' => time() - 120]);
    $track->updateAttributes(['created_at' => time() - 180]);

    // 2. A robot and curl: kept, not counted.
    $visits = new LinkVisits();
    $page1 = 'https://skeeks.com/solutions/plitka?utm_source=crm&utm_medium=email&utm_campaign=personal&sx='.$code;
    check($visits->seen($track, $page1, 'Плитка', '203.0.113.5', 'curl/8.4.0')['result'] === LinkVisits::RESULT_BOT, 'curl is a robot');
    $feed = static fn(string $event) => CmsLog::find()->andWhere(['log_type' => LinkVisits::LOG_TYPE, 'model_code' => CmsLead::class, 'model_id' => (int)$lead->id])
        ->andWhere(['like', 'data', '"track":'.(int)$track->id])->andWhere(['like', 'data', '"event":"'.$event.'"'])->count();
    check($feed(LinkVisits::EVENT_VISIT) == 0 && $feed(LinkVisits::EVENT_CHECKED) == 0, 'A robot writes nothing to the feed');

    // 3. A link check in an automated browser: once in the feed, no visit.
    check($visits->seen($track, $page1, 'Плитка', '203.0.113.6', CHROME, ['webdriver' => '1'] + PERSON)['result'] === LinkVisits::RESULT_CHECK, 'An automated browser is a link check');
    check($visits->seen($track, $page1, 'Плитка', '203.0.113.7', CHROME, ['webdriver' => '1'] + PERSON)['result'] === LinkVisits::RESULT_CHECK, 'Another checker');
    check($feed(LinkVisits::EVENT_CHECKED) == 1 && $feed(LinkVisits::EVENT_VISIT) == 0, '"Возможно, проверка ссылок" once per letter');

    // 4. An employee signed in to the CRM: not a visit.
    check($visits->seen($track, $page1, 'Плитка', '203.0.113.8', CHROME, PERSON, null, true)['result'] === LinkVisits::RESULT_STAFF, 'An employee is not a visit');

    // 5. A person: the first visit, the feed, the notification, the call task.
    $notifies = (int)CmsWebNotify::find()->andWhere(['cms_user_id' => (int)$admin->id])->count();
    $first = $visits->seen($track, $page1, 'Решения для магазинов плитки', '203.0.113.9', CHROME, PERSON);
    check($first['result'] === LinkVisits::RESULT_FIRST && !empty($first['token']), 'A person makes the first visit with a token: '.json_encode($first));
    check($feed(LinkVisits::EVENT_VISIT) == 1, 'The visit is in the lead feed');
    $track->refresh();
    check((int)$track->visits_count === 1 && $track->first_visit_at, 'The code counts the visit');
    check((int)CmsWebNotify::find()->andWhere(['cms_user_id' => (int)$admin->id])->count() === $notifies + 1, 'The sender is notified');
    $task = CmsTask::find()->andWhere(['executor_id' => (int)$admin->id, 'cms_lead_id' => (int)$lead->id])->andWhere(['like', 'name', 'Позвонить: клиент перешёл на сайт из письма'])
        ->orderBy(['id' => SORT_DESC])->one();
    check($task && in_array($task->status, CmsTask::activeStatuses(), false), 'The sender gets a call task');

    // 6. The next pages of the visit: one feed entry with the path.
    check($visits->page($first['token'], 'https://skeeks.com/works/sfery/plitka', 'Портфолио: плитка')['result'] === LinkVisits::RESULT_PAGE, 'The next page is added');
    check($visits->page($first['token'], 'https://skeeks.com/works/sfery/plitka', 'Портфолио: плитка')['result'] === LinkVisits::RESULT_PAGE, 'A reload is no new page');
    check($visits->page($first['token'], 'https://evil.example/x', 'Чужой')['result'] === LinkVisits::RESULT_FOREIGN, 'A page of another site is refused');
    $visit = CmsCommTrackVisit::find()->andWhere(['token' => $first['token']])->one();
    check(count($visit->getPageList()) === 2 && $visit->getPageList()[0]['url'] === 'https://skeeks.com/solutions/plitka?utm_source=crm&utm_medium=email&utm_campaign=personal',
        'The visit keeps two pages, without the code');
    $log = CmsLog::find()->andWhere(['log_type' => LinkVisits::LOG_TYPE, 'model_code' => CmsLead::class, 'model_id' => (int)$lead->id])
        ->andWhere(['like', 'data', '"visit":'.(int)$visit->id])->one();
    $rendered = (new VisitLogRenderer())->render($log);
    check(str_contains($rendered, 'Перешёл на сайт из письма') && str_contains($rendered, 'Решения для магазинов плитки') && str_contains($rendered, 'Портфолио: плитка')
        && str_contains($rendered, 'Проверка меток ссылок'), 'The feed entry shows the letter and the path: '.strip_tags($rendered));
    check(!str_contains($rendered, 'sx='), 'The feed shows no code');

    // 7. Another link of the letter in the same browser continues the visit; another browser is a new visit, no second task.
    check($visits->seen($track, 'https://skeeks-platform.ru/?sx='.$code, 'Платформа', '203.0.113.9', CHROME, PERSON, $first['token'])['result'] === LinkVisits::RESULT_CONTINUED,
        'The same browser continues its visit');
    $other = $visits->seen($track, $page1, 'Плитка', '198.51.100.20', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1', PERSON);
    check($other['result'] === LinkVisits::RESULT_COUNTED && $other['token'] !== $first['token'], 'Another browser is a new visit');
    check((int)CmsTask::find()->andWhere(['executor_id' => (int)$admin->id, 'cms_lead_id' => (int)$lead->id])->andWhere(['like', 'name', 'Позвонить: клиент перешёл%', false])
        ->andWhere(['status' => CmsTask::activeStatuses()])->count() === 1, 'No second call task while one is open');
    check($visits->page($first['token'], 'https://skeeks.com/about', 'О нас', time() + LinkVisits::IDLE + 60)['result'] === LinkVisits::RESULT_ENDED, 'A visit ends after the idle time');
    check($visits->page('nope', 'https://skeeks.com/about', 'О нас')['result'] === LinkVisits::RESULT_UNKNOWN, 'An unknown token is no visit');

    // 8. A reply quoting the letter: the quote keeps its links and code, the new text gets its own.
    $reply = new ComposeForm(['service' => $service]);
    $reply->cms_comm_account_id = (int)$message->cms_comm_account_id;
    $reply->to = $email;
    $reply->subject = 'Re: Проверка меток ссылок';
    $reply->reply_to_message_id = (int)$message->id;
    $reply->include_quote = 1;
    $reply->body_html = '<p>Ещё: <a href="https://skeeks.com/works">портфолио</a></p>';
    check($reply->validate(), 'The reply is valid');
    $replyMessage = $service->queue($reply);
    $replyTrack = CmsCommTrack::find()->andWhere(['cms_comm_message_id' => (int)$replyMessage->id])->one();
    check($replyTrack && $replyTrack->code !== $code, 'The reply has its own code');
    check(str_contains((string)$replyMessage->body_html, 'https://skeeks.com/works?utm_source=crm&amp;utm_medium=email&amp;utm_campaign=personal&amp;sx='.$replyTrack->code)
        && str_contains((string)$replyMessage->body_html, 'sx='.$code), 'The new link gets the new code, the quoted one keeps the old');
    check(substr_count((string)$replyMessage->body_html, 'sx=') === substr_count($html, 'sx=') + 1, 'The quote gets no second code');

    // 9. AI clients read the visit in the client history; links with a code are hidden from them.
    if (Yii::$app->has('cmsMcp') && ($client = \skeeks\cms\oauth2\models\CmsOauthClient::find()->orderBy(['id' => SORT_DESC])->one())) {
        $token = new \skeeks\cms\oauth2\models\CmsOauthAccessToken(['client_id' => (int)$client->id,
            'scopes' => json_encode(array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']))]);
        $history = Yii::$app->cmsMcp->executeTool('cms_crm_history_get', ['record_type' => 'lead', 'record_id' => (int)$lead->id, 'limit' => 50], $token, ['transport' => 'test']);
        $found = array_values(array_filter($history['items'] ?? [], static fn($i) => ($i['subtype'] ?? '') === 'site_visit'));
        check(array_filter($found, static fn($i) => str_contains((string)$i['summary'], 'Портфолио: плитка')), 'The history shows the visit with its pages');
        $json = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        check(!str_contains($json, 'sx='), 'No personal code reaches AI clients');
        $read = Yii::$app->cmsMcp->executeTool('cms_comm_message_get', ['id' => (int)$message->id], $token, ['transport' => 'test']);
        check(!str_contains(json_encode($read, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'sx='.$code), 'The letter read by AI hides the marked links');
        $track->refresh();
        check((int)$track->visits_count === 2, 'Reading by AI is no visit');
    } else {
        echo "SKIP history: skeeks/cms-mcp is not installed\n";
    }

    // 10. A campaign letter: its recipient "перешёл на сайт" at the first visit.
    // An own campaign: the stand may have none, and its real ones are not touched.
    $ownCampaign = new \skeeks\cms\communication\models\CmsCommCampaign(['cms_site_id' => $siteId, 'name' => 'Проверка: метки ссылок',
        'status' => \skeeks\cms\communication\models\CmsCommCampaign::STATUS_RUNNING, 'audience_type' => \skeeks\cms\communication\models\CmsCommCampaign::AUDIENCE_MANUAL]);
    $ownCampaign->save(false);
    $campaignId = (int)$ownCampaign->id;
    check($campaignId > 0, 'The test campaign is made');
    {
        $db->createCommand()->insert('{{%cms_comm_campaign_recipient}}', ['cms_comm_campaign_id' => $campaignId, 'record_type' => 'lead', 'record_id' => (int)$lead->id,
            'address' => $email, 'status' => 'sent', 'cms_comm_message_id' => (int)$replyMessage->id, 'sent_at' => time() - 120,
            'created_at' => time(), 'updated_at' => time()])->execute();
        $replyMessage->updateAttributes(['status' => CmsCommMessage::STATUS_SENT, 'message_at' => time() - 120, 'cms_comm_campaign_id' => $campaignId]);
        $replyTrack->updateAttributes(['cms_comm_campaign_id' => $campaignId, 'created_at' => time() - 180]);
        $visits->seen($replyTrack, 'https://skeeks.com/works?sx='.$replyTrack->code, 'Портфолио', '203.0.113.30', CHROME, PERSON);
        check((int)$db->createCommand('SELECT visited_at FROM {{%cms_comm_campaign_recipient}} WHERE cms_comm_message_id = :m', [':m' => (int)$replyMessage->id])->queryScalar() > 0,
            'The campaign recipient is "перешёл на сайт"');
        $campaign = \skeeks\cms\communication\models\CmsCommCampaign::findOne($campaignId);
        check(\skeeks\cms\communication\services\Campaigns::stats($campaign)['visited'] === 1, 'The campaign report counts the visit');
    }

    // 11. Marking off: the letter leaves as written.
    LinkTrackingSettings::reset($siteId, ['enabled' => false, 'domains' => ['skeeks.com'], 'taskOnVisit' => true]);
    $plain = new ComposeForm(['service' => $service]);
    $plain->cms_comm_account_id = (int)$message->cms_comm_account_id;
    $plain->to = $email;
    $plain->subject = 'Без меток';
    $plain->body_html = '<p><a href="https://skeeks.com/">сайт</a></p>';
    check($plain->validate(), 'The letter without marks is valid');
    $plainMessage = $service->queue($plain);
    check(!str_contains((string)$plainMessage->body_html, 'sx=') && !CmsCommTrack::find()->andWhere(['cms_comm_message_id' => (int)$plainMessage->id])->exists(),
        'With marking off no code is made');
} finally {
    $tx->rollBack();
    LinkTrackingSettings::reset();
}

echo "OK visits-stand: {$checks} checks\n";
