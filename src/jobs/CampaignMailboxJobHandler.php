<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\campaign\MailboxCampaignChannel;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Campaign letters of one mailbox (MailboxCampaignChannel): one letter per
 * step at the pace of the mailbox, then the same run waits in the queue a
 * random pause, or until the next working period when the day's limit is
 * reached. Ends when the running campaigns have nothing left for the
 * mailbox; a paused campaign is skipped, its resume pushes the job again.
 */
class CampaignMailboxJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $account = CmsCommAccount::findOne((int)$context->get('accountId'));
        if (!$account) {
            return;
        }
        $channel = \Yii::$app->communication->getCampaignChannel(MailboxCampaignChannel::ID);
        if (!$channel instanceof MailboxCampaignChannel) {
            return;
        }
        $wait = $channel->sendNext($account);
        if ($wait === null) {
            $reporter->setStage('complete', 'Писем кампаний для ящика больше нет');
            return;
        }
        $reporter->setStage('wait', 'Следующее письмо через '.$wait.' с');
        // Outside any transaction: the step is committed already.
        $context->requestRequeue($wait);
    }
}
