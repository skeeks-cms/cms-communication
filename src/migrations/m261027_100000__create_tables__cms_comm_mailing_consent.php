<?php

use yii\db\Migration;

/**
 * Mailings, stage 4A: topics of advertising mailings, contacts (a normalized
 * email or phone, later a bot chat) with their consent and opt-out by topic,
 * the stop list (opted out of all advertising, complaint, undeliverable),
 * the topic and the personal opt-out link of an advertising letter, and
 * imports of recipients into leads with a row-by-row report.
 */
class m261027_100000__create_tables__cms_comm_mailing_consent extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $topic = '{{%cms_comm_topic}}';
        $this->createTable($topic, [
            'id'          => $this->primaryKey(),
            'created_by'  => $this->integer()->null(),
            'updated_by'  => $this->integer()->null(),
            'created_at'  => $this->integer()->null(),
            'updated_at'  => $this->integer()->null(),
            'cms_site_id' => $this->integer()->notNull(),
            'name'        => $this->string(255)->notNull()->comment('Название темы'),
            'description' => $this->text()->null()->comment('Описание для клиента на странице отписки'),
            'is_active'   => $this->boolean()->notNull()->defaultValue(1),
            'priority'    => $this->integer()->notNull()->defaultValue(100),
        ], $tableOptions);
        $this->createIndex('cms_comm_topic__site_name', $topic, ['cms_site_id', 'name'], true);
        $this->addForeignKey('cms_comm_topic__cms_site_id', $topic, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_topic__created_by', $topic, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_topic__updated_by', $topic, 'updated_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($topic, 'Темы рекламных рассылок');

        $contact = '{{%cms_comm_contact}}';
        $this->createTable($contact, [
            'id'          => $this->primaryKey(),
            'created_at'  => $this->integer()->null(),
            'updated_at'  => $this->integer()->null(),
            'cms_site_id' => $this->integer()->notNull(),
            'kind'        => $this->string(32)->notNull()->comment('email, phone; позже чат бота'),
            'value'       => $this->string(255)->notNull()->comment('Нормализованный: email в нижнем регистре, телефон цифрами 79991234567'),
            'code'        => $this->string(64)->notNull()->comment('Код личной ссылки отписки'),
        ], $tableOptions);
        $this->createIndex('cms_comm_contact__site_kind_value', $contact, ['cms_site_id', 'kind', 'value'], true);
        $this->createIndex('cms_comm_contact__code', $contact, ['code'], true);
        $this->addForeignKey('cms_comm_contact__cms_site_id', $contact, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($contact, 'Контакты рассылок: адрес почты, номер телефона');

        $contactTopic = '{{%cms_comm_contact_topic}}';
        $this->createTable($contactTopic, [
            'id'                  => $this->primaryKey(),
            'created_at'          => $this->integer()->null(),
            'updated_at'          => $this->integer()->null(),
            'cms_comm_contact_id' => $this->integer()->notNull(),
            'cms_comm_topic_id'   => $this->integer()->notNull(),
            'status'              => $this->string(32)->notNull()->comment('subscribed, unsubscribed'),
            'basis'               => $this->string(32)->null()->comment('Основание согласия: site, verbal, cold'),
            'consent_at'          => $this->integer()->null(),
            'consent_by'          => $this->integer()->null()->comment('Кто отметил согласие'),
            'unsubscribed_at'     => $this->integer()->null(),
            'unsubscribed_by'     => $this->integer()->null()->comment('Сотрудник, отметивший отписку'),
            'unsubscribe_source'  => $this->string(32)->null()->comment('link, one_click, manager, import, api'),
            'cms_comm_message_id' => $this->integer()->null()->comment('Письмо, из которого пришла отписка'),
            'note'                => $this->string(255)->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_contact_topic__contact_topic', $contactTopic, ['cms_comm_contact_id', 'cms_comm_topic_id'], true);
        $this->createIndex('cms_comm_contact_topic__topic_status', $contactTopic, ['cms_comm_topic_id', 'status']);
        $this->addForeignKey('cms_comm_contact_topic__contact_id', $contactTopic, 'cms_comm_contact_id', $contact, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_contact_topic__topic_id', $contactTopic, 'cms_comm_topic_id', $topic, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_contact_topic__consent_by', $contactTopic, 'consent_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_contact_topic__unsubscribed_by', $contactTopic, 'unsubscribed_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_contact_topic__message_id', $contactTopic, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($contactTopic, 'Согласие и отписка контакта по теме рассылки');

        $suppression = '{{%cms_comm_suppression}}';
        $this->createTable($suppression, [
            'id'                  => $this->primaryKey(),
            'created_at'          => $this->integer()->null(),
            'created_by'          => $this->integer()->null(),
            'cms_site_id'         => $this->integer()->notNull(),
            'cms_comm_contact_id' => $this->integer()->notNull(),
            'reason'              => $this->string(32)->notNull()->comment('unsubscribed_all, complaint, bounce'),
            'source'              => $this->string(32)->null()->comment('link, one_click, manager, provider, import, api'),
            'cms_comm_message_id' => $this->integer()->null(),
            'note'                => $this->string(500)->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_suppression__contact_reason', $suppression, ['cms_comm_contact_id', 'reason'], true);
        $this->createIndex('cms_comm_suppression__site_reason', $suppression, ['cms_site_id', 'reason']);
        $this->addForeignKey('cms_comm_suppression__cms_site_id', $suppression, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_suppression__contact_id', $suppression, 'cms_comm_contact_id', $contact, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_suppression__created_by', $suppression, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_suppression__message_id', $suppression, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($suppression, 'Стоп-лист: отписан от всех рекламных, жалоба, недоставляемый адрес');

        $message = '{{%cms_comm_message}}';
        $this->addColumn($message, 'cms_comm_topic_id', $this->integer()->null()->comment('Тема рекламной рассылки: у письма есть отписка')->after('provider_ref'));
        $this->addColumn($message, 'unsubscribe_url', $this->string(500)->null()->comment('Личная ссылка отписки получателя (List-Unsubscribe)')->after('cms_comm_topic_id'));
        $this->addForeignKey('cms_comm_message__cms_comm_topic_id', $message, 'cms_comm_topic_id', $topic, 'id', 'SET NULL', 'CASCADE');

        $import = '{{%cms_comm_import}}';
        $this->createTable($import, [
            'id'                => $this->primaryKey(),
            'created_by'        => $this->integer()->null(),
            'updated_by'        => $this->integer()->null(),
            'created_at'        => $this->integer()->null(),
            'updated_at'        => $this->integer()->null(),
            'cms_site_id'       => $this->integer()->notNull(),
            'file_name'         => $this->string(255)->null(),
            'status'            => $this->string(32)->notNull()->comment('draft, queued, running, done, failed'),
            'columns'           => $this->text()->null()->comment('JSON: заголовки колонок файла'),
            'mapping'           => $this->text()->null()->comment('JSON: номер колонки => поле'),
            'executor_id'       => $this->integer()->null()->comment('Ответственный за новые лиды'),
            'cms_comm_topic_id' => $this->integer()->null(),
            'consent_basis'     => $this->string(32)->null(),
            'row_count'         => $this->integer()->notNull()->defaultValue(0),
            'created_count'     => $this->integer()->notNull()->defaultValue(0),
            'duplicate_count'   => $this->integer()->notNull()->defaultValue(0),
            'skipped_count'     => $this->integer()->notNull()->defaultValue(0),
            'cms_job_run_id'    => $this->integer()->null(),
            'error'             => $this->string(1000)->null(),
            'finished_at'       => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_import__site_created', $import, ['cms_site_id', 'created_at']);
        $this->addForeignKey('cms_comm_import__cms_site_id', $import, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_import__created_by', $import, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_import__updated_by', $import, 'updated_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_import__executor_id', $import, 'executor_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_import__topic_id', $import, 'cms_comm_topic_id', $topic, 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($import, 'Импорт получателей рассылок в лиды');

        $row = '{{%cms_comm_import_row}}';
        $this->createTable($row, [
            'id'                 => $this->primaryKey(),
            'cms_comm_import_id' => $this->integer()->notNull(),
            'row_num'            => $this->integer()->notNull()->comment('Номер строки в файле'),
            'data'               => $this->text()->notNull()->comment('JSON: значения колонок'),
            'status'             => $this->string(32)->null()->comment('created, duplicate, skipped'),
            'reason'             => $this->string(500)->null(),
            'cms_lead_id'        => $this->integer()->null()->comment('Созданный лид'),
            'match_code'         => $this->string(190)->null()->comment('Найденная запись CRM: класс'),
            'match_id'           => $this->integer()->null(),
            'matched_by'         => $this->string(64)->null()->comment('email, phone, inn, name, file'),
        ], $tableOptions);
        $this->createIndex('cms_comm_import_row__import_row', $row, ['cms_comm_import_id', 'row_num'], true);
        $this->createIndex('cms_comm_import_row__import_status', $row, ['cms_comm_import_id', 'status']);
        $this->addForeignKey('cms_comm_import_row__import_id', $row, 'cms_comm_import_id', $import, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_import_row__cms_lead_id', $row, 'cms_lead_id', '{{%cms_lead}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($row, 'Строки импорта и их итог');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_import_row}}');
        $this->dropTable('{{%cms_comm_import}}');

        $message = '{{%cms_comm_message}}';
        $this->dropForeignKey('cms_comm_message__cms_comm_topic_id', $message);
        $this->dropColumn($message, 'unsubscribe_url');
        $this->dropColumn($message, 'cms_comm_topic_id');

        $this->dropTable('{{%cms_comm_suppression}}');
        $this->dropTable('{{%cms_comm_contact_topic}}');
        $this->dropTable('{{%cms_comm_contact}}');
        $this->dropTable('{{%cms_comm_topic}}');
    }
}
