<?php

use yii\db\Migration;

/**
 * No common pool (ARCHITECTURE.md п. 13а): a CRM client always has a main
 * responsible. Campaigns whose new companies had «без ответственного» get
 * «Отправитель», base imports into a list without a responsible get their
 * author («Я»). Only these values change, so a repeated or interrupted run
 * changes nothing else; new campaigns default to «Отправитель».
 *
 * safeDown keeps the data (the earlier «без ответственного» is not a setting
 * to bring back: it would leave clients without a main responsible) and only
 * returns the default of the column.
 */
class m261109_130000__update_cms_comm_no_pool extends Migration
{
    public function safeUp()
    {
        $campaign = $this->db->getTableSchema('{{%cms_comm_campaign}}', true);
        if ($campaign && $campaign->getColumn('new_card_owner')) {
            $this->update('{{%cms_comm_campaign}}', ['new_card_owner' => 'sender'], ['new_card_owner' => 'none']);
            if ((string)$campaign->getColumn('new_card_owner')->defaultValue !== 'sender') {
                $this->alterColumn('{{%cms_comm_campaign}}', 'new_card_owner', $this->string(16)->notNull()->defaultValue('sender')
                    ->comment('Ответственный за новые компании: sender | worker'));
            }
        }
        $import = $this->db->getTableSchema('{{%cms_comm_import}}', true);
        if ($import && $import->getColumn('mode') && $import->getColumn('executor_id') && $import->getColumn('created_by')) {
            $this->update('{{%cms_comm_import}}', ['executor_id' => new \yii\db\Expression('created_by')],
                ['and', ['mode' => 'audience', 'executor_id' => null], ['not', ['created_by' => null]]]);
        }
    }

    public function safeDown()
    {
        $campaign = $this->db->getTableSchema('{{%cms_comm_campaign}}', true);
        if ($campaign && $campaign->getColumn('new_card_owner') && (string)$campaign->getColumn('new_card_owner')->defaultValue !== 'none') {
            $this->alterColumn('{{%cms_comm_campaign}}', 'new_card_owner', $this->string(16)->notNull()->defaultValue('none')
                ->comment('Ответственный за новые компании: none | sender | worker'));
        }
    }
}
