<?php
/**
 * Import of recipients into deals (LeadImport) on the stand database, rolled
 * back — П2г: a deal keeps no contacts of its own. A row of a new client
 * makes its company (or person) with the phones and emails, the deal in work
 * is linked to it; a row of a client already in the CRM becomes an unsorted
 * deal whose contacts stay request data.
 *
 * LEAD_IMPORT_STAND_TEST=local php tests/lead-import-stand.php
 */
if (getenv('LEAD_IMPORT_STAND_TEST') !== 'local') {
    throw new RuntimeException('Set LEAD_IMPORT_STAND_TEST=local.');
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

use skeeks\cms\communication\models\CmsCommImportRow;
use skeeks\cms\communication\services\LeadImport;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompanyEmail;
use skeeks\cms\models\CmsCompanyPhone;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsUserEmail;

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
    $worker = CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.is_active' => 1])->orderBy(['id' => SORT_ASC])->one();
    $uniq = (string)random_int(100000, 999999);

    // A client already in the CRM: a company with a phone, no open deal.
    $known = new CmsCompany(['name' => 'Проверка: известная '.$uniq]);
    $check($known->save(), 'a known company is saved');
    $knownPhone = '+7 921 '.substr($uniq, 0, 3).'-'.substr($uniq, 3, 2).'-'.substr($uniq, 5, 1).'1';
    (new CmsCompanyPhone(['cms_company_id' => $known->id, 'value' => $knownPhone]))->save(false);

    $csv = "Компания;Контактное лицо;E-mail;Телефон\n"
        ."Проверка: новая {$uniq};Иван Петров;new-{$uniq}@import.test;+7 922 {$uniq}\n"
        .";Ольга Смирнова;person-{$uniq}@import.test;\n"
        ."Проверка: другое имя {$uniq};;;{$knownPhone}\n";
    $import = LeadImport::create($siteId, $csv, 'Проверка '.$uniq.'.csv', true);
    $import->executor_id = (int)$worker->id;
    $import->created_by = (int)$worker->id;
    $import->save(false);
    LeadImport::run($import);

    $rows = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => $import->id])->orderBy(['row_num' => SORT_ASC])->all();
    $check(count($rows) === 3, 'three rows');

    // 1. A new company with a person: the cards get the contacts, the deal is linked and in work.
    $lead = CmsLead::findOne((int)$rows[0]->cms_lead_id);
    $check($lead && $lead->cms_company_id && $lead->cms_user_id, 'a new client: the deal is linked to the new company and person');
    $check($lead->status === CmsLead::STATUS_IN_WORK && (int)$lead->executor_id === (int)$worker->id, 'the deal of a new client is in work with the executor of the import');
    $check(!$lead->phones && !$lead->emails, 'the deal keeps no phones or emails of its own');
    $check((int)\skeeks\cms\models\CmsCompany2user::find()->andWhere(['cms_company_id' => $lead->cms_company_id, 'cms_user_id' => $lead->cms_user_id])->count() === 1, 'the person is linked to the company');
    $check(CmsUserEmail::find()->andWhere(['cms_user_id' => $lead->cms_user_id, 'value' => 'new-'.$uniq.'@import.test'])->exists()
        || CmsCompanyEmail::find()->andWhere(['cms_company_id' => $lead->cms_company_id, 'value' => 'new-'.$uniq.'@import.test'])->exists(), 'the email of the row is in a card');

    // 2. A person only: a client person with the email, the deal linked to him.
    $lead = CmsLead::findOne((int)$rows[1]->cms_lead_id);
    $check($lead && !$lead->cms_company_id && $lead->cms_user_id && !$lead->emails, 'a person row: the deal is linked to the new person, no email of its own');
    $check(CmsUser::findOne($lead->cms_user_id)->email === 'person-'.$uniq.'@import.test', 'the email is the person\'s');

    // 3. A client already in the CRM: an unsorted deal, its phone is request data, no new card.
    $lead = CmsLead::findOne((int)$rows[2]->cms_lead_id);
    $check($lead && $lead->status === CmsLead::STATUS_NEW && !$lead->cms_company_id && !$lead->cms_user_id, 'a known client: the deal waits in «Неразобранное» without a client');
    $check(count($lead->phones) === 1, 'its phone stays request data');
    $check(!CmsCompany::find()->andWhere(['name' => 'Проверка: другое имя '.$uniq])->exists(), 'no second company for a known client');

    echo 'OK lead-import-stand: '.$checks." checks\n";
} finally {
    if ($transaction->isActive) {
        $transaction->rollBack();
    }
}
