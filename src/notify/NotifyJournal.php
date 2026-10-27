<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use skeeks\cms\backend\widgets\BackendSurfaceWidget;
use skeeks\cms\communication\models\CmsCommNotifyDelivery;
use skeeks\cms\notify\NotifySettingsEvent;
use yii\helpers\Html;

/**
 * "Журнал доставки" on the personal notification settings page: what went
 * where and what happened to it.
 */
class NotifyJournal
{
    public static function render(NotifySettingsEvent $event): void
    {
        $rows = NotifyDeliveries::journal((int)$event->userId, 30);
        if (!$rows) {
            return;
        }
        $names = CmsCommNotifyDelivery::statusNames();
        $formatter = \Yii::$app->formatter;
        $items = '';
        foreach ($rows as $row) {
            $state = $names[$row->status] ?? $row->status;
            $details = array_filter([$row->reason, $row->error]);
            $items .= Html::tag('tr',
                Html::tag('td', Html::encode($formatter->asDatetime((int)$row->created_at, 'short')), ['class' => 'sx-notify-journal__time'])
                .Html::tag('td', Html::encode((string)$row->title), ['class' => 'sx-notify-journal__title'])
                .Html::tag('td', Html::encode(NotifyDeliveries::channelName((string)$row->channel)))
                .Html::tag('td', Html::tag('span', Html::encode($state), ['class' => 'sx-notify-journal__state sx-notify-journal__state--'.$row->status])
                    .($details ? Html::tag('div', Html::encode(implode('. ', $details)), ['class' => 'sx-notify-journal__reason']) : ''))
            );
        }

        ob_start();
        BackendSurfaceWidget::begin([
            'title' => 'Журнал доставки',
            'hint'  => 'Последние уведомления, отправленные в почту, мессенджеры и приложение.',
        ]);
        echo Html::tag('div', Html::tag('table',
            Html::tag('thead', Html::tag('tr', '<th>Когда</th><th>Уведомление</th><th>Куда</th><th>Итог</th>')).Html::tag('tbody', $items),
            ['class' => 'sx-notify-settings__table sx-notify-journal']), ['class' => 'sx-notify-settings__table-wrap']);
        BackendSurfaceWidget::end();
        $event->html[] = ob_get_clean();
    }
}
