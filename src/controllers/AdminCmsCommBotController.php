<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\actions\BackendGridModelRelatedAction;
use skeeks\cms\backend\actions\BackendModelAction;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\bot\BotSubscriptions;
use skeeks\cms\communication\bot\MessengerDriver;
use skeeks\cms\communication\models\CmsCommBotUpdate;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\grid\BooleanColumn;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * "Боты мессенджеров": a bot is a connection with a messenger driver. The
 * form keeps the token write-only (as mailbox passwords). After saving, the
 * bot is checked in the messenger (its id and name are stored), gets its
 * account (employee rights, as for a mailbox) and its webhook subscription;
 * a site that must not receive (a stand) never touches the subscription.
 */
class AdminCmsCommBotController extends AdminCmsCommConnectionController
{
    public function init()
    {
        parent::init();
        $this->name = 'Боты мессенджеров';
    }

    public function actions()
    {
        $actions = parent::actions();
        unset($actions['poll']);
        $drivers = array_keys(Bots::drivers());

        $afterSave = function (Event $e) {
            /** @var CmsCommConnection $model */
            $model = $e->sender->model;
            $this->afterBotSave($model);
        };

        $result = ArrayHelper::merge($actions, [
            'index' => [
                'emptyState' => [
                    'title' => 'Ботов пока нет',
                    'description' => $drivers
                        ? 'Подключите бота Telegram или MAX: сообщения клиентов из личных диалогов и рабочих чатов будут приходить в раздел «Чаты», отвечать можно из CRM.'
                        : 'Не установлен ни один драйвер мессенджера (skeeks/cms-communication-max, skeeks/cms-communication-telegram).',
                    'icon' => 'far fa-comments',
                    'action' => $drivers ? ['backendAction' => 'create', 'label' => 'Подключить бота'] : null,
                ],
                'grid' => [
                    'on init' => static function (Event $event) use ($drivers) {
                        $event->sender->dataProvider->query->cmsSite()->andWhere(['driver' => $drivers ?: ['']]);
                    },
                    'columns' => [
                        'name' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => '/cmsCommunication/admin-cms-comm-bot',
                            'attribute' => 'name',
                        ],
                        'bot' => [
                            'label' => 'Бот',
                            'value' => static function (CmsCommConnection $model) {
                                $config = $model->getPublicConfig();
                                return !empty($config['botUsername']) ? '@'.$config['botUsername'] : '— не проверен';
                            },
                        ],
                        'chats' => [
                            'label' => 'Чатов',
                            'value' => static fn(CmsCommConnection $model) => (int)CmsCommConversation::find()->andWhere(['cms_comm_connection_id' => (int)$model->id])->count(),
                        ],
                        'is_active' => ['class' => BooleanColumn::class, 'trueValue' => 1, 'falseValue' => 0],
                    ],
                ],
            ],
            'create' => ['on afterSave' => $afterSave],
            'update' => ['on afterSave' => $afterSave],
            'check' => [
                'name' => 'Проверить бота',
                'icon' => 'fas fa-plug',
            ],
            'access' => [
                'class' => BackendGridModelRelatedAction::class,
                'name' => 'Доступ сотрудников',
                'icon' => 'fas fa-user-lock',
                'priority' => 200,
                'controllerRoute' => '/cmsCommunication/admin-cms-comm-account-access',
                'relation' => ['cms_comm_account_id' => 'botAccountId'],
                'on gridInit' => static function (Event $e) {
                    $action = $e->sender;
                    $action->relatedIndexAction->backendShowings = false;
                    $action->relatedIndexAction->filters = false;
                    $visibleColumns = $action->relatedIndexAction->grid['visibleColumns'];
                    ArrayHelper::removeValue($visibleColumns, 'cms_comm_account_id');
                    $action->relatedIndexAction->grid['visibleColumns'] = $visibleColumns;
                },
            ],
            'journal' => [
                'class' => BackendModelAction::class,
                'name' => 'Журнал событий',
                'icon' => 'fas fa-list',
                'priority' => 220,
                'callback' => [$this, 'journal'],
            ],
        ]);
        // Lists are replaced, not merged (merging would append them to the mailbox connection ones).
        $result['index']['grid']['visibleColumns'] = ['checkbox', 'actions', 'name', 'driver', 'bot', 'chats', 'is_active'];
        $result['check']['callback'] = [$this, 'check'];

        return $result;
    }

    public function updateFields($action)
    {
        /** @var CmsCommConnection $model */
        $model = $action->model;
        $drivers = array_intersect_key(CmsCommConnection::component()->getDriversForSelect(), Bots::drivers());
        if ($model->isNewRecord && (!$model->driver || !isset($drivers[$model->driver])) && $drivers) {
            $model->driver = (string)array_key_first($drivers);
        }
        $fields = parent::updateFields($action);
        $fields['main']['fields']['driver']['items'] = $drivers;
        $fields['main']['fields']['driver']['label'] = 'Мессенджер';
        // Hints of a bot, not of a mail login.
        $main = [];
        foreach ($fields['main']['fields'] as $key => $field) {
            if ($field === 'name') {
                $main['name'] = ['hint' => 'Для себя, например «Бот поддержки в MAX».'];
                continue;
            }
            $main[$key] = $field;
        }
        $main['is_active'] = ArrayHelper::merge((array)($main['is_active'] ?? []), ['hint' => 'Выключенный бот не получает и не отправляет сообщения.']);
        $fields['main']['fields'] = $main;
        if (isset($fields['driver'])) {
            $fields['driver']['name'] = 'Настройки бота';
            // The webhook secret is made by the site itself; the proxy says only what it is.
            unset($fields['driver']['fields']['webhookSecret']);
            if (isset($fields['driver']['fields']['proxy'])) {
                $fields['driver']['fields']['proxy']['hint'] = 'Если сервер не видит API мессенджера напрямую, например http://user:pass@host:3128. Хранится зашифрованным; пустое поле оставляет сохранённое значение.';
            }
        }

        return $fields;
    }

    /** "Проверить бота": who the bot is, its subscription, what the site may do. */
    public function check()
    {
        /** @var CmsCommConnection $model */
        $model = $this->model;

        return $this->renderContent($this->statusHtml($model, $this->refreshBot($model)));
    }

    /** The journal of raw updates: what came, what was applied, what failed and why. */
    public function journal()
    {
        /** @var CmsCommConnection $model */
        $model = $this->model;
        $rows = CmsCommBotUpdate::find()->andWhere(['cms_comm_connection_id' => (int)$model->id])->orderBy(['id' => SORT_DESC])->limit(100)->all();
        $counts = CmsCommBotUpdate::find()->select(['status', 'n' => 'COUNT(*)'])->andWhere(['cms_comm_connection_id' => (int)$model->id])
            ->groupBy('status')->asArray()->all();
        $html = '<div class="sx-surface" style="padding:16px"><p class="sx-gray">События мессенджера за последние 30 дней (показаны последние 100). ';
        foreach ($counts as $row) {
            $html .= Html::encode(CmsCommBotUpdate::statuses()[$row['status']] ?? $row['status']).': '.(int)$row['n'].'. ';
        }
        $html .= '</p>';
        if (!$rows) {
            return $this->renderContent($html.'<p>Событий пока не было.</p></div>');
        }
        $html .= '<table class="table table-sm"><thead><tr><th>Когда</th><th>Событие</th><th>Состояние</th><th>Попыток</th><th>Ошибка</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.\Yii::$app->formatter->asDatetime((int)$row->created_at, 'short').'</td>'
                .'<td>'.Html::encode((string)$row->update_type).'</td>'
                .'<td>'.Html::encode(CmsCommBotUpdate::statuses()[$row->status] ?? $row->status).'</td>'
                .'<td>'.(int)$row->attempts.'</td>'
                .'<td class="sx-gray">'.Html::encode(mb_substr((string)$row->error, 0, 300)).'</td></tr>';
        }

        return $this->renderContent($html.'</tbody></table></div>');
    }

    private function afterBotSave(CmsCommConnection $model): void
    {
        $result = $this->refreshBot($model);
        $message = $result['error'] ? 'Бот сохранён, но '.$result['error'] : 'Бот сохранён: '.$result['summary'];
        \Yii::$app->session->setFlash($result['error'] ? 'warning' : 'success', $message);
    }

    /**
     * Checks the token in the messenger (stores the bot id and name), makes
     * the account and keeps the subscription as the site needs it.
     *
     * @return array{summary: string, error: string|null, subscription: array|null, notes: array}
     */
    private function refreshBot(CmsCommConnection $model): array
    {
        $result = ['summary' => '', 'error' => null, 'subscription' => null, 'notes' => []];
        try {
            $driver = Bots::driver($model);
            $me = $driver->getMe();
            if ($driver->botId !== $me->id || $driver->botUsername !== $me->username) {
                $driver->botId = $me->id;
                $driver->botUsername = $me->username;
                $model->applyDriver($driver);
                $model->save(false, ['driver_config', 'updated_at']);
            }
            $account = Bots::account($model);
            $address = $me->username ? mb_strtolower('@'.$me->username) : null;
            if ($address !== null && $account->address !== $address) {
                $twin = \skeeks\cms\communication\models\CmsCommAccount::find()
                    ->andWhere(['cms_site_id' => (int)$account->cms_site_id, 'channel' => $account->channel, 'address' => $address])
                    ->andWhere(['!=', 'id', (int)$account->id])->one();
                if ($twin) {
                    // Two connections of one bot would split its chats and fight over the webhook.
                    $result['error'] = 'этот бот уже подключён: «'.($twin->name ?: $twin->address).'». Удалите лишнее подключение.';
                    return $result;
                }
                $account->address = $address;
                $account->save(false, ['address', 'updated_at']);
            }
            $result['summary'] = ($me->name ?: 'бот').($me->username ? ' @'.$me->username : '');
            $result['notes'] = $driver->describeBot();
        } catch (\Throwable $e) {
            $result['error'] = 'мессенджер не принял токен: '.$e->getMessage();
            return $result;
        }
        try {
            $result['subscription'] = BotSubscriptions::ensure($model, $driver);
        } catch (\Throwable $e) {
            $result['error'] = 'подписаться на сообщения не удалось: '.$e->getMessage();
        }

        return $result;
    }

    private function statusHtml(CmsCommConnection $model, array $result): string
    {
        $config = $model->getPublicConfig();
        $mode = MessengerDriver::modes()[$config['mode'] ?? MessengerDriver::MODE_WEBHOOK] ?? '';
        $state = $result['subscription']['state'] ?? null;
        $states = [
            BotSubscriptions::RECEIVE_OFF => 'Этому сайту запрещено получать сообщения ботов (communication.botReceive = false, например на стенде): подписку ведёт боевой сайт.',
            BotSubscriptions::OK => 'Сообщения приходят на этот сайт.',
            BotSubscriptions::SUBSCRIBED => 'Подписка на сообщения оформлена.',
            BotSubscriptions::UNSUBSCRIBED => 'Вебхук снят: бот получает сообщения опросом.',
            BotSubscriptions::POLL => 'Бот получает сообщения опросом (раз в минуту).',
        ];
        $rows = [
            'Бот' => !empty($config['botUsername']) ? '@'.$config['botUsername'] : '—',
            'Как получать сообщения' => $mode,
            'Адрес вебхука' => Bots::webhookUrl($model),
            'Состояние' => $result['error'] ? 'Ошибка: '.$result['error'] : ($states[$state] ?? '—'),
        ];
        if (!empty($result['subscription']['error'])) {
            $rows['Мессенджер сообщает'] = $result['subscription']['error'];
        }
        $rows += (array)($result['notes'] ?? []);
        $html = '<div class="sx-surface" style="padding:16px"><table class="table table-sm">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th style="width:240px">'.Html::encode($label).'</th><td>'.Html::encode((string)$value).'</td></tr>';
        }

        return $html.'</table></div>';
    }
}
