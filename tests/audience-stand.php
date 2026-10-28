<?php
/**
 * П5 on the stand database, rolled back: a base imported into a list
 * becomes companies and contacts without deals (new cards cold with their
 * source, found cards reused, a current client, a recent refusal, an
 * opt-out, a similar name and a client of another manager left out with the
 * reason, a second row of a new company joins it); "Добавить адреса" splits
 * pasted lines; a signal of interest makes the card warm and never a deal;
 * a deal member of a list moves to its company; an import of requests adds
 * to an open deal.
 *
 * AUDIENCE_STAND_TEST=local php tests/audience-stand.php
 */
if (getenv('AUDIENCE_STAND_TEST') !== 'local') {
    throw new RuntimeException('Set AUDIENCE_STAND_TEST=local.');
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

use skeeks\cms\communication\models\CmsCommAudience;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommImport;
use skeeks\cms\communication\models\CmsCommImportRow;
use skeeks\cms\communication\models\CmsCommListMember;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\Audience;
use skeeks\cms\communication\services\AudienceImport;
use skeeks\cms\communication\services\AudienceMigration;
use skeeks\cms\communication\services\LeadImport;
use skeeks\cms\communication\services\RecipientLists;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2manager;
use skeeks\cms\models\CmsCompanyEmail;
use skeeks\cms\models\CmsCompanyPhone;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
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
    $manager = null;
    foreach (CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.is_active' => 1])->all() as $worker) {
        $isAdmin = Yii::$app->authManager->checkAccess((int)$worker->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS);
        if ($isAdmin && !$admin) {
            $admin = $worker;
        } elseif (!$isAdmin && !$manager) {
            $manager = $worker;
        }
    }
    $check($admin && $manager, 'the stand needs an administrator and a manager');
    Yii::$app->user->setIdentity($manager);
    $u = (string)mt_rand(100000, 999999);
    $mail = static fn(string $name) => 'p5-'.$name.'-'.$u.'@skeeks.com';
    $leadsBefore = (int)CmsLead::find()->count();

    // Existing cards: the manager's company, a current client, a refusal, a client of the administrator.
    $makeCompany = static function (string $name, string $email, int $managerId) use ($check) {
        $company = new CmsCompany(['name' => $name]);
        $check($company->save(), 'company '.$name);
        (new CmsCompany2manager(['cms_company_id' => (int)$company->id, 'cms_user_id' => $managerId]))->save(false);
        $check((new CmsCompanyEmail(['cms_company_id' => (int)$company->id, 'value' => $email]))->save(), 'email of '.$name);

        return $company;
    };
    $own = $makeCompany('Своя П5 '.$u, $mail('own'), (int)$manager->id);
    $current = $makeCompany('Клиент П5 '.$u, $mail('current'), (int)$manager->id);
    $deal = new CmsDeal(['name' => 'Хостинг '.$u, 'description' => '', 'is_periodic' => 0, 'is_auto' => 0, 'cms_company_id' => (int)$current->id, 'is_active' => 1, 'start_at' => time() - 86400, 'amount' => 100, 'currency_code' => 'RUB', 'cms_deal_type_id' => (int)\skeeks\cms\models\CmsDealType::find()->select('id')->scalar()]);
    $deal->save(false);
    $refused = $makeCompany('Отказ П5 '.$u, $mail('refused'), (int)$manager->id);
    Yii::$app->db->createCommand()->insert(CmsLead::tableName(), ['cms_site_id' => $siteId, 'name' => 'Отказ '.$u, 'status' => CmsLead::STATUS_REJECTED,
        'cms_company_id' => (int)$refused->id, 'source_type' => CmsLead::SOURCE_MANUAL, 'created_at' => time(), 'updated_at' => time(), 'reject_reason' => 'дорого'])->execute();
    $foreign = $makeCompany('Чужая П5 '.$u, $mail('foreign'), (int)$admin->id);
    $similar = $makeCompany('Похожая П5 '.$u, $mail('similar'), (int)$manager->id);
    $optout = $mail('optout');
    \skeeks\cms\communication\services\MailingConsents::suppress(\skeeks\cms\communication\services\MailingContacts::get($siteId, 'email', $optout),
        \skeeks\cms\communication\models\CmsCommSuppression::REASON_ALL, 'test');

    $list = (new RecipientLists())->create($siteId, 'П5 '.$u, $manager);
    $rows = [
        ['Компания', 'Контакт', 'Email', 'Телефон'],
        ['ООО «Новая П5 '.$u.'»', 'Андрей Смирнов', $mail('new1'), '+7 900 1'.substr($u, 0, 3).'-'.substr($u, 3, 2).'-01'],
        ['ООО «Новая П5 '.$u.'»', 'Ольга Иванова', $mail('new2'), ''],
        ['', '', $mail('bare'), ''],
        ['Своя', '', $mail('own'), ''],
        ['Клиент', '', $mail('current'), ''],
        ['Отказ', '', $mail('refused'), ''],
        ['Чужая', '', $mail('foreign'), ''],
        ['ООО «Похожая П5 '.$u.'»', '', $mail('similar2'), ''],
        ['Отписка', '', $optout, ''],
        ['Повтор', '', $mail('new1'), ''],
        ['Пусто', 'Пётр', '', ''],
    ];
    $content = implode("\n", array_map(static fn($r) => implode(';', $r), $rows));
    $import = LeadImport::create($siteId, $content, 'p5-'.$u.'.csv', true);
    $import->mode = CmsCommImport::MODE_AUDIENCE;
    $import->cms_comm_list_id = (int)$list->id;
    $import->executor_id = (int)$manager->id;
    $import->created_by = (int)$manager->id;
    $import->cms_comm_topic_id = (int)\skeeks\cms\communication\models\CmsCommTopic::find()->select('id')->andWhere(['cms_site_id' => $siteId])->scalar() ?: null;
    $import->save(false);

    $preview = AudienceImport::preview($import);
    $counts = $preview['counts'];
    $check($counts['created'] === 3 && $counts['duplicate'] === 2 && $counts['excluded'] === 5 && $counts['skipped'] === 1,
        'preview: 3 new, 2 found (own and a repeat), 5 excluded, 1 skipped: '.json_encode($counts));
    $byRow = [];
    foreach ($preview['rows'] as $o) {
        $byRow[(int)$o['row']->row_num] = $o;
    }
    $check(strpos((string)$byRow[6]['reason'], 'Действующий клиент') === 0, 'a current client is left out');
    $check(strpos((string)$byRow[7]['reason'], 'Недавний отказ') === 0, 'a recent refusal is left out');
    $check(strpos((string)$byRow[8]['reason'], 'Клиент другого менеджера') === 0 && strpos((string)$byRow[8]['reason'], (string)$foreign->id) === false,
        'a client of another manager is left out by name and responsible, without its id');
    $check(strpos((string)$byRow[9]['reason'], 'Похожая компания') === 0, 'a similar name is not linked and not duplicated');
    $check(strpos((string)$byRow[10]['reason'], 'Отписался') === 0, 'an opted-out address is left out');

    AudienceImport::run($import);
    $import->refresh();
    $check($import->status === CmsCommImport::STATUS_DONE && (int)$import->created_count === 3 && (int)$import->excluded_count === 5, 'the run gives the preview');
    $check((int)CmsLead::find()->count() === $leadsBefore + 1, 'no deals are made by the import (one is the refusal of the test)');
    $row2 = CmsCommImportRow::findOne(['cms_comm_import_id' => (int)$import->id, 'row_num' => 2]);
    $row3 = CmsCommImportRow::findOne(['cms_comm_import_id' => (int)$import->id, 'row_num' => 3]);
    $check($row2->record_type === 'company' && (int)$row3->record_id === (int)$row2->record_id, 'the second contact joins the new company of the file');
    $newCompany = CmsCompany::findOne((int)$row2->record_id);
    $check(count($newCompany->users) === 2, 'the new company has both contacts');
    $origin = Audience::of($siteId, $newCompany);
    $check($origin && $origin->state === CmsCommAudience::STATE_COLD && strpos((string)$origin->source_name, 'Рассылка: база') === 0, 'a new card is cold with its source');
    $row4 = CmsCommImportRow::findOne(['cms_comm_import_id' => (int)$import->id, 'row_num' => 4]);
    $bare = CmsUser::findOne((int)$row4->record_id);
    $check($row4->record_type === 'client' && $bare && $bare->email === $mail('bare') && !$bare->password_hash, 'a bare address gets a contact card, without a password');
    $members = CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$list->id, 'removed_at' => null])->count();
    $check((int)$members === 3, 'the list has the new company, the contact and the own company: '.$members);
    $check(CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$list->id, 'record_type' => 'company', 'record_id' => (int)$own->id])->exists(), 'a found card joins the list');
    $check(!CmsCompanyEmail::find()->andWhere(['cms_company_id' => (int)$own->id])->andWhere(['<>', 'value', $mail('own')])->exists(), 'the found card is not changed');

    // "Добавить адреса".
    $lines = AudienceImport::fromLines($list, $mail('bare')."\nООО «Строка П5 ".$u."», Павел Адресов, ".$mail('line').", +7 900 777-".substr($u, 0, 2).'-'.substr($u, 2, 2)."\nНаталья Новикова ".$mail('nat'), $manager);
    $lines->created_by = (int)$manager->id;
    $lines->save(false, ['created_by']);
    $p = AudienceImport::preview($lines);
    $check($p['counts']['duplicate'] === 1 && $p['counts']['created'] === 2, '"Добавить адреса": one found, two new: '.json_encode($p['counts']));
    $nat = $p['rows'][2]['data'];
    $check($nat['company'] === '' && $nat['name'] === 'Наталья Новикова', 'a single name beside an address is a person');
    $check(AudienceImport::looksLikeCompany('ООО Ромашка') && !AudienceImport::looksLikeCompany('Иван Петров'), 'a company name is told from a person');

    // A signal of interest: warm, no deal.
    $message = CmsCommMessage::find()->andWhere(['cms_site_id' => $siteId, 'direction' => CmsCommMessage::DIRECTION_OUT])->orderBy(['id' => SORT_DESC])->one();
    $check((bool)$message, 'the stand needs an outgoing letter');
    $recipient = new CmsCommCampaignRecipient(['cms_comm_campaign_id' => null, 'record_type' => 'company', 'record_id' => (int)$newCompany->id, 'status' => 'sent']);
    $warm = Audience::warm($siteId, [$newCompany], CmsCommAudience::WARM_PRESENTATION, null, (int)$message->id);
    $check(count($warm) === 1 && Audience::of($siteId, $newCompany)->isWarm(), 'a view of a presentation makes the company warm');
    $check(!Audience::warm($siteId, [$newCompany], CmsCommAudience::WARM_VISIT), 'the first signal is kept');
    $check(strpos(Audience::text(Audience::of($siteId, $newCompany)), 'Тёплый: смотрел Презентацию') === 0, 'the card says it');
    $check((int)CmsLead::find()->count() === $leadsBefore + 1, 'a signal never makes a deal');

    // A deal member of a list moves to its company; the deal stays.
    $lead = new CmsLead(['cms_site_id' => $siteId, 'name' => 'Член списка '.$u, 'cms_company_id' => (int)$own->id, 'status' => CmsLead::STATUS_NEW, 'source_type' => CmsLead::SOURCE_MANUAL]);
    $lead->save(false);
    $other = (new RecipientLists())->create($siteId, 'Старый П5 '.$u, $manager);
    (new RecipientLists())->add($other, $lead, $manager, CmsCommListMember::VIA_IMPORT, 'import:1', false);
    $plan = array_values(array_filter(AudienceMigration::leadMembers(false), static fn($r) => $r['list'] === (int)$other->id));
    $check($plan && $plan[0]['action'] === 'company' && $plan[0]['target'] === 'company:'.$own->id, 'the plan moves the member to the deal company');
    AudienceMigration::leadMembers(true);
    $check(CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$other->id, 'record_type' => 'company', 'record_id' => (int)$own->id, 'removed_at' => null,
        'added_via' => CmsCommListMember::VIA_IMPORT])->exists(), 'the company is in the list with the origin of the member');
    $check(CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$other->id, 'record_type' => 'lead'])->andWhere(['not', ['removed_at' => null]])->exists()
        && CmsLead::find()->andWhere(['id' => (int)$lead->id])->exists(), 'the deal member is marked removed, the deal stays');
    $again = array_values(array_filter(AudienceMigration::leadMembers(false), static fn($r) => $r['list'] === (int)$other->id));
    $check(!$again, 'a second run has nothing to move');

    // An import of requests: an open deal of the client is added to, not repeated.
    Yii::$app->user->setIdentity($admin);
    $requests = LeadImport::create($siteId, "Компания;Email\nСвоя;".$mail('own')."\nНовая заявка;".$mail('request'), null, true);
    $requests->created_by = (int)$admin->id;
    $requests->save(false, ['created_by']);
    $r = LeadImport::preview($requests);
    $check($r['counts']['duplicate'] === 1 && $r['counts']['created'] === 1 && strpos((string)$r['rows'][0]['reason'], 'Дополнит открытую сделку') === 0,
        'requests: the client with an open deal adds to it, a new one becomes a deal: '.json_encode($r['counts']));

    // Another manager's client: the preview names the responsible (the names of CrmAccess::presentCandidate(), not arrays).
    $check(strpos((string)$byRow[8]['reason'], 'ответственный '.trim((string)$admin->shortDisplayName)) !== false
        && strpos((string)$byRow[8]['reason'], 'Array') === false, 'a foreign client names its responsible: '.$byRow[8]['reason']);

    // An open deal of another manager's client is not added to by an import of requests: a new deal in «Неразобранное».
    $foreignLead = new CmsLead(['cms_site_id' => $siteId, 'name' => 'Чужая сделка '.$u, 'cms_company_id' => (int)$foreign->id, 'source_type' => CmsLead::SOURCE_MANUAL]);
    $foreignLead->detachBehaviors();
    $foreignLead->status = CmsLead::STATUS_IN_WORK;
    $foreignLead->executor_id = (int)$admin->id;
    $foreignLead->created_at = $foreignLead->updated_at = time();
    $foreignLead->save(false);
    Yii::$app->user->setIdentity($manager);
    $theirs = LeadImport::create($siteId, "Компания;Email\nЧужая;".$mail('foreign'), null, true);
    $theirs->created_by = (int)$manager->id;
    $theirs->executor_id = (int)$manager->id;
    $theirs->save(false, ['created_by', 'executor_id']);
    $t = LeadImport::preview($theirs);
    $check($t['counts']['created'] === 1 && $t['counts']['duplicate'] === 0 && strpos((string)$t['rows'][0]['reason'], 'Клиент другого менеджера') === 0,
        'requests: a foreign open deal is not added to: '.json_encode($t['counts']));
    $activities = static fn() => (int)(new \yii\db\Query())->from('{{%cms_log}}')->andWhere(['model_code' => $foreignLead->skeeksModelCode, 'model_id' => (int)$foreignLead->id])->count();
    $before = $activities();
    LeadImport::run($theirs);
    $theirs->refresh();
    $made = CmsLead::find()->andWhere(['source_ref' => 'comm-import:'.(int)$theirs->id.':2'])->one();
    $check($activities() === $before && $made && !$made->executor_id && $made->status === CmsLead::STATUS_NEW,
        'the foreign deal is untouched, the request waits in «Неразобранное» without the import executor');

    // П2д: a base without a responsible is the common pool — new cards get no manager («Добавить адреса» by default).
    $pool = AudienceImport::fromLines($list, $mail('pool').', Пул Пулов', $manager);
    $check(!$pool->executor_id, '"Добавить адреса": no responsible by default');
    AudienceImport::run($pool);
    $poolRow = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$pool->id])->one();
    $check($poolRow && $poolRow->record_type === 'client' && !\skeeks\cms\models\CmsUser2manager::find()->andWhere(['client_id' => (int)$poolRow->record_id])->exists(),
        'a card of the pool has no manager');
    $mine = AudienceImport::fromLines($list, $mail('mine'), $manager, (int)$manager->id);
    AudienceImport::run($mine);
    $mineRow = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$mine->id])->one();
    $check($mineRow && \skeeks\cms\models\CmsUser2manager::find()->andWhere(['client_id' => (int)$mineRow->record_id, 'worker_id' => (int)$manager->id])->exists(),
        '«Я»: the card is the manager\'s');

    // П2д: the flow of a campaign makes deals (ARCHITECTURE.md п. 13, «Ручное предложение и поток»).
    $sentLetter = CmsCommMessage::find()->andWhere(['direction' => CmsCommMessage::DIRECTION_OUT, 'cms_site_id' => $siteId])->orderBy(['id' => SORT_DESC])->one();
    $replyLetter = CmsCommMessage::find()->andWhere(['direction' => CmsCommMessage::DIRECTION_IN, 'cms_site_id' => $siteId])->orderBy(['id' => SORT_DESC])->one();
    $check($sentLetter && $replyLetter, 'the stand needs an incoming and an outgoing letter');
    $mailbox = static function (array $senders) use ($siteId, $u) {
        $account = new \skeeks\cms\communication\models\CmsCommAccount(['cms_site_id' => $siteId, 'channel' => 'email',
            'address' => 'p2d-'.count($senders).'-'.$u.'@skeeks.com', 'is_active' => 1]);
        $account->save(false);
        foreach ($senders as $id) {
            (new \skeeks\cms\communication\models\CmsCommAccountAccess(['cms_comm_account_id' => (int)$account->id, 'cms_user_id' => $id,
                'read_level' => 'all', 'send_level' => 'own']))->save(false);
        }

        return $account;
    };
    $own = $mailbox([(int)$manager->id]);
    $shared = $mailbox([(int)$manager->id, (int)$admin->id]);
    $campaign = new \skeeks\cms\communication\models\CmsCommCampaign(['name' => 'Тест П2д '.$u, 'cms_site_id' => $siteId]);
    $campaign->save(false);
    $replyOf = static function (CmsCompany $company, $account) use ($campaign, $manager, $sentLetter, $u) {
        $recipient = new CmsCommCampaignRecipient(['cms_comm_campaign_id' => (int)$campaign->id, 'record_type' => 'company', 'record_id' => (int)$company->id,
            'address' => 'r-'.$company->id.'-'.$u.'@skeeks.com', 'sender_user_id' => (int)$manager->id, 'cms_comm_account_id' => (int)$account->id,
            'cms_comm_message_id' => (int)$sentLetter->id, 'status' => CmsCommCampaignRecipient::STATUS_SENT]);
        $recipient->save(false);

        return $recipient;
    };
    $tasks = new \skeeks\cms\communication\services\CampaignTasks();
    $campaigns = \skeeks\cms\communication\services\Campaigns::class;
    $access = \skeeks\cms\crm\CrmAccess::instance();
    $check($campaign->hasFlow() && $campaign->lead_to === 'sender' && (int)$campaign->lead_on_view === 1 && $campaign->new_card_owner === 'none',
        'the flow defaults of a new campaign: deals to the sender, a view makes a deal, new companies to the pool');
    $sales = \skeeks\cms\crm\OutboundSales::class;
    $pipeline = class_exists($sales) ? $sales::pipeline($siteId) : null;
    $stageId = static function (string $role) use ($pipeline, $sales) {
        $stage = $pipeline && defined($sales.'::ROLE_'.strtoupper($role)) ? $sales::stage($pipeline, constant($sales.'::ROLE_'.strtoupper($role))) : null;

        return $stage ? (int)$stage->id : null;
    };

    // A reply makes a deal of the sender in «Исходящие продажи» at «Ответил».
    $client = $makeCompany('Свой клиент П2д '.$u, $mail('p2d-own'), (int)$manager->id);
    $sentOwn = $replyOf($client, $own);
    $lead = $tasks->onReply($campaign, $sentOwn, $replyLetter);
    $check($lead instanceof CmsLead && (int)$lead->executor_id === (int)$manager->id && $lead->status === CmsLead::STATUS_IN_WORK,
        'a reply: the deal is the sender\'s at once');
    if ($pipeline) {
        $check((int)$lead->cms_lead_pipeline_id === (int)$pipeline->id && (!$stageId('replied') || (int)$lead->cms_lead_stage_id === $stageId('replied')),
            'the deal is in «Исходящие продажи» at «Ответил»');
    }
    // The campaign decides, not the mailbox: a shared one goes to the sender too.
    $client2 = $makeCompany('Общий ящик П2д '.$u, $mail('p2d-shared'), (int)$manager->id);
    $lead2 = $tasks->onReply($campaign, $replyOf($client2, $shared), $replyLetter);
    $check($lead2 instanceof CmsLead && (int)$lead2->executor_id === (int)$manager->id, 'a shared mailbox: the deal still goes where the campaign says');
    // A company of the pool: the sender gets the deal and becomes its main responsible («Взять в работу»).
    $poolCompany = new CmsCompany(['name' => 'Пул П2д '.$u]);
    $poolCompany->save(false);
    $lead3 = $tasks->onReply($campaign, $replyOf($poolCompany, $own), $replyLetter);
    $check($lead3 instanceof CmsLead && (int)$lead3->executor_id === (int)$manager->id && $access->mainResponsibleId('company', (int)$poolCompany->id) === (int)$manager->id,
        'a company of the pool: the deal is the sender\'s, he becomes the main responsible');
    // A protected client of another main responsible: its main responsible gets the deal.
    $protection = \skeeks\cms\crm\CrmProtection::class;
    if (class_exists($protection) && $protection::ready()) {
        $guarded = $makeCompany('Защита П2д '.$u, $mail('p2d-guarded'), (int)$admin->id);
        (new $protection())->setAlways('company', (int)$guarded->id, true);
        $lead4 = $tasks->onReply($campaign, $replyOf($guarded, $own), $replyLetter);
        $check($lead4 instanceof CmsLead && (int)$lead4->executor_id === (int)$admin->id, 'a protected client: the deal is its main responsible\'s');
    }
    // «Неразобранное» by the campaign.
    $unsorted = new \skeeks\cms\communication\models\CmsCommCampaign(['name' => 'Тест П2д разбор '.$u, 'cms_site_id' => $siteId, 'lead_to' => 'unsorted']);
    $unsorted->save(false);
    $client5 = $makeCompany('Разбор П2д '.$u, $mail('p2d-unsorted'), (int)$manager->id);
    $r5 = new CmsCommCampaignRecipient(['cms_comm_campaign_id' => (int)$unsorted->id, 'record_type' => 'company', 'record_id' => (int)$client5->id,
        'address' => $mail('p2d-unsorted'), 'sender_user_id' => (int)$manager->id, 'cms_comm_account_id' => (int)$own->id, 'status' => CmsCommCampaignRecipient::STATUS_SENT]);
    $r5->save(false);
    $lead5 = $tasks->onReply($unsorted, $r5, $replyLetter);
    $check($lead5 instanceof CmsLead && !$lead5->executor_id && $lead5->status === CmsLead::STATUS_NEW, '«Куда сделка: Неразобранное»');

    // A request from the letter continues the open deal.
    $task = $tasks->onRequest($campaign, $sentOwn, 'Вопрос: сколько стоит?');
    $check($task instanceof \skeeks\cms\models\CmsTask && (int)$task->cms_lead_id === (int)$lead->id && strpos($task->name, 'Связаться:') === 0,
        'a request of a client with an open deal: a task in it');

    // A view makes a deal by default (at «Смотрел»), then no call task; a second view adds to the open deal.
    $viewCompany = $makeCompany('Просмотр П2д '.$u, $mail('p2d-view'), (int)$manager->id);
    $viewer = $replyOf($viewCompany, $own);
    $viewLead = $tasks->onView($campaign, $viewer);
    $check($viewLead instanceof CmsLead && (int)$viewLead->executor_id === (int)$manager->id && strpos($viewLead->name, 'Смотрел Презентацию') === 0
        && (!$stageId('viewed') || (int)$viewLead->cms_lead_stage_id === $stageId('viewed')), 'a view makes a deal of the sender at «Смотрел»');
    $check($tasks->onView($campaign, $viewer) instanceof CmsLead && CmsLead::find()->andWhere(['cms_company_id' => (int)$viewCompany->id])->count() == 1,
        'a second view adds to the open deal');
    $sentLetter->updateAttributes(['cms_comm_campaign_id' => (int)$campaign->id]);
    $check($campaigns::taskOnOpen((int)$sentLetter->id) === false && $campaigns::taskOnOpen((int)$sentLetter->id, 'visit') === true,
        'no call task when the view makes a deal; a visit without a deal — the task');
    $campaign->lead_on_view = 0;
    $campaign->save(false, ['lead_on_view']);
    $quiet = $replyOf($makeCompany('Без сделки П2д '.$u, $mail('p2d-quiet'), (int)$manager->id), $own);
    $check($tasks->onView($campaign, $quiet) === null && $campaigns::taskOnOpen((int)$sentLetter->id) === true,
        'a view without «сделка при просмотре»: no deal, the call task instead');
    $check($tasks->onVisit($campaign, $quiet) === null, 'a visit makes no deal by default');
    $campaign->lead_on_visit = 1;
    $visitLead = $tasks->onVisit($campaign, $quiet);
    $check($visitLead instanceof CmsLead && strpos($visitLead->name, 'Перешёл на сайт') === 0, '«сделка при переходе по ссылке»');

    // F04: the pool is decided BEFORE the deal (the deal itself is a history): a free client gets the receiver
    // as its main responsible, a client with a history or with a main one keeps its owner, a protected one goes
    // to its main responsible — for every event and every receiver; a repeated event changes nothing.
    $matrixCampaign = static function (string $to) use ($siteId, $u, $admin) {
        $c = new \skeeks\cms\communication\models\CmsCommCampaign(['name' => 'Матрица П2д '.$to.' '.$u, 'cms_site_id' => $siteId,
            'lead_to' => $to, 'lead_worker_id' => $to === 'worker' ? (int)$admin->id : null, 'lead_on_view' => 1, 'lead_on_visit' => 1]);
        $c->save(false);

        return $c;
    };
    $fire = static function (string $kind, $c, CmsCommCampaignRecipient $r) use ($tasks, $replyLetter) {
        switch ($kind) {
            case 'reply': return $tasks->onReply($c, $r, $replyLetter);
            case 'request': return $tasks->onRequest($c, $r, 'Вопрос: сколько стоит?');
            case 'view': return $tasks->onView($c, $r);
            default: return $tasks->onVisit($c, $r);
        }
    };
    $recipientOf = static function ($c, CmsCompany $company) use ($manager, $own, $sentLetter, $u) {
        $r = new CmsCommCampaignRecipient(['cms_comm_campaign_id' => (int)$c->id, 'record_type' => 'company', 'record_id' => (int)$company->id,
            'address' => 'm-'.$company->id.'-'.$u.'@skeeks.com', 'sender_user_id' => (int)$manager->id, 'cms_comm_account_id' => (int)$own->id,
            'cms_comm_message_id' => (int)$sentLetter->id, 'status' => CmsCommCampaignRecipient::STATUS_SENT]);
        $r->save(false);

        return $r;
    };
    $leadsOf = static fn(CmsCompany $company) => CmsLead::find()->andWhere(['cms_company_id' => (int)$company->id])->all();
    $n = 0;
    foreach (['sender' => (int)$manager->id, 'worker' => (int)$admin->id, 'unsorted' => null] as $to => $receiverId) {
        $c = $matrixCampaign($to);
        foreach (['reply', 'request', 'view', 'visit'] as $kind) {
            $n++;
            // A free client of the pool.
            $free = new CmsCompany(['name' => 'Пул '.$to.' '.$kind.' '.$u]);
            $free->save(false);
            $r = $recipientOf($c, $free);
            $fire($kind, $c, $r);
            $leads = $leadsOf($free);
            $main = $access->mainResponsibleId('company', (int)$free->id);
            $check(count($leads) === 1 && (int)$leads[0]->executor_id === (int)$receiverId && $main === $receiverId,
                'free client, '.$kind.' → '.$to.': one deal of the receiver, the receiver is the main one ('.json_encode([count($leads), $leads[0]->executor_id ?? null, $main]).')');
            // The same event again: still one deal, the same main.
            $fire($kind, $c, $r);
            $check(count($leadsOf($free)) === 1 && $access->mainResponsibleId('company', (int)$free->id) === $receiverId, 'free client, '.$kind.' → '.$to.': a repeat changes nothing');

            // A client with a history (an earlier closed deal) and no main: not of the pool, never taken by the flow.
            $old = new CmsCompany(['name' => 'История '.$to.' '.$kind.' '.$u]);
            $old->save(false);
            Yii::$app->db->createCommand()->insert(CmsLead::tableName(), ['cms_site_id' => $siteId, 'name' => 'Старая сделка '.$u, 'status' => CmsLead::STATUS_REJECTED,
                'cms_company_id' => (int)$old->id, 'source_type' => CmsLead::SOURCE_MANUAL, 'created_at' => time() - 86400 * 400, 'updated_at' => time() - 86400 * 400])->execute();
            $fire($kind, $c, $recipientOf($c, $old));
            $check($access->mainResponsibleId('company', (int)$old->id) === null, 'a client with a history, '.$kind.' → '.$to.': the flow sets no main responsible');

            // A client with a main responsible (the administrator): it stays.
            if ($to !== 'worker') {
                $owned = $makeCompany('Свой у админа '.$to.' '.$kind.' '.$u, $mail('m-'.$n), (int)$admin->id);
                $fire($kind, $c, $recipientOf($c, $owned));
                $check($access->mainResponsibleId('company', (int)$owned->id) === (int)$admin->id, 'a client with a main responsible, '.$kind.' → '.$to.': the main one stays');
            }

            // A protected client of the administrator: its deal is the administrator's.
            if (class_exists($protection) && $protection::ready() && $to !== 'unsorted') {
                $guarded2 = $makeCompany('Защита '.$to.' '.$kind.' '.$u, $mail('g-'.$n), (int)$admin->id);
                (new $protection())->setAlways('company', (int)$guarded2->id, true);
                $fire($kind, $c, $recipientOf($c, $guarded2));
                $gl = $leadsOf($guarded2);
                $check(count($gl) === 1 && (int)$gl[0]->executor_id === (int)$admin->id && $access->mainResponsibleId('company', (int)$guarded2->id) === (int)$admin->id,
                    'a protected client, '.$kind.' → '.$to.': the deal is its main responsible\'s');
            }
        }
    }

    // The responsible of new companies of the flow (a bot creates them with the campaign).
    $check($campaigns::newCardOwnerId((int)$campaign->id, (int)$manager->id) === null, 'new companies: the pool by default');
    $campaign->new_card_owner = 'sender';
    $campaign->save(false, ['new_card_owner']);
    $check($campaigns::newCardOwnerId((int)$campaign->id, (int)$manager->id) === (int)$manager->id, 'new companies: the sender');
    $campaign->new_card_owner = 'worker';
    $campaign->new_card_worker_id = (int)$admin->id;
    $campaign->save(false, ['new_card_owner', 'new_card_worker_id']);
    $check($campaigns::newCardOwnerId((int)$campaign->id, (int)$manager->id) === (int)$admin->id, 'new companies: the chosen employee');
    // A bot creates a company with the campaign (cms-mcp cms_company_create_full): the responsible of the campaign.
    if (class_exists(\skeeks\cms\mcp\services\CmsCompanyService::class)) {
        Yii::$app->user->setIdentity($manager);
        $made = (new \skeeks\cms\mcp\services\CmsCompanyService())->companyCreateFull(['name' => 'Бот П2д '.$u, 'cms_comm_campaign_id' => (int)$campaign->id]);
        $madeId = (int)($made['company']['id'] ?? 0);
        $check($madeId && CmsCompany2manager::find()->andWhere(['cms_company_id' => $madeId])->select('cms_user_id')->column() == [(int)$admin->id],
            'cms_company_create_full with the campaign: the responsible of new companies of the campaign');
        // A company of the pool: every CRM employee reads it (П2а), so the bot gets its card.
        $pool = (new \skeeks\cms\mcp\services\CmsCompanyService())->companyCreateFull(['name' => 'Бот пул П2д '.$u]);
        $check(!CmsCompany2manager::find()->andWhere(['cms_company_id' => (int)($pool['company']['id'] ?? 0)])->exists(),
            'cms_company_create_full without a campaign: the CRM default — the pool');
        $check($made['created'] && ($made['company']['visible'] ?? true) === false && $pool['created'] && !isset($pool['company']['visible']),
            'a company of another responsible is reported created without access; the pool is readable by every CRM employee');
    }

    // The defaults of a new campaign come from the CRM settings «Исходящие продажи».
    if (Yii::$app->has('crmSettings') && property_exists(Yii::$app->crmSettings, 'outgoingLeadTo')) {
        $settings = Yii::$app->crmSettings;
        $was = [$settings->outgoingLeadOnView, $settings->outgoingTaskOnView, $settings->outgoingLeadTo, $settings->outgoingNewCardOwner];
        $settings->outgoingLeadOnView = 0;
        $settings->outgoingTaskOnView = 0;
        $settings->outgoingLeadTo = 'unsorted';
        $settings->outgoingNewCardOwner = 'sender';
        $fresh = new \skeeks\cms\communication\models\CmsCommCampaign(['name' => 'x']);
        $check((int)$fresh->lead_on_view === 0 && (int)$fresh->task_on_open === 0 && $fresh->lead_to === 'unsorted' && $fresh->new_card_owner === 'sender',
            'a new campaign takes the CRM defaults');
        [$settings->outgoingLeadOnView, $settings->outgoingTaskOnView, $settings->outgoingLeadTo, $settings->outgoingNewCardOwner] = $was;
    }

    // The audience schema of this stand is migrated: imports keep their mode.
    $check(CmsCommImport::audienceReady() && $import->isAudience() && !$theirs->isAudience() && strpos($theirs->asText(), 'Импорт №') === 0,
        'the audience schema is detected; a leads import stays one');

    echo 'OK audience-stand: '.$checks." checks\n";
} finally {
    $transaction->rollBack();
}
