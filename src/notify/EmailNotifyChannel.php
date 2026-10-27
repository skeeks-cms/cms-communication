<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use skeeks\cms\communication\models\CmsCommNotifyDelivery;
use skeeks\cms\models\CmsUser;

/**
 * Mail: a short letter with the link to the record, sent by the system mailer
 * of the site (as other letters of the CMS), not by a mailbox of "Почта".
 */
class EmailNotifyChannel extends DeliveryNotifyChannel
{
    public $name = 'Почта';
    public $priority = 300;

    public function isAvailable(): bool
    {
        return \Yii::$app->has('mailer');
    }

    public function isConnected(int $userId): bool
    {
        return $this->email($userId) !== '';
    }

    public function status(int $userId): string
    {
        $email = $this->email($userId);

        return $email !== '' ? $email : 'В профиле нет email';
    }

    public function send(CmsCommNotifyDelivery $delivery, NotifyMessage $message): array
    {
        $email = $this->email((int)$delivery->cms_user_id);
        if ($email === '') {
            return [CmsCommNotifyDelivery::STATUS_FAILED, 'В профиле нет email.', null];
        }
        // A stand: only the recipients allowed for mail of the stand.
        if (!\Yii::$app->communication->isRecipientAllowed($email)) {
            return [CmsCommNotifyDelivery::STATUS_FAILED, 'Адрес не разрешён настройкой allowedRecipients.', null];
        }
        try {
            $ok = \Yii::$app->mailer->compose()
                ->setFrom([\Yii::$app->cms->adminEmail => \Yii::$app->cms->appName])
                ->setTo($email)
                ->setSubject($message->subject())
                ->setTextBody($message->text())
                ->setHtmlBody($message->html())
                ->send();
        } catch (\Throwable $e) {
            // A broken connection may come after the server took the letter: unknown, no fallback (no double).
            return [CmsCommNotifyDelivery::STATUS_UNKNOWN, 'Почта: нет ответа сервера, неизвестно, ушло ли письмо: '.$e->getMessage(), null];
        }

        return $ok ? [CmsCommNotifyDelivery::STATUS_SENT, null, null] : [CmsCommNotifyDelivery::STATUS_FAILED, 'Почтовый сервер не принял письмо.', null];
    }

    private function email(int $userId): string
    {
        $user = CmsUser::findOne($userId);

        return $user ? trim((string)$user->email) : '';
    }
}
