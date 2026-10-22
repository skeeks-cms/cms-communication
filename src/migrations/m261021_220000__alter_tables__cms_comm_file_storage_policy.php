<?php

use yii\db\Migration;

/**
 * Letter files that are not kept in the CRM: the row stays (name, size, place
 * in the letter) without a storage file and is fetched from the mailbox when
 * someone opens it. Mailboxes get their own file storage settings.
 */
class m261021_220000__alter_tables__cms_comm_file_storage_policy extends Migration
{
    public function safeUp()
    {
        $table = '{{%cms_comm_message_file}}';
        // A removed storage file leaves the letter file fetchable instead of deleting it.
        $this->dropForeignKey('cms_comm_message_file__storage_file_id', $table);
        $this->alterColumn($table, 'storage_file_id', $this->integer()->null()->comment('Копия в хранилище CRM; null — только в почтовом ящике'));
        $this->addForeignKey('cms_comm_message_file__storage_file_id', $table, 'storage_file_id', '{{%cms_storage_file}}', 'id', 'SET NULL', 'CASCADE');
        $this->addColumn($table, 'part_index', $this->integer()->null()->comment('Номер файла в письме для догрузки из ящика')->after('priority'));
        $this->addColumn($table, 'is_cached', $this->boolean()->notNull()->defaultValue(0)->comment('Копия догружена из ящика по запросу')->after('part_index'));
        $this->addColumn($table, 'last_access_at', $this->integer()->null()->comment('Когда файл последний раз открывали')->after('is_cached'));
        $this->addColumn($table, 'fetch_error', $this->string(255)->null()->comment('Почему не удалось догрузить из ящика')->after('last_access_at'));

        $this->addColumn('{{%cms_comm_account}}', 'file_settings', $this->text()->null()->comment('JSON: хранение файлов ящика; null — как в настройках почты'));
    }

    public function safeDown()
    {
        $table = '{{%cms_comm_message_file}}';
        $this->dropColumn('{{%cms_comm_account}}', 'file_settings');
        $this->dropColumn($table, 'fetch_error');
        $this->dropColumn($table, 'last_access_at');
        $this->dropColumn($table, 'is_cached');
        $this->dropColumn($table, 'part_index');
        $this->delete($table, ['storage_file_id' => null]);
        $this->dropForeignKey('cms_comm_message_file__storage_file_id', $table);
        $this->alterColumn($table, 'storage_file_id', $this->integer()->notNull());
        $this->addForeignKey('cms_comm_message_file__storage_file_id', $table, 'storage_file_id', '{{%cms_storage_file}}', 'id', 'CASCADE', 'CASCADE');
    }
}
