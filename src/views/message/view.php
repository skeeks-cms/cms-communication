<?php
/**
 * A letter, opened in the right-side panel: actions, sender, recipients, CRM
 * links, the letter itself, attachments and the quick reply under it.
 * "Ответить", "Ответить всем" and "Переслать" open the letter editor right
 * here, under the letter.
 *
 * @var $this yii\web\View
 * @var $model skeeks\cms\communication\models\CmsCommMessage
 * @var $canReply bool
 * @var $showImages bool external images are shown in this view
 * @var $alwaysShowImages bool the employee shows them for every letter
 * @var $replyDraft bool the employee has started an answer to this letter (a draft)
 * @var $created array[] records created from this letter (MessageSorting::createdRecords())
 * @var $createActions array[] "Создать задачу / лид / сделку" the employee may open (MailUi::createActions())
 * @var $canManage bool the employee may move the letter in its mailbox and change its labels
 */

use skeeks\cms\communication\assets\MailClientAsset;
use skeeks\cms\communication\controllers\AdminCmsCommMessageController;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\models\CmsCommMessage;
use yii\helpers\Html;
use yii\helpers\Url;

MailClientAsset::register($this);
// The open letter reads like web mail: a plain white panel, not cards on the canvas.
$this->registerCss('body { background: var(--sx-color-surface, #fff) !important; }');

$incoming = $model->direction === CmsCommMessage::DIRECTION_IN;
$senderName = $model->from_name ?: (string)$model->from_address;

$formatter = \Yii::$app->formatter;
$at = (int)$model->message_at;
$time = $formatter->asTime($at, 'php:H:i');
if (date('Y-m-d', $at) === date('Y-m-d')) {
    $date = 'Сегодня в '.$time;
} elseif (date('Y-m-d', $at) === date('Y-m-d', strtotime('-1 day'))) {
    $date = 'Вчера в '.$time;
} else {
    $date = $formatter->asDate($at, 'long').' в '.$time;
}

$list = static function (array $addresses): string {
    $items = [];
    foreach ($addresses as $address => $name) {
        $items[] = $name ? $name.' <'.$address.'>' : $address;
    }
    return implode(', ', $items);
};
$replyAll = count($model->getTo()) + count($model->getCc()) > 1;
$created = $created ?? [];
$createActions = $createActions ?? [];
$conversation = $model->conversation;
$assignee = $conversation ? $conversation->assignedUser : null;
$composeUrl = static fn(array $params) => Url::to(array_merge(['create', 'inline' => 1], $params));
$canManage = !empty($canManage);
$role = MailUi::mailboxRole($model);
$moveActions = [];
if ($canManage) {
    $moveActions[] = in_array($role, ['trash', 'gone'], true)
        ? ['restore', 'undo', 'Восстановить', 'Вернуть во «Входящие» (или «Отправленные»)']
        : ['trash', 'trash', 'Удалить', 'В корзину почтового ящика (Delete)'];
    $moveActions[] = in_array($role, ['archive', 'other'], true)
        ? ['restore', 'inbox', 'Во входящие', 'Вернуть во «Входящие» (или «Отправленные»)']
        : ['archive', 'archive', 'В архив', 'В архив почтового ящика (e)'];
    $moveActions[] = $role === 'junk'
        ? ['restore', 'inbox', 'Не спам', 'Не спам: вернуть во «Входящие» (!)']
        : ['junk', 'spam', 'Это спам', 'В спам: почтовый сервис учится на этом (!)'];
}
?>
<div class="sx-letter" data-sx-letter data-id="<?= (int)$model->id ?>"
     data-mark-url="<?= Html::encode(Url::to(['mark'])) ?>"
     data-settings-url="<?= Html::encode(Url::to(['settings'])) ?>"
     data-frame-url="<?= Html::encode(Url::to(['view', 'pk' => $model->id, 'frame' => 1])) ?>"
     data-fetch-url="<?= Html::encode(Url::to(['fetch', 'pk' => $model->id])) ?>"
     data-fetch-status-url="<?= Html::encode(Url::to(['fetch-status', 'pk' => $model->id])) ?>"
     data-unlink-url="<?= Html::encode(Url::to(['unlink', 'pk' => $model->id])) ?>"
     data-assign-url="<?= Html::encode(Url::to(['assign', 'pk' => $model->id])) ?>"
     data-move-url="<?= Html::encode(Url::to(['move'])) ?>"
     data-server-folders-url="<?= Html::encode(Url::to(['server-folders'])) ?>"
     data-labels-url="<?= Html::encode(Url::to(['labels'])) ?>"
     data-label-save-url="<?= Html::encode(Url::to(['label-save'])) ?>">
    <div class="sx-letter__toolbar">
        <?php if ($canReply) : ?>
            <button type="button" class="sx-mail__tool" data-sx-letter-compose="<?= Html::encode($composeUrl(['reply_to' => $model->id])) ?>" title="Ответить (r)">
                <?= MailUi::icon('reply', 17) ?> <span class="sx-mail__tool-text">Ответить</span>
            </button>
            <?php if ($replyAll) : ?>
                <button type="button" class="sx-mail__tool" data-sx-letter-compose="<?= Html::encode($composeUrl(['reply_to' => $model->id, 'all' => 1])) ?>" data-sx-letter-reply-all title="Ответить всем (a)">
                    <?= MailUi::icon('reply-all', 17) ?> <span class="sx-mail__tool-text">Ответить всем</span>
                </button>
            <?php endif; ?>
            <button type="button" class="sx-mail__tool" data-sx-letter-compose="<?= Html::encode($composeUrl(['forward' => $model->id])) ?>" data-sx-letter-forward title="Переслать (f)">
                <?= MailUi::icon('forward', 17) ?> <span class="sx-mail__tool-text">Переслать</span>
            </button>
        <?php endif; ?>
        <?php // A letter collected from the mailbox history counts as read. ?>
        <?php if ($incoming && !$model->is_history) : ?>
            <button type="button" class="sx-mail__tool" data-sx-letter-unread title="Shift + u"><?= MailUi::icon('mail', 17) ?> <span class="sx-mail__tool-text">Не прочитано</span></button>
        <?php endif; ?>
        <?php if ($moveActions) : ?>
            <?php foreach ($moveActions as $a) : ?>
                <button type="button" class="sx-mail__tool sx-mail__tool--icon" data-sx-letter-move="<?= Html::encode($a[0]) ?>" title="<?= Html::encode($a[3]) ?>">
                    <?= MailUi::icon($a[1], 17) ?> <span class="sx-mail__tool-text"><?= Html::encode($a[2]) ?></span>
                </button>
            <?php endforeach; ?>
            <span class="sx-mail__menu-wrap">
                <button type="button" class="sx-mail__tool sx-mail__tool--icon" data-sx-letter-folder-menu aria-haspopup="true" title="В папку почтового ящика (v)">
                    <?= MailUi::icon('folder', 17) ?> <span class="sx-mail__tool-text">В папку</span>
                </button>
                <span class="sx-mail__menu" data-sx-letter-floating hidden></span>
            </span>
            <span class="sx-mail__menu-wrap">
                <button type="button" class="sx-mail__tool sx-mail__tool--icon" data-sx-letter-labels-menu aria-haspopup="true" title="Метки (m)">
                    <?= MailUi::icon('tag', 17) ?> <span class="sx-mail__tool-text">Метки</span>
                </button>
                <span class="sx-mail__menu" data-sx-letter-floating hidden></span>
            </span>
        <?php endif; ?>
        <span class="sx-letter__toolbar-gap"></span>
        <button type="button" class="sx-mail__tool" data-sx-letter-link="<?= Html::encode(MailUi::linkUrl((int)$model->id)) ?>" title="Привязать к лиду, компании, клиенту или сделке (l)">
            <?= MailUi::icon('link', 17) ?> <span class="sx-mail__tool-text">Привязать</span>
        </button>
        <?php if ($createActions) : ?>
            <span class="sx-mail__menu-wrap">
                <button type="button" class="sx-mail__tool" data-sx-mail-menu-toggle aria-haspopup="true" title="Создать задачу, лид или сделку из письма">
                    <?= MailUi::icon('plus', 17) ?> <span class="sx-mail__tool-text">Создать</span> <?= MailUi::icon('chevron-down', 14) ?>
                </button>
                <span class="sx-mail__menu" data-sx-mail-menu hidden>
                    <?php foreach ($createActions as $type => $action) : ?>
                        <button type="button" class="sx-mail__menu-item" data-sx-letter-create="<?= Html::encode($action['url']) ?>" data-type="<?= Html::encode($type) ?>">
                            <?= MailUi::icon($action['icon'], 16) ?> <?= Html::encode($action['title']) ?>
                        </button>
                    <?php endforeach; ?>
                </span>
            </span>
        <?php endif; ?>
        <span class="sx-mail__menu-wrap">
            <button type="button" class="sx-mail__tool" data-sx-letter-assign aria-haspopup="true" title="Ответственный за переписку">
                <?= MailUi::icon('user', 17) ?> <span class="sx-mail__tool-text">Ответственный</span> <?= MailUi::icon('chevron-down', 14) ?>
            </button>
            <span class="sx-mail__menu sx-mail__menu--right" data-sx-letter-assign-menu hidden></span>
        </span>
    </div>

    <div class="sx-letter__head">
        <span class="sx-mail__avatar sx-letter__avatar" style="--sx-mail-hue: <?= MailUi::hue((string)$model->from_address ?: $senderName) ?>"><?= Html::encode(MailUi::initials($senderName)) ?></span>
        <div class="sx-letter__from">
            <div>
                <span class="sx-letter__name"><?= Html::encode($senderName) ?></span>
                <?php if ($model->from_name) : ?>
                    <span class="sx-letter__muted"><?= Html::encode($model->from_address) ?></span>
                <?php endif; ?>
                <span class="sx-letter__muted sx-letter__date"><?= Html::encode($date) ?></span>
            </div>
            <div class="sx-letter__muted">Кому: <?= Html::encode($list($model->getTo()) ?: '—') ?></div>
            <?php if ($model->getCc()) : ?>
                <div class="sx-letter__muted">Копия: <?= Html::encode($list($model->getCc())) ?></div>
            <?php endif; ?>
            <?php if ($model->getBcc()) : ?>
                <div class="sx-letter__muted">Скрытая копия: <?= Html::encode($list($model->getBcc())) ?></div>
            <?php endif; ?>
            <?php if (!$incoming) : ?>
                <div class="sx-letter__muted">
                    <?= Html::encode($model->getStatusName()) ?><?= $model->sentBy ? ' · отправил '.Html::encode($model->sentBy->shortDisplayName) : '' ?>
                    <?php if ($model->error) : ?><div class="sx-letter__error"><?= Html::encode($model->error) ?></div><?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="sx-letter__muted sx-letter__assignee" data-sx-letter-assignee>
                <?php if ($assignee) : ?>
                    Ответственный: <strong><?= Html::encode($assignee->shortDisplayName) ?></strong>
                <?php else : ?>
                    Ответственный не назначен
                <?php endif; ?>
            </div>
            <?= MailUi::createdMarks($created) ?>
            <?php if ($tags = MailUi::mailboxTags($model)) : ?>
                <div class="sx-letter__tags"><?= $tags ?></div>
            <?php endif; ?>
            <?php foreach ($model->mailboxCopies as $copy) : ?>
                <?php if ($copy->sync_error) : ?>
                    <div class="sx-letter__error"><?= MailUi::icon('info', 14) ?> <?= Html::encode($copy->sync_error) ?></div>
                    <?php break; ?>
                <?php elseif ($copy->folder_role === 'gone') : ?>
                    <div class="sx-letter__muted">Письмо удалено в почтовом ящике: в CRM осталась только его копия.</div>
                    <?php break; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?= MailUi::linkedRecords($model, true) ?>
    </div>

    <div class="sx-letter__body">
        <?php if ($model->body_html) : ?>
            <?php
            $blocked = 0;
            $doc = AdminCmsCommMessageController::letterDocument($model, $showImages, $blocked);
            ?>
            <?php // Hidden images: show once or always; shown by the setting: switch it off right here. ?>
            <div class="sx-letter__images" data-sx-letter-images <?= $blocked || $alwaysShowImages ? '' : 'hidden' ?>>
                <?= MailUi::icon('image', 16) ?>
                <span data-sx-letter-images-text><?= $blocked
                    ? 'Картинки из интернета скрыты: так отправитель не узнает, что письмо открыто.'
                    : 'Картинки показываются сразу.' ?></span>
                <button type="button" class="sx-compose__link" data-sx-letter-images-show <?= $blocked ? '' : 'hidden' ?>>Показать картинки</button>
                <button type="button" class="sx-compose__link" data-sx-letter-images-always="1" <?= $alwaysShowImages ? 'hidden' : '' ?>>Показывать всегда</button>
                <button type="button" class="sx-compose__link" data-sx-letter-images-always="0" <?= $alwaysShowImages ? '' : 'hidden' ?>>Не показывать сразу</button>
            </div>
            <?php
            // Images inside the text that the CRM does not keep come from the mailbox while the letter is open.
            // A letter received before files were kept: its saved list says whether it carries images.
            $missingImages = (bool)array_filter($model->files, static fn($f) => $f->is_inline && !$f->isStored())
                || (!$model->files && stripos((string)$model->body_html, 'cid:') !== false
                    && array_filter($model->getAttachmentList(), static fn($a) => strpos((string)($a['type'] ?? ''), 'image/') === 0));
            ?>
            <?php if ($missingImages) : ?>
                <div class="sx-letter__images sx-letter__fetching" data-sx-letter-fetch-images>
                    <span class="sx-letter__spinner" aria-hidden="true"></span>
                    <span data-sx-letter-fetch-text>Картинки письма загружаются из почтового ящика…</span>
                </div>
            <?php endif; ?>
            <?php // The letter goes into its frame after the panel has opened: its images never delay opening.
            // allow-same-origin only lets this page measure the frame height. ?>
            <iframe class="sx-letter__frame" scrolling="no" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                    data-srcdoc="<?= Html::encode($doc) ?>" data-sx-letter-frame></iframe>
        <?php else : ?>
            <div class="sx-letter__text"><?= Html::encode((string)$model->body_text) ?></div>
        <?php endif; ?>
    </div>

    <?php // Stored files download through the access check; letters received before storing keep only names. ?>
    <?php if ($files = $model->getAttachmentFiles()) : ?>
        <div class="sx-letter__attachments">
            <div class="sx-letter__attachments-title"><?= MailUi::icon('paperclip', 15) ?> <?= count($files) ?> <?= count($files) === 1 ? 'вложение' : (count($files) < 5 ? 'вложения' : 'вложений') ?></div>
            <?php foreach ($files as $file) : ?>
                <?php $url = \skeeks\cms\communication\services\MailFiles::url($file); ?>
                <?php $downloadUrl = \yii\helpers\Url::to([\skeeks\cms\communication\services\MailFiles::ROUTE_FILE, 'id' => $file->id, 'download' => 1]); ?>
                <?php if ($file->isStored()) : ?>
                    <a class="sx-letter__file" href="<?= Html::encode($downloadUrl) ?>"
                       data-pjax="0" title="Скачать «<?= Html::encode($file->name) ?>»" download>
                        <?php if (\skeeks\cms\communication\services\MailFiles::isInlineType($file->storageFile->mime_type ?? null)) : ?>
                            <img class="sx-letter__file-thumb" src="<?= Html::encode($url) ?>" alt="" loading="lazy">
                        <?php else : ?>
                            <span class="sx-letter__file-icon"><?= MailUi::icon('file', 22) ?></span>
                        <?php endif; ?>
                        <span class="sx-letter__file-text">
                            <span class="sx-letter__file-name"><?= Html::encode($file->name) ?></span>
                            <span class="sx-letter__muted"><?= $file->size ? Html::encode($formatter->asShortSize((int)$file->size)) : '' ?></span>
                        </span>
                    </a>
                <?php else : ?>
                    <?php // Not kept in the CRM: fetched from the mailbox on click, then downloaded. ?>
                    <button type="button" class="sx-letter__file is-remote" data-sx-letter-fetch-file="<?= (int)$file->id ?>"
                            title="Файл хранится в почтовом ящике: загрузить и скачать «<?= Html::encode($file->name) ?>»">
                        <span class="sx-letter__file-icon"><?= MailUi::icon('file', 22) ?></span>
                        <span class="sx-letter__file-text">
                            <span class="sx-letter__file-name"><?= Html::encode($file->name) ?></span>
                            <span class="sx-letter__muted" data-sx-letter-file-state><?= $file->fetch_error
                                ? Html::encode($file->fetch_error)
                                : Html::encode(trim(($file->size ? $formatter->asShortSize((int)$file->size).' · ' : '').'в почтовом ящике')) ?></span>
                        </span>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php elseif (!$model->files && ($attachments = $model->getAttachmentList())) : ?>
        <div class="sx-letter__attachments">
            <?php // A letter received before files were kept: its files are described and fetched from the mailbox on click. ?>
            <?php foreach ($attachments as $file) : ?>
                <button type="button" class="sx-letter__file is-remote" data-sx-letter-fetch-all
                        title="Файл хранится в почтовом ящике: загрузить вложения письма">
                    <span class="sx-letter__file-icon"><?= MailUi::icon('file', 22) ?></span>
                    <span class="sx-letter__file-text">
                        <span class="sx-letter__file-name"><?= Html::encode($file['name'] ?? '') ?></span>
                        <span class="sx-letter__muted" data-sx-letter-file-state><?= Html::encode(trim((isset($file['size']) ? $formatter->asShortSize((int)$file['size']).' · ' : '').'в почтовом ящике')) ?></span>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($canReply) : ?>
        <div class="sx-letter__reply">
            <button type="button" class="sx-letter__reply-bar" data-sx-letter-compose="<?= Html::encode($composeUrl(['reply_to' => $model->id])) ?>" data-sx-letter-reply-bar>
                <?= MailUi::icon('reply', 17) ?> <span><?= !empty($replyDraft)
                    ? 'Продолжить черновик ответа'
                    : 'Ответить'.($incoming ? ' '.Html::encode($senderName) : '') ?></span>
            </button>
            <div class="sx-letter__compose" data-sx-letter-compose-box></div>
            <div class="sx-letter__sent" data-sx-letter-sent hidden></div>
        </div>
    <?php endif; ?>
</div>
