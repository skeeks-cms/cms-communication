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
 */

use skeeks\cms\communication\assets\MailClientAsset;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\widgets\Pjax;
use yii\helpers\Html;
use yii\helpers\Url;

MailClientAsset::register($this);

$listParams = ['mail', 'folder' => $folder, 'q' => $search !== '' ? $search : null, 'unread' => $unreadOnly ? 1 : null];
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
                    .Html::tag('span', $f['unread'] ?: '', ['class' => 'sx-mail__count', 'title' => 'Непрочитанные', 'data-sx-mail-count' => $f['key']]),
                    Url::to(['mail', 'folder' => $f['key']]),
                    ['class' => 'sx-mail__folder'.($f['key'] === $folder ? ' is-active' : ''), 'data-folder' => $f['key']]
                ) ?>
            <?php endforeach; ?>
        </nav>
        <button type="button" class="sx-mail__hotkeys-link" data-sx-mail-hotkeys>Горячие клавиши</button>
    </aside>

    <section class="sx-mail__panel sx-mail__list">
        <div class="sx-mail__head">
            <form class="sx-mail__search" method="get" action="<?= Html::encode(Url::to(['mail'])) ?>" data-pjax="1">
                <input type="hidden" name="folder" value="<?= Html::encode($folder) ?>">
                <span class="sx-mail__search-icon"><?= MailUi::icon('search', 18) ?></span>
                <input type="search" name="q" value="<?= Html::encode($search) ?>" placeholder="Поиск в папке «<?= Html::encode($title) ?>»" aria-label="Поиск писем" data-sx-mail-search>
            </form>

            <div class="sx-mail__toolbar">
                <label class="sx-mail__check" title="Выбрать все показанные">
                    <input type="checkbox" data-sx-mail-all>
                </label>
                <button type="button" class="sx-mail__tool" data-sx-mail-mark="1" disabled title="Shift + i"><?= MailUi::icon('mail-open', 17) ?> <span class="sx-mail__tool-text">Прочитано</span></button>
                <button type="button" class="sx-mail__tool" data-sx-mail-mark="0" disabled title="Shift + u"><?= MailUi::icon('mail', 17) ?> <span class="sx-mail__tool-text">Не прочитано</span></button>
                <?= Html::a(Html::tag('span', '', ['class' => 'sx-mail__filter-dot']).' Непрочитанные',
                    Url::to(array_merge($listParams, ['unread' => $unreadOnly ? null : 1])),
                    ['class' => 'sx-mail__tool sx-mail__filter'.($unreadOnly ? ' is-active' : ''), 'title' => $unreadOnly ? 'Показать все письма' : 'Показать только непрочитанные']) ?>
                <span class="sx-mail__toolbar-title"><?= Html::encode($title) ?><?= $search !== '' ? ' · поиск «'.Html::encode($search).'»' : '' ?></span>
            </div>
        </div>

        <?php if (!$messages) : ?>
            <div class="sx-mail__empty"><?= $search !== '' ? 'Ничего не найдено.' : ($unreadOnly ? 'Непрочитанных писем нет.' : 'Писем нет.') ?></div>
        <?php endif; ?>

        <div class="sx-mail__rows" data-sx-mail-rows>
            <?= $this->render('_rows', ['messages' => $messages, 'readIds' => $readIds]) ?>
        </div>

        <div class="sx-mail__more" data-sx-mail-more-wrap <?= $hasMore ? '' : 'hidden' ?>>
            <button type="button" class="sx-mail__more-button" data-sx-mail-more>Показать ещё</button>
        </div>
    </section>

    <div class="sx-mail__selection" data-sx-mail-selection hidden>
        <span data-sx-mail-selected></span>
        <button type="button" class="sx-mail__selection-action" data-sx-mail-mark="1">Прочитано</button>
        <button type="button" class="sx-mail__selection-action" data-sx-mail-mark="0">Не прочитано</button>
        <button type="button" class="sx-mail__selection-close" data-sx-mail-clear aria-label="Снять выбор" title="Снять выбор">×</button>
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
