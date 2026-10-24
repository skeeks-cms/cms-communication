<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\bot\BotFiles;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Puts files of an incoming chat message into the CMS storage (bot\BotFiles).
 */
class BotFetchFileJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $result = BotFiles::fetch((int)$context->get('messageId'));
        $reporter->countSuccess($result['stored']);
        if ($result['failed']) {
            $reporter->countWarning($result['failed']);
        }
        $reporter->setResult($result);
        $reporter->setStage('complete', "Сохранено файлов: {$result['stored']}, недоступно: {$result['failed']}");
    }
}
