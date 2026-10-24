<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\bot\BotIngest;
use skeeks\cms\communication\models\CmsCommBotUpdate;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Applies stored updates of one bot in the order they came (bot\BotIngest),
 * each in its own transaction. Woken by the webhook or the poll; the run
 * reads waiting rows when it starts, and "coalesce" keeps one waiting run
 * beside a running one, so an update stored meanwhile is not left behind.
 * A long queue goes on in a new process.
 */
class BotApplyJobHandler extends AbstractJobHandler
{
    /** Seconds of one run before it continues in a new process. */
    public const SLICE = 50;

    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $connection = CmsCommConnection::findOne((int)$context->get('connectionId'));
        if (!$connection) {
            $reporter->setStage('complete', 'Бот удалён');
            return;
        }
        $ingest = new BotIngest($connection);
        $started = time();
        $lastId = 0;
        $counts = ['applied' => 0, 'skipped' => 0, 'failed' => 0];
        while (true) {
            /** @var CmsCommBotUpdate[] $rows */
            $rows = CmsCommBotUpdate::find()
                ->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'status' => CmsCommBotUpdate::STATUS_NEW])
                ->andWhere(['>', 'id', $lastId])
                ->orderBy(['id' => SORT_ASC])->limit(100)->all();
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int)$row->id;
                $status = $ingest->apply($row);
                if ($status === CmsCommBotUpdate::STATUS_APPLIED) {
                    $counts['applied']++;
                    $reporter->countSuccess();
                } elseif ($status === CmsCommBotUpdate::STATUS_SKIPPED) {
                    $counts['skipped']++;
                    $reporter->countSkipped();
                } else {
                    $counts['failed']++;
                    $reporter->countError();
                }
                if (time() - $started >= self::SLICE) {
                    $reporter->setResult($counts);
                    $context->requestRequeue(0);
                }
            }
        }
        $reporter->setResult($counts);
        $reporter->setStage('complete', "Обработано событий: {$counts['applied']}, пропущено: {$counts['skipped']}, с ошибкой: {$counts['failed']}");
    }
}
