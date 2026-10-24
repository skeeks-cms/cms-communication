<?php

use yii\db\Migration;

/**
 * Mailings, stage 4D step 2: rules of a list of recipients. A rule is the
 * filter of a CRM section ("Компании": a category, "Нет оплат") saved from
 * that section; the list takes the records the filter finds, now and later
 * (a scheduled job, before every campaign), on behalf of the employee who
 * saved it. A member removed by hand is never added back by a rule.
 */
class m261031_130000__create_table__cms_comm_list_rule extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $rule = '{{%cms_comm_list_rule}}';
        $this->createTable($rule, [
            'id'               => $this->primaryKey(),
            'created_at'       => $this->integer()->null(),
            'updated_at'       => $this->integer()->null(),
            'cms_comm_list_id' => $this->integer()->notNull(),
            'section'          => $this->string(32)->notNull()->comment('Раздел CRM: companies, leads, clients'),
            'filter_values'    => $this->text()->notNull()->comment('JSON: значения фильтров раздела'),
            'title'            => $this->string(1000)->null()->comment('Как правило читается: «Категория: Плитка; Нет оплат»'),
            'user_id'          => $this->integer()->null()->comment('Сотрудник, от имени которого считается фильтр (его видимость записей)'),
            'is_active'        => $this->boolean()->notNull()->defaultValue(1),
            'last_run_at'      => $this->integer()->null(),
            'last_found'       => $this->integer()->null()->comment('Сколько записей нашёл фильтр при последнем пересчёте'),
            'last_added'       => $this->integer()->null()->comment('Сколько добавлено при последнем пересчёте'),
            'added_total'      => $this->integer()->notNull()->defaultValue(0),
            'last_error'       => $this->string(1000)->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_list_rule__list', $rule, ['cms_comm_list_id']);
        $this->createIndex('cms_comm_list_rule__active_run', $rule, ['is_active', 'last_run_at']);
        $this->addForeignKey('cms_comm_list_rule__list_id', $rule, 'cms_comm_list_id', '{{%cms_comm_list}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_list_rule__user_id', $rule, 'user_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($rule, 'Правила пополнения списков рассылок: сохранённые фильтры разделов CRM');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_list_rule}}');
    }
}
