<?php
/**
 * The side panel of an open chat, as in messengers: the big picture, the
 * name, the setting "Сообщать в чат о задачах", the chat members with their
 * photos and roles; "Это наш сотрудник" marks a member as an employee.
 *
 * @var $this yii\web\View
 * @var $conversation skeeks\cms\communication\models\CmsCommConversation
 * @var $canEdit bool the employee may write to the chat
 * @var $workers string[] user id => name: active employees to choose from
 */

use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommParticipant;
use yii\helpers\Html;

$group = $conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE;
$members = $conversation->getParticipants()->andWhere(['left_at' => null])->with(['identity', 'identity.cmsUser'])->all();
$order = [CmsCommParticipant::ROLE_CREATOR => 0, CmsCommParticipant::ROLE_ADMIN => 1];
usort($members, static function (CmsCommParticipant $a, CmsCommParticipant $b) use ($order) {
    return [$order[$a->role] ?? 2, $a->identity ? $a->identity->asText() : ''] <=> [$order[$b->role] ?? 2, $b->identity ? $b->identity->asText() : ''];
});
$roles = [CmsCommParticipant::ROLE_CREATOR => 'владелец', CmsCommParticipant::ROLE_ADMIN => 'админ'];
?>
<div class="sx-chat-info__card">
    <span class="sx-chat-info__photo"><?= ChatUi::avatar($conversation) ?></span>
    <div class="sx-chat-info__name"><?= Html::encode($conversation->asText()) ?></div>
    <div class="sx-chat-info__sub">
        <?= Html::encode(ChatUi::channelName($conversation)) ?> · <?= Html::encode(CmsCommConversation::chatTypes()[$conversation->chat_type] ?? '') ?>
    </div>
</div>
<div class="sx-chat-info__section">
    <label class="sx-chat-info__switch" title="Задачи ставят командой /task или «@бот задача …»: бот отвечает номером задачи, а потом сообщает, что она принята, выполнена или отменена">
        <input type="checkbox" data-sx-chat-task-notify <?= (int)$conversation->task_notify ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
        <span>Сообщать в чат о задачах</span>
    </label>
    <div class="sx-chat-info__hint">Задачу в чате ставят командой <b>/task текст</b> или ответом <b>/task</b> на сообщение.</div>
</div>
<?php if ($group): ?>
    <div class="sx-chat-info__section">
        <div class="sx-chat-info__title"><?= count($members) ? Html::encode(ChatUi::members(count($members))) : 'Участники' ?></div>
        <?php if (!$members): ?>
            <div class="sx-chat-info__empty">Мессенджер пока не передал список участников.</div>
        <?php endif; ?>
        <?php foreach ($members as $p): ?>
            <?php $identity = $p->identity; $name = $identity ? $identity->asText() : 'Участник'; $ours = $identity && $identity->isWorker(); ?>
            <div class="sx-chat-member">
                <?= ChatUi::personAvatar($identity, $conversation, $name) ?>
                <span class="sx-chat-member__body">
                    <span class="sx-chat-member__name"><?= Html::encode($name) ?></span>
                    <span class="sx-chat-member__sub">
                        <?php if ($ours): ?>
                            <?php
                            // The CRM name only when it is another person's name, not the same words in another order.
                            $words = static fn(string $s) => array_unique(preg_split('/\s+/u', mb_strtolower(trim($s)), -1, PREG_SPLIT_NO_EMPTY));
                            $crmName = $identity->cmsUser ? trim((string)$identity->cmsUser->shortDisplayName) : '';
                            $same = $crmName === '' || !array_diff($words($crmName), $words($name)) || !array_diff($words($name), $words($crmName));
                            ?>
                            <span class="sx-chat-member__ours">сотрудник<?= $same ? '' : ' — '.Html::encode($crmName) ?></span>
                        <?php elseif ($identity && $identity->is_bot): ?>
                            бот
                        <?php elseif ($identity && $identity->username): ?>
                            @<?= Html::encode($identity->username) ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($canEdit && $identity && !$identity->is_bot): ?>
                        <select class="sx-chat-member__worker" data-sx-chat-worker="<?= (int)$identity->id ?>" hidden aria-label="Кто из сотрудников">
                            <option value="">Кто из сотрудников?</option>
                            <?php foreach ($workers as $id => $workerName): ?>
                                <option value="<?= (int)$id ?>"><?= Html::encode($workerName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </span>
                <?php if (isset($roles[$p->role])): ?><span class="sx-chat-member__role"><?= $roles[$p->role] ?></span><?php endif; ?>
                <?php if ($canEdit && $identity && !$identity->is_bot): ?>
                    <?php if ($ours): ?>
                        <button type="button" class="sx-chat-member__action" data-sx-chat-unworker="<?= (int)$identity->id ?>" title="Это не наш сотрудник">×</button>
                    <?php else: ?>
                        <button type="button" class="sx-chat-member__action" data-sx-chat-worker-open="<?= (int)$identity->id ?>" title="Это наш сотрудник: его сообщения — наши ответы">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="9" cy="8" r="3.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M2.5 19c.8-3.2 3.3-5 6.5-5s5.7 1.8 6.5 5M16 11l2 2 4-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
