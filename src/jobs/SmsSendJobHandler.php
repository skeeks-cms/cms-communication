<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SmsSendChannel;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobPermanentException;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;
use skeeks\cms\models\CmsSmsMessage;
use skeeks\cms\models\CmsSmsProvider;

/**
 * Hands one queued SMS (a message of channel "sms") to the SMS provider of
 * the site. The provider's own record (cms_sms_message) is kept in
 * provider_ref, so the SMS is also in "SMS сообщения" of the CMS. Like
 * letters: queued → sending before the call, never resent automatically.
 */
class SmsSendJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $reporter->setTotal(1);
        [$status, $error] = self::deliver((int)$context->get('messageId'), (int)$context->get('providerId'), (string)$context->get('userIp'));
        if ($status === null) {
            return;
        }
        if ($status !== CmsCommMessage::STATUS_SENT) {
            $reporter->countError();
            throw new JobPermanentException('СМС не отправлено: '.$error);
        }
        $reporter->countSuccess();
        $reporter->advance();
        $reporter->setResult(['messageId' => (int)$context->get('messageId'), 'status' => $status]);
        $reporter->setStage('complete', 'СМС передано провайдеру');
    }

    /**
     * The delivery itself, for the job and tests.
     *
     * @return array{0: string|null, 1: string|null} [status, error]; null status — nothing to do
     */
    public static function deliver(int $messageId, int $providerId, string $userIp = ''): array
    {
        $message = CmsCommMessage::findOne($messageId);
        if (!$message || $message->channel !== SmsSendChannel::CHANNEL || $message->status !== CmsCommMessage::STATUS_QUEUED) {
            return [null, null];
        }
        $phone = (string)(array_keys($message->getTo())[0] ?? '');
        if (!\Yii::$app->communication->isPhoneAllowed($phone)) {
            return self::finish($messageId, CmsCommMessage::STATUS_FAILED, "Номер {$phone} не разрешён настройкой allowedPhones.");
        }
        $provider = CmsSmsProvider::find()->andWhere(['id' => $providerId, 'cms_site_id' => (int)$message->cms_site_id])->one()
            ?: SmsSendChannel::provider((int)$message->cms_site_id);
        $handler = $provider ? $provider->handler : null;
        if (!$handler) {
            return self::finish($messageId, CmsCommMessage::STATUS_FAILED, 'Провайдер СМС не настроен.');
        }

        // Outcome marker: from here on a crash leaves the SMS visibly uncertain.
        if (!CmsCommMessage::updateAll(['status' => CmsCommMessage::STATUS_SENDING, 'updated_at' => time()], ['id' => $messageId, 'status' => CmsCommMessage::STATUS_QUEUED])) {
            return [null, null];
        }

        // As CmsSmsProvider::send(), but without the web request it reads its IP from.
        $sms = new CmsSmsMessage([
            'cms_site_id' => (int)$message->cms_site_id,
            'cms_sms_provider_id' => (int)$provider->id,
            'phone' => $phone,
            'message' => (string)$message->body_text,
            'user_ip' => $userIp !== '' ? $userIp : '127.0.0.1',
        ]);
        try {
            $handler->sendMessage($sms);
            $sms->status = CmsSmsMessage::STATUS_NEW;
        } catch (\Throwable $e) {
            $sms->status = CmsSmsMessage::STATUS_ERROR;
            $sms->error_message = mb_substr($e->getMessage(), 0, 1000);
        }
        $sms->save(false);
        CmsCommMessage::updateAll(['provider_ref' => (string)$sms->id], ['id' => $messageId]);

        return $sms->status === CmsSmsMessage::STATUS_ERROR
            ? self::finish($messageId, CmsCommMessage::STATUS_FAILED, (string)$sms->error_message)
            : self::finish($messageId, CmsCommMessage::STATUS_SENT, null);
    }

    private static function finish(int $id, string $status, ?string $error): array
    {
        $attributes = ['status' => $status, 'error' => $error !== null ? mb_substr($error, 0, 1000) : null, 'updated_at' => time()];
        if ($status === CmsCommMessage::STATUS_SENT) {
            $attributes['message_at'] = time();
        }
        CmsCommMessage::updateAll($attributes, ['id' => $id]);

        return [$status, $error];
    }
}
