<?php

use yii\db\Migration;

/**
 * Manual sorting of letters: records an employee unlinked from a whole
 * conversation (later letters of it are not linked to them again by address
 * or by the thread), and who assigned the person responsible for the
 * conversation and when.
 */
class m261023_100000__create_table__cms_comm_link_exclusion extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $table = '{{%cms_comm_link_exclusion}}';
        $this->createTable($table, [
            'id'                       => $this->primaryKey(),
            'created_at'               => $this->integer()->null(),
            'created_by'               => $this->integer()->null(),
            'cms_comm_conversation_id' => $this->integer()->notNull(),
            'model_code'               => $this->string(190)->notNull(),
            'model_id'                 => $this->integer()->notNull(),
        ], $tableOptions);
        $this->createIndex('cms_comm_link_exclusion__conversation_model', $table, ['cms_comm_conversation_id', 'model_code', 'model_id'], true);
        $this->addForeignKey('cms_comm_link_exclusion__conversation_id', $table, 'cms_comm_conversation_id', '{{%cms_comm_conversation}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_link_exclusion__created_by', $table, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($table, 'Записи CRM, отвязанные от переписки вручную: автоматическая привязка их не возвращает');

        $conversation = '{{%cms_comm_conversation}}';
        $this->addColumn($conversation, 'assigned_at', $this->integer()->null()->comment('Когда назначен ответственный')->after('assigned_user_id'));
        $this->addColumn($conversation, 'assigned_by', $this->integer()->null()->comment('Кто назначил ответственного')->after('assigned_at'));
        $this->addForeignKey('cms_comm_conversation__assigned_by', $conversation, 'assigned_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $conversation = '{{%cms_comm_conversation}}';
        $this->dropForeignKey('cms_comm_conversation__assigned_by', $conversation);
        $this->dropColumn($conversation, 'assigned_by');
        $this->dropColumn($conversation, 'assigned_at');
        $this->dropTable('{{%cms_comm_link_exclusion}}');
    }
}
