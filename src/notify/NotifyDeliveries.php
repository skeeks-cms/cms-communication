<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommNotifyChannel;
use skeeks\cms\communication\models\CmsCommNotifyDelivery;
use skeeks\cms\models\CmsWebNotify;
use skeeks\cms\notify\Notifications;
use skeeks\cms\notify\NotifyEvent;

/**
 * Delivery of notifications outside the CRM (mail, messengers, the
 * application) by the person's settings "что и куда".
 *
 * - A stored notification (Notifications::EVENT_CREATED) gets one journal
 *   row per channel that should get it, and a job `comm.notify.deliver`
 *   in DELAY seconds: related events of a group created meanwhile go as one
 *   message.
 * - One event — one message in a channel (unique notification, channel and
 *   event of a burst); a burst row updated by more events goes again not
 *   sooner than BURST_INTERVAL.
 * - Quiet hours postpone everything but urgent until they end; a notification
 *   read in the CRM meanwhile is not sent.
 * - A refusal (bot blocked, no email, the mail server said no) tries the next
 *   connected channel; an unknown outcome is never repeated or replaced.
 */
class NotifyDeliveries
{
    public const DELAY = 20;
    public const BURST_INTERVAL = 900;

    /** A later event of an entry waits for the earlier one to send it in one message. */
    public const REASON_WAITS = 'Вместе с предыдущим уведомлением';

    /** A test of a channel from the settings page. */
    public const REASON_TEST = 'Проверка канала';

    /** The events of an entry sent in one message by delivery №… */
    public const REASON_MERGED = 'Отправлено одним сообщением с №';

    /** Handler of Notifications::EVENT_CREATED: queue rows inside the caller's transaction. */
    public static function onCreated(NotifyEvent $event): void
    {
        $notify = $event->notify;
        if (!$notify->kind || !\Yii::$app->has('jobs')) {
            return;
        }
        /** @var Notifications $notifications */
        $notifications = \Yii::$app->notifications;
        $notifications->resetSettings((int)$notify->cms_user_id);
        foreach ($notifications->channelList as $code => $channel) {
            if (!$channel instanceof DeliveryNotifyChannel || !$notifications->shouldDeliver($notify, $code)
                || !$channel->isConnected((int)$notify->cms_user_id)) {
                continue;
            }
            if (!$event->isNew && CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$notify->id, 'channel' => $code])
                    ->andWhere(['>', 'created_at', time() - self::BURST_INTERVAL])->exists()) {
                continue;
            }
            self::queue($notify, $code, $event->isNew ? 1 : (int)$notify->count, self::DELAY);
        }
    }

    /**
     * A test of a channel ("Проверить"): sent at once whatever the settings and quiet hours say.
     * When the settings already queued the notification for this channel, that row becomes the test.
     */
    public static function queueTest(CmsWebNotify $notify, string $channel): ?CmsCommNotifyDelivery
    {
        $existing = CmsCommNotifyDelivery::find()->andWhere(['cms_web_notify_id' => (int)$notify->id, 'channel' => $channel, 'step' => 1])->one();
        if (!$existing) {
            return self::queue($notify, $channel, 1, 0, null, true);
        }
        if (in_array($existing->status, [CmsCommNotifyDelivery::STATUS_QUEUED, CmsCommNotifyDelivery::STATUS_POSTPONED], true)) {
            $existing->setAttributes(['reason' => self::REASON_TEST, 'skip_if_read' => 0, 'status' => CmsCommNotifyDelivery::STATUS_QUEUED, 'scheduled_at' => time()], false);
            $existing->save(false);
            \Yii::$app->jobs->push('comm.notify.deliver', ['deliveryId' => (int)$existing->id, 'test' => 1], ['delay' => 0]);
        }

        return $existing;
    }

    /**
     * Why a delivery may not go now (settings changed while it waited, mute), null — it may.
     * A fallback and a channel test are explicit and pass.
     */
    private static function refusedNow(CmsCommNotifyDelivery $delivery, CmsWebNotify $notify): ?string
    {
        if ($delivery->fallback_of_id || $delivery->reason === self::REASON_TEST) {
            return null;
        }
        /** @var Notifications $notifications */
        $notifications = \Yii::$app->notifications;
        $urgent = (int)$notify->importance >= Notifications::IMPORTANCE_URGENT;
        if (!$urgent && $notify->kind && $notifications->isMuted((int)$delivery->cms_user_id, (string)$notify->kind)) {
            return 'Отключено: «Не уведомлять о таком»';
        }
        if (!$notifications->shouldDeliver($notify, (string)$delivery->channel)) {
            return 'Выключено в настройках уведомлений';
        }

        return null;
    }

    public static function queue(CmsWebNotify $notify, string $channel, int $step, int $delay, ?CmsCommNotifyDelivery $fallbackOf = null, bool $test = false): ?CmsCommNotifyDelivery
    {
        $delivery = new CmsCommNotifyDelivery([
            'cms_web_notify_id' => (int)$notify->id,
            'cms_user_id' => (int)$notify->cms_user_id,
            'channel' => $channel,
            'step' => $step,
            'status' => CmsCommNotifyDelivery::STATUS_QUEUED,
            'title' => mb_substr((string)$notify->name, 0, 255),
            'scheduled_at' => time() + $delay,
            'fallback_of_id' => $fallbackOf ? (int)$fallbackOf->id : null,
            'reason' => $test ? self::REASON_TEST : ($fallbackOf ? 'Запасной канал: «'.self::channelName($fallbackOf->channel).'» не доставил' : null),
            // A silent row of the CRM is read from the start: reading cannot mean "already seen".
            'skip_if_read' => $fallbackOf || $test ? 0 : (int)!$notify->is_read,
        ]);
        try {
            $delivery->save(false);
        } catch (\yii\db\IntegrityException $e) {
            return null;
        }
        \Yii::$app->jobs->push('comm.notify.deliver', ['deliveryId' => (int)$delivery->id], ['delay' => $delay]);

        return $delivery;
    }

    /**
     * The job: one delivery row.
     *
     * @return array [state: done|requeue, seconds or text]
     */
    public static function run(int $deliveryId): array
    {
        $delivery = CmsCommNotifyDelivery::findOne($deliveryId);
        if (!$delivery || !in_array($delivery->status, [CmsCommNotifyDelivery::STATUS_QUEUED, CmsCommNotifyDelivery::STATUS_POSTPONED], true)) {
            return ['done', 'Уже обработано'];
        }
        $notify = $delivery->notify;
        if (!$notify) {
            return self::skip($delivery, 'Уведомление удалено');
        }
        /** @var Notifications $notifications */
        $notifications = \Yii::$app->notifications;
        $notifications->resetSettings((int)$delivery->cms_user_id);
        $channel = $notifications->channel((string)$delivery->channel);
        if (!$channel instanceof DeliveryNotifyChannel) {
            return self::skip($delivery, 'Канал больше недоступен');
        }
        $urgent = (int)$notify->importance >= Notifications::IMPORTANCE_URGENT;
        $isTest = $delivery->reason === self::REASON_TEST;
        if (($refusal = self::refusedNow($delivery, $notify)) !== null) {
            return self::skip($delivery, $refusal);
        }
        if (!$urgent && $delivery->skip_if_read && $notify->is_read) {
            return self::skip($delivery, 'Прочитано в CRM');
        }
        if (!$urgent && !$isTest && ($until = $notifications->quietEndsAt((int)$delivery->cms_user_id))) {
            $delivery->status = CmsCommNotifyDelivery::STATUS_POSTPONED;
            $delivery->reason = 'Тихие часы до '.\Yii::$app->formatter->asTime($until, 'short');
            $delivery->scheduled_at = $until;
            $delivery->save(false);

            return ['requeue', max(60, $until - time())];
        }

        // An earlier queued event of the same entry carries this one.
        // Jobs may run in any order: a later event that ran first waits in the journal and the earlier
        // one takes it into its message.
        $group = self::groupDeliveries($delivery, $notify);
        foreach ($group as $other) {
            if ((int)$other->id < (int)$delivery->id && in_array($other->status, [CmsCommNotifyDelivery::STATUS_QUEUED, CmsCommNotifyDelivery::STATUS_POSTPONED], true)) {
                return self::skip($delivery, self::REASON_WAITS);
            }
        }
        $merged = array_filter($group, static fn($d) => (int)$d->id > (int)$delivery->id
            && (in_array($d->status, [CmsCommNotifyDelivery::STATUS_QUEUED, CmsCommNotifyDelivery::STATUS_POSTPONED], true)
                || ($d->status === CmsCommNotifyDelivery::STATUS_SKIPPED && $d->reason === self::REASON_WAITS)));

        $rows = [$notify];
        foreach ($merged as $i => $other) {
            // Every event of the entry follows the settings of now, not only the first one.
            $refusal = $other->notify ? self::refusedNow($other, $other->notify) : 'Уведомление удалено';
            if ($refusal !== null) {
                self::skip($other, $refusal);
                unset($merged[$i]);
                continue;
            }
            $rows[] = $other->notify;
        }
        $message = self::compose($rows);

        if (!CmsCommNotifyDelivery::updateAll(['status' => CmsCommNotifyDelivery::STATUS_SENDING, 'updated_at' => time()],
            ['id' => (int)$delivery->id, 'status' => [CmsCommNotifyDelivery::STATUS_QUEUED, CmsCommNotifyDelivery::STATUS_POSTPONED]])) {
            return ['done', 'Уже обработано'];
        }
        $delivery->refresh();
        foreach ($merged as $other) {
            self::skip($other, self::REASON_MERGED.(int)$delivery->id);
        }

        try {
            [$status, $error, $messageId] = $channel->send($delivery, $message);
        } catch (\Throwable $e) {
            \Yii::error('Уведомление: доставка #'.(int)$delivery->id.' — исход неизвестен: '.$e->getMessage(), self::class);
            [$status, $error, $messageId] = [CmsCommNotifyDelivery::STATUS_UNKNOWN, $e->getMessage(), null];
        }
        $delivery->cms_comm_message_id = $messageId;
        self::finish($delivery, $status, $error);

        return ['done', CmsCommNotifyDelivery::statusNames()[$delivery->status] ?? $delivery->status];
    }

    /** The send job of a bot finished: the outcome of the notification message in the chat. */
    public static function onBotResult(int $messageId, ?string $status, ?string $error): void
    {
        if ($status === null || $status === 'wait') {
            return;
        }
        $delivery = CmsCommNotifyDelivery::find()->andWhere(['cms_comm_message_id' => $messageId, 'status' => CmsCommNotifyDelivery::STATUS_SENDING])->one();
        if (!$delivery) {
            return;
        }
        $map = [
            CmsCommMessage::STATUS_SENT => CmsCommNotifyDelivery::STATUS_SENT,
            CmsCommMessage::STATUS_FAILED => CmsCommNotifyDelivery::STATUS_FAILED,
        ];
        self::finish($delivery, $map[$status] ?? CmsCommNotifyDelivery::STATUS_UNKNOWN, $error);
    }

    /** The text of an entry: the heading, the event, what followed it, the link. */
    public static function compose(array $rows): NotifyMessage
    {
        /** @var CmsWebNotify $main */
        $main = $rows[0];
        $message = new NotifyMessage();
        $site = \Yii::$app->has('cms') ? trim((string)\Yii::$app->cms->appName) : '';
        $message->heading = ($site !== '' ? $site.' · ' : '').$main->categoryName;
        $message->urgent = (int)$main->importance >= Notifications::IMPORTANCE_URGENT;
        foreach ($rows as $i => $row) {
            $line = trim((string)$row->name);
            $label = self::modelLabel($row);
            if ($label !== '' && mb_stripos($line, $label) === false) {
                $line .= ': «'.$label.'»';
            }
            $message->lines[] = $i === 0 ? $line : '+ '.$line;
            if ($i === 0 && $row->comment) {
                $message->lines[] = mb_strimwidth((string)$row->comment, 0, 300, '…');
            }
        }
        $message->url = self::siteUrl().'/~n/'.(int)$main->id;

        return $message;
    }

    /**
     * The address of the site for links in messages. Delivery runs in a console job
     * without a request, so without communication.baseUrl it is the default site.
     */
    private static function siteUrl(): string
    {
        $url = rtrim(\Yii::$app->communication->siteUrl(), '/');
        if ($url === '') {
            $site = \skeeks\cms\models\CmsSite::find()->andWhere(['is_default' => 1])->one();
            $url = rtrim($site ? (string)$site->url : '', '/');
        }

        return $url;
    }

    /**
     * Journal rows of a user, newest first.
     *
     * @return CmsCommNotifyDelivery[]
     */
    public static function journal(int $userId, int $limit = 30): array
    {
        return CmsCommNotifyDelivery::find()->andWhere(['cms_user_id' => $userId])->orderBy(['id' => SORT_DESC])->limit($limit)->all();
    }

    public static function channelName(string $code): string
    {
        $channel = \Yii::$app->notifications->channel($code);

        return $channel ? (string)$channel->name : $code;
    }

    private static function finish(CmsCommNotifyDelivery $delivery, string $status, ?string $error): void
    {
        $delivery->status = $status;
        $delivery->error = $error !== null ? mb_substr($error, 0, 1000) : null;
        if ($status === CmsCommNotifyDelivery::STATUS_SENT) {
            $delivery->sent_at = time();
        }
        $delivery->save(false);

        if (in_array($status, [CmsCommNotifyDelivery::STATUS_SENT, CmsCommNotifyDelivery::STATUS_FAILED], true)) {
            $row = CmsCommNotifyChannel::find()->andWhere(['cms_user_id' => (int)$delivery->cms_user_id, 'channel' => $delivery->channel])->one();
            if ($row) {
                NotifyLinks::markResult($row, $status === CmsCommNotifyDelivery::STATUS_SENT, $error);
            }
        }
        if ($status === CmsCommNotifyDelivery::STATUS_FAILED) {
            self::fallback($delivery);
        }
    }

    /** The next connected channel after a refusal; the CRM always has the notification anyway. */
    private static function fallback(CmsCommNotifyDelivery $failed): void
    {
        $notify = $failed->notify;
        if (!$notify) {
            return;
        }
        $used = CmsCommNotifyDelivery::find()->select('channel')->andWhere(['cms_web_notify_id' => (int)$notify->id])->column();
        foreach (\Yii::$app->notifications->channelList as $code => $channel) {
            if (!$channel instanceof DeliveryNotifyChannel || in_array($code, $used, true) || !$channel->isConnected((int)$notify->cms_user_id)) {
                continue;
            }
            self::queue($notify, $code, (int)$failed->step, 0, $failed);
            // Events that went in the failed message go too: queued after the main one, they join its message.
            $merged = CmsCommNotifyDelivery::find()->andWhere([
                'cms_user_id' => (int)$failed->cms_user_id,
                'channel' => $failed->channel,
                'status' => CmsCommNotifyDelivery::STATUS_SKIPPED,
                'reason' => self::REASON_MERGED.(int)$failed->id,
            ])->orderBy(['id' => SORT_ASC])->all();
            foreach ($merged as $child) {
                if ($child->notify) {
                    self::queue($child->notify, $code, (int)$child->step, 0, $failed);
                }
            }

            return;
        }
    }

    /** @return CmsCommNotifyDelivery[] deliveries of the same channel for the other events of the entry */
    private static function groupDeliveries(CmsCommNotifyDelivery $delivery, CmsWebNotify $notify): array
    {
        if (!$notify->group_key) {
            return [];
        }
        $ids = CmsWebNotify::find()->select('id')->andWhere(['cms_user_id' => (int)$notify->cms_user_id, 'group_key' => $notify->group_key])->column();

        return CmsCommNotifyDelivery::find()->andWhere([
            'cms_web_notify_id' => $ids,
            'channel' => $delivery->channel,
        ])->andWhere(['<>', 'id', (int)$delivery->id])->orderBy(['id' => SORT_ASC])->all();
    }

    private static function skip(CmsCommNotifyDelivery $delivery, string $reason): array
    {
        $delivery->status = CmsCommNotifyDelivery::STATUS_SKIPPED;
        $delivery->reason = $reason;
        $delivery->save(false);

        return ['done', $reason];
    }

    private static function modelLabel(CmsWebNotify $row): string
    {
        try {
            $model = $row->model;

            return $model ? trim($row->getModelLabel($model)) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
