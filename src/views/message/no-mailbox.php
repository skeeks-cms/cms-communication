<?php
/**
 * "Написать письмо" without a mailbox that may send, on a plain page (see _no-mailbox).
 *
 * @var $this yii\web\View
 * @var $text string
 * @var $settingsUrl string|null
 */

use skeeks\cms\communication\assets\MailClientAsset;

MailClientAsset::register($this);
$this->registerCss('body { background: var(--sx-color-surface, #fff) !important; }');
?>
<div class="sx-compose-page">
    <?= $this->render('_no-mailbox', ['text' => $text, 'settingsUrl' => $settingsUrl]) ?>
</div>
