<?php

use yii\db\Migration;

/**
 * Tasks asked for in a chat (stage 5.5): the command `/task` (or a mention of
 * the bot with "задача") creates a CRM task; the row keeps the chat, the
 * command message and the task, so the bot answers once and later tells the
 * chat that the task was accepted, done or cancelled. Whether a chat hears
 * about its tasks is a setting of the chat.
 */
class m261104_120000__create_table__cms_comm_chat_task extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql' ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB' : null;

        $this->createTable('{{%cms_comm_chat_task}}', [
            'id'                       => $this->primaryKey(),
            'created_at'               => $this->integer()->null(),
            'cms_comm_conversation_id' => $this->integer()->notNull(),
            'cms_comm_message_id'      => $this->integer()->notNull()->comment('Сообщение с командой'),
            'cms_task_id'              => $this->integer()->notNull(),
            'last_status'              => $this->string(32)->null()->comment('О каком статусе задачи чат уже знает'),
        ], $tableOptions);
        $this->createIndex('cms_comm_chat_task__message', '{{%cms_comm_chat_task}}', 'cms_comm_message_id', true);
        $this->createIndex('cms_comm_chat_task__task', '{{%cms_comm_chat_task}}', 'cms_task_id');
        $this->addForeignKey('cms_comm_chat_task__conversation_id', '{{%cms_comm_chat_task}}', 'cms_comm_conversation_id', '{{%cms_comm_conversation}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_chat_task__message_id', '{{%cms_comm_chat_task}}', 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_chat_task__task_id', '{{%cms_comm_chat_task}}', 'cms_task_id', '{{%cms_task}}', 'id', 'CASCADE', 'CASCADE');

        $this->addColumn('{{%cms_comm_conversation}}', 'task_notify', $this->tinyInteger(1)->notNull()->defaultValue(1)->comment('Бот пишет в чат о задачах, поставленных из чата'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_conversation}}', 'task_notify');
        $this->dropTable('{{%cms_comm_chat_task}}');
    }
}
