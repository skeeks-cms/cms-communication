<?php
/**
 * "Отправить клиенту": what is sent, the client's contacts from the CRM and
 * a panel per channel. A contact picks its channel and fills the recipient;
 * each channel draws its own form (mail — the usual letter form with the
 * sendable put in). mail.js runs it.
 *
 * @var $this yii\web\View
 * @var $send skeeks\cms\communication\send\SendRequest
 * @var $contacts array[] SendContacts::of()
 * @var $channels array[] channel id => channel, reason, contacts, form
 * @var $active string channel asked for in the URL
 */

use skeeks\cms\communication\assets\MailClientAsset;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\SendContacts;
use yii\helpers\Html;

MailClientAsset::register($this);
$this->registerCss('body { background: var(--sx-color-surface, #fff) !important; }');

// The channel of each kind of contact: the first one available.
$byKind = [];
foreach ($channels as $id => $item) {
    if (!$item['reason'] && !isset($byKind[$item['channel']->contactKind()])) {
        $byKind[$item['channel']->contactKind()] = $id;
    }
}
if (!isset($channels[$active]) || $channels[$active]['reason']) {
    $active = '';
    foreach ($channels as $id => $item) {
        if (!$item['reason']) {
            $active = $id;
            break;
        }
    }
}
$files = $send->sendable->getSendFiles();
?>
<div class="sx-send" data-sx-send>
    <div class="sx-send__head">
        <div class="sx-send__icon"><?= MailUi::icon('file-text', 22) ?></div>
        <div class="sx-send__what">
            <div class="sx-send__title"><?= Html::encode($send->sendable->getSendTitle()) ?></div>
            <div class="sx-send__meta">
                Личная ссылка «<?= Html::encode($send->sendable->getSendButton()) ?>»<?php if ($files) : ?>
                    и <?= count($files) > 1 ? 'файлы' : 'файл' ?>:
                    <?php foreach ($files as $file) : ?>
                        <span class="sx-send__file"><?= MailUi::icon('paperclip', 13) ?><?= Html::encode($file->name) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sx-send__section">
        <div class="sx-send__label">Кому отправить</div>
        <?php if ($contacts) : ?>
            <div class="sx-send__contacts" role="list">
                <?php foreach ($contacts as $contact) : ?>
                    <?php $channel = $byKind[$contact['kind']] ?? null; ?>
                    <button type="button" class="sx-send__contact" role="listitem" data-sx-send-contact
                            data-kind="<?= Html::encode($contact['kind']) ?>"
                            data-channel="<?= Html::encode((string)$channel) ?>"
                            data-value="<?= Html::encode($contact['kind'] === SendContacts::PHONE ? $contact['display'] : $contact['value']) ?>"
                            data-name="<?= Html::encode($contact['name']) ?>"
                            <?= $channel ? '' : 'disabled title="Нет канала, через который можно отправить на этот контакт"' ?>>
                        <span class="sx-send__contact-icon"><?= MailUi::icon(['phone' => 'phone', 'chat' => 'message'][$contact['kind']] ?? 'mail', 15) ?></span>
                        <span class="sx-send__contact-value"><?= Html::encode($contact['display']) ?></span>
                        <span class="sx-send__contact-who">
                            <?= Html::encode(trim(($contact['name'] !== '' && $contact['name'] !== $contact['record'] ? $contact['name'].' · ' : '')
                                .(CrmRecords::type($contact['type'])['title'] ?? '').' «'.$contact['record'].'»')) ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="sx-send__empty">У клиента в CRM нет email и телефонов. Введите адрес или номер ниже.</div>
        <?php endif; ?>
    </div>

    <div class="sx-send__tabs" role="tablist">
        <?php foreach ($channels as $id => $item) : ?>
            <button type="button" role="tab" class="sx-send__tab<?= $id === $active ? ' is-active' : '' ?>"
                    data-sx-send-tab="<?= Html::encode($id) ?>" aria-selected="<?= $id === $active ? 'true' : 'false' ?>"
                    <?= $item['reason'] ? 'title="'.Html::encode($item['reason']).'"' : '' ?>>
                <?= MailUi::icon($item['channel']->icon(), 16) ?>
                <span><?= Html::encode($item['channel']->title()) ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($channels as $id => $item) : ?>
        <div class="sx-send__panel" data-sx-send-panel="<?= Html::encode($id) ?>" role="tabpanel" <?= $id === $active ? '' : 'hidden' ?>>
            <?php if ($item['reason']) : ?>
                <div class="sx-send__unavailable"><?= MailUi::icon('info', 16) ?> <?= Html::encode($item['reason']) ?></div>
            <?php else : ?>
                <?= $item['form'] ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
