<?php
/**
 * The card of a campaign (AdminCmsCommCampaignController::view()): a draft —
 * the summary of recipients with the reasons of exclusion, the preview on a
 * lead, a test letter and "Запустить"; a launched campaign — progress,
 * pause, resume, cancel and the report by recipients.
 *
 * @var $this yii\web\View
 * @var $campaign skeeks\cms\communication\models\CmsCommCampaign
 * @var $summary array|null Campaigns::summary()
 * @var $summaryError string|null
 * @var $launchErrors string[]
 * @var $stats array|null Campaigns::stats()
 * @var $report skeeks\cms\communication\models\CmsCommCampaignRecipient[]
 * @var $reportTotal int
 * @var $filter string
 * @var $page int
 * @var $sample skeeks\cms\communication\models\CmsCommCampaignRecipient|null
 * @var $preview array|null Campaigns::preview()
 * @var $previewError string|null
 * @var $samples string[] "type:id" => recipient
 */

use skeeks\cms\communication\assets\MailingAsset;
use skeeks\cms\communication\controllers\AdminCmsCommCampaignController;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\communication\services\CrmRecords;
use yii\helpers\Html;
use yii\helpers\Url;

MailingAsset::register($this);

$formatter = \Yii::$app->formatter;
$date = static fn($t) => $t ? $formatter->asDatetime((int)$t, 'php:d.m.Y H:i') : '';
$csrf = Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken);
$button = static fn(string $do, string $label, string $class, array $options = []) => Html::beginForm(Url::current(), 'post', ['class' => 'sx-campaign__inline'])
    .Html::hiddenInput('do', $do).Html::submitButton($label, ['class' => 'btn '.$class] + $options).Html::endForm();
$recordRoutes = [CrmRecords::LEAD => '/cms/admin-cms-lead/view', CrmRecords::COMPANY => '/cms/admin-cms-company/view', CrmRecords::CLIENT => '/cms/admin-user/view'];
$recordLink = static function (CmsCommCampaignRecipient $r) use ($recordRoutes): string {
    $title = (CrmRecords::type((string)$r->record_type)['title'] ?? 'Запись').' №'.(int)$r->record_id.($r->name ? ' '.$r->name : '');

    return isset($recordRoutes[$r->record_type])
        ? Html::a(Html::encode($title), Url::to([$recordRoutes[$r->record_type], 'pk' => (int)$r->record_id]), ['target' => '_blank', 'data-pjax' => 0])
        : Html::encode($title);
};
// The letter of a recipient: template or personal, and the link to change it while it can change.
$textCell = static function (CmsCommCampaignRecipient $r, bool $draft) use ($campaign): string {
    $label = Html::tag('span', Html::encode($r->getTextLabel()), ['class' => $r->getHasPersonalText() ? 'sx-campaign__personal' : 'sx-import__muted']);
    $editable = $draft ? $r->status !== CmsCommCampaignRecipient::STATUS_EXCLUDED || $r->id
        : $r->status === CmsCommCampaignRecipient::STATUS_PENDING && $campaign->isActive;
    if (!$editable) {
        return $label;
    }

    return $label.' '.Html::a($r->getHasPersonalText() ? 'изменить' : 'свой текст',
            Url::to(['recipient', 'pk' => (int)$campaign->id, 'record' => $r->record_type.':'.(int)$r->record_id]), ['data-pjax' => 0]);
};
$source = Campaigns::offerSource($campaign);
// Who the audience is: the lists by name, the excluding lists.
$listNames = \skeeks\cms\communication\models\CmsCommList::forSite((int)$campaign->cms_site_id);
$names = static fn(array $ids) => implode(', ', array_map(static fn($id) => '«'.($listNames[(int)$id] ?? '№'.(int)$id).'»', $ids));
$audienceText = CmsCommCampaign::audienceTypes()[$campaign->audience_type] ?? (string)$campaign->audience_type;
if ($campaign->audience_type === CmsCommCampaign::AUDIENCE_LISTS && $campaign->audienceLists) {
    $audienceText .= ' '.$names($campaign->audienceLists);
}
if ($campaign->audienceExcludeLists) {
    $audienceText .= ', кроме '.$names($campaign->audienceExcludeLists);
}
$offer = CmsCommCampaign::offerKinds()[$campaign->offer_kind] ?? '';
if ($campaign->offer_id && $source) {
    $items = $campaign->offer_kind === CmsCommCampaign::OFFER_EACH
        ? $source->templates((int)$campaign->cms_site_id)
        : $source->sharedItems(\Yii::$app->user->identity, (int)$campaign->cms_site_id);
    $offer .= ': '.($items[(int)$campaign->offer_id] ?? '№'.(int)$campaign->offer_id);
}
?>
<div class="sx-campaign">
    <div class="sx-campaign__head">
        <span class="sx-campaign__status sx-campaign__status--<?= Html::encode($campaign->status) ?>"><?= Html::encode($campaign->getStatusName()) ?></span>
        <?php if ($aiMark = \skeeks\cms\communication\services\AiMark::of($campaign)) : ?>
            <span><?= \skeeks\cms\communication\services\AiMark::badge($aiMark, null, 'Подготовлено ИИ') ?>
                <?= Html::encode(\skeeks\cms\communication\services\AiMark::text($aiMark).'. Запускает кампанию только сотрудник.') ?></span>
        <?php endif; ?>
        <span>Тема: <strong><?= Html::encode($campaign->topic ? $campaign->topic->name : 'не выбрана') ?></strong></span>
        <span>Канал: <strong><?= Html::encode(Campaigns::channel($campaign)->title()) ?></strong>,
            <?= Html::encode(mb_strtolower(CmsCommCampaign::senderModes()[$campaign->sender_mode] ?? '')) ?>
            <?= $campaign->account ? Html::encode($campaign->account->address) : '' ?></span>
        <span>Предложение: <strong><?= Html::encode($offer) ?></strong></span>
        <span>Аудитория: <strong><?= Html::encode($audienceText) ?></strong></span>
        <span>Бережно: <strong><?= Html::encode(implode(', ', array_filter([
                    $campaign->one_per_company ? 'одна компания — одно письмо' : null,
                    (int)$campaign->quiet_days > 0 ? 'не мешать менеджеру '.(int)$campaign->quiet_days.' дн.' : null,
                    ($frequency = \skeeks\cms\communication\components\MailingSettings::frequencyDays((int)$campaign->cms_site_id)) > 0
                        ? 'не чаще раза в '.$frequency.' дн.' : null,
                ])) ?: 'без ограничений') ?></strong></span>
        <span>Задачи менеджеру: <strong><?= Html::encode(implode(', ', array_filter([
                    $campaign->task_on_open ? 'открыл предложение или перешёл на сайт' : null,
                    $campaign->task_on_reply ? 'ответил' : null,
                ])) ?: 'нет') ?></strong></span>
        <?php if ($campaign->launched_at) : ?>
            <span>Запущена <?= Html::encode($date($campaign->launched_at)) ?><?= $campaign->launchedBy ? ', '.Html::encode($campaign->launchedBy->shortDisplayName) : '' ?></span>
        <?php endif; ?>
    </div>

    <?php if ($campaign->isDraft) : ?>
        <div class="sx-import__card">
            <h2 class="sx-import__title">Кто получит письмо</h2>
            <?php if ($summaryError) : ?>
                <div class="sx-import__notice sx-import__notice--error" role="alert"><?= Html::encode($summaryError) ?></div>
            <?php elseif ($summary) : ?>
                <div class="sx-campaign__stats">
                    <div class="sx-campaign__stat"><strong><?= (int)$summary['total'] ?></strong><span>получателей в аудитории</span></div>
                    <div class="sx-campaign__stat sx-campaign__stat--ok"><strong><?= (int)$summary['pending'] ?></strong><span>получат письмо</span></div>
                    <div class="sx-campaign__stat"><strong><?= (int)$summary['excluded'] ?></strong><span>исключены</span></div>
                </div>
                <?php if ($summary['reasons']) : ?>
                    <ul class="sx-campaign__reasons">
                        <?php foreach ($summary['reasons'] as $reason => $count) : ?>
                            <li><?= Html::encode(CmsCommCampaignRecipient::reasons()[$reason] ?? $reason) ?> — <strong><?= (int)$count ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($summary['accounts']) : ?>
                    <p class="sx-import__muted">С каких ящиков:</p>
                    <ul class="sx-campaign__reasons">
                        <?php foreach ($summary['accounts'] as $accountId => $count) :
                            $account = CmsCommAccount::findOne((int)$accountId);
                            $days = $account ? (int)ceil($count / max(1, \skeeks\cms\communication\services\MailboxPace::settings($account)['dailyLimit'])) : 0;
                            ?>
                            <li><?= Html::encode($account ? $account->asText() : '—') ?> — <strong><?= (int)$count ?></strong>
                                <span class="sx-import__muted">(<?= Html::encode(AdminCmsCommCampaignController::paceText($account)) ?><?= $days > 1 ? '; примерно '.$days.' раб. дн.' : '' ?>)</span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($summary['recipients']) : ?>
                    <details class="sx-campaign__details">
                        <summary>Все получатели и исключённые</summary>
                        <div class="sx-import__table-wrap">
                            <table class="sx-import__table">
                                <thead><tr><th>Получатель</th><th>Адрес</th><th>Итог</th><th>Письмо</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($summary['recipients'], 0, 500) as $r) : ?>
                                    <tr>
                                        <td><?= $recordLink($r) ?></td>
                                        <td><?= Html::encode((string)$r->address ?: '—') ?></td>
                                        <td><?php if ($r->status === CmsCommCampaignRecipient::STATUS_EXCLUDED) : ?>
                                                <span class="sx-campaign__badge sx-campaign__badge--excluded">Исключён</span>
                                                <div class="sx-import__muted"><?= Html::encode($r->getReasonText()) ?></div>
                                            <?php else : ?>
                                                <span class="sx-campaign__badge sx-campaign__badge--sent">Получит</span>
                                                <div class="sx-import__muted"><?= Html::encode($r->account ? $r->account->address : '') ?></div>
                                            <?php endif; ?></td>
                                        <td><?= $textCell($r, true) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="sx-import__card">
            <h2 class="sx-import__title">Итоги</h2>
            <?php
            $toSend = (int)$stats['total'] - (int)$stats['excluded'];
            $pct = static fn(string $key) => $stats['sent'] ? ' · '.Html::encode(str_replace('.', ',', (string)$stats['percent'][$key])).'%' : '';
            ?>
            <div class="sx-campaign__stats">
                <div class="sx-campaign__stat sx-campaign__stat--ok"><strong><?= (int)$stats['sent'] ?> из <?= $toSend ?></strong><span>отправлено</span></div>
                <div class="sx-campaign__stat"><strong><?= (int)$stats['opened'] ?><?= $pct('opened') ?></strong><span>открыли предложение</span></div>
                <div class="sx-campaign__stat"><strong><?= (int)$stats['visited'] ?><?= $pct('visited') ?></strong><span>перешли на сайт</span></div>
                <div class="sx-campaign__stat"><strong><?= (int)$stats['replied'] ?><?= $pct('replied') ?></strong><span>ответили</span></div>
                <div class="sx-campaign__stat"><strong><?= (int)$stats['accepted'] ?><?= $pct('accepted') ?></strong><span>приняли КП</span></div>
                <div class="sx-campaign__stat"><strong><?= (int)$stats['unsubscribed'] ?><?= $pct('unsubscribed') ?></strong><span>отписались</span></div>
                <div class="sx-campaign__stat"><strong><?= (int)$stats['pending'] ?></strong><span>ждут отправки</span></div>
                <div class="sx-campaign__stat<?= $stats['failed'] ? ' sx-campaign__stat--bad' : '' ?>"><strong><?= (int)$stats['failed'] ?></strong><span>ошибки</span></div>
                <div class="sx-campaign__stat"><strong><?= (int)$stats['excluded'] ?></strong><span>исключены</span></div>
            </div>
            <p class="sx-import__muted">Проценты — от отправленных писем. «Открыли» и «перешли на сайт» — только настоящие переходы людей по ссылкам из письма (не проверки ссылок почтовыми службами).</p>
            <?php if ($campaign->status === CmsCommCampaign::STATUS_RUNNING && $stats['pending']) :
                $this->registerJs('setTimeout(function () { if (!document.hidden) { window.location.reload(); } }, 30000);');
                ?>
                <div class="sx-import__notice sx-import__notice--info" role="status">
                    Письма уходят постепенно: по скорости каждого ящика и только в рабочие часы. Страница обновляется сама.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="sx-campaign__actions">
        <?php if ($campaign->isDraft) : ?>
            <?php if ($launchErrors) : ?>
                <div class="sx-import__notice sx-import__notice--error" role="alert"><?= Html::encode(implode(' ', $launchErrors)) ?></div>
            <?php endif; ?>
            <?= $button('launch', 'Запустить: писем — '.(int)($summary['pending'] ?? 0), 'btn-primary', [
                'disabled' => $launchErrors || empty($summary['pending']),
                'data-confirm' => 'Запустить кампанию? Получатели зафиксируются, письма пойдут по расписанию ящиков.',
            ]) ?>
            <?= Html::a('Изменить письмо и аудиторию', Url::to(['update', 'pk' => (int)$campaign->id]), ['class' => 'btn btn-default', 'data-pjax' => 0]) ?>
            <?= Html::a('+ Добавить получателя', Url::to(['recipient', 'pk' => (int)$campaign->id]), ['class' => 'btn btn-default', 'data-pjax' => 0]) ?>
        <?php elseif ($campaign->status === CmsCommCampaign::STATUS_RUNNING) : ?>
            <?= $button('pause', 'Пауза', 'btn-default') ?>
            <?= $button('cancel', 'Отменить кампанию', 'btn-default', ['data-confirm' => 'Отменить кампанию? Неотправленные письма не уйдут.']) ?>
        <?php elseif ($campaign->status === CmsCommCampaign::STATUS_PAUSED) : ?>
            <?= $button('resume', 'Продолжить', 'btn-primary') ?>
            <?= $button('cancel', 'Отменить кампанию', 'btn-default', ['data-confirm' => 'Отменить кампанию? Неотправленные письма не уйдут.']) ?>
        <?php endif; ?>
    </div>

    <div class="sx-import__card">
        <h2 class="sx-import__title">Письмо</h2>
        <?php if ($samples) : ?>
            <form method="get" action="<?= Html::encode(Url::current(['lead' => null])) ?>" class="sx-campaign__sample">
                <?php foreach (\Yii::$app->request->get() as $key => $value) :
                    if ($key === 'lead' || is_array($value)) {
                        continue;
                    } ?>
                    <?= Html::hiddenInput($key, $value) ?>
                <?php endforeach; ?>
                <label for="sx-campaign-lead">Как увидит</label>
                <?= Html::dropDownList('lead', $sample ? $sample->record_type.':'.(int)$sample->record_id : null, $samples, ['class' => 'form-control', 'id' => 'sx-campaign-lead', 'onchange' => 'this.form.submit()']) ?>
            </form>
        <?php endif; ?>
        <?php if ($previewError) : ?>
            <div class="sx-import__notice sx-import__notice--error" role="alert"><?= Html::encode($previewError) ?></div>
        <?php elseif ($preview) : ?>
            <dl class="sx-campaign__letter-head">
                <dt>От кого</dt><dd><?= Html::encode($preview['from'] ?: '—') ?><?= $preview['sender'] ? ' · '.Html::encode($preview['sender']) : '' ?></dd>
                <dt>Кому</dt><dd><?= Html::encode($preview['to']) ?></dd>
                <dt>Тема</dt><dd><strong><?= Html::encode($preview['subject'] ?: '(без темы)') ?></strong></dd>
            </dl>
            <iframe class="sx-campaign__letter" sandbox="" title="Предпросмотр письма"
                    srcdoc="<?= Html::encode('<!doctype html><meta charset="utf-8"><body style="margin:12px;background:#fff;color:#1f2430;font-family:Arial,sans-serif">'.$preview['html'].'</body>') ?>"></iframe>
            <?php if ($campaign->offer_kind === CmsCommCampaign::OFFER_EACH) : ?>
                <p class="sx-import__muted">КП этому лиду создастся из шаблона, когда уйдёт его письмо; кнопка поведёт на его КП.</p>
            <?php endif; ?>
            <?= Html::beginForm(Url::current(), 'post', ['class' => 'sx-campaign__inline']) ?>
            <?= Html::hiddenInput('do', 'test') ?>
            <?= Html::hiddenInput('lead', $sample ? $sample->record_type.':'.(int)$sample->record_id : '') ?>
            <?= Html::submitButton('Отправить тестовое письмо себе', ['class' => 'btn btn-default']) ?>
            <span class="sx-import__muted">на <?= Html::encode((string)\Yii::$app->user->identity->email ?: 'email из профиля') ?>; ссылки в тесте не работают</span>
            <?= Html::endForm() ?>
        <?php else : ?>
            <p class="sx-import__muted">Предпросмотр появится, когда в аудитории будет хотя бы один получатель.</p>
        <?php endif; ?>
    </div>

    <?php if (!$campaign->isDraft) : ?>
        <div class="sx-import__card">
            <h2 class="sx-import__title">Получатели</h2>
            <div class="sx-import__counts">
                <?= Html::a('Все: '.(int)$stats['total'], Url::current(['state' => null, 'page' => null]), ['class' => 'sx-import__filter'.($filter === '' ? ' is-active' : ''), 'data-pjax' => 0]) ?>
                <?php foreach (AdminCmsCommCampaignController::reportFilters() as $key => $item) :
                    $count = ['sent' => $stats['sent'], 'pending' => $stats['pending'], 'failed' => $stats['failed'], 'opened' => $stats['opened'], 'visited' => $stats['visited'],
                        'replied' => $stats['replied'], 'accepted' => $stats['accepted'], 'unsubscribed' => $stats['unsubscribed'],
                        'excluded' => $stats['excluded'], 'cancelled' => $stats['cancelled'], 'personal' => $stats['personal']][$key];
                    if (!$count && $filter !== $key) {
                        continue;
                    } ?>
                    <?= Html::a(Html::encode($item['label']).': '.(int)$count, Url::current(['state' => $key, 'page' => null]),
                        ['class' => 'sx-import__filter'.($filter === $key ? ' is-active' : ''), 'data-pjax' => 0]) ?>
                <?php endforeach; ?>
            </div>
            <div class="sx-import__table-wrap">
                <table class="sx-import__table sx-campaign__report">
                    <thead><tr><th>Получатель</th><th>Письмо</th><th>Что было дальше</th></tr></thead>
                    <tbody>
                    <?php foreach ($report as $r) : ?>
                        <tr>
                            <td><?= $recordLink($r) ?>
                                <div class="sx-import__muted"><?= Html::encode((string)$r->address) ?></div>
                                <div><?= $textCell($r, false) ?></div></td>
                            <td><span class="sx-campaign__badge sx-campaign__badge--<?= Html::encode($r->status) ?>"><?= Html::encode($r->getStatusName()) ?></span>
                                <?php if ($r->status === CmsCommCampaignRecipient::STATUS_EXCLUDED) : ?>
                                    <div class="sx-import__muted"><?= Html::encode($r->getReasonText()) ?></div>
                                <?php elseif ($r->error) : ?>
                                    <div class="sx-campaign__error"><?= Html::encode($r->error) ?></div>
                                <?php endif; ?>
                                <?php if ($r->sent_at) : ?>
                                    <div class="sx-import__muted"><?= Html::encode($date($r->sent_at)) ?><?= $r->account ? ', '.Html::encode($r->account->address) : '' ?></div>
                                <?php elseif ($r->account && in_array($r->status, [CmsCommCampaignRecipient::STATUS_PENDING, CmsCommCampaignRecipient::STATUS_SENDING], true)) : ?>
                                    <div class="sx-import__muted">с <?= Html::encode($r->account->address) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="sx-campaign__events">
                                <?php foreach (['opened_at' => 'Открыл предложение', 'visited_at' => 'Перешёл на сайт', 'replied_at' => 'Ответил', 'accepted_at' => 'Принял КП', 'unsubscribed_at' => 'Отписался'] as $field => $label) : ?>
                                    <?php if ($r->$field) : ?>
                                        <span class="sx-campaign__event sx-campaign__event--<?= Html::encode(substr($field, 0, -3)) ?>"><?= Html::encode($label) ?>
                                            <span class="sx-import__muted"><?= Html::encode($date($r->$field)) ?></span></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$report) : ?>
                        <tr><td colspan="3" class="sx-import__muted">Никого.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($reportTotal > 100) : ?>
                <div class="sx-import__pages">
                    <?php for ($p = 1; $p <= (int)ceil($reportTotal / 100); $p++) : ?>
                        <?= Html::a($p, Url::current(['page' => $p]), ['class' => 'sx-import__filter'.($p === $page ? ' is-active' : ''), 'data-pjax' => 0]) ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
