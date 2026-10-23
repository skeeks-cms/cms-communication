<?php

return [
    'bootstrap' => [\skeeks\cms\communication\CommunicationBootstrap::class],

    'components' => [
        // The opt-out page of mailings and the one-click opt-out of mail programs (controllers\UnsubscribeController).
        'urlManager' => [
            'rules' => [
                '~unsub/<code:[A-Za-z0-9_-]{16,64}>' => 'cmsCommunication/unsubscribe/view',
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
                    // Beside "Почта": campaigns are letters from the same mailboxes.
                    'mailing' => [
                        'label' => 'Рассылки',
                        'priority' => 186,
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
