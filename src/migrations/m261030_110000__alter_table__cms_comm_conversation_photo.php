<?php

use yii\db\Migration;

/**
 * The picture of a bot chat — the group's icon or the photo of the person
 * of a private dialog — kept in the CMS storage, and when the messenger was
 * last asked about the chat (title, picture).
 */
class m261030_110000__alter_table__cms_comm_conversation_photo extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_comm_conversation}}', 'photo_storage_file_id', $this->integer()->null()->comment('Фото чата в хранилище'));
        $this->addColumn('{{%cms_comm_conversation}}', 'photo_ref', $this->string(1000)->null()->comment('Откуда взято фото: не скачивать то же снова'));
        $this->addColumn('{{%cms_comm_conversation}}', 'info_checked_at', $this->integer()->null()->comment('Когда спрашивали мессенджер о названии и фото'));
        $this->addForeignKey('cms_comm_conversation__photo_storage_file_id', '{{%cms_comm_conversation}}', 'photo_storage_file_id', '{{%cms_storage_file}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropForeignKey('cms_comm_conversation__photo_storage_file_id', '{{%cms_comm_conversation}}');
        $this->dropColumn('{{%cms_comm_conversation}}', 'info_checked_at');
        $this->dropColumn('{{%cms_comm_conversation}}', 'photo_ref');
        $this->dropColumn('{{%cms_comm_conversation}}', 'photo_storage_file_id');
    }
}
