<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\models\CmsCommListRule;
use skeeks\cms\communication\services\ListRules;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Lists of recipients fill themselves: every active rule not counted for an
 * hour is counted again on behalf of the employee who saved it
 * (ListRules::asUser() gives the console job a user), new records join.
 */
class ListRulesJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $service = new ListRules();
        $rules = CmsCommListRule::find()->andWhere(['is_active' => 1])
            ->andWhere(['or', ['last_run_at' => null], ['<', 'last_run_at', time() - ListRules::JOB_INTERVAL + 60]])
            ->orderBy(['last_run_at' => SORT_ASC, 'id' => SORT_ASC])->all();
        $reporter->setTotal(count($rules));
        $added = 0;
        foreach ($rules as $rule) {
            if ($reporter->isCancelled()) {
                break;
            }
            $added += $service->run($rule);
            if ($rule->last_error) {
                $reporter->warning('Правило №'.(int)$rule->id.': '.mb_substr((string)$rule->last_error, 0, 300));
            }
            $reporter->advance(1);
            $reporter->heartbeat();
        }
        $reporter->setResult(['rules' => count($rules), 'added' => $added]);
    }
}
