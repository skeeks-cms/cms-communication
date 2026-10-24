<?php

return [
    'controllerMap' => [
        'migrate' => [
            'migrationPath' => [
                '@skeeks/cms/communication/migrations',
            ],
        ],
        // Bots: state, subscribing, importing the chats a bot already is in (console\BotController).
        'comm-bot' => \skeeks\cms\communication\console\BotController::class,
    ],
];
