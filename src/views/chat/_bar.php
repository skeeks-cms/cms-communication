<?php
/**
 * The dialog bar: who answers the client, "Взять диалог", "Передать",
 * "Закрыть" / "Открыть снова", how long the client waits.
 *
 * @var $this yii\web\View
 * @var $conversation skeeks\cms\communication\models\CmsCommConversation
 * @var $colleagues string[] user id => name: employees with access to the bot
 * @var $me int
 */

use skeeks\cms\communication\helpers\ChatUi;
use yii\helpers\Html;

$assigned = (int)$conversation->assigned_user_id;
?>
<div class="sx-chat-bar">
    <span class="sx-chat-bar__who">
        <?php if ($conversation->closed_at): ?>
            Диалог закрыт
        <?php elseif ($assigned === $me): ?>
            Диалог ведёте вы
        <?php elseif ($assigned && $conversation->assignedUser): ?>
            Ведёт: <b><?= Html::encode($conversation->assignedUser->shortDisplayName) ?></b>
        <?php else: ?>
            Диалог никто не ведёт
        <?php endif; ?>
        <?php if ($conversation->waiting_since && !$conversation->closed_at): ?>
            <span class="sx-chat-row__waiting">клиент ждёт <?= Html::encode(ChatUi::waiting((int)$conversation->waiting_since)) ?></span>
        <?php endif; ?>
    </span>
    <span class="sx-chat-bar__actions">
        <?php if (!$assigned || $conversation->closed_at): ?>
            <button type="button" class="btn btn-sm btn-primary" data-sx-chat-do="take">Взять диалог</button>
        <?php endif; ?>
        <?php if ($colleagues || $assigned): ?>
            <select class="sx-chat-bar__transfer" data-sx-chat-transfer aria-label="Передать диалог">
                <option value="">Передать…</option>
                <?php foreach ($colleagues as $id => $name): ?>
                    <?php if ($id !== $assigned): ?><option value="<?= (int)$id ?>"><?= Html::encode($name) ?></option><?php endif; ?>
                <?php endforeach; ?>
                <?php if ($assigned): ?><option value="0">— никому (в «Новые»)</option><?php endif; ?>
            </select>
        <?php endif; ?>
        <?php if ($conversation->closed_at): ?>
            <button type="button" class="sx-chat-btn" data-sx-chat-do="reopen">Открыть снова</button>
        <?php else: ?>
            <button type="button" class="sx-chat-btn" data-sx-chat-do="close">Закрыть</button>
        <?php endif; ?>
    </span>
</div>
