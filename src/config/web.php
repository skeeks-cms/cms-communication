<?php

return [
    'bootstrap' => [\skeeks\cms\communication\CommunicationBootstrap::class],

    'components' => [
        // The opt-out page of mailings and the one-click opt-out of mail programs (controllers\UnsubscribeController).
        'urlManager' => [
            'rules' => [
                '~unsub/<code:[A-Za-z0-9_-]{16,64}>' => 'cmsCommunication/unsubscribe/view',
                // Webhooks of bots: the messenger posts updates here (controllers\BotWebhookController).
                'POST ~comm-bot/<id:\d+>' => 'cmsCommunication/bot-webhook/receive',
            ],
        ],
        'backendAdmin' => [
            'menu' => [
                'data' => [
                    'mail' => [
                        'label' => 'Почта',
                        'priority' => 185,
                        'img' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/mail.svg'],
                        'url' => ['cmsCommunication/admin-cms-comm-message/mail'],
                    ],
                    // Right under "Почта": dialogs and group chats of the bots (Telegram, MAX).
                    'chats' => [
                        'label' => 'Чаты',
                        'priority' => 186,
                        'img' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/sms.svg'],
                        'url' => ['cmsCommunication/admin-cms-comm-chat'],
                    ],
                    // Beside "Почта": campaigns are letters from the same mailboxes.
                    'mailing' => [
                        'label' => 'Рассылки',
                        'priority' => 187,
                        'img' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/marketing.svg'],
                        'url' => ['cmsCommunication/admin-cms-comm-campaign'],
                    ],
                    'settings' => [
                        'items' => [
                            [
                                'name' => 'Почтовые ящики',
                                'url' => ['cmsCommunication/admin-cms-comm-account'],
                                'image' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/mail.svg'],
                            ],
                            [
                                'name' => 'Настройки почты',
                                'url' => ['cmsCommunication/admin-cms-comm-settings'],
                                'image' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/mail.svg'],
                            ],
                            [
                                'name' => 'Боты мессенджеров',
                                'url' => ['cmsCommunication/admin-cms-comm-bot'],
                                'image' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/sms.svg'],
                            ],
                            [
                                'name' => 'Темы рассылок',
                                'url' => ['cmsCommunication/admin-cms-comm-topic'],
                                'image' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/marketing.svg'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'modules' => [
        'cmsCommunication' => [
            'class' => \skeeks\cms\communication\CommunicationModule::class,
        ],
    ],
];
