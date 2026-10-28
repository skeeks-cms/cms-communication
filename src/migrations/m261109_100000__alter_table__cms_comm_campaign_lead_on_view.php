<?php

use yii\db\Migration;

/**
 * П2д: a campaign may create a deal when a recipient views its presentation
 * or offer (off by default; the default of a new campaign is the CRM setting
 * «Исходящие продажи»).
 */
class m261109_100000__alter_table__cms_comm_campaign_lead_on_view extends Migration
{
    public function safeUp()
    {
        $table = $this->db->getTableSchema('{{%cms_comm_campaign}}', true);
        if ($table && !$table->getColumn('lead_on_view')) {
            $this->addColumn('{{%cms_comm_campaign}}', 'lead_on_view', $this->boolean()->notNull()->defaultValue(0)
                ->comment('Создавать сделку при просмотре Презентации или КП')->after('task_on_open'));
        }
    }

    /** Refuses while a campaign makes deals on a view: dropping the column would erase that setting silently. */
    public function safeDown()
    {
        $table = $this->db->getTableSchema('{{%cms_comm_campaign}}', true);
        if (!$table || !$table->getColumn('lead_on_view')) {
            return;
        }
        $count = (int)(new \yii\db\Query())->from('{{%cms_comm_campaign}}')->andWhere(['lead_on_view' => 1])->count('*', $this->db);
        if ($count) {
            echo '    > refused: '.$count." campaign(s) make a deal on a view; dropping lead_on_view would erase it. Restore the database backup instead.\n";

            return false;
        }
        $this->dropColumn('{{%cms_comm_campaign}}', 'lead_on_view');
    }
}
