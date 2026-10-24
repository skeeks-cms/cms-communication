<?php
/**
 * "Добавить в список" of a lead, company or client card
 * (AdminCmsCommListController::addRecord()), in the small backend window:
 * the lists the record is in (with "убрать"), the choice of a list or a new
 * one. The card reloads when the window closes after a change.
 *
 * @var $this yii\web\View
 * @var $record yii\db\ActiveRecord
 * @var $recordRef string
 * @var $form skeeks\cms\communication\forms\ListAddForm
 * @var $done string|null
 * @var $memberships skeeks\cms\communication\models\CmsCommListMember[]
 */

use skeeks\cms\communication\forms\ListAddForm;
use skeeks\cms\communication\services\CrmRecords;
use yii\helpers\Html;
use yii\helpers\Url;

$csrf = Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken);
$formatter = \Yii::$app->formatter;
if ($done) {
    $this->registerJs("if (window.sx && sx.Window && sx.Window.openerWidgetTriggerEvent) { sx.Window.openerWidgetTriggerEvent('model-update', {submitBtn: 'apply'}); }");
}
$choices = ListAddForm::choices();
foreach ($memberships as $member) {
    unset($choices[(string)$member->cms_comm_list_id]);
}
$this->registerJs(<<<JS
(function () {
    function toggle() { $('[data-sx-list-new]').prop('hidden', $('#listaddform-list').val() !== 'new'); }
    $(document).on('change', '#listaddform-list', toggle);
    toggle();
})();
JS);
?>
<div class="sx-campaign sx-list-add">
    <p><strong><?= Html::encode(CrmRecords::name($record)) ?></strong></p>
    <?php if ($done) : ?>
        <div class="sx-import__notice sx-import__notice--info" role="status"><?= Html::encode($done) ?></div>
    <?php endif; ?>

    <div class="sx-import__card">
        <h2 class="sx-import__title">В списках</h2>
        <?php if (!$memberships) : ?>
            <p class="sx-import__muted">Ни в одном списке.</p>
        <?php else : ?>
            <ul class="sx-list-add__lists">
                <?php foreach ($memberships as $member) : ?>
                    <li>
                        <span><?= Html::encode($member->list->name) ?>
                            <span class="sx-import__muted">— <?= Html::encode($formatter->asDate((int)$member->created_at, 'php:d.m.Y')) ?>,
                                <?= Html::encode($member->getViaText()) ?><?= $member->addedBy ? ' · '.Html::encode($member->addedBy->shortDisplayName) : '' ?></span></span>
                        <?= Html::beginForm(Url::current(), 'post', ['class' => 'sx-campaign__inline']) ?>
                        <?= Html::hiddenInput('do', 'remove').Html::hiddenInput('member', (int)$member->id) ?>
                        <?= Html::submitButton('убрать', ['class' => 'btn btn-default btn-sm']) ?>
                        <?= Html::endForm() ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= Html::encode(Url::current()) ?>" class="sx-import__card">
        <?= $csrf ?>
        <h2 class="sx-import__title">Добавить в список</h2>
        <div class="sx-import__settings">
            <label>
                <span class="sx-import__label"><?= Html::encode($form->getAttributeLabel('list')) ?></span>
                <?= Html::activeDropDownList($form, 'list', $choices, ['class' => 'form-control', 'prompt' => '— выберите —']) ?>
                <?php if ($form->hasErrors('list')) : ?><span class="sx-import__error"><?= Html::encode($form->getFirstError('list')) ?></span><?php endif; ?>
            </label>
            <label data-sx-list-new hidden>
                <span class="sx-import__label"><?= Html::encode($form->getAttributeLabel('newName')) ?></span>
                <?= Html::activeTextInput($form, 'newName', ['class' => 'form-control', 'maxlength' => 255]) ?>
                <?php if ($form->hasErrors('newName')) : ?><span class="sx-import__error"><?= Html::encode($form->getFirstError('newName')) ?></span><?php endif; ?>
            </label>
        </div>
        <p class="sx-import__muted">В список попадает сама запись: адрес выберет кампания при отправке, отписка контакта действует и здесь.</p>
        <div class="sx-import__actions">
            <button type="submit" class="btn btn-primary">Добавить</button>
        </div>
    </form>
</div>
