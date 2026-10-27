<?php
/**
 * "Добавить" in a list (AdminCmsCommListController::add()), in the backend
 * window: the choice of a lead, company or client; the window stays open
 * for the next one and the list reloads when it closes.
 *
 * @var $this yii\web\View
 * @var $list skeeks\cms\communication\models\CmsCommList
 * @var $error string|null
 * @var $recent skeeks\cms\communication\models\CmsCommListMember[] added by this employee in the last hour
 */

use skeeks\cms\communication\services\CrmRecords;
use yii\helpers\Html;
use yii\helpers\Url;

if (\Yii::$app->session->hasFlash('success')) {
    $this->registerJs("if (window.sx && sx.Window && sx.Window.openerWidgetTriggerEvent) { sx.Window.openerWidgetTriggerEvent('model-update', {submitBtn: 'apply'}); }");
}
$titles = array_map(static fn($t) => $t['title'], CrmRecords::types());
?>
<div class="sx-campaign sx-campaign-recipient">
    <?php if ($error) : ?>
        <div class="sx-import__notice sx-import__notice--error" role="alert"><?= Html::encode($error) ?></div>
    <?php endif; ?>
    <?= $this->render('@skeeks/cms/communication/views/campaign/_record-picker', [
        // A post: the window parameters stay in the address.
        'action' => Url::current(['record' => null]),
        'hidden' => [],
        'method' => 'post',
        'title' => 'Добавить в «'.$list->name.'»',
        'hint' => 'Сделка, компания или клиент, которых вы видите в CRM. Адрес выберет кампания при отправке: у компании — её email, иначе email её людей.',
        'button' => 'Добавить',
        'siteId' => (int)$list->cms_site_id,
    ]) ?>
    <?php if ($recent) : ?>
        <div class="sx-import__card">
            <h2 class="sx-import__title">Только что добавлены</h2>
            <ul>
                <?php foreach ($recent as $member) : ?>
                    <li><?= Html::encode(($titles[$member->record_type] ?? 'Запись').' №'.(int)$member->record_id
                            .(($record = $member->getRecord()) ? ' · '.CrmRecords::name($record) : '')) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
