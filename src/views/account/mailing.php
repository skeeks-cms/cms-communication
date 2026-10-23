<?php
/**
 * "Рассылки" of a mailbox (AdminCmsCommAccountController::mailing()): the
 * pace of campaign letters and what waits to leave.
 *
 * @var $this yii\web\View
 * @var $account skeeks\cms\communication\models\CmsCommAccount
 * @var $settings array MailboxPace values
 * @var $errors string[]
 * @var $saved bool
 * @var $sentToday int
 * @var $nextSlot int
 * @var $pending int
 */

use skeeks\cms\communication\assets\MailingAsset;
use skeeks\cms\communication\services\MailboxPace;
use yii\helpers\Html;
use yii\helpers\Url;

MailingAsset::register($this);
$error = static fn(string $field) => !empty($errors[$field]) ? Html::tag('span', Html::encode($errors[$field]), ['class' => 'sx-import__error']) : '';
?>
<div class="sx-campaign-pace">
    <p class="sx-import__muted">Кампании рассылок пишут с этого ящика обычными личными письмами — не быстрее, чем указано здесь. В дневной лимит входят
        и личные письма за день: из CRM и из самой почты. Что не уместилось — уходит в следующий рабочий день.</p>

    <?php if ($saved) : ?>
        <div class="sx-consent__notice sx-consent__notice--success" role="status">Сохранено.</div>
    <?php endif; ?>

    <div class="sx-import__card">
        <div class="sx-import__meta">
            <span>Сегодня ушло писем: <strong><?= (int)$sentToday ?></strong> из <?= (int)$settings['dailyLimit'] ?></span>
            <span>Ждут отправки в кампаниях: <strong><?= (int)$pending ?></strong></span>
            <?php if ($pending) : ?>
                <span>Следующее письмо: <strong><?= Html::encode($nextSlot <= time() ? 'сейчас' : \Yii::$app->formatter->asDatetime($nextSlot, 'php:d.m.Y H:i')) ?></strong></span>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" action="<?= Html::encode(Url::current()) ?>" class="sx-import__card">
        <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
        <h2 class="sx-import__title">Скорость рассылок</h2>
        <div class="sx-import__settings">
            <label>
                <span class="sx-import__label">Писем в день, не больше</span>
                <?= Html::input('number', 'pace[dailyLimit]', (int)$settings['dailyLimit'], ['class' => 'form-control', 'min' => 1, 'max' => 2000]) ?>
                <?= $error('dailyLimit') ?>
            </label>
            <label>
                <span class="sx-import__label">Пауза между письмами, секунд: от</span>
                <?= Html::input('number', 'pace[pauseMin]', (int)$settings['pauseMin'], ['class' => 'form-control', 'min' => 0, 'max' => 3600]) ?>
                <?= $error('pauseMin') ?>
            </label>
            <label>
                <span class="sx-import__label">до</span>
                <?= Html::input('number', 'pace[pauseMax]', (int)$settings['pauseMax'], ['class' => 'form-control', 'min' => 0, 'max' => 3600]) ?>
            </label>
            <label>
                <span class="sx-import__label">Рабочие часы: с</span>
                <?= Html::input('number', 'pace[hourFrom]', (int)$settings['hourFrom'], ['class' => 'form-control', 'min' => 0, 'max' => 23]) ?>
                <?= $error('hourFrom') ?>
            </label>
            <label>
                <span class="sx-import__label">до</span>
                <?= Html::input('number', 'pace[hourTo]', (int)$settings['hourTo'], ['class' => 'form-control', 'min' => 1, 'max' => 24]) ?>
            </label>
        </div>
        <span class="sx-import__label">Дни</span>
        <div class="sx-campaign-pace__days">
            <?php foreach (MailboxPace::dayNames() as $day => $name) : ?>
                <label><?= Html::checkbox('pace[days][]', in_array($day, array_map('intval', (array)$settings['days']), true), ['value' => $day]) ?> <?= Html::encode($name) ?></label>
            <?php endforeach; ?>
        </div>
        <?= $error('days') ?>
        <p class="sx-import__muted">Пауза каждый раз случайная в этих пределах. По умолчанию: <?= Html::encode(MailboxPace::describe(MailboxPace::DEFAULTS)) ?>.</p>
        <div class="sx-import__actions">
            <button type="submit" class="btn btn-primary">Сохранить</button>
        </div>
    </form>
</div>
