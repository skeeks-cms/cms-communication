<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\models\CmsCommImport;
use skeeks\cms\communication\services\LeadImport;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * A large import of mailing recipients into leads (services\LeadImport).
 * Rows already done are skipped, so a repeated run goes on where it stopped;
 * a cancelled run keeps the rows it has done.
 */
class LeadImportJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $import = CmsCommImport::findOne((int)$context->get('importId'));
        if (!$import || $import->status === CmsCommImport::STATUS_DONE) {
            return;
        }
        LeadImport::run($import, $reporter);
    }
}
