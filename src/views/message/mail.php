<?php
/**
 * "Почта": folders on the left, the letters of the folder as a wide list.
 * Folders, search and toolbar stay in place while the list scrolls; more
 * letters load with "Показать ещё"; a letter opens in the backend panel on
 * the right.
 *
 * @var $this yii\web\View
 * @var $folders array[]
 * @var $folder string
 * @var $search string
 * @var $unreadOnly bool
 * @var $messages skeeks\cms\communication\models\CmsCommMessage[]
 * @var $readIds int[]
 * @var $hasMore bool
 * @var $canSend bool
 * @var $drafts skeeks\cms\communication\models\CmsCommDraft[]|null the "Черновики" folder
 * @var $assignee string filter: "", "me", "none" or an employee id
 * @var $linked string filter: "", "yes" (linked to the CRM), "no" (not sorted)
 * @var $assignees array id => name: other employees responsible for visible conversations
 * @var $total int letters of the folder with these filters ("Выбрать все письма папки")
 * @var $canManage bool the employee manages letters of some mailbox (move, labels)
 * @var $labels skeeks\cms\communication\models\CmsCommLabel[]
 * @var $label int filter: letters with this label
 */

use skeeks\cms\communication\assets\MailClientAsset;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\MailFolders;
use skeeks\cms\widgets\Pjax;
use yii\helpers\Html;
use yii\helpers\Url;

MailClientAsset::register($this);

$showAssignee = (bool)\Yii::$app->communication->showAssignee;
$assignee = $assignee ?? '';
$linked = $linked ?? '';
$assignees = $assignees ?? [];
$labels = $labels ?? [];
$label = (int)($label ?? 0);
$canManage = !empty($canManage);
$total = (int)($total ?? 0);
$listParams = ['mail', 'folder' => $folder, 'q' => $search !== '' ? $search : null, 'unread' => $unreadOnly ? 1 : null,
    'assignee' => $assignee !== '' ? $assignee : null, 'linked' => $linked !== '' ? $linked : null, 'label' => $label ?: null];
// The list filters again, for actions on "all letters of the folder".
$listFilter = array_filter(array_slice($listParams, 1, null, true), static fn($v) => $v !== null);
// Trash and spam of a mailbox offer the way back instead of moving there again.
$folderKind = (string)strtok($folder, ':');
$labelItems = ['' => 'Метка: все'];
foreach ($labels as $l) {
    $labelItems[(string)$l->id] = 'Метка: '.$l->name;
}
$drafts = $drafts ?? null;
$assigneeItems = ['' => 'Ответственный: все', MailFolders::ASSIGNEE_ME => 'Ответственный: я', MailFolders::ASSIGNEE_NONE => 'Без ответственного'];
foreach ($assignees as $id => $name) {
    $assigneeItems[(string)$id] = 'Ответственный: '.$name;
}
$linkedItems = ['' => 'Привязка: все', MailFolders::LINKED_YES => 'Привязано к CRM', MailFolders::LINKED_NO => 'Не разобрано'];
// Row menu ("Создать…", "Привязать"): URLs of a letter with its id put in by mail.js.
$rowMenu = [
    'link' => MailUi::linkUrl('__ID__'),
    'assign' => Url::to(['assign', 'pk' => '__ID__']),
    'create' => MailUi::createActions('__ID__'),
    'me' => (int)\Yii::$app->user->id,
];
$personalUrl = (string)\skeeks\cms\backend\helpers\BackendUrlHelper::createByParams(['personal'])->enableEmptyLayout()->enableNoActions()->url;
$title = 'Все письма';
$totalUnread = 0;
foreach ($folders as $f) {
    if ($f['key'] === $folder) {
        $title = ($f['group'] ? $f['group'].' — ' : '').$f['title'];
    }
    if ($f['key'] === \skeeks\cms\communication\services\MailFolders::ALL) {
        $totalUnread = (int)$f['unread'];
    }
}
// Actions in the mailbox: the toolbar, the selection bar and the row menu share them.
$moveActions = [];
if ($canManage) {
    $moveActions[] = $folderKind === 'trash'
        ? ['restore', 'undo', 'Восстановить', 'Вернуть во «Входящие» (или «Отправленные»)']
        : ['trash', 'trash', 'Удалить', 'В корзину почтового ящика (Delete)'];
    $moveActions[] = $folderKind === 'archive'
        ? ['restore', 'inbox', 'Во входящие', 'Вернуть во «Входящие» (или «Отправленные»)']
        : ['archive', 'archive', 'В архив', 'В архив почтового ящика (e)'];
    $moveActions[] = $folderKind === 'junk'
        ? ['restore', 'inbox', 'Не спам', 'Не спам: вернуть во «Входящие» (!)']
        : ['junk', 'spam', 'Это спам', 'В спам: почтовый сервис учится на этом (!)'];
}
$moveButton = static function (array $a, string $class, bool $disabled = true): string {
    return Html::button(MailUi::icon($a[1], 17).' '.Html::tag('span', Html::encode($a[2]), ['class' => 'sx-mail__tool-text']), [
        'class' => $class, 'data-sx-mail-move' => $a[0], 'title' => $a[3], 'disabled' => $disabled,
    ]);
};
$hotkeys = [
    '↑ ↓ или j k' => 'Предыдущее / следующее письмо',
    'Enter или o' => 'Открыть письмо',
    'x' => 'Отметить письмо',
    'Shift + щелчок' => 'Отметить несколько подряд',
    'Shift + i / Shift + u' => 'Прочитано / не прочитано',
    'c' => 'Написать письмо',
    '/' => 'Поиск',
    'r' => 'Ответить (в открытом письме)',
    'a' => 'Ответить всем (в открытом письме)',
    'f' => 'Переслать (в открытом письме)',
    'l' => 'Привязать к лиду, компании, клиенту или сделке (в открытом письме)',
    'Delete или #' => 'Удалить письмо',
    'e' => 'В архив',
    '!' => 'Это спам',
    'v' => 'В папку',
    'm' => 'Метки',
    'Правая кнопка мыши' => 'Меню письма: удалить, в архив, в папку, метки, привязать, создать задачу, лид или сделку',
    'Ctrl + Enter' => 'Отправить письмо',
    'Esc' => 'Закрыть письмо',
    '?' => 'Эта подсказка',
];
?>
<?php Pjax::begin(['id' => 'sx-mail-pjax', 'timeout' => 15000]); ?>
<div class="sx-mail" data-sx-mail
     data-mark-url="<?= Html::encode(Url::to(['mark'])) ?>"
     data-refresh-url="<?= Html::encode(Url::to(['refresh'])) ?>"
     data-list-url="<?= Html::encode(Url::to($listParams)) ?>"
     data-draft-delete-url="<?= Html::encode(Url::to(['draft-delete'])) ?>"
     data-move-url="<?= Html::encode(Url::to(['move'])) ?>"
     data-server-folders-url="<?= Html::encode(Url::to(['server-folders'])) ?>"
     data-labels-url="<?= Html::encode(Url::to(['labels'])) ?>"
     data-label-save-url="<?= Html::encode(Url::to(['label-save'])) ?>"
     data-list-filter="<?= Html::encode(\yii\helpers\Json::encode((object)$listFilter)) ?>"
     data-total="<?= $total ?>"
     data-folder-kind="<?= Html::encode($folderKind) ?>"
     data-can-manage="<?= $canManage ? 1 : 0 ?>"
     data-unread-total="<?= $totalUnread ?>">
    <aside class="sx-mail__panel sx-mail__folders">
        <div class="sx-mail__actions">
            <?php if ($canSend) : ?>
                <?= Html::a(MailUi::icon('send', 16).' Написать', '#', ['class' => 'btn btn-primary sx-mail__compose', 'data-pjax' => '0',
                    'data-sx-mail-compose' => true, 'title' => 'Написать письмо (c)', 'onclick' => MailUi::composeOnClick()]) ?>
            <?php endif; ?>
            <button type="button" class="sx-mail__refresh" data-sx-mail-refresh title="Проверить новые письма" aria-label="Проверить новые письма">
                <?= MailUi::icon('refresh', 18) ?>
            </button>
        </div>
        <nav class="sx-mail__folder-list">
            <?php $group = false; ?>
            <?php foreach ($folders as $f) : ?>
                <?php if ($f['group'] !== $group) : $group = $f['group']; ?>
                    <?php if ($group !== null) : ?>
                        <div class="sx-mail__group" title="<?= Html::encode($group) ?>"><?= Html::encode($group) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?= Html::a(
                    Html::tag('span', MailUi::icon($f['icon'], 18), ['class' => 'sx-mail__folder-icon'])
                    .Html::tag('span', Html::encode($f['title']), ['class' => 'sx-mail__folder-title'])
                    .Html::tag('span', $f['unread'] ?: '', ['class' => 'sx-mail__count', 'title' => $f['key'] === \skeeks\cms\communication\services\MailFolders::DRAFTS ? 'Черновики' : 'Непрочитанные', 'data-sx-mail-count' => $f['key']]),
                    Url::to(['mail', 'folder' => $f['key']]),
                    ['class' => 'sx-mail__folder'.($f['key'] === $folder ? ' is-active' : ''), 'data-folder' => $f['key']]
                ) ?>
            <?php endforeach; ?>
        </nav>
        <div class="sx-mail__links">
            <a href="#" class="sx-mail__personal-link" data-pjax="0" title="Уведомления, подписи и шаблоны ответов"
               onclick="<?= Html::encode('new sx.classes.backend.widgets.Action('.\yii\helpers\Json::encode(['isOpenNewWindow' => true, 'url' => $personalUrl]).').go(); return false;') ?>">Мои настройки</a>
            <button type="button" class="sx-mail__hotkeys-link" data-sx-mail-hotkeys>Горячие клавиши</button>
        </div>
    </aside>

    <section class="sx-mail__panel sx-mail__list">
        <div class="sx-mail__head">
            <form class="sx-mail__search" method="get" action="<?= Html::encode(Url::to(['mail'])) ?>" data-pjax="1">
                <input type="hidden" name="folder" value="<?= Html::encode($folder) ?>">
                <span class="sx-mail__search-icon"><?= MailUi::icon('search', 18) ?></span>
                <input type="search" name="q" value="<?= Html::encode($search) ?>" placeholder="Поиск в папке «<?= Html::encode($title) ?>»" aria-label="Поиск писем" data-sx-mail-search>
            </form>

            <div class="sx-mail__toolbar">
                <?php if ($drafts !== null) : ?>
                <span class="sx-mail__toolbar-title"><?= Html::encode($title) ?><?= $search !== '' ? ' · поиск «'.Html::encode($search).'»' : '' ?></span>
                <?php else : ?>
                <label class="sx-mail__check" title="Выбрать все показанные">
                    <input type="checkbox" data-sx-mail-all>
                </label>
                <button type="button" class="sx-mail__tool" data-sx-mail-mark="1" disabled title="Shift + i"><?= MailUi::icon('mail-open', 17) ?> <span class="sx-mail__tool-text">Прочитано</span></button>
                <button type="button" class="sx-mail__tool" data-sx-mail-mark="0" disabled title="Shift + u"><?= MailUi::icon('mail', 17) ?> <span class="sx-mail__tool-text">Не прочитано</span></button>
                <?php if ($moveActions) : ?>
                    <span class="sx-mail__tool-sep"></span>
                    <?php foreach ($moveActions as $a) : ?>
                        <?= $moveButton($a, 'sx-mail__tool sx-mail__tool--icon') ?>
                    <?php endforeach; ?>
                    <button type="button" class="sx-mail__tool sx-mail__tool--icon" data-sx-mail-folder-menu disabled title="В папку почтового ящика (v)"><?= MailUi::icon('folder', 17) ?> <span class="sx-mail__tool-text">В папку</span></button>
                    <button type="button" class="sx-mail__tool sx-mail__tool--icon" data-sx-mail-labels-menu disabled title="Метки (m)"><?= MailUi::icon('tag', 17) ?> <span class="sx-mail__tool-text">Метки</span></button>
                    <span class="sx-mail__tool-sep"></span>
                <?php endif; ?>
                <?= Html::a(Html::tag('span', '', ['class' => 'sx-mail__filter-dot']).' Непрочитанные',
                    Url::to(array_merge($listParams, ['unread' => $unreadOnly ? null : 1])),
                    ['class' => 'sx-mail__tool sx-mail__filter'.($unreadOnly ? ' is-active' : ''), 'title' => $unreadOnly ? 'Показать все письма' : 'Показать только непрочитанные']) ?>
                <?php // Filters keep the folder, search and each other: the option value is the URL to open. ?>
                <?php
                // "Ответственный" and "Привязка" are hidden unless the site switches them on
                // (CommunicationComponent); a filter already in the address stays visible to be cleared.
                $filters = [];
                if ($showAssignee || $assignee !== '') {
                    $filters['assignee'] = [$assigneeItems, $assignee, 'Ответственный за переписку'];
                }
                if (\Yii::$app->communication->showLinkedFilter || $linked !== '') {
                    $filters['linked'] = [$linkedItems, $linked, 'Привязка к лидам, компаниям, клиентам и сделкам'];
                }
                ?>
                <?php if ($labels) { $filters['label'] = [$labelItems, $label ? (string)$label : '', 'Письма с меткой']; } ?>
                <?php foreach ($filters as $param => [$items, $current, $hint]) : ?>
                    <?php if (!isset($items[$current])) { $items[$current] = $current; } ?>
                    <select class="sx-mail__select<?= $current !== '' ? ' is-active' : '' ?>" data-sx-mail-filter title="<?= Html::encode($hint) ?>" aria-label="<?= Html::encode($hint) ?>">
                        <?php foreach ($items as $value => $label) : ?>
                            <option value="<?= Html::encode(Url::to(array_merge($listParams, [$param => $value !== '' ? $value : null]))) ?>" <?= (string)$value === (string)$current ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endforeach; ?>
                <span class="sx-mail__toolbar-title"><?= Html::encode($title) ?><?= $search !== '' ? ' · поиск «'.Html::encode($search).'»' : '' ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($drafts !== null) : ?>
            <?php if (!$drafts) : ?>
                <div class="sx-mail__empty"><?= $search !== '' ? 'Ничего не найдено.' : 'Черновиков нет. Начатое письмо сохраняется здесь само, пока вы его пишете.' ?></div>
            <?php endif; ?>
            <div class="sx-mail__rows" data-sx-mail-rows>
                <?= $this->render('_drafts', ['drafts' => $drafts]) ?>
            </div>
        <?php else : ?>
        <?php if (!$messages) : ?>
            <div class="sx-mail__empty"><?= $search !== '' ? 'Ничего не найдено.' : ($unreadOnly ? 'Непрочитанных писем нет.' : 'Писем нет.') ?></div>
        <?php endif; ?>

        <div class="sx-mail__rows" data-sx-mail-rows>
            <?= $this->render('_rows', ['messages' => $messages, 'readIds' => $readIds]) ?>
        </div>
        <?php endif; ?>

        <div class="sx-mail__more" data-sx-mail-more-wrap <?= $hasMore ? '' : 'hidden' ?>>
            <button type="button" class="sx-mail__more-button" data-sx-mail-more>Показать ещё</button>
        </div>
    </section>

    <div class="sx-mail__selection" data-sx-mail-selection hidden>
        <span class="sx-mail__selection-text">
            <span data-sx-mail-selected></span>
            <?php // All loaded rows are chosen, the folder has more: choose every letter of it. ?>
            <button type="button" class="sx-mail__selection-all" data-sx-mail-select-folder hidden></button>
        </span>
        <span class="sx-mail__selection-actions">
            <button type="button" class="sx-mail__selection-action" data-sx-mail-mark="1">Прочитано</button>
            <button type="button" class="sx-mail__selection-action" data-sx-mail-mark="0">Не прочитано</button>
            <?php foreach ($moveActions as $a) : ?>
                <?= $moveButton($a, 'sx-mail__selection-action', false) ?>
            <?php endforeach; ?>
            <?php if ($moveActions) : ?>
                <button type="button" class="sx-mail__selection-action" data-sx-mail-folder-menu title="В папку почтового ящика"><?= MailUi::icon('folder', 17) ?> <span class="sx-mail__tool-text">В папку</span></button>
                <button type="button" class="sx-mail__selection-action" data-sx-mail-labels-menu title="Метки"><?= MailUi::icon('tag', 17) ?> <span class="sx-mail__tool-text">Метки</span></button>
            <?php endif; ?>
        </span>
        <button type="button" class="sx-mail__selection-close" data-sx-mail-clear aria-label="Снять выбор" title="Снять выбор">×</button>
    </div>

    <?php // "В папку" and "Метки": filled by mail.js for the chosen letters. ?>
    <div class="sx-mail__menu sx-mail__floating" data-sx-mail-floating hidden role="menu"></div>

    <?php // The row menu (right click or "⋯" of a row); mail.js puts the letter id into the URLs. ?>
    <div class="sx-mail__menu sx-mail__context" data-sx-mail-context hidden role="menu"
         data-config="<?= Html::encode(\yii\helpers\Json::encode($rowMenu)) ?>">
        <button type="button" class="sx-mail__menu-item" data-sx-mail-context-action="open"><?= MailUi::icon('mail-open', 16) ?> Открыть</button>
        <button type="button" class="sx-mail__menu-item" data-sx-mail-context-action="read"><?= MailUi::icon('mail-open', 16) ?> Прочитано</button>
        <button type="button" class="sx-mail__menu-item" data-sx-mail-context-action="unread"><?= MailUi::icon('mail', 16) ?> Не прочитано</button>
        <?php if ($moveActions) : ?>
            <span class="sx-mail__menu-sep"></span>
            <?php foreach ($moveActions as $a) : ?>
                <button type="button" class="sx-mail__menu-item" data-sx-mail-context-action="move" data-move="<?= Html::encode($a[0]) ?>"><?= MailUi::icon($a[1], 16) ?> <?= Html::encode($a[2]) ?></button>
            <?php endforeach; ?>
            <button type="button" class="sx-mail__menu-item" data-sx-mail-context-action="folder"><?= MailUi::icon('folder', 16) ?> В папку…</button>
            <button type="button" class="sx-mail__menu-item" data-sx-mail-context-action="labels"><?= MailUi::icon('tag', 16) ?> Метки…</button>
        <?php endif; ?>
        <span class="sx-mail__menu-sep"></span>
        <button type="button" class="sx-mail__menu-item" data-sx-mail-context-action="link"><?= MailUi::icon('link', 16) ?> Привязать…</button>
        <?php foreach ($rowMenu['create'] as $type => $action) : ?>
            <button type="button" class="sx-mail__menu-item" data-sx-mail-context-action="create" data-type="<?= Html::encode($type) ?>"><?= MailUi::icon($action['icon'], 16) ?> <?= Html::encode($action['title']) ?>…</button>
        <?php endforeach; ?>
        <?php if ($showAssignee) : ?>
            <span class="sx-mail__menu-sep"></span>
            <button type="button" class="sx-mail__menu-item" data-sx-mail-context-action="assign-me"><?= MailUi::icon('user', 16) ?> Назначить меня ответственным</button>
        <?php endif; ?>
    </div>

    <div class="sx-mail__dialog" data-sx-mail-hotkeys-dialog hidden role="dialog" aria-label="Горячие клавиши">
        <div class="sx-mail__dialog-box">
            <div class="sx-mail__dialog-head">
                <strong>Горячие клавиши</strong>
                <button type="button" class="sx-mail__selection-close" data-sx-mail-hotkeys-close aria-label="Закрыть">×</button>
            </div>
            <table class="sx-mail__hotkeys">
                <?php foreach ($hotkeys as $keys => $what) : ?>
                    <tr><td><kbd><?= Html::encode($keys) ?></kbd></td><td><?= Html::encode($what) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <p class="sx-mail__dialog-note">Клавиши работают при любой раскладке.</p>
        </div>
    </div>
</div>
<?php Pjax::end(); ?>
