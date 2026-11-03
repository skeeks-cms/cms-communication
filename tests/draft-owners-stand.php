<?php
/**
 * Drafts of available employees (owner's decision 03.11.2026): a leader by
 * departments or an administrator reads, changes, checks, sends and hands
 * over drafts of the employees he manages; anyone else is refused. A draft
 * sent for its owner leaves from the owner's mailbox, with his signature and
 * against his day limit, and records who actually sent it ("по поручению").
 *
 * Against a local application with cms-mcp, in a transaction that is rolled
 * back (letters are only queued inside it: nothing leaves):
 * DRAFT_OWNERS_TEST=local php tests/draft-owners-stand.php.
 * Identities: TEST_ADMIN_ID (1), TEST_OAUTH_CLIENT_ID (an OAuth client); the
 * leader, his employee and the employees of another department are created.
 */
if (getenv('DRAFT_OWNERS_TEST') !== 'local') {
    throw new RuntimeException('Set DRAFT_OWNERS_TEST=local.');
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

use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommApiSend;
use skeeks\cms\communication\models\CmsCommDraft;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommSignature;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\AiSend;
use skeeks\cms\communication\services\DraftOwners;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\communication\services\MailSignatures;
use skeeks\cms\models\CmsDepartment;
use skeeks\cms\models\CmsDepartment2worker;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;
use skeeks\cms\oauth2\models\CmsOauthClient;

if (!Yii::$app->has('cmsMcp')) {
    echo "SKIP: skeeks/cms-mcp is not installed\n";
    return;
}
$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; echo 'ok    '.$message."\n"; }
function rejects(callable $callback, string $needle, $message) {
    try { $callback(); } catch (Throwable $e) {
        if ($needle !== '' && mb_strpos($e->getMessage(), $needle) === false) { throw new RuntimeException($message.': '.$e->getMessage()); }
        check(true, $message); return;
    }
    throw new RuntimeException($message);
}
/** A tool as the given user through his OAuth token (all scopes). */
function run(CmsUser $user, string $tool, array $arguments) {
    global $client;
    Yii::$app->user->setIdentity($user);
    \skeeks\cms\crm\CrmAccess::instance()->resetMemo();
    $scopes = array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']);
    $token = new CmsOauthAccessToken(['client_id' => (int)$client->id, 'scopes' => json_encode($scopes)]);
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}

$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    $siteId = (int)Yii::$app->skeeks->site->id;
    \skeeks\cms\communication\components\AiApiSettings::reset($siteId, ['sendEnabled' => true, 'limits' => []]);
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    $client = CmsOauthClient::findOne((int)(getenv('TEST_OAUTH_CLIENT_ID') ?: 0)) ?: CmsOauthClient::find()->orderBy(['id' => SORT_DESC])->one();
    check($admin && $client, 'The administrator and an OAuth client exist');
    $accountId = (int)(new MailComposeService($admin, $siteId))->defaultAccountId();
    check($accountId > 0, 'A mailbox to send from exists');
    $mark = 'Проверка черновиков '.bin2hex(random_bytes(3));

    // Two departments: the leader with his employee; another head with his employee.
    $worker = static function (string $name) use ($siteId) {
        $user = new CmsUser(['first_name' => $name, 'last_name' => 'Тест', 'cms_site_id' => $siteId, 'is_worker' => 1, 'is_active' => 1,
            'auth_key' => Yii::$app->security->generateRandomString()]);
        $user->save(false);
        // The roles of an ordinary employee of the site (TEST_ROLES, "user,worker"): no administrator rights.
        foreach (array_filter(explode(',', getenv('TEST_ROLES') ?: 'user,worker')) as $name) {
            if ($role = Yii::$app->authManager->getRole(trim($name))) {
                Yii::$app->authManager->assign($role, (int)$user->id);
            }
        }

        return CmsUser::findOne((int)$user->id);
    };
    $leader = $worker($mark.' руководитель');
    $employee = $worker($mark.' менеджер');
    $otherHead = $worker($mark.' руководитель Б');
    $otherEmployee = $worker($mark.' менеджер Б');
    $department = static function (string $name, CmsUser $head, CmsUser $member) use ($db) {
        $db->createCommand()->insert('{{%cms_department}}', ['name' => $name, 'worker_id' => (int)$head->id, 'sort' => 100])->execute();
        (new CmsDepartment2worker(['cms_department_id' => (int)$db->getLastInsertID(), 'worker_id' => (int)$member->id]))->save(false);
    };
    $department($mark.' отдел А', $leader, $employee);
    $department($mark.' отдел Б', $otherHead, $otherEmployee);
    CmsDepartment::resetTree();
    // The employees send from the same mailbox (their own access rows); the leader has none.
    foreach ([$employee, $otherEmployee, $otherHead] as $user) {
        (new CmsCommAccountAccess(['cms_comm_account_id' => $accountId, 'cms_user_id' => (int)$user->id, 'read_level' => 'all', 'send_level' => 'any', 'is_default' => 1]))->save(false);
    }

    // 1. Who is available: the rule of the core (subordinates; every employee for an administrator).
    $ids = static fn(CmsUser $u) => (new DraftOwners($u, $siteId))->ids();
    check(in_array((int)$employee->id, $ids($leader), true) && !in_array((int)$otherEmployee->id, $ids($leader), true), 'The leader manages his employee, not one of another department');
    check($ids($employee) === [(int)$employee->id] && !(new DraftOwners($employee, $siteId))->hasOthers(), 'An ordinary manager has only his own drafts');
    check(!in_array((int)$employee->id, $ids($otherHead), true), 'A head of another department does not manage the employee');
    check(count(array_intersect([(int)$leader->id, (int)$employee->id, (int)$otherHead->id, (int)$otherEmployee->id], $ids($admin))) === 4, 'An administrator manages every employee');

    // 2. The employee's draft prepared by AI for him (his mailbox, his client).
    $lead = new CmsLead(['name' => $mark.' клиент', 'executor_id' => (int)$employee->id, 'cms_site_id' => $siteId]);
    $lead->save(false);
    (new CmsLeadEmail(['cms_lead_id' => (int)$lead->id, 'value' => 'test-draft-owners@skeeks.com']))->save(false);
    $topic = new CmsCommTopic(['cms_site_id' => $siteId, 'name' => $mark.' тема', 'is_active' => 1]);
    $topic->save(false);
    (new CmsCommSignature(['cms_user_id' => (int)$employee->id, 'cms_comm_account_id' => $accountId, 'body_html' => '<p>Менеджер Тест, студия</p>']))->save(false);
    $created = run($employee, 'cms_comm_draft_create', ['cms_comm_topic_id' => (int)$topic->id, 'cms_comm_account_id' => $accountId, 'confirm' => true,
        'items' => [['record_type' => 'lead', 'record_id' => (int)$lead->id, 'address' => 'test-draft-owners@skeeks.com', 'subject' => 'Предложение', 'body_html' => '<p>Здравствуйте!</p><p>{Отписаться}</p>']]]);
    $draftId = (int)($created['items'][0]['draft_id'] ?? 0);
    check($draftId && (int)CmsCommDraft::findOne($draftId)->cms_user_id === (int)$employee->id, 'The draft is in the employee\'s drafts: '.json_encode($created['items'] ?? $created, JSON_UNESCAPED_UNICODE));

    // 3. Others: the employee of another department and its head do not see it; a manager does not see the leader's drafts.
    $foreignList = run($otherHead, 'cms_comm_draft_list', ['cms_user_id' => (int)$employee->id]);
    check(!$foreignList['success'] && $foreignList['reason'] === AiSend::REASON_NOT_OWN, 'A head of another department cannot list the employee\'s drafts');
    rejects(fn() => run($otherHead, 'cms_comm_draft_get', ['draft_id' => $draftId]), 'руководитель', 'A head of another department cannot read the draft');
    $foreignCheck = run($otherHead, 'cms_comm_draft_check', ['draft_id' => $draftId]);
    check(!$foreignCheck['ok'] && $foreignCheck['problems'][0]['reason'] === AiSend::REASON_NOT_OWN
        && mb_strpos($foreignCheck['problems'][0]['message'], 'руководитель по отделам или администратор') !== false, 'not_own stays for unavailable drafts, with who may');
    rejects(fn() => run($otherHead, 'cms_comm_draft_update', ['draft_id' => $draftId, 'subject' => 'Чужая правка', 'confirm' => true]), 'руководитель', 'A head of another department cannot change the draft');
    check(!run($employee, 'cms_comm_draft_list', ['cms_user_id' => (int)$leader->id])['success'], 'An ordinary manager does not see drafts of his leader');

    // 4. The leader: the list with the switch, the draft as the owner's.
    $list = run($leader, 'cms_comm_draft_list', ['cms_user_id' => (int)$employee->id]);
    check($list['success'] && in_array($draftId, array_column($list['drafts'], 'id'), true) && in_array((int)$employee->id, array_column($list['employees'], 'id'), true)
        && (int)$list['employees'][0]['id'] === (int)$leader->id, 'The leader lists the employee\'s drafts; he is first among available employees');
    $get = run($leader, 'cms_comm_draft_get', ['draft_id' => $draftId]);
    check((int)$get['owner']['id'] === (int)$employee->id && $get['acting_for'] && (int)$get['client']['id'] === (int)$lead->id
        && $get['signature'] && $get['signature']['in_letter'], 'The draft shows its owner, client and the owner\'s signature');
    $updated = run($leader, 'cms_comm_draft_update', ['draft_id' => $draftId, 'subject' => 'Предложение от руководителя', 'confirm' => true]);
    $draft = CmsCommDraft::findOne($draftId);
    check($updated['saved'] && $draft->subject === 'Предложение от руководителя' && (int)$draft->cms_user_id === (int)$employee->id
        && (int)$draft->prepared_user_id === (int)$leader->id, 'The leader changes it: the owner stays, "по запросу" is the leader');

    // 5. The check and the send for the employee: his mailbox, his limit, "по поручению".
    $check = run($leader, 'cms_comm_draft_check', ['draft_id' => $draftId]);
    check($check['ok'] && (int)$check['draft']['owner']['id'] === (int)$employee->id && $check['draft']['acting_for']
        && (int)$check['sender']['cms_comm_account_id'] === $accountId, 'The leader\'s check passes as the employee\'s letter: '.json_encode($check['problems'], JSON_UNESCAPED_UNICODE));
    $key = 'draft-owners-'.bin2hex(random_bytes(4));
    $sent = run($leader, 'cms_comm_draft_send', ['draft_id' => $draftId, 'check_token' => $check['check_token'], 'idempotency_key' => $key, 'confirm' => true]);
    $message = CmsCommMessage::findOne((int)($sent['message']['id'] ?? 0));
    check($sent['sent'] && $message && (int)$message->sent_by_user_id === (int)$employee->id && (int)$message->acted_by_user_id === (int)$leader->id
        && (int)$message->cms_comm_account_id === $accountId, 'The letter is the employee\'s, from his mailbox, sent by the leader: '.json_encode($sent, JSON_UNESCAPED_UNICODE));
    check(mb_strpos((string)$message->body_html, 'Менеджер Тест, студия') !== false, 'The letter carries the employee\'s signature');
    $row = CmsCommApiSend::find()->andWhere(['idempotency_key' => $key])->one();
    check($row && (int)$row->cms_user_id === (int)$leader->id && (int)$row->sender_user_id === (int)$employee->id, 'The API send keeps who sent and whose draft');
    check((new AiSend($employee, 'x', $siteId))->sentToday() === 1 && (new AiSend($leader, 'x', $siteId))->sentToday() === 0, 'The send counts against the employee\'s day limit');
    check(run($leader, 'cms_comm_send_status', ['idempotency_key' => $key])['found'], 'The leader reads the status by his key');
    check(!CmsCommDraft::find()->andWhere(['id' => $draftId])->exists(), 'The sent draft is gone');
    $acted = (string)$message->getActedByText();
    check(mb_strpos($acted, 'по поручению') !== false && mb_strpos($acted, (string)$leader->shortDisplayName) !== false
        && mb_strpos($acted, (string)$employee->shortDisplayName) !== false, 'The letter says who sent it for whom: '.$acted);
    $log = CmsLog::find()->andWhere(['log_type' => CmsCommMessage::LOG_TYPE])->andWhere(['like', 'data', '"id":'.(int)$message->id])->one();
    $feed = (new \skeeks\cms\communication\log\MessageLogRenderer())->render($log ?: new CmsLog(['data' => ['id' => (int)$message->id]]));
    check(mb_strpos($feed, 'по поручению') !== false, 'The client feed says "по поручению"');

    // 6. An administrator works with drafts of anyone.
    $otherDraft = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$otherEmployee->id, 'cms_comm_account_id' => $accountId, 'subject' => 'Б', 'body_html' => '<p>Б</p>']);
    $otherDraft->save(false);
    $adminList = run($admin, 'cms_comm_draft_list', ['cms_user_id' => (int)$otherEmployee->id]);
    check($adminList['success'] && in_array((int)$otherDraft->id, array_column($adminList['drafts'], 'id'), true), 'An administrator lists drafts of any employee');
    check((int)run($admin, 'cms_comm_draft_get', ['draft_id' => (int)$otherDraft->id])['owner']['id'] === (int)$otherEmployee->id, 'An administrator reads a draft of any employee');

    // 7. Handing over: only between available employees; the signature follows the new owner.
    (new CmsCommSignature(['cms_user_id' => (int)$otherHead->id, 'cms_comm_account_id' => $accountId, 'body_html' => '<p>Руководитель Б</p>']))->save(false);
    $own = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$employee->id, 'cms_comm_account_id' => $accountId, 'subject' => 'Передача',
        'body_html' => '<p>Текст</p>'.MailSignatures::block('<p>Менеджер Тест, студия</p>')]);
    $own->save(false);
    $leaderOwners = new DraftOwners($leader, $siteId);
    rejects(fn() => $leaderOwners->transfer([$own], (int)$otherEmployee->id), 'руководите', 'The leader cannot hand a draft to an employee of another department');
    rejects(fn() => (new DraftOwners($otherHead, $siteId))->transfer([$own], (int)$otherHead->id), 'не ваш', 'A head of another department cannot take the draft');
    check($leaderOwners->transfer($leaderOwners->findAll([(int)$own->id]), (int)$leader->id) === 1 && (int)$own->refresh() >= 0
        && (int)$own->cms_user_id === (int)$leader->id, 'The leader takes the draft: the owner changes');
    check(!(new DraftOwners($employee, $siteId))->find((int)$own->id), 'The former owner no longer sees it');
    check($own->cms_comm_account_id === null && mb_strpos((string)$own->body_html, 'Менеджер Тест, студия') === false,
        'A new owner without the mailbox gets none, the former signature goes: '.$own->body_html);
    $adminOwners = new DraftOwners($admin, $siteId);
    check($adminOwners->transfer([$own], (int)$otherHead->id) === 1 && (int)$own->refresh() >= 0 && (int)$own->cms_user_id === (int)$otherHead->id
        && (int)$own->cms_comm_account_id === $accountId, 'An administrator hands it to an employee of another department: his default mailbox');
    $back = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$employee->id, 'cms_comm_account_id' => $accountId, 'subject' => 'Подпись',
        'body_html' => '<p>Текст</p>'.MailSignatures::block('<p>Менеджер Тест, студия</p>')]);
    $back->save(false);
    $adminOwners->transfer([$back], (int)$otherEmployee->id);
    (new CmsCommSignature(['cms_user_id' => (int)$otherEmployee->id, 'cms_comm_account_id' => $accountId, 'body_html' => '<p>Менеджер Б</p>']))->save(false);
    $back2 = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$employee->id, 'cms_comm_account_id' => $accountId, 'subject' => 'Подпись 2',
        'body_html' => '<p>Текст</p>'.MailSignatures::block('<p>Менеджер Тест, студия</p>')]);
    $back2->save(false);
    $adminOwners->transfer([$back2], (int)$otherEmployee->id);
    $back2->refresh();
    check(mb_strpos((string)$back2->body_html, 'Менеджер Б') !== false && mb_strpos((string)$back2->body_html, 'Менеджер Тест, студия') === false,
        'The new owner\'s signature replaces the former one');
} finally {
    $tx->rollBack();
    \skeeks\cms\communication\components\AiApiSettings::reset();
    CmsDepartment::resetTree();
}
echo "OK: {$checks} checks\n";
