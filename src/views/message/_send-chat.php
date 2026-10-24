<?php
/**
 * A messenger chat of "Отправить клиенту": the chat, the text the employee
 * may edit and what the client gets — the text, the button with the personal
 * link (made when the message is sent) and the files. mail.js posts it to
 * "send".
 *
 * @var $this yii\web\View
 * @var $request skeeks\cms\communication\send\SendRequest
 * @var $contacts array[] chats of the client (SendContacts::CHAT)
 * @var $text string
 * @var $maxText int
 */

use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\SendService;
use yii\helpers\Html;
use yii\helpers\Url;

$id = 'sx-send-chat-'.substr(md5(uniqid('', true)), 0, 6);
$files = $request->sendable->getSendFiles();
?>
<form class="sx-compose sx-send-form" data-sx-send-form action="<?= Html::encode(Url::to(['send'])) ?>" method="post" novalidate>
    <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
    <?= Html::hiddenInput('sendable', $request->ref()) ?>
    <?= Html::hiddenInput('channel', 'chat') ?>
    <?= Html::hiddenInput('record', SendService::recordRefOf($request->record)) ?>

    <div class="sx-compose__row" data-sx-send-row="recipient">
        <label class="sx-compose__label" for="<?= $id ?>-chat">Чат</label>
        <div class="sx-compose__control">
            <?php if ($contacts): ?>
                <select class="sx-compose__input" id="<?= $id ?>-chat" name="recipient" data-sx-send-recipient>
                    <?php foreach ($contacts as $contact): ?>
                        <option value="<?= Html::encode($contact['value']) ?>">
                            <?= Html::encode($contact['display'].' — '.(CrmRecords::type($contact['type'])['title'] ?? '').' «'.$contact['record'].'»') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="hidden" name="recipient" value="" data-sx-send-recipient>
                <div class="sx-send__empty">У клиента нет чатов, в которые вам можно писать. Привяжите чат к клиенту в разделе «Чаты» или пригласите клиента в бота из его карточки.</div>
            <?php endif; ?>
        </div>
        <div class="sx-compose__error" data-sx-send-error="recipient"></div>
    </div>

    <div class="sx-send-form__text">
        <label class="sx-compose__label" for="<?= $id ?>-text">Текст</label>
        <textarea class="sx-send-form__textarea" id="<?= $id ?>-text" name="text" rows="4" maxlength="<?= (int)$maxText ?>"
                  data-sx-send-text><?= Html::encode($text) ?></textarea>
        <div class="sx-compose__error" data-sx-send-error="text"></div>
    </div>

    <div class="sx-send-form__preview">
        <div class="sx-send__label">Клиент получит</div>
        <div class="sx-send-form__bubble" data-sx-send-preview
             data-link-label="<?= Html::encode('кнопка «'.$request->sendable->getSendButton().'»') ?>"></div>
        <?php if ($files): ?>
            <div class="sx-send-form__count">
                и <?= count($files) > 1 ? 'файлы' : 'файл' ?> отдельным сообщением:
                <?php foreach ($files as $file): ?>
                    <span class="sx-send__file"><?= MailUi::icon('paperclip', 13) ?><?= Html::encode($file->name) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="sx-compose__error" data-sx-send-error="channel"></div>
    <div class="sx-compose__error" data-sx-send-error="sendable"></div>
    <div class="sx-compose__footer">
        <button type="submit" class="btn btn-primary sx-compose__send" data-sx-send-submit <?= $contacts ? '' : 'disabled' ?>><?= MailUi::icon('send', 16) ?> Отправить в чат</button>
        <span class="sx-compose__hint">Сообщение уйдёт от бота с вашей подписью и будет видно в «Чатах»</span>
        <span class="sx-compose__status" data-sx-send-status></span>
    </div>
</form>
