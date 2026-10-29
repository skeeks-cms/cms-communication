<?php
/**
 * The "Написать" window of an employee without a mailbox that may send: why,
 * and for an administrator the mailboxes where access is given.
 *
 * @var $this yii\web\View
 * @var $text string
 * @var $settingsUrl string|null
 */

use yii\helpers\Html;

?>
<div class="sx-surface sx-surface--raised sx-surface--padded sx-compose-no-mailbox" role="status">
    <p><?= Html::encode($text) ?></p>
    <?php if ($settingsUrl) : ?>
        <p><?= Html::a('Почтовые ящики и доступы', $settingsUrl, ['target' => '_blank', 'data-pjax' => '0']) ?></p>
    <?php endif; ?>
</div>
