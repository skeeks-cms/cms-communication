<?php

use yii\db\Migration;

/**
 * Content fingerprint of letter files: the same logo of a signature or the same
 * file of a repeated mailing is stored once and shared by every letter.
 */
class m261021_200000__alter_table__cms_comm_message_file_hash extends Migration
{
    public function safeUp()
    {
        $table = '{{%cms_comm_message_file}}';
        $this->addColumn($table, 'content_hash', $this->string(64)->null()->comment('SHA-256 содержимого файла')->after('size'));
        $this->createIndex('cms_comm_message_file__content_hash', $table, ['content_hash', 'size']);

        // Files stored before: fingerprint what is on disk.
        $rows = (new \yii\db\Query())->from($table)->select(['id', 'storage_file_id'])->andWhere(['content_hash' => null])->all($this->db);
        foreach ($rows as $row) {
            $file = \skeeks\cms\models\CmsStorageFile::findOne((int)$row['storage_file_id']);
            $path = $file ? (string)$file->rootSrc : '';
            if ($path !== '' && is_file($path)) {
                $this->db->createCommand()->update($table, ['content_hash' => hash_file('sha256', $path)], ['id' => (int)$row['id']])->execute();
            }
        }
    }

    public function safeDown()
    {
        $this->dropIndex('cms_comm_message_file__content_hash', '{{%cms_comm_message_file}}');
        $this->dropColumn('{{%cms_comm_message_file}}', 'content_hash');
    }
}
