<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\helpers;

use skeeks\cms\backend\widgets\BackendSurfaceWidget;
use skeeks\cms\communication\mail\MailDriverInterface;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommSyncState;
use yii\helpers\Html;

/**
 * "Проверить подключение" and "Получить письма" for mailbox and connection cards.
 */
class ConnectionDiagnostics
{
    /**
     * Logs in to IMAP and SMTP right away (not through the queue) with the
     * driver timeout; reads and sends nothing.
     */
    public static function check(?CmsCommConnection $connection): string
    {
        if (!$connection) {
            return self::surface('Проверка подключения', Html::tag('div', 'У ящика не настроено подключение к серверу.', ['class' => 'alert alert-warning']));
        }
        try {
            $driver = $connection->createDriver(true);
            $results = $driver instanceof MailDriverInterface
                ? $driver->checkConnection()
                : [['label' => 'Подключение', 'ok' => false, 'message' => 'Драйвер не поддерживает проверку.']];
        } catch (\Throwable $e) {
            $results = [['label' => 'Подключение', 'ok' => false, 'message' => $e->getMessage()]];
        }

        $html = '';
        foreach ($results as $result) {
            $html .= Html::tag('div',
                Html::tag('strong', ($result['ok'] ? '✓ ' : '✕ ').Html::encode($result['label']))
                .'<div>'.Html::encode($result['message']).'</div>',
                ['class' => 'alert '.($result['ok'] ? 'alert-success' : 'alert-danger')]);
        }
        foreach (CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => $connection->id, 'folder_role' => CmsCommSyncState::RECEIVE_ROLES])->all() as $state) {
            $html .= Html::tag('div', Html::encode(sprintf('%s: последний приём %s%s',
                CmsCommSyncState::roles()[$state->folder_role] ?? $state->folder,
                $state->last_success_at ? \Yii::$app->formatter->asRelativeTime($state->last_success_at) : 'ещё не было',
                $state->last_error ? '. Ошибка: '.$state->last_error : ''
            )), ['class' => 'sx-gray']);
        }

        return self::surface('Проверка подключения', $html);
    }

    /** Starts receiving now instead of waiting for the schedule. */
    public static function poll(?CmsCommConnection $connection): string
    {
        if (!$connection) {
            return self::surface('Получить письма', Html::tag('div', 'У ящика не настроено подключение к серверу.', ['class' => 'alert alert-warning']));
        }
        if (\skeeks\cms\communication\mail\MailingServices::isServiceDriver((string)$connection->driver)) {
            return self::surface('Получить письма', Html::tag('div', 'Через сервис рассылок письма только отправляются: ответы клиентов приходят в рабочий ящик, '
                .'указанный в «Ответы направлять на» (Reply-To).', ['class' => 'alert alert-info']));
        }
        $run = \Yii::$app->jobs->push('comm.mail.poll', ['connectionId' => (int)$connection->id], ['siteId' => (int)$connection->cms_site_id]);
        $text = $run
            ? 'Приём писем запущен. Через несколько секунд письма появятся в разделе «Почта» и в лентах лидов, компаний и клиентов.'
            : 'Приём писем по этому ящику уже идёт.';

        return self::surface('Получить письма', Html::tag('div', Html::encode($text), ['class' => 'alert alert-info']));
    }

    private static function surface(string $title, string $content): string
    {
        return BackendSurfaceWidget::widget(['title' => $title, 'titleTag' => 'h2', 'raised' => true, 'content' => $content]);
    }
}
