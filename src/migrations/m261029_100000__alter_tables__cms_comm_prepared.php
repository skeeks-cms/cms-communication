<?php

use yii\db\Migration;

/**
 * "Подготовлено ИИ" (AI-2): a draft, a letter and a campaign keep who
 * prepared them — AI through which client (an MCP client), for which CRM
 * user — set by the server from the OAuth token, never from a form or tool
 * arguments. A draft made by AI also keeps the mailing topic it was checked
 * for (the letter goes with the personal opt-out link) and a hash of the
 * prepared text, so the letter knows whether the employee changed it; a
 * campaign recipient keeps the employee who changed a text written by AI.
 */
class m261029_100000__alter_tables__cms_comm_prepared extends Migration
{
    private const TABLES = ['{{%cms_comm_draft}}' => 'cms_comm_draft', '{{%cms_comm_message}}' => 'cms_comm_message', '{{%cms_comm_campaign}}' => 'cms_comm_campaign'];

    public function safeUp()
    {
        foreach (self::TABLES as $table => $name) {
            $this->addColumn($table, 'prepared_by', $this->string(32)->null()->comment('Кто подготовил: ai — ИИ; пусто — сотрудник'));
            $this->addColumn($table, 'prepared_client', $this->string(190)->null()->comment('Через что: mcp:<название OAuth-клиента>'));
            $this->addColumn($table, 'prepared_user_id', $this->integer()->null()->comment('Пользователь CRM, под которым работал ИИ'));
            $this->addColumn($table, 'prepared_at', $this->integer()->null());
            $this->createIndex($name.'__prepared', $table, ['prepared_user_id', 'prepared_at']);
            $this->addForeignKey($name.'__prepared_user_id', $table, 'prepared_user_id', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        }
        $this->addColumn('{{%cms_comm_draft}}', 'prepared_hash', $this->string(64)->null()->comment('Отпечаток темы и текста ИИ: изменил ли их сотрудник'));
        $this->addColumn('{{%cms_comm_draft}}', 'cms_comm_topic_id', $this->integer()->null()->comment('Тема рассылки черновика ИИ: письмо уйдёт с личной ссылкой отписки'));
        $this->addForeignKey('cms_comm_draft__cms_comm_topic_id', '{{%cms_comm_draft}}', 'cms_comm_topic_id', '{{%cms_comm_topic}}', 'id', 'SET NULL', 'CASCADE');
        $this->addColumn('{{%cms_comm_message}}', 'prepared_edited', $this->boolean()->null()->comment('Сотрудник изменил тему или текст ИИ перед отправкой'));
        $this->addColumn('{{%cms_comm_campaign_recipient}}', 'text_edited_by', $this->integer()->null()->comment('Сотрудник, изменивший личный текст ИИ')->after('text_at'));
        $this->addForeignKey('cms_comm_campaign_recipient__text_edited_by', '{{%cms_comm_campaign_recipient}}', 'text_edited_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropForeignKey('cms_comm_campaign_recipient__text_edited_by', '{{%cms_comm_campaign_recipient}}');
        $this->dropColumn('{{%cms_comm_campaign_recipient}}', 'text_edited_by');
        $this->dropColumn('{{%cms_comm_message}}', 'prepared_edited');
        $this->dropForeignKey('cms_comm_draft__cms_comm_topic_id', '{{%cms_comm_draft}}');
        $this->dropColumn('{{%cms_comm_draft}}', 'cms_comm_topic_id');
        $this->dropColumn('{{%cms_comm_draft}}', 'prepared_hash');
        foreach (self::TABLES as $table => $name) {
            $this->dropForeignKey($name.'__prepared_user_id', $table);
            $this->dropIndex($name.'__prepared', $table);
            $this->dropColumn($table, 'prepared_at');
            $this->dropColumn($table, 'prepared_user_id');
            $this->dropColumn($table, 'prepared_client');
            $this->dropColumn($table, 'prepared_by');
        }
    }
}
