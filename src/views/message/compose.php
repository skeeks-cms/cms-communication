<?php
/**
 * "Написать письмо" in the backend panel: the letter editor on a plain page.
 *
 * @var $this yii\web\View
 * @var $params array parameters of _compose
 */

use skeeks\cms\communication\assets\MailClientAsset;

MailClientAsset::register($this);
$this->registerCss('body { background: var(--sx-color-surface, #fff) !important; }');
?>
<div class="sx-compose-page">
    <?= $this->render('_compose', $params) ?>
</div>
