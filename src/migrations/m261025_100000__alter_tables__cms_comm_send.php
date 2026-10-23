<?php

use yii\db\Migration;

/**
 * "Отправить клиенту": an SMS keeps the record of its provider
 * (cms_sms_message.id), a draft keeps the things put in with "Прикрепить
 * из…".
 */
class m261025_100000__alter_tables__cms_comm_send extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_comm_message}}', 'provider_ref', $this->string(190)->null()
            ->comment('Запись у провайдера: cms_sms_message.id для СМС')->after('external_id'));
        $this->addColumn('{{%cms_comm_draft}}', 'sendables', $this->text()->null()
            ->comment('JSON: прикреплённые документы других пакетов, type:id')->after('file_ids'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_draft}}', 'sendables');
        $this->dropColumn('{{%cms_comm_message}}', 'provider_ref');
    }
}
