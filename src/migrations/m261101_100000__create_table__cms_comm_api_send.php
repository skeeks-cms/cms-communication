<?php

use yii\db\Migration;

/**
 * Sends of checked drafts through MCP/REST (AI clients): one row per
 * idempotency key of a CRM user. The key answers a repeated request with
 * the result of the first one instead of a second letter, refuses a repeat
 * with other data, and lets a client that lost the answer (a timeout) learn
 * the result by the key. The row is written in the transaction of the
 * letter: either both exist or neither.
 */
class m261101_100000__create_table__cms_comm_api_send extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;
        $table = '{{%cms_comm_api_send}}';
        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'created_at' => $this->integer()->null(),
            'updated_at' => $this->integer()->null(),
            'cms_site_id' => $this->integer()->notNull(),
            'cms_user_id' => $this->integer()->notNull()->comment('Пользователь CRM, под которым работал клиент API'),
            'client' => $this->string(190)->null()->comment('mcp:<название OAuth-клиента>'),
            'idempotency_key' => $this->string(128)->notNull(),
            'request_hash' => $this->string(64)->notNull()->comment('Отпечаток черновика, отправителя, получателя, текста и предложения'),
            'cms_comm_draft_id' => $this->integer()->null()->comment('Отправленный черновик (после отправки удалён)'),
            'cms_comm_message_id' => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_api_send__key', $table, ['cms_site_id', 'cms_user_id', 'idempotency_key'], true);
        $this->createIndex('cms_comm_api_send__user_created', $table, ['cms_user_id', 'created_at']);
        $this->createIndex('cms_comm_api_send__message', $table, ['cms_comm_message_id']);
        $this->addForeignKey('cms_comm_api_send__cms_user_id', $table, 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_api_send__cms_comm_message_id', $table, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_api_send}}');
    }
}
