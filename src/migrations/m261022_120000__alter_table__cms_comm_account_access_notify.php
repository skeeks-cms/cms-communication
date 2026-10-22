<?php

use yii\db\Migration;

/**
 * The employee's choice "уведомлять о новых письмах" for each mailbox they
 * read: new incoming letters create a CmsWebNotify unless it is switched off.
 */
class m261022_120000__alter_table__cms_comm_account_access_notify extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_comm_account_access}}', 'is_notify', $this->boolean()->notNull()->defaultValue(1)
            ->comment('Уведомлять сотрудника о новых письмах ящика')->after('is_responsible'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_account_access}}', 'is_notify');
    }
}
