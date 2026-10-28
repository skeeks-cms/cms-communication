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

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_campaign}}', 'lead_on_view');
    }
}
