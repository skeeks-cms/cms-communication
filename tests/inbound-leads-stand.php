<?php
/**
 * An incoming letter, chat message or call of a client and the deal of the
 * client (core crm\CrmInbound, owner's decision 29.10.2026), on the stand
 * database, rolled back:
 * - one open deal of the client — the letter goes to it too and the executor
 *   with «Свои сделки» sees it in the client card;
 * - two open deals — no guess: both executors are notified once, the page
 *   «Привязать к сделке» links the letter to one's own deal only;
 * - a reply in the conversation follows the deal of the earlier letter;
 * - a call by the phone of the client card — the same rule.
 *
 * INBOUND_LEADS_TEST=local php tests/inbound-leads-stand.php
 */
if (getenv('INBOUND_LEADS_TEST') !== 'local') {
    throw new RuntimeException('Set INBOUND_LEADS_TEST=local.');
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
// Only the bell: no messenger or e-mail delivery from the stand.
$c['components']['notifications']['channels'] = ['push' => null, 'messengers' => null, 'email' => null];
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\InboundLeads;
use skeeks\cms\communication\services\MessageLinker;
use skeeks\cms\crm\CrmAccess;
use skeeks\cms\crm\CrmInbound;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2manager;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsTelephonyCall;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;
use skeeks\cms\rbac\CmsManager;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException('FAIL: '.$message); } ++$checks; }

$site = \skeeks\cms\models\CmsSite::find()->default()->one();
Yii::$app->skeeks->site = $site;
$siteId = (int)$site->id;
$access = CrmAccess::instance();
$admin = null;
$plain = [];
foreach (CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.is_active' => 1])->orderBy(['id' => SORT_ASC])->all() as $worker) {
    if (Yii::$app->authManager->checkAccess((int)$worker->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
        $admin = $admin ?? $worker;
    } elseif (Yii::$app->authManager->checkAccess((int)$worker->id, 'cms/admin-lead')) {
        $plain[] = $worker;
    }
}
check($admin && count($plain) >= 2, 'the stand has an administrator and two employees with the deals right');
// Two employees, neither the head of the other (a head would own the deals of his subordinate).
[$w1, $w2] = [$plain[0], $plain[1]];
foreach ($plain as $a) {
    foreach ($plain as $b) {
        // $w1 does not own the deals of $w2 (he may be the subordinate of $w2: the stand may have one department).
        if ($a->id !== $b->id && !in_array((int)$b->id, $access->ownerIds($a), true)) {
            [$w1, $w2] = [$a, $b];
            break 2;
        }
    }
}

$transaction = Yii::$app->db->beginTransaction();
try {
    Yii::$app->user->setIdentity($admin);
    $uniq = (string)mt_rand(100000, 999999);
    $company = new CmsCompany(['name' => 'Входящие '.$uniq]);
    $company->save(false);
    CmsCompany2manager::deleteAll(['cms_company_id' => $company->id]);
    $access->setMain('company', (int)$company->id, (int)$admin->id);
    $access->grantLink('company', (int)$company->id, (int)$w1->id, CrmAccess::LEVEL_DEAL);
    $access->grantLink('company', (int)$company->id, (int)$w2->id, CrmAccess::LEVEL_DEAL);
    $leadOf = static function (CmsUser $executor, string $name) use ($company, $siteId): CmsLead {
        $lead = new CmsLead(['name' => $name, 'cms_site_id' => $siteId, 'cms_company_id' => $company->id, 'executor_id' => $executor->id, 'status' => CmsLead::STATUS_IN_WORK]);
        $lead->save(false);

        return $lead;
    };
    $letter = static function (string $subject, ?CmsCommConversation $conversation = null) use ($company, $siteId): CmsCommMessage {
        $conversation = $conversation ?: new CmsCommConversation(['cms_site_id' => $siteId, 'channel' => 'email', 'thread_key' => 'inbound-test-'.bin2hex(random_bytes(6)), 'subject' => $subject]);
        $conversation->save(false);
        $message = new CmsCommMessage(['cms_site_id' => $siteId, 'cms_comm_conversation_id' => $conversation->id, 'channel' => 'email',
            'direction' => CmsCommMessage::DIRECTION_IN, 'status' => CmsCommMessage::STATUS_RECEIVED, 'external_id' => 'inbound.'.bin2hex(random_bytes(8)),
            'from_address' => 'client@example.test', 'subject' => $subject, 'body_text' => 'Текст', 'is_internal' => 0, 'is_auto' => 0, 'message_at' => time()]);
        $message->save(false);
        // As receiving: the client by its address, then the earlier letters of the conversation.
        $linker = new MessageLinker();
        $linker->link($message, [$company], CmsCommLink::TYPE_AUTO);
        $linker->inheritFromConversation($message);

        return $message;
    };
    $inLead = static fn(CmsCommMessage $message, CmsLead $lead): bool => CmsCommLink::find()
        ->andWhere(['cms_comm_message_id' => $message->id, 'model_code' => $lead->skeeksModelCode, 'model_id' => $lead->id])->exists();
    $notified = static fn(CmsUser $user, string $key): int => (int)CmsWebNotify::find()->andWhere(['cms_user_id' => $user->id, 'event_key' => 'crm.inbound:'.$key])->count();

    // One open deal: the letter goes to it, the executor with «Свои сделки» sees it in the client card.
    $lead1 = $leadOf($w1, 'Сделка 1 '.$uniq);
    $one = $letter('Одна сделка '.$uniq);
    check(InboundLeads::route($one, 'написал') && $inLead($one, $lead1), 'one open deal of the client: the letter goes to it');
    Yii::$app->user->setIdentity($w1);
    $cardLogs = array_map('intval', CmsCompany::findOne($company->id)->getCardLogs()->select(\skeeks\cms\models\CmsLog::tableName().'.id')->column());
    $leadLog = (int)CmsCommLink::find()->select('cms_log_id')->andWhere(['cms_comm_message_id' => $one->id, 'model_code' => $lead1->skeeksModelCode, 'model_id' => $lead1->id])->scalar();
    check($access->clientDataLevel($w1, 'company', (int)$company->id) === CrmAccess::LEVEL_DEAL && in_array($leadLog, $cardLogs, true),
        '"deal": the executor sees the letter in the client card (through his deal)');
    Yii::$app->user->setIdentity($admin);

    // Two open deals: no guess, both executors are asked once.
    $lead2 = $leadOf($w2, 'Сделка 2 '.$uniq);
    $two = $letter('Две сделки '.$uniq);
    check(InboundLeads::route($two, 'написал') === null && !$inLead($two, $lead1) && !$inLead($two, $lead2), 'two open deals: the letter stays on the client');
    InboundLeads::route($two, 'написал');
    check($notified($w1, 'message:'.$two->id) === 1 && $notified($w2, 'message:'.$two->id) === 1, 'both executors are notified, once per letter');
    $note = CmsWebNotify::find()->andWhere(['cms_user_id' => $w1->id, 'event_key' => 'crm.inbound:message:'.$two->id])->one();
    check(mb_strpos((string)$note->name, 'привяжите к своей сделке') !== false && mb_strpos((string)$note->url, 'attach-inbound') !== false,
        'the notification leads to «Привязать к сделке»');
    $inbound = CrmInbound::instance();
    check(array_keys($inbound->leadsFor($w1, InboundLeads::TYPE, $two)) === [(int)$lead1->id], 'the page offers the employee his own deal only');
    try {
        $inbound->attach($w1, InboundLeads::TYPE, $two, (int)$lead2->id);
        check(false, 'a foreign deal is refused');
    } catch (\DomainException $e) {
        check(true, 'a foreign deal is refused');
    }
    $inbound->attach($w1, InboundLeads::TYPE, $two, (int)$lead1->id);
    check($inLead($two, $lead1) && !$inLead($two, $lead2), '«Привязать к сделке»: the letter is in his deal');

    // A reply in the conversation follows the deal of the earlier letter, not the rule of the client.
    $first = $letter('Переписка '.$uniq);
    (new MessageLinker())->link($first, [$lead2], CmsCommLink::TYPE_MANUAL, (int)$w2->id);
    $reply = $letter('Re: Переписка '.$uniq, CmsCommConversation::findOne($first->cms_comm_conversation_id));
    check(InboundLeads::route($reply, 'написал') === null && $inLead($reply, $lead2) && !$inLead($reply, $lead1)
        && $notified($w1, 'message:'.$reply->id) === 0, 'a reply in the conversation goes to the deal of the earlier letter, nobody is asked');

    // A call by the phone of the client card: the same rule.
    $call = new CmsTelephonyCall(['direction' => CmsTelephonyCall::DIRECTION_IN, 'status' => CmsTelephonyCall::STATUS_ANSWERED,
        'client_phone' => '+7 900 '.substr($uniq, 0, 3).'-'.substr($uniq, 3, 2).'-01', 'cms_company_id' => $company->id, 'provider_call_id' => 'inbound-'.$uniq]);
    $call->save(false);
    (new \skeeks\cms\services\CmsLeadTelephonyService())->attachToLead($call);
    $call->refresh();
    check(!$call->cms_lead_id && $notified($w1, 'call:'.$call->id) === 1 && $notified($w2, 'call:'.$call->id) === 1,
        'a call of a client with two deals: no deal, both executors are asked');
    CmsLead::updateAll(['status' => CmsLead::STATUS_SUCCESS], ['id' => $lead2->id]);
    $call2 = new CmsTelephonyCall(['direction' => CmsTelephonyCall::DIRECTION_IN, 'status' => CmsTelephonyCall::STATUS_ANSWERED,
        'client_phone' => $call->client_phone, 'cms_company_id' => $company->id, 'provider_call_id' => 'inbound2-'.$uniq]);
    $call2->save(false);
    (new \skeeks\cms\services\CmsLeadTelephonyService())->attachToLead($call2);
    $call2->refresh();
    check((int)$call2->cms_lead_id === (int)$lead1->id, 'a call of a client with one open deal goes to it');
} finally {
    $transaction->rollBack();
}

echo "OK inbound-leads-stand: $checks checks\n";
