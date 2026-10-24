<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotSubscriptions;
use skeeks\cms\communication\bot\BotUpdates;
use skeeks\cms\communication\models\CmsCommBotUpdate;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * Hourly care of bots: the webhook is subscribed as the site needs it
 * (bot\BotSubscriptions), updates left waiting (a failed wake, a retry) are
 * applied, the journal keeps 30 days.
 */
class BotMaintainJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $result = ['checked' => 0, 'subscribed' => 0, 'errors' => 0, 'woken' => 0, 'removed' => 0];
        $drivers = array_keys(Bots::drivers());
        foreach ($drivers ? CmsCommConnection::find()->andWhere(['driver' => $drivers])->all() : [] as $connection) {
            $result['checked']++;
            try {
                $state = BotSubscriptions::ensure($connection);
                if (in_array($state['state'], [BotSubscriptions::SUBSCRIBED, BotSubscriptions::UNSUBSCRIBED], true)) {
                    $result['subscribed']++;
                    $reporter->info('Подписка бота «'.$connection->name.'»: '.$state['state']);
                }
                if ($state['error']) {
                    $reporter->warning('Бот «'.$connection->name.'»: мессенджер сообщает об ошибке доставки: '.$state['error']);
                }
            } catch (\Throwable $e) {
                $result['errors']++;
                $reporter->itemError('connection', (int)$connection->id, 'Не удалось проверить подписку бота «'.$connection->name.'»: '.$e->getMessage());
            }
            // Titles and pictures of chats: those never asked or asked a week ago.
            if (Bots::canReceive() && (int)$connection->is_active) {
                foreach (\skeeks\cms\communication\bot\BotChats::stale((int)$connection->id) as $chat) {
                    \Yii::$app->jobs->push('comm.bot.chat-info', ['conversationId' => (int)$chat->id], ['siteId' => (int)$connection->cms_site_id]);
                }
            }
            if (CmsCommBotUpdate::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'status' => CmsCommBotUpdate::STATUS_NEW])->exists()) {
                BotUpdates::wake($connection);
                $result['woken']++;
            }
        }
        $result['removed'] = BotUpdates::cleanup();
        $reporter->setResult($result);
        $reporter->setStage('complete', "Ботов: {$result['checked']}, подписка обновлена: {$result['subscribed']}, ошибок: {$result['errors']}");
    }
}
