<?php
/**
 * The day limit of API sends (AiSend::withDayLock(), reserveDay()) on TWO real connections of MariaDB,
 * in a SEPARATE database (TEST_DB_NAME, skeeks_comm_limit by default) with empty copies of
 * cms_user and cms_comm_api_send from the stand database (TEST_DB_SOURCE, skeeks_local), dropped
 * at the end. The stand database is never changed; no letter is queued.
 *
 * - the second send of an employee waits for the first one's day lock and fails in time, never passes;
 * - a transaction that read before taking the lock (REPEATABLE READ snapshot) still counts a send
 *   another connection committed meanwhile (the locking read of reserveDay());
 * - sends of another employee never wait, and nothing locks the cms_user row: an insert referring to
 *   the employee from another connection goes on at once.
 *
 * COMM_DAY_LIMIT_TEST=local php tests/day-limit-db.php
 *   TEST_DB_HOST (db), TEST_DB_USER (root), TEST_DB_PASSWORD (root), TEST_ADMIN_ID (1)
 */
if (getenv('COMM_DAY_LIMIT_TEST') !== 'local') {
    throw new RuntimeException('Set COMM_DAY_LIMIT_TEST=local.');
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

use skeeks\cms\communication\components\AiApiSettings;
use skeeks\cms\communication\services\AiRefusal;
use skeeks\cms\communication\services\AiSend;

$host = getenv('TEST_DB_HOST') ?: 'db';
$user = getenv('TEST_DB_USER') ?: 'root';
$password = getenv('TEST_DB_PASSWORD') ?: 'root';
$name = getenv('TEST_DB_NAME') ?: 'skeeks_comm_limit';
$source = getenv('TEST_DB_SOURCE') ?: 'skeeks_local';
if ($name === $source) {
    throw new RuntimeException('The test database must not be the stand database.');
}
$fail = 0;
$expect = static function (bool $ok, string $what) use (&$fail) { $fail += $ok ? 0 : 1; echo ($ok ? 'ok    ' : 'FAIL  ').$what."\n"; };

// Resolved on the stand database before the connection is switched: the site and the limits (in memory).
$siteId = (int)Yii::$app->skeeks->site->id;
$employeeId = (int)(getenv('TEST_ADMIN_ID') ?: 1);
$otherId = (int)Yii::$app->db->createCommand('SELECT id FROM cms_user WHERE id <> :id ORDER BY id LIMIT 1', [':id' => $employeeId])->queryScalar();
AiApiSettings::reset($siteId, ['sendEnabled' => true, 'limits' => ['sendPerDay' => 1]]);

$server = new PDO('mysql:host='.$host, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`; CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$dsn = 'mysql:host='.$host.';dbname='.$name;
try {
    foreach (['cms_user', 'cms_comm_api_send'] as $table) {
        $server->exec("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
    }
    $server->exec("INSERT INTO `$name`.`cms_user` SELECT * FROM `$source`.`cms_user` WHERE id IN ($employeeId, $otherId)");
    // The application's connection (A) and a second one (B), both on the test database.
    $a = new yii\db\Connection(['dsn' => $dsn, 'username' => $user, 'password' => $password, 'charset' => 'utf8mb4']);
    Yii::$app->set('db', $a);
    $b = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $b->exec('SET SESSION innodb_lock_wait_timeout = 2');
    // Both connections are closed by their ids at the end, whatever happens (an open transaction would hold the drop).
    $ids = [(int)$a->createCommand('SELECT CONNECTION_ID()')->queryScalar(), (int)$b->query('SELECT CONNECTION_ID()')->fetchColumn()];
    $expect(strtoupper((string)$a->createCommand('SELECT @@tx_isolation')->queryScalar()) === 'REPEATABLE-READ', 'The isolation of the stand: REPEATABLE READ');
    $insert = static function (PDO $pdo, int $sender, string $key) use ($siteId) {
        $pdo->prepare('INSERT INTO cms_comm_api_send (created_at, updated_at, cms_site_id, cms_user_id, sender_user_id, idempotency_key, request_hash, cms_comm_message_id, test_mode)
            VALUES (UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), ?, ?, ?, ?, ?, 1, 0)')->execute([$siteId, $sender, $sender, $key, str_repeat('a', 64)]);
    };
    $send = static function (int $sender) use ($a, $siteId) {
        try {
            AiSend::withDayLock($siteId, $sender, static fn() => $a->transaction(static fn() => AiSend::reserveDay($siteId, $sender)));

            return 'passed';
        } catch (AiRefusal $e) {
            return $e->reason;
        }
    };
    $lockName = 'sx_comm_api_send:'.$siteId.':'.$employeeId;

    // 1. Another send of the employee holds his day lock (B): A waits and fails in time, never passes.
    $b->query("SELECT GET_LOCK('$lockName', 0)")->fetchAll();
    AiSend::$dayLockTimeout = 1;
    $expect($send($employeeId) === 'send_busy', 'A send waits for the day lock of the employee and gives up in time');
    // Another employee is not held by it.
    $expect($send($otherId) === 'passed', 'Another employee\'s send does not wait');
    // B commits its send and releases the lock: A now counts it and refuses the last place.
    $b->beginTransaction();
    $insert($b, $employeeId, 'b-1');
    $b->commit();
    $b->query("SELECT RELEASE_LOCK('$lockName')")->fetchAll();
    AiSend::$dayLockTimeout = 10;
    $expect($send($employeeId) === 'send_day_limit', 'After the first send committed, the second one is refused: one place, one send');

    // 2. A transaction that took its snapshot before the lock still sees a send committed meanwhile.
    $a->createCommand()->delete('cms_comm_api_send')->execute();
    $outer = $a->beginTransaction();
    $before = (int)$a->createCommand('SELECT COUNT(*) FROM cms_comm_api_send')->queryScalar();
    $insert($b, $employeeId, 'b-2');
    $plain = (int)$a->createCommand('SELECT COUNT(*) FROM cms_comm_api_send')->queryScalar();
    $result = $send($employeeId);
    $outer->rollBack();
    $expect($before === 0 && $plain === 0 && $result === 'send_day_limit',
        'An old snapshot (plain count '.$plain.') does not let the send pass: the locking read sees the committed send');

    // 3. No row of cms_user is locked by a send: an insert referring to the employee goes on at once.
    $a->createCommand()->delete('cms_comm_api_send')->execute();
    $blocked = null;
    AiSend::withDayLock($siteId, $employeeId, static function () use ($a, $b, $siteId, $employeeId, $insert, &$blocked) {
        return $a->transaction(static function () use ($b, $siteId, $employeeId, $insert, &$blocked) {
            AiSend::reserveDay($siteId, $employeeId);
            try {
                $b->beginTransaction();
                $b->query('SELECT id FROM cms_user WHERE id = '.$employeeId.' LOCK IN SHARE MODE')->fetchAll();
                $insert($b, $employeeId, 'b-3');
                $b->rollBack();
                $blocked = false;
            } catch (PDOException $e) {
                if ($b->inTransaction()) {
                    $b->rollBack();
                }
                $blocked = $e->getMessage();
            }
        });
    });
    $expect($blocked === false, 'A send in progress does not block other writes that refer to the employee'.(is_string($blocked) ? ': '.$blocked : ''));
} catch (Throwable $e) {
    $expect(false, get_class($e).': '.$e->getMessage());
} finally {
    foreach ($ids ?? [] as $id) {
        try {
            $server->exec('KILL '.(int)$id);
        } catch (Throwable $e) {
        }
    }
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}
echo $fail ? "FAIL day-limit-db: {$fail}\n" : "OK day-limit-db\n";
exit($fail ? 1 : 0);
