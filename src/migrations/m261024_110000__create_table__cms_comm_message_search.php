<?php

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\MailSearch;
use yii\db\Migration;

/**
 * Search over whole conversations: one normalized searchable line per
 * letter (sender, recipients, subject, text — from HTML when there is no
 * plain part), filled for the letters received so far.
 */
class m261024_110000__create_table__cms_comm_message_search extends Migration
{
    public function safeUp()
    {
        $mysql = $this->db->driverName === 'mysql';
        $table = '{{%cms_comm_message_search}}';
        $this->createTable($table, [
            'cms_comm_message_id'      => $this->integer()->notNull(),
            'cms_site_id'              => $this->integer()->notNull(),
            'cms_comm_conversation_id' => $this->integer()->notNull(),
            // Already lower case: a binary comparison is enough and faster.
            'search_text'              => $mysql ? 'MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL' : $this->text()->notNull(),
        ], $mysql ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB' : null);
        $this->addPrimaryKey('cms_comm_message_search__pk', $table, ['cms_comm_message_id']);
        $this->createIndex('cms_comm_message_search__site_conversation', $table, ['cms_site_id', 'cms_comm_conversation_id']);
        $this->addForeignKey('cms_comm_message_search__message_id', $table, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($table, 'Текст писем для поиска по переписке');

        $this->db->getSchema()->refreshTableSchema($table);
        $lastId = 0;
        do {
            $messages = CmsCommMessage::find()->andWhere(['>', 'id', $lastId])->orderBy(['id' => SORT_ASC])->limit(200)->all();
            $rows = [];
            foreach ($messages as $message) {
                $lastId = (int)$message->id;
                $rows[] = [(int)$message->id, (int)$message->cms_site_id, (int)$message->cms_comm_conversation_id, MailSearch::text($message)];
            }
            if ($rows) {
                $this->db->createCommand()->batchInsert($table, ['cms_comm_message_id', 'cms_site_id', 'cms_comm_conversation_id', 'search_text'], $rows)->execute();
            }
        } while ($messages);
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_message_search}}');
    }
}
