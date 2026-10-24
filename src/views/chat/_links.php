<?php
/**
 * Who the chat belongs to: linked CRM records (open the card, "×" — unlink),
 * "Привязать…", suggestions by the chat title; in a private dialog — the
 * person: their linked client, their phone, "Попросить номер".
 *
 * @var $this yii\web\View
 * @var $conversation skeeks\cms\communication\models\CmsCommConversation
 * @var $records array[] ChatLinks::records()
 * @var $suggestions skeeks\cms\models\CmsCompany[]
 * @var $identity skeeks\cms\communication\models\CmsCommIdentity|null
 * @var $canAskPhone bool
 */

use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\SendContacts;
use yii\helpers\Html;
use yii\helpers\Json;

$methods = [
    CmsCommIdentity::LINK_START => 'по ссылке-приглашению',
    CmsCommIdentity::LINK_CONTACT => 'по своему номеру',
    CmsCommIdentity::LINK_SMS => 'по коду из СМС',
    CmsCommIdentity::LINK_MANUAL => 'вручную',
];
?>
<div class="sx-chat-links">
    <span class="sx-chat-links__label">Клиент в CRM:</span>
    <?php foreach ($records as $item): ?>
        <?php $link = $item['link']; $record = $item['record']; ?>
        <span class="sx-chat-links__item">
            <?php if ($record): ?>
                <?= ChatUi::recordLink($link->model_code, $record, $item['type']) ?>
                <button type="button" class="sx-chat-links__unlink" title="Отвязать" aria-label="Отвязать"
                        data-sx-chat-unlink="<?= Html::encode(Json::encode(['code' => $link->model_code, 'id' => (int)$link->model_id, 'name' => CrmRecords::name($record)])) ?>">×</button>
            <?php else: ?>
                <span class="sx-gray"><?= Html::encode(CrmRecords::type($item['type'])['title'] ?? 'Запись') ?> без доступа</span>
            <?php endif; ?>
        </span>
    <?php endforeach; ?>
    <?php if (!$records): ?><span class="sx-gray">не привязан</span><?php endif; ?>
    <button type="button" class="sx-chat-btn sx-chat-btn--small" data-sx-chat-link-window>Привязать…</button>
    <?php foreach ($suggestions as $company): ?>
        <button type="button" class="sx-chat-links__suggest" title="Название чата похоже на эту компанию"
                data-sx-chat-link-to="<?= Html::encode(Json::encode(['type' => CrmRecords::COMPANY, 'id' => (int)$company->id])) ?>">
            + <?= Html::encode(CrmRecords::name($company)) ?>?
        </button>
    <?php endforeach; ?>
</div>
<?php if ($identity): ?>
    <div class="sx-chat-links sx-chat-links--person">
        <span class="sx-chat-links__label">Собеседник:</span>
        <span><?= Html::encode($identity->asText()) ?><?= $identity->username ? ' <span class="sx-gray">@'.Html::encode($identity->username).'</span>' : '' ?></span>
        <?php if ($identity->phone): ?>
            <span title="<?= $identity->phone_verified_at ? 'Номер собеседника, подтверждён мессенджером' : '' ?>">📞 <?= Html::encode(SendContacts::formatPhone((string)$identity->phone)) ?><?= $identity->phone_verified_at ? ' ✓' : '' ?></span>
        <?php endif; ?>
        <?php if ($identity->link_method): ?>
            <span class="sx-gray">узнали <?= Html::encode($methods[$identity->link_method] ?? '') ?></span>
        <?php endif; ?>
        <?php if ($identity->is_blocked): ?><span class="sx-chat__warn">остановил бота</span><?php endif; ?>
        <?php if (!$identity->cms_user_id): ?>
            <button type="button" class="sx-chat-btn sx-chat-btn--small" data-sx-chat-link-window="person" title="Кто этот человек в CRM: лид, компания или клиент">Привязать собеседника…</button>
        <?php endif; ?>
        <?php if ($canAskPhone): ?>
            <button type="button" class="sx-chat-btn sx-chat-btn--small" data-sx-chat-do="ask-phone" title="Боту уйдёт сообщение с кнопкой «Поделиться номером»">Попросить номер</button>
        <?php endif; ?>
    </div>
<?php endif; ?>
