<?php

use yii\db\Migration;

/**
 * Signatures of employees (one per employee and mailbox) and quick reply
 * templates: personal (cms_user_id) or shared by a mailbox
 * (cms_comm_account_id without a user).
 */
class m261022_130000__create_tables__cms_comm_signature_template extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $table = '{{%cms_comm_signature}}';
        $this->createTable($table, [
            'id'                  => $this->primaryKey(),
            'created_at'          => $this->integer()->null(),
            'updated_at'          => $this->integer()->null(),
            'cms_comm_account_id' => $this->integer()->notNull(),
            'cms_user_id'         => $this->integer()->notNull(),
            'body_html'           => $this->text()->notNull()->comment('Текст подписи (HTML)'),
        ], $tableOptions);
        $this->createIndex('cms_comm_signature__account_user', $table, ['cms_comm_account_id', 'cms_user_id'], true);
        $this->createIndex('cms_comm_signature__user', $table, ['cms_user_id']);
        $this->addForeignKey('cms_comm_signature__account_id', $table, 'cms_comm_account_id', '{{%cms_comm_account}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_signature__cms_user_id', $table, 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($table, 'Подписи сотрудников в письмах по ящикам');

        $table = '{{%cms_comm_template}}';
        $this->createTable($table, [
            'id'                  => $this->primaryKey(),
            'created_by'          => $this->integer()->null(),
            'updated_by'          => $this->integer()->null(),
            'created_at'          => $this->integer()->null(),
            'updated_at'          => $this->integer()->null(),
            'cms_site_id'         => $this->integer()->notNull(),
            'cms_comm_account_id' => $this->integer()->null()->comment('Общий шаблон ящика'),
            'cms_user_id'         => $this->integer()->null()->comment('Личный шаблон сотрудника'),
            'name'                => $this->string(255)->notNull(),
            'body_html'           => $this->text()->notNull()->comment('Текст шаблона (HTML)'),
            'priority'            => $this->integer()->notNull()->defaultValue(100),
        ], $tableOptions);
        $this->createIndex('cms_comm_template__site_user', $table, ['cms_site_id', 'cms_user_id']);
        $this->createIndex('cms_comm_template__account', $table, ['cms_comm_account_id']);
        $this->addForeignKey('cms_comm_template__cms_site_id', $table, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_template__account_id', $table, 'cms_comm_account_id', '{{%cms_comm_account}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_template__cms_user_id', $table, 'cms_user_id', '{{%cms_user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addCommentOnTable($table, 'Шаблоны быстрых ответов: личные и общие для ящика');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_template}}');
        $this->dropTable('{{%cms_comm_signature}}');
    }
}
