<?php
/**
 * The choice of a lead, company or client the employee sees (a GET form that
 * sends record=lead:12): a recipient of a campaign, a member of a list.
 *
 * @var $this yii\web\View
 * @var $action string the form URL
 * @var $hidden array name => value of hidden fields (pk)
 * @var $title string
 * @var $hint string
 * @var $button string
 * @var $siteId int
 * @var $method string|null "get" (default) or "post" when choosing the record changes data
 */

use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\models\CmsLead;
use skeeks\cms\widgets\AjaxSelectModel;
use yii\helpers\Html;

$user = \Yii::$app->user->identity;
$method = ($method ?? 'get') === 'post' ? 'post' : 'get';
?>
<form method="<?= $method ?>" action="<?= Html::encode($action) ?>" class="sx-import__card" data-sx-campaign-add>
    <?php if ($method === 'post') : ?>
        <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
    <?php endif; ?>
    <?php // Nested values too: the parameters of the backend window (_sxb) keep the next page in the window.
    foreach (explode('&', http_build_query($hidden)) as $pair) :
        if ($pair === '') {
            continue;
        }
        [$name, $value] = array_map('urldecode', explode('=', $pair, 2) + [1 => '']); ?>
        <?= Html::hiddenInput($name, $value) ?>
    <?php endforeach; ?>
    <?= Html::hiddenInput('record', '', ['data-sx-campaign-record' => true]) ?>
    <h2 class="sx-import__title"><?= Html::encode($title) ?></h2>
    <p class="sx-import__muted"><?= Html::encode($hint) ?></p>
    <div class="sx-campaign-recipient__types" role="radiogroup">
        <?php foreach (Campaigns::ADDABLE as $i => $type) : ?>
            <label><input type="radio" name="type" value="<?= Html::encode($type) ?>" <?= $i === 0 ? 'checked' : '' ?>> <?= Html::encode(CrmRecords::type($type)['title']) ?></label>
        <?php endforeach; ?>
    </div>
    <?php foreach (Campaigns::ADDABLE as $i => $type) :
        $class = CrmRecords::codeOf($type); ?>
        <div class="form-group" data-sx-campaign-type="<?= Html::encode($type) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
            <?= AjaxSelectModel::widget([
                'name' => 'id_'.$type,
                'id' => 'sx-campaign-record-'.$type,
                'modelClass' => $class,
                'pluginOptions' => ['placeholder' => 'Начните вводить название, имя, телефон или email', 'allowClear' => true, 'width' => '100%'],
                'searchQuery' => static function ($word = '') use ($type, $class, $user, $siteId) {
                    $query = CrmRecords::visibleQuery($type, $user);
                    if ($type === CrmRecords::LEAD) {
                        $query->andWhere([CmsLead::tableName().'.cms_site_id' => $siteId]);
                    }
                    $word = trim((string)$word);
                    if ($word !== '' && method_exists($query, 'search')) {
                        $query->search($word);
                    }

                    return $query->orderBy([$class::tableName().'.id' => SORT_DESC]);
                },
            ]) ?>
        </div>
    <?php endforeach; ?>
    <div class="sx-import__actions">
        <button type="submit" class="btn btn-primary"><?= Html::encode($button) ?></button>
    </div>
</form>
<?php $this->registerJs(<<<JS
(function () {
    var form = $('[data-sx-campaign-add]');
    form.on('change', 'input[name=type]', function () {
        var type = form.find('input[name=type]:checked').val();
        form.find('[data-sx-campaign-type]').each(function () { this.hidden = $(this).data('sx-campaign-type') !== type; });
    });
    form.on('submit', function (e) {
        var type = form.find('input[name=type]:checked').val();
        var id = form.find('[name=id_' + type + ']').val();
        if (!id) { e.preventDefault(); alert('Выберите запись CRM.'); return; }
        form.find('[data-sx-campaign-record]').val(type + ':' + id);
        form.find('[name^=id_], input[name=type]').prop('disabled', true);
    });
})();
JS); ?>
