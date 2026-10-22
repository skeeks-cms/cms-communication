<?php

use yii\db\Migration;

/**
 * Blind copies of letters sent from the CRM. Kept only on our side: the
 * recipients never see them; received letters have no blind copies.
 */
class m261021_160000__alter_table__cms_comm_message_bcc extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_comm_message}}', 'bcc_list', $this->text()->null()->comment('JSON: адрес => имя, скрытая копия')->after('cc_list'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_message}}', 'bcc_list');
    }
}
