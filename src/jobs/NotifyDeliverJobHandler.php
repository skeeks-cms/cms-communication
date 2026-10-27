<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\notify\NotifyDeliveries;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Delivers one notification to one channel outside the CRM
 * (notify\NotifyDeliveries::run()); quiet hours wait in the queue.
 */
class NotifyDeliverJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $reporter->setTotal(1);
        [$state, $value] = NotifyDeliveries::run((int)$context->get('deliveryId'));
        if ($state === 'requeue') {
            $reporter->setStage('waiting', 'Тихие часы: отправка позже');
            $context->requestRequeue((int)$value);

            return;
        }
        $reporter->advance();
        $reporter->countSuccess();
        $reporter->setResult(['deliveryId' => (int)$context->get('deliveryId'), 'result' => (string)$value]);
        $reporter->setStage('complete', (string)$value);
    }
}
