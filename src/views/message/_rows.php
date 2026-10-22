<?php
/**
 * Rows of the letter list; also returned alone for "Показать ещё".
 *
 * @var $this yii\web\View
 * @var $messages skeeks\cms\communication\models\CmsCommMessage[]
 * @var $readIds int[]
 */

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\models\CmsCommMessage;
use yii\helpers\Html;

$formatter = \Yii::$app->formatter;
$shortDate = static function ($timestamp) use ($formatter) {
    $timestamp = (int)$timestamp;
    return date('Y-m-d', $timestamp) === date('Y-m-d')
        ? $formatter->asTime($timestamp, 'php:H:i')
        : (date('Y', $timestamp) === date('Y') ? $formatter->asDate($timestamp, 'php:j M') : $formatter->asDate($timestamp, 'php:d.m.y'));
};
?>
<?php foreach ($messages as $m) : ?>
    <?php
    $incoming = $m->direction === CmsCommMessage::DIRECTION_IN;
    // A letter collected from the mailbox history counts as read and cannot be marked unread.
    $readable = $incoming && !$m->is_history;
    $unread = $readable && !in_array((int)$m->id, $readIds, true);
    $name = $incoming ? ($m->from_name ?: (string)$m->from_address) : 'Кому: '.(implode(', ', array_keys($m->getTo())) ?: '—');
    $avatarKey = $incoming ? (string)$m->from_address : (string)array_key_first($m->getTo());
    $tags = [];
    foreach (MailUi::visibleLinks($m) as $link) {
        $record = $link->getLinkedModel();
        $tags[] = $record && method_exists($record, 'asText') ? $record->asText() : '#'.$link->model_id;
    }
    $status = !$incoming && $m->status !== CmsCommMessage::STATUS_SENT ? $m->getStatusName() : '';
    $viewUrl = (string)BackendUrlHelper::createByParams(['view', 'pk' => $m->id])->enableEmptyLayout()->enableNoActions()->url;
    ?>
    <div class="sx-mail__row<?= $unread ? ' is-unread' : '' ?>" data-id="<?= (int)$m->id ?>" data-at="<?= (int)$m->message_at ?>"
         data-url="<?= Html::encode($viewUrl) ?>" tabindex="-1" role="button">
        <label class="sx-mail__check" title="Выбрать (x)"><input type="checkbox" data-sx-mail-select value="<?= (int)$m->id ?>" tabindex="-1"></label>
        <span class="sx-mail__avatar" style="--sx-mail-hue: <?= (int)MailUi::hue($avatarKey ?: $name) ?>"><?= Html::encode(MailUi::initials($incoming ? $name : ($avatarKey ?: '?'))) ?></span>
        <span class="sx-mail__who" title="<?= Html::encode($incoming ? $m->getFromAsText() : $m->getToAsText()) ?>"><?= Html::encode($name) ?></span>
        <button type="button" class="sx-mail__dot" data-sx-mail-toggle-read
                title="<?= $readable ? ($unread ? 'Отметить прочитанным' : 'Отметить непрочитанным') : '' ?>" <?= $readable ? '' : 'disabled' ?> tabindex="-1"></button>
        <span class="sx-mail__line">
            <span class="sx-mail__subject"><?= Html::encode($m->subject ?: '(без темы)') ?></span>
            <span class="sx-mail__snippet"><?= Html::encode($m->getSnippet(200)) ?></span>
        </span>
        <span class="sx-mail__meta">
            <?php foreach (array_unique($tags) as $tag) : ?>
                <span class="sx-mail__tag" title="Связано с CRM"><?= Html::encode($tag) ?></span>
            <?php endforeach; ?>
            <?php if ($status) : ?>
                <span class="sx-mail__tag sx-mail__tag--status"><?= Html::encode($status) ?></span>
            <?php endif; ?>
            <?php if ($m->getAttachmentList()) : ?>
                <span class="sx-mail__clip" title="Есть вложения"><?= MailUi::icon('paperclip', 16) ?></span>
            <?php endif; ?>
        </span>
        <span class="sx-mail__date" title="<?= Html::encode($formatter->asDatetime($m->message_at, 'php:d.m.Y H:i')) ?>"><?= Html::encode($shortDate($m->message_at)) ?></span>
    </div>
<?php endforeach; ?>
