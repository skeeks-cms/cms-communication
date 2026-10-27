<?php
/**
 * Mailbox card → «Сбор писем»: collect letters of an earlier period.
 *
 * @var $this yii\web\View
 * @var $account skeeks\cms\communication\models\CmsCommAccount
 * @var $form skeeks\cms\communication\forms\MailHistoryForm
 * @var $run skeeks\cms\job\models\CmsJobRun|null just started
 * @var $runs skeeks\cms\job\models\CmsJobRun[] latest collections of this mailbox
 * @var $initialDays int
 */

use skeeks\cms\communication\assets\MailClientAsset;
use skeeks\cms\communication\forms\MailHistoryForm;
use yii\helpers\Html;
use yii\helpers\Url;

MailClientAsset::register($this);
$formatter = \Yii::$app->formatter;
$name = static fn(string $attribute) => Html::getInputName($form, $attribute);
$runUrl = static fn($r) => Url::to(['/cmsJob/admin-cms-job-run/view', 'pk' => $r->id]);
$active = null;
foreach ($runs as $r) {
    if (!$r->isFinished) {
        $active = $r;
        break;
    }
}
?>
<div class="sx-mail-settings">
    <p class="sx-mail-settings__lead">
        При подключении ящика CRM забирает письма за последние <?= (int)$initialDays ?> дней. Здесь можно собрать
        письма за больший срок — из «Входящих» и «Отправленных». Письма привяжутся к сделкам, компаниям и клиентам
        и появятся в их ленте по своей дате. Собранные письма считаются прочитанными. Письма, которые уже есть
        в CRM, повторно не добавляются, поэтому собирать можно и повторно. Сам почтовый ящик не меняется.
    </p>

    <?php foreach (\Yii::$app->session->getAllFlashes() as $type => $message) : ?>
        <div class="sx-mail-settings__notice is-<?= Html::encode($type) ?>"><?= Html::encode(implode(' ', (array)$message)) ?></div>
    <?php endforeach; ?>

    <?php if (!$account->connection) : ?>
        <div class="sx-mail-settings__notice is-warning">У ящика не настроен вход на почтовый сервер.</div>
    <?php else : ?>
        <?= Html::beginForm('', 'post', ['class' => 'sx-mail-settings__form', 'data-pjax' => 0]) ?>
            <label class="sx-mail-settings__field">
                <span><?= Html::encode($form->getAttributeLabel('period')) ?></span>
                <?= Html::dropDownList($name('period'), $form->period, MailHistoryForm::periods(),
                    ['class' => 'form-control', 'data-sx-mail-history-period' => true]) ?>
            </label>

            <label class="sx-mail-settings__field" data-sx-mail-history-date <?= $form->period === MailHistoryForm::PERIOD_DATE ? '' : 'hidden' ?>>
                <span><?= Html::encode($form->getAttributeLabel('date')) ?></span>
                <?= Html::input('date', $name('date'), $form->date, ['class' => 'form-control', 'max' => date('Y-m-d')]) ?>
                <?php if ($form->hasErrors('date')) : ?>
                    <div class="sx-mail-settings__error"><?= Html::encode($form->getFirstError('date')) ?></div>
                <?php endif; ?>
            </label>

            <?php if ($run) : ?>
                <div class="sx-mail-settings__notice is-success">
                    Сбор писем запущен. Он идёт в фоне, работать с почтой можно как обычно.
                    <?= Html::a('Открыть ход выполнения', $runUrl($run), ['data-pjax' => 0, 'target' => '_blank']) ?>
                </div>
            <?php elseif ($active) : ?>
                <div class="sx-mail-settings__notice is-info">
                    Сейчас идёт сбор писем <?= Html::encode(MailHistoryForm::describe((int)($active->payload['since'] ?? 0))) ?>.
                    <?= Html::a('Открыть ход выполнения', $runUrl($active), ['data-pjax' => 0, 'target' => '_blank']) ?>
                </div>
            <?php endif; ?>

            <div class="sx-mail-settings__buttons">
                <button type="submit" class="btn btn-primary" <?= $run || $active ? 'disabled' : '' ?>>Собрать письма</button>
            </div>
        <?= Html::endForm() ?>

        <?php if ($runs) : ?>
            <div class="sx-mail-settings__history">
                <div class="sx-mail-settings__history-title">Последние сборы</div>
                <?php foreach ($runs as $r) : ?>
                    <?php $received = $r->result['received'] ?? ($r->cursor['received'] ?? null); ?>
                    <div class="sx-mail-settings__history-row">
                        <span><?= Html::encode($formatter->asDatetime($r->created_at, 'php:d.m.Y H:i')) ?></span>
                        <span><?= Html::encode(MailHistoryForm::describe((int)($r->payload['since'] ?? 0))) ?></span>
                        <span><?= Html::encode($r->statusText) ?><?= $received !== null ? ', собрано писем: '.(int)$received : '' ?></span>
                        <?= Html::a('Подробнее', $runUrl($r), ['data-pjax' => 0, 'target' => '_blank']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$this->registerJs(<<<JS
$(document).on('change', '[data-sx-mail-history-period]', function () {
    $('[data-sx-mail-history-date]').prop('hidden', this.value !== 'date');
});
JS);
