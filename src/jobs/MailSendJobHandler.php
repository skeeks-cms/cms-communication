<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\mail\MailDriverInterface;
use skeeks\cms\communication\mail\MailSendException;
use skeeks\cms\communication\mail\MailingServiceInterface;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\Unsubscribe;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobPermanentException;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * Sends one queued letter through the SMTP of its mailbox and stores a copy
 * in the Sent folder unless the server already did. The status moves
 * queued → sending before the network call; a broken connection after that
 * leaves it `unknown`, and such a letter is never resent automatically.
 * A mailing service that refused for now (MailSendException::$retryAfter,
 * nothing was taken) gets the letter again after its pause, for a few hours;
 * such a driver keeps no "Sent" folder (mail\MailingServiceInterface).
 */
class MailSendJobHandler extends AbstractJobHandler
{
    /** How long a letter refused "for now" by a mailing service keeps waiting. */
    public const RETRY_HOURS = 6;

    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $id = (int)$context->get('messageId');
        $message = CmsCommMessage::findOne($id);
        if (!$message || $message->status !== CmsCommMessage::STATUS_QUEUED) {
            return;
        }
        $reporter->setTotal(1);
        $account = $message->account;
        $connection = $account ? $account->connection : null;
        if (!$connection || !$connection->is_active) {
            $this->finish($id, CmsCommMessage::STATUS_FAILED, 'У ящика нет активного подключения.');
            throw new JobPermanentException('У ящика нет активного подключения.');
        }

        $component = \Yii::$app->communication;
        foreach (array_keys($message->getTo() + $message->getCc() + $message->getBcc()) as $address) {
            if (!$component->isRecipientAllowed($address)) {
                $this->finish($id, CmsCommMessage::STATUS_FAILED, "Получатель {$address} не разрешён настройкой allowedRecipients.");
                throw new JobPermanentException('Получатель не разрешён настройкой allowedRecipients.');
            }
        }

        $driver = $connection->createDriver(true);
        if (!$driver instanceof MailDriverInterface) {
            $this->finish($id, CmsCommMessage::STATUS_FAILED, 'Драйвер подключения не умеет отправлять почту.');
            throw new JobPermanentException('Драйвер подключения не умеет отправлять почту.');
        }

        // A letter never leaves without one of its files.
        foreach ($message->files as $file) {
            $path = $file->storageFile ? $file->storageFile->rootSrc : null;
            if (!$path || !is_readable($path)) {
                $this->finish($id, CmsCommMessage::STATUS_FAILED, "Файл «{$file->name}» не найден в хранилище.");
                throw new JobPermanentException('Файл письма не найден в хранилище.');
            }
        }

        // Outcome marker: from here on a crash leaves the letter visibly uncertain.
        if (!CmsCommMessage::updateAll(['status' => CmsCommMessage::STATUS_SENDING, 'updated_at' => time()], ['id' => $id, 'status' => CmsCommMessage::STATUS_QUEUED])) {
            return;
        }

        $email = $this->buildEmail($message);
        $reporter->setStage('send', 'Отправка письма');
        try {
            $driver->send($email);
        } catch (MailSendException $e) {
            if ($e->retryAfter && (int)$message->created_at > time() - self::RETRY_HOURS * 3600) {
                // Nothing was taken: the letter waits in the queue, the same run comes back.
                CmsCommMessage::updateAll(['status' => CmsCommMessage::STATUS_QUEUED, 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => time()],
                    ['id' => $id, 'status' => CmsCommMessage::STATUS_SENDING]);
                $retry = $e->retryAfter;
            } else {
                $status = $e->uncertain ? CmsCommMessage::STATUS_UNKNOWN : CmsCommMessage::STATUS_FAILED;
                $this->finish($id, $status, $e->getMessage());
                $reporter->countError();
                throw new JobPermanentException('Письмо не отправлено: '.$e->getMessage());
            }
        } catch (\Throwable $e) {
            $this->finish($id, CmsCommMessage::STATUS_UNKNOWN, 'Неизвестно, отправлено ли письмо: '.mb_substr($e->getMessage(), 0, 300));
            throw new JobPermanentException('Неизвестно, отправлено ли письмо.');
        }

        if (isset($retry)) {
            $reporter->setStage('wait', 'Сервис просит подождать: повтор через '.$retry.' с');
            // Outside the try: the status is committed already.
            $context->requestRequeue($retry);
        }

        $this->finish($id, CmsCommMessage::STATUS_SENT, null, time());
        $reporter->countSuccess();
        $reporter->advance();
        if ($driver instanceof MailingServiceInterface) {
            // No folders at a mailing service: the CRM row is the copy.
            $reporter->setResult(['messageId' => $id, 'status' => CmsCommMessage::STATUS_SENT]);
            $reporter->setStage('complete', 'Письмо передано сервису рассылок');
            return;
        }

        $reporter->setStage('sent-folder', 'Копия в «Отправленные»');
        // Some servers save letters sent over SMTP into "Sent" themselves, a moment later.
        sleep(3);
        try {
            $session = $driver->openSession();
            try {
                $sent = null;
                foreach ($session->folders() as $folder) {
                    if ($folder['role'] === 'sent') {
                        $sent = $folder['name'];
                        break;
                    }
                }
                if ($sent !== null && !$session->hasMessageId($sent, $message->external_id)) {
                    $session->append($sent, self::withBcc($email->toString(), array_keys($message->getBcc())));
                }
            } finally {
                $session->close();
            }
        } catch (\Throwable $e) {
            // The letter is sent; only the server copy is missing.
            $reporter->warning('Письмо отправлено, но копия в «Отправленные» не сохранена: '.mb_substr($e->getMessage(), 0, 300));
        }

        $reporter->setResult(['messageId' => $id, 'status' => CmsCommMessage::STATUS_SENT]);
        $reporter->setStage('complete', 'Письмо отправлено');
    }

    /**
     * The sender's copy for "Отправленные" shows the blind copy like mail
     * clients do. The rendered letter has no Bcc header (recipients never see
     * it), so it is added to this copy only.
     */
    public static function withBcc(string $raw, array $bcc): string
    {
        if (!$bcc || preg_match('/^Bcc:/mi', (string)strstr($raw, "\r\n\r\n", true))) {
            return $raw;
        }
        $bcc = array_filter($bcc, static fn($a) => (bool)filter_var($a, FILTER_VALIDATE_EMAIL));

        return $bcc ? 'Bcc: '.implode(', ', $bcc)."\r\n".$raw : $raw;
    }

    /** The letter as it leaves (also read by tests: headers of advertising letters). */
    public function buildEmail(CmsCommMessage $message): Email
    {
        $account = $message->account;
        $email = (new Email())
            ->from(new Address((string)$account->address, (string)$account->name))
            ->subject((string)$message->subject)
            ->text((string)$message->body_text)
            ->html($message->body_html ?: '<div style="white-space:pre-wrap;font-family:Arial,sans-serif;font-size:14px">'.htmlspecialchars((string)$message->body_text, ENT_QUOTES, 'UTF-8').'</div>')
            ->date(new \DateTimeImmutable('@'.(int)$message->message_at));
        foreach ($message->getTo() as $address => $name) {
            $email->addTo(new Address((string)$address, (string)$name));
        }
        foreach ($message->getBcc() as $address => $name) {
            $email->addBcc(new Address((string)$address, (string)$name));
        }
        foreach ($message->getCc() as $address => $name) {
            $email->addCc(new Address((string)$address, (string)$name));
        }
        if ($account->reply_to) {
            $email->replyTo((string)$account->reply_to);
        }
        foreach ($message->files as $file) {
            $part = new DataPart(new File($file->storageFile->rootSrc), $file->name, $file->mime_type ?: null);
            if ($file->is_inline && $file->content_id) {
                // The text refers to it as cid:<content_id>.
                $part->asInline()->setContentId($file->content_id);
            }
            $email->addPart($part);
        }
        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $message->external_id);
        if ($message->in_reply_to) {
            $headers->addIdHeader('In-Reply-To', $message->in_reply_to);
        }
        if ($message->references_list) {
            $headers->addIdHeader('References', array_values(array_filter(explode(' ', $message->references_list))));
        }
        // An advertising letter: the opt-out of mail programs, in one click (RFC 2369, RFC 8058).
        if ($message->hasAttribute('unsubscribe_url') && $message->unsubscribe_url) {
            foreach (Unsubscribe::headers((string)$message->unsubscribe_url) as $name => $value) {
                $headers->addTextHeader($name, $value);
            }
        }

        return $email;
    }

    private function finish(int $id, string $status, ?string $error, ?int $sentAt = null): void
    {
        $attributes = ['status' => $status, 'error' => $error !== null ? mb_substr($error, 0, 1000) : null, 'updated_at' => time()];
        if ($sentAt) {
            $attributes['message_at'] = $sentAt;
        }
        CmsCommMessage::updateAll($attributes, ['id' => $id]);
    }
}
