<?php
/**
 * "Привязать письмо": a lead, company, client or deal chosen with the
 * standard CRM search (AjaxSelectModel over the records the employee sees),
 * for this letter or the whole conversation. Opened in a backend window over
 * the letter; mail.js posts it and refreshes the letter.
 *
 * @var $this yii\web\View
 * @var $model skeeks\cms\communication\models\CmsCommMessage
 * @var $letters int letters in the conversation
 * @var $siteId int
 */

use skeeks\cms\communication\assets\MailClientAsset;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\models\CmsLead;
use skeeks\cms\widgets\AjaxSelectModel;
use yii\helpers\Html;
use yii\helpers\Url;

MailClientAsset::register($this);
$this->registerCss('body { background: var(--sx-color-surface, #fff) !important; }');

$user = \Yii::$app->user->identity;
$selectFor = static function (string $type) use ($user, $siteId) {
    $class = CrmRecords::codeOf($type);

    return AjaxSelectModel::widget([
        'name' => 'id_'.$type,
        'id' => 'sx-mail-link-'.$type,
        'modelClass' => $class,
        'options' => ['data-sx-mail-link-select' => $type],
        'pluginOptions' => ['placeholder' => 'Начните вводить название, имя, телефон или email', 'allowClear' => true, 'width' => '100%'],
        'searchQuery' => static function ($word = '') use ($type, $class, $user, $siteId) {
            $query = CrmRecords::visibleQuery($type, $user);
            if ($type === CrmRecords::LEAD) {
                $query->andWhere([CmsLead::tableName().'.cms_site_id' => $siteId]);
            }
            $word = trim((string)$word);
            if ($word !== '') {
                if (method_exists($query, 'search')) {
                    $query->search($word);
                } else {
                    $query->andWhere(['or', ['like', $class::tableName().'.name', $word], [$class::tableName().'.id' => ctype_digit($word) ? (int)$word : 0]]);
                }
            }

            return $query->orderBy([$class::tableName().'.id' => SORT_DESC]);
        },
    ]);
};
$first = CrmRecords::LINKABLE[0];
?>
<form class="sx-mail-link" data-sx-mail-link-form action="<?= Html::encode(Url::to(['link', 'pk' => $model->id])) ?>" method="post">
    <div class="sx-mail-link__letter">
        <?php // The subject is the window title already. ?>
        <?= MailUi::icon('mail', 16) ?>
        <span class="sx-letter__muted"><?= Html::encode($model->direction === 'in' ? $model->getFromAsText() : $model->getToAsText()) ?></span>
    </div>

    <div class="sx-mail-link__label">К чему привязать</div>
    <div class="sx-mail-link__types" role="radiogroup">
        <?php foreach (CrmRecords::LINKABLE as $type) : ?>
            <?php $config = CrmRecords::type($type); ?>
            <label class="sx-mail-link__type">
                <input type="radio" name="type" value="<?= Html::encode($type) ?>" <?= $type === $first ? 'checked' : '' ?> data-sx-mail-link-type>
                <span><?= MailUi::icon($config['icon'], 16) ?> <?= Html::encode($config['title']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <?php foreach (CrmRecords::LINKABLE as $type) : ?>
        <div class="sx-mail-link__select" data-sx-mail-link-for="<?= Html::encode($type) ?>" <?= $type === $first ? '' : 'hidden' ?>>
            <?= $selectFor($type) ?>
        </div>
    <?php endforeach; ?>

    <?php if ($letters > 1) : ?>
        <div class="sx-mail-link__label">Что привязать</div>
        <label class="sx-mail-link__scope"><input type="radio" name="conversation" value="1" checked> Всю переписку (писем: <?= (int)$letters ?>)</label>
        <label class="sx-mail-link__scope"><input type="radio" name="conversation" value="0"> Только это письмо</label>
    <?php else : ?>
        <input type="hidden" name="conversation" value="1">
    <?php endif; ?>
    <p class="sx-mail-link__hint">Письмо появится в ленте записи. Новые письма этой переписки привяжутся сами.</p>

    <div class="sx-mail-link__error" data-sx-mail-link-error hidden></div>
    <div class="sx-mail-link__actions">
        <button type="submit" class="btn btn-primary">Привязать</button>
    </div>
</form>
