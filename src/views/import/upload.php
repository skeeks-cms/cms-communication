<?php
/**
 * Import, step 1, in the backend window (AdminCmsCommImportController::upload()):
 * the form, again with the error of the upload. Opened by «Загрузить таблицу»
 * of the tab «Импорт» of «Сделки» and «Компании» and by «Импорт в список» of a list.
 *
 * @var $this yii\web\View
 * @var $error string|null
 * @var $mode string CmsCommImport::MODE_*
 * @var $list skeeks\cms\communication\models\CmsCommList|null
 */

use skeeks\cms\communication\assets\MailingAsset;

MailingAsset::register($this);
?>
<div class="sx-import">
    <?= $this->render('@skeeks/cms/communication/views/import/_upload-form', ['error' => $error, 'mode' => $mode, 'list' => $list]) ?>
</div>
