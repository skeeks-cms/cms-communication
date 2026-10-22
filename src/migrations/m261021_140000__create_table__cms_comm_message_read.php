<?php

use yii\db\Migration;

/**
 * "Read" marks of every employee: one employee reading a letter in a shared
 * mailbox does not mark it read for the others.
 */
class m261021_140000__create_table__cms_comm_message_read extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $table = '{{%cms_comm_message_read}}';
        $this->createTable($table, [
            'id'                  => $this->primaryKey(),
            'cms_comm_message_id' => $this->integer()->notNull(),
            'cms_user_id'         => $this->integer()->notNull(),
            'read_at'             => $this->integer()->notNull(),
        ], $tableOptions);
        $this->createIndex('cms_comm_message_read__message_user', $table, ['cms_comm_message_id', 'cms_user_id'], true);
        $this->createIndex('cms_comm_message_read__user', $table, ['cms_user_id', 'cms_comm_message_id']);
        $this->addForeignKey('cms_comm_message_read__message_id', $table, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message_read__cms_user_id', $table, 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($table, 'Отметки «прочитано» по сотрудникам');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_message_read}}');
    }
}
