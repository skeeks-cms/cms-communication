<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\console;

use skeeks\cms\communication\bot\BotImport;
use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotSubscriptions;
use skeeks\cms\communication\models\CmsCommConnection;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Bots from the console: the state of a bot, subscribing it now, importing
 * the chats it already is in.
 *
 *   php yii comm-bot/state 14
 *   php yii comm-bot/subscribe 14
 *   php yii comm-bot/import 14 /tmp/chats.json [/tmp/more.json] --days=90 --skip=-72800743125203,-70979229057277 --by=1
 */
class BotController extends Controller
{
    /** @var int days of history to import */
    public $days = 90;
    /** @var string chat ids not to import, comma separated (a monitoring chat, a channel) */
    public $skip = '';
    /** @var int|null the employee company links are made for */
    public $by;
    /** @var bool only list what would be imported */
    public $dryRun = false;
    /**
     * @var string a stand without the bot's token: the name of an environment
     * variable to take it from for this run only (never stored)
     */
    public $tokenEnv = '';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), $actionID === 'import' ? ['days', 'skip', 'by', 'dryRun', 'tokenEnv'] : []);
    }

    /** Whether the site receives, the bot, its webhook. */
    public function actionState(int $id): int
    {
        $connection = $this->connection($id);
        $driver = Bots::driver($connection);
        $this->stdout('Бот: '.$connection->name.' (@'.$driver->botUsername.'), приём на сайте: '.(Bots::canReceive() ? 'да' : 'нет').PHP_EOL);
        $webhook = $driver->getWebhook();
        $this->stdout('Вебхук: '.($webhook['url'] ?: 'нет').($webhook['error'] ? ', ошибка: '.$webhook['error'] : '').PHP_EOL);

        return ExitCode::OK;
    }

    /** Subscribes the bot now (the maintenance job does it too). */
    public function actionSubscribe(int $id): int
    {
        $result = BotSubscriptions::ensure($this->connection($id));
        $this->stdout(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return ExitCode::OK;
    }

    /** Imports chats of registry files: history, company, title, picture, members. */
    public function actionImport(int $id, string ...$files): int
    {
        $connection = $this->connection($id);
        $rows = BotImport::registry($files);
        $skip = array_filter(array_map('trim', explode(',', (string)$this->skip)));
        $rows = array_diff_key($rows, array_flip($skip));
        $this->stdout('Чатов к импорту: '.count($rows).', пропущено: '.count($skip).', за '.(int)$this->days.' дн.'.PHP_EOL);
        if ($this->dryRun) {
            foreach ($rows as $row) {
                $this->stdout($row['chat_id'].' '.$row['type'].' '.$row['title'].($row['cms_company_id'] ? ' → компания '.$row['cms_company_id'] : '').PHP_EOL);
            }
            return ExitCode::OK;
        }
        $driver = Bots::driver($connection);
        if ($this->tokenEnv !== '' && (string)getenv($this->tokenEnv) !== '') {
            $driver->token = (string)getenv($this->tokenEnv);
        }
        $import = new BotImport($connection, $driver);
        $total = 0;
        foreach ($rows as $row) {
            $result = $import->chat($row, (int)$this->days, $this->by ? (int)$this->by : null);
            $total += $result['messages'];
            $this->stdout(sprintf("%s %s: сообщений %d%s%s%s\n", $result['chat'], $result['title'], $result['messages'],
                $result['conversation'] ? ', чат '.$result['conversation'] : '', $result['company'] ? ', компания '.$result['company'] : '',
                $result['error'] ? ' — '.$result['error'] : ''));
        }
        $this->stdout('Готово: новых сообщений '.$total.PHP_EOL);

        return ExitCode::OK;
    }

    private function connection(int $id): CmsCommConnection
    {
        $connection = CmsCommConnection::findOne($id);
        if (!$connection || !Bots::isBot($connection)) {
            throw new \yii\console\Exception('Бот #'.$id.' не найден.');
        }

        return $connection;
    }
}
