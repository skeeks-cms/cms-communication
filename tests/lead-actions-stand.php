<?php
/**
 * New actions from a deal (ARCHITECTURE.md п. 13а, LeadActions) against a
 * local application, in a transaction that is rolled back:
 * LEAD_ACTIONS_STAND_TEST=local php tests/lead-actions-stand.php
 *
 * A company (main responsible — the administrator) and a deal of the
 * employee TEST_MANAGER_ID (1663) are made inside the transaction, names start
 * with «Проверка:». For the levels "deal", "edit" and "full" of the employee
 * and the deal in work, successful, refused and returned to work: the letter
 * form from the deal (ComposeForm::validateContext), "Прикрепить из…" and
 * "Отправить клиенту" of a document of the deal (SendService), a proposal in
 * the deal (CmsProposal) and its sending (CmsProposalSend, ProposalSends) when
 * skeeks/cms-proposal is installed. Nothing is sent: the SMS channel is asked
 * only for the refusal, which comes before the channel.
 */
if (getenv('LEAD_ACTIONS_STAND_TEST') !== 'local') {
    throw new RuntimeException('Set LEAD_ACTIONS_STAND_TEST=local.');
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
require_once __DIR__.'/support/SampleSendable.php';

use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\services\LeadActions;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\communication\services\SendService;
use skeeks\cms\communication\tests\SampleSendable;
use skeeks\cms\communication\tests\SampleSendableSource;
use skeeks\cms\crm\CrmAccess;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;

function leadExpect($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = Yii::$app->db;
$siteId = (int)Yii::$app->skeeks->site->id;
$admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
$manager = CmsUser::findOne((int)(getenv('TEST_MANAGER_ID') ?: 1663));
leadExpect($admin && $manager && $manager->is_worker && !CrmAccess::instance()->isAdmin($manager), 'The test needs an administrator and an ordinary employee.');
$proposals = class_exists(\skeeks\cms\proposal\models\CmsProposal::class);
// The sample's document belongs to the deal: "Прикрепить из…" and "Отправить клиенту" check its deal.
Yii::$app->communication->sendables = ['sample' => ['class' => SampleSendableSource::class,
    'finder' => static function (int $id) {
        $model = CmsLead::find()->andWhere(['id' => $id, 'name' => ['Проверка: своя сделка', 'Проверка: чужая сделка']])->one();
        return $model ? new SampleSendable($id, [$model]) : null;
    }]];

$results = [];
$transaction = $db->beginTransaction();
try {
    $access = CrmAccess::instance();
    Yii::$app->user->setIdentity($admin);
    $company = new CmsCompany(['name' => 'Проверка: действия по сделке']);
    $company->save(false);
    $access->setMain('company', (int)$company->id, (int)$admin->id);
    $access->grantLink('company', (int)$company->id, (int)$manager->id, CrmAccess::LEVEL_DEAL);

    $lead = new CmsLead(['name' => 'Проверка: своя сделка', 'cms_site_id' => $siteId, 'cms_company_id' => (int)$company->id,
        'executor_id' => (int)$manager->id, 'status' => CmsLead::STATUS_IN_WORK]);
    $lead->save(false);
    $foreign = new CmsLead(['name' => 'Проверка: чужая сделка', 'cms_site_id' => $siteId, 'cms_company_id' => (int)$company->id,
        'executor_id' => (int)$admin->id, 'status' => CmsLead::STATUS_IN_WORK]);
    $foreign->save(false);

    $setStatus = static function (CmsLead $model, string $status) {
        $model->refresh();
        $model->status = $status;
        $model->save(false);
        $model->refresh();
    };
    $setLevel = static function (string $level) use ($db, $company, $manager) {
        $db->createCommand()->update('cms_company2manager', ['access_level' => $level],
            ['cms_company_id' => (int)$company->id, 'cms_user_id' => (int)$manager->id])->execute();
    };

    /** Every point of a new action from the deal for the acting employee: null — allowed, else the refusals. */
    $check = static function (CmsUser $user, CmsLead $model) use ($siteId, $proposals) {
        Yii::$app->user->setIdentity($user);
        $model->refresh();
        $refusals = [];
        $rule = LeadActions::error($user, $model);
        if ($rule) {
            $refusals['rule'] = $rule;
        }
        $form = new ComposeForm(['record' => $model]);
        $form->validateContext('context_model_id');
        if ($form->hasErrors()) {
            $refusals['letter'] = $form->getFirstError('context_model_id');
        }
        $sends = new SendService($user, $siteId);
        if ($error = (new MailComposeService($user, $siteId))->sendablesError(new ComposeForm(['sendables' => ['sample:'.$model->id]]))) {
            $refusals['attach'] = $error;
        }
        try {
            $sends->send($sends->request('sample:'.$model->id, ['channel' => 'sms', 'recipient' => '+7 900 000-00-01', 'record' => 'lead:'.$model->id]));
            $refusals['sms'] = 'SENT';
        } catch (SendException $e) {
            // Allowed by the deal rule: the stand refuses the phone itself (allowedPhones), nothing is sent.
            if (!isset($e->errors['recipient'])) {
                $refusals['sms'] = (string)reset($e->errors);
            }
        }
        if ($proposals) {
            $proposal = new \skeeks\cms\proposal\models\CmsProposal(['name' => 'Проверка: КП в сделке', 'cms_lead_id' => (int)$model->id,
                'cms_site_id' => $siteId, 'responsible_id' => (int)$user->id]);
            if (!$proposal->validate()) {
                $refusals['proposal'] = implode(' ', $proposal->getErrorSummary(false));
            }
            $send = new \skeeks\cms\proposal\models\CmsProposalSend(['cms_proposal_id' => 1, 'channel' => 'email', 'cms_lead_id' => (int)$model->id]);
            if (!$send->validate(['cms_lead_id'])) {
                $refusals['proposal_send'] = $send->getFirstError('cms_lead_id');
            }
        }

        return $refusals ?: null;
    };
    $points = $proposals ? ['rule', 'letter', 'attach', 'sms', 'proposal', 'proposal_send'] : ['rule', 'letter', 'attach', 'sms'];
    $allRefused = static fn($refusals, string $text) => is_array($refusals) && count($refusals) === count($points)
        && !array_filter($refusals, static fn($r) => $r !== $text);

    foreach ([CrmAccess::LEVEL_DEAL, CrmAccess::LEVEL_EDIT, CrmAccess::LEVEL_FULL] as $level) {
        $setLevel($level);
        $setStatus($lead, CmsLead::STATUS_IN_WORK);
        $results[$level]['in_work'] = $check($manager, $lead);
        leadExpect($results[$level]['in_work'] === null, "«{$level}»: an open own deal must allow every action: ".json_encode($results[$level]['in_work'], JSON_UNESCAPED_UNICODE));

        foreach ([CmsLead::STATUS_SUCCESS, CmsLead::STATUS_REJECTED] as $status) {
            $setStatus($lead, $status);
            $results[$level][$status] = $check($manager, $lead);
            if ($level === CrmAccess::LEVEL_FULL) {
                leadExpect($results[$level][$status] === null, "«full»: a closed deal of a client seen in full must allow actions ({$status}): "
                    .json_encode($results[$level][$status], JSON_UNESCAPED_UNICODE));
            } else {
                leadExpect($allRefused($results[$level][$status], LeadActions::CLOSED), "«{$level}»: every action from a closed deal ({$status}) must be refused: "
                    .json_encode($results[$level][$status], JSON_UNESCAPED_UNICODE));
            }
        }

        $setStatus($lead, CmsLead::STATUS_IN_WORK);
        $results[$level]['reopened'] = $check($manager, $lead);
        leadExpect($results[$level]['reopened'] === null, "«{$level}»: a deal returned to work must allow actions again: "
            .json_encode($results[$level]['reopened'], JSON_UNESCAPED_UNICODE));
    }

    // A deal of another employee: "deal" does not work on it, open or closed.
    $setLevel(CrmAccess::LEVEL_DEAL);
    $results['foreign_open'] = $check($manager, $foreign);
    leadExpect($allRefused($results['foreign_open'], CrmAccess::LEAD_WORK_DENIED), 'A deal of another employee must be refused for «Свои сделки».');

    // The administrator acts on a closed deal: he acts on the client anyway.
    $setStatus($lead, CmsLead::STATUS_SUCCESS);
    $results['admin_closed'] = $check($admin, $lead);
    leadExpect($results['admin_closed'] === null, 'The administrator must keep actions on a closed deal: '.json_encode($results['admin_closed'], JSON_UNESCAPED_UNICODE));

    // The console and system work (no acting employee) are not limited by the form and the models.
    Yii::$app->user->setIdentity(null);
    $form = new ComposeForm(['record' => $lead]);
    $form->validateContext('context_model_id');
    leadExpect(!$form->hasErrors() && LeadActions::actingError($lead) === null, 'Without an acting employee the deal rule must not refuse.');
    $results['console'] = 'not limited';
} finally {
    $transaction->rollBack();
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
echo "OK: lead actions (".($proposals ? 'with' : 'without')." cms-proposal), rolled back\n";
