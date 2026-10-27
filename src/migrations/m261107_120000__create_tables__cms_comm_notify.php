<?php

use yii\db\Migration;

/**
 * Notifications in channels outside the CRM (step У4a).
 *
 * cms_comm_notify_channel — where a person receives notifications in a
 * channel: the private chat with a bot (MAX, Telegram), later other addresses;
 * its state and the last success or error. The card "Каналы связи" of a person
 * reads it too.
 *
 * cms_comm_notify_delivery — the delivery journal: one row per notification,
 * channel and burst step (one event — one message in a channel), its status,
 * why it was skipped, the chat message it became, the fallback it started.
 */
class m261107_120000__create_tables__cms_comm_notify extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql' ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB' : null;

        $this->createTable('{{%cms_comm_notify_channel}}', [
            'id'                      => $this->primaryKey(),
            'created_at'              => $this->integer()->null(),
            'updated_at'              => $this->integer()->null(),
            'cms_user_id'             => $this->integer()->notNull()->comment('Получатель'),
            'channel'                 => $this->string(32)->notNull()->comment('Канал: max, telegram'),
            'cms_comm_connection_id'  => $this->integer()->null()->comment('Бот'),
            'cms_comm_conversation_id'=> $this->integer()->null()->comment('Личный чат с ботом'),
            'cms_comm_identity_id'    => $this->integer()->null()->comment('Собеседник'),
            'is_active'               => $this->tinyInteger(1)->notNull()->defaultValue(1)->comment('Включён'),
            'last_ok_at'              => $this->integer()->null()->comment('Последняя доставка'),
            'last_error_at'           => $this->integer()->null(),
            'last_error'              => $this->string(1000)->null(),
        ], $options);
        $this->createIndex('cms_comm_notify_channel__user_channel', '{{%cms_comm_notify_channel}}', ['cms_user_id', 'channel'], true);
        $this->addForeignKey('cms_comm_notify_channel__user', '{{%cms_comm_notify_channel}}', 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_notify_channel__connection', '{{%cms_comm_notify_channel}}', 'cms_comm_connection_id', '{{%cms_comm_connection}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_notify_channel__conversation', '{{%cms_comm_notify_channel}}', 'cms_comm_conversation_id', '{{%cms_comm_conversation}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_notify_channel__identity', '{{%cms_comm_notify_channel}}', 'cms_comm_identity_id', '{{%cms_comm_identity}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%cms_comm_notify_delivery}}', [
            'id'                  => $this->primaryKey(),
            'created_at'          => $this->integer()->null(),
            'updated_at'          => $this->integer()->null(),
            'cms_web_notify_id'   => $this->integer()->null()->comment('Уведомление (null — удалено)'),
            'cms_user_id'         => $this->integer()->notNull()->comment('Получатель'),
            'channel'             => $this->string(32)->notNull(),
            'step'                => $this->integer()->notNull()->defaultValue(1)->comment('Событие пачки'),
            'status'              => $this->string(16)->notNull()->comment('queued, postponed, sending, sent, failed, unknown, skipped'),
            'reason'              => $this->string(255)->null()->comment('Почему отложено, пропущено или запасной канал'),
            'title'               => $this->string(255)->null()->comment('Текст уведомления'),
            'scheduled_at'        => $this->integer()->null(),
            'sent_at'             => $this->integer()->null(),
            'error'               => $this->string(1000)->null(),
            'cms_comm_message_id' => $this->integer()->null()->comment('Сообщение чата'),
            'fallback_of_id'      => $this->integer()->null()->comment('Запасной канал для'),
            'skip_if_read'        => $this->tinyInteger(1)->notNull()->defaultValue(1)->comment('Не слать, если прочитано в CRM'),
        ], $options);
        $this->createIndex('cms_comm_notify_delivery__unique', '{{%cms_comm_notify_delivery}}', ['cms_web_notify_id', 'channel', 'step'], true);
        $this->createIndex('cms_comm_notify_delivery__user', '{{%cms_comm_notify_delivery}}', ['cms_user_id', 'id']);
        $this->createIndex('cms_comm_notify_delivery__message', '{{%cms_comm_notify_delivery}}', 'cms_comm_message_id');
        $this->addForeignKey('cms_comm_notify_delivery__notify', '{{%cms_comm_notify_delivery}}', 'cms_web_notify_id', '{{%cms_web_notify}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_notify_delivery__user', '{{%cms_comm_notify_delivery}}', 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_notify_delivery__message', '{{%cms_comm_notify_delivery}}', 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_notify_delivery}}');
        $this->dropTable('{{%cms_comm_notify_channel}}');
    }
}
