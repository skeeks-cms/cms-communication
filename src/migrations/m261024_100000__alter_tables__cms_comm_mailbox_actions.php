<?php

use yii\db\Migration;

/**
 * Actions with letters in the mailbox on the server (move to the trash, the
 * archive, spam, another folder; labels):
 * - a copy of a letter knows the role of its server folder and the move
 *   asked for in the CRM until a job has done it on the server (the screen
 *   shows the letter in its new place at once), the labels waiting to be
 *   written as IMAP keywords and the last server error;
 * - a letter knows whether it is in the trash or spam of all its mailboxes
 *   (hidden from the usual folders);
 * - a folder cursor knows the folder delimiter and UIDNEXT: every folder of
 *   the mailbox is watched to find letters moved in the mail service itself;
 * - CRM labels with a color.
 */
class m261024_100000__alter_tables__cms_comm_mailbox_actions extends Migration
{
    public function safeUp()
    {
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $copy = '{{%cms_comm_message_mailbox}}';
        $this->addColumn($copy, 'folder_role', $this->string(16)->null()->comment('Роль папки на сервере: inbox, sent, trash, junk, archive, drafts, other, gone')->after('uid'));
        $this->addColumn($copy, 'target_role', $this->string(16)->null()->comment('Куда переносится письмо: действие в CRM ещё не выполнено на сервере')->after('folder_role'));
        $this->addColumn($copy, 'target_folder', $this->string(190)->null()->comment('Папка на сервере для переноса в обычную папку')->after('target_role'));
        $this->addColumn($copy, 'target_by', $this->integer()->null()->comment('Кто попросил перенести')->after('target_folder'));
        $this->addColumn($copy, 'labels_dirty', $this->boolean()->notNull()->defaultValue(0)->comment('Метки ещё не записаны на сервер')->after('target_by'));
        $this->addColumn($copy, 'sync_error', $this->string(500)->null()->comment('Ошибка последнего действия на сервере')->after('labels_dirty'));
        $this->addColumn($copy, 'sync_error_at', $this->integer()->null()->after('sync_error'));
        $this->createIndex('cms_comm_message_mailbox__connection_folder_uid', $copy, ['cms_comm_connection_id', 'folder', 'uid']);
        $this->addForeignKey('cms_comm_message_mailbox__target_by', $copy, 'target_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');

        // Copies received so far lie in the folders the receive job reads.
        $this->execute('UPDATE '.$this->db->quoteTableName('{{%cms_comm_message_mailbox}}').' c
            JOIN '.$this->db->quoteTableName('{{%cms_comm_sync_state}}').' s ON s.cms_comm_connection_id = c.cms_comm_connection_id AND s.folder = c.folder
            SET c.folder_role = s.folder_role');

        $message = '{{%cms_comm_message}}';
        $this->addColumn($message, 'box_state', $this->string(16)->null()->comment('trash или junk: письмо в корзине или спаме всех своих ящиков')->after('is_history'));
        $this->createIndex('cms_comm_message__site_box_date', $message, ['cms_site_id', 'box_state', 'message_at']);

        $state = '{{%cms_comm_sync_state}}';
        $this->alterColumn($state, 'folder_role', $this->string(16)->notNull()->comment('inbox и sent — приём писем; trash, junk, archive, drafts, other — наблюдение за переносами'));
        $this->addColumn($state, 'delimiter', $this->string(8)->null()->comment('Разделитель вложенных папок')->after('folder_role'));
        $this->addColumn($state, 'uidnext', $this->bigInteger()->null()->after('uidvalidity'));

        $label = '{{%cms_comm_label}}';
        $this->createTable($label, [
            'id'          => $this->primaryKey(),
            'created_by'  => $this->integer()->null(),
            'updated_by'  => $this->integer()->null(),
            'created_at'  => $this->integer()->null(),
            'updated_at'  => $this->integer()->null(),
            'cms_site_id' => $this->integer()->notNull(),
            'name'        => $this->string(64)->notNull(),
            'color'       => $this->string(16)->notNull()->defaultValue('#64748b'),
            'priority'    => $this->integer()->notNull()->defaultValue(100),
        ], $tableOptions);
        $this->createIndex('cms_comm_label__site_name', $label, ['cms_site_id', 'name'], true);
        $this->addForeignKey('cms_comm_label__cms_site_id', $label, 'cms_site_id', '{{%cms_site}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_label__created_by', $label, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('cms_comm_label__updated_by', $label, 'updated_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($label, 'Метки писем');

        $messageLabel = '{{%cms_comm_message_label}}';
        $this->createTable($messageLabel, [
            'cms_comm_message_id' => $this->integer()->notNull(),
            'cms_comm_label_id'   => $this->integer()->notNull(),
            'created_at'          => $this->integer()->null(),
            'created_by'          => $this->integer()->null(),
        ], $tableOptions);
        $this->addPrimaryKey('cms_comm_message_label__pk', $messageLabel, ['cms_comm_message_id', 'cms_comm_label_id']);
        $this->createIndex('cms_comm_message_label__label', $messageLabel, ['cms_comm_label_id', 'cms_comm_message_id']);
        $this->addForeignKey('cms_comm_message_label__message_id', $messageLabel, 'cms_comm_message_id', '{{%cms_comm_message}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message_label__label_id', $messageLabel, 'cms_comm_label_id', $label, 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('cms_comm_message_label__created_by', $messageLabel, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'CASCADE');
        $this->addCommentOnTable($messageLabel, 'Метки, поставленные письмам');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_comm_message_label}}');
        $this->dropTable('{{%cms_comm_label}}');

        $state = '{{%cms_comm_sync_state}}';
        // Watched folders exist only for this feature; the receive cursors stay.
        $this->delete($state, ['not in', 'folder_role', ['inbox', 'sent']]);
        $this->dropColumn($state, 'uidnext');
        $this->dropColumn($state, 'delimiter');
        $this->alterColumn($state, 'folder_role', $this->string(16)->notNull()->comment('inbox или sent'));

        $message = '{{%cms_comm_message}}';
        $this->dropIndex('cms_comm_message__site_box_date', $message);
        $this->dropColumn($message, 'box_state');

        $copy = '{{%cms_comm_message_mailbox}}';
        $this->dropForeignKey('cms_comm_message_mailbox__target_by', $copy);
        // MySQL dropped the index of the connection foreign key when the wider index came:
        // the key needs its own index back before the wider one goes.
        if ($this->db->driverName === 'mysql') {
            $hasOwn = $this->db->createCommand('SHOW INDEX FROM '.$this->db->quoteTableName($copy).' WHERE Key_name = :name',
                [':name' => 'cms_comm_message_mailbox__connection_id'])->queryOne();
            if (!$hasOwn) {
                $this->createIndex('cms_comm_message_mailbox__connection_id', $copy, ['cms_comm_connection_id']);
            }
        }
        $this->dropIndex('cms_comm_message_mailbox__connection_folder_uid', $copy);
        foreach (['sync_error_at', 'sync_error', 'labels_dirty', 'target_by', 'target_folder', 'target_role', 'folder_role'] as $column) {
            $this->dropColumn($copy, $column);
        }
    }
}
