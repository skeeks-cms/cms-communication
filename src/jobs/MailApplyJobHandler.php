<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\mail\MailDriverInterface;
use skeeks\cms\communication\mail\MailManageSessionInterface;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\communication\services\MailboxActions;
use skeeks\cms\communication\services\MailServerSync;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobPermanentException;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Does on the server what employees asked for in the CRM with letters of
 * one connection: moves (trash, archive, spam, a folder, back) and labels
 * (services\MailboxActions records them, services\MailServerSync does them).
 * The job reads the waiting copies when it runs, so one run does every
 * request made before it; overlap "coalesce" keeps one waiting run beside a
 * running one. The receive job does the same as a safety net.
 *
 * A connection whose driver cannot change the mailbox, or whose server does
 * not answer, leaves letters where they are, with the reason.
 */
class MailApplyJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $connection = CmsCommConnection::findOne((int)$context->get('connectionId'));
        if (!$connection) {
            $reporter->setStage('complete', 'Подключение не найдено');
            return;
        }

        $reporter->setStage('connect', 'Подключение к почтовому серверу');
        $driver = $connection->is_active ? $connection->createDriver(true) : null;
        $session = null;
        try {
            if (!$driver instanceof MailDriverInterface) {
                throw new \DomainException($connection->is_active ? 'Драйвер подключения не поддерживает почту.' : 'Подключение к ящику выключено.');
            }
            $session = $driver->openSession();
            if (!$session instanceof MailManageSessionInterface) {
                throw new \DomainException('Драйвер этого ящика не умеет переносить письма на сервере.');
            }
        } catch (\Throwable $e) {
            if ($session) {
                $session->close();
            }
            $this->failAll($connection, $e instanceof \DomainException ? $e->getMessage() : 'Не удалось подключиться к почтовому серверу: '.$e->getMessage());
            throw new JobPermanentException($e->getMessage());
        }

        $reporter->setStage('apply', 'Перенос писем в почтовом ящике');
        try {
            $result = (new MailServerSync())->apply($connection, $session);
        } finally {
            $session->close();
        }

        $reporter->countSuccess($result['moved']);
        if ($result['failed']) {
            $reporter->countError($result['failed']);
        }
        $reporter->setResult(['connectionId' => (int)$connection->id] + $result);
        $reporter->setStage('complete', 'Перенесено писем: '.$result['moved'].($result['failed'] ? ', не перенесено: '.$result['failed'] : ''));
    }

    /** Nothing can be done now: letters stay where they are on the server, with the reason. */
    private function failAll(CmsCommConnection $connection, string $error): void
    {
        $ids = CmsCommMessageMailbox::find()->select('cms_comm_message_id')
            ->andWhere(['cms_comm_connection_id' => (int)$connection->id])->andWhere(['not', ['target_role' => null]])->column();
        CmsCommMessageMailbox::updateAll(
            ['target_role' => null, 'target_folder' => null, 'target_by' => null, 'sync_error' => mb_substr($error, 0, 500), 'sync_error_at' => time()],
            ['and', ['cms_comm_connection_id' => (int)$connection->id], ['not', ['target_role' => null]]]
        );
        MailboxActions::refreshState(array_map('intval', $ids));
    }
}
