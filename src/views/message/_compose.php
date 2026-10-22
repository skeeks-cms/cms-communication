<?php
/**
 * The letter editor: recipients with suggestions, "Копия" / "Скрытая копия"
 * on demand, sender mailbox, subject, formatted text, the answered letter
 * quoted or the forwarded one attached. Used as the "Написать" window and
 * inline under an open letter (quick reply). mail.js submits it with AJAX.
 *
 * @var $this yii\web\View
 * @var $form skeeks\cms\communication\forms\ComposeForm
 * @var $accounts skeeks\cms\communication\models\CmsCommAccount[]
 * @var $contextRecord yii\db\ActiveRecord|null
 * @var $original skeeks\cms\communication\models\CmsCommMessage|null answered or forwarded letter
 * @var $inline bool
 * @var $files array[] attached files: id, name, size, type
 */

use skeeks\cms\communication\helpers\MailUi;
use yii\helpers\Html;
use yii\helpers\Url;

$name = static fn(string $attribute) => Html::getInputName($form, $attribute);
$forward = (bool)$form->forward_message_id;
$reply = (bool)$form->reply_to_message_id;
$accountTitle = static fn($a) => $a->name ? $a->name.' <'.$a->address.'>' : $a->address;
$current = null;
foreach ($accounts as $account) {
    if ((int)$account->id === (int)$form->cms_comm_account_id) {
        $current = $account;
    }
}
$field = static function (string $label, string $attribute, string $input, bool $hidden = false) {
    return Html::tag('div',
        Html::tag('label', Html::encode($label), ['class' => 'sx-compose__label'])
        .Html::tag('div', $input, ['class' => 'sx-compose__control'])
        .Html::tag('div', '', ['class' => 'sx-compose__error', 'data-sx-compose-error' => $attribute]),
        ['class' => 'sx-compose__row', 'data-sx-compose-row' => $attribute, 'hidden' => $hidden]);
};
$recipients = static fn(string $attribute, string $value, bool $autofocus = false) => Html::tag('div',
    Html::input('text', null, '', [
        'class' => 'sx-compose__chips-input',
        'autocomplete' => 'off',
        'data-sx-compose-chips-input' => true,
        'aria-label' => 'Адрес получателя',
        'autofocus' => $autofocus,
    ])
    .Html::hiddenInput($attribute, $value, ['data-sx-compose-value' => true]),
    ['class' => 'sx-compose__chips', 'data-sx-compose-chips' => true]);
?>
<form class="sx-compose<?= $inline ? ' sx-compose--inline' : '' ?>" data-sx-compose
      action="<?= Html::encode(Url::to(['create'])) ?>" method="post" novalidate
      data-contacts-url="<?= Html::encode(Url::to(['contacts'])) ?>"
      data-upload-url="<?= Html::encode(Url::to(['upload'])) ?>"
      data-max-size="<?= \skeeks\cms\communication\services\MailFiles::MAX_SIZE ?>">
    <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
    <?= Html::hiddenInput($name('context_model_code'), $form->context_model_code) ?>
    <?= Html::hiddenInput($name('context_model_id'), $form->context_model_id) ?>
    <?= Html::hiddenInput($name('reply_to_message_id'), $form->reply_to_message_id) ?>
    <?= Html::hiddenInput($name('forward_message_id'), $form->forward_message_id) ?>

    <?php if ($inline) : ?>
        <div class="sx-compose__mode">
            <?= MailUi::icon($forward ? 'forward' : 'reply', 16) ?>
            <span><?= $forward ? 'Пересылка письма' : ($form->cc ? 'Ответ всем' : 'Ответ') ?></span>
        </div>
    <?php endif; ?>

    <div class="sx-compose__row" data-sx-compose-row="to">
        <label class="sx-compose__label">Кому</label>
        <div class="sx-compose__control">
            <?= $recipients($name('to'), $form->to, !$reply) ?>
        </div>
        <div class="sx-compose__more">
            <button type="button" class="sx-compose__link" data-sx-compose-show="cc" <?= $form->cc ? 'hidden' : '' ?>>Копия</button>
            <button type="button" class="sx-compose__link" data-sx-compose-show="bcc" <?= $form->bcc ? 'hidden' : '' ?>>Скрытая</button>
            <?php if ($inline && count($accounts) > 1) : ?>
                <button type="button" class="sx-compose__link" data-sx-compose-show="cms_comm_account_id">От кого</button>
            <?php endif; ?>
        </div>
        <div class="sx-compose__error" data-sx-compose-error="to"></div>
    </div>
    <?= $field('Копия', 'cc', $recipients($name('cc'), $form->cc), !$form->cc) ?>
    <?= $field('Скрытая копия', 'bcc', $recipients($name('bcc'), $form->bcc), !$form->bcc) ?>
    <?= $field('От кого', 'cms_comm_account_id',
        count($accounts) > 1
            ? Html::dropDownList($name('cms_comm_account_id'), $form->cms_comm_account_id,
                \yii\helpers\ArrayHelper::map($accounts, 'id', $accountTitle), ['class' => 'sx-compose__select'])
            : Html::hiddenInput($name('cms_comm_account_id'), $form->cms_comm_account_id)
                .Html::tag('span', Html::encode($current ? $accountTitle($current) : '—'), ['class' => 'sx-compose__static']),
        // The full window shows the sender at once; the quick reply keeps it behind "От кого".
        $inline) ?>
    <?= $field('Тема', 'subject',
        Html::input('text', $name('subject'), $form->subject, ['class' => 'sx-compose__input', 'maxlength' => 500, 'autocomplete' => 'off']),
        $inline && !$forward) ?>

    <?php if ($contextRecord) : ?>
        <div class="sx-compose__context">
            <?= MailUi::icon('info', 15) ?>
            Письмо попадёт в ленту: <strong><?= Html::encode(method_exists($contextRecord, 'asText') ? $contextRecord->asText() : '#'.$contextRecord->primaryKey) ?></strong>
        </div>
    <?php endif; ?>

    <div class="sx-compose__editor-wrap">
        <?= \skeeks\cms\widgets\formInputs\richText\RichTextInputWidget::widget([
            'name'        => $name('body_html'),
            'value'       => $form->getHtml(),
            'options'     => ['id' => 'sx-compose-body-'.substr(md5(uniqid('', true)), 0, 8), 'data-sx-compose-body' => true],
            'preset'      => \skeeks\cms\communication\forms\ComposeForm::EDITOR_PRESET,
            'tools'       => \skeeks\cms\communication\forms\ComposeForm::EDITOR_TOOLS,
            'uploadUrl'   => ['upload'],
            'maxImageSize' => \skeeks\cms\communication\services\MailFiles::MAX_SIZE,
            'placeholder' => 'Напишите письмо…',
            'paragraph'   => 'div',
            'plain'       => true,
            'minHeight'   => $inline ? 140 : 220,
            'attachLabel' => 'Прикрепить файлом',
            'wrapperOptions' => ['data-sx-compose-rte' => true],
        ]) ?>
        <div class="sx-compose__error" data-sx-compose-error="body"></div>
    </div>

    <?php // Attachments: the button, drag and drop onto the form, or paste. ?>
    <div class="sx-compose__files" data-sx-compose-files>
        <?= Html::hiddenInput($name('file_ids'), '') ?>
        <?= Html::hiddenInput($name('uploads'), '', ['data-sx-compose-uploads' => true]) ?>
        <?php foreach ($files as $file) : ?>
            <span class="sx-compose__file" data-sx-compose-file="<?= (int)$file['id'] ?>">
                <?= MailUi::icon('paperclip', 14) ?>
                <span class="sx-compose__file-name" title="<?= Html::encode($file['name']) ?>"><?= Html::encode($file['name']) ?></span>
                <span class="sx-compose__file-size"><?= Html::encode(\Yii::$app->formatter->asShortSize($file['size'])) ?></span>
                <button type="button" class="sx-compose__chip-remove" data-sx-compose-file-remove aria-label="Убрать файл">×</button>
                <?= Html::hiddenInput($name('file_ids').'[]', (int)$file['id']) ?>
            </span>
        <?php endforeach; ?>
    </div>
    <div class="sx-compose__error" data-sx-compose-error="file_ids"></div>
    <input type="file" multiple hidden data-sx-compose-file-input>

    <?php
    // Files of the forwarded (or quoted) letter that lie only in the mailbox are fetched before sending.
    $sourceMissing = $original ? array_filter($original->files, static fn($f) => !$f->isStored() && ($forward || $f->is_inline)) : [];
    ?>
    <?php if ($sourceMissing) : ?>
        <div class="sx-compose__context sx-letter__fetching" data-sx-compose-source-fetch
             data-kind="<?= $forward ? 'all' : 'images' ?>"
             data-fetch-url="<?= Html::encode(Url::to(['fetch', 'pk' => $original->id])) ?>"
             data-fetch-status-url="<?= Html::encode(Url::to(['fetch-status', 'pk' => $original->id])) ?>">
            <span class="sx-letter__spinner" aria-hidden="true"></span>
            <span data-sx-compose-source-text><?= $forward
                ? 'Вложения пересылаемого письма загружаются из почтового ящика…'
                : 'Картинки цитаты загружаются из почтового ящика…' ?></span>
        </div>
    <?php endif; ?>

    <?php if ($original) : ?>
        <div class="sx-compose__quote">
            <?php if ($forward) : ?>
                <div class="sx-compose__quote-head">
                    <?= MailUi::icon('forward', 15) ?>
                    <span>Пересылаемое письмо: <strong><?= Html::encode($original->subject ?: '(без темы)') ?></strong>, от <?= Html::encode($original->getFromAsText()) ?></span>
                </div>
            <?php else : ?>
                <label class="sx-compose__quote-head">
                    <?= Html::hiddenInput($name('include_quote'), 0) ?>
                    <input type="checkbox" name="<?= Html::encode($name('include_quote')) ?>" value="1" <?= $form->include_quote ? 'checked' : '' ?>>
                    <span>Цитировать письмо</span>
                </label>
                <button type="button" class="sx-compose__link" data-sx-compose-quote-toggle>Показать</button>
                <div class="sx-compose__quote-text" data-sx-compose-quote hidden><?= Html::encode($original->getSnippet(1500)) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="sx-compose__footer">
        <button type="submit" class="btn btn-primary sx-compose__send" data-sx-compose-send><?= MailUi::icon('send', 16) ?> Отправить</button>
        <button type="button" class="sx-compose__attach" data-sx-compose-attach title="Прикрепить файлы (можно перетащить в письмо)">
            <?= MailUi::icon('paperclip', 18) ?> <span>Прикрепить</span>
        </button>
        <span class="sx-compose__hint">Ctrl+Enter</span>
        <span class="sx-compose__status" data-sx-compose-status></span>
        <?php if ($inline) : ?>
            <button type="button" class="sx-compose__link sx-compose__cancel" data-sx-compose-cancel>Отменить</button>
        <?php endif; ?>
    </div>
</form>
