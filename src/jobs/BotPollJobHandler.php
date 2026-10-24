<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotUpdates;
use skeeks\cms\communication\bot\MessengerDriver;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Takes updates of a bot in the poll mode (a site without public HTTPS):
 * long polls for about a minute, every batch stored together with its
 * cursor (bot\BotUpdates::store()). Never runs where the site must not
 * receive (a stand with production bots) or for a bot in the webhook mode:
 * a poll would take the updates away from the webhook.
 */
class BotPollJobHandler extends AbstractJobHandler
{
    public const SLICE = 50;
    public const TIMEOUT = 20;

    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $connection = CmsCommConnection::findOne((int)$context->get('connectionId'));
        if (!$connection || !(int)$connection->is_active || !Bots::isBot($connection)) {
            $reporter->setStage('complete', 'Бот выключен или удалён');
            return;
        }
        if (!Bots::canReceive()) {
            $reporter->setStage('complete', 'Сайту запрещено получать сообщения ботов (communication.botReceive)');
            return;
        }
        $driver = Bots::driver($connection);
        if ($driver->mode !== MessengerDriver::MODE_POLL) {
            $reporter->setStage('complete', 'Бот получает сообщения через вебхук');
            return;
        }
        $started = time();
        $stored = 0;
        do {
            $result = $driver->poll(BotUpdates::cursor($connection), self::TIMEOUT);
            $counts = BotUpdates::store($connection, $driver, $result->updates, $result->cursor);
            $stored += $counts['stored'];
            $reporter->heartbeat();
        } while (time() - $started < self::SLICE && !$reporter->isCancelled());
        $reporter->setResult(['stored' => $stored]);
        $reporter->setStage('complete', "Получено событий: {$stored}");
    }
}
