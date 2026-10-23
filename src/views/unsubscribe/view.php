<?php
/**
 * The opt-out page of advertising mailings (UnsubscribeController).
 *
 * @var yii\web\View $this
 * @var skeeks\cms\communication\models\CmsCommContact $contact
 * @var skeeks\cms\communication\models\CmsCommTopic[] $topics active topics of the site
 * @var array $state MailingConsents::state()
 * @var skeeks\cms\communication\models\CmsCommTopic|null $topic the topic of the letter
 * @var string|null $done what was saved
 * @var bool $hasSiblings the contact belongs to CRM records: "по всем каналам" makes sense
 * @var bool $allSuppressed opted out of all advertising
 * @var string $siteName
 * @var string $siteLogo
 * @var string $siteUrl
 * @var string $action
 */

use skeeks\cms\communication\models\CmsCommContact;
use yii\helpers\Html;

$isPhone = $contact->kind === CmsCommContact::KIND_PHONE;
$what = $isPhone ? 'номер' : 'адрес';
?>
<div class="sx-un">
    <div class="sx-un-header">
        <?php if ($siteLogo) : ?>
            <a href="<?= Html::encode($siteUrl ?: '/'); ?>"><img class="sx-un-header__logo" src="<?= Html::encode($siteLogo); ?>" alt="<?= Html::encode($siteName); ?>"></a>
        <?php elseif ($siteName) : ?>
            <a class="sx-un-header__name" href="<?= Html::encode($siteUrl ?: '/'); ?>"><?= Html::encode($siteName); ?></a>
        <?php endif; ?>
    </div>

    <h1>Подписка на рассылки</h1>
    <p class="sx-un-lead">Настройки для <?= $what; ?>а <strong><?= Html::encode($contact->masked()); ?></strong></p>

    <?php if ($done) : ?>
        <div class="sx-un-notice sx-un-notice--success" role="status"><?= Html::encode($done); ?></div>
    <?php elseif ($allSuppressed) : ?>
        <div class="sx-un-notice sx-un-notice--muted">Вы отписаны от всех рекламных рассылок на этот <?= $what; ?>. Если хотите снова получать
            что-то из списка ниже, отметьте темы и сохраните.</div>
    <?php endif; ?>

    <?php if ($topics) : ?>
        <form class="sx-un-card" method="post" action="<?= Html::encode($action); ?>">
            <h2>Темы рассылок</h2>
            <p class="sx-un-card__hint">Оставьте отмеченными темы, которые вам интересны. Снимите галочку, чтобы больше не получать письма по теме.</p>
            <fieldset class="sx-un-topics">
                <legend hidden>Темы рассылок</legend>
                <?php foreach ($topics as $one) :
                    $row = $state['topics'][(int)$one->id] ?? null;
                    $checked = !$allSuppressed && (!$row || $row->isSubscribed());
                    $current = $topic && (int)$topic->id === (int)$one->id;
                    ?>
                    <label class="sx-un-topic<?= $current ? ' is-current' : ''; ?>">
                        <input type="checkbox" name="topics[]" value="<?= (int)$one->id; ?>" <?= $checked ? 'checked' : ''; ?>>
                        <span>
                            <span class="sx-un-topic__name"><?= Html::encode($one->name); ?><?php if ($current) : ?><span class="sx-un-topic__mark">это письмо</span><?php endif; ?></span>
                            <?php if ($one->description) : ?><span class="sx-un-topic__text"><?= Html::encode($one->description); ?></span><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <button type="submit" class="sx-un-button sx-un-button--primary">Сохранить</button>
        </form>
    <?php endif; ?>

    <?php if (!$allSuppressed || $hasSiblings) : ?>
        <form class="sx-un-card" method="post" action="<?= Html::encode($action); ?>">
            <h2>Отписаться от всех рекламных рассылок</h2>
            <p class="sx-un-card__hint">Мы больше не будем присылать рекламные письма и сообщения на этот <?= $what; ?>.</p>
            <input type="hidden" name="all" value="1">
            <?php if ($hasSiblings) : ?>
                <label class="sx-un-check">
                    <input type="checkbox" name="all_channels" value="1">
                    <span>И по всем остальным каналам: другие адреса почты и телефоны, которые вы нам оставляли</span>
                </label>
            <?php endif; ?>
            <button type="submit" class="sx-un-button sx-un-button--danger">Отписаться от всех</button>
        </form>
    <?php endif; ?>

    <p class="sx-un-note">Служебные сообщения — счета, уведомления об услугах, ответы по вашим обращениям — продолжат приходить:
        они нужны для работы и не являются рекламой.</p>
</div>
