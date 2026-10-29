<?php
/**
 * Release audit N4/N5 (29.10.2026) on a SEPARATE MySQL database: the new migrations of the package
 * resume after a run stopped at any statement (MySQL does not roll DDL back, the history row is
 * written after safeUp) and the rollback of the audience refuses while it would erase the audience
 * or the import data. Uses the interruption harness of skeeks/cms (tests/support/migration-interrupt.php).
 *
 * The database (TEST_DB_NAME, skeeks_comm_n45 by default) gets empty copies of the tables from the
 * stand database (TEST_DB_SOURCE, skeeks_local), is brought back to the schema before these
 * migrations by their own safeDown and is dropped at the end. The stand database is never changed.
 *
 * COMM_MIGRATIONS_REPEAT_TEST=local php tests/migrations-repeat-db.php
 *   TEST_DB_HOST (db), TEST_DB_USER (root), TEST_DB_PASSWORD (root)
 */
if (getenv('COMM_MIGRATIONS_REPEAT_TEST') !== 'local') {
    throw new RuntimeException('Set COMM_MIGRATIONS_REPEAT_TEST=local.');
}
$root = getenv('TEST_APP_ROOT') ?: '/app';
require $root.'/vendor/autoload.php';
require $root.'/vendor/yiisoft/yii2/Yii.php';
$harness = require $root.'/vendor/skeeks/cms/tests/support/migration-interrupt.php';
$host = getenv('TEST_DB_HOST') ?: 'db';
$user = getenv('TEST_DB_USER') ?: 'root';
$password = getenv('TEST_DB_PASSWORD') ?: 'root';
$name = getenv('TEST_DB_NAME') ?: 'skeeks_comm_n45';
$source = getenv('TEST_DB_SOURCE') ?: 'skeeks_local';
if ($name === $source) {
    throw new RuntimeException('The test database must not be the stand database.');
}

$migrations = [
    'm261108_100000__create_table__cms_comm_audience' => ['cms_comm_audience', 'cms_comm_import', 'cms_comm_import_row'],
    'm261109_100000__alter_table__cms_comm_campaign_lead_on_view' => ['cms_comm_campaign'],
    'm261109_110000__alter_table__cms_comm_campaign_flow' => ['cms_comm_campaign'],
    'm261109_130000__update_cms_comm_no_pool' => ['cms_comm_campaign', 'cms_comm_import'],
];
$copy = ['cms_site', 'cms_comm_audience', 'cms_comm_import', 'cms_comm_import_row', 'cms_comm_campaign', 'cms_comm_message'];

$server = new PDO('mysql:host='.$host, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$name`; CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$fail = 0;
$expect = static function (bool $ok, string $what) use (&$fail) { $fail += $ok ? 0 : 1; echo ($ok ? 'ok    ' : 'FAIL  ').$what."\n"; };
try {
    foreach ($copy as $table) {
        $server->exec("CREATE TABLE `$name`.`$table` LIKE `$source`.`$table`");
    }
    new yii\console\Application(['id' => 'comm-n45', 'basePath' => $root, 'components' => ['db' => [
        'class' => yii\db\Connection::class, 'dsn' => 'mysql:host='.$host.';dbname='.$name, 'username' => $user, 'password' => $password, 'charset' => 'utf8mb4']]]);
    $db = Yii::$app->db;
    $dir = dirname(__DIR__).'/src/migrations/';
    foreach (array_keys($migrations) as $class) {
        require_once $dir.$class.'.php';
    }
    foreach (array_reverse(array_keys($migrations)) as $class) {
        $error = $harness->run($db, $class, 'safeDown');
        $expect($error === null, "$class: down to the schema before them".($error ? " — $error" : ''));
    }
    foreach ($migrations as $class => $tables) {
        $harness->check($db, $class, $tables, $expect);
    }

    // N4: the audience and the import data refuse the rollback before the DDL.
    $audience = 'm261108_100000__create_table__cms_comm_audience';
    $schemaBefore = $harness->snapshot($db, $migrations[$audience]);
    $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
    $db->createCommand()->insert('cms_comm_audience', ['cms_site_id' => 1, 'record_type' => 'company', 'record_id' => 900001,
        'source_name' => 'Проверка: рассылка'])->execute();
    $error = $harness->run($db, $audience, 'safeDown');
    $expect($error === 'returned false' && $harness->snapshot($db, $migrations[$audience]) === $schemaBefore
        && (int)(new yii\db\Query())->from('cms_comm_audience')->count('*', $db) === 1, 'audience: rollback refused, the schema and the row stay');
    $db->createCommand()->delete('cms_comm_audience')->execute();
    $error = $harness->run($db, $audience, 'safeDown');
    $expect($error === null && !$db->getTableSchema('cms_comm_audience', true), 'no audience data: rollback drops it');
    $harness->run($db, $audience, 'safeUp');
    $expect($harness->snapshot($db, $migrations[$audience]) === $schemaBefore, 'audience: up brings the same schema back');
} finally {
    if (isset($db)) {
        $db->close();
    }
    $server->exec("DROP DATABASE IF EXISTS `$name`");
}
if ($fail) {
    echo "FAIL: $fail\n";
    exit(1);
}
echo "OK migrations-repeat-db\n";
