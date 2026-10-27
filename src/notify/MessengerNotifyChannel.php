<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommNotifyChannel;
use skeeks\cms\communication\models\CmsCommNotifyDelivery;
use skeeks\cms\communication\send\SendException;
use yii\helpers\Html;

/**
 * A messenger (MAX, Telegram): the person's private chat with a bot of the
 * site, connected by a one-time link (NotifyLinks). The code of the channel is
 * the channel of the bot driver. Messages go through the CRM (bot\BotSend),
 * so they are in the chat history; the final outcome comes from the send job
 * (NotifyDeliveries::onBotResult()).
 */
class MessengerNotifyChannel extends DeliveryNotifyChannel
{
    public $priority = 100;

    /** @var CmsCommConnection[]|null */
    private $_bots;

    public function init()
    {
        parent::init();
        if (!$this->name) {
            $this->name = Bots::channels()[$this->code] ?? $this->code;
        }
    }

    /** @return CmsCommConnection[] active bots of the channel on the site */
    public function bots(): array
    {
        if ($this->_bots === null) {
            $this->_bots = [];
            $drivers = Bots::drivers();
            $query = CmsCommConnection::find()->andWhere(['is_active' => 1, 'driver' => array_keys($drivers) ?: ['-']]);
            foreach ($query->orderBy(['id' => SORT_ASC])->all() as $connection) {
                $class = $drivers[(string)$connection->driver] ?? null;
                if ($class && $class::channel() === $this->code) {
                    $this->_bots[] = $connection;
                }
            }
        }

        return $this->_bots;
    }

    public function isAvailable(): bool
    {
        return (bool)$this->bots();
    }

    public function row(int $userId): ?CmsCommNotifyChannel
    {
        return NotifyLinks::channelRow($userId, (string)$this->code);
    }

    public function isConnected(int $userId): bool
    {
        $row = $this->row($userId);
        $conversation = $row && $row->is_active ? $row->conversation : null;

        return $conversation && $conversation->bot_status !== CmsCommConversation::BOT_LEFT
            && !($row->identity && $row->identity->is_blocked);
    }

    public function status(int $userId): string
    {
        $row = $this->row($userId);
        if (!$row || !$row->conversation) {
            return 'Не подключён';
        }
        if ($row->identity && $row->identity->is_blocked) {
            return 'Бот остановлен в мессенджере: откройте его и нажмите «Старт»';
        }
        $who = $row->identity ? trim((string)$row->identity->name.($row->identity->username ? ' @'.$row->identity->username : '')) : '';
        $bot = $row->connection ? (string)$row->connection->name : '';
        return ($row->is_active ? 'Подключён' : 'Выключен').($who !== '' ? ': '.$who : '').($bot !== '' ? ', бот «'.$bot.'»' : '');
    }

    /** The last refusal after the last success, in plain words. */
    public function problem(int $userId): ?string
    {
        $row = $this->row($userId);
        if (!$row || !$row->last_error || (int)$row->last_error_at <= (int)$row->last_ok_at) {
            return null;
        }
        $error = (string)$row->last_error;
        $when = \Yii::$app->formatter->asDatetime((int)$row->last_error_at, 'short');
        if (mb_stripos($error, 'allowedChats') !== false) {
            $error = 'на этом сайте отправка в этот чат запрещена настройками (так бывает на тестовом стенде)';
        } elseif (mb_stripos($error, 'blocked') !== false || mb_stripos($error, 'заблок') !== false) {
            $error = 'бот остановлен в мессенджере — откройте бота и нажмите «Старт»';
        }

        return 'Не удалось доставить '.$when.': '.$error.'. Если подключён другой канал, уведомление ушло через него.';
    }

    public function renderActions(int $userId, \yii\web\View $view): string
    {
        $button = static function (string $action, string $label, array $params, string $class = 'sx-button sx-button--secondary') {
            $html = Html::beginForm(['/cmsCommunication/admin-notify-channel/'.$action], 'post', ['class' => 'sx-notify-settings__inline-form']);
            foreach ($params as $key => $value) {
                $html .= Html::hiddenInput($key, $value);
            }

            return $html.Html::submitButton($label, ['class' => $class]).Html::endForm();
        };
        $code = (string)$this->code;
        $row = $this->row($userId);
        $html = '';
        if ($this->isConnected($userId)) {
            return $button('test', 'Проверить', ['channel' => $code])
                .$button('disconnect', 'Выключить', ['channel' => $code]);
        }
        if ($row && $row->conversation && !$row->is_active) {
            $html .= $button('enable', 'Включить', ['channel' => $code], 'sx-button sx-button--primary');
        }
        $pending = NotifyLinks::pending($userId, $code);
        if ($pending && $pending['url']) {
            return $html.Html::a('Открыть '.$this->name, $pending['url'], ['class' => 'sx-button sx-button--primary', 'target' => '_blank', 'rel' => 'noopener'])
                .Html::tag('span', 'Нажмите «Старт» в боте, затем обновите эту страницу. Ссылка действует до '
                    .\Yii::$app->formatter->asTime((int)$pending['expires_at'], 'short').'.', ['class' => 'sx-notify-settings__hint']);
        }
        $bots = $this->bots();
        foreach ($bots as $bot) {
            $label = count($bots) > 1 ? 'Подключить через «'.$bot->name.'»' : 'Подключить';
            $html .= $button('connect', $label, ['channel' => $code, 'connection' => (int)$bot->id], 'sx-button sx-button--primary');
        }

        return $html;
    }

    public function send(CmsCommNotifyDelivery $delivery, NotifyMessage $message): array
    {
        $row = $this->row((int)$delivery->cms_user_id);
        $conversation = $row && $row->is_active ? $row->conversation : null;
        if (!$conversation) {
            return [CmsCommNotifyDelivery::STATUS_FAILED, $this->name.' не подключён.', null];
        }
        // A button needs a public https address; a link of a stand goes as text.
        $button = strpos($message->url, 'https://') === 0;
        try {
            $sent = BotSend::queue($conversation, $message->text(!$button), [
                'sign' => false,
                'buttons' => $button ? [[['text' => 'Открыть в CRM', 'url' => $message->url]]] : [],
            ]);
        } catch (SendException $e) {
            NotifyLinks::markResult($row, false, $e->getMessage());

            return [CmsCommNotifyDelivery::STATUS_FAILED, $e->getMessage(), null];
        }

        return [CmsCommNotifyDelivery::STATUS_SENDING, null, (int)$sent->id];
    }
}
