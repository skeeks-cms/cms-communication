<?php
/**
 * The card of an import into leads (AdminCmsCommImportController::view()):
 * a draft — columns, settings, preview with duplicates and "Импортировать";
 * a queued or running one — progress; a finished one — the report by rows.
 *
 * @var $this yii\web\View
 * @var $import skeeks\cms\communication\models\CmsCommImport
 * @var $preview array|null LeadImport::preview()
 * @var $errors string[]
 * @var $sample skeeks\cms\communication\models\CmsCommImportRow[] first rows for the column examples
 * @var $executors string[] id => name
 * @var $topics skeeks\cms\communication\models\CmsCommTopic[]
 * @var $rows skeeks\cms\communication\models\CmsCommImportRow[] the page of the report
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
// Opened in a window from the tab "Импорт": the list there shows the new import and its state once the window closes.
$this->registerJs("if (window.sx && sx.Window && sx.Window.openerWidgetTriggerEvent) { sx.Window.openerWidgetTriggerEvent('model-update', {submitBtn: 'apply'}); }");

$user = \Yii::$app->user->identity;
$titles = $import->getColumnTitles();
$mapping = $import->getMappingArray();
$isDraft = $import->status === CmsCommImport::STATUS_DRAFT;
$isRunning = in_array($import->status, [CmsCommImport::STATUS_QUEUED, CmsCommImport::STATUS_RUNNING], true);
$step = $isDraft ? ($preview ? 3 : 2) : 4;
$statusLabels = CmsCommImportRow::statuses();
$badge = static fn(string $status) => Html::tag('span', Html::encode($statusLabels[$status] ?? $status), ['class' => 'sx-import__badge sx-import__badge--'.$status]);

$recordRoutes = [
    CrmRecords::LEAD => '/cms/admin-cms-lead/view',
    CrmRecords::COMPANY => '/cms/admin-cms-company/view',
    CrmRecords::CLIENT => '/cms/admin-user/view',
];
$recordLink = static function (string $type, int $id) use ($user, $recordRoutes): string {
    $title = (CrmRecords::type($type)['title'] ?? 'Запись').' №'.$id;
    if (!isset($recordRoutes[$type]) || !CrmRecords::isVisible(CrmRecords::codeOf($type), $id, $user)) {
        return Html::encode($title);
    }

    return Html::a(Html::encode($title), Url::to([$recordRoutes[$type], 'pk' => $id]), ['target' => '_blank', 'data-pjax' => 0]);
};
$who = static function (array $data): string {
    $parts = array_filter([$data['company'], $data['name']], static fn($v) => $v !== '');
    $contacts = array_merge($data['emails'], array_map([SendContacts::class, 'formatPhone'], $data['phones']));

    return Html::tag('div', Html::encode($parts ? implode(' — ', $parts) : '—'), ['class' => 'sx-import__who'])
        .Html::tag('div', Html::encode(implode(', ', $contacts)), ['class' => 'sx-import__muted']);
};
?>
<div class="sx-import">
    <ol class="sx-import__steps">
        <?php foreach (['Загрузить таблицу', 'Колонки и настройки', 'Предпросмотр с повторами', 'Импорт'] as $i => $title) : ?>
            <li class="<?= $i + 1 < $step ? 'is-done' : ($i + 1 === $step ? 'is-current' : '') ?>"><?= Html::encode($title) ?></li>
        <?php endforeach; ?>
    </ol>

    <div class="sx-import__meta">
        <span><?= Html::encode($import->file_name ?: 'Вставленная таблица') ?></span>
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
                <p class="sx-import__muted">Что лежит в каждой колонке. Email и телефон могут быть в нескольких колонках; лишние колонки можно не
                    импортировать — их значения всё равно попадут в описание сделки.</p>
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
                            <?= Html::dropDownList('mapping['.$i.']', $mapping[$i] ?? '', ['' => 'Не импортировать'] + LeadImport::fields(), ['class' => 'form-control']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sx-import__card">
                <h2 class="sx-import__title">Настройки для всего файла</h2>
                <div class="sx-import__settings">
                    <label>
                        <span class="sx-import__label">Ответственный за новые сделки</span>
                        <?= Html::dropDownList('executor_id', $import->executor_id, $executors, ['class' => 'form-control', 'prompt' => 'Никому — в «Неразобранное»']) ?>
                        <?php if (!empty($errors['executor_id'])) : ?><span class="sx-import__error"><?= Html::encode($errors['executor_id']) ?></span><?php endif; ?>
                    </label>
                    <label>
                        <span class="sx-import__label">Тема рассылки</span>
                        <?= Html::dropDownList('cms_comm_topic_id', $import->cms_comm_topic_id, ['' => 'Без темы — только создать сделки'] + \yii\helpers\ArrayHelper::map($topics, 'id', 'name'), ['class' => 'form-control']) ?>
                    </label>
                    <label>
                        <span class="sx-import__label">Основание согласия</span>
                        <?= Html::dropDownList('consent_basis', $import->consent_basis ?: CmsCommContactTopic::BASIS_COLD, CmsCommContactTopic::bases(), ['class' => 'form-control']) ?>
                    </label>
                    <?php if (\skeeks\cms\communication\helpers\ListUi::canUse()) : ?>
                        <label>
                            <span class="sx-import__label">Список рассылки</span>
                            <?= Html::dropDownList('cms_comm_list_id', $import->listChoice ?? (string)$import->cms_comm_list_id,
                                ['' => 'Не добавлять в список', 'new' => '+ Новый список «Импорт №'.(int)$import->id.'»']
                                + \skeeks\cms\communication\models\CmsCommList::forSite((int)$import->cms_site_id), ['class' => 'form-control']) ?>
                        </label>
                    <?php endif; ?>
                </div>
                <p class="sx-import__muted">Тема и основание отмечаются у каждого email и телефона файла — и у новых сделок, и у найденных повторов.
                    Отписку клиента импорт не снимает, а настоящее согласие не превращается в «холодный контакт». «Холодным» контактам можно
                    писать только личные письма менеджера; через сервис рассылок — только тем, кто дал согласие.</p>
            </div>

            <?php if ($preview) :
                $counts = $preview['counts'];
                ?>
                <div class="sx-import__card">
                    <h2 class="sx-import__title">Предпросмотр</h2>
                    <div class="sx-import__counts">
                        <span><?= $badge(CmsCommImportRow::STATUS_CREATED) ?> <?= (int)$counts[CmsCommImportRow::STATUS_CREATED] ?></span>
                        <span><?= $badge(CmsCommImportRow::STATUS_DUPLICATE) ?> <?= (int)$counts[CmsCommImportRow::STATUS_DUPLICATE] ?></span>
                        <span><?= $badge(CmsCommImportRow::STATUS_SKIPPED) ?> <?= (int)$counts[CmsCommImportRow::STATUS_SKIPPED] ?></span>
                    </div>
                    <div class="sx-import__table-wrap">
                        <table class="sx-import__table">
                            <thead><tr><th>Строка</th><th>Получатель</th><th>Итог</th></tr></thead>
                            <tbody>
                            <?php foreach ($preview['rows'] as $outcome) : ?>
                                <tr>
                                    <td><?= (int)$outcome['row']->row_num ?></td>
                                    <td><?= $who($outcome['data']) ?></td>
                                    <td><?= $badge($outcome['status']) ?>
                                        <?php if ($outcome['match'] && $outcome['sameFile'] === null) : ?>
                                            <div class="sx-import__muted"><?= $recordLink($outcome['match']['type'], (int)$outcome['match']['id']) ?>,
                                                <?= Html::encode(LeadImport::matchText($outcome['match'])) ?></div>
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
                <?php if ($preview) : ?>
                    <button type="submit" name="start" value="1" class="btn btn-primary">Импортировать: создать сделок — <?= (int)$preview['counts'][CmsCommImportRow::STATUS_CREATED] ?></button>
                    <?php if ($import->row_count > LeadImport::SYNC_LIMIT) : ?>
                        <span class="sx-import__muted">Большой файл: импорт пойдёт в фоне, по окончании придёт уведомление.</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </form>
    <?php else : ?>
        <?php if ($isRunning) :
            $done = (int)$import->created_count + (int)$import->duplicate_count + (int)$import->skipped_count;
            $this->registerJs('setTimeout(function () { window.location.reload(); }, 4000);');
            ?>
            <div class="sx-import__notice sx-import__notice--info" role="status">
                Импорт идёт в фоне: обработано <?= $done ?> из <?= (int)$import->row_count ?> строк. Страница обновится сама; по окончании придёт уведомление.
                <?php if ($import->cms_job_run_id) : ?>
                    <?= Html::a('Фоновая задача', Url::to(['/cmsJob/admin-cms-job-run/view', 'pk' => (int)$import->cms_job_run_id]), ['data-pjax' => 0, 'target' => '_blank']) ?>
                <?php endif; ?>
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
                <span>Ответственный: <strong><?= Html::encode($import->executor ? $import->executor->shortDisplayName : '—') ?></strong></span>
                <span>Тема: <strong><?= Html::encode($import->topic ? $import->topic->name : 'без темы') ?></strong></span>
                <?php if ($import->topic) : ?>
                    <span>Основание: <strong><?= Html::encode(CmsCommContactTopic::bases()[$import->consent_basis] ?? '—') ?></strong></span>
                <?php endif; ?>
                <?php if ($import->list) : ?>
                    <span>Список: <strong><?= Html::encode($import->list->name) ?></strong></span>
                <?php endif; ?>
            </div>
            <div class="sx-import__counts">
                <?= Html::a('Все строки: '.(int)$import->row_count, Url::current(['status' => null, 'page' => null]), ['class' => 'sx-import__filter'.($statusFilter === '' ? ' is-active' : ''), 'data-pjax' => 0]) ?>
                <?php foreach ([CmsCommImportRow::STATUS_CREATED => 'created_count', CmsCommImportRow::STATUS_DUPLICATE => 'duplicate_count',
                                   CmsCommImportRow::STATUS_SKIPPED => 'skipped_count'] as $s => $attribute) : ?>
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
                                <?php if ($row->status) : ?>
                                    <?= $badge((string)$row->status) ?>
                                <?php else : ?>
                                    <span class="sx-import__muted">ещё не обработана</span>
                                <?php endif; ?>
                                <?php if ($row->cms_lead_id) : ?>
                                    <div><?= $recordLink(CrmRecords::LEAD, (int)$row->cms_lead_id) ?></div>
                                <?php elseif ($row->match_code && $row->match_id && ($type = CrmRecords::typeOfCode((string)$row->match_code))) : ?>
                                    <div class="sx-import__muted"><?= $recordLink($type, (int)$row->match_id) ?><?= $row->reason ? ', '.Html::encode($row->reason) : '' ?></div>
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
