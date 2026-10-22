<?php

use yii\db\Migration;

/**
 * Conversations, messages, their copies in mailboxes, links to CRM entities
 * and the receive cursor of every connection folder.
 */
class m261021_120000__create_tables__cms_comm_messages extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $state = '{{%cms_comm_sync_state}}';
        $this->createTable($state, [
            'id'                     => $this->primaryKey(),
            'created_at'             => $this->integer()->null(),
            'updated_at'             => $this->integer()->null(),
            'cms_comm_connection_id' => $this->integer()->notNull(),
            'folder'                 => $this->string(190)->notNull()->comment('Папка на сервере'),
            'folder_role'            => $this->string(16)->notNull()->comment('inbox или sent'),
            'uidvalidity'            => $this->bigInteger()->null(),
            'last_uid'               => $this->bigInteger()->notNull()->defaultValue(0)->comment('Последний принятый UID'),
            'last_success_at'        => $this->integer()->null(),
            'last_error'             => $this->text()->null(),
            'last_error_at'          => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_sync_state__connection_folder', $state, ['cms_comm_connection_id', 'folder'], true);
        $this->addForeignKey('cms_comm_sync_state__connection_id', $state, 'cms_comm_connection_id', '{{%cms_comm_connection}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($state, 'Курсор приёма писем по папкам подключения');

        $conversation = '{{%cms_comm_conversation}}';
        $this->createTable($conversation, [
            'id'               => $this->primaryKey(),
            'created_at'       => $this->integer()->null(),
            'updated_at'       => $this->integer()->null(),
            'cms_site_id'      => $this->integer()->notNull(),
            'channel'          => $this->string(32)->notNull()->defaultValue('email'),
            'thread_key'       => $this->string(190)->notNull()->comment('Ключ цепочки: Message-ID первого письма'),
            'subject'          => $this->string(500)->null(),
            'last_message_at'  => $this->integer()->null(),
            'assigned_user_id' => $this->integer()->null()->comment('Ответственный за переписку'),
        ], $tableOptions);
        $this->createIndex('cms_comm_conversation__site_thread', $conversation, ['cms_site_id', 'channel', 'thread_key'], true);
        $this->createIndex('cms_comm_conversation__last', $conversation, ['cms_site_id', 'last_message_at']);
        $this->addForeignKey('cms_comm_conversation__cms_site_id', $conversation, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_conversation__assigned_user_id', $conversation, 'assigned_user_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($conversation, 'Переписки: цепочки писем, диалоги');

        $message = '{{%cms_comm_message}}';
        $this->createTable($message, [
            'id'                       => $this->primaryKey(),
            'created_at'               => $this->integer()->null(),
            'updated_at'               => $this->integer()->null(),
            'cms_site_id'              => $this->integer()->notNull(),
            'cms_comm_conversation_id' => $this->integer()->notNull(),
            'cms_comm_account_id'      => $this->integer()->null()->comment('Ящик отправителя для исходящих'),
            'channel'                  => $this->string(32)->notNull()->defaultValue('email'),
            'direction'                => $this->string(8)->notNull()->comment('in или out'),
            'status'                   => $this->string(16)->notNull()->comment('received, queued, sending, sent, failed, unknown'),
            'external_id'              => $this->string(190)->notNull()->comment('Message-ID'),
            'in_reply_to'              => $this->string(190)->null(),
            'references_list'          => $this->text()->null(),
            'from_address'             => $this->string(255)->null(),
            'from_name'                => $this->string(255)->null(),
            'to_list'                  => $this->text()->null()->comment('JSON: адрес => имя'),
            'cc_list'                  => $this->text()->null()->comment('JSON: адрес => имя'),
            'subject'                  => $this->string(500)->null(),
            'body_text'                => $this->getDb()->getSchema()->createColumnSchemaBuilder('mediumtext')->null(),
            'body_html'                => $this->getDb()->getSchema()->createColumnSchemaBuilder('mediumtext')->null(),
            'attachments'              => $this->text()->null()->comment('JSON: имена и размеры вложений'),
            'is_internal'              => $this->boolean()->notNull()->defaultValue(0)->comment('Все участники — наши адреса'),
            'is_auto'                  => $this->boolean()->notNull()->defaultValue(0)->comment('Автоматическое письмо: автоответ, рассылка'),
            'message_at'               => $this->integer()->null()->comment('Дата письма'),
            'sent_by_user_id'          => $this->integer()->null()->comment('Кто отправил из CRM'),
            'error'                    => $this->text()->null(),
        ], $tableOptions);
        // One letter is one message even when it lies in several mailboxes.
        $this->createIndex('cms_comm_message__site_external', $message, ['cms_site_id', 'channel', 'external_id'], true);
        $this->createIndex('cms_comm_message__conversation', $message, ['cms_comm_conversation_id', 'message_at']);
        $this->createIndex('cms_comm_message__site_date', $message, ['cms_site_id', 'message_at']);
        $this->createIndex('cms_comm_message__in_reply_to', $message, ['cms_site_id', 'in_reply_to']);
        $this->addForeignKey('cms_comm_message__cms_site_id', $message, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message__conversation_id', $message, 'cms_comm_conversation_id', $conversation, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message__account_id', $message, 'cms_comm_account_id', '{{%cms_comm_account}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_message__sent_by_user_id', $message, 'sent_by_user_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($message, 'Письма и сообщения');

        $copy = '{{%cms_comm_message_mailbox}}';
        $this->createTable($copy, [
            'id'                     => $this->primaryKey(),
            'created_at'             => $this->integer()->null(),
            'cms_comm_message_id'    => $this->integer()->notNull(),
            'cms_comm_account_id'    => $this->integer()->notNull(),
            'cms_comm_connection_id' => $this->integer()->null(),
            'folder'                 => $this->string(190)->null(),
            'uidvalidity'            => $this->bigInteger()->null(),
            'uid'                    => $this->bigInteger()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_message_mailbox__message_account', $copy, ['cms_comm_message_id', 'cms_comm_account_id'], true);
        $this->createIndex('cms_comm_message_mailbox__account', $copy, ['cms_comm_account_id', 'cms_comm_message_id']);
        $this->addForeignKey('cms_comm_message_mailbox__message_id', $copy, 'cms_comm_message_id', $message, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message_mailbox__account_id', $copy, 'cms_comm_account_id', '{{%cms_comm_account}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message_mailbox__connection_id', $copy, 'cms_comm_connection_id', '{{%cms_comm_connection}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($copy, 'Копии письма в почтовых ящиках');

        $link = '{{%cms_comm_link}}';
        $this->createTable($link, [
            'id'                  => $this->primaryKey(),
            'created_at'          => $this->integer()->null(),
            'created_by'          => $this->integer()->null(),
            'cms_comm_message_id' => $this->integer()->notNull(),
            'model_code'          => $this->string(190)->notNull(),
            'model_id'            => $this->integer()->notNull(),
            'link_type'           => $this->string(16)->notNull()->comment('auto, context, thread, manual'),
            'cms_log_id'          => $this->integer()->null()->comment('Запись в ленте сущности'),
        ], $tableOptions);
        $this->createIndex('cms_comm_link__message_model', $link, ['cms_comm_message_id', 'model_code', 'model_id'], true);
        $this->createIndex('cms_comm_link__model', $link, ['model_code', 'model_id']);
        $this->addForeignKey('cms_comm_link__message_id', $link, 'cms_comm_message_id', $message, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_link__created_by', $link, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_comm_link__cms_log_id', $link, 'cms_log_id', '{{%cms_log}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($link, 'Связи писем с лидами, компаниями, клиентами и другими сущностями');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_link}}');
        $this->dropTable('{{%cms_comm_message_mailbox}}');
        $this->dropTable('{{%cms_comm_message}}');
        $this->dropTable('{{%cms_comm_conversation}}');
        $this->dropTable('{{%cms_comm_sync_state}}');
    }
}
