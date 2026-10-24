<?php

use yii\db\Migration;

/**
 * The photo of a person in a messenger (from the members of a group or the
 * person of a private dialog), kept in the CMS storage, as the picture of a
 * chat.
 */
class m261030_120000__alter_table__cms_comm_identity_photo extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_comm_identity}}', 'photo_storage_file_id', $this->integer()->null()->comment('Фото человека в хранилище'));
        $this->addColumn('{{%cms_comm_identity}}', 'photo_ref', $this->string(1000)->null()->comment('Откуда взято фото: не скачивать то же снова'));
        $this->addForeignKey('cms_comm_identity__photo_storage_file_id', '{{%cms_comm_identity}}', 'photo_storage_file_id', '{{%cms_storage_file}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropForeignKey('cms_comm_identity__photo_storage_file_id', '{{%cms_comm_identity}}');
        $this->dropColumn('{{%cms_comm_identity}}', 'photo_ref');
        $this->dropColumn('{{%cms_comm_identity}}', 'photo_storage_file_id');
    }
}
