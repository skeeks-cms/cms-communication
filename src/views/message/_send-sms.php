<?php
/**
 * SMS of "Отправить клиенту": the phone, the text the employee may edit and
 * what the client gets — the text and the personal link on its own line
 * (the link is made when the SMS is sent). mail.js posts it to "send".
 *
 * @var $this yii\web\View
 * @var $request skeeks\cms\communication\send\SendRequest
 * @var $contacts array[] phones of the client
 * @var $phone string first phone, formatted
 * @var $text string
 * @var $maxText int
 */

use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\SendService;
use yii\helpers\Html;
use yii\helpers\Url;

$id = 'sx-send-sms-'.substr(md5(uniqid('', true)), 0, 6);
?>
<form class="sx-compose sx-send-form" data-sx-send-form action="<?= Html::encode(Url::to(['send'])) ?>" method="post" novalidate
      data-optout-url="<?= Html::encode(Url::to(['optout'])) ?>">
    <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
    <?= Html::hiddenInput('sendable', $request->ref()) ?>
    <?= Html::hiddenInput('channel', 'sms') ?>
    <?= Html::hiddenInput('record', SendService::recordRefOf($request->record)) ?>

    <div class="sx-compose__row" data-sx-send-row="recipient">
        <label class="sx-compose__label" for="<?= $id ?>-phone">Телефон</label>
        <div class="sx-compose__control">
            <input type="tel" class="sx-compose__input" id="<?= $id ?>-phone" name="recipient" value="<?= Html::encode($phone) ?>"
                   autocomplete="off" placeholder="+7 900 000-00-00" data-sx-send-recipient>
        </div>
        <div class="sx-compose__error" data-sx-send-error="recipient"></div>
    </div>
    <?php // A number on the stop list of mailings: the SMS still goes, the employee is warned (mail.js). ?>
    <div class="sx-send-form__optout" data-sx-send-optout hidden></div>

    <div class="sx-send-form__text">
        <label class="sx-compose__label" for="<?= $id ?>-text">Текст</label>
        <textarea class="sx-send-form__textarea" id="<?= $id ?>-text" name="text" rows="4" maxlength="<?= (int)$maxText ?>"
                  data-sx-send-text><?= Html::encode($text) ?></textarea>
        <div class="sx-compose__error" data-sx-send-error="text"></div>
    </div>

    <div class="sx-send-form__preview">
        <div class="sx-send__label">Клиент получит</div>
        <div class="sx-send-form__bubble" data-sx-send-preview
             data-link-label="<?= Html::encode('ссылка «'.$request->sendable->getSendButton().'»') ?>"></div>
        <div class="sx-send-form__count" data-sx-send-count></div>
    </div>

    <div class="sx-compose__error" data-sx-send-error="channel"></div>
    <div class="sx-compose__error" data-sx-send-error="sendable"></div>
    <div class="sx-compose__footer">
        <button type="submit" class="btn btn-primary sx-compose__send" data-sx-send-submit><?= MailUi::icon('send', 16) ?> Отправить СМС</button>
        <span class="sx-compose__hint">Ссылка добавится в конце, у каждой отправки — своя</span>
        <span class="sx-compose__status" data-sx-send-status></span>
    </div>
</form>
