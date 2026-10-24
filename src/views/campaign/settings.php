<?php
/**
 * "Рассылки" → «Настройки» (AdminCmsCommCampaignController::settings()):
 * rules of every mailing of the site.
 *
 * @var $this yii\web\View
 * @var $component skeeks\cms\communication\components\MailingSettings
 */

use skeeks\cms\communication\assets\MailingAsset;

MailingAsset::register($this);
?>
<div class="sx-campaign sx-campaign-recipient">
    <p class="sx-import__muted">
        Правила для всех кампаний сайта. У каждой кампании есть ещё свои: «Одна компания — одно письмо» и «Не мешать менеджеру»
        (не писать тем, с кем недавно общались). Исключённых по правилам сводка кампании показывает с причиной до запуска,
        а частоту и «не мешать менеджеру» проверяет ещё раз перед каждым письмом.
    </p>
    <?php $form = $component->beginConfigForm(); ?>
    <?= $component->renderConfigFormFields($form) ?>
    <?= $form->buttonsStandart($component) ?>
    <?php $form::end(); ?>
</div>
