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
    $foreignCheck = run($otherHead, 'cms_comm_draft_check', ['draft_id' => $draftId]);
    check(!$foreignCheck['ok'] && $foreignCheck['problems'][0]['reason'] === AiSend::REASON_NOT_OWN
        && mb_strpos($foreignCheck['problems'][0]['message'], 'руководитель по отделам или администратор') !== false, 'not_own stays for unavailable drafts, with who may');
    // Every draft tool refuses alike: success=false with the stable reason, never an exception text to parse.
    foreach (['cms_comm_draft_get' => [], 'cms_comm_draft_update' => ['subject' => 'Чужая правка', 'confirm' => true],
                 'cms_comm_draft_attachment_add' => ['cms_storage_file_id' => 1], 'cms_comm_draft_attachment_remove' => ['attachment_id' => 1]] as $tool => $more) {
        $refused = run($otherHead, $tool, ['draft_id' => $draftId] + $more);
        check($refused['success'] === false && $refused['reason'] === AiSend::REASON_NOT_OWN, $tool.': not_own for a head of another department');
    }
    $missing = run($leader, 'cms_comm_draft_get', ['draft_id' => 999999999]);
    check($missing['success'] === false && $missing['reason'] === 'not_found', 'An unknown draft is not_found, apart from not_own');
    check(CmsCommDraft::findOne($draftId)->subject === 'Предложение', 'The refused change changed nothing');
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

    // A new client of the employee with an AI draft for him (one draft waits per client).
    $sfx = bin2hex(random_bytes(3));
    $makeDraft = static function (string $subject) use ($employee, $siteId, $topic, $accountId, $mark) {
        static $n = 0;
        $n++;
        $address = 'test-draft-owners-'.$n.'@skeeks.com';
        $client = new CmsLead(['name' => $mark.' клиент '.$n, 'executor_id' => (int)$employee->id, 'cms_site_id' => $siteId]);
        $client->save(false);
        (new CmsLeadEmail(['cms_lead_id' => (int)$client->id, 'value' => $address]))->save(false);
        $made = run($employee, 'cms_comm_draft_create', ['cms_comm_topic_id' => (int)$topic->id, 'cms_comm_account_id' => $accountId, 'confirm' => true,
            'items' => [['record_type' => 'lead', 'record_id' => (int)$client->id, 'address' => $address, 'subject' => $subject, 'body_html' => '<p>Текст</p><p>{Отписаться}</p>']]]);
        $id = (int)($made['items'][0]['draft_id'] ?? 0);
        check($id > 0, 'A draft for "'.$subject.'": '.json_encode($made['items'] ?? $made, JSON_UNESCAPED_UNICODE));

        return [$client, $id, $address];
    };
    $lettersOf = static fn(CmsLead $client) => (int)CmsCommMessage::find()->alias('m')
        ->innerJoin(['l' => \skeeks\cms\communication\models\CmsCommLink::tableName()], 'l.cms_comm_message_id = m.id')
        ->andWhere(['l.model_id' => (int)$client->id, 'm.direction' => CmsCommMessage::DIRECTION_OUT])->count('DISTINCT m.id');

    // 8. Two sends of one draft at once (the owner and his leader, two keys): one letter, the second is refused.
    [$raceClient, $raceDraft] = $makeDraft('Гонка');
    $raceCheck = run($leader, 'cms_comm_draft_check', ['draft_id' => $raceDraft]);
    $ownerToken = (new AiSend($employee, 'x', $siteId))->check($raceDraft)['check_token'] ?? '';
    check($raceCheck['ok'] && $ownerToken === $raceCheck['check_token'], 'Both checks pass with the same token');
    $ownerResult = null;
    $armed = true;
    // The owner's send runs while the leader's send has read the draft and not yet taken it.
    $handler = static function ($event) use (&$armed, &$ownerResult, $raceDraft, $ownerToken, $employee, $siteId, $sfx) {
        if ($armed && (int)$event->sender->id === $raceDraft) {
            $armed = false;
            $ownerResult = (new AiSend($employee, 'x', $siteId))->send($raceDraft, $ownerToken, 'race-owner-'.$sfx, true);
        }
    };
    yii\base\Event::on(CmsCommDraft::class, yii\db\ActiveRecord::EVENT_AFTER_FIND, $handler);
    try {
        $leaderResult = run($leader, 'cms_comm_draft_send', ['draft_id' => $raceDraft, 'check_token' => $raceCheck['check_token'], 'idempotency_key' => 'race-leader-'.$sfx, 'confirm' => true]);
    } finally {
        yii\base\Event::off(CmsCommDraft::class, yii\db\ActiveRecord::EVENT_AFTER_FIND, $handler);
    }
    check(!$armed && ($ownerResult['sent'] ?? false), 'The owner\'s send went first: '.json_encode($ownerResult, JSON_UNESCAPED_UNICODE));
    check($leaderResult['state'] === 'refused' && !CmsCommApiSend::find()->andWhere(['idempotency_key' => 'race-leader-'.$sfx])->exists(),
        'The leader\'s send of the same draft is refused and takes no key: '.json_encode($leaderResult['problems'] ?? $leaderResult, JSON_UNESCAPED_UNICODE));
    check($lettersOf($raceClient) === 1, 'One draft made one letter');
    check(run($employee, 'cms_comm_draft_send', ['draft_id' => $raceDraft, 'check_token' => $ownerToken, 'idempotency_key' => 'race-owner-'.$sfx, 'confirm' => true])['repeated'],
        'The first key still returns the first result');

    // 9. A check token of another owner: after a hand-over the old token does not send.
    [, $handDraft] = $makeDraft('Передача после проверки');
    $handCheck = run($admin, 'cms_comm_draft_check', ['draft_id' => $handDraft]);
    check($handCheck['ok'], 'The administrator\'s check of the employee\'s draft passes');
    // The administrator gets the same signature: the text stays the same, only the owner changes.
    CmsCommSignature::deleteAll(['cms_user_id' => (int)$admin->id, 'cms_comm_account_id' => $accountId]);
    (new CmsCommSignature(['cms_user_id' => (int)$admin->id, 'cms_comm_account_id' => $accountId, 'body_html' => '<p>Менеджер Тест, студия</p>']))->save(false);
    $bodyBefore = (string)CmsCommDraft::findOne($handDraft)->body_html;
    (new DraftOwners($admin, $siteId))->transfer([CmsCommDraft::findOne($handDraft)], (int)$admin->id);
    check((string)CmsCommDraft::findOne($handDraft)->body_html === $bodyBefore, 'The hand-over kept the text (the same signature)');
    $stale = run($admin, 'cms_comm_draft_send', ['draft_id' => $handDraft, 'check_token' => $handCheck['check_token'], 'idempotency_key' => 'hand-'.$sfx]);
    check($stale['state'] === 'refused' && $stale['problems'][0]['reason'] === AiSend::REASON_CHANGED, 'The token of the former owner is refused after a hand-over');

    // 10. One day counter of API sends for letters and chats: a letter for the employee counts against him.
    $chatCount = static function (CmsUser $user) use ($siteId) {
        $chat = new \skeeks\cms\communication\mcp\ChatToolService($user, 'x', $siteId);
        $method = new ReflectionMethod($chat, 'sentToday');
        $method->setAccessible(true);

        return (int)$method->invoke($chat);
    };
    check($chatCount($leader) === AiSend::sentTodayBy($siteId, (int)$leader->id) && AiSend::sentTodayBy($siteId, (int)$leader->id) === 0,
        'The leader\'s chat counter is the letters\' one: nothing of the employee\'s letters');
    check($chatCount($employee) === AiSend::sentTodayBy($siteId, (int)$employee->id) && $chatCount($employee) === 2,
        'The employee\'s counter has his two letters, whoever sent them, in chats too');

    // 11. The rights to act on the client are the owner's, not the caller's.
    $company = new \skeeks\cms\models\CmsCompany(['name' => $mark.' компания']);
    $company->save(false);
    \skeeks\cms\models\CmsCompany2manager::deleteAll(['cms_company_id' => (int)$company->id]);
    $link = new \skeeks\cms\models\CmsCompany2manager(['cms_company_id' => (int)$company->id, 'cms_user_id' => (int)$employee->id]);
    $link->save(false);
    \skeeks\cms\models\CmsCompany2manager::updateAll(['access_level' => \skeeks\cms\crm\CrmAccess::LEVEL_DEAL, 'is_main' => 0],
        ['cms_company_id' => (int)$company->id, 'cms_user_id' => (int)$employee->id]);
    (new \skeeks\cms\models\CmsCompanyEmail(['cms_company_id' => (int)$company->id, 'value' => 'test-draft-owners-company@skeeks.com']))->save(false);
    $companyDraft = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$employee->id, 'cms_comm_account_id' => $accountId,
        'to_list' => 'test-draft-owners-company@skeeks.com', 'subject' => 'Компании', 'body_html' => '<p>Текст</p>', 'purpose' => 'advertising',
        'cms_comm_topic_id' => (int)$topic->id, 'context_model_code' => (new \skeeks\cms\models\CmsCompany())->skeeksModelCode, 'context_model_id' => (int)$company->id]);
    $companyDraft->save(false);
    $ownLead = static fn(array $result) => in_array(\skeeks\cms\crm\CrmAccess::OWN_LEAD_REQUIRED, array_column($result['problems'] ?? [], 'message'), true);
    if (\skeeks\cms\crm\CrmAccess::levelsReady()) {
        check($ownLead(run($admin, 'cms_comm_draft_check', ['draft_id' => (int)$companyDraft->id])),
            'The administrator cannot write to a company for an employee who works with it only through his deals');
        \skeeks\cms\models\CmsCompany2manager::updateAll(['access_level' => \skeeks\cms\crm\CrmAccess::LEVEL_FULL],
            ['cms_company_id' => (int)$company->id, 'cms_user_id' => (int)$employee->id]);
        check(!$ownLead(run($admin, 'cms_comm_draft_check', ['draft_id' => (int)$companyDraft->id])), 'With the full level of the employee it may');
    }

    // 12. Hand-over through the API: a dry run, the change, refusals per draft.
    [, $apiDraft] = $makeDraft('Передача через API');
    $dry = run($leader, 'cms_comm_draft_transfer', ['draft_ids' => [$apiDraft], 'cms_user_id' => (int)$leader->id]);
    check($dry['requires_confirmation'] && $dry['items'][0]['status'] === 'ready' && (int)CmsCommDraft::findOne($apiDraft)->cms_user_id === (int)$employee->id,
        'Without confirm the hand-over is only checked');
    $moved = run($leader, 'cms_comm_draft_transfer', ['draft_ids' => [$apiDraft, 999999999], 'cms_user_id' => (int)$leader->id, 'confirm' => true]);
    check($moved['items'][0]['status'] === 'transferred' && $moved['items'][1]['reason'] === 'not_found'
        && (int)CmsCommDraft::findOne($apiDraft)->cms_user_id === (int)$leader->id, 'The leader takes the draft through the API; an unknown one is refused in its row');
    check(run($leader, 'cms_comm_draft_transfer', ['draft_ids' => [$apiDraft], 'cms_user_id' => (int)$otherEmployee->id, 'confirm' => true])['reason'] === AiSend::REASON_NOT_OWN,
        'Not to an employee of another department');
    check(run($otherHead, 'cms_comm_draft_transfer', ['draft_ids' => [$apiDraft], 'cms_user_id' => (int)$otherHead->id, 'confirm' => true])['items'][0]['reason'] === AiSend::REASON_NOT_OWN,
        'Another head cannot take it');

    // 13. A draft without a mailbox of its owner is not given another's on a change.
    $noBox = run($admin, 'cms_comm_draft_update', ['draft_id' => $apiDraft, 'subject' => 'Новая тема', 'confirm' => true]);
    $apiRow = CmsCommDraft::findOne($apiDraft);
    check($noBox['reason'] === 'no_mailbox' && (int)$apiRow->cms_user_id === (int)$leader->id && $apiRow->cms_comm_account_id === null
        && $apiRow->subject === 'Передача через API', 'The leader\'s draft without a mailbox stays as it is: no_mailbox');

    // 14. Test mode by the caller's role: an administrator changes an employee's draft in test mode.
    [$testClient, $testDraft, $testAddress] = $makeDraft('Тестовый режим');
    Yii::$app->communication->aiTestRecipients = ['lead:'.(int)$testClient->id => [$testAddress]];
    $testUpdate = run($admin, 'cms_comm_draft_update', ['draft_id' => $testDraft, 'subject' => 'Тестовый режим 2', 'test_mode' => true]);
    Yii::$app->communication->aiTestRecipients = [];
    check(($testUpdate['success'] ?? false) && ($testUpdate['test_mode'] ?? false), 'The administrator\'s test mode works on the employee\'s draft: '.json_encode($testUpdate, JSON_UNESCAPED_UNICODE));

    // 15. The day limit of preparing on a change: a draft not yet counted for the caller today takes a place.
    [, $limitDraft] = $makeDraft('Лимит');
    $leaderActions = new \skeeks\cms\communication\services\AiActions($leader, 'x', $siteId);
    Yii::$app->communication->aiLimits = ['perDay' => $leaderActions->usedToday()];
    $overLimit = run($leader, 'cms_comm_draft_update', ['draft_id' => $limitDraft, 'subject' => 'Сверх лимита', 'confirm' => true]);
    check($overLimit['reason'] === 'day_limit' && CmsCommDraft::findOne($limitDraft)->subject === 'Лимит', 'Over the limit the leader cannot take an employee\'s draft');
    Yii::$app->communication->aiLimits = [];
    check(run($leader, 'cms_comm_draft_update', ['draft_id' => $limitDraft, 'subject' => 'В лимите', 'confirm' => true])['saved'], 'Within the limit he can');
    Yii::$app->communication->aiLimits = ['perDay' => $leaderActions->usedToday()];
    check(run($leader, 'cms_comm_draft_update', ['draft_id' => $limitDraft, 'subject' => 'Снова', 'confirm' => true])['saved'], 'A draft counted for him today changes again without a new place');
    Yii::$app->communication->aiLimits = [];

    // 16. Signatures on a hand-over: the block is replaced whatever the former signature is now; added when there was none.
    $sigDraft = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$employee->id, 'cms_comm_account_id' => $accountId, 'subject' => 'Старая подпись',
        'body_html' => '<p>Текст</p><div><br></div>'.MailSignatures::block('<p>Подпись до изменения настроек</p>')]);
    $sigDraft->save(false);
    $adminOwners->transfer([$sigDraft], (int)$otherEmployee->id);
    $sigDraft->refresh();
    check(mb_strpos((string)$sigDraft->body_html, 'Менеджер Б') !== false && mb_strpos((string)$sigDraft->body_html, 'до изменения') === false
        && mb_strpos((string)$sigDraft->body_html, 'Текст') !== false, 'A signature changed in the settings since is replaced too: '.$sigDraft->body_html);
    CmsCommSignature::deleteAll(['cms_user_id' => (int)$employee->id]);
    $plainDraft = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$employee->id, 'cms_comm_account_id' => $accountId, 'subject' => 'Без подписи', 'body_html' => '<p>Текст</p>']);
    $plainDraft->save(false);
    $adminOwners->transfer([$plainDraft], (int)$otherEmployee->id);
    $plainDraft->refresh();
    check(mb_strpos((string)$plainDraft->body_html, 'Менеджер Б') !== false, 'Without a former signature the new owner\'s one is added');

    // 17. An inactive employee: his drafts stay available to the administrator; he receives none.
    $leftDraft = new CmsCommDraft(['cms_site_id' => $siteId, 'cms_user_id' => (int)$otherEmployee->id, 'cms_comm_account_id' => $accountId, 'subject' => 'Уволился', 'body_html' => '<p>Т</p>']);
    $leftDraft->save(false);
    CmsUser::updateAll(['is_active' => 0], ['id' => (int)$otherEmployee->id]);
    $afterLeave = new DraftOwners($admin, $siteId);
    check($afterLeave->find((int)$leftDraft->id) && isset($afterLeave->employees()[(int)$otherEmployee->id]) && !isset($afterLeave->targets()[(int)$otherEmployee->id]),
        'An inactive employee\'s drafts stay in the switch; he is not offered for a hand-over');
    rejects(fn() => $afterLeave->transfer([$plainDraft], (int)$otherEmployee->id), 'работающему', 'A draft is not handed to an inactive employee');
    check($afterLeave->transfer([$leftDraft], (int)$admin->id) === 1, 'The administrator takes the draft of the employee who left');
    CmsUser::updateAll(['is_active' => 1], ['id' => (int)$otherEmployee->id]);

    // 18. The mail screen: the window of an employee's draft acts as the owner and only with the draft's own links.
    Yii::$app->user->setIdentity($leader);
    \skeeks\cms\crm\CrmAccess::instance()->resetMemo();
    $controller = Yii::$app->createController('cmsCommunication/admin-cms-comm-message')[0];
    $request = Yii::$app->request;
    $post = static function (array $body) use ($request) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request->setBodyParams($body);
    };
    [$uiClient, $uiDraft, $uiAddress] = $makeDraft('Окно руководителя');
    Yii::$app->user->setIdentity($leader);
    \skeeks\cms\crm\CrmAccess::instance()->resetMemo();
    // A letter of the employee's mailbox the leader cannot read.
    $hidden = CmsCommMessage::find()->cmsSite($siteId)->forManager($employee)
        ->andWhere(['not in', CmsCommMessage::tableName().'.id', CmsCommMessage::find()->cmsSite($siteId)->forManager($leader)->select(CmsCommMessage::tableName().'.id')])
        ->orderBy([CmsCommMessage::tableName().'.id' => SORT_DESC])->one();
    $form = ['draft_id' => $uiDraft, 'cms_comm_account_id' => $accountId, 'to' => $uiAddress, 'subject' => 'Окно руководителя', 'body_html' => '<p>Текст</p>'];
    if ($hidden) {
        $post(['ComposeForm' => $form + ['reply_to_message_id' => (int)$hidden->id, 'forward_message_id' => (int)$hidden->id], 'draft_owner_id' => (int)$employee->id]);
        $saved = $controller->saveDraft();
        $uiRow = CmsCommDraft::findOne($uiDraft);
        check($saved['success'] && $uiRow->reply_to_message_id === null && $uiRow->forward_message_id === null,
            'The leader cannot link the employee\'s draft to a letter he cannot read: '.json_encode([$saved, $uiRow->reply_to_message_id, $uiRow->forward_message_id]));
    }
    $request->setQueryParams(['q' => 'test-draft-owners', 'draft_id' => $uiDraft]);
    check($controller->contacts()['items'] === (new MailComposeService($employee, $siteId))->suggestRecipients('test-draft-owners'),
        'Recipients are suggested as the owner of the draft');
    // The window opened for the employee, the draft was handed to the leader meanwhile: no save, no send.
    $adminOwners->transfer([CmsCommDraft::findOne($uiDraft)], (int)$leader->id);
    $post(['ComposeForm' => $form, 'draft_owner_id' => (int)$employee->id]);
    $stale = $controller->saveDraft();
    check(!$stale['success'] && !empty($stale['moved']), 'A window of the former owner does not save');
    $letters = (int)CmsCommMessage::find()->count();
    $staleSend = $controller->compose();
    check(!$staleSend['success'] && (int)CmsCommMessage::find()->count() === $letters, 'A window of the former owner does not send');
    // Handed to an employee the leader does not manage: the window sends nothing, not even as the leader.
    $adminOwners->transfer([CmsCommDraft::findOne($uiDraft)], (int)$otherHead->id);
    $post(['ComposeForm' => $form, 'draft_owner_id' => (int)$leader->id]);
    $goneSend = $controller->compose();
    check(!$goneSend['success'] && (int)CmsCommMessage::find()->count() === $letters, 'A draft no longer available sends nothing');
    // The window of an available employee's draft sends his letter "по поручению".
    [$sendClient, $sendDraft, $sendAddress] = $makeDraft('Отправка из окна');
    Yii::$app->user->setIdentity($leader);
    $post(['ComposeForm' => ['draft_id' => $sendDraft, 'cms_comm_account_id' => $accountId, 'to' => $sendAddress, 'subject' => 'Отправка из окна',
        'body_html' => (string)CmsCommDraft::findOne($sendDraft)->body_html], 'draft_owner_id' => (int)$employee->id]);
    $sentUi = $controller->compose();
    $uiMessage = CmsCommMessage::findOne((int)($sentUi['id'] ?? 0));
    check($sentUi['success'] && $uiMessage && (int)$uiMessage->sent_by_user_id === (int)$employee->id && (int)$uiMessage->acted_by_user_id === (int)$leader->id
        && !CmsCommDraft::find()->andWhere(['id' => $sendDraft])->exists(), 'The window sends the employee\'s letter for him: '.json_encode($sentUi, JSON_UNESCAPED_UNICODE));
    // --- Second review: operations interleaved with a hand-over, a change or another send ---
    // $interleave runs $action once, when the draft is read by the operation under test (inTransaction:
    // only on the read inside its own transaction, i.e. the locked one); the returned function removes the hook.
    $interleave = static function (int $id, callable $action, bool $inTransaction = false) {
        $armed = true;
        $handler = static function ($event) use (&$armed, $id, $action, $inTransaction) {
            $transaction = Yii::$app->db->getTransaction();
            if ($armed && (int)$event->sender->id === $id && (!$inTransaction || ($transaction && $transaction->level > 1))) {
                $armed = false;
                $action();
            }
        };
        yii\base\Event::on(CmsCommDraft::class, yii\db\ActiveRecord::EVENT_AFTER_FIND, $handler);

        return static function () use ($handler, &$armed) {
            yii\base\Event::off(CmsCommDraft::class, yii\db\ActiveRecord::EVENT_AFTER_FIND, $handler);

            return !$armed;
        };
    };
    $asLeader = static function () use ($leader) {
        Yii::$app->user->setIdentity($leader);
        \skeeks\cms\crm\CrmAccess::instance()->resetMemo();
    };

    // 19. Handed to another department after the leader's request read the draft: the autosave writes nothing.
    [, $r1Draft, $r1Address] = $makeDraft('Перехват автосохранения');
    $asLeader();
    $done = $interleave($r1Draft, static fn() => $adminOwners->transfer([CmsCommDraft::findOne($r1Draft)], (int)$otherHead->id));
    $post(['ComposeForm' => ['draft_id' => $r1Draft, 'cms_comm_account_id' => $accountId, 'to' => $r1Address, 'subject' => 'Правка руководителя', 'body_html' => '<p>Т</p>'],
        'draft_owner_id' => (int)$employee->id]);
    $r1Save = $controller->saveDraft();
    $r1Row = CmsCommDraft::findOne($r1Draft);
    check($done() && !$r1Save['success'] && (int)$r1Row->cms_user_id === (int)$otherHead->id && $r1Row->subject === 'Перехват автосохранения',
        'An autosave racing a hand-over to another department writes nothing: '.json_encode($r1Save, JSON_UNESCAPED_UNICODE));
    // The same for deleting: the draft of the other department stays.
    [, $r1Delete] = $makeDraft('Перехват удаления');
    $asLeader();
    $done = $interleave($r1Delete, static fn() => $adminOwners->transfer([CmsCommDraft::findOne($r1Delete)], (int)$otherHead->id));
    $post(['ids' => [$r1Delete], 'draft_owner_id' => (int)$employee->id]);
    $r1Deleted = $controller->deleteDraft();
    check($done() && $r1Deleted['removed'] === 0 && CmsCommDraft::find()->andWhere(['id' => $r1Delete])->exists(), 'A delete racing a hand-over leaves the draft');
    // And a change through the API.
    [, $r1Api] = $makeDraft('Перехват правки API');
    $done = $interleave($r1Api, static fn() => $adminOwners->transfer([CmsCommDraft::findOne($r1Api)], (int)$otherHead->id));
    $r1Update = run($leader, 'cms_comm_draft_update', ['draft_id' => $r1Api, 'subject' => 'Правка через API', 'confirm' => true]);
    check($done() && $r1Update['success'] === false && in_array($r1Update['reason'], ['not_own', 'moved'], true)
        && CmsCommDraft::findOne($r1Api)->subject === 'Перехват правки API', 'An API change racing a hand-over writes nothing: '.json_encode($r1Update, JSON_UNESCAPED_UNICODE));
    // A list or window of the former owner hands over nothing.
    [, $r7Draft] = $makeDraft('Старое окно');
    $adminOwners->transfer([CmsCommDraft::findOne($r7Draft)], (int)$leader->id);
    $asLeader();
    $post(['ids' => [$r7Draft], 'user_id' => (int)$leader->id, 'draft_owner_id' => (int)$employee->id]);
    $r7 = $controller->transferDraft();
    check((int)$r7['transferred'] === 0 && (int)CmsCommDraft::findOne($r7Draft)->cms_user_id === (int)$leader->id, 'A stale window hands over nothing');
    check((new DraftOwners($leader, $siteId))->transfer([CmsCommDraft::findOne($r7Draft)], (int)$employee->id, (int)$otherHead->id) === 0,
        'A hand-over expecting another owner moves nothing');

    // 20. A delegated window suggests only who both see: never a correspondent of the employee's other letters.
    $connection = \skeeks\cms\communication\models\CmsCommAccount::findOne($accountId)->cms_comm_connection_id;
    $secret = 'secret-'.$sfx.'@skeeks.com';
    $conversation = new \skeeks\cms\communication\models\CmsCommConversation(['cms_site_id' => $siteId, 'channel' => 'email', 'thread_key' => 'secret-'.$sfx, 'subject' => 'Секрет']);
    $conversation->save(false);
    $letter = new CmsCommMessage(['cms_site_id' => $siteId, 'cms_comm_conversation_id' => (int)$conversation->id, 'cms_comm_account_id' => $accountId, 'channel' => 'email',
        'direction' => CmsCommMessage::DIRECTION_IN, 'status' => CmsCommMessage::STATUS_RECEIVED, 'external_id' => 'secret-'.$sfx, 'from_address' => $secret,
        'from_name' => 'Секретный корреспондент', 'subject' => 'Секрет', 'message_at' => time()]);
    $letter->save(false);
    (new \skeeks\cms\communication\models\CmsCommMessageMailbox(['cms_comm_message_id' => (int)$letter->id, 'cms_comm_account_id' => $accountId,
        'cms_comm_connection_id' => $connection]))->save(false);
    $emails = static fn(array $items) => array_column($items, 'email');
    check(in_array($secret, $emails((new MailComposeService($employee, $siteId))->suggestRecipients('secret-'.$sfx)), true)
        && !in_array($secret, $emails((new MailComposeService($leader, $siteId))->suggestRecipients('secret-'.$sfx)), true), 'The employee sees the correspondent, the leader does not');
    [, $r2Draft] = $makeDraft('Подсказки');
    $asLeader();
    $request->setQueryParams(['q' => 'secret-'.$sfx, 'draft_id' => $r2Draft]);
    check(!in_array($secret, $emails($controller->contacts()['items']), true), 'The employee\'s draft does not open his correspondents to the leader');
    $request->setQueryParams(['q' => 'test-draft-owners', 'draft_id' => $r2Draft]);
    check(count($controller->contacts()['items']) > 0, 'Clients both see are still suggested');

    // 21. The version: a change between the check and the lock (the same updated_at second) refuses the send.
    [, $r3Draft] = $makeDraft('Версия');
    $r3Check = run($leader, 'cms_comm_draft_check', ['draft_id' => $r3Draft]);
    $stamp = (int)CmsCommDraft::findOne($r3Draft)->updated_at;
    // The change lands after the send has read and checked the draft, before it takes the lock.
    $done = $interleave($r3Draft, static fn() => CmsCommDraft::updateAll(['subject' => 'Свежая правка', 'updated_at' => $stamp], ['id' => $r3Draft]));
    $r3Send = run($leader, 'cms_comm_draft_send', ['draft_id' => $r3Draft, 'check_token' => $r3Check['check_token'], 'idempotency_key' => 'r3-'.$sfx, 'confirm' => true]);
    check($done() && $r3Send['state'] === 'refused' && $r3Send['problems'][0]['reason'] === AiSend::REASON_CHANGED
        && CmsCommDraft::findOne($r3Draft)->subject === 'Свежая правка', 'A change within the same second refuses the API send and stays');
    // The window: it holds the version it opened with; a change elsewhere refuses its send and its save.
    [, $r3Window, $r3Address] = $makeDraft('Версия окна');
    $opened = \skeeks\cms\communication\services\MailDrafts::version(CmsCommDraft::findOne($r3Window));
    CmsCommDraft::updateAll(['subject' => 'Правка в другом окне'], ['id' => $r3Window]);
    $asLeader();
    $windowForm = ['ComposeForm' => ['draft_id' => $r3Window, 'cms_comm_account_id' => $accountId, 'to' => $r3Address, 'subject' => 'Старое окно', 'body_html' => '<p>Т</p>'],
        'draft_owner_id' => (int)$employee->id, 'draft_version' => $opened];
    $letters = (int)CmsCommMessage::find()->count();
    $post($windowForm);
    $r3Ui = $controller->compose();
    check(!$r3Ui['success'] && (int)CmsCommMessage::find()->count() === $letters && CmsCommDraft::findOne($r3Window)->subject === 'Правка в другом окне',
        'A window of an older version does not send and keeps the newer change');
    $post($windowForm);
    check(!empty($controller->saveDraft()['moved']) && CmsCommDraft::findOne($r3Window)->subject === 'Правка в другом окне', 'Nor does it save over it');

    // 22. The day limit is taken atomically: two drafts of one owner at once never pass it together.
    [, $r4a] = $makeDraft('Лимит A');
    [, $r4b] = $makeDraft('Лимит Б');
    $r4aCheck = run($leader, 'cms_comm_draft_check', ['draft_id' => $r4a]);
    $r4bToken = (new AiSend($employee, 'x', $siteId))->check($r4b)['check_token'] ?? '';
    Yii::$app->communication->aiLimits = ['sendPerDay' => AiSend::sentTodayBy($siteId, (int)$employee->id) + 1];
    $r4Owner = null;
    $done = $interleave($r4a, static function () use (&$r4Owner, $employee, $siteId, $r4b, $r4bToken, $sfx) {
        $r4Owner = (new AiSend($employee, 'x', $siteId))->send($r4b, $r4bToken, 'r4-owner-'.$sfx, true);
    }, true);
    $r4Leader = run($leader, 'cms_comm_draft_send', ['draft_id' => $r4a, 'check_token' => $r4aCheck['check_token'], 'idempotency_key' => 'r4-leader-'.$sfx, 'confirm' => true]);
    $limit = Yii::$app->communication->aiLimits['sendPerDay'];
    Yii::$app->communication->aiLimits = [];
    // The owner's send ran inside the leader's transaction (one test connection) and went back with its
    // refusal; on two connections it commits. What matters: the leader's send saw it and took no place.
    check($done() && ($r4Owner['sent'] ?? false) && $r4Leader['state'] === 'refused' && $r4Leader['problems'][0]['reason'] === AiSend::REASON_SEND_LIMIT
        && !CmsCommApiSend::find()->andWhere(['idempotency_key' => 'r4-leader-'.$sfx])->exists() && $limit > 0,
        'The last place of the limit goes to one send only: '.json_encode($r4Leader['problems'] ?? $r4Leader, JSON_UNESCAPED_UNICODE));

    // 23. A parallel repeat of the same key and request returns the first result, not a refusal.
    [, $r5Draft] = $makeDraft('Повтор ключа');
    $r5Check = run($leader, 'cms_comm_draft_check', ['draft_id' => $r5Draft]);
    $r5Args = ['draft_id' => $r5Draft, 'check_token' => $r5Check['check_token'], 'idempotency_key' => 'r5-'.$sfx, 'confirm' => true];
    $r5Inner = null;
    $done = $interleave($r5Draft, static function () use (&$r5Inner, $leader, $r5Draft, $r5Args) {
        $r5Inner = (new AiSend($leader, 'x', (int)Yii::$app->skeeks->site->id))->send($r5Draft, $r5Args['check_token'], $r5Args['idempotency_key'], true);
    });
    $r5Outer = run($leader, 'cms_comm_draft_send', $r5Args);
    check($done() && ($r5Inner['sent'] ?? false) && $r5Outer['repeated'] && (int)$r5Outer['message']['id'] === (int)$r5Inner['message']['id'],
        'The repeat that waited for the first send answers with its letter: '.json_encode($r5Outer, JSON_UNESCAPED_UNICODE));

    // 24. Signatures: the author's own "--" line and what follows it stay.
    $authored = '<p>Начало</p><p>--</p><p>Важные условия</p>';
    check(DraftOwners::replaceSignature($authored, null, null) === $authored, 'An author\'s "--" line is not a signature');
    $withNew = DraftOwners::replaceSignature($authored, null, '<p>Новая подпись</p>');
    check(mb_strpos($withNew, 'Важные условия') !== false && mb_strpos($withNew, 'Новая подпись') !== false, 'The new signature is added below the whole text');
    $middle = '<p>Текст</p>'.MailSignatures::block('<p>Подпись</p>').'<p>Постскриптум автора</p>';
    check(mb_strpos(DraftOwners::replaceSignature($middle, null, null), 'Постскриптум автора') !== false, 'A block in the middle of the text is the author\'s');
    check(mb_strpos(DraftOwners::replaceSignature('<p>Текст</p><div><br></div>'.MailSignatures::block('<p>Старая</p>'), null, '<p>Новая</p>'), 'Старая') === false,
        'The block of the form at the end is replaced');
} finally {
    $tx->rollBack();
    \skeeks\cms\communication\components\AiApiSettings::reset();
    CmsDepartment::resetTree();
}
echo "OK: {$checks} checks\n";
