<?php

use yii\db\Migration;

/**
 * Connections (logins), mailboxes/channels and employee access to them.
 */
class m261021_100000__create_tables__cms_comm_accounts extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $connection = '{{%cms_comm_connection}}';
        $this->createTable($connection, [
            'id'            => $this->primaryKey(),
            'created_by'    => $this->integer()->null(),
            'updated_by'    => $this->integer()->null(),
            'created_at'    => $this->integer()->null(),
            'updated_at'    => $this->integer()->null(),
            'cms_site_id'   => $this->integer()->notNull(),
            'name'          => $this->string(255)->notNull()->comment('Название подключения'),
            'driver'        => $this->string(64)->notNull()->comment('Драйвер: imap, ...'),
            'driver_config' => $this->text()->null()->comment('Настройки драйвера без секретов (JSON)'),
            'secret'        => $this->text()->null()->comment('Зашифрованные секреты драйвера'),
            'is_active'     => $this->boolean()->notNull()->defaultValue(1),
        ], $tableOptions);
        $this->createIndex('cms_comm_connection__site', $connection, ['cms_site_id', 'is_active']);
        $this->addForeignKey('cms_comm_connection__cms_site_id', $connection, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($connection, 'Подключения коммуникаций: логин почтового сервера, бот, API сервиса');

        $account = '{{%cms_comm_account}}';
        $this->createTable($account, [
            'id'                     => $this->primaryKey(),
            'created_by'             => $this->integer()->null(),
            'updated_by'             => $this->integer()->null(),
            'created_at'             => $this->integer()->null(),
            'updated_at'             => $this->integer()->null(),
            'cms_site_id'            => $this->integer()->notNull(),
            'cms_comm_connection_id' => $this->integer()->null()->comment('Подключение для приёма и отправки'),
            'channel'                => $this->string(32)->notNull()->defaultValue('email')->comment('Канал: email, ...'),
            'address'                => $this->string(255)->notNull()->comment('Адрес ящика или идентификатор канала'),
            'name'                   => $this->string(255)->null()->comment('Имя отправителя'),
            'reply_to'               => $this->string(255)->null()->comment('Куда направлять ответы'),
            'is_expose_linked'       => $this->boolean()->notNull()->defaultValue(1)->comment('Показывать письма через связанные лиды, компании и клиентов'),
            'is_active'              => $this->boolean()->notNull()->defaultValue(1),
        ], $tableOptions);
        $this->createIndex('cms_comm_account__site_address', $account, ['cms_site_id', 'channel', 'address'], true);
        $this->createIndex('cms_comm_account__connection', $account, 'cms_comm_connection_id');
        $this->addForeignKey('cms_comm_account__cms_site_id', $account, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        // A connection in use cannot be deleted: the model refuses, RESTRICT is the safety net.
        $this->addForeignKey('cms_comm_account__connection_id', $account, 'cms_comm_connection_id', $connection, 'id', 'RESTRICT', 'CASCADE');
        $this->addCommentOnTable($account, 'Почтовые ящики и каналы коммуникаций с правами сотрудников');

        $access = '{{%cms_comm_account_access}}';
        $this->createTable($access, [
            'id'                  => $this->primaryKey(),
            'created_by'          => $this->integer()->null(),
            'updated_by'          => $this->integer()->null(),
            'created_at'          => $this->integer()->null(),
            'updated_at'          => $this->integer()->null(),
            'cms_comm_account_id' => $this->integer()->notNull(),
            'cms_user_id'         => $this->integer()->notNull()->comment('Сотрудник'),
            'read_level'          => $this->string(16)->notNull()->defaultValue('linked')->comment('Чтение: none, linked, all'),
            'send_level'          => $this->string(16)->notNull()->defaultValue('none')->comment('Отправка: none, own, any'),
            'can_manage'          => $this->boolean()->notNull()->defaultValue(0)->comment('Управление ящиком'),
            'can_manage_messages' => $this->boolean()->notNull()->defaultValue(0)->comment('Удаление и перемещение писем на сервере'),
            'is_default'          => $this->boolean()->notNull()->defaultValue(0)->comment('Ящик по умолчанию для сотрудника'),
            'is_responsible'      => $this->boolean()->notNull()->defaultValue(0)->comment('Получает служебные уведомления ящика'),
        ], $tableOptions);
        $this->createIndex('cms_comm_account_access__account_user', $access, ['cms_comm_account_id', 'cms_user_id'], true);
        $this->createIndex('cms_comm_account_access__user', $access, ['cms_user_id', 'is_default']);
        $this->addForeignKey('cms_comm_account_access__account_id', $access, 'cms_comm_account_id', $account, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_account_access__cms_user_id', $access, 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($access, 'Права сотрудников на ящики и каналы коммуникаций');

        foreach ([$connection => 'cms_comm_connection', $account => 'cms_comm_account', $access => 'cms_comm_account_access'] as $table => $prefix) {
            foreach (['created_by', 'updated_by'] as $column) {
                $this->addForeignKey($prefix.'__'.$column, $table, $column, '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
            }
        }
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_account_access}}');
        $this->dropTable('{{%cms_comm_account}}');
        $this->dropTable('{{%cms_comm_connection}}');
    }
}
