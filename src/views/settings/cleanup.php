<?php
/**
 * "Настройки почты" → «Очистка файлов»: the administrator chooses what to
 * remove, sees how much it is and starts the cleanup job.
 *
 * @var $this yii\web\View
 * @var $form skeeks\cms\communication\forms\MailFileCleanupForm
 * @var $preview array|null count, bytes
 * @var $run skeeks\cms\job\models\CmsJobRun|null
 * @var $accounts skeeks\cms\communication\models\CmsCommAccount[]
 */

use skeeks\cms\communication\assets\MailClientAsset;
use yii\helpers\Html;
use yii\helpers\Url;

MailClientAsset::register($this);
$formatter = \Yii::$app->formatter;
$name = static fn(string $attribute) => Html::getInputName($form, $attribute);
?>
<div class="sx-mail-settings">
    <p class="sx-mail-settings__lead">
        Удаляются только копии файлов в CRM. Письма, их текст и файлы в почтовом ящике остаются: при открытии письма
        файл снова загрузится из ящика. Файлы писем, которых CRM не нашла на почтовом сервере (например, удалённых
        там), не удаляются — у них копия в CRM единственная. Ежедневно копии удаляются и сами, по настройке
        «Хранить копии файлов, дней».
    </p>

    <?php foreach (\Yii::$app->session->getAllFlashes() as $type => $message) : ?>
        <div class="sx-mail-settings__notice is-<?= Html::encode($type) ?>"><?= Html::encode(implode(' ', (array)$message)) ?></div>
    <?php endforeach; ?>

    <?= Html::beginForm(Url::to(['cleanup']), 'post', ['class' => 'sx-mail-settings__form', 'data-pjax' => 0]) ?>
        <label class="sx-mail-settings__field">
            <span><?= Html::encode($form->getAttributeLabel('olderThanDays')) ?></span>
            <?= Html::input('number', $name('olderThanDays'), $form->olderThanDays, ['min' => 0, 'class' => 'form-control']) ?>
            <small>0 — любые, независимо от даты письма.</small>
        </label>

        <fieldset class="sx-mail-settings__field">
            <legend>Что удалять</legend>
            <label class="sx-mail-settings__check">
                <?= Html::hiddenInput($name('attachments'), 0) ?>
                <?= Html::checkbox($name('attachments'), (bool)$form->attachments) ?> <?= Html::encode($form->getAttributeLabel('attachments')) ?>
            </label>
            <label class="sx-mail-settings__check">
                <?= Html::hiddenInput($name('images'), 0) ?>
                <?= Html::checkbox($name('images'), (bool)$form->images) ?> <?= Html::encode($form->getAttributeLabel('images')) ?>
            </label>
            <?php if ($form->hasErrors('attachments')) : ?>
                <div class="sx-mail-settings__error"><?= Html::encode($form->getFirstError('attachments')) ?></div>
            <?php endif; ?>
        </fieldset>

        <label class="sx-mail-settings__field">
            <span><?= Html::encode($form->getAttributeLabel('minSizeMb')) ?></span>
            <?= Html::input('number', $name('minSizeMb'), $form->minSizeMb, ['min' => 0, 'class' => 'form-control']) ?>
            <small>0 — любого размера.</small>
        </label>

        <label class="sx-mail-settings__field">
            <span><?= Html::encode($form->getAttributeLabel('accountId')) ?></span>
            <?= Html::dropDownList($name('accountId'), $form->accountId,
                ['' => 'Все ящики'] + \yii\helpers\ArrayHelper::map($accounts, 'id', 'address'), ['class' => 'form-control']) ?>
        </label>

        <?php if ($preview !== null) : ?>
            <div class="sx-mail-settings__preview">
                <?php if ($preview['count']) : ?>
                    Будет удалено копий файлов: <strong><?= (int)$preview['count'] ?></strong>,
                    примерно <strong><?= Html::encode($formatter->asShortSize($preview['bytes'])) ?></strong>
                    (одинаковые файлы хранятся один раз, поэтому освободится не больше этого).
                <?php else : ?>
                    Под эти условия ничего не подходит.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($run) : ?>
            <div class="sx-mail-settings__notice is-success">
                Очистка запущена.
                <?= Html::a('Открыть ход выполнения', Url::to(['/cmsJob/admin-cms-job-run/view', 'pk' => $run->id]), ['data-pjax' => 0, 'target' => '_blank']) ?>
            </div>
        <?php endif; ?>

        <div class="sx-mail-settings__buttons">
            <button type="submit" class="btn btn-default" name="preview" value="1">Посчитать</button>
            <?php if ($preview && $preview['count'] && !$run) : ?>
                <button type="submit" class="btn btn-primary" name="start" value="1">Удалить копии файлов</button>
            <?php endif; ?>
        </div>
    <?= Html::endForm() ?>
</div>
