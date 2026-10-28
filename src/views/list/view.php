<?php
/**
 * The card of a list of recipients (AdminCmsCommListController::view()):
 * counters, "Добавить", the CSV, members with who, when and from where
 * added them, "убрать"; removed members under their own filter.
 *
 * @var $this yii\web\View
 * @var $list skeeks\cms\communication\models\CmsCommList
 * @var $stats array RecipientLists::stats()
 * @var $members skeeks\cms\communication\models\CmsCommListMember[]
 * @var $data array type => RecipientLists::addresses()
 * @var $stopped true[] address => true
 * @var $total int
 * @var $page int
 * @var $state string '', 'removed' or a record type
 * @var $removedCount int
 * @var $rules skeeks\cms\communication\models\CmsCommListRule[]
 */

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\helpers\ListUi;
use skeeks\cms\communication\services\CrmRecords;
use yii\helpers\Html;
use yii\helpers\Url;

$formatter = \Yii::$app->formatter;
$recordRoutes = [CrmRecords::LEAD => '/cms/admin-cms-lead/view', CrmRecords::COMPANY => '/cms/admin-cms-company/view', CrmRecords::CLIENT => '/cms/admin-user/view'];
$addUrl = ['/cmsCommunication/admin-cms-comm-list/add', 'pk' => (int)$list->id];
$addressesUrl = ['/cmsCommunication/admin-cms-comm-list/add-addresses', 'pk' => (int)$list->id];
$importUrl = ['/cmsCommunication/admin-cms-comm-import/create', 'list' => (int)$list->id];
$exportUrl = (string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-list/export', 'pk' => (int)$list->id])->url;
$filter = static fn(string $value, string $label) => Html::a(Html::encode($label), Url::current(['state' => $value ?: null, 'page' => null]),
    ['class' => 'sx-import__filter'.($state === $value ? ' is-active' : ''), 'data-pjax' => 0]);
$titles = array_map(static fn($t) => $t['title'], CrmRecords::types());
$pages = (int)ceil($total / 100);
?>
<div class="sx-campaign sx-list">
    <?php if ($list->description) : ?>
        <p class="sx-import__muted"><?= nl2br(Html::encode($list->description)) ?></p>
    <?php endif; ?>

    <div class="sx-list__stats">
        <div class="sx-list__stat"><strong><?= (int)$stats['members'] ?></strong><span>получателей</span></div>
        <div class="sx-list__stat sx-list__stat--ok"><strong><?= (int)$stats['email'] ?></strong><span>можно отправить письмом</span></div>
        <div class="sx-list__stat sx-list__stat--warn"><strong><?= (int)$stats['stopped'] ?></strong><span>отписались от всех рассылок или в стоп-листе</span></div>
        <div class="sx-list__stat"><strong><?= (int)$stats['noEmail'] ?></strong><span>без email</span></div>
    </div>
    <p class="sx-import__muted">Отписку от отдельной темы и остальные правила (частота, «не мешать менеджеру», одна компания — одно письмо)
        проверит сводка кампании перед запуском.</p>

    <div class="sx-import__actions">
        <?= Html::a('+ Добавить из CRM', Url::to($addUrl), ['class' => 'btn btn-primary', 'data-pjax' => 0, 'onclick' => ListUi::windowOnClick($addUrl, 'small')]) ?>
        <?php if (ListUi::canImportAudience()) : ?>
        <?= Html::a('Добавить адреса', Url::to($addressesUrl), ['class' => 'btn btn-default', 'data-pjax' => 0, 'onclick' => ListUi::windowOnClick($addressesUrl)]) ?>
        <?= Html::a('Импорт в список', Url::to($importUrl), ['class' => 'btn btn-default', 'data-pjax' => 0,
            'onclick' => \skeeks\cms\communication\helpers\MailingUi::importUploadOnClick(['list' => (int)$list->id])]) ?>
        <?php endif; ?>
        <?= Html::a('Выгрузить CSV', $exportUrl, ['class' => 'btn btn-default', 'data-pjax' => 0, 'target' => '_blank']) ?>
    </div>
    <p class="sx-import__muted">Много записей сразу — «Импорт в список» (таблица CSV или из Excel: компании и контакты без сделок, повторы найдутся
        сами), «Добавить адреса» (вставить email и телефоны) или массовым действием «В список рассылки» в разделах «Компании», «Сделки», «Клиенты».</p>

    <div class="sx-import__card">
        <h2 class="sx-import__title">Правила пополнения</h2>
        <?php if (!$rules) : ?>
            <p class="sx-import__muted">Список может пополняться сам. Откройте
                <?= implode(', ', array_map(static fn($s) => Html::a(Html::encode('«'.$s['title'].'»'), Url::to([$s['route']]), ['target' => '_blank', 'data-pjax' => 0]),
                    \skeeks\cms\communication\services\ListRules::sections())) ?>,
                поставьте нужный фильтр (например, категория и «Готовый фильтр: Нет оплат»), отметьте записи или «для всех» и нажмите
                «В список рассылки» с галочкой «Пополнять по этому фильтру». Новые записи по этому фильтру будут добавляться раз в час
                и перед каждой кампанией.</p>
        <?php else : ?>
            <ul class="sx-list-add__lists sx-list__rules">
                <?php foreach ($rules as $rule) :
                    $form = static fn(string $do, string $label, string $class = 'btn-default', array $options = []) => Html::beginForm(Url::current(), 'post', ['class' => 'sx-campaign__inline'])
                        .Html::hiddenInput('do', $do).Html::hiddenInput('rule', (int)$rule->id)
                        .Html::submitButton($label, ['class' => 'btn btn-sm '.$class] + $options).Html::endForm();
                    ?>
                    <li>
                        <span>
                            <strong><?= Html::encode($rule->title) ?></strong><?= $rule->is_active ? '' : ' <span class="sx-import__badge sx-import__badge--skipped">выключено</span>' ?>
                            <span class="sx-import__muted"><br>№<?= (int)$rule->id ?>, от имени <?= Html::encode($rule->user ? $rule->user->shortDisplayName : '—') ?>;
                                <?php if ($rule->last_run_at) : ?>
                                    пересчитано <?= Html::encode($formatter->asDatetime((int)$rule->last_run_at, 'php:d.m.Y H:i')) ?>:
                                    найдено <?= (int)$rule->last_found ?>, добавлено <?= (int)$rule->last_added ?>, всего добавлено <?= (int)$rule->added_total ?>
                                <?php else : ?>
                                    ещё не пересчитывалось
                                <?php endif; ?>
                            </span>
                            <?php if ($rule->last_error) : ?>
                                <br><span class="sx-import__error"><?= Html::encode($rule->last_error) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="sx-list__rule-actions">
                            <?= $form('rule-run', 'Обновить сейчас') ?>
                            <?= $form('rule-toggle', $rule->is_active ? 'Выключить' : 'Включить') ?>
                            <?= $form('rule-delete', 'Удалить', 'btn-default', ['data-confirm' => 'Удалить правило? Добавленные им записи останутся в списке.']) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="sx-import__muted">Правила пересчитываются раз в час и перед каждой кампанией по списку. Добавляются только новые записи;
                кого убрали вручную, правило не вернёт; запись, которая перестала подходить, остаётся в списке — для этого есть
                «Исключить списки» в кампании.</p>
        <?php endif; ?>
    </div>

    <div class="sx-import__card">
        <div class="sx-import__counts">
            <?= $filter('', 'В списке: '.(int)$stats['members']) ?>
            <?php foreach ($stats['byType'] as $type => $count) : ?>
                <?= $filter($type, ($titles[$type] ?? $type).': '.(int)$count) ?>
            <?php endforeach; ?>
            <?php if ($removedCount) : ?>
                <?= $filter('removed', 'Убраны: '.$removedCount) ?>
            <?php endif; ?>
        </div>
        <?php if (!$members) : ?>
            <p class="sx-import__muted"><?= $state === 'removed' ? 'Убранных нет.' : 'В списке пока никого нет.' ?></p>
        <?php else : ?>
            <div class="sx-import__table-wrap">
                <table class="sx-import__table sx-list__table">
                    <thead>
                    <tr><th>Запись CRM</th><th>Email</th><th>Добавлен</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($members as $member) :
                        $record = $data[$member->record_type]['records'][(int)$member->record_id] ?? null;
                        $addresses = $data[$member->record_type]['addresses'][(int)$member->record_id] ?? [];
                        $address = (string)(array_key_first($addresses) ?? '');
                        $title = ($titles[$member->record_type] ?? 'Запись').' №'.(int)$member->record_id;
                        ?>
                        <tr>
                            <td>
                                <?= isset($recordRoutes[$member->record_type]) && $record
                                    ? Html::a(Html::encode($title), Url::to([$recordRoutes[$member->record_type], 'pk' => (int)$member->record_id]), ['target' => '_blank', 'data-pjax' => 0])
                                    : Html::encode($title) ?>
                                <div><?= $record ? Html::encode(CrmRecords::name($record)) : '<span class="sx-import__muted">удалена из CRM</span>' ?></div>
                            </td>
                            <td>
                                <?php if ($address === '') : ?>
                                    <span class="sx-import__muted">нет email</span>
                                <?php else : ?>
                                    <?= Html::encode($address) ?><?= $addresses[$address] !== '' ? ' <span class="sx-import__muted">'.Html::encode($addresses[$address]).'</span>' : '' ?>
                                    <?php if (isset($stopped[$address])) : ?>
                                        <div><span class="sx-import__badge sx-import__badge--duplicate">стоп-лист</span></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="sx-import__who">
                                <?= Html::encode($formatter->asDatetime((int)$member->created_at, 'php:d.m.Y H:i')) ?>,
                                <?= Html::encode($member->getViaText()) ?><?= $member->addedBy ? ' · '.Html::encode($member->addedBy->shortDisplayName) : '' ?>
                                <?php if ($member->removed_at) : ?>
                                    <div class="sx-import__muted">убран <?= Html::encode($formatter->asDatetime((int)$member->removed_at, 'php:d.m.Y H:i')) ?><?=
                                        $member->removedBy ? ' · '.Html::encode($member->removedBy->shortDisplayName) : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$member->removed_at) : ?>
                                    <?= Html::beginForm(Url::current(), 'post', ['class' => 'sx-campaign__inline']) ?>
                                    <?= Html::hiddenInput('do', 'remove').Html::hiddenInput('member', (int)$member->id) ?>
                                    <?= Html::submitButton('убрать', ['class' => 'btn btn-default btn-sm', 'data-confirm' => 'Убрать из списка? В истории он останется.']) ?>
                                    <?= Html::endForm() ?>
                                <?php elseif ($record) : ?>
                                    <?= Html::beginForm(Url::current(), 'post', ['class' => 'sx-campaign__inline']) ?>
                                    <?= Html::hiddenInput('do', 'restore').Html::hiddenInput('member', (int)$member->id) ?>
                                    <?= Html::submitButton('вернуть', ['class' => 'btn btn-default btn-sm']) ?>
                                    <?= Html::endForm() ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1) : ?>
                <div class="sx-import__pages">
                    <?php for ($p = 1; $p <= $pages; $p++) : ?>
                        <?= $p === $page ? Html::tag('strong', $p) : Html::a($p, Url::current(['page' => $p]), ['data-pjax' => 0]) ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
