<?php
/**
 * "Настройки почты" → «Сохранить файлы в CRM»: the administrator chooses which
 * files of earlier letters to keep for good, sees how much it is and starts
 * the job.
 *
 * @var $this yii\web\View
 * @var $form skeeks\cms\communication\forms\MailFileSaveForm
 * @var $preview array|null fetch, bytes, cached, letters
 * @var $run skeeks\cms\job\models\CmsJobRun|null
 * @var $accounts skeeks\cms\communication\models\CmsCommAccount[]
 * @var $policy skeeks\cms\communication\services\MailFilePolicy site settings
 */

use skeeks\cms\communication\assets\MailClientAsset;
use skeeks\cms\communication\forms\MailFileSaveForm;
use yii\helpers\Html;
use yii\helpers\Url;

MailClientAsset::register($this);
$formatter = \Yii::$app->formatter;
$name = static fn(string $attribute) => Html::getInputName($form, $attribute);
// Saved copies of letters older than the copy term would go with the next daily cleanup.
$beyondTerm = $policy->keepDays > 0 && ($form->period === MailFileSaveForm::PERIOD_ALL || $form->since() < strtotime('today') - $policy->keepDays * 86400);
?>
<div class="sx-mail-settings">
    <p class="sx-mail-settings__lead">
        Загружает из почтовых ящиков картинки и вложения уже полученных писем и сохраняет их в CRM насовсем.
        Это нужно, например, если почта работала в экономном или минимальном режиме, а теперь файлы нужны прямо в CRM.
        Файлы, которые уже открывали и которые хранятся временно, тоже станут постоянными. Почтовый ящик не меняется.
    </p>

    <?php if ($policy->keepDays > 0) : ?>
        <div class="sx-mail-settings__notice <?= $beyondTerm ? 'is-warning' : '' ?>">
            Срок хранения копий файлов — <?= (int)$policy->keepDays ?> дн.: ежедневная уборка удалит копии файлов писем
            старше этого срока, если письма есть в почтовом ящике.
            <?php if ($beyondTerm) : ?>
                Чтобы файлы более старых писем остались, увеличьте срок или поставьте 0 на вкладке
                <?= Html::a('«Хранение файлов»', Url::to(['files']), ['data-pjax' => 0]) ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php foreach (\Yii::$app->session->getAllFlashes() as $type => $message) : ?>
        <div class="sx-mail-settings__notice is-<?= Html::encode($type) ?>"><?= Html::encode(implode(' ', (array)$message)) ?></div>
    <?php endforeach; ?>

    <?= Html::beginForm(Url::to(['save-files']), 'post', ['class' => 'sx-mail-settings__form', 'data-pjax' => 0]) ?>
        <label class="sx-mail-settings__field">
            <span><?= Html::encode($form->getAttributeLabel('accountId')) ?></span>
            <?= Html::dropDownList($name('accountId'), $form->accountId,
                ['' => 'Все ящики'] + \yii\helpers\ArrayHelper::map($accounts, 'id', 'address'), ['class' => 'form-control']) ?>
        </label>

        <label class="sx-mail-settings__field">
            <span><?= Html::encode($form->getAttributeLabel('period')) ?></span>
            <?= Html::dropDownList($name('period'), $form->period,
                [MailFileSaveForm::PERIOD_ALL => 'Все письма'] + array_diff_key(MailFileSaveForm::periods(), [MailFileSaveForm::PERIOD_ALL => true]),
                ['class' => 'form-control', 'data-sx-mail-history-period' => true]) ?>
        </label>

        <label class="sx-mail-settings__field" data-sx-mail-history-date <?= $form->period === MailFileSaveForm::PERIOD_DATE ? '' : 'hidden' ?>>
            <span><?= Html::encode($form->getAttributeLabel('date')) ?></span>
            <?= Html::input('date', $name('date'), $form->date, ['class' => 'form-control', 'max' => date('Y-m-d')]) ?>
            <?php if ($form->hasErrors('date')) : ?>
                <div class="sx-mail-settings__error"><?= Html::encode($form->getFirstError('date')) ?></div>
            <?php endif; ?>
        </label>

        <fieldset class="sx-mail-settings__field">
            <legend>Что сохранить</legend>
            <label class="sx-mail-settings__check">
                <?= Html::hiddenInput($name('images'), 0) ?>
                <?= Html::checkbox($name('images'), (bool)$form->images) ?> <?= Html::encode($form->getAttributeLabel('images')) ?>
            </label>
            <label class="sx-mail-settings__check">
                <?= Html::hiddenInput($name('attachments'), 0) ?>
                <?= Html::checkbox($name('attachments'), (bool)$form->attachments) ?> <?= Html::encode($form->getAttributeLabel('attachments')) ?>
            </label>
            <?php if ($form->hasErrors('attachments')) : ?>
                <div class="sx-mail-settings__error"><?= Html::encode($form->getFirstError('attachments')) ?></div>
            <?php endif; ?>
        </fieldset>

        <label class="sx-mail-settings__field">
            <span><?= Html::encode($form->getAttributeLabel('maxSizeMb')) ?></span>
            <?= Html::input('number', $name('maxSizeMb'), $form->maxSizeMb, ['min' => 0, 'class' => 'form-control']) ?>
            <small>0 — любого размера.</small>
        </label>

        <?php if ($preview !== null) : ?>
            <div class="sx-mail-settings__preview">
                <?php if ($preview['fetch'] || $preview['cached']) : ?>
                    <?php if ($preview['fetch']) : ?>
                        Будет загружено из ящиков файлов: <strong><?= (int)$preview['fetch'] ?></strong>,
                        примерно <strong><?= Html::encode($formatter->asShortSize($preview['bytes'])) ?></strong>
                        (одинаковые файлы хранятся один раз, поэтому места займут не больше этого).
                    <?php endif; ?>
                    <?php if ($preview['cached']) : ?>
                        Уже загруженных файлов станет постоянными: <strong><?= (int)$preview['cached'] ?></strong>.
                    <?php endif; ?>
                    Писем: <?= (int)$preview['letters'] ?>.
                <?php else : ?>
                    Под эти условия ничего не подходит: файлы уже сохранены в CRM.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($run) : ?>
            <div class="sx-mail-settings__notice is-success">
                Сохранение файлов запущено. Оно идёт в фоне, работать с почтой можно как обычно.
                <?= Html::a('Открыть ход выполнения', Url::to(['/cmsJob/admin-cms-job-run/view', 'pk' => $run->id]), ['data-pjax' => 0, 'target' => '_blank']) ?>
            </div>
        <?php endif; ?>

        <div class="sx-mail-settings__buttons">
            <button type="submit" class="btn btn-default" name="preview" value="1">Посчитать</button>
            <?php if ($preview && ($preview['fetch'] || $preview['cached']) && !$run) : ?>
                <button type="submit" class="btn btn-primary" name="start" value="1">Сохранить файлы</button>
            <?php endif; ?>
        </div>
    <?= Html::endForm() ?>
</div>
<?php
$this->registerJs(<<<JS
$(document).on('change', '[data-sx-mail-history-period]', function () {
    $('[data-sx-mail-history-date]').prop('hidden', this.value !== 'date');
});
JS);
