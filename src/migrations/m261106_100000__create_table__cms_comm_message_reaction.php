<?php

use yii\db\Migration;

/**
 * Reactions on chat messages (a thumbs up, a heart): from people of the
 * messenger (cms_comm_identity_id, the whole set of a person replaced by
 * each reaction event) and from employees in the CRM (cms_user_id, one emoji
 * an employee; the bot shows the latest of them in the messenger, a bot may
 * set one reaction a message).
 */
class m261106_100000__create_table__cms_comm_message_reaction extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql' ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB' : null;

        $this->createTable('{{%cms_comm_message_reaction}}', [
            'id'                   => $this->primaryKey(),
            'created_at'           => $this->integer()->null(),
            'cms_comm_message_id'  => $this->integer()->notNull(),
            'cms_comm_identity_id' => $this->integer()->null()->comment('Человек мессенджера'),
            'cms_user_id'          => $this->integer()->null()->comment('Сотрудник, поставивший реакцию из CRM'),
            'emoji'                => $this->string(32)->notNull(),
        ], $tableOptions);
        $this->createIndex('cms_comm_message_reaction__message', '{{%cms_comm_message_reaction}}', 'cms_comm_message_id');
        $this->addForeignKey('cms_comm_message_reaction__message_id', '{{%cms_comm_message_reaction}}', 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message_reaction__identity_id', '{{%cms_comm_message_reaction}}', 'cms_comm_identity_id', '{{%cms_comm_identity}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message_reaction__user_id', '{{%cms_comm_message_reaction}}', 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_message_reaction}}');
    }
}
