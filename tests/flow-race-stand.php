<?php
/**
 * Two events of one free client at once (П2д, F04): two processes — a reply
 * and a view of the same recipient — must make one deal and keep one main
 * responsible. Runs against the database of the local stand and COMMITS
 * (a lock is seen only between connections), then removes what it made —
 * also the feed, the journal of the access, the notifications and their
 * deliveries of its records, by exact pairs — and checks that nothing of it is left. Its records
 * are named «Проверка: гонка П2д …».
 *
 * FLOW_RACE_TEST=local php tests/flow-race-stand.php setup        → prints "<recipient id> <start time>"
 * FLOW_RACE_TEST=local php tests/flow-race-stand.php fire <reply|view|visit|request> <recipient id> <start time>
 * FLOW_RACE_TEST=local php tests/flow-race-stand.php check <recipient id>   → OK / FAIL, then cleans up
 */
if (getenv('FLOW_RACE_TEST') !== 'local') {
    throw new RuntimeException('Set FLOW_RACE_TEST=local.');
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
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\CampaignTasks;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;

$site = \skeeks\cms\models\CmsSite::find()->default()->one();
Yii::$app->skeeks->site = $site;
$siteId = (int)$site->id;
$step = $argv[1] ?? '';

if ($step === 'setup') {
    $worker = CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.is_active' => 1])->orderBy(['id' => SORT_ASC])->one();
    $sent = CmsCommMessage::find()->andWhere(['direction' => CmsCommMessage::DIRECTION_OUT, 'cms_site_id' => $siteId])->orderBy(['id' => SORT_DESC])->one();
    $u = (string)mt_rand(100000, 999999);
    $company = new CmsCompany(['name' => 'Проверка: гонка П2д '.$u]);
    $company->save(false);
    // п. 13а: a CRM client always has a main responsible; the events must not change it.
    (new \skeeks\cms\models\CmsCompany2manager(['cms_company_id' => (int)$company->id, 'cms_user_id' => (int)$worker->id]))->save(false);
    $campaign = new CmsCommCampaign(['name' => 'Проверка: гонка П2д '.$u, 'cms_site_id' => $siteId, 'lead_to' => 'sender', 'lead_on_view' => 1, 'lead_on_visit' => 1]);
    $campaign->save(false);
    $recipient = new CmsCommCampaignRecipient(['cms_comm_campaign_id' => (int)$campaign->id, 'record_type' => 'company', 'record_id' => (int)$company->id,
        'address' => 'race-'.$u.'@skeeks.com', 'sender_user_id' => (int)$worker->id, 'cms_comm_message_id' => $sent ? (int)$sent->id : null,
        'status' => CmsCommCampaignRecipient::STATUS_SENT]);
    $recipient->save(false);
    echo (int)$recipient->id.' '.(time() + 3)."\n";
    exit(0);
}

$recipient = CmsCommCampaignRecipient::findOne((int)($argv[$step === 'fire' ? 3 : 2] ?? 0));
if (!$recipient) {
    throw new RuntimeException('No recipient.');
}
$campaign = $recipient->campaign;
$companyId = (int)$recipient->record_id;

if ($step === 'fire') {
    $start = (int)($argv[4] ?? time());
    while (time() < $start) {
        usleep(20000);
    }
    $tasks = new CampaignTasks();
    $reply = CmsCommMessage::find()->andWhere(['direction' => CmsCommMessage::DIRECTION_IN, 'cms_site_id' => $siteId])->orderBy(['id' => SORT_DESC])->one();
    $result = ['reply' => fn() => $tasks->onReply($campaign, $recipient, $reply), 'view' => fn() => $tasks->onView($campaign, $recipient),
        'visit' => fn() => $tasks->onVisit($campaign, $recipient), 'request' => fn() => $tasks->onRequest($campaign, $recipient, 'Вопрос')][$argv[2]]();
    echo $argv[2].': '.($result ? get_class($result).' #'.$result->id : 'null')."\n";
    exit(0);
}

if ($step === 'check') {
    $db = Yii::$app->db;
    $ok = false;
    // Records the test made, as model_code => ids: their notifications go with them.
    $made = [CmsCompany::class => [$companyId], CmsCommCampaign::class => [(int)$campaign->id], CmsCommCampaignRecipient::class => [(int)$recipient->id]];
    try {
        $leads = CmsLead::find()->andWhere(['cms_company_id' => $companyId])->all();
        $mains = (new \yii\db\Query())->from('{{%cms_company2manager}}')->andWhere(['cms_company_id' => $companyId, 'is_main' => 1])->count();
        $ok = count($leads) === 1 && (int)$mains === 1;
        echo ($ok ? 'OK flow-race-stand' : 'FAIL flow-race-stand').': deals '.count($leads).', main responsibles '.$mains."\n";
    } finally {
        // Remove what the test made: its deals (with their tasks, links and feed), the recipient, the campaign, the
        // company; then their feed, journal of the access, notifications and deliveries by exact pairs only.
        $leadIds = array_map('intval', CmsLead::find()->select('id')->andWhere(['cms_company_id' => $companyId])->column());
        $taskIds = $leadIds ? array_map('intval', (new \yii\db\Query())->select('id')->from('{{%cms_task}}')->andWhere(['cms_lead_id' => $leadIds])->column()) : [];
        $made[CmsCompany::class][] = $companyId;
        $made[(new CmsCompany())->skeeksModelCode][] = $companyId;
        foreach ([CmsLead::class, (new CmsLead())->skeeksModelCode] as $code) {
            $made[$code] = array_merge($made[$code] ?? [], $leadIds);
        }
        foreach ([\skeeks\cms\models\CmsTask::class, (new \skeeks\cms\models\CmsTask())->skeeksModelCode] as $code) {
            $made[$code] = array_merge($made[$code] ?? [], $taskIds);
        }
        $pairs = ['or'];
        foreach ($made as $code => $ids) {
            if ($ids = array_values(array_unique(array_filter($ids)))) {
                $pairs[] = ['model_code' => $code, 'model_id' => $ids];
            }
        }
        $access = ['or', ['record_code' => 'company', 'record_id' => $companyId], ['cms_lead_id' => $leadIds ?: [0]]];
        $deliveriesReady = $db->getTableSchema('{{%cms_comm_notify_delivery}}', true) !== null;
        $accessReady = $db->getTableSchema('{{%cms_crm_access_log}}', true) !== null;
        $notifyIds = static fn() => array_map('intval', (new \yii\db\Query())->select('id')->from('{{%cms_web_notify}}')->andWhere($pairs)->column());
        $deliveryIds = static fn(array $notify) => $deliveriesReady && $notify
            ? array_map('intval', (new \yii\db\Query())->select('id')->from('{{%cms_comm_notify_delivery}}')->andWhere(['cms_web_notify_id' => $notify])->column()) : [];
        // Collected before the models go: a delivery outlives its notification (ON DELETE SET NULL).
        $notifies = $notifyIds();
        $sends = $deliveryIds($notifies);

        foreach (CmsLead::find()->andWhere(['id' => $leadIds ?: [0]])->all() as $lead) {
            $db->createCommand()->delete('{{%cms_task}}', ['cms_lead_id' => (int)$lead->id])->execute();
            $db->createCommand()->delete('{{%cms_comm_link}}', ['model_code' => [CmsLead::class, $lead->skeeksModelCode], 'model_id' => (int)$lead->id])->execute();
            $lead->delete();
        }
        $db->createCommand()->delete('{{%cms_company2manager}}', ['cms_company_id' => $companyId])->execute();
        $recipient->delete();
        $campaign->delete();
        if ($company = CmsCompany::findOne($companyId)) {
            $company->delete();
        }

        // After the models: their delete() writes its own entries.
        $db->createCommand()->delete('{{%cms_log}}', $pairs)->execute();
        if ($accessReady) {
            $db->createCommand()->delete('{{%cms_crm_access_log}}', $access)->execute();
        }
        $notifies = array_values(array_unique(array_merge($notifies, $notifyIds())));
        $sends = array_values(array_unique(array_merge($sends, $deliveryIds($notifies))));
        if ($sends) {
            $db->createCommand()->delete('{{%cms_comm_notify_delivery}}', ['id' => $sends])->execute();
        }
        if ($notifies) {
            $db->createCommand()->delete('{{%cms_web_notify}}', ['id' => $notifies])->execute();
        }

        $count = static fn(string $table, array $where) => (int)(new \yii\db\Query())->from($table)->andWhere($where)->count();
        $left = array_filter([
            'deals' => (int)CmsLead::find()->andWhere(['cms_company_id' => $companyId])->count(),
            'company' => CmsCompany::findOne($companyId) ? 1 : 0,
            'campaign' => $count('{{%cms_comm_campaign}}', ['id' => (int)$campaign->id]) + $count('{{%cms_comm_campaign_recipient}}', ['id' => (int)$recipient->id]),
            'links' => $count('{{%cms_company2manager}}', ['cms_company_id' => $companyId]) + ($leadIds ? $count('{{%cms_comm_link}}', ['model_code' => [CmsLead::class, (new CmsLead())->skeeksModelCode], 'model_id' => $leadIds]) : 0),
            'cms_log' => $count('{{%cms_log}}', $pairs),
            'cms_crm_access_log' => $accessReady ? $count('{{%cms_crm_access_log}}', $access) : 0,
            'cms_web_notify' => count($notifyIds()),
            'cms_comm_notify_delivery' => $sends ? $count('{{%cms_comm_notify_delivery}}', ['id' => $sends]) : 0,
        ]);
        if ($left) {
            echo 'FAIL flow-race-stand: left after cleaning up '.json_encode($left)."\n";
            $ok = false;
        } else {
            echo 'Cleaned up: no deals, company, links, feed, access journal, notifications or deliveries of the test are left'
                .' (notifications removed: '.count($notifies).', deliveries: '.count($sends).")\n";
        }
    }
    exit($ok ? 0 : 1);
}
throw new RuntimeException('Step: setup | fire | check.');
