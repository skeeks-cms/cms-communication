<?php
/**
 * "Настройки почты" → «Работа ИИ через API»: whether letters may be sent
 * through the API and the limits of AI clients; what applies now and where
 * each value comes from.
 *
 * @var $this yii\web\View
 * @var $component skeeks\cms\communication\components\AiApiSettings
 * @var $sendEnabled bool
 * @var $limits array key => [value, source: crm|config|default]
 */

use skeeks\cms\communication\assets\MailClientAsset;
use yii\helpers\Html;

MailClientAsset::register($this);
$sources = ['crm' => 'задано здесь', 'config' => 'из конфигурации сайта', 'default' => 'по умолчанию'];
$labels = $component->attributeLabels();
?>
<div class="sx-mail-settings">
    <p class="sx-mail-settings__lead">
        Подключённый помощник (Claude Code, Codex и другие программы с доступом к CRM по OAuth) готовит черновики писем и
        кампаний, а с отдельным правом — отправляет проверенные письма клиентам. Здесь тот, кто управляет почтой, решает, можно ли
        отправлять письма через API на этом сайте, и задаёт лимиты. Пустое поле — значение из конфигурации сайта, а если
        его нет — по умолчанию. Кампании помощник не запускает никогда: это делает сотрудник в «Рассылках».
    </p>

    <?php $form = $component->beginConfigForm(); ?>
    <?= $component->renderConfigFormFields($form) ?>
    <?= $form->buttonsStandart($component) ?>
    <?php $form::end(); ?>

    <h3 class="sx-mail-settings__title">Сейчас действует</h3>
    <?php if (!$sendEnabled) : ?>
        <p class="sx-mail-settings__notice is-warning">
            Отправка писем через API выключена: помощник готовит черновики, а отправляют их сотрудники из «Почты».
        </p>
    <?php else : ?>
        <p class="sx-mail-settings__notice is-success">
            Отправка писем через API разрешена — сотрудникам, которым дано право на отправку при подключении помощника.
        </p>
    <?php endif; ?>
    <ul class="sx-mail-settings__list">
        <?php foreach (array_intersect_key(array_replace(array_flip($component::LIMITS), $limits), $limits) as $key => $row) : ?>
            <li>
                <?= Html::encode($labels[$key] ?? $key) ?>: <b><?= (int)$row['value'] ?></b>
                <span class="sx-mail-settings__muted">— <?= Html::encode($sources[$row['source']] ?? $row['source']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
