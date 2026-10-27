<?php
/**
 * "Подписки на рассылки" of one contact of a CRM card, in a backend window
 * (AdminCmsCommConsentController::contact()).
 *
 * @var $this yii\web\View
 * @var $record yii\db\ActiveRecord lead, company or client
 * @var $kind string email, phone
 * @var $value string normalized
 * @var $display string
 * @var $topics skeeks\cms\communication\models\CmsCommTopic[]
 * @var $state array MailingConsents::state()
 * @var $canEdit bool
 * @var $saved bool
 */

use skeeks\cms\communication\assets\MailingAsset;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\services\CrmRecords;
use yii\helpers\Html;
use yii\helpers\Url;

MailingAsset::register($this);
$this->registerCss('body { background: var(--sx-color-surface, #fff) !important; }');
if ($saved) {
    // The card behind the window shows the new state.
    $this->registerJs("if (window.sx && sx.Window && sx.Window.openerWidgetTriggerEvent) { sx.Window.openerWidgetTriggerEvent('model-update', {submitBtn: 'apply'}); }");
}

$who = static fn($user) => $user ? (string)$user->shortDisplayName : null;
$date = static fn($time) => $time ? \Yii::$app->formatter->asDatetime((int)$time, 'php:d.m.Y H:i') : '';
$describe = static function (?CmsCommContactTopic $row) use ($who, $date): string {
    if (!$row) {
        return 'Нет отметок: можно писать лично как холодному контакту, через сервис рассылок — нельзя.';
    }
    if ($row->isSubscribed()) {
        $text = (CmsCommContactTopic::bases()[$row->basis] ?? $row->basis).', '.$date($row->consent_at);
        if ($by = $who($row->consentBy)) {
            $text .= ', отметил '.$by;
        }
        return $text.($row->note ? '. '.$row->note : '');
    }
    $text = 'Отписался '.$date($row->unsubscribed_at);
    if ($row->unsubscribe_source) {
        $text .= ' '.(CmsCommContactTopic::sources()[$row->unsubscribe_source] ?? $row->unsubscribe_source);
    }
    if ($by = $who($row->unsubscribedBy)) {
        $text .= ' ('.$by.')';
    }
    if ($row->cms_comm_message_id) {
        $text .= ', письмо №'.(int)$row->cms_comm_message_id;
    }
    return $text;
};
$choices = ['' => 'Нет отметки'] + CmsCommContactTopic::bases() + [CmsCommContactTopic::STATUS_UNSUBSCRIBED => 'Отписан'];
?>
<form class="sx-consent" method="post" action="<?= Html::encode(Url::current()) ?>">
    <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
    <div class="sx-consent__head">
        <?= MailUi::icon($kind === CmsCommContact::KIND_PHONE ? 'phone' : 'mail', 16) ?>
        <strong><?= Html::encode($display) ?></strong>
        <span class="sx-consent__muted"><?= Html::encode(CrmRecords::name($record)) ?></span>
    </div>

    <?php if ($saved) : ?>
        <div class="sx-consent__notice sx-consent__notice--success" role="status">Сохранено</div>
    <?php endif; ?>

    <p class="sx-consent__hint">Согласие и отписка относятся к этому <?= $kind === CmsCommContact::KIND_PHONE ? 'номеру' : 'адресу' ?>, а не к карточке:
        тот же контакт у сделки, компании и клиента — одна отметка. Служебные письма и личные письма менеджера отписка не блокирует.</p>

    <div class="sx-consent__section">Стоп-лист</div>
    <?php foreach (CmsCommSuppression::reasons() as $reason => $title) :
        $row = $state['suppressions'][$reason] ?? null;
        ?>
        <label class="sx-consent__check<?= $row ? ' is-on' : '' ?>">
            <input type="checkbox" name="suppression[]" value="<?= Html::encode($reason) ?>" <?= $row ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
            <span>
                <span class="sx-consent__name"><?= Html::encode($title) ?></span>
                <?php if ($row) : ?>
                    <span class="sx-consent__muted"><?= Html::encode(trim($date($row->created_at).' '.(CmsCommContactTopic::sources()[$row->source] ?? (string)$row->source)
                        .($row->createdBy ? ' ('.$who($row->createdBy).')' : '').($row->note ? '. '.$row->note : ''))) ?></span>
                <?php endif; ?>
            </span>
        </label>
    <?php endforeach; ?>

    <div class="sx-consent__section">Темы рассылок</div>
    <?php if (!$topics) : ?>
        <p class="sx-consent__muted">Темы рассылок не заведены. Администратор добавляет их в «Настройки → Темы рассылок».</p>
    <?php endif; ?>
    <?php foreach ($topics as $topic) :
        $row = $state['topics'][(int)$topic->id] ?? null;
        $current = $row ? ($row->isSubscribed() ? (string)$row->basis : CmsCommContactTopic::STATUS_UNSUBSCRIBED) : '';
        ?>
        <div class="sx-consent__topic">
            <div class="sx-consent__topic-head">
                <span class="sx-consent__name"><?= Html::encode($topic->name) ?><?= $topic->is_active ? '' : ' <span class="sx-consent__muted">(выключена)</span>' ?></span>
                <?= Html::dropDownList('topics['.(int)$topic->id.']', $current, $choices, ['class' => 'form-control sx-consent__select', 'disabled' => !$canEdit]) ?>
            </div>
            <div class="sx-consent__muted"><?= Html::encode($describe($row)) ?></div>
        </div>
    <?php endforeach; ?>

    <?php if ($canEdit) : ?>
        <label class="sx-consent__section" for="sx-consent-note">Комментарий к согласию</label>
        <input id="sx-consent-note" class="form-control" type="text" name="note" maxlength="255" placeholder="Например: согласие в звонке 23.10, записано в задаче">
        <div class="sx-consent__actions">
            <button type="submit" class="btn btn-primary">Сохранить</button>
        </div>
    <?php else : ?>
        <p class="sx-consent__muted">Подписки контакта сделки меняет ответственный менеджер или администратор.</p>
    <?php endif; ?>
</form>
