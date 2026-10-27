<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobPermanentException;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Delivers one queued message of a bot (bot\BotSend::deliver()). "Too many
 * requests" of the messenger waits in the queue and goes on with the parts
 * not sent yet; an unknown outcome is never repeated.
 */
class BotSendJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $reporter->setTotal(1);
        [$status, $error] = BotSend::deliver((int)$context->get('messageId'));
        // A notification sent to an employee's chat: its journal gets the outcome (and a refusal its fallback).
        try {
            \skeeks\cms\communication\notify\NotifyDeliveries::onBotResult((int)$context->get('messageId'), $status, $error);
        } catch (\Throwable $e) {
            \Yii::error('Итог уведомления по сообщению #'.(int)$context->get('messageId').' не записан: '.$e->getMessage(), self::class);
        }
        if ($status === null) {
            return;
        }
        if ($status === 'wait') {
            $reporter->setStage('waiting', 'Мессенджер просит подождать: '.(int)$error.' с');
            $context->requestRequeue((int)$error);
        }
        if ($status !== CmsCommMessage::STATUS_SENT) {
            $reporter->countError();
            throw new JobPermanentException('Сообщение не отправлено: '.$error);
        }
        $reporter->countSuccess();
        $reporter->advance();
        $reporter->setResult(['messageId' => (int)$context->get('messageId'), 'status' => $status]);
        $reporter->setStage('complete', 'Сообщение отправлено');
    }
}
