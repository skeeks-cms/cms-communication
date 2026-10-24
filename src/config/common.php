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
            // "Отправить клиенту": one channel for chats of every messenger bot (drivers add none).
            'channels' => [
                'email' => \skeeks\cms\communication\send\EmailSendChannel::class,
                'sms' => \skeeks\cms\communication\send\SmsSendChannel::class,
                'chat' => \skeeks\cms\communication\send\ChatSendChannel::class,
            ],
            // Mailing campaigns: mailing service drivers add their channels here.
            'campaignChannels' => [
                'mailbox' => \skeeks\cms\communication\campaign\MailboxCampaignChannel::class,
            ],
            // Bot events after they are stored, in this order: who the person is (invitations,
            // their own phone, chats of a linked person), tasks asked for by "/task", then notifications
            // about new messages.
            'botHandlers' => [
                'identity' => \skeeks\cms\communication\chat\ChatIdentityHandler::class,
                'tasks' => \skeeks\cms\communication\chat\ChatTasks::class,
                'notify' => \skeeks\cms\communication\chat\ChatNotifier::class,
            ],
        ],

        // Letters in the activity feeds of leads, companies and clients.
        'skeeks' => [
            // Group chats of bots: one summary per chat and day (chat\ChatFeed).
            'logTypes' => [
                \skeeks\cms\communication\models\CmsCommMessage::LOG_TYPE => 'Письмо',
                \skeeks\cms\communication\chat\ChatFeed::LOG_DIGEST => 'Сводка чата',
                // Visits of our sites by links of letters, with the pages of the visit (services\LinkVisits).
                \skeeks\cms\communication\services\LinkVisits::LOG_TYPE => 'Переход на сайт из письма',
            ],
            'logRenderers' => [
                \skeeks\cms\communication\models\CmsCommMessage::LOG_TYPE => \skeeks\cms\communication\log\MessageLogRenderer::class,
                \skeeks\cms\communication\chat\ChatFeed::LOG_DIGEST => \skeeks\cms\communication\log\ChatDigestLogRenderer::class,
                \skeeks\cms\communication\services\LinkVisits::LOG_TYPE => \skeeks\cms\communication\log\VisitLogRenderer::class,
            ],
            'activityLogTypes' => [\skeeks\cms\communication\models\CmsCommMessage::LOG_TYPE, \skeeks\cms\communication\chat\ChatFeed::LOG_DIGEST,
                \skeeks\cms\communication\services\LinkVisits::LOG_TYPE],
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
            // Lists of recipients fill themselves by their rules (saved filters of CRM sections).
            'comm.list.rules' => [
                'type' => 'comm.list.rules',
                'title' => 'Рассылки: пополнить списки по правилам',
                'handler' => \skeeks\cms\communication\jobs\ListRulesJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 600,
                'leaseSeconds' => 300,
                'maxAttempts' => 1,
                // A rule adds only records that never were in the list: running twice adds nothing twice.
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn() => 'comm.list.rules',
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

            // Bots (bot\*). Updates stored by the webhook or a poll are applied in order, one bot at a time.
            'comm.bot.apply' => [
                'type' => 'comm.bot.apply',
                'title' => 'Бот: обработка сообщений',
                'handler' => \skeeks\cms\communication\jobs\BotApplyJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 120,
                'leaseSeconds' => 120,
                'maxAttempts' => 1,
                // An applied update is not applied again; a message is unique per chat.
                'idempotent' => true,
                'overlapPolicy' => 'coalesce',
                'resourceKey' => static fn($payload) => 'comm-bot:'.(int)($payload['connectionId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.bot.apply:'.(int)($payload['connectionId'] ?? 0),
            ],
            'comm.bot.poll-all' => [
                'type' => 'comm.bot.poll-all',
                'title' => 'Боты: опрос мессенджеров',
                'handler' => \skeeks\cms\communication\jobs\BotPollAllJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 60,
                'leaseSeconds' => 60,
                'maxAttempts' => 1,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn() => 'comm.bot.poll-all',
            ],
            // Poll mode only (a site without public HTTPS). Its own lock: applying goes on beside it.
            'comm.bot.poll' => [
                'type' => 'comm.bot.poll',
                'title' => 'Бот: получение сообщений опросом',
                'handler' => \skeeks\cms\communication\jobs\BotPollJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 120,
                'leaseSeconds' => 120,
                'maxAttempts' => 1,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'resourceKey' => static fn($payload) => 'comm-bot-poll:'.(int)($payload['connectionId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.bot.poll:'.(int)($payload['connectionId'] ?? 0),
            ],
            'comm.bot.send' => [
                'type' => 'comm.bot.send',
                'title' => 'Бот: отправка сообщения',
                'handler' => \skeeks\cms\communication\jobs\BotSendJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'visible',
                'retentionDays' => 30,
                'timeout' => 300,
                'leaseSeconds' => 360,
                // An external send with an unknown outcome is never retried; a wait asked by the messenger is a requeue.
                'maxAttempts' => 1,
                'idempotent' => false,
                'overlapPolicy' => 'skip',
                // Messages of one bot leave one after another: the messenger's pace per chat holds.
                'resourceKey' => static fn($payload) => 'comm-bot-send:'.(int)($payload['connectionId'] ?? 0),
                'dedupKey' => static fn($payload) => 'comm.bot.send:'.(int)($payload['messageId'] ?? 0),
            ],
            'comm.bot.fetch-file' => [
                'type' => 'comm.bot.fetch-file',
                'title' => 'Бот: загрузка файлов сообщения',
                'handler' => \skeeks\cms\communication\jobs\BotFetchFileJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 300,
                'leaseSeconds' => 120,
                'maxAttempts' => 3,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn($payload) => 'comm.bot.fetch-file:'.(int)($payload['messageId'] ?? 0),
            ],
            // The title and the picture of a chat (a group icon, the person's photo), for a new chat and weekly.
            'comm.bot.chat-info' => [
                'type' => 'comm.bot.chat-info',
                'title' => 'Бот: фото и название чата',
                'handler' => \skeeks\cms\communication\jobs\BotChatInfoJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 120,
                'leaseSeconds' => 120,
                'maxAttempts' => 2,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn($payload) => 'comm.bot.chat-info:'.(int)($payload['conversationId'] ?? 0),
            ],
            'comm.bot.maintain' => [
                'type' => 'comm.bot.maintain',
                'title' => 'Боты: проверка подписки и журнала',
                'handler' => \skeeks\cms\communication\jobs\BotMaintainJobHandler::class,
                'queue' => 'communication',
                'visibility' => 'transient',
                'timeout' => 300,
                'leaseSeconds' => 120,
                'maxAttempts' => 1,
                'idempotent' => true,
                'overlapPolicy' => 'skip',
                'dedupKey' => static fn() => 'comm.bot.maintain',
            ],
        ]],

        // "Рассылки": creating and launching campaigns is a right of its own; so is managing mail
        // (mailboxes and "Настройки почты", AI limits included), given to administrators.
        'authManager' => [
            'config' => [
                'roles' => [
                    [
                        'name' => \skeeks\cms\rbac\CmsManager::ROLE_ADMIN,
                        'child' => ['permissions' => ['cmsCommunication/admin-cms-comm-campaign', 'cmsCommunication/admin-cms-comm-settings']],
                    ],
                ],
                'permissions' => [
                    [
                        'name' => 'cmsCommunication/admin-cms-comm-campaign',
                        'description' => 'Рассылки: создавать и запускать кампании',
                    ],
                    [
                        'name' => 'cmsCommunication/admin-cms-comm-settings',
                        'description' => 'Почта: управлять почтовыми ящиками и «Настройками почты» (в том числе работой ИИ через API)',
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
                'comm.list.rules' => [
                    'jobType' => 'comm.list.rules',
                    'name' => 'Рассылки: пополнить списки по правилам',
                    'interval' => 3600,
                ],
                // By the storage settings of each mailbox ("Хранить копии файлов, дней").
                'comm.mail.cleanup-files' => [
                    'jobType' => 'comm.mail.cleanup-files',
                    'jobPayload' => ['auto' => true],
                    'name' => 'Очистка старых файлов писем по настройкам хранения',
                    'interval' => 86400,
                ],
                // Bots in the poll mode; nothing to do for bots with a webhook.
                'comm.bot.poll-all' => [
                    'jobType' => 'comm.bot.poll-all',
                    'name' => 'Боты: опрос мессенджеров (для сайтов без HTTPS)',
                    'interval' => 60,
                ],
                'comm.bot.maintain' => [
                    'jobType' => 'comm.bot.maintain',
                    'name' => 'Боты: проверка подписки на сообщения и очистка журнала',
                    'interval' => 3600,
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
            // Chats of messenger bots: reading, a reply checked then sent after a person agreed, a task from a message.
            'communication-chats' => \skeeks\cms\communication\mcp\ChatToolProvider::class,
        ],
        'historyLogDescribers' => [
            \skeeks\cms\communication\models\CmsCommMessage::LOG_TYPE => \skeeks\cms\communication\mcp\MessageHistoryDescriber::class,
            \skeeks\cms\communication\services\LinkVisits::LOG_TYPE => \skeeks\cms\communication\mcp\VisitHistoryDescriber::class,
            \skeeks\cms\communication\chat\ChatFeed::LOG_DIGEST => \skeeks\cms\communication\mcp\ChatDigestHistoryDescriber::class,
        ],
        'historySources' => ['mailing' => \skeeks\cms\communication\mcp\MailingHistorySource::class],
        'overviewContributors' => ['mailing' => \skeeks\cms\communication\mcp\MailingOverviewContributor::class],
        // The personal opt-out link of a letter is never shown to API clients: opening it unsubscribes.
        // A link with the personal code of the recipient neither: an agent opening it would be the client's visit.
        'personalLinkPatterns' => ['unsubscribe' => '~/\~unsub/~', 'visit' => '~[?&]sx=[A-Za-z0-9_-]{16}(?:[&#]|$)~'],
    ]],
];
