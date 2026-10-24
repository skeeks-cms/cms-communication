<?php
/**
 * "Пригласить в бота": a one-time link for a lead, company or client. The
 * person who opens it is linked to the record, their chat with the bot
 * appears in the record. The employee sends the link the usual way
 * (a letter, SMS, a message).
 *
 * @var $this yii\web\View
 * @var $record yii\db\ActiveRecord
 * @var $type string
 * @var $bots skeeks\cms\communication\models\CmsCommAccount[] connection id => account
 * @var $botId int
 * @var $result array|null ChatIdentities::invite()
 */

use skeeks\cms\communication\assets\ChatAsset;
use skeeks\cms\communication\chat\ChatIdentities;
use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\services\CrmRecords;
use yii\helpers\Html;
use yii\helpers\Url;

ChatAsset::register($this);
$this->registerCss('body { background: var(--sx-color-surface, #fff) !important; }');
$name = CrmRecords::name($record);
?>
<div class="sx-chat-link">
    <?php if (!$bots): ?>
        <p>Нет бота, через который вам можно писать клиентам. Администратор подключает ботов и выдаёт доступ в «Настройки → Боты мессенджеров».</p>
    <?php else: ?>
        <p class="sx-chat-link__intro">
            Ссылка откроет бота у <b><?= Html::encode($name) ?></b>. Когда человек нажмёт «Начать», его чат с ботом привяжется к
            <?= Html::encode(mb_strtolower(CrmRecords::type($type)['title'] ?? 'записи')) ?>, а переписка появится в ленте. Ссылка одноразовая,
            действует <?= (int)ChatIdentities::INVITE_DAYS ?> дней, личных данных в ней нет.
        </p>
        <form method="get" action="<?= Html::encode(Url::current(['make' => null, 'bot' => null])) ?>" class="sx-chat-link__types">
            <?php foreach (\Yii::$app->request->get() as $key => $value): ?>
                <?php if (!in_array($key, ['bot', 'make'], true) && is_scalar($value)): ?><input type="hidden" name="<?= Html::encode($key) ?>" value="<?= Html::encode($value) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <?php foreach ($bots as $id => $account): ?>
                <label class="sx-chat-link__type">
                    <input type="radio" name="bot" value="<?= (int)$id ?>" <?= $id === $botId ? 'checked' : '' ?>>
                    <span><?= Html::encode(ChatUi::channelName(new \skeeks\cms\communication\models\CmsCommConversation(['channel' => $account->channel]))) ?> · <?= Html::encode($account->name ?: $account->address) ?></span>
                </label>
            <?php endforeach; ?>
            <input type="hidden" name="make" value="1">
            <button type="submit" class="btn btn-primary">Сделать ссылку</button>
        </form>
        <?php if ($result): ?>
            <?php if ($result['url']): ?>
                <div class="sx-chat-invite">
                    <input type="text" readonly value="<?= Html::encode($result['url']) ?>" data-sx-chat-invite-url onclick="this.select()">
                    <button type="button" class="sx-chat-btn" data-sx-chat-invite-copy>Скопировать</button>
                </div>
                <p class="sx-gray">Отправьте ссылку клиенту письмом, по СМС или в любом мессенджере.</p>
            <?php else: ?>
                <p class="sx-chat__warn">У бота не известно имя: откройте «Проверить бота» в его карточке.</p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
