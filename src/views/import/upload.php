<?php
/**
 * Import into leads, step 1, as its own page (AdminCmsCommImportController::upload()):
 * the form again with the error of the upload. The usual entry is the tab
 * "Импорт" of the section "Лиды" (views/import/tab.php).
 *
 * @var $this yii\web\View
 * @var $error string|null
 */

use skeeks\cms\communication\assets\MailingAsset;

MailingAsset::register($this);
?>
<div class="sx-import">
    <?= $this->render('@skeeks/cms/communication/views/import/_upload-form', ['error' => $error]) ?>
</div>
