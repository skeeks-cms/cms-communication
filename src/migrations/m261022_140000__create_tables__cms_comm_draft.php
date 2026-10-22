<?php

use yii\db\Migration;

/**
 * Letters being written, saved automatically by the letter form. Files of a
 * draft live in the CMS storage with the draft (cms_comm_draft_file), not in
 * the temporary upload area that is cleaned after an hour.
 */
class m261022_140000__create_tables__cms_comm_draft extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $table = '{{%cms_comm_draft}}';
        $this->createTable($table, [
            'id'                  => $this->primaryKey(),
            'created_at'          => $this->integer()->null(),
            'updated_at'          => $this->integer()->null(),
            'cms_site_id'         => $this->integer()->notNull(),
            'cms_user_id'         => $this->integer()->notNull(),
            'cms_comm_account_id' => $this->integer()->null()->comment('От кого'),
            'to_list'             => $this->text()->null()->comment('Кому, как набрано'),
            'cc_list'             => $this->text()->null(),
            'bcc_list'            => $this->text()->null(),
            'subject'             => $this->string(500)->null(),
            'body_html'           => $this->getDb()->getSchema()->createColumnSchemaBuilder('mediumtext')->null(),
            'reply_to_message_id' => $this->integer()->null()->comment('Письмо, на которое отвечают'),
            'forward_message_id'  => $this->integer()->null()->comment('Пересылаемое письмо'),
            'is_reply_all'        => $this->boolean()->notNull()->defaultValue(0),
            'include_quote'       => $this->boolean()->notNull()->defaultValue(1),
            'context_model_code'  => $this->string(190)->null(),
            'context_model_id'    => $this->integer()->null(),
            'file_ids'            => $this->text()->null()->comment('Вложения пересылаемого письма (JSON, id файлов хранилища)'),
        ], $tableOptions);
        $this->createIndex('cms_comm_draft__user', $table, ['cms_site_id', 'cms_user_id', 'updated_at']);
        $this->createIndex('cms_comm_draft__reply', $table, ['cms_user_id', 'reply_to_message_id']);
        $this->createIndex('cms_comm_draft__forward', $table, ['cms_user_id', 'forward_message_id']);
        $this->addForeignKey('cms_comm_draft__cms_site_id', $table, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_draft__cms_user_id', $table, 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_draft__account_id', $table, 'cms_comm_account_id', '{{%cms_comm_account}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_draft__reply_to_message_id', $table, 'reply_to_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_draft__forward_message_id', $table, 'forward_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($table, 'Черновики писем');

        $table = '{{%cms_comm_draft_file}}';
        $this->createTable($table, [
            'id'                => $this->primaryKey(),
            'created_at'        => $this->integer()->null(),
            'cms_comm_draft_id' => $this->integer()->notNull(),
            'storage_file_id'   => $this->integer()->null(),
            'name'              => $this->string(255)->notNull(),
            'size'              => $this->bigInteger()->null(),
            'content_hash'      => $this->string(64)->null()->comment('SHA-256: одна копия содержимого на сайт'),
            'is_inline'         => $this->boolean()->notNull()->defaultValue(0)->comment('Картинка в тексте, а не вложение'),
            'token'             => $this->string(255)->null()->comment('Временный файл формы, которым файл открыт сейчас'),
            'priority'          => $this->integer()->notNull()->defaultValue(100),
        ], $tableOptions);
        $this->createIndex('cms_comm_draft_file__draft', $table, ['cms_comm_draft_id', 'priority']);
        $this->createIndex('cms_comm_draft_file__storage_file', $table, ['storage_file_id']);
        $this->createIndex('cms_comm_draft_file__hash', $table, ['content_hash']);
        $this->addForeignKey('cms_comm_draft_file__draft_id', $table, 'cms_comm_draft_id', '{{%cms_comm_draft}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_draft_file__storage_file_id', $table, 'storage_file_id', '{{%cms_storage_file}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($table, 'Файлы черновиков писем');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_draft_file}}');
        $this->dropTable('{{%cms_comm_draft}}');
    }
}
