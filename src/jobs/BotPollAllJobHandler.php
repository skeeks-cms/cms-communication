<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\MessengerDriver;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Scheduled entry point of the poll mode: one comm.bot.poll per active bot
 * that polls. Nothing on a site that must not receive.
 */
class BotPollAllJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $started = 0;
        if (Bots::canReceive() && Bots::drivers()) {
            foreach (CmsCommConnection::find()->andWhere(['is_active' => 1, 'driver' => array_keys(Bots::drivers())])->all() as $connection) {
                $config = $connection->getPublicConfig();
                if (($config['mode'] ?? MessengerDriver::MODE_WEBHOOK) !== MessengerDriver::MODE_POLL) {
                    continue;
                }
                if (\Yii::$app->jobs->pushChild($context->getRun(), 'comm.bot.poll', ['connectionId' => (int)$connection->id], ['siteId' => (int)$connection->cms_site_id])) {
                    $started++;
                }
            }
        }
        $reporter->setResult(['started' => $started]);
        $reporter->setStage('complete', "Запущен опрос ботов: {$started}");
    }
}
