<?php
/**
 * Read tools of mail and mailings for skeeks/cms-mcp (mcp\*), against a local
 * application with cms-mcp installed, in a read-only transaction:
 * COMM_MCP_TEST=local php tests/mcp-read.php. Tools run through
 * McpComponent::executeTool() like REST and MCP. Identities: TEST_ADMIN_ID
 * (1), TEST_WORKER_ID (1663).
 */
if (getenv('COMM_MCP_TEST') !== 'local') {
    throw new RuntimeException('Set COMM_MCP_TEST=local.');
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

use skeeks\cms\communication\mcp\MailReadService;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\communication\services\MailingPolicy;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;

if (!Yii::$app->has('cmsMcp')) {
    echo "SKIP: skeeks/cms-mcp is not installed\n";
    return;
}
$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function rejects(callable $callback, $message) { try { $callback(); } catch (Throwable $e) { ++$GLOBALS['checks']; return; } throw new RuntimeException($message); }
function run(string $tool, array $arguments) {
    static $token;
    $token = $token ?: new CmsOauthAccessToken(['scopes' => json_encode(array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']))]);
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}
function listed(array $arguments): array {
    $ids = [];
    for ($offset = 0; ; $offset += 100) {
        $page = run('cms_comm_message_list', $arguments + ['limit' => 100, 'offset' => $offset]);
        $ids = array_merge($ids, array_column($page['items'], 'id'));
        if ($offset + 100 >= $page['total']) { return $ids; }
    }
}
function visible(CmsUser $user): array {
    $t = CmsCommMessage::tableName();
    $ids = array_map('intval', CmsCommMessage::find()->forManager($user)
        ->andWhere(['or', [$t.'.box_state' => null], ['not', [$t.'.box_state' => ['trash', 'junk']]]])->select($t.'.id')->column());
    sort($ids);
    return $ids;
}

$db = Yii::$app->db;
$db->createCommand('SET TRANSACTION READ ONLY')->execute();
$tx = $db->beginTransaction();
try {
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    $worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
    check($admin && $worker, 'Local test identities exist');
    Yii::$app->user->setIdentity($admin);

    // 1. Registration through params, scope, schemas with examples.
    foreach (['cms_comm_message_list', 'cms_comm_message_get', 'cms_comm_conversation_get', 'cms_comm_account_list', 'cms_comm_topic_list', 'cms_comm_mailing_check', 'cms_comm_contact_state_get'] as $name) {
        $tool = Yii::$app->cmsMcp->getTool($name);
        check($tool->getRequiredScope() === 'cms.communication.read', $name.' reads with cms.communication.read');
        check(mb_strlen($tool->getDescription()) > 80, $name.' is described');
    }

    // 2. The own text: quote, signature and footer removed; contacts masked.
    $m = new CmsCommMessage(['body_text' => "Добрый день!\nПришлите счёт за хостинг.\nСпасибо.\n\nС уважением,\nИван Петров\nтел. +7 999 123-45-67\n\n-----\nОт: Студия <info@example.ru>\n> старое письмо"]);
    [$text, $cut] = (new MailReadService())->ownText($m);
    check($cut && strpos($text, 'Пришлите счёт') !== false, 'Own text kept');
    check(strpos($text, 'С уважением') === false && strpos($text, '123-45') === false && strpos($text, 'старое') === false, 'Signature and quote removed');

    // 3. Visibility equals "Почта": the worker lists exactly the letters forManager() gives; others are unavailable.
    foreach ([$worker, $admin] as $user) {
        Yii::$app->user->setIdentity($user);
        $expected = visible($user);
        if ($user === $worker) {
            $ids = listed([]);
            sort($ids);
            check($ids === $expected, 'Worker letters equal forManager()');
        } else {
            check((int)run('cms_comm_message_list', ['limit' => 1])['total'] === count($expected), 'Administrator letters equal forManager()');
        }
    }
    Yii::$app->user->setIdentity($worker);
    $hidden = CmsCommMessage::find()->andWhere(['not', ['id' => visible($worker) ?: [0]]])->select('id')->orderBy(['id' => SORT_DESC])->scalar();
    if ($hidden) {
        rejects(function () use ($hidden) { run('cms_comm_message_get', ['id' => (int)$hidden]); }, 'A hidden letter is unavailable');
        rejects(function () use ($hidden) { run('cms_comm_conversation_get', ['cms_comm_message_id' => (int)$hidden]); }, 'Its conversation too');
    }
    $foreignLead = CmsLead::find()->andWhere(['not', ['id' => CmsLead::find()->forManager($worker)->select(CmsLead::tableName().'.id')]])->select('id')->scalar();
    if ($foreignLead) {
        rejects(function () use ($foreignLead) { run('cms_comm_message_list', ['record_type' => 'lead', 'record_id' => (int)$foreignLead]); }, 'Letters of another manager\'s lead are unavailable');
    }

    // 4. Letters of a client are the linked ones; texts carry no full address or personal link.
    Yii::$app->user->setIdentity($admin);
    $leadId = (int)CmsCommLink::find()->select('model_id')->andWhere(['model_code' => CmsLead::class])->groupBy('model_id')->orderBy(new yii\db\Expression('COUNT(*) DESC'))->scalar();
    if ($leadId) {
        $page = run('cms_comm_message_list', ['record_type' => 'lead', 'record_id' => $leadId, 'limit' => 100]);
        check($page['total'] > 0, 'The lead has letters');
        foreach ($page['items'] as $item) {
            check(in_array(['type' => 'lead', 'id' => $leadId], array_map(function ($l) { return ['type' => $l['type'], 'id' => $l['id']]; }, $item['links']), true), 'Listed letter is linked to the lead');
        }
        $json = json_encode(array_map(function ($item) { return run('cms_comm_message_get', ['id' => $item['id']]); }, array_slice($page['items'], 0, 20)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        check(!preg_match('/[A-Z0-9._%+\-]{2,}@[A-Z0-9\-]+\.[A-Z]{2,}/i', $json), 'No full email by default');
        check(strpos($json, '/~unsub/') === false && strpos($json, '/~kp/') === false, 'No personal links in letters');
        $first = run('cms_comm_message_get', ['id' => $page['items'][0]['id'], 'include_contacts' => true]);
        check(isset($first['from']['address']) && strpos(json_encode($first['to']).json_encode($first['from']), '***') === false, 'include_contacts gives full addresses');
        $conversation = run('cms_comm_conversation_get', ['cms_comm_message_id' => $page['items'][0]['id']]);
        $dates = array_column($conversation['messages'], 'date');
        $sorted = $dates; sort($sorted);
        check($dates === $sorted, 'A conversation reads in the order of writing');

        // 5. "Можно ли писать" is MailingPolicy::checkMany() for the contacts of the card.
        $topics = run('cms_comm_topic_list', []);
        if ($topics['items']) {
            $topicId = (int)$topics['items'][0]['id'];
            $check = run('cms_comm_mailing_check', ['record_type' => 'lead', 'record_id' => $leadId, 'cms_comm_topic_id' => $topicId, 'channel' => 'email']);
            $emails = array_column(array_filter(MailingContacts::ofRecord(CmsLead::findOne($leadId)), function ($r) { return $r['kind'] === 'email'; }), 'value');
            $expected = MailingPolicy::checkMany((int)Yii::$app->skeeks->site->id, 'email', $emails, $topicId, MailingPolicy::MODE_MANAGER);
            check(array_column($check['items'], 'allowed') === array_values(array_column($expected, 'allowed')), 'Same answers as MailingPolicy');
            check(array_column($check['items'], 'reason') === array_values(array_column($expected, 'reason')), 'Same reasons as MailingPolicy');
            $state = run('cms_comm_contact_state_get', ['record_type' => 'lead', 'record_id' => $leadId]);
            check($state['total'] === count(MailingContacts::ofRecord(CmsLead::findOne($leadId))), 'State of every contact of the card');
        }
    }
    rejects(function () { run('cms_comm_mailing_check', ['cms_comm_topic_id' => 1]); }, 'A check needs contacts or a client');

    echo "OK: {$checks} checks\n";
} finally {
    $tx->rollBack();
}
