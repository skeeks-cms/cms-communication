<?php

use yii\db\Migration;

/**
 * A personal letter of a campaign recipient: its own subject and text
 * instead of the campaign template, and who prepared it — an employee or AI,
 * through which client (the CRM, an MCP client).
 */
class m261028_110000__alter_table__cms_comm_campaign_recipient_text extends Migration
{
    public function safeUp()
    {
        $table = '{{%cms_comm_campaign_recipient}}';
        $this->addColumn($table, 'subject', $this->string(500)->null()->comment('Личная тема письма; пусто — тема кампании')->after('name'));
        $this->addColumn($table, 'body_html', $this->text()->null()->comment('Личный текст письма; пусто — шаблон кампании')->after('subject'));
        $this->addColumn($table, 'text_by', $this->string(32)->null()->comment('Кто подготовил личный текст: user — сотрудник, ai — ИИ')->after('body_html'));
        $this->addColumn($table, 'text_client', $this->string(190)->null()->comment('Через что: crm, mcp:<клиент>')->after('text_by'));
        $this->addColumn($table, 'text_user_id', $this->integer()->null()->comment('Пользователь CRM, сохранивший текст (сотрудник или ИИ-помощник)')->after('text_client'));
        $this->addColumn($table, 'text_at', $this->integer()->null()->after('text_user_id'));
        $this->addForeignKey('cms_comm_campaign_recipient__text_user_id', $table, 'text_user_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $table = '{{%cms_comm_campaign_recipient}}';
        $this->dropForeignKey('cms_comm_campaign_recipient__text_user_id', $table);
        $this->dropColumn($table, 'text_at');
        $this->dropColumn($table, 'text_user_id');
        $this->dropColumn($table, 'text_client');
        $this->dropColumn($table, 'text_by');
        $this->dropColumn($table, 'body_html');
        $this->dropColumn($table, 'subject');
    }
}
