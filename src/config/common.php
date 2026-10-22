<?php
/**
 * Shared configuration of communications.
 *
 * Driver packages add themselves to components.communication.drivers.
 * The encryption key of connection secrets comes from protected project
 * configuration; Yii Config injects $params into config files only, so the key
 * is passed to the component and never copied into Yii::$app->params.
 */
$secretKey = $params['cms-communication']['secretKey'] ?? null;
if (!is_string($secretKey) || $secretKey === '') {
    $secretKey = getenv('CMS_COMMUNICATION_SECRET_KEY') ?: null;
}

return [
    'components' => [
        'communication' => [
            'class' => \skeeks\cms\communication\CommunicationComponent::class,
            'secretKey' => $secretKey,
        ],

        // Letters in the activity feeds of leads, companies and clients.
        'skeeks' => [
            'logTypes' => [\skeeks\cms\communication\models\CmsCommMessage::LOG_TYPE => 'Письмо'],
            'logRenderers' => [\skeeks\cms\communication\models\CmsCommMessage::LOG_TYPE => \skeeks\cms\communication\log\MessageLogRenderer::class],
            'activityLogTypes' => [\skeeks\cms\communication\models\CmsCommMessage::LOG_TYPE],
            // "Создать задачу / лид / сделку" from a letter: ?source=message:ID in the standard create window.
            'recordSources' => [\skeeks\cms\communication\source\MessageRecordSource::TYPE => \skeeks\cms\communication\source\MessageRecordSource::class],
        ],

        'jobQueueFactory' => ['queues' => ['communication' => []]],
        'jobRegistry' => ['types' => [
            // Scheduled entry point: one child job per mail connection.
            'comm.mail.poll-all' => [
                'type' => 'comm.mail.poll-all',
                'title' => 'Приём почты: все ящики',
                'handler' => \skeeks\cms\communication\jobs\MailPollAllJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 60,
                'leaseSeconds' => 60,
                'maxAttempts' => 1,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn() => 'comm.mail.poll-all',
            ],
            'comm.mail.poll' => [
                'type' => 'comm.mail.poll',
                'title' => 'Приём почты',
                'handler' => \skeeks\cms\communication\jobs\MailPollJobHandler::class,
                'queue' => 'communication',
                // Successful runs are routine; failures stay in the history.
                'visibility' => 'transient',
                'timeout' => 900,
                'leaseSeconds' => 120,
                'maxAttempts' => 1,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'resourceKey' => static fn($payload) => 'comm-connection:'.(int)($payload['connectionId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.mail.poll:'.(int)($payload['connectionId'] ?? 0),
            ],
            // "Сбор писем" of a mailbox for a chosen period. Works in slices and frees the
            // mailbox between them, so receiving new letters goes on (MailHistoryJobHandler).
            'comm.mail.history' => [
                'type' => 'comm.mail.history',
                'title' => 'Сбор писем из почтового ящика',
                'handler' => \skeeks\cms\communication\jobs\MailHistoryJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'visible',
                'retentionDays' => 90,
                'timeout' => 600,
                'leaseSeconds' => 180,
                'maxAttempts' => 1,
                'idempotent' => true,
                'cancellable' => true,
                'overlapPolicy' => 'skip',
                'resourceKey' => static fn($payload) => 'comm-connection:'.(int)($payload['connectionId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.mail.history:'.(int)($payload['connectionId'] ?? 0),
            ],
            'comm.mail.send' => [
                'type' => 'comm.mail.send',
                'title' => 'Отправка письма',
                'handler' => \skeeks\cms\communication\jobs\MailSendJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'visible',
                'retentionDays' => 30,
                'timeout' => 120,
                'leaseSeconds' => 180,
                // An external send with an unknown outcome is never retried.
                'maxAttempts' => 1,
                'idempotent' => false,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn($payload) => 'comm.mail.send:'.(int)($payload['messageId'] ?? 0),
            ],
            // A letter file not kept in the CRM, fetched from the mailbox when someone opens it.
            'comm.mail.fetch-files' => [
                'type' => 'comm.mail.fetch-files',
                'title' => 'Загрузка файлов письма из почтового ящика',
                'handler' => \skeeks\cms\communication\jobs\MailFetchFilesJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 300,
                'leaseSeconds' => 120,
                'maxAttempts' => 1,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn($payload) => 'comm.mail.fetch:'.(int)($payload['messageId'] ?? 0).':'.(string)($payload['kind'] ?? 'all'),
            ],
            // "Сохранить файлы в CRM": keeps files of earlier letters for good (MailSaveFilesJobHandler).
            // Reads mailboxes only, so it takes no mailbox lock: receiving goes on beside it.
            'comm.mail.save-files' => [
                'type' => 'comm.mail.save-files',
                'title' => 'Сохранение файлов писем в CRM',
                'handler' => \skeeks\cms\communication\jobs\MailSaveFilesJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'visible',
                'retentionDays' => 90,
                'timeout' => 600,
                'leaseSeconds' => 180,
                'maxAttempts' => 1,
                'idempotent' => true,
                'cancellable' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn($payload) => 'comm.mail.save-files:'.(int)($payload['siteId'] ?? 0),
            ],
            // Removes CRM copies of letter files that the mailbox still has.
            'comm.mail.cleanup-files' => [
                'type' => 'comm.mail.cleanup-files',
                'title' => 'Очистка файлов писем',
                'handler' => \skeeks\cms\communication\jobs\MailCleanupFilesJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'visible',
                'retentionDays' => 30,
                'timeout' => 3600,
                'leaseSeconds' => 300,
                'maxAttempts' => 1,
                'idempotent' => true,
                'cancellable' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn() => 'comm.mail.cleanup-files',
            ],
        ]],

        'cmsAgent' => [
            'jobs' => [
                'comm.mail.poll-all' => [
                    'jobType' => 'comm.mail.poll-all',
                    'name' => 'Приём почты из подключённых ящиков',
                    'interval' => 120,
                ],
                // By the storage settings of each mailbox ("Хранить копии файлов, дней").
                'comm.mail.cleanup-files' => [
                    'jobType' => 'comm.mail.cleanup-files',
                    'jobPayload' => ['auto' => true],
                    'name' => 'Очистка старых файлов писем по настройкам хранения',
                    'interval' => 86400,
                ],
            ],
        ],
    ],
];
