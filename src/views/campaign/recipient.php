<?php
/**
 * A recipient of a campaign (AdminCmsCommCampaignController::recipient()):
 * without a record — the choice of a lead, company or client to add; with a
 * record — its address, name and personal letter (subject and text instead
 * of the campaign template) with the preview. Before launch the recipient is
 * added or changed; after launch only the letter of a recipient still
 * waiting can change.
 *
 * @var $this yii\web\View
 * @var $campaign skeeks\cms\communication\models\CmsCommCampaign
 * @var $record yii\db\ActiveRecord|null
 * @var $recordRef string "lead:12"
 * @var $recipient skeeks\cms\communication\models\CmsCommCampaignRecipient|null saved row of this record
 * @var $addresses string[] address => name
 * @var $values array form values: address, name, subject, body_html
 * @var $error string|null
 * @var $preview array|null Campaigns::preview()
 * @var $canEdit bool
 */

use skeeks\cms\communication\assets\MailingAsset;
use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\widgets\formInputs\richText\RichTextInputWidget;
use yii\helpers\Html;
use yii\helpers\Url;

MailingAsset::register($this);
$siteId = (int)$campaign->cms_site_id;
$back = Html::a('← К кампании', Url::to(['view', 'pk' => (int)$campaign->id]), ['data-pjax' => 0]);
?>
<div class="sx-campaign sx-campaign-recipient">
    <p><?= $back ?></p>
    <?php if ($error) : ?>
        <div class="sx-import__notice sx-import__notice--error" role="alert"><?= Html::encode($error) ?></div>
    <?php endif; ?>

    <?php if (!$record) : ?>
        <?= $this->render('@skeeks/cms/communication/views/campaign/_record-picker', [
            'action' => Url::to(['recipient']),
            'hidden' => ['pk' => (int)$campaign->id],
            'title' => 'Добавить получателя',
            'hint' => 'Сделка, компания или клиент, которых вы видите в CRM. Дальше — адрес и, по желанию, личный текст письма.',
            'button' => 'Дальше',
            'siteId' => $siteId,
        ]) ?>
    <?php else : ?>
        <div class="sx-campaign__head">
            <span><?= Html::encode(CrmRecords::type(explode(':', $recordRef)[0])['title'] ?? 'Запись') ?>:
                <strong><?= Html::encode(CrmRecords::name($record)) ?></strong></span>
            <?php if ($recipient) : ?>
                <span class="sx-campaign__badge sx-campaign__badge--<?= Html::encode($recipient->status) ?>"><?= Html::encode($recipient->getStatusName()) ?></span>
                <span class="sx-import__muted"><?= Html::encode($recipient->getTextLabel()) ?><?= $recipient->text_at
                        ? ', '.Html::encode(\Yii::$app->formatter->asDatetime((int)$recipient->text_at, 'php:d.m.Y H:i')) : '' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($canEdit) : ?>
            <form method="post" action="<?= Html::encode(Url::current()) ?>" class="sx-import__card">
                <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
                <?php if ($campaign->isDraft) : ?>
                    <div class="sx-import__settings">
                        <label>
                            <span class="sx-import__label">Адрес</span>
                            <?= Html::dropDownList('address', $values['address'], array_combine(array_keys($addresses), array_map(
                                static fn($a, $n) => $a.($n !== '' ? ' — '.$n : ''), array_keys($addresses), $addresses)) ?: ['' => 'У записи нет email'], ['class' => 'form-control']) ?>
                        </label>
                        <label>
                            <span class="sx-import__label">Имя для {Имя}</span>
                            <?= Html::textInput('name', $values['name'], ['class' => 'form-control', 'maxlength' => 255]) ?>
                        </label>
                    </div>
                <?php endif; ?>
                <h2 class="sx-import__title">Личное письмо</h2>
                <p class="sx-import__muted">Пусто — уйдёт шаблон кампании. Подстановки те же; в личном тексте обязательны {Отписаться}
                    <?= $campaign->offer_kind !== CmsCommCampaign::OFFER_NONE ? 'и {Ссылка}' : '' ?>. Пустая тема — тема кампании.</p>
                <div class="form-group">
                    <label class="control-label" for="sx-campaign-subject">Тема письма</label>
                    <?= Html::textInput('subject', $values['subject'], ['class' => 'form-control', 'id' => 'sx-campaign-subject', 'maxlength' => 500,
                        'placeholder' => (string)$campaign->subject]) ?>
                </div>
                <div class="form-group">
                    <?= RichTextInputWidget::widget([
                        'name' => 'body_html',
                        'value' => $values['body_html'],
                        'options' => ['id' => 'sx-campaign-body'],
                        'preset' => ComposeForm::EDITOR_PRESET,
                        'tools' => array_values(array_diff(ComposeForm::EDITOR_TOOLS, ['image'])),
                        'minHeight' => 220,
                    ]) ?>
                </div>
                <div class="sx-import__actions">
                    <button type="submit" name="do" value="save" class="btn btn-primary">Сохранить</button>
                    <?php if ($recipient && $recipient->getHasPersonalText()) : ?>
                        <button type="submit" name="do" value="template" class="btn btn-default">Вернуть шаблон кампании</button>
                    <?php endif; ?>
                    <?php if ($recipient && $campaign->isDraft) : ?>
                        <button type="submit" name="do" value="remove" class="btn btn-default" data-confirm="Убрать получателя из кампании?">Убрать из кампании</button>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($preview) : ?>
            <div class="sx-import__card">
                <h2 class="sx-import__title">Как увидит</h2>
                <dl class="sx-campaign__letter-head">
                    <dt>От кого</dt><dd><?= Html::encode($preview['from'] ?: 'определится при запуске') ?></dd>
                    <dt>Кому</dt><dd><?= Html::encode($preview['to']) ?></dd>
                    <dt>Тема</dt><dd><strong><?= Html::encode($preview['subject'] ?: '(без темы)') ?></strong></dd>
                </dl>
                <iframe class="sx-campaign__letter" sandbox="" title="Предпросмотр письма"
                        srcdoc="<?= Html::encode('<!doctype html><meta charset="utf-8"><body style="margin:12px;background:#fff;color:#1f2430;font-family:Arial,sans-serif">'.$preview['html'].'</body>') ?>"></iframe>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
