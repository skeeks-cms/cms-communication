<?php

use yii\db\Migration;

/**
 * Visits of our sites by links of letters (step F). A letter that carries
 * links to our domains gets a personal code of its recipient (`sx=` in the
 * link, cms_comm_track); the script of our site counts a visit by that code
 * only after the page was shown (cms_comm_track_visit) and records the next
 * pages of the visit in the same row. A campaign recipient keeps when they
 * first came to the site (visited_at).
 */
class m261103_100000__create_tables__cms_comm_track extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $table = '{{%cms_comm_track}}';
        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'created_at' => $this->integer()->null(),
            'cms_site_id' => $this->integer()->notNull(),
            'code' => $this->string(32)->notNull()->comment('Личный код получателя в ссылках письма (sx=)'),
            'cms_comm_message_id' => $this->integer()->notNull(),
            'channel' => $this->string(32)->notNull()->defaultValue('email'),
            'recipient' => $this->string(255)->null()->comment('Адрес получателя (первый в «Кому»)'),
            'model_code' => $this->string(255)->null()->comment('Запись CRM получателя: лид, компания, клиент'),
            'model_id' => $this->integer()->null(),
            'cms_comm_campaign_id' => $this->integer()->null(),
            'offer_ref' => $this->string(64)->null()->comment('Предложение в письме: proposal:<id>'),
            'utm_campaign' => $this->string(64)->null(),
            'first_visit_at' => $this->integer()->null(),
            'last_visit_at' => $this->integer()->null(),
            'visits_count' => $this->integer()->notNull()->defaultValue(0),
        ], $tableOptions);
        $this->createIndex('cms_comm_track__code', $table, ['code'], true);
        $this->createIndex('cms_comm_track__message', $table, ['cms_comm_message_id']);
        $this->createIndex('cms_comm_track__model', $table, ['model_code', 'model_id']);
        $this->addForeignKey('cms_comm_track__cms_comm_message_id', $table, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_track__cms_site_id', $table, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');

        $table = '{{%cms_comm_track_visit}}';
        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'created_at' => $this->integer()->null(),
            'updated_at' => $this->integer()->null(),
            'cms_comm_track_id' => $this->integer()->notNull(),
            'token' => $this->string(48)->null()->comment('Визит в браузере (cookie sx_visit); у роботов и проверок ссылок пусто'),
            'ip' => $this->string(45)->null(),
            'user_agent' => $this->string(500)->null(),
            'is_bot' => $this->tinyInteger(1)->notNull()->defaultValue(0),
            'check_reason' => $this->string(16)->null()->comment('Похоже на проверку ссылок почтовой службой: early, network, browser'),
            'host' => $this->string(255)->null()->comment('Сайт визита'),
            'pages' => $this->text()->null()->comment('Страницы визита: [{url, title, at}]'),
            'pages_count' => $this->integer()->notNull()->defaultValue(0),
            'last_at' => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_track_visit__token', $table, ['token'], true);
        $this->createIndex('cms_comm_track_visit__track', $table, ['cms_comm_track_id', 'created_at']);
        $this->addForeignKey('cms_comm_track_visit__cms_comm_track_id', $table, 'cms_comm_track_id', '{{%cms_comm_track}}', 'id', 'CASCADE', 'CASCADE');

        $this->addColumn('{{%cms_comm_campaign_recipient}}', 'visited_at', $this->integer()->null()->comment('Впервые перешёл на сайт по ссылке из письма'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_campaign_recipient}}', 'visited_at');
        $this->dropTable('{{%cms_comm_track_visit}}');
        $this->dropTable('{{%cms_comm_track}}');
    }
}
