<?php

use yii\db\Migration;

/**
 * Mailings, stage 4D step 1: lists of recipients. A member of a list is a
 * CRM record (a lead, a company, a client), not an address: the channel
 * picks the contact when a letter leaves. A member keeps who, when and how
 * added it and is marked "removed" instead of being deleted. Campaigns get
 * the rules that protect every mailing: one letter per company and
 * "не мешать менеджеру" (no letter to whom we talked to lately); an import
 * may fill a list at once. The recipient table gets an index for the
 * frequency of advertising letters per contact.
 */
class m261031_120000__create_tables__cms_comm_list extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $list = '{{%cms_comm_list}}';
        $this->createTable($list, [
            'id'          => $this->primaryKey(),
            'created_by'  => $this->integer()->null(),
            'updated_by'  => $this->integer()->null(),
            'created_at'  => $this->integer()->null(),
            'updated_at'  => $this->integer()->null(),
            'cms_site_id' => $this->integer()->notNull(),
            'name'        => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_list__site', $list, ['cms_site_id']);
        $this->addForeignKey('cms_comm_list__cms_site_id', $list, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_list__created_by', $list, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_list__updated_by', $list, 'updated_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($list, 'Списки получателей рассылок');

        $member = '{{%cms_comm_list_member}}';
        $this->createTable($member, [
            'id'               => $this->primaryKey(),
            'created_at'       => $this->integer()->null(),
            'updated_at'       => $this->integer()->null(),
            'cms_comm_list_id' => $this->integer()->notNull(),
            'record_type'      => $this->string(32)->notNull()->comment('lead, company, client'),
            'record_id'        => $this->integer()->notNull(),
            'added_by'         => $this->integer()->null()->comment('Кто добавил: сотрудник (или ИИ-помощник)'),
            'added_via'        => $this->string(32)->notNull()->comment('manual — вручную, section — раздел CRM, import — импорт, mcp — ИИ через MCP'),
            'added_ref'        => $this->string(190)->null()->comment('Откуда: раздел (companies, leads, clients), номер импорта, клиент MCP'),
            'removed_at'       => $this->integer()->null()->comment('Убран из списка'),
            'removed_by'       => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_list_member__list_record', $member, ['cms_comm_list_id', 'record_type', 'record_id'], true);
        $this->createIndex('cms_comm_list_member__record', $member, ['record_type', 'record_id']);
        $this->addForeignKey('cms_comm_list_member__list_id', $member, 'cms_comm_list_id', $list, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_list_member__added_by', $member, 'added_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_list_member__removed_by', $member, 'removed_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($member, 'Участники списков рассылок: записи CRM');

        $campaign = '{{%cms_comm_campaign}}';
        $this->addColumn($campaign, 'one_per_company', $this->boolean()->notNull()->defaultValue(0)
            ->comment('Одна компания — одно письмо: компании и её людям пишем одному контакту')->after('task_on_reply'));
        $this->addColumn($campaign, 'quiet_days', $this->integer()->null()
            ->comment('Не мешать менеджеру: не писать тем, с кем общались за столько дней; пусто или 0 — выключено')->after('one_per_company'));

        $this->addColumn('{{%cms_comm_import}}', 'cms_comm_list_id', $this->integer()->null()
            ->comment('Список рассылки, в который импорт добавляет лиды')->after('consent_basis'));
        $this->addForeignKey('cms_comm_import__cms_comm_list_id', '{{%cms_comm_import}}', 'cms_comm_list_id', $list, 'id', 'SET NULL', 'CASCADE');

        $this->createIndex('cms_comm_campaign_recipient__address_sent', '{{%cms_comm_campaign_recipient}}', ['address', 'sent_at']);
    }

    public function safeDown()
    {
        $this->dropIndex('cms_comm_campaign_recipient__address_sent', '{{%cms_comm_campaign_recipient}}');

        $this->dropForeignKey('cms_comm_import__cms_comm_list_id', '{{%cms_comm_import}}');
        $this->dropColumn('{{%cms_comm_import}}', 'cms_comm_list_id');

        $this->dropColumn('{{%cms_comm_campaign}}', 'quiet_days');
        $this->dropColumn('{{%cms_comm_campaign}}', 'one_per_company');

        $this->dropTable('{{%cms_comm_list_member}}');
        $this->dropTable('{{%cms_comm_list}}');
    }
}
