<?php

return [
    'bootstrap' => [\skeeks\cms\communication\CommunicationBootstrap::class],

    'components' => [
        'backendAdmin' => [
            'menu' => [
                'data' => [
                    'mail' => [
                        'label' => 'Почта',
                        'priority' => 185,
                        'img' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/mail.svg'],
                        'url' => ['cmsCommunication/admin-cms-comm-message/mail'],
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
