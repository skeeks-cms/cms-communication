<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\services\MailDrafts;
use skeeks\cms\communication\services\MailFileCleanup;
use skeeks\cms\communication\services\MailFilePolicy;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Removes CRM copies of letter files: daily by the storage settings of each
 * mailbox (payload auto) or once by the criteria an administrator chose in
 * "Настройки почты" → «Очистка файлов». Letters and the mailbox keep the
 * files; opening a letter fetches them again. The daily run also removes
 * drafts not changed for "Хранить неотправленные черновики, дней" with their
 * files (MailDrafts::cleanupExpired()).
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
            // Drafts nobody has touched for the configured term go with their files.
            $reporter->setStage('cleanup', 'Удаление старых черновиков');
            $drafts = MailDrafts::cleanupExpired($siteId, (int)(MailFilePolicy::siteValues($siteId)['draftKeepDays'] ?? 90),
                static function () use ($reporter) {
                    $reporter->heartbeat();
                    return !$reporter->isCancelled();
                });
            $result['drafts'] = $drafts['removed'];
            $result['freed'] += $drafts['freed'];
        } else {
            $criteria = MailFileCleanup::criteria((array)$context->getPayload());
            $reporter->setStage('cleanup', 'Очистка по выбранным условиям');
            $result = $cleanup->runManual($criteria, $siteId);
            $result['criteria'] = $criteria;
        }

        $reporter->countSuccess($result['removed']);
        $reporter->setResult($result);
        $reporter->setStage('complete', 'Удалено копий файлов: '.$result['removed']
            .(isset($result['drafts']) ? ', старых черновиков: '.$result['drafts'] : '')
            .', освобождено '.\Yii::$app->formatter->asShortSize($result['freed']));
    }
}
