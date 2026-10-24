<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\mail\MailDriverInterface;
use skeeks\cms\communication\mail\MailingServiceInterface;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Scheduled entry point: starts receiving for every active mail connection
 * that has mailboxes. Each connection is a separate child job with its own
 * resource lock, so a slow server does not delay the others.
 */
class MailPollAllJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $connections = CmsCommConnection::find()
            ->andWhere(['is_active' => 1])
            ->andWhere(['id' => \skeeks\cms\communication\models\CmsCommAccount::find()->select('cms_comm_connection_id')->andWhere(['is_active' => 1])])
            ->all();
        $drivers = CmsCommConnection::component()->drivers;

        $started = 0;
        foreach ($connections as $connection) {
            $class = $drivers[$connection->driver] ?? null;
            $class = is_array($class) ? ($class['class'] ?? null) : $class;
            // A mailing service only sends: there is nothing to receive.
            if (!is_string($class) || !is_subclass_of($class, MailDriverInterface::class) || is_subclass_of($class, MailingServiceInterface::class)) {
                continue;
            }
            if (\Yii::$app->jobs->pushChild($context->getRun(), 'comm.mail.poll', ['connectionId' => (int)$connection->id], ['siteId' => (int)$connection->cms_site_id])) {
                $started++;
            }
        }
        $reporter->setResult(['started' => $started]);
        $reporter->setStage('complete', "Запущен приём для подключений: {$started}");
    }
}
