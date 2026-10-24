<?php

use yii\db\Migration;

/**
 * The purpose of a letter prepared by an AI client: advertising (a mailing
 * topic and the opt-out link), personal work correspondence or a service
 * letter (services\LetterPurpose decides what each needs). A draft keeps it;
 * the API send journal keeps it with the test mode of the send.
 */
class m261102_100000__alter_tables__cms_comm_letter_purpose extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_comm_draft}}', 'purpose', $this->string(32)->null()->comment('Назначение письма ИИ: advertising, personal, service; пусто — по теме рассылки'));
        $this->addColumn('{{%cms_comm_api_send}}', 'purpose', $this->string(32)->null()->comment('Назначение отправленного письма'));
        $this->addColumn('{{%cms_comm_api_send}}', 'test_mode', $this->boolean()->notNull()->defaultValue(0)->comment('Отправка в тестовом режиме разрешённому тестовому получателю'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%cms_comm_api_send}}', 'test_mode');
        $this->dropColumn('{{%cms_comm_api_send}}', 'purpose');
        $this->dropColumn('{{%cms_comm_draft}}', 'purpose');
    }
}
