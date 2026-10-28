<?php

use yii\db\Migration;

/**
 * П2д: the campaign is the flow (a bot through MCP/API, a mailing): the
 * responsible of new companies, what besides a reply makes a deal, who gets
 * the deal and its pipeline. Existing campaigns keep what they did:
 * «Неразобранное» for deals (as before П2д), no responsible for new
 * companies, a view and a visit make no deal (lead_on_view stays as it was).
 * safeDown drops only the columns added here.
 */
class m261109_110000__alter_table__cms_comm_campaign_flow extends Migration
{
    private const COLUMNS = ['new_card_owner', 'new_card_worker_id', 'lead_on_visit', 'lead_to', 'lead_worker_id', 'lead_pipeline_id'];

    public function safeUp()
    {
        $table = '{{%cms_comm_campaign}}';
        $schema = $this->db->getTableSchema($table, true);
        $add = function (string $name, $type) use ($table, $schema) {
            if (!$schema->getColumn($name)) {
                $this->addColumn($table, $name, $type);
            }
        };
        $add('new_card_owner', $this->string(16)->notNull()->defaultValue('none')->comment('Ответственный за новые компании: none | sender | worker'));
        $add('new_card_worker_id', $this->integer()->null()->comment('Сотрудник для новых компаний'));
        $add('lead_on_visit', $this->boolean()->notNull()->defaultValue(0)->comment('Создавать сделку при переходе по ссылке'));
        $add('lead_to', $this->string(16)->notNull()->defaultValue('sender')->comment('Куда сделка: unsorted | sender | worker'));
        $add('lead_worker_id', $this->integer()->null()->comment('Сотрудник для сделок'));
        $add('lead_pipeline_id', $this->integer()->null()->comment('Воронка сделок; пусто — «Исходящие продажи»'));

        // Campaigns made before П2д put every new deal into «Неразобранное».
        $this->update($table, ['lead_to' => 'unsorted']);
    }

    public function safeDown()
    {
        $schema = $this->db->getTableSchema('{{%cms_comm_campaign}}', true);
        foreach (self::COLUMNS as $name) {
            if ($schema && $schema->getColumn($name)) {
                $this->dropColumn('{{%cms_comm_campaign}}', $name);
            }
        }
    }
}
