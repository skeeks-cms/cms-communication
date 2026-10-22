<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\services\MailFileFetcher;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Fetches files of one letter from its mailbox: images inside the text
 * (kind "images"), one attachment (kind "file", fileId) or all files of a
 * letter received before files were kept (kind "all"). Reads the letter
 * read-only (EXAMINE) by its folder and UID; nothing is changed on the server.
 */
class MailFetchFilesJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $reporter->setStage('fetch', 'Загрузка письма из почтового ящика');
        $result = (new MailFileFetcher())->fetch(
            (int)$context->get('messageId'),
            (string)($context->get('kind') ?: 'all'),
            $context->get('fileId') ? (int)$context->get('fileId') : null
        );
        if ($result['stored']) {
            $reporter->countSuccess($result['stored']);
        }
        if ($result['error']) {
            $reporter->countError();
            $reporter->warning($result['error']);
        }
        $reporter->setResult($result);
        $reporter->setStage('complete', $result['error'] ?: 'Файлы загружены');
    }
}
