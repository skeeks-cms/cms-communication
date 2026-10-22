<?php
/**
 * "Настройки почты" → «Хранение файлов»: the site settings and the mailboxes
 * that override them.
 *
 * @var $this yii\web\View
 * @var $component skeeks\cms\communication\components\MailFileSettings
 * @var $overrides skeeks\cms\communication\models\CmsCommAccount[]
 */

use skeeks\cms\communication\assets\MailClientAsset;
use skeeks\cms\communication\components\MailFileSettings;
use yii\helpers\Html;
use yii\helpers\Url;

MailClientAsset::register($this);
?>
<div class="sx-mail-settings">
    <p class="sx-mail-settings__lead">
        Текст писем CRM хранит всегда. Картинки из текста и вложения можно хранить у себя или брать из почтового
        ящика, когда письмо или файл открывают: тогда файл догружается за несколько секунд. Копия, которой нет в
        CRM, недоступна, только если письмо удалили или перенесли в почтовом ящике или подключение к ящику не работает.
        У каждого ящика можно задать свои настройки в его карточке.
    </p>

    <?php $form = $component->beginConfigForm(); ?>
    <?= $component->renderConfigFormFields($form) ?>
    <?= $form->buttonsStandart($component) ?>
    <?php $form::end(); ?>

    <h3 class="sx-mail-settings__title">Ящики со своими настройками</h3>
    <?php if (!$overrides) : ?>
        <p class="sx-mail-settings__muted">Все ящики работают по этим настройкам.</p>
    <?php else : ?>
        <ul class="sx-mail-settings__list">
            <?php foreach ($overrides as $account) : ?>
                <?php $own = $account->getFileSettings(); ?>
                <li>
                    <?= Html::a(Html::encode($account->address), Url::to(['/cmsCommunication/admin-cms-comm-account/update', 'pk' => $account->id]), ['data-pjax' => 0]) ?>
                    — <?= Html::encode(MailFileSettings::modes()[$own['mode'] ?? ''] ?? '') ?>
                    <?php if (!empty($own['keepDays'])) : ?>, копии хранятся <?= (int)$own['keepDays'] ?> дн.<?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
