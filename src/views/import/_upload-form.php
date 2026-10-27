<?php
/**
 * Import into leads, step 1: a CSV file or a table pasted from Excel or
 * Google Sheets. Posts to AdminCmsCommImportController::upload().
 *
 * @var $this yii\web\View
 * @var $error string|null
 */

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\services\LeadImport;
use yii\helpers\Html;

?>
<?php // Posted with the backend parameters of the page: in a window the next step stays in it. ?>
<form method="post" enctype="multipart/form-data" data-pjax="0"
      action="<?= Html::encode((string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-import/create'])->setBackendParamsByCurrentRequest()->url) ?>">
    <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
    <ol class="sx-import__steps">
        <li class="is-current">Загрузить таблицу</li>
        <li>Колонки и настройки</li>
        <li>Предпросмотр с повторами</li>
        <li>Импорт</li>
    </ol>

    <?php if (!empty($error)) : ?>
        <div class="sx-import__notice sx-import__notice--error" role="alert"><?= Html::encode($error) ?></div>
    <?php endif; ?>

    <div class="sx-import__card sx-import__drop" data-sx-import-drop>
        <h2 class="sx-import__title">Файл CSV</h2>
        <p class="sx-import__muted">Из Excel: «Файл → Сохранить как → CSV». Кодировка UTF-8 или Windows-1251, разделитель — точка с запятой,
            запятая или табуляция: определятся сами.</p>
        <div class="sx-import__file">
            <label class="sx-button sx-button--secondary sx-import__file-button">
                <input type="file" name="file" accept=".csv,.txt,.tsv,text/csv" class="sx-import__file-input" data-sx-import-file>
                <i class="fas fa-upload" aria-hidden="true"></i> Выбрать файл
            </label>
            <span class="sx-import__muted" data-sx-import-file-name>или перетащите файл сюда</span>
        </div>
    </div>

    <div class="sx-import__card">
        <h2 class="sx-import__title">Или вставьте таблицу</h2>
        <p class="sx-import__muted">Выделите строки в Excel или Google Таблицах вместе с заголовками, скопируйте и вставьте сюда.</p>
        <textarea name="table" class="form-control sx-import__paste" rows="6" placeholder="Компания&#9;Email&#9;Телефон&#10;ООО Ромашка&#9;info@romashka.ru&#9;+7 900 000-00-00"></textarea>
    </div>

    <label class="sx-import__check">
        <input type="checkbox" name="header" value="1" checked>
        Первая строка — заголовки колонок
    </label>

    <p class="sx-import__muted">Дальше вы сопоставите колонки с полями сделки, выберете ответственного, тему рассылки и основание согласия и
        посмотрите, какие строки уже есть в CRM. Файлы до <?= LeadImport::MAX_ROWS ?> строк; больше <?= LeadImport::SYNC_LIMIT ?> строк
        импортируются в фоне.</p>

    <div class="sx-import__actions">
        <button type="submit" class="btn btn-primary">Загрузить</button>
    </div>
</form>
<?php
// The chosen file by its name; a file dropped onto the card is chosen too.
$this->registerJs(<<<JS
(function () {
    var drop = document.querySelector('[data-sx-import-drop]');
    var input = drop && drop.querySelector('[data-sx-import-file]');
    var label = drop && drop.querySelector('[data-sx-import-file-name]');
    if (!input) { return; }
    var show = function () { label.textContent = input.files && input.files.length ? input.files[0].name : 'или перетащите файл сюда'; };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(function (type) {
        drop.addEventListener(type, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        drop.addEventListener(type, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
    });
    drop.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); }
    });
})();
JS);
?>
