<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\services\MailFileCleanup;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Removes CRM copies of letter files: daily by the storage settings of each
 * mailbox (payload auto) or once by the criteria an administrator chose in
 * "Настройки почты" → «Очистка файлов». Letters and the mailbox keep the
 * files; opening a letter fetches them again.
 */
class MailCleanupFilesJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $siteId = (int)$context->getRun()->cms_site_id;
        $cleanup = new MailFileCleanup();
        $cleanup->onProgress = static function (int $done, int $freed) use ($reporter) {
            $reporter->setStage('cleanup', 'Удалено копий: '.$done.', освобождено '.\Yii::$app->formatter->asShortSize($freed));
            $reporter->heartbeat();
            return !$reporter->isCancelled();
        };
        $reporter->setTotal(null);

        if ($context->get('auto')) {
            $reporter->setStage('cleanup', 'Очистка по настройкам хранения ящиков');
            $result = $cleanup->runAuto($siteId);
        } else {
            $criteria = MailFileCleanup::criteria((array)$context->getPayload());
            $reporter->setStage('cleanup', 'Очистка по выбранным условиям');
            $result = $cleanup->runManual($criteria, $siteId);
            $result['criteria'] = $criteria;
        }

        $reporter->countSuccess($result['removed']);
        $reporter->setResult($result);
        $reporter->setStage('complete', 'Удалено копий файлов: '.$result['removed'].', освобождено '.\Yii::$app->formatter->asShortSize($result['freed']));
    }
}
