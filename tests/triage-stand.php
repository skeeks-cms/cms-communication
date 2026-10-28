<?php
/**
 * Core П2а in communications, on the stand database, rolled back: a reply
 * to a campaign becomes a deal in «Неразобранное» (source mailing, the reply
 * and the campaign letter linked, a repeated reply — the same deal) or a
 * task inside the open deal; letters of a request added to another deal are
 * linked to it (TriageLinks on crm.leadTriaged); import duplicates still
 * give the first, strongest match through the core check.
 *
 * TRIAGE_STAND_TEST=local php tests/triage-stand.php
 */
if (getenv('TRIAGE_STAND_TEST') !== 'local') {
    throw new RuntimeException('Set TRIAGE_STAND_TEST=local.');
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

use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\CampaignTasks;
use skeeks\cms\communication\services\CrmDuplicates;
use skeeks\cms\communication\services\MessageLinker;
use skeeks\cms\crm\LeadTriage;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\rbac\CmsManager;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks) {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
};

$transaction = Yii::$app->db->beginTransaction();
try {
    $site = \skeeks\cms\models\CmsSite::find()->default()->one();
    Yii::$app->skeeks->site = $site;
    $siteId = (int)$site->id;
    $admin = null;
    foreach (CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.is_active' => 1])->all() as $worker) {
        if (Yii::$app->authManager->checkAccess((int)$worker->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            $admin = $worker;
            break;
        }
    }
    $reply = CmsCommMessage::find()->andWhere(['direction' => CmsCommMessage::DIRECTION_IN, 'cms_site_id' => $siteId])->orderBy(['id' => SORT_DESC])->one();
    $sent = CmsCommMessage::find()->andWhere(['direction' => CmsCommMessage::DIRECTION_OUT, 'cms_site_id' => $siteId])->orderBy(['id' => SORT_DESC])->one();
    $check($admin && $reply && $sent, 'the stand needs an administrator, an incoming and an outgoing letter');
    Yii::$app->user->setIdentity($admin);

    $uniq = (string)mt_rand(100000, 999999);
    $company = new CmsCompany(['name' => 'Рассылка '.$uniq]);
    $check($company->save(), 'company saved');
    $campaign = new CmsCommCampaign(['name' => 'Тест П2а '.$uniq, 'cms_site_id' => $siteId]);
    $campaign->id = 900000000 + (int)$uniq;
    $recipient = new CmsCommCampaignRecipient([
        'cms_comm_campaign_id' => $campaign->id,
        'record_type' => 'company',
        'record_id' => (int)$company->id,
        'address' => 'reply-'.$uniq.'@skeeks.com',
        'name' => 'Ирина',
        'sender_user_id' => (int)$admin->id,
        'cms_comm_message_id' => (int)$sent->id,
    ]);
    $recipient->id = 900000000 + (int)$uniq;

    // A reply of a company without open deals: a deal in «Неразобранное».
    $lead = (new CampaignTasks())->onReply($campaign, $recipient, $reply);
    $check($lead instanceof CmsLead && $lead->status === CmsLead::STATUS_NEW && $lead->source_type === CmsLead::SOURCE_MAILING, 'a reply becomes an unsorted deal');
    $check((int)$lead->cms_company_id === (int)$company->id && $lead->source_name === 'Рассылка: '.$campaign->name, 'the deal knows its client and the campaign');
    $code = $lead->skeeksModelCode;
    $check(CmsCommLink::find()->andWhere(['model_code' => $code, 'model_id' => $lead->id, 'cms_comm_message_id' => $reply->id])->exists(), 'the reply is linked to the deal');
    $check(CmsCommLink::find()->andWhere(['model_code' => $code, 'model_id' => $lead->id, 'cms_comm_message_id' => $sent->id])->exists(), 'the campaign letter is linked to the deal');
    $again = (new CampaignTasks())->onReply($campaign, $recipient, $reply);
    $check($again instanceof CmsTask && (int)$again->cms_lead_id === (int)$lead->id, 'a second reply: a task inside the same open deal, no second deal');
    $check(CmsLead::find()->andWhere(['source_type' => CmsLead::SOURCE_MAILING, 'source_ref' => 'campaign-reply:'.$recipient->id])->count() == 1, 'one deal per recipient');

    // Letters of a request added to another deal follow it.
    $target = new CmsLead(['name' => 'Цель '.$uniq, 'cms_site_id' => $siteId, 'executor_id' => $admin->id, 'status' => CmsLead::STATUS_IN_WORK, 'cms_company_id' => $company->id]);
    $check($target->save(), 'target saved');
    $other = (new \skeeks\cms\services\CmsLeadService())->createFromSource(['cms_site_id' => $siteId, 'source_type' => CmsLead::SOURCE_FORM, 'source_ref' => 'p2a-comm-'.$uniq, 'name' => 'Обращение '.$uniq, 'status' => CmsLead::STATUS_NEW]);
    (new MessageLinker())->link($reply, [$other], CmsCommLink::TYPE_MANUAL, (int)$admin->id);
    (new LeadTriage())->merge($other, $target, (int)$admin->id);
    $check(CmsCommLink::find()->andWhere(['model_code' => $code, 'model_id' => $target->id, 'cms_comm_message_id' => $reply->id])->exists(), 'the letter of the request is linked to the target deal');
    $check(CmsCommLink::find()->andWhere(['model_code' => $code, 'model_id' => $other->id, 'cms_comm_message_id' => $reply->id])->exists(), 'the closed request keeps its history');

    // Import duplicates: the first, strongest match through the core check.
    $found = (new CrmDuplicates($siteId))->findMany(['x' => ['emails' => ['reply-'.$uniq.'@skeeks.com'], 'phones' => [], 'inn' => '', 'name' => 'Рассылка '.$uniq]]);
    $check(isset($found['x']) && $found['x']['by'] === 'email' && $found['x']['type'] === 'lead', 'an email match of a deal wins over a name');

    echo 'OK triage-stand: '.$checks." checks\n";
} finally {
    $transaction->rollBack();
}
