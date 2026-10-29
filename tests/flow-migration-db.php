<?php
/**
 * The flow migrations of the campaign (П2д, F05) on a SEPARATE database: an
 * old schema with campaigns, a repeated run, an interrupted run, safeDown
 * with and without own settings. Creates and drops the database
 * `FLOW_MIGRATION_DB` (default skeeks_flow_migration_test); the stand
 * database is never touched.
 *
 * FLOW_MIGRATION_TEST=local php tests/flow-migration-db.php
 */
if (getenv('FLOW_MIGRATION_TEST') !== 'local') {
    throw new RuntimeException('Set FLOW_MIGRATION_TEST=local.');
}
define('ROOT_DIR', getenv('TEST_APP_ROOT') ?: '/app');
require ROOT_DIR.'/vendor/autoload.php';
require ROOT_DIR.'/vendor/yiisoft/yii2/Yii.php';
$dir = dirname(__DIR__).'/src/migrations/';
require_once $dir.'m261109_100000__alter_table__cms_comm_campaign_lead_on_view.php';
require_once $dir.'m261109_110000__alter_table__cms_comm_campaign_flow.php';
require_once $dir.'m261109_130000__update_cms_comm_no_pool.php';

$name = getenv('FLOW_MIGRATION_DB') ?: 'skeeks_flow_migration_test';
$host = getenv('FLOW_MIGRATION_HOST') ?: 'db';
$server = new yii\db\Connection(['dsn' => 'mysql:host='.$host, 'username' => 'root', 'password' => getenv('FLOW_MIGRATION_PASSWORD') ?: 'root', 'charset' => 'utf8mb4']);
$server->createCommand('DROP DATABASE IF EXISTS `'.$name.'`')->execute();
$server->createCommand('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4')->execute();
$db = new yii\db\Connection(['dsn' => 'mysql:host='.$host.';dbname='.$name, 'username' => 'root', 'password' => getenv('FLOW_MIGRATION_PASSWORD') ?: 'root', 'charset' => 'utf8mb4']);
new yii\console\Application(['id' => 'flow-migration-test', 'basePath' => __DIR__, 'components' => ['db' => $db]]);

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks) {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
};
$migration = static function (string $class) use ($db): \yii\db\Migration {
    $m = new $class(['db' => $db, 'compact' => true]);

    return $m;
};
$quiet = static function (callable $fn) {
    ob_start();
    try {
        return $fn();
    } finally {
        ob_end_clean();
    }
};
$row = static fn(int $id) => (new yii\db\Query())->from('cms_comm_campaign')->andWhere(['id' => $id])->one($db);

try {
    // The old schema: a campaign table before П2д with two campaigns.
    $db->createCommand('CREATE TABLE cms_comm_campaign (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, task_on_open TINYINT(1) NOT NULL DEFAULT 1)')->execute();
    $db->createCommand()->batchInsert('cms_comm_campaign', ['name'], [['Старая 1'], ['Старая 2']])->execute();
    $view = $migration('m261109_100000__alter_table__cms_comm_campaign_lead_on_view');
    $flow = $migration('m261109_110000__alter_table__cms_comm_campaign_flow');

    $quiet(fn() => $view->up());
    $quiet(fn() => $flow->up());
    $check($row(1)['lead_to'] === 'unsorted' && $row(2)['lead_to'] === 'unsorted' && $row(1)['new_card_owner'] === 'none' && (int)$row(1)['lead_on_view'] === 0,
        'the old campaigns keep «Неразобранное», no view deal, the pool');
    $db->createCommand()->insert('cms_comm_campaign', ['name' => 'Новая'])->execute();
    $check($row(3)['lead_to'] === 'sender', 'a new row gets the default «отправитель»');

    // The new schema with data: settings of a user survive a repeated run.
    $db->createCommand()->update('cms_comm_campaign', ['lead_to' => 'sender', 'new_card_owner' => 'worker', 'new_card_worker_id' => 7, 'lead_on_visit' => 1], ['id' => 1])->execute();
    $quiet(fn() => $view->up());
    $quiet(fn() => $flow->up());
    $quiet(fn() => $flow->up());
    $r1 = $row(1);
    $check($r1['lead_to'] === 'sender' && $r1['new_card_owner'] === 'worker' && (int)$r1['new_card_worker_id'] === 7 && (int)$r1['lead_on_visit'] === 1
        && $row(2)['lead_to'] === 'unsorted', 'a second and a third run change no campaign');

    // An interrupted run: only some columns were added (lead_to with its first default), the rest are missing.
    foreach (['lead_worker_id', 'lead_pipeline_id', 'new_card_worker_id'] as $column) {
        $db->createCommand()->dropColumn('cms_comm_campaign', $column)->execute();
    }
    $db->createCommand()->alterColumn('cms_comm_campaign', 'lead_to', "VARCHAR(16) NOT NULL DEFAULT 'unsorted'")->execute();
    $db->getSchema()->refresh();
    $quiet(fn() => $flow->up());
    $schema = $db->getTableSchema('cms_comm_campaign', true);
    $check($schema->getColumn('lead_worker_id') && $schema->getColumn('lead_pipeline_id') && $schema->getColumn('new_card_worker_id')
        && $schema->getColumn('lead_to')->defaultValue === 'sender', 'an interrupted run is completed: missing columns, the default of new rows');
    $check($row(1)['lead_to'] === 'sender' && $row(2)['lead_to'] === 'unsorted' && $row(1)['new_card_owner'] === 'worker', 'completing it changes no campaign');

    // safeDown never erases own settings silently.
    $check($quiet(fn() => $flow->down()) === false && $db->getTableSchema('cms_comm_campaign', true)->getColumn('lead_to') !== null,
        'safeDown of the flow refuses while a campaign has settings of its own');
    $db->createCommand()->update('cms_comm_campaign', ['lead_on_view' => 1], ['id' => 2])->execute();
    $db->createCommand()->update('cms_comm_campaign', ['lead_to' => 'unsorted', 'new_card_owner' => 'none', 'lead_on_visit' => 0], [])->execute();
    $check($quiet(fn() => $flow->down()) !== false && $db->getTableSchema('cms_comm_campaign', true)->getColumn('lead_to') === null,
        'safeDown of the flow drops the columns when no campaign has own settings');
    $check($quiet(fn() => $view->down()) === false && $db->getTableSchema('cms_comm_campaign', true)->getColumn('lead_on_view') !== null,
        'safeDown of lead_on_view refuses while a campaign makes deals on a view');
    $db->createCommand()->update('cms_comm_campaign', ['lead_on_view' => 0], [])->execute();
    $check($quiet(fn() => $view->down()) !== false && $db->getTableSchema('cms_comm_campaign', true)->getColumn('lead_on_view') === null,
        'safeDown of lead_on_view drops the column otherwise');

    // п. 13а, no pool: «без ответственного» of old campaigns and base imports becomes the sender / the author.
    $quiet(fn() => $view->up());
    $quiet(fn() => $flow->up());
    $db->createCommand()->update('cms_comm_campaign', ['new_card_owner' => 'none'], ['id' => 1])->execute();
    $db->createCommand()->update('cms_comm_campaign', ['new_card_owner' => 'worker', 'new_card_worker_id' => 7], ['id' => 2])->execute();
    $db->createCommand('CREATE TABLE cms_comm_import (id INT AUTO_INCREMENT PRIMARY KEY, mode VARCHAR(16) NOT NULL DEFAULT \'leads\', executor_id INT NULL, created_by INT NULL)')->execute();
    $db->createCommand()->batchInsert('cms_comm_import', ['mode', 'executor_id', 'created_by'],
        [['audience', null, 5], ['audience', 9, 5], ['leads', null, 5], ['audience', null, null]])->execute();
    $pool = $migration('m261109_130000__update_cms_comm_no_pool');
    $quiet(fn() => $pool->up());
    $imports = (new yii\db\Query())->from('cms_comm_import')->orderBy('id')->all($db);
    $check($row(1)['new_card_owner'] === 'sender' && $row(2)['new_card_owner'] === 'worker' && (int)$row(2)['new_card_worker_id'] === 7,
        'no pool: «без ответственного» of a campaign becomes «Отправитель», a chosen employee stays');
    $check((int)$imports[0]['executor_id'] === 5 && (int)$imports[1]['executor_id'] === 9 && $imports[2]['executor_id'] === null && $imports[3]['executor_id'] === null,
        'no pool: a base import without a responsible gets its author; a chosen one, a requests import and an import without an author stay');
    $db->createCommand()->insert('cms_comm_campaign', ['name' => 'После 13а'])->execute();
    $check((new yii\db\Query())->from('cms_comm_campaign')->orderBy(['id' => SORT_DESC])->one($db)['new_card_owner'] === 'sender', 'no pool: a new campaign defaults to «Отправитель»');
    $db->createCommand()->update('cms_comm_campaign', ['new_card_owner' => 'worker'], ['id' => 1])->execute();
    $quiet(fn() => $pool->up());
    $check($row(1)['new_card_owner'] === 'worker', 'no pool: a repeated run changes no setting');
    $check($quiet(fn() => $pool->down()) !== false && $row(1)['new_card_owner'] === 'worker' && (int)(new yii\db\Query())->from('cms_comm_import')->andWhere(['id' => 1])->one($db)['executor_id'] === 5,
        'no pool: safeDown keeps the data');

    echo 'OK flow-migration-db: '.$checks." checks\n";
} finally {
    $server->createCommand('DROP DATABASE IF EXISTS `'.$name.'`')->execute();
}
