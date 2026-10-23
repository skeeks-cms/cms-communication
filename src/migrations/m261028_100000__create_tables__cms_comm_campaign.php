<?php

use yii\db\Migration;

/**
 * Mailings, stage 4B: campaigns (a topic, an audience, a letter with an
 * offer, a channel), their recipients fixed at launch with the report of
 * each one, the campaign mark of a letter and the sending pace of a mailbox
 * (letters per day, pauses, working hours).
 */
class m261028_100000__create_tables__cms_comm_campaign extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $campaign = '{{%cms_comm_campaign}}';
        $this->createTable($campaign, [
            'id'                  => $this->primaryKey(),
            'created_by'          => $this->integer()->null(),
            'updated_by'          => $this->integer()->null(),
            'created_at'          => $this->integer()->null(),
            'updated_at'          => $this->integer()->null(),
            'cms_site_id'         => $this->integer()->notNull(),
            'name'                => $this->string(255)->notNull(),
            'status'              => $this->string(32)->notNull()->comment('draft, running, paused, done, cancelled'),
            'cms_comm_topic_id'   => $this->integer()->null()->comment('Тема рассылки: обязательна для запуска'),
            'audience_type'       => $this->string(32)->notNull()->comment('leads — лиды по фильтру, import — строки импорта; позже companies, clients'),
            'audience'            => $this->text()->null()->comment('JSON: условия аудитории'),
            'channel'             => $this->string(32)->notNull()->comment('Канал кампании: mailbox — ящики менеджеров; позже сервисы рассылок'),
            'sender_mode'         => $this->string(32)->notNull()->comment('responsible — ящик ответственного за лида, account — выбранный ящик'),
            'cms_comm_account_id' => $this->integer()->null()->comment('Выбранный ящик'),
            'subject'             => $this->string(500)->null(),
            'body_html'           => $this->text()->null(),
            'offer_kind'          => $this->string(32)->notNull()->comment('none, shared — один документ всем, each — каждому свой из шаблона'),
            'offer_type'          => $this->string(64)->null()->comment('Тип отправляемого: proposal'),
            'offer_id'            => $this->integer()->null()->comment('Документ (shared) или шаблон (each)'),
            'task_on_open'        => $this->boolean()->notNull()->defaultValue(1),
            'task_on_reply'       => $this->boolean()->notNull()->defaultValue(1),
            'launched_at'         => $this->integer()->null(),
            'launched_by'         => $this->integer()->null(),
            'finished_at'         => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_campaign__site_status', $campaign, ['cms_site_id', 'status']);
        $this->addForeignKey('cms_comm_campaign__cms_site_id', $campaign, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_campaign__created_by', $campaign, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_campaign__updated_by', $campaign, 'updated_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_campaign__launched_by', $campaign, 'launched_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_campaign__topic_id', $campaign, 'cms_comm_topic_id', '{{%cms_comm_topic}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_campaign__account_id', $campaign, 'cms_comm_account_id', '{{%cms_comm_account}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($campaign, 'Кампании рассылок');

        $recipient = '{{%cms_comm_campaign_recipient}}';
        $this->createTable($recipient, [
            'id'                   => $this->primaryKey(),
            'created_at'           => $this->integer()->null(),
            'updated_at'           => $this->integer()->null(),
            'cms_comm_campaign_id' => $this->integer()->notNull(),
            'record_type'          => $this->string(32)->notNull()->comment('lead; позже company, client'),
            'record_id'            => $this->integer()->notNull(),
            'address'              => $this->string(255)->null()->comment('Email получателя'),
            'name'                 => $this->string(255)->null(),
            'cms_comm_account_id'  => $this->integer()->null()->comment('Ящик, с которого уходит письмо'),
            'sender_user_id'       => $this->integer()->null()->comment('Сотрудник, от имени которого письмо'),
            'status'               => $this->string(32)->notNull()->comment('excluded, pending, sent, failed, cancelled'),
            'reason'               => $this->string(64)->null()->comment('Почему исключён'),
            'error'                => $this->string(1000)->null(),
            'cms_comm_message_id'  => $this->integer()->null(),
            'send_ref'             => $this->string(190)->null()->comment('Отправленный документ: type:id'),
            'sent_at'              => $this->integer()->null(),
            'opened_at'            => $this->integer()->null(),
            'replied_at'           => $this->integer()->null(),
            'accepted_at'          => $this->integer()->null(),
            'unsubscribed_at'      => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_campaign_recipient__campaign_record', $recipient, ['cms_comm_campaign_id', 'record_type', 'record_id'], true);
        $this->createIndex('cms_comm_campaign_recipient__campaign_status', $recipient, ['cms_comm_campaign_id', 'status']);
        $this->createIndex('cms_comm_campaign_recipient__account_status', $recipient, ['cms_comm_account_id', 'status']);
        $this->createIndex('cms_comm_campaign_recipient__message', $recipient, ['cms_comm_message_id']);
        $this->addForeignKey('cms_comm_campaign_recipient__campaign_id', $recipient, 'cms_comm_campaign_id', $campaign, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_campaign_recipient__account_id', $recipient, 'cms_comm_account_id', '{{%cms_comm_account}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_campaign_recipient__sender_user_id', $recipient, 'sender_user_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_campaign_recipient__message_id', $recipient, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($recipient, 'Получатели кампании и отчёт по каждому');

        $message = '{{%cms_comm_message}}';
        $this->addColumn($message, 'cms_comm_campaign_id', $this->integer()->null()->comment('Кампания рассылки, отправившая письмо')->after('unsubscribe_url'));
        $this->addForeignKey('cms_comm_message__cms_comm_campaign_id', $message, 'cms_comm_campaign_id', $campaign, 'id', 'SET NULL', 'CASCADE');

        $this->addColumn('{{%cms_comm_account}}', 'mailing_settings', $this->text()->null()
            ->comment('JSON: скорость рассылок с ящика — писем в день, паузы, рабочие часы'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_account}}', 'mailing_settings');

        $message = '{{%cms_comm_message}}';
        $this->dropForeignKey('cms_comm_message__cms_comm_campaign_id', $message);
        $this->dropColumn($message, 'cms_comm_campaign_id');

        $this->dropTable('{{%cms_comm_campaign_recipient}}');
        $this->dropTable('{{%cms_comm_campaign}}');
    }
}
