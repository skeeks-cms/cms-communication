<?php

use yii\db\Migration;

/**
 * Drafts of available employees (owner's decision 03.11.2026): a leader or an
 * administrator may send a draft of an employee he manages. The letter is the
 * employee's (his mailbox, his signature, sent_by_user_id), and keeps who
 * actually sent it ("по поручению"); a send through the API keeps whose draft
 * it sent, so the day limit counts against the owner of the mailbox.
 *
 * Every statement runs only when its result is missing: a run stopped at any
 * statement (MySQL does not roll DDL back) resumes, a repeated run changes nothing.
 */
class m261110_100000__alter_tables__cms_comm_acted_by extends Migration
{
    public function safeUp()
    {
        if (!$this->column('{{%cms_comm_message}}', 'acted_by_user_id')) {
            $this->addColumn('{{%cms_comm_message}}', 'acted_by_user_id', $this->integer()->null()
                ->comment('Кто фактически отправил письмо сотрудника (руководитель, администратор); пусто — сам отправитель'));
        }
        if (!$this->foreignKey('{{%cms_comm_message}}', 'cms_comm_message__acted_by_user_id')) {
            $this->addForeignKey('cms_comm_message__acted_by_user_id', '{{%cms_comm_message}}', 'acted_by_user_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        }
        if (!$this->column('{{%cms_comm_api_send}}', 'sender_user_id')) {
            $this->addColumn('{{%cms_comm_api_send}}', 'sender_user_id', $this->integer()->null()
                ->comment('Чей черновик отправлен (его ящик и дневной лимит); пусто — сам пользователь API'));
        }
        if (!$this->index('{{%cms_comm_api_send}}', 'cms_comm_api_send__sender')) {
            $this->createIndex('cms_comm_api_send__sender', '{{%cms_comm_api_send}}', ['cms_site_id', 'sender_user_id', 'created_at']);
        }
        if (!$this->foreignKey('{{%cms_comm_api_send}}', 'cms_comm_api_send__sender_user_id')) {
            $this->addForeignKey('cms_comm_api_send__sender_user_id', '{{%cms_comm_api_send}}', 'sender_user_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        }
    }

    public function safeDown()
    {
        if ($this->foreignKey('{{%cms_comm_api_send}}', 'cms_comm_api_send__sender_user_id')) {
            $this->dropForeignKey('cms_comm_api_send__sender_user_id', '{{%cms_comm_api_send}}');
        }
        if ($this->index('{{%cms_comm_api_send}}', 'cms_comm_api_send__sender')) {
            $this->dropIndex('cms_comm_api_send__sender', '{{%cms_comm_api_send}}');
        }
        if ($this->column('{{%cms_comm_api_send}}', 'sender_user_id')) {
            $this->dropColumn('{{%cms_comm_api_send}}', 'sender_user_id');
        }
        if ($this->foreignKey('{{%cms_comm_message}}', 'cms_comm_message__acted_by_user_id')) {
            $this->dropForeignKey('cms_comm_message__acted_by_user_id', '{{%cms_comm_message}}');
        }
        if ($this->column('{{%cms_comm_message}}', 'acted_by_user_id')) {
            $this->dropColumn('{{%cms_comm_message}}', 'acted_by_user_id');
        }
    }

    private function column(string $table, string $name): bool
    {
        $schema = $this->db->getTableSchema($table, true);

        return $schema && $schema->getColumn($name);
    }

    private function foreignKey(string $table, string $name): bool
    {
        $schema = $this->db->getTableSchema($table, true);

        return $schema && isset($schema->foreignKeys[$name]);
    }

    private function index(string $table, string $name): bool
    {
        foreach ($this->db->getSchema()->getTableIndexes($table, true) as $index) {
            if ($index->name === $name) {
                return true;
            }
        }

        return false;
    }
}
