<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\console;

use skeeks\cms\communication\services\AudienceMigration;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * The audience of mailings from the console.
 *
 *   php yii comm-audience/lead-members            # what would be moved, nothing is written
 *   php yii comm-audience/lead-members --apply=1  # move deal members of lists to companies and contacts
 */
class AudienceController extends Controller
{
    /** @var bool write the changes; without it only the plan is printed */
    public $apply = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['apply']);
    }

    /** Members of lists that are deals (record_type lead) → companies and contacts (services\AudienceMigration). */
    public function actionLeadMembers()
    {
        $apply = (bool)$this->apply;
        $rows = AudienceMigration::leadMembers($apply);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['action']] = ($counts[$row['action']] ?? 0) + 1;
            $this->stdout(sprintf("список %d, сделка %d: %s %s — %s\n", $row['list'], $row['lead'], $row['action'], $row['target'], $row['note']));
        }
        $this->stdout(($apply ? 'Перенесено' : 'План (ничего не записано, запустите с --apply=1)').': '.json_encode($counts, JSON_UNESCAPED_UNICODE)."\n");

        return isset($counts['error']) ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
