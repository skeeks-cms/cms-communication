<?php
/**
 * Automatic linking of letters to companies and clients, on the stand
 * database, rolled back (regression of letter #7093 on skeeks.com,
 * 30.10.2026: an invoice sent in an old thread to a new client was linked
 * to 4 companies and 5 clients of earlier letters of the thread):
 * - an address written at several companies (or several people) links none
 *   of them — the letter stays for sorting;
 * - links found by address pass in a conversation only between letters with
 *   a common outside participant; a letter to another client in the same
 *   thread does not get the previous clients;
 * - an incoming reply from an unknown address follows the letter it answers;
 * - links chosen by an employee (manual) follow the whole conversation.
 *
 * AUTOLINK_TEST=local php tests/autolink-stand.php
 */
if (getenv('AUTOLINK_TEST') !== 'local') {
    throw new RuntimeException('Set AUTOLINK_TEST=local.');
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
$c['components']['notifications']['channels'] = ['push' => null, 'messengers' => null, 'email' => null];
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\AddressBook;
use skeeks\cms\communication\services\MessageLinker;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompanyEmail;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException('FAIL: '.$message); } ++$checks; }

$site = \skeeks\cms\models\CmsSite::find()->default()->one();
Yii::$app->skeeks->site = $site;
$siteId = (int)$site->id;
$ours = (string)\skeeks\cms\communication\models\CmsCommAccount::findMailboxes()->select('address')->andWhere(['cms_site_id' => $siteId])->scalar();
check($ours !== '', 'The stand needs a mailbox of the site.');

$db = Yii::$app->db;
$transaction = $db->beginTransaction();
try {
    $tag = 'autolink-'.bin2hex(random_bytes(3));
    $company = static function (string $name, array $emails) use ($tag) {
        $model = new CmsCompany(['name' => $tag.' '.$name]);
        if (!$model->save(false)) {
            throw new RuntimeException('Company not saved.');
        }
        foreach ($emails as $email) {
            $row = new CmsCompanyEmail(['cms_company_id' => (int)$model->id, 'value' => $email]);
            if (!$row->save(false)) {
                throw new RuntimeException('Company email not saved.');
            }
        }
        return $model;
    };
    $a = $company('A', [$tag.'-first@example.test', $tag.'-shared@example.test']);
    $b = $company('B', [$tag.'-second@example.test', $tag.'-shared@example.test']);
    $cCompany = $company('C', [$tag.'-third@example.test']);
    $book = new AddressBook();
    $ids = static fn(array $records) => array_map(static fn($r) => get_class($r).':'.$r->primaryKey, $records);

    // 1. An address of two companies links neither of them.
    check($ids($book->findRecords([$tag.'-shared@example.test'], $siteId)) === [], 'An address of two companies must link none of them.');
    check($ids($book->findRecords([$tag.'-first@example.test'], $siteId)) === [CmsCompany::class.':'.$a->id], 'An address of one company must link it.');
    check(count($book->findRecords([$tag.'-first@example.test', $tag.'-second@example.test'], $siteId)) === 2,
        'A letter to two clients with their own addresses must link both.');

    // 2. One thread, letters to different clients (a reused invoice thread).
    $conversation = new CmsCommConversation(['cms_site_id' => $siteId, 'channel' => 'email', 'thread_key' => '<'.$tag.'@example.test>', 'subject' => 'Счет']);
    $conversation->save(false);
    $n = 0;
    $letter = static function (string $direction, string $from, array $to, ?string $inReplyTo = null) use ($conversation, $siteId, $tag, &$n) {
        $n++;
        $message = new CmsCommMessage([
            'cms_site_id' => $siteId, 'cms_comm_conversation_id' => (int)$conversation->id, 'channel' => 'email',
            'direction' => $direction, 'status' => $direction === 'in' ? 'received' : 'sent',
            'external_id' => '<'.$tag.'-'.$n.'@example.test>', 'in_reply_to' => $inReplyTo,
            'from_address' => $from, 'to_list' => json_encode(array_fill_keys($to, '')), 'subject' => 'Счет на оплату',
            'message_at' => time() + $n,
        ]);
        $message->save(false);
        return $message;
    };
    $linker = new MessageLinker();
    $receive = static function (CmsCommMessage $message, array $external) use ($linker, $book, $siteId) {
        $linker->link($message, $book->findRecords($external, $siteId), CmsCommLink::TYPE_AUTO);
        $linker->inheritFromConversation($message);
    };
    $links = static fn(CmsCommMessage $m) => array_map(static fn($l) => $l->model_code.':'.$l->model_id.':'.$l->link_type,
        CmsCommLink::find()->andWhere(['cms_comm_message_id' => (int)$m->id])->orderBy(['model_id' => SORT_ASC])->all());

    $first = $letter('out', $ours, [$tag.'-first@example.test']);
    $receive($first, [$tag.'-first@example.test']);
    check($links($first) === [CmsCompany::class.':'.$a->id.':auto'], 'The first letter links its client.');

    $second = $letter('out', $ours, [$tag.'-second@example.test'], $first->external_id);
    $receive($second, [$tag.'-second@example.test']);
    check($links($second) === [CmsCompany::class.':'.$b->id.':auto'],
        'A letter to another client in the same thread must not get the previous client: '.implode(', ', $links($second)));

    $reply = $letter('in', $tag.'-second@example.test', [$ours], $second->external_id);
    $receive($reply, [$tag.'-second@example.test']);
    check($links($reply) === [CmsCompany::class.':'.$b->id.':auto'], 'A reply of the client keeps only that client.');

    $otherMailbox = $letter('in', $tag.'-unknown@example.test', [$ours], $second->external_id);
    $receive($otherMailbox, [$tag.'-unknown@example.test']);
    check($links($otherMailbox) === [CmsCompany::class.':'.$b->id.':thread'],
        'A reply from an unknown address must follow the letter it answers only: '.implode(', ', $links($otherMailbox)));

    $outToUnknown = $letter('out', $ours, [$tag.'-another@example.test'], $reply->external_id);
    $receive($outToUnknown, [$tag.'-another@example.test']);
    check($links($outToUnknown) === [], 'Our letter to a new address in an old thread must stay for sorting.');

    // 3. A link chosen by an employee follows the conversation.
    $linker->link($first, [$cCompany], CmsCommLink::TYPE_MANUAL, null);
    $later = $letter('out', $ours, [$tag.'-another@example.test'], $outToUnknown->external_id);
    $receive($later, [$tag.'-another@example.test']);
    check($links($later) === [CmsCompany::class.':'.$cCompany->id.':thread'], 'A manual link must follow the whole conversation.');
} finally {
    $transaction->rollBack();
}

echo "autolink-stand: OK ({$checks} checks, rolled back)\n";
