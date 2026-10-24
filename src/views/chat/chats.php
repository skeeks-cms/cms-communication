<?php
/**
 * "Чаты": folders and chats on the left, the open chat on the right. On a
 * phone the list and the chat take the whole width in turn.
 *
 * @var $this yii\web\View
 * @var $folders array[] key, title, group, unread
 * @var $folder string
 * @var $search string
 * @var $listHtml string
 * @var $paneHtml string
 * @var $hasBots bool the employee has access to some bot
 */

use skeeks\cms\backend\helpers\BackendIcon;
use skeeks\cms\communication\assets\ChatAsset;
use yii\helpers\Html;
use yii\helpers\Url;

ChatAsset::register($this);
$icons = [
    \skeeks\cms\communication\chat\ChatFolders::NEW => 'mail-in',
    \skeeks\cms\communication\chat\ChatFolders::MINE => 'user',
    \skeeks\cms\communication\chat\ChatFolders::GROUPS => 'users',
    \skeeks\cms\communication\chat\ChatFolders::ALL => 'chat',
    \skeeks\cms\communication\chat\ChatFolders::CLOSED => 'check-circle',
];

$this->title = 'Чаты';
$config = [
    'listUrl' => Url::to(['list']),
    'folder' => $folder,
    'search' => $search,
];
?>
<div class="sx-chats<?= $paneHtml !== '' ? ' sx-chats--open' : '' ?>" data-sx-chats="<?= Html::encode(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <nav class="sx-chats__folders" aria-label="Папки чатов">
        <?php // A column of folders as in messengers: an icon, the name under it, the unread chats on the icon. ?>
        <?php foreach ($folders as $f): ?>
            <?php $icon = $icons[$f['key']] ?? (str_starts_with($f['key'], 'bot:') ? 'bot' : 'chat'); ?>
            <a class="sx-chats__folder<?= $f['key'] === $folder ? ' is-active' : '' ?>" data-sx-folder="<?= Html::encode($f['key']) ?>"
               href="<?= Html::encode(Url::to(['chats', 'folder' => $f['key']])) ?>" title="<?= Html::encode(($f['group'] ? $f['group'].': ' : '').$f['title']) ?>">
                <span class="sx-chats__folder-icon">
                    <?= BackendIcon::render($icon) ?>
                    <span class="sx-chats__count" data-sx-folder-count="<?= Html::encode($f['key']) ?>"><?= $f['unread'] ?: '' ?></span>
                </span>
                <span class="sx-chats__folder-title"><?= Html::encode($f['title']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <aside class="sx-chats__side">
        <form class="sx-chats__search" method="get" action="<?= Html::encode(Url::to(['chats'])) ?>">
            <input type="hidden" name="folder" value="<?= Html::encode($folder) ?>">
            <input type="search" name="q" value="<?= Html::encode($search) ?>" placeholder="Поиск по чатам" aria-label="Поиск по чатам">
        </form>
        <div class="sx-chats__list" data-sx-chat-list>
            <?= $listHtml ?>
        </div>
        <?php if (!$hasBots): ?>
            <div class="sx-chats__hint">
                У вас нет доступа ни к одному боту. Администратор подключает ботов и выдаёт доступ в «Настройки → Боты мессенджеров».
            </div>
        <?php endif; ?>
    </aside>
    <section class="sx-chats__main" data-sx-chat-main>
        <?php if ($paneHtml !== ''): ?>
            <?= $paneHtml ?>
        <?php else: ?>
            <div class="sx-chats__empty">Выберите чат слева</div>
        <?php endif; ?>
    </section>
</div>
