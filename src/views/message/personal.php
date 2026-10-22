<?php
/**
 * "Мои настройки почты": the employee's own notifications for each mailbox
 * they read, a signature for each mailbox they send from, quick reply
 * templates (added and changed in the standard window of
 * AdminCmsCommTemplateController). Opened in the panel from "Почта" and
 * from the letter form.
 *
 * @var $this yii\web\View
 * @var $accesses skeeks\cms\communication\models\CmsCommAccountAccess[] mailboxes the employee reads
 * @var $accounts skeeks\cms\communication\models\CmsCommAccount[] mailboxes the employee sends from
 * @var $signatures array mailbox id => signature HTML
 * @var $templates skeeks\cms\communication\models\CmsCommTemplate[]
 * @var $service skeeks\cms\communication\services\MailTemplates
 */

use skeeks\cms\communication\assets\MailClientAsset;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\services\MailTemplates;
use skeeks\cms\widgets\formInputs\richText\RichTextInputWidget;
use yii\helpers\Html;
use yii\helpers\Url;

MailClientAsset::register($this);
$this->registerCss('body { background: var(--sx-color-surface, #fff) !important; }');
$csrf = Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken);
$editor = static fn(string $name, string $value, string $id) => RichTextInputWidget::widget([
    'name' => $name,
    'value' => $value,
    'options' => ['id' => $id],
    'preset' => MailTemplates::EDITOR_PRESET,
    'tools' => MailTemplates::EDITOR_TOOLS,
    'paragraph' => 'div',
    'minHeight' => 90,
]);
$accountTitle = static fn($a) => $a->name ? $a->name.' <'.$a->address.'>' : $a->address;
$action = Url::current();
?>
<div class="sx-mail-settings sx-mail-personal">
    <?php foreach (\Yii::$app->session->getAllFlashes() as $type => $message) : ?>
        <div class="sx-mail-settings__notice is-<?= Html::encode($type) ?>"><?= Html::encode(implode(' ', (array)$message)) ?></div>
    <?php endforeach; ?>

    <h3 class="sx-mail-settings__title">Уведомления о новых письмах</h3>
    <?php if (!$accesses) : ?>
        <p class="sx-mail-settings__muted">Уведомления приходят по почтовым ящикам, к которым вам выдан доступ. Сейчас таких ящиков нет.</p>
    <?php else : ?>
        <?= Html::beginForm($action, 'post', ['class' => 'sx-mail-settings__form', 'data-pjax' => 0]) ?>
            <?= $csrf ?>
            <?= Html::hiddenInput('section', 'notify') ?>
            <p class="sx-mail-settings__muted">Новое письмо появится в уведомлениях CRM. Несколько писем, пришедших вместе, — одним уведомлением.</p>
            <?php foreach ($accesses as $access) : ?>
                <label class="sx-mail-settings__check">
                    <?= Html::checkbox('notify['.(int)$access->cms_comm_account_id.']', (bool)$access->is_notify) ?>
                    <span>
                        <?= Html::encode($access->account ? $accountTitle($access->account) : '#'.$access->cms_comm_account_id) ?>
                        <?php if ($access->read_level === CmsCommAccountAccess::READ_LINKED) : ?>
                            <small class="sx-mail-settings__muted">— только письма ваших лидов, компаний и клиентов</small>
                        <?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
            <div class="sx-mail-settings__buttons"><button type="submit" class="btn btn-primary">Сохранить</button></div>
        <?= Html::endForm() ?>
    <?php endif; ?>

    <h3 class="sx-mail-settings__title">Подпись</h3>
    <?php if (!$accounts) : ?>
        <p class="sx-mail-settings__muted">Подпись нужна, когда вы отправляете письма. Вам пока не выдано право отправки ни с одного ящика.</p>
    <?php else : ?>
        <?= Html::beginForm($action, 'post', ['class' => 'sx-mail-settings__form sx-mail-personal__wide', 'data-pjax' => 0]) ?>
            <?= $csrf ?>
            <?= Html::hiddenInput('section', 'signatures') ?>
            <p class="sx-mail-settings__muted">Подставляется под текстом письма — в новых письмах, ответах и пересылке — и меняется, когда вы выбираете другой ящик в «От кого».</p>
            <?php foreach ($accounts as $account) : ?>
                <div class="sx-mail-settings__field">
                    <span><?= Html::encode($accountTitle($account)) ?></span>
                    <?= $editor('signatures['.(int)$account->id.']', (string)($signatures[(int)$account->id] ?? ''), 'sx-mail-signature-'.(int)$account->id) ?>
                </div>
            <?php endforeach; ?>
            <div class="sx-mail-settings__buttons"><button type="submit" class="btn btn-primary">Сохранить подписи</button></div>
        <?= Html::endForm() ?>
    <?php endif; ?>

    <h3 class="sx-mail-settings__title">Шаблоны ответов</h3>
    <p class="sx-mail-settings__muted">Готовые тексты, которые вставляются в письмо кнопкой «Шаблоны». Личные видите только вы, общие — все, кто пишет с этого ящика.</p>
    <?php if ($templates) : ?>
        <div class="sx-mail-personal__templates">
            <?php foreach ($templates as $template) : ?>
                <div class="sx-mail-personal__template">
                    <div class="sx-mail-personal__template-text">
                        <?php // Added and changed in the standard backend window (AdminCmsCommTemplateController). ?>
                        <?= $service->canEdit($template)
                            ? \skeeks\cms\backend\widgets\BackendEntityLink::widget([
                                'controllerId' => '/cmsCommunication/admin-cms-comm-template',
                                'modelId' => $template->id,
                                'label' => $template->name,
                                'action' => 'update',
                                'options' => ['class' => 'sx-mail-personal__template-name'],
                            ])
                            : Html::tag('strong', Html::encode($template->name)) ?>
                        <span class="sx-mail-settings__muted"><?= $template->cms_user_id ? 'Личный' : 'Общий: '.Html::encode($template->account ? $template->account->address : '') ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($accounts) : ?>
        <?php $createUrl = (string)\skeeks\cms\backend\helpers\BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-template/create'])
            ->enableEmptyLayout()->enableNoActions()->url; ?>
        <?= Html::a('+ Добавить шаблон', '#', ['class' => 'btn btn-default', 'data-pjax' => 0,
            'onclick' => 'new sx.classes.backend.widgets.Action('.\yii\helpers\Json::encode(['isOpenNewWindow' => true, 'url' => $createUrl]).').go(); return false;']) ?>
    <?php endif; ?>
</div>
