<?php

use yii\db\Migration;

/**
 * Letters collected from the mailbox history ("Сбор писем" of a mailbox):
 * they count as read and never notify anyone as new letters.
 */
class m261022_100000__alter_table__cms_comm_message_history extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_comm_message}}', 'is_history', $this->boolean()->notNull()->defaultValue(0)
            ->comment('Собрано из истории ящика: считается прочитанным')->after('is_auto'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_message}}', 'is_history');
    }
}
