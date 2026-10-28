<?php

use yii\db\Migration;

/**
 * Mailings in the new CRM model (П5): the cold base is an audience, not
 * deals.
 *
 * - cms_comm_audience: the mailing state of a CRM record (company, client;
 *   a lead for old members): where a card made by a mailing import came from
 *   («Рассылка: база …») and whether it is cold or warm (followed a link of a
 *   letter, opened a presentation). One row per record and site; consent and
 *   opt-out stay with the contact (cms_comm_contact_topic).
 * - cms_comm_import.mode: audience — rows become companies and contacts of a
 *   list, without deals; leads — real requests (the old behaviour, existing
 *   imports keep it). excluded_count: rows left out by the rules (a current
 *   client, a recent refusal, an opt-out, a client of another manager).
 * - cms_comm_import_row.record_type / record_id: the CRM record the row became
 *   or matched in an audience import.
 */
class m261108_100000__create_table__cms_comm_audience extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $table = '{{%cms_comm_audience}}';
        $this->createTable($table, [
            'id'                 => $this->primaryKey(),
            'created_at'         => $this->integer()->null(),
            'updated_at'         => $this->integer()->null(),
            'cms_site_id'        => $this->integer()->notNull(),
            'record_type'        => $this->string(32)->notNull()->comment('company, client, lead'),
            'record_id'          => $this->integer()->notNull(),
            'source_name'        => $this->string(255)->null()->comment('Источник карточки: «Рассылка: база …»'),
            'cms_comm_import_id' => $this->integer()->null()->comment('Импорт, создавший карточку'),
            'state'              => $this->string(16)->notNull()->defaultValue('cold')->comment('cold — холодный, warm — тёплый'),
            'warm_at'            => $this->integer()->null()->comment('Когда стал тёплым'),
            'warm_by'            => $this->string(32)->null()->comment('visit — перешёл на сайт, presentation — смотрел Презентацию, offer — открыл КП'),
            'warm_campaign_id'   => $this->integer()->null(),
            'warm_message_id'    => $this->integer()->null(),
        ], $tableOptions);
        $this->createIndex('cms_comm_audience__site_record', $table, ['cms_site_id', 'record_type', 'record_id'], true);
        $this->createIndex('cms_comm_audience__state', $table, ['cms_site_id', 'state']);
        $this->addForeignKey('cms_comm_audience__cms_site_id', $table, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_audience__import_id', $table, 'cms_comm_import_id', '{{%cms_comm_import}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_audience__campaign_id', $table, 'warm_campaign_id', '{{%cms_comm_campaign}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_audience__message_id', $table, 'warm_message_id', '{{%cms_comm_message}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($table, 'Рассылки: источник и состояние записи CRM (холодный, тёплый)');

        $this->addColumn('{{%cms_comm_import}}', 'mode', $this->string(16)->notNull()->defaultValue('leads')
            ->comment('audience — база в список, без сделок; leads — заявки в сделки'));
        $this->addColumn('{{%cms_comm_import}}', 'excluded_count', $this->integer()->notNull()->defaultValue(0)->comment('Исключено правилами'));
        $this->addColumn('{{%cms_comm_import_row}}', 'record_type', $this->string(32)->null()->comment('Запись CRM строки: company, client'));
        $this->addColumn('{{%cms_comm_import_row}}', 'record_id', $this->integer()->null());
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_import_row}}', 'record_id');
        $this->dropColumn('{{%cms_comm_import_row}}', 'record_type');
        $this->dropColumn('{{%cms_comm_import}}', 'excluded_count');
        $this->dropColumn('{{%cms_comm_import}}', 'mode');
        $this->dropTable('{{%cms_comm_audience}}');
    }
}
