<?php
/**
 * Messages of a chat as in messengers: the client on the left, ours on the
 * right (sent from the CRM or written by an employee in a group). Each message
 * carries its author and day: chats.js joins messages of one author into a
 * series (the name above the first, the photo beside the last) and puts the
 * day separators, also for messages that come later by polling.
 *
 * @var $this yii\web\View
 * @var $messages skeeks\cms\communication\models\CmsCommMessage[]
 * @var $conversation skeeks\cms\communication\models\CmsCommConversation
 */

use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\MailFiles;
use yii\helpers\Html;
use yii\helpers\Url;

$group = $conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE;
$check = '<svg viewBox="0 0 16 11" width="16" height="11" aria-hidden="true"><path d="M1 5.5l3.5 3.5L11 2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$clock = '<svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true"><circle cx="6" cy="6" r="4.8" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M6 3.4V6l1.8 1.2" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>';
$replyIcon = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z" fill="currentColor"/></svg>';
$taskIcon = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8.5 12.5l2.5 2.5 4.5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
// Tasks created from these messages: "Задача №N" under the message.
$tasks = [];
if ($messages) {
    foreach (\skeeks\cms\communication\models\CmsCommLink::find()
                 ->andWhere(['cms_comm_message_id' => array_map(static fn($m) => (int)$m->id, $messages), 'model_code' => \skeeks\cms\models\CmsTask::class,
                     'link_type' => \skeeks\cms\communication\models\CmsCommLink::TYPE_CREATED])
                 ->orderBy(['id' => SORT_ASC])->all() as $link) {
        $tasks[(int)$link->cms_comm_message_id][] = (int)$link->model_id;
    }
}
// Reactions under the messages, grouped by emoji; the employee's own are marked.
$reactions = \skeeks\cms\communication\chat\ChatReactions::summary(array_map(static fn($m) => (int)$m->id, $messages), (int)\Yii::$app->user->id);
foreach ($messages as $m):
    $ours = $m->direction === CmsCommMessage::DIRECTION_OUT || ($m->identity && $m->identity->isWorker());
    $author = ChatUi::author($m);
    $reply = $m->reply_to_message_id ? CmsCommMessage::findOne((int)$m->reply_to_message_id) : null;
    $key = $m->direction === CmsCommMessage::DIRECTION_OUT ? 'u'.(int)$m->sent_by_user_id : 'p'.(int)$m->cms_comm_identity_id.':'.$author;
    $time = (int)$m->message_at;
    $text = ChatUi::body($m);
    $images = $others = [];
    foreach ($m->files as $file) {
        if ($file->storage_file_id && MailFiles::isInlineType($file->mime_type)) {
            $images[] = $file;
        } else {
            $others[] = $file;
        }
    }
    $media = $images && trim($text) === '' && !$others && !$reply;
    // The name is shown in groups and on our side (several employees answer one client).
    $named = $group || $ours;
    $classes = ['sx-msg', $ours ? 'sx-msg--ours' : 'sx-msg--theirs'];
    if ($m->removed_at) {
        $classes[] = 'sx-msg--removed';
    }
    if ($media) {
        $classes[] = 'sx-msg--media';
    }
    $outgoing = $m->direction === CmsCommMessage::DIRECTION_OUT && !$m->is_history;
    ob_start(); ?>
    <span class="sx-msg__meta">
        <?php if ($m->removed_at): ?><span>удалено</span><?php elseif ($m->edited_at): ?><span>изменено</span><?php endif; ?>
        <span class="sx-msg__time" title="<?= Html::encode($time ? Yii::$app->formatter->asDatetime($time, 'd MMMM yyyy, HH:mm') : '') ?>"><?= $time ? Yii::$app->formatter->asTime($time, 'HH:mm') : '' ?></span>
        <?php if ($outgoing): ?>
            <span class="sx-msg__status sx-msg__status--<?= Html::encode($m->status) ?>" title="<?= Html::encode(ChatUi::status($m).($m->error ? ': '.$m->error : '')) ?>">
                <?php if ($m->status === CmsCommMessage::STATUS_SENT): ?><?= $check ?>
                <?php elseif ($m->status === CmsCommMessage::STATUS_FAILED || $m->status === CmsCommMessage::STATUS_UNKNOWN): ?>!
                <?php else: ?><?= $clock ?><?php endif; ?>
            </span>
        <?php endif; ?>
    </span>
    <?php $meta = ob_get_clean(); ?>
    <div class="<?= implode(' ', $classes) ?>" data-sx-msg="<?= (int)$m->id ?>" data-sx-author="<?= Html::encode($key) ?>"
         data-sx-day="<?= $time ? Html::encode(Yii::$app->formatter->asDate($time, 'yyyy-MM-dd')) : '' ?>" data-sx-day-title="<?= $time ? Html::encode(ChatUi::day($time)) : '' ?>">
        <span class="sx-msg__avatar-slot"><?= ChatUi::messageAvatar($m, $conversation, $author) ?></span>
        <div class="sx-msg__bubble" style="--sx-chat-hue: <?= ChatUi::hue($author) ?>">
            <?php if ($named): ?>
                <div class="sx-msg__author"><?= Html::encode($author) ?></div>
            <?php endif; ?>
            <?php if ($reply): ?>
                <div class="sx-msg__reply" data-sx-chat-goto="<?= (int)$reply->id ?>" role="button" tabindex="0" title="Перейти к сообщению"
                     style="--sx-chat-hue: <?= ChatUi::hue(ChatUi::author($reply)) ?>">
                    <b><?= Html::encode(ChatUi::author($reply)) ?></b>
                    <span><?= Html::encode(mb_substr(trim(preg_replace('/\s+/u', ' ', ChatUi::body($reply))), 0, 120) ?: 'Файл') ?></span>
                </div>
            <?php endif; ?>
            <?php if ($images): ?>
                <div class="sx-msg__images sx-msg__images--<?= min(count($images), 3) ?>">
                    <?php foreach ($images as $file): ?>
                        <?php $url = Url::to(['file', 'id' => (int)$file->id]); ?>
                        <a href="<?= Html::encode($url) ?>" target="_blank" rel="noopener" class="sx-msg__image">
                            <img src="<?= Html::encode($url) ?>" alt="<?= Html::encode($file->name) ?>" loading="lazy">
                        </a>
                    <?php endforeach; ?>
                    <?php if ($media): ?><?= $meta ?><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php foreach ($others as $file): ?>
                <?php $url = Url::to(['file', 'id' => (int)$file->id]); ?>
                <?php $info = $file->storage_file_id ? ChatUi::size($file->size) : ($file->fetch_error ?: 'загружается…'); ?>
                <?= Html::beginTag($file->storage_file_id ? 'a' : 'span', $file->storage_file_id
                    ? ['class' => 'sx-msg__file', 'href' => $url, 'download' => true, 'title' => 'Скачать']
                    : ['class' => 'sx-msg__file is-pending', 'title' => (string)$file->fetch_error]) ?>
                    <span class="sx-msg__file-icon"><?= Html::encode(ChatUi::extension((string)$file->name)) ?></span>
                    <span class="sx-msg__file-body">
                        <span class="sx-msg__file-name"><?= Html::encode($file->name) ?></span>
                        <span class="sx-msg__file-info"><?= Html::encode($info) ?></span>
                    </span>
                <?= Html::endTag($file->storage_file_id ? 'a' : 'span') ?>
            <?php endforeach; ?>
            <?php if (!$media): ?>
                <div class="sx-msg__text"><?= trim($text) !== '' ? ChatUi::text($text) : '' ?><?= $meta ?></div>
            <?php endif; ?>
            <?php if (!empty($tasks[(int)$m->id])): ?>
                <div class="sx-msg__tasks">
                    <?php foreach ($tasks[(int)$m->id] as $taskId): ?>
                        <?= \skeeks\cms\backend\widgets\BackendEntityLink::widget([
                            'controllerId' => '/cms/admin-cms-task',
                            'modelId' => $taskId,
                            'content' => $taskIcon.' Задача №'.$taskId,
                            'options' => ['class' => 'sx-msg__task', 'title' => 'Открыть задачу'],
                        ]) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($reactions[(int)$m->id])): ?>
                <div class="sx-msg__reactions">
                    <?php foreach ($reactions[(int)$m->id] as $r): ?>
                        <button type="button" class="sx-msg__reaction<?= $r['mine'] ? ' is-mine' : '' ?>" data-sx-chat-react="<?= Html::encode($r['emoji']) ?>"
                                title="<?= Html::encode(implode(', ', array_filter($r['names']))) ?>">
                            <span class="sx-msg__reaction-emoji"><?= Html::encode($r['emoji']) ?></span><?php if ($r['count'] > 1): ?><span><?= (int)$r['count'] ?></span><?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($m->status === CmsCommMessage::STATUS_FAILED || $m->status === CmsCommMessage::STATUS_UNKNOWN): ?>
                <div class="sx-msg__error"><?= Html::encode(ChatUi::status($m).($m->error ? ': '.$m->error : '')) ?></div>
            <?php endif; ?>
        </div>
        <?php if (!$m->removed_at): ?>
            <?php // Actions of the message: the menu by the right click (a long press on a phone) runs them. ?>
            <span class="sx-msg__actions" hidden>
                <?php if ($m->direction === CmsCommMessage::DIRECTION_IN): ?>
                    <button type="button" class="sx-msg__reply-btn" data-sx-chat-reply-to="<?= (int)$m->id ?>" data-sx-author-name="<?= Html::encode($author) ?>"
                            data-sx-text="<?= Html::encode($m->getSnippet(60) ?: 'Файл') ?>" title="Ответить" aria-label="Ответить"><?= $replyIcon ?></button>
                <?php endif; ?>
                <button type="button" class="sx-msg__reply-btn" data-sx-chat-task="<?= (int)$m->id ?>" title="Создать задачу из сообщения" aria-label="Создать задачу"><?= $taskIcon ?></button>
            </span>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
