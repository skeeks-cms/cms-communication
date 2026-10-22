<?php

use yii\db\Migration;

/**
 * Files of a letter: attachments and images inside its text (content_id,
 * referenced as cid: in the HTML). The bytes live in the CMS storage; they are
 * served only through the access-checked "Почта" download action.
 */
class m261021_180000__create_table__cms_comm_message_file extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $table = '{{%cms_comm_message_file}}';
        $this->createTable($table, [
            'id'                  => $this->primaryKey(),
            'created_at'          => $this->integer()->null(),
            'cms_comm_message_id' => $this->integer()->notNull(),
            'storage_file_id'     => $this->integer()->notNull(),
            'name'                => $this->string(255)->notNull()->comment('Имя файла в письме'),
            'mime_type'           => $this->string(255)->null(),
            'size'                => $this->bigInteger()->null(),
            'content_id'          => $this->string(255)->null()->comment('Content-ID картинки в тексте письма'),
            'is_inline'           => $this->boolean()->notNull()->defaultValue(0)->comment('Картинка в тексте, а не вложение'),
            'priority'            => $this->integer()->notNull()->defaultValue(100),
        ], $tableOptions);
        $this->createIndex('cms_comm_message_file__message', $table, ['cms_comm_message_id', 'priority']);
        $this->createIndex('cms_comm_message_file__storage_file', $table, ['storage_file_id']);
        $this->addForeignKey('cms_comm_message_file__message_id', $table, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message_file__storage_file_id', $table, 'storage_file_id', '{{%cms_storage_file}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($table, 'Файлы писем: вложения и картинки в тексте');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_message_file}}');
    }
}
