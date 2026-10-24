<?php
/**
 * One chat in the backend panel (opened from a notification).
 *
 * @var $this yii\web\View
 * @var $paneHtml string
 */

use skeeks\cms\communication\assets\ChatAsset;

ChatAsset::register($this);
?>
<div class="sx-chats sx-chats--panel sx-chats--open">
    <section class="sx-chats__main" data-sx-chat-main>
        <?= $paneHtml ?>
    </section>
</div>
