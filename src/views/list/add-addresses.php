<?php
/**
 * "Добавить адреса" of a list (AdminCmsCommListController::addAddresses()),
 * in the backend window: a line per recipient; the next step is the preview
 * of the audience import made of the lines.
 *
 * @var $this yii\web\View
 * @var $list skeeks\cms\communication\models\CmsCommList
 * @var $error string|null
 * @var $text string
 * @var $executorId int|null
 * @var $responsibles string[] AdminCmsCommImportController::responsibleOptions()
 */

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\services\AudienceImport;
use yii\helpers\Html;

?>
<div class="sx-import">
    <form method="post" data-pjax="0"
          action="<?= Html::encode((string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-list/add-addresses', 'pk' => (int)$list->id])->setBackendParamsByCurrentRequest()->url) ?>">
        <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
        <?php if ($error) : ?>
            <div class="sx-import__notice sx-import__notice--error" role="alert"><?= Html::encode($error) ?></div>
        <?php endif; ?>
        <div class="sx-import__card">
            <h2 class="sx-import__title">Добавить адреса в «<?= Html::encode($list->name) ?>»</h2>
            <p class="sx-import__muted">По одному получателю в строке: email, телефон или строка целиком — «Ромашка, Иван Петров, info@romashka.ru,
                +7 900 000-00-00». Кого CRM уже знает — найдётся сам; на новый адрес заведётся карточка контакта (и компании, если она указана).
                Перед добавлением покажем, кто новый, кто уже есть и кто не попадёт в список.</p>
            <?= Html::textarea('lines', $text, ['class' => 'form-control sx-import__paste', 'rows' => 10,
                'placeholder' => "ivan@romashka.ru\nООО «Ромашка», Иван Петров, info@romashka.ru, +7 900 000-00-00\n+7 900 111-22-33"]) ?>
            <label>
                <span class="sx-import__label">Ответственный за новые компании и контакты</span>
                <?= Html::dropDownList('executor_id', $executorId ?: '', $responsibles, ['class' => 'form-control']) ?>
            </label>
            <p class="sx-import__muted">До <?= AudienceImport::MAX_LINES ?> строк. Большую базу удобнее загрузить таблицей — «Импорт в список».</p>
        </div>
        <div class="sx-import__actions">
            <button type="submit" class="btn btn-primary">Проверить адреса</button>
        </div>
    </form>
</div>
