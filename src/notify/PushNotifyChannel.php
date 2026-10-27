<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use skeeks\cms\communication\models\CmsCommNotifyDelivery;

/**
 * The mobile application (skeeks/cms-mobile): a push to the person's devices
 * that opens the notification link. The journal says "sent" once the push is
 * queued in cms-mobile: its provider outcome stays in the cms-mobile report and
 * starts no fallback here. Acceptance by the push provider is not proof of receipt.
 */
class PushNotifyChannel extends DeliveryNotifyChannel
{
    public $name = 'Приложение';
    public $priority = 200;

    public function isAvailable(): bool
    {
        return \Yii::$app->has('mobilePush') && \Yii::$app->mobilePush->enabled
            && class_exists(\skeeks\cms\mobile\models\CmsMobileInstallation::class);
    }

    public function isConnected(int $userId): bool
    {
        return $this->devices($userId) > 0;
    }

    public function status(int $userId): string
    {
        $count = $this->devices($userId);

        return $count ? 'Устройств: '.$count : 'Приложение не установлено или уведомления в нём выключены';
    }

    public function send(CmsCommNotifyDelivery $delivery, NotifyMessage $message): array
    {
        try {
            $ids = \Yii::$app->mobilePush->enqueue((int)$delivery->cms_user_id, 'notify:'.(int)$delivery->id, '/~n/'.(int)$delivery->cms_web_notify_id);
        } catch (\Throwable $e) {
            return [CmsCommNotifyDelivery::STATUS_FAILED, 'Push не поставлен: '.$e->getMessage(), null];
        }

        if (!$ids) {
            return [CmsCommNotifyDelivery::STATUS_FAILED, 'Нет устройств с включёнными уведомлениями.', null];
        }
        // cms-mobile owns the outcome (its delivery job and report); this journal only knows the push is queued,
        // so a later refusal of the push provider starts no fallback channel.
        $delivery->reason = 'Передано в очередь push (устройств: '.count($ids).'); итог — в отчёте отправки на телефон';

        return [CmsCommNotifyDelivery::STATUS_SENT, null, null];
    }

    private function devices(int $userId): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        return (int)\skeeks\cms\mobile\models\CmsMobileInstallation::find()
            ->andWhere(['cms_user_id' => $userId, 'enabled' => 1, 'permission' => 'granted'])->count();
    }
}
