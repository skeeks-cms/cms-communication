<?php
/**
 * The card of an import of a base into a list (AdminCmsCommImportController::audienceView()):
 * a draft — columns, the list, settings, the preview with new, found and
 * excluded rows and why, "Импортировать"; a queued or running one —
 * progress; a finished one — the report by rows.
 *
 * @var $this yii\web\View
 * @var $import skeeks\cms\communication\models\CmsCommImport
 * @var $preview array|null AudienceImport::preview()
 * @var $errors string[]
 * @var $listName string
 * @var $sample skeeks\cms\communication\models\CmsCommImportRow[]
 * @var $executors string[] id => name
 * @var $topics skeeks\cms\communication\models\CmsCommTopic[]
 * @var $lists string[] id => name
 * @var $rows skeeks\cms\communication\models\CmsCommImportRow[]
 * @var $rowsTotal int
 * @var $page int
 * @var $statusFilter string
 */

use skeeks\cms\communication\assets\MailingAsset;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommImport;
use skeeks\cms\communication\models\CmsCommImportRow;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\LeadImport;
use skeeks\cms\communication\services\SendContacts;
use yii\helpers\Html;
use yii\helpers\Url;

MailingAsset::register($this);
$this->registerJs("if (window.sx && sx.Window && sx.Window.openerWidgetTriggerEvent) { sx.Window.openerWidgetTriggerEvent('model-update', {submitBtn: 'apply'}); }");

$user = \Yii::$app->user->identity;
$titles = $import->getColumnTitles();
$mapping = $import->getMappingArray();
$isDraft = $import->status === CmsCommImport::STATUS_DRAFT;
$isRunning = in_array($import->status, [CmsCommImport::STATUS_QUEUED, CmsCommImport::STATUS_RUNNING], true);
$step = $isDraft ? ($preview ? 3 : 2) : 4;
$statusLabels = CmsCommImportRow::statuses(CmsCommImport::MODE_AUDIENCE);
$badgeClass = [CmsCommImportRow::STATUS_CREATED => 'created', CmsCommImportRow::STATUS_DUPLICATE => 'duplicate',
    CmsCommImportRow::STATUS_EXCLUDED => 'skipped', CmsCommImportRow::STATUS_SKIPPED => 'skipped'];
$badge = static fn(string $status) => Html::tag('span', Html::encode($statusLabels[$status] ?? $status), ['class' => 'sx-import__badge sx-import__badge--'.($badgeClass[$status] ?? $status)]);
$recordRoutes = [CrmRecords::COMPANY => '/cms/admin-cms-company/view', CrmRecords::CLIENT => '/cms/admin-user/view', CrmRecords::LEAD => '/cms/admin-cms-lead/view'];
$recordTitles = [CrmRecords::COMPANY => 'Компания', CrmRecords::CLIENT => 'Контакт', CrmRecords::LEAD => 'Сделка'];
$recordLink = static function (string $type, int $id) use ($user, $recordRoutes, $recordTitles): string {
    $title = ($recordTitles[$type] ?? 'Запись').' №'.$id;
    if (!isset($recordRoutes[$type]) || !CrmRecords::isVisible(CrmRecords::codeOf($type), $id, $user)) {
        return Html::encode(($recordTitles[$type] ?? 'Запись').' другого менеджера');
    }

    return Html::a(Html::encode($title), Url::to([$recordRoutes[$type], 'pk' => $id]), ['target' => '_blank', 'data-pjax' => 0]);
};
$who = static function (array $data): string {
    $parts = array_filter([$data['company'], $data['name']], static fn($v) => $v !== '');
    $contacts = array_merge($data['emails'], array_map([SendContacts::class, 'formatPhone'], $data['phones']));

    return Html::tag('div', Html::encode($parts ? implode(' — ', $parts) : '—'), ['class' => 'sx-import__who'])
        .Html::tag('div', Html::encode(implode(', ', $contacts)), ['class' => 'sx-import__muted']);
};
$fields = LeadImport::fields();
$fields['name'] = 'Контакт (имя)';
?>
<div class="sx-import">
    <ol class="sx-import__steps">
        <?php foreach (['Загрузить таблицу', 'Колонки и список', 'Предпросмотр', 'Импорт'] as $i => $title) : ?>
            <li class="<?= $i + 1 < $step ? 'is-done' : ($i + 1 === $step ? 'is-current' : '') ?>"><?= Html::encode($title) ?></li>
        <?php endforeach; ?>
    </ol>

    <div class="sx-import__meta">
        <span><strong>База в список рассылки</strong></span>
        <span><?= Html::encode($import->file_name ?: 'Вставленные адреса') ?></span>
        <span>строк: <strong><?= (int)$import->row_count ?></strong></span>
        <span><?= Html::encode($import->getStatusName()) ?></span>
    </div>

    <?php if ($import->status === CmsCommImport::STATUS_FAILED && $import->error) : ?>
        <div class="sx-import__notice sx-import__notice--error" role="alert"><?= Html::encode($import->error) ?></div>
    <?php endif; ?>

    <?php if ($isDraft) : ?>
        <form method="post" action="<?= Html::encode(Url::current()) ?>">
            <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
            <div class="sx-import__card">
                <h2 class="sx-import__title">Колонки</h2>
                <p class="sx-import__muted">Что лежит в каждой колонке. Email и телефон могут быть в нескольких колонках. Строка с названием компании
                    станет компанией с контактом, без названия — контактом.</p>
                <?php if (!empty($errors['mapping'])) : ?>
                    <div class="sx-import__notice sx-import__notice--error"><?= Html::encode($errors['mapping']) ?></div>
                <?php endif; ?>
                <div class="sx-import__columns">
                    <?php foreach ($titles as $i => $title) :
                        $examples = array_filter(array_map(static fn($r) => $r->getValues()[$i] ?? '', $sample), static fn($v) => $v !== '');
                        ?>
                        <div class="sx-import__column">
                            <div class="sx-import__column-title"><?= Html::encode($title) ?></div>
                            <div class="sx-import__muted sx-import__examples"><?= Html::encode($examples ? implode(' · ', $examples) : 'пусто') ?></div>
                            <?= Html::dropDownList('mapping['.$i.']', $mapping[$i] ?? '', ['' => 'Не импортировать'] + $fields, ['class' => 'form-control']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sx-import__card">
                <h2 class="sx-import__title">Список и настройки</h2>
                <div class="sx-import__settings">
                    <label>
                        <span class="sx-import__label">Список рассылки</span>
                        <?= Html::dropDownList('cms_comm_list_id', $import->listChoice ?? ($import->cms_comm_list_id ? (string)$import->cms_comm_list_id : 'new'),
                            ['new' => '+ Новый список'] + $lists, ['class' => 'form-control', 'data-sx-import-list' => 1]) ?>
                        <?php if (!empty($errors['cms_comm_list_id'])) : ?><span class="sx-import__error"><?= Html::encode($errors['cms_comm_list_id']) ?></span><?php endif; ?>
                    </label>
                    <label data-sx-import-list-name>
                        <span class="sx-import__label">Название нового списка</span>
                        <?= Html::textInput('list_name', $listName !== '' ? $listName : ($import->file_name ? pathinfo($import->file_name, PATHINFO_FILENAME) : ''),
                            ['class' => 'form-control', 'placeholder' => 'Например: Плиточные магазины Юга']) ?>
                    </label>
                    <label>
                        <span class="sx-import__label">Ответственный за новые компании и контакты</span>
                        <?= Html::dropDownList('executor_id', $import->executor_id, $executors, ['class' => 'form-control']) ?>
                        <?php if (!empty($errors['executor_id'])) : ?><span class="sx-import__error"><?= Html::encode($errors['executor_id']) ?></span><?php endif; ?>
                    </label>
                    <label>
                        <span class="sx-import__label">Тема рассылки</span>
                        <?= Html::dropDownList('cms_comm_topic_id', $import->cms_comm_topic_id, ['' => 'Без темы'] + \yii\helpers\ArrayHelper::map($topics, 'id', 'name'), ['class' => 'form-control']) ?>
                    </label>
                    <label>
                        <span class="sx-import__label">Основание согласия</span>
                        <?= Html::dropDownList('consent_basis', $import->consent_basis ?: CmsCommContactTopic::BASIS_COLD, CmsCommContactTopic::bases(), ['class' => 'form-control']) ?>
                    </label>
                </div>
                <p class="sx-import__muted">Сделки не создаются: сделка появится, когда получатель ответит на письмо или оставит заявку. Новые карточки
                    получают источник «Рассылка: база …» и отметку «холодный, согласия нет». Найденные в CRM компании и контакты не меняются:
                    импорт только добавляет их в список. Отписку импорт не снимает.</p>
                <p class="sx-import__muted">Не попадут в список: действующие клиенты (активная услуга или оплата за последний год), клиенты с отказом
                    за последние 90 дней, отписавшиеся, клиенты других менеджеров, строки с несколькими совпадениями или только с похожим названием компании.</p>
            </div>

            <?php if ($preview) :
                $counts = $preview['counts'];
                ?>
                <div class="sx-import__card">
                    <h2 class="sx-import__title">Предпросмотр</h2>
                    <div class="sx-import__counts">
                        <?php foreach ($counts as $status => $count) : ?>
                            <span><?= $badge($status) ?> <?= (int)$count ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($preview['reasons']) : ?>
                        <p class="sx-import__muted">Исключены: <?= Html::encode(implode(', ', array_map(static fn($r, $n) => mb_strtolower($r, 'UTF-8').' — '.$n,
                                array_keys($preview['reasons']), $preview['reasons']))) ?>.</p>
                    <?php endif; ?>
                    <div class="sx-import__table-wrap">
                        <table class="sx-import__table">
                            <thead><tr><th>Строка</th><th>Получатель</th><th>Итог</th></tr></thead>
                            <tbody>
                            <?php foreach ($preview['rows'] as $outcome) : ?>
                                <tr>
                                    <td><?= (int)$outcome['row']->row_num ?></td>
                                    <td><?= $who($outcome['data']) ?></td>
                                    <td><?= $badge($outcome['status']) ?>
                                        <?php if ($outcome['record'] && $outcome['sameFile'] === null && $outcome['visible']) : ?>
                                            <div class="sx-import__muted"><?= $recordLink($outcome['record'][0], (int)$outcome['record'][1]) ?>,
                                                <?= Html::encode((string)$outcome['reason']) ?></div>
                                        <?php elseif ($outcome['status'] === CmsCommImportRow::STATUS_CREATED) : ?>
                                            <div class="sx-import__muted"><?= Html::encode($outcome['reason'] ?: ($outcome['data']['company'] !== ''
                                                    ? 'Новая компания'.($outcome['data']['name'] !== '' ? ' и контакт' : '') : 'Новый контакт')) ?></div>
                                        <?php elseif ($outcome['reason']) : ?>
                                            <div class="sx-import__muted"><?= Html::encode($outcome['reason']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($import->row_count > count($preview['rows'])) : ?>
                        <p class="sx-import__muted">Показаны первые <?= count($preview['rows']) ?> строк из <?= (int)$import->row_count ?>; итоги — по всему файлу.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="sx-import__actions">
                <button type="submit" name="preview" value="1" class="btn <?= $preview ? 'btn-default' : 'btn-primary' ?>">
                    <?= $preview ? 'Обновить предпросмотр' : 'Показать предпросмотр' ?></button>
                <?php if ($preview) :
                    $into = (int)$preview['counts'][CmsCommImportRow::STATUS_CREATED] + (int)$preview['counts'][CmsCommImportRow::STATUS_DUPLICATE];
                    ?>
                    <button type="submit" name="start" value="1" class="btn btn-primary">Добавить в список: <?= $into ?></button>
                    <?php if ($import->row_count > LeadImport::SYNC_LIMIT) : ?>
                        <span class="sx-import__muted">Большой файл: импорт пойдёт в фоне, по окончании придёт уведомление.</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </form>
        <?php
        $this->registerJs(<<<JS
(function () {
    var select = document.querySelector('[data-sx-import-list]');
    var name = document.querySelector('[data-sx-import-list-name]');
    if (!select || !name) { return; }
    var sync = function () { name.style.display = select.value === 'new' ? '' : 'none'; };
    select.addEventListener('change', sync);
    sync();
})();
JS);
        ?>
    <?php else : ?>
        <?php if ($isRunning) :
            $done = (int)$import->created_count + (int)$import->duplicate_count + (int)$import->excluded_count + (int)$import->skipped_count;
            $this->registerJs('setTimeout(function () { window.location.reload(); }, 4000);');
            ?>
            <div class="sx-import__notice sx-import__notice--info" role="status">
                Импорт идёт в фоне: обработано <?= $done ?> из <?= (int)$import->row_count ?> строк. Страница обновится сама; по окончании придёт уведомление.
            </div>
        <?php endif; ?>

        <?php if ($import->status === CmsCommImport::STATUS_FAILED) : ?>
            <form method="post" action="<?= Html::encode(Url::current()) ?>" class="sx-import__actions">
                <?= Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
                <button type="submit" name="start" value="1" class="btn btn-primary">Продолжить импорт</button>
                <span class="sx-import__muted">Сделанные строки не повторятся.</span>
            </form>
        <?php endif; ?>

        <div class="sx-import__card">
            <h2 class="sx-import__title">Отчёт</h2>
            <div class="sx-import__meta">
                <?php if ($import->list) : ?>
                    <span>Список: <strong><?= Html::a(Html::encode($import->list->name), Url::to(['/cmsCommunication/admin-cms-comm-list/view', 'pk' => (int)$import->list->id]),
                                ['target' => '_blank', 'data-pjax' => 0]) ?></strong></span>
                <?php endif; ?>
                <span>Ответственный за новые: <strong><?= Html::encode($import->executor ? $import->executor->shortDisplayName : '—') ?></strong></span>
                <span>Тема: <strong><?= Html::encode($import->topic ? $import->topic->name : 'без темы') ?></strong></span>
            </div>
            <div class="sx-import__counts">
                <?= Html::a('Все строки: '.(int)$import->row_count, Url::current(['status' => null, 'page' => null]), ['class' => 'sx-import__filter'.($statusFilter === '' ? ' is-active' : ''), 'data-pjax' => 0]) ?>
                <?php foreach ([CmsCommImportRow::STATUS_CREATED => 'created_count', CmsCommImportRow::STATUS_DUPLICATE => 'duplicate_count',
                                   CmsCommImportRow::STATUS_EXCLUDED => 'excluded_count', CmsCommImportRow::STATUS_SKIPPED => 'skipped_count'] as $s => $attribute) : ?>
                    <?= Html::a($badge($s).' '.(int)$import->$attribute, Url::current(['status' => $s, 'page' => null]),
                        ['class' => 'sx-import__filter'.($statusFilter === $s ? ' is-active' : ''), 'data-pjax' => 0]) ?>
                <?php endforeach; ?>
            </div>
            <div class="sx-import__table-wrap">
                <table class="sx-import__table">
                    <thead><tr><th>Строка</th><th>Получатель</th><th>Итог</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?= (int)$row->row_num ?></td>
                            <td><?= $who(LeadImport::rowData($import, $row->getValues())) ?></td>
                            <td>
                                <?= $row->status ? $badge((string)$row->status) : '<span class="sx-import__muted">ещё не обработана</span>' ?>
                                <?php if ($row->record_type && $row->record_id) : ?>
                                    <div><?= $recordLink((string)$row->record_type, (int)$row->record_id) ?><?= $row->reason ? '<span class="sx-import__muted">, '.Html::encode($row->reason).'</span>' : '' ?></div>
                                <?php elseif ($row->reason) : ?>
                                    <div class="sx-import__muted"><?= Html::encode($row->reason) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($rowsTotal > 100) : ?>
                <div class="sx-import__pages">
                    <?php for ($p = 1; $p <= (int)ceil($rowsTotal / 100); $p++) : ?>
                        <?= Html::a($p, Url::current(['page' => $p]), ['class' => 'sx-import__filter'.($p === $page ? ' is-active' : ''), 'data-pjax' => 0]) ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
