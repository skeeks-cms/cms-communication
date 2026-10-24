<?php
/**
 * "Настройки почты" → «Переходы из писем» (step F): marks of links to our
 * sites, the domains, the call task; how to connect a site on another
 * installation.
 *
 * @var $this yii\web\View
 * @var $component skeeks\cms\communication\components\LinkTrackingSettings
 * @var $settings array what letters use now: enabled, domains, taskOnVisit
 * @var $scriptUrl string
 */

use skeeks\cms\communication\assets\MailClientAsset;
use yii\helpers\Html;

MailClientAsset::register($this);
?>
<div class="sx-mail-settings">
    <p class="sx-mail-settings__lead">
        Когда клиент переходит по ссылке из письма на наш сайт, в ленте его карточки появляется запись «Перешёл на сайт
        из письма» со страницами, которые он посмотрел, а отправитель письма получает уведомление. Засчитываются только
        люди: проверки ссылок почтовыми службами и роботы не считаются. Открытие письма не отслеживается — только переход.
    </p>

    <?php $form = $component->beginConfigForm(); ?>
    <?= $component->renderConfigFormFields($form) ?>
    <?= $form->buttonsStandart($component) ?>
    <?php $form::end(); ?>

    <h3 class="sx-mail-settings__title">Сейчас размечаются ссылки на</h3>
    <?php if (!$settings['enabled']) : ?>
        <p class="sx-mail-settings__muted">Разметка выключена: письма уходят с исходными ссылками. Переходы по уже отправленным письмам засчитываются.</p>
    <?php elseif (!$settings['domains']) : ?>
        <p class="sx-mail-settings__muted">Нет ни одного домена: укажите сайты выше.</p>
    <?php else : ?>
        <ul class="sx-mail-settings__list">
            <?php foreach ($settings['domains'] as $domain) : ?>
                <li><?= Html::encode($domain) ?> <span class="sx-mail-settings__muted">и поддомены</span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h3 class="sx-mail-settings__title">Сайт на другой установке</h3>
    <p class="sx-mail-settings__muted">
        На этом сайте учёт подключается сам. Если ссылки ведут на сайт, работающий отдельно от этой CRM, добавьте его домен
        в список выше и подключите на нём этот скрипт — лучше только на страницах, открытых по ссылке с параметром
        <code>sx</code>, и пока у посетителя есть cookie <code>sx_visit</code>. На том сайте нужно уведомление о cookie.
    </p>
    <pre class="sx-mail-settings__code"><?= Html::encode('<script src="'.$scriptUrl.'"></script>') ?></pre>
</div>
