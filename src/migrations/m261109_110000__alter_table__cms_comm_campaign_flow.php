<?php

use yii\db\Migration;

/**
 * П2д: the campaign is the flow (a bot through MCP/API, a mailing): the
 * responsible of new companies, what besides a reply makes a deal, who gets
 * the deal and its pipeline.
 *
 * Existing campaigns keep what they did before П2д — «Неразобранное» for
 * deals: `lead_to` is added with the default `unsorted`, so the rows present
 * at that moment get it in the same DDL statement, and only then the default
 * of new rows becomes `sender`. No UPDATE of data: a repeated or an
 * interrupted run (MySQL DDL is not transactional) adds only what is missing
 * and never changes a campaign already set up.
 *
 * safeDown refuses while a campaign has flow settings of its own (dropping
 * the columns would erase them silently); a site rolls back such data from
 * its database backup.
 */
class m261109_110000__alter_table__cms_comm_campaign_flow extends Migration
{
    private const TABLE = '{{%cms_comm_campaign}}';

    public function safeUp()
    {
        $add = function (string $name, $type) {
            if (!$this->db->getTableSchema(self::TABLE, true)->getColumn($name)) {
                $this->addColumn(self::TABLE, $name, $type);
            }
        };
        $add('new_card_owner', $this->string(16)->notNull()->defaultValue('none')->comment('Ответственный за новые компании: none | sender | worker'));
        $add('new_card_worker_id', $this->integer()->null()->comment('Сотрудник для новых компаний'));
        $add('lead_on_visit', $this->boolean()->notNull()->defaultValue(0)->comment('Создавать сделку при переходе по ссылке'));
        // The campaigns made before П2д: «Неразобранное», set by the DDL itself.
        $add('lead_to', $this->string(16)->notNull()->defaultValue('unsorted')->comment('Куда сделка: unsorted | sender | worker'));
        $add('lead_worker_id', $this->integer()->null()->comment('Сотрудник для сделок'));
        $add('lead_pipeline_id', $this->integer()->null()->comment('Воронка сделок; пусто — «Исходящие продажи»'));
        // New campaigns: the sender (the model sets it from the CRM settings anyway). Changes no row.
        if ((string)$this->db->getTableSchema(self::TABLE, true)->getColumn('lead_to')->defaultValue !== 'sender') {
            $this->alterColumn(self::TABLE, 'lead_to', $this->string(16)->notNull()->defaultValue('sender')->comment('Куда сделка: unsorted | sender | worker'));
        }
    }

    public function safeDown()
    {
        $schema = $this->db->getTableSchema(self::TABLE, true);
        $own = ['or'];
        foreach (['new_card_owner' => ['<>', 'new_card_owner', 'none'], 'new_card_worker_id' => ['not', ['new_card_worker_id' => null]],
                     'lead_on_visit' => ['lead_on_visit' => 1], 'lead_to' => ['<>', 'lead_to', 'unsorted'],
                     'lead_worker_id' => ['not', ['lead_worker_id' => null]], 'lead_pipeline_id' => ['not', ['lead_pipeline_id' => null]]] as $name => $condition) {
            if ($schema->getColumn($name)) {
                $own[] = $condition;
            }
        }
        $count = count($own) > 1 ? (int)(new \yii\db\Query())->from(self::TABLE)->andWhere($own)->count('*', $this->db) : 0;
        if ($count) {
            echo '    > refused: '.$count." campaign(s) have flow settings (new companies, deals, pipeline); dropping the columns would erase them. Restore the database backup instead.\n";

            return false;
        }
        foreach (['new_card_owner', 'new_card_worker_id', 'lead_on_visit', 'lead_to', 'lead_worker_id', 'lead_pipeline_id'] as $name) {
            if ($this->db->getTableSchema(self::TABLE, true)->getColumn($name)) {
                $this->dropColumn(self::TABLE, $name);
            }
        }
    }
}
