<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotUpdates;
use skeeks\cms\communication\models\CmsCommConnection;
use yii\web\Response;

/**
 * Webhook of a bot: `POST /~comm-bot/<connection id>`. Checks the secret the
 * messenger sends in its header, stores the raw update (bot\BotUpdates) and
 * answers 200 at once; applying is the job comm.bot.apply. A failure to
 * store answers 500, and the messenger delivers the update again (a repeat
 * is not written twice). No session, no CSRF: the secret is the access.
 */
class BotWebhookController extends \yii\web\Controller
{
    public $enableCsrfValidation = false;

    public function actionReceive($id)
    {
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $request = \Yii::$app->request;
        if (!$request->isPost) {
            $response->statusCode = 405;
            return ['ok' => false];
        }
        $connection = CmsCommConnection::findOne((int)$id);
        if (!$connection || !Bots::isBot($connection) || !Bots::canReceive()) {
            $response->statusCode = 404;
            return ['ok' => false];
        }
        try {
            $driver = Bots::driver($connection);
        } catch (\Throwable $e) {
            \Yii::error('Вебхук бота #'.(int)$connection->id.': настройки не читаются: '.$e->getMessage(), self::class);
            $response->statusCode = 500;
            return ['ok' => false];
        }
        $expected = (string)$driver->webhookSecret;
        $given = (string)$request->headers->get($driver->webhookSecretHeader(), '');
        if ($expected === '' || !hash_equals($expected, $given)) {
            $response->statusCode = 403;
            return ['ok' => false];
        }
        $update = json_decode((string)$request->getRawBody(), true);
        if (!is_array($update)) {
            $response->statusCode = 400;
            return ['ok' => false];
        }
        try {
            BotUpdates::store($connection, $driver, [$update]);
        } catch (\Throwable $e) {
            \Yii::error('Вебхук бота #'.(int)$connection->id.': событие не записано: '.$e->getMessage(), self::class);
            $response->statusCode = 500;
            return ['ok' => false];
        }

        return ['ok' => true];
    }
}
