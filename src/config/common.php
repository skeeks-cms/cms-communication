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
            // "Отправить клиенту": messenger drivers add their channels here.
            'channels' => [
                'email' => \skeeks\cms\communication\send\EmailSendChannel::class,
                'sms' => \skeeks\cms\communication\send\SmsSendChannel::class,
            ],
            // Mailing campaigns: mailing service drivers add their channels here.
            'campaignChannels' => [
                'mailbox' => \skeeks\cms\communication\campaign\MailboxCampaignChannel::class,
            ],
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
            // An SMS of "Отправить клиенту" handed to the SMS provider of the site (SmsSendJobHandler).
            'comm.sms.send' => [
                'type' => 'comm.sms.send',
                'title' => 'Отправка СМС',
                'handler' => \skeeks\cms\communication\jobs\SmsSendJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'visible',
                'retentionDays' => 30,
                'timeout' => 60,
                'leaseSeconds' => 120,
                // An external send with an unknown outcome is never retried.
                'maxAttempts' => 1,
                'idempotent' => false,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn($payload) => 'comm.sms.send:'.(int)($payload['messageId'] ?? 0),
            ],
            // Moves and labels asked for in the CRM, done on the server (MailApplyJobHandler).
            // The run reads waiting copies when it starts: "coalesce" keeps one waiting run beside
            // a running one, so a request made while it runs is not lost.
            'comm.mail.apply' => [
                'type' => 'comm.mail.apply',
                'title' => 'Действия с письмами в почтовом ящике',
                'handler' => \skeeks\cms\communication\jobs\MailApplyJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 600,
                'leaseSeconds' => 120,
                'maxAttempts' => 1,
                // Repeating it moves nothing twice: a letter already moved is not in its old folder.
                'idempotent' => true,
                'overlapPolicy' => 'coalesce',
                'resourceKey' => static fn($payload) => 'comm-connection:'.(int)($payload['connectionId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.mail.apply:'.(int)($payload['connectionId'] ?? 0),
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
            // A large import of mailing recipients into leads (LeadImportJobHandler); small ones run at once.
            'comm.import.leads' => [
                'type' => 'comm.import.leads',
                'title' => 'Импорт получателей рассылок в лиды',
                'handler' => \skeeks\cms\communication\jobs\LeadImportJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'visible',
                'retentionDays' => 90,
                'timeout' => 3600,
                'leaseSeconds' => 300,
                'maxAttempts' => 1,
                // Rows already done are skipped and every lead has a stable source_ref: nothing doubles.
                'idempotent' => true,
                'cancellable' => true,
                'overlapPolicy' => 'skip',
                'resourceKey' => static fn($payload) => 'comm-import:'.(int)($payload['importId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.import.leads:'.(int)($payload['importId'] ?? 0),
            ],
            // Campaign letters of one mailbox at its pace (CampaignMailboxJobHandler): one letter per step,
            // then the same run waits in the queue a random pause or until the next working period.
            'comm.campaign.mailbox' => [
                'type' => 'comm.campaign.mailbox',
                'title' => 'Рассылка из почтового ящика',
                'handler' => \skeeks\cms\communication\jobs\CampaignMailboxJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 300,
                'leaseSeconds' => 180,
                'maxAttempts' => 1,
                // A step claims its recipient in the transaction of the letter: a repeated step sends nothing twice.
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'resourceKey' => static fn($payload) => 'comm-campaign-mailbox:'.(int)($payload['accountId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.campaign.mailbox:'.(int)($payload['accountId'] ?? 0),
            ],
            'comm.campaign.tick' => [
                'type' => 'comm.campaign.tick',
                'title' => 'Рассылки: продолжить идущие кампании',
                'handler' => \skeeks\cms\communication\jobs\CampaignTickJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 120,
                'leaseSeconds' => 120,
                'maxAttempts' => 1,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn() => 'comm.campaign.tick',
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

        // "Рассылки": creating and launching campaigns is a right of its own.
        'authManager' => [
            'config' => [
                'roles' => [
                    [
                        'name' => \skeeks\cms\rbac\CmsManager::ROLE_ADMIN,
                        'child' => ['permissions' => ['cmsCommunication/admin-cms-comm-campaign']],
                    ],
                ],
                'permissions' => [
                    [
                        'name' => 'cmsCommunication/admin-cms-comm-campaign',
                        'description' => 'Рассылки: создавать и запускать кампании',
                    ],
                ],
            ],
        ],

        'cmsAgent' => [
            'jobs' => [
                'comm.mail.poll-all' => [
                    'jobType' => 'comm.mail.poll-all',
                    'name' => 'Приём почты из подключённых ящиков',
                    'interval' => 120,
                ],
                'comm.campaign.tick' => [
                    'jobType' => 'comm.campaign.tick',
                    'name' => 'Рассылки: продолжить идущие кампании',
                    'interval' => 300,
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

    // Read tools of mail and mailings for skeeks/cms-mcp (inert without it): mcp\CommunicationReadToolProvider.
    'params' => ['cmsMcp' => [
        'toolProviders' => [
            'communication' => \skeeks\cms\communication\mcp\CommunicationReadToolProvider::class,
            // AI actions: drafts and campaign letters prepared for people to send (never sent or launched here).
            'communication-actions' => \skeeks\cms\communication\mcp\CommunicationActionToolProvider::class,
        ],
        'historyLogDescribers' => [\skeeks\cms\communication\models\CmsCommMessage::LOG_TYPE => \skeeks\cms\communication\mcp\MessageHistoryDescriber::class],
        'historySources' => ['mailing' => \skeeks\cms\communication\mcp\MailingHistorySource::class],
        'overviewContributors' => ['mailing' => \skeeks\cms\communication\mcp\MailingOverviewContributor::class],
        // The personal opt-out link of a letter is never shown to API clients: opening it unsubscribes.
        'personalLinkPatterns' => ['unsubscribe' => '~/\~unsub/~'],
    ]],
];
