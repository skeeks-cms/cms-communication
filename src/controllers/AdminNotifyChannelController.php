<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\BackendController;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\notify\MessengerNotifyChannel;
use skeeks\cms\communication\notify\NotifyDeliveries;
use skeeks\cms\communication\notify\NotifyLinks;
use skeeks\cms\rbac\CmsManager;
use yii\filters\VerbFilter;

/**
 * Buttons of a messenger channel on the personal notification settings page:
 * connect (a one-time link to the bot), switch off and on, send a test. The
 * person acts only on their own channel; every action returns to the page.
 */
class AdminNotifyChannelController extends BackendController
{
    public function getPermissionName()
    {
        return CmsManager::PERMISSION_ADMIN_ACCESS;
    }

    public function init()
    {
        $this->name = 'Каналы уведомлений';
        parent::init();
    }

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['*' => ['post']]],
        ]);
    }

    public function actionConnect()
    {
        $channel = $this->messenger();
        $connection = CmsCommConnection::findOne((int)\Yii::$app->request->post('connection'));
        $allowed = $connection && in_array((int)$connection->id, array_map(static fn($b) => (int)$b->id, $channel->bots()), true);
        if (!$allowed) {
            return $this->back('error', 'Бот недоступен.');
        }
        $link = NotifyLinks::connect($this->userId(), $connection);
        if (!$link['url']) {
            return $this->back('error', 'У бота не указано имя: ссылку на него не составить. Заполните имя бота в его карточке.');
        }

        return $this->back('success', 'Откройте '.$channel->name.' по кнопке и нажмите «Старт».');
    }

    public function actionDisconnect()
    {
        $channel = $this->messenger();
        NotifyLinks::setActive($this->userId(), (string)$channel->code, false);

        return $this->back('success', $channel->name.': уведомления больше не приходят. Включить снова можно здесь же.');
    }

    public function actionEnable()
    {
        $channel = $this->messenger();
        NotifyLinks::setActive($this->userId(), (string)$channel->code, true);

        return $this->back('success', $channel->name.' снова включён.');
    }

    /** A test notification straight to this channel, whatever the table says. */
    public function actionTest()
    {
        $channel = $this->messenger();
        if (!$channel->isConnected($this->userId())) {
            return $this->back('error', $channel->name.' не подключён.');
        }
        $notifications = \Yii::$app->notifications;
        $rows = $notifications->notify($this->userId(), 'notify.test', [
            'name' => 'Проверка: уведомления CRM приходят в '.$channel->name,
            'eventKey' => 'notify.test:'.$channel->code.':'.time(),
        ]);
        if (!$rows) {
            return $this->back('error', 'Не удалось создать проверочное уведомление.');
        }
        NotifyDeliveries::queueTest($rows[0], (string)$channel->code);

        return $this->back('success', 'Проверочное уведомление отправлено в '.$channel->name.'. Итог — в журнале доставки ниже.');
    }

    private function messenger(): MessengerNotifyChannel
    {
        $channel = \Yii::$app->notifications->channel((string)\Yii::$app->request->post('channel'));
        if (!$channel instanceof MessengerNotifyChannel) {
            throw new \yii\web\NotFoundHttpException('Канал не найден.');
        }

        return $channel;
    }

    private function userId(): int
    {
        return (int)\Yii::$app->user->id;
    }

    private function back(string $type, string $message)
    {
        \Yii::$app->session->setFlash($type, $message);

        return $this->redirect(['/cms/admin-profile/notifications']);
    }
}
