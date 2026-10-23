<?php
/**
 * Rows of the "Черновики" folder: a draft opens in the letter window and is
 * written further there; × removes it with its files.
 *
 * @var $this yii\web\View
 * @var $drafts skeeks\cms\communication\models\CmsCommDraft[]
 */

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\AiMark;
use yii\helpers\Html;

$formatter = \Yii::$app->formatter;
$shortDate = static function ($timestamp) use ($formatter) {
    $timestamp = (int)$timestamp;
    return date('Y-m-d', $timestamp) === date('Y-m-d')
        ? $formatter->asTime($timestamp, 'php:H:i')
        : (date('Y', $timestamp) === date('Y') ? $formatter->asDate($timestamp, 'php:j M') : $formatter->asDate($timestamp, 'php:d.m.y'));
};
?>
<?php foreach ($drafts as $d) : ?>
    <?php
    $to = $d->getToAddresses();
    $name = $to ? 'Кому: '.implode(', ', $to) : 'Получатель не указан';
    $kind = $d->forward_message_id ? 'Пересылка' : ($d->reply_to_message_id ? ($d->is_reply_all ? 'Ответ всем' : 'Ответ') : '');
    $url = (string)BackendUrlHelper::createByParams(['create', 'draft' => $d->id])->enableEmptyLayout()->enableNoActions()->url;
    $attached = array_filter($d->files, static fn($f) => !$f->is_inline) || $d->getFileIds();
    ?>
    <div class="sx-mail__row sx-mail__row--draft" data-id="draft-<?= (int)$d->id ?>" data-draft-id="<?= (int)$d->id ?>"
         data-url="<?= Html::encode($url) ?>" tabindex="-1" role="button">
        <span class="sx-mail__check"></span>
        <span class="sx-mail__avatar" style="--sx-mail-hue: <?= (int)MailUi::hue((string)($to[0] ?? '?')) ?>"><?= Html::encode(MailUi::initials((string)($to[0] ?? '?'))) ?></span>
        <span class="sx-mail__who" title="<?= Html::encode($name) ?>"><span class="sx-mail__draft-mark">Черновик</span> <?= AiMark::badge(AiMark::of($d)) ?> <?= Html::encode($name) ?></span>
        <span class="sx-mail__dot" aria-hidden="true"></span>
        <span class="sx-mail__line">
            <span class="sx-mail__subject"><?= Html::encode($d->subject ?: '(без темы)') ?></span>
            <span class="sx-mail__snippet"><?= Html::encode($d->getSnippet(200)) ?></span>
        </span>
        <span class="sx-mail__meta">
            <?php if ($kind) : ?>
                <span class="sx-mail__tag" title="<?= Html::encode($d->replyTo ? 'На письмо: '.($d->replyTo->subject ?: '(без темы)') : ($d->forwarded ? 'Письмо: '.($d->forwarded->subject ?: '(без темы)') : '')) ?>"><?= Html::encode($kind) ?></span>
            <?php endif; ?>
            <?php if ($attached) : ?>
                <span class="sx-mail__clip" title="Есть вложения"><?= MailUi::icon('paperclip', 16) ?></span>
            <?php endif; ?>
            <button type="button" class="sx-mail__draft-delete" data-sx-mail-draft-delete="<?= (int)$d->id ?>" title="Удалить черновик" aria-label="Удалить черновик">×</button>
        </span>
        <span class="sx-mail__date" title="Изменён <?= Html::encode($formatter->asDatetime($d->updated_at, 'php:d.m.Y H:i')) ?>"><?= Html::encode($shortDate($d->updated_at)) ?></span>
    </div>
<?php endforeach; ?>
