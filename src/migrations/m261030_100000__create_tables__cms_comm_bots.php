<?php

use yii\db\Migration;

/**
 * Bots (stage 5.1): messenger identities, participants of group chats, the
 * journal of raw bot updates (a webhook stores an update, a job applies it),
 * links of a whole chat to CRM records, one-time link tokens (`?start=`),
 * the state of a dialog (waiting for an answer, closed) and personal read
 * marks of chats.
 * A chat is an ordinary conversation of channel telegram/max with its bot
 * connection and chat id; a chat message is an ordinary message with its
 * author identity. A file of a chat message keeps the messenger reference it
 * is downloaded by.
 */
class m261030_100000__create_tables__cms_comm_bots extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql' ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB' : null;

        $this->createTable('{{%cms_comm_identity}}', [
            'id'                => $this->primaryKey(),
            'created_at'        => $this->integer()->null(),
            'updated_at'        => $this->integer()->null(),
            'cms_site_id'       => $this->integer()->notNull(),
            'channel'           => $this->string(32)->notNull()->comment('telegram, max'),
            'external_id'       => $this->string(64)->notNull()->comment('Id пользователя в мессенджере'),
            'name'              => $this->string(255)->null(),
            'username'          => $this->string(255)->null(),
            'is_bot'            => $this->boolean()->notNull()->defaultValue(0),
            'phone'             => $this->string(32)->null()->comment('Подтверждённый телефон: свой контакт собеседника'),
            'phone_verified_at' => $this->integer()->null(),
            'cms_user_id'       => $this->integer()->null()->comment('Клиент или сотрудник'),
            'link_method'       => $this->string(16)->null()->comment('start, contact, sms, manual'),
            'linked_at'         => $this->integer()->null(),
            'linked_by'         => $this->integer()->null(),
            'is_blocked'        => $this->boolean()->notNull()->defaultValue(0)->comment('Собеседник остановил или заблокировал бота'),
            'last_seen_at'      => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_identity__site_channel_external', '{{%cms_comm_identity}}', ['cms_site_id', 'channel', 'external_id'], true);
        $this->createIndex('cms_comm_identity__cms_user_id', '{{%cms_comm_identity}}', 'cms_user_id');
        $this->addForeignKey('cms_comm_identity__cms_site_id', '{{%cms_comm_identity}}', 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_identity__cms_user_id', '{{%cms_comm_identity}}', 'cms_user_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_identity__linked_by', '{{%cms_comm_identity}}', 'linked_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');

        $this->addColumn('{{%cms_comm_conversation}}', 'cms_comm_connection_id', $this->integer()->null()->comment('Бот чата'));
        $this->addColumn('{{%cms_comm_conversation}}', 'chat_type', $this->string(16)->null()->comment('private, group, channel'));
        $this->addColumn('{{%cms_comm_conversation}}', 'external_chat_id', $this->string(64)->null()->comment('Id чата в мессенджере'));
        $this->addColumn('{{%cms_comm_conversation}}', 'title', $this->string(255)->null()->comment('Название группы или имя собеседника'));
        $this->addColumn('{{%cms_comm_conversation}}', 'bot_status', $this->string(16)->null()->comment('member, admin, left'));
        $this->addColumn('{{%cms_comm_conversation}}', 'bot_permissions', $this->text()->null()->comment('JSON: права бота в чате'));
        // Dialogs of chats: "ждёт ответа" since the first unanswered message of the client, "Закрыть".
        $this->addColumn('{{%cms_comm_conversation}}', 'waiting_since', $this->integer()->null()->comment('Клиент ждёт ответа с этого времени'));
        $this->addColumn('{{%cms_comm_conversation}}', 'closed_at', $this->integer()->null()->comment('Диалог закрыт; новое сообщение клиента открывает его'));
        $this->addColumn('{{%cms_comm_conversation}}', 'closed_by', $this->integer()->null());
        $this->createIndex('cms_comm_conversation__connection_chat', '{{%cms_comm_conversation}}', ['cms_comm_connection_id', 'external_chat_id']);
        $this->addForeignKey('cms_comm_conversation__closed_by', '{{%cms_comm_conversation}}', 'closed_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');

        // Personal read mark of a chat: messages up to this one are read by the employee.
        $this->createTable('{{%cms_comm_conversation_read}}', [
            'cms_comm_conversation_id' => $this->integer()->notNull(),
            'cms_user_id'              => $this->integer()->notNull(),
            'last_message_id'          => $this->integer()->notNull(),
            'read_at'                  => $this->integer()->null(),
        ], $tableOptions);
        $this->addPrimaryKey('cms_comm_conversation_read__pk', '{{%cms_comm_conversation_read}}', ['cms_comm_conversation_id', 'cms_user_id']);
        $this->addForeignKey('cms_comm_conversation_read__conversation_id', '{{%cms_comm_conversation_read}}', 'cms_comm_conversation_id', '{{%cms_comm_conversation}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_conversation_read__cms_user_id', '{{%cms_comm_conversation_read}}', 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_conversation__cms_comm_connection_id', '{{%cms_comm_conversation}}', 'cms_comm_connection_id', '{{%cms_comm_connection}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%cms_comm_participant}}', [
            'id'                       => $this->primaryKey(),
            'created_at'               => $this->integer()->null(),
            'updated_at'               => $this->integer()->null(),
            'cms_comm_conversation_id' => $this->integer()->notNull(),
            'cms_comm_identity_id'     => $this->integer()->notNull(),
            'role'                     => $this->string(16)->notNull()->defaultValue('member')->comment('member, admin, creator'),
            'joined_at'                => $this->integer()->null(),
            'left_at'                  => $this->integer()->null(),
            'last_message_at'          => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_participant__conversation_identity', '{{%cms_comm_participant}}', ['cms_comm_conversation_id', 'cms_comm_identity_id'], true);
        $this->createIndex('cms_comm_participant__identity', '{{%cms_comm_participant}}', 'cms_comm_identity_id');
        $this->addForeignKey('cms_comm_participant__conversation_id', '{{%cms_comm_participant}}', 'cms_comm_conversation_id', '{{%cms_comm_conversation}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_participant__identity_id', '{{%cms_comm_participant}}', 'cms_comm_identity_id', '{{%cms_comm_identity}}', 'id', 'CASCADE', 'CASCADE');

        $this->addColumn('{{%cms_comm_message}}', 'cms_comm_identity_id', $this->integer()->null()->comment('Автор сообщения чата'));
        $this->addColumn('{{%cms_comm_message}}', 'reply_to_message_id', $this->integer()->null()->comment('Ответ на сообщение чата'));
        $this->addColumn('{{%cms_comm_message}}', 'edited_at', $this->integer()->null()->comment('Изменено в мессенджере'));
        $this->addColumn('{{%cms_comm_message}}', 'removed_at', $this->integer()->null()->comment('Удалено в мессенджере'));
        $this->createIndex('cms_comm_message__identity', '{{%cms_comm_message}}', 'cms_comm_identity_id');
        $this->addForeignKey('cms_comm_message__cms_comm_identity_id', '{{%cms_comm_message}}', 'cms_comm_identity_id', '{{%cms_comm_identity}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_message__reply_to_message_id', '{{%cms_comm_message}}', 'reply_to_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');

        $this->addColumn('{{%cms_comm_message_file}}', 'external_ref', $this->string(1000)->null()->comment('Ссылка или ключ файла в мессенджере'));

        $this->createTable('{{%cms_comm_bot_update}}', [
            'id'                     => $this->primaryKey(),
            'created_at'             => $this->integer()->null(),
            'cms_comm_connection_id' => $this->integer()->notNull(),
            'update_key'             => $this->string(190)->notNull()->comment('Ключ события: повтор доставки не записывается'),
            'update_type'            => $this->string(64)->null(),
            'payload'                => $this->getDb()->getSchema()->createColumnSchemaBuilder('mediumtext')->notNull(),
            'status'                 => $this->string(16)->notNull()->defaultValue('new')->comment('new, applied, skipped, failed'),
            'attempts'               => $this->integer()->notNull()->defaultValue(0),
            'error'                  => $this->text()->null(),
            'applied_at'             => $this->integer()->null(),
            'cms_comm_message_id'    => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_bot_update__connection_key', '{{%cms_comm_bot_update}}', ['cms_comm_connection_id', 'update_key'], true);
        $this->createIndex('cms_comm_bot_update__connection_status', '{{%cms_comm_bot_update}}', ['cms_comm_connection_id', 'status', 'id']);
        $this->createIndex('cms_comm_bot_update__created_at', '{{%cms_comm_bot_update}}', 'created_at');
        $this->addForeignKey('cms_comm_bot_update__connection_id', '{{%cms_comm_bot_update}}', 'cms_comm_connection_id', '{{%cms_comm_connection}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_bot_update__message_id', '{{%cms_comm_bot_update}}', 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%cms_comm_conversation_link}}', [
            'id'                       => $this->primaryKey(),
            'created_at'               => $this->integer()->null(),
            'created_by'               => $this->integer()->null(),
            'cms_comm_conversation_id' => $this->integer()->notNull(),
            'model_code'               => $this->string(190)->notNull(),
            'model_id'                 => $this->integer()->notNull(),
        ], $tableOptions);
        $this->createIndex('cms_comm_conversation_link__unique', '{{%cms_comm_conversation_link}}', ['cms_comm_conversation_id', 'model_code', 'model_id'], true);
        $this->createIndex('cms_comm_conversation_link__model', '{{%cms_comm_conversation_link}}', ['model_code', 'model_id']);
        $this->addForeignKey('cms_comm_conversation_link__conversation_id', '{{%cms_comm_conversation_link}}', 'cms_comm_conversation_id', '{{%cms_comm_conversation}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_conversation_link__created_by', '{{%cms_comm_conversation_link}}', 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%cms_comm_link_token}}', [
            'id'                     => $this->primaryKey(),
            'created_at'             => $this->integer()->null(),
            'created_by'             => $this->integer()->null(),
            'cms_site_id'            => $this->integer()->notNull(),
            'cms_comm_connection_id' => $this->integer()->null()->comment('Бот, в который ведёт ссылка'),
            'token'                  => $this->string(64)->notNull(),
            'purpose'                => $this->string(32)->notNull()->comment('Что делает ключ, например привязка собеседника к записи'),
            'model_code'             => $this->string(190)->null(),
            'model_id'               => $this->integer()->null(),
            'expires_at'             => $this->integer()->notNull(),
            'used_at'                => $this->integer()->null(),
            'used_identity_id'       => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_link_token__token', '{{%cms_comm_link_token}}', 'token', true);
        $this->createIndex('cms_comm_link_token__model', '{{%cms_comm_link_token}}', ['model_code', 'model_id']);
        $this->addForeignKey('cms_comm_link_token__cms_site_id', '{{%cms_comm_link_token}}', 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_link_token__connection_id', '{{%cms_comm_link_token}}', 'cms_comm_connection_id', '{{%cms_comm_connection}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_link_token__created_by', '{{%cms_comm_link_token}}', 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_link_token__used_identity_id', '{{%cms_comm_link_token}}', 'used_identity_id', '{{%cms_comm_identity}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_link_token}}');
        $this->dropTable('{{%cms_comm_conversation_link}}');
        $this->dropTable('{{%cms_comm_bot_update}}');
        $this->dropColumn('{{%cms_comm_message_file}}', 'external_ref');

        $this->dropForeignKey('cms_comm_message__reply_to_message_id', '{{%cms_comm_message}}');
        $this->dropForeignKey('cms_comm_message__cms_comm_identity_id', '{{%cms_comm_message}}');
        $this->dropIndex('cms_comm_message__identity', '{{%cms_comm_message}}');
        $this->dropColumn('{{%cms_comm_message}}', 'removed_at');
        $this->dropColumn('{{%cms_comm_message}}', 'edited_at');
        $this->dropColumn('{{%cms_comm_message}}', 'reply_to_message_id');
        $this->dropColumn('{{%cms_comm_message}}', 'cms_comm_identity_id');

        $this->dropTable('{{%cms_comm_participant}}');

        $this->dropTable('{{%cms_comm_conversation_read}}');
        $this->dropForeignKey('cms_comm_conversation__closed_by', '{{%cms_comm_conversation}}');
        $this->dropForeignKey('cms_comm_conversation__cms_comm_connection_id', '{{%cms_comm_conversation}}');
        $this->dropIndex('cms_comm_conversation__connection_chat', '{{%cms_comm_conversation}}');
        foreach (['closed_by', 'closed_at', 'waiting_since', 'bot_permissions', 'bot_status', 'title', 'external_chat_id', 'chat_type', 'cms_comm_connection_id'] as $column) {
            $this->dropColumn('{{%cms_comm_conversation}}', $column);
        }

        $this->dropTable('{{%cms_comm_identity}}');
    }
}
