<?php

use yii\db\Migration;

/**
 * Mailings, stage 4C: delivery events of letters reported by a mailing
 * service (Unisender Go and the like): delivered, delivery deferred, not
 * delivered, complaint, opt-out through the service. One row per event of
 * the service (unique provider + event key: a repeated webhook writes
 * nothing twice). The letter keeps its latest delivery state for lists and
 * cards; a campaign recipient — when its letter was delivered or bounced.
 */
class m261105_100000__create_table__cms_comm_message_event extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $event = '{{%cms_comm_message_event}}';
        $this->createTable($event, [
            'id'                     => $this->primaryKey(),
            'created_at'             => $this->integer()->null(),
            'cms_site_id'            => $this->integer()->notNull(),
            'cms_comm_message_id'    => $this->integer()->null()->comment('Письмо; пусто — письмо не найдено'),
            'cms_comm_connection_id' => $this->integer()->null()->comment('Подключение сервиса, от которого событие'),
            'provider'               => $this->string(32)->notNull()->comment('Драйвер сервиса: unisender'),
            'event_key'              => $this->string(64)->notNull()->comment('Ключ события у сервиса: повтор вебхука не пишется дважды'),
            'event'                  => $this->string(32)->notNull()->comment('delivered, deferred, bounced, complained, unsubscribed, opened, clicked, sent, other'),
            'event_at'               => $this->integer()->null(),
            'address'                => $this->string(255)->null(),
            'detail'                 => $this->string(1000)->null()->comment('Причина недоставки, ответ сервера получателя'),
            'data'                   => $this->text()->null()->comment('JSON: событие как его прислал сервис'),
        ], $tableOptions);
        $this->createIndex('cms_comm_message_event__key', $event, ['provider', 'event_key'], true);
        $this->createIndex('cms_comm_message_event__message', $event, ['cms_comm_message_id', 'event_at']);
        $this->createIndex('cms_comm_message_event__site_at', $event, ['cms_site_id', 'event_at']);
        $this->addForeignKey('cms_comm_message_event__message_id', $event, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_message_event__connection_id', $event, 'cms_comm_connection_id', '{{%cms_comm_connection}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_message_event__site_id', $event, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($event, 'События доставки писем от сервисов рассылок');

        $this->addColumn('{{%cms_comm_message}}', 'delivery_status', $this->string(16)->null()
            ->comment('Доставка по событиям сервиса: delivered, deferred, bounced, complained'));
        $this->addColumn('{{%cms_comm_message}}', 'delivery_at', $this->integer()->null());

        $this->addColumn('{{%cms_comm_campaign_recipient}}', 'delivered_at', $this->integer()->null()->comment('Сервис подтвердил доставку'));
        $this->addColumn('{{%cms_comm_campaign_recipient}}', 'bounced_at', $this->integer()->null()->comment('Сервис сообщил, что письмо не доставлено'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_campaign_recipient}}', 'bounced_at');
        $this->dropColumn('{{%cms_comm_campaign_recipient}}', 'delivered_at');
        $this->dropColumn('{{%cms_comm_message}}', 'delivery_at');
        $this->dropColumn('{{%cms_comm_message}}', 'delivery_status');
        $this->dropTable('{{%cms_comm_message_event}}');
    }
}
