<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * The scheduled safety net of campaigns: every running campaign gets its
 * channel started again (a mailbox job that is waiting already makes the
 * push a no-op), a running campaign with nothing left becomes "Завершена".
 */
class CampaignTickJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $started = 0;
        foreach (CmsCommCampaign::find()->andWhere(['status' => CmsCommCampaign::STATUS_RUNNING])->each(50) as $campaign) {
            if (Campaigns::finishIfDone($campaign)) {
                continue;
            }
            try {
                Campaigns::channel($campaign)->start($campaign);
                $started++;
            } catch (\Throwable $e) {
                $reporter->warning('Кампания №'.(int)$campaign->id.': '.mb_substr($e->getMessage(), 0, 300));
            }
        }
        $reporter->setResult(['campaigns' => $started]);
    }
}
