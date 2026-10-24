<?php
/**
 * Rows of the chat list.
 *
 * @var $this yii\web\View
 * @var $conversations skeeks\cms\communication\models\CmsCommConversation[]
 * @var $last skeeks\cms\communication\models\CmsCommMessage[] conversation id => last message
 * @var $unread int[] conversation id => unread incoming messages
 * @var $currentId int
 * @var $search string
 * @var $folder string
 */

use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommConversation;
use yii\helpers\Html;
use yii\helpers\Url;

if (!$conversations): ?>
    <div class="sx-chats__none"><?= $search !== '' ? 'Ничего не найдено' : 'Здесь пока пусто' ?></div>
<?php return; endif;

foreach ($conversations as $c):
    $m = $last[(int)$c->id] ?? null;
    $count = (int)($unread[(int)$c->id] ?? 0);
    $classes = ['sx-chat-row'];
    if ((int)$c->id === $currentId) {
        $classes[] = 'is-active';
    }
    if ($count) {
        $classes[] = 'is-unread';
    }
    ?>
    <a class="<?= implode(' ', $classes) ?>" data-sx-chat="<?= (int)$c->id ?>"
       data-sx-pane-url="<?= Html::encode(Url::to(['pane', 'pk' => (int)$c->id])) ?>"
       href="<?= Html::encode(Url::to(['chats', 'folder' => $folder, 'id' => (int)$c->id])) ?>"
       title="<?= Html::encode(ChatUi::channelName($c).' · '.(CmsCommConversation::chatTypes()[$c->chat_type] ?? '')) ?>">
        <?= ChatUi::avatar($c) ?>
        <span class="sx-chat-row__body">
            <span class="sx-chat-row__top">
                <span class="sx-chat-row__title"><?= Html::encode($c->asText()) ?></span>
                <span class="sx-chat-row__time"><?= $c->last_message_at ? Html::encode(ChatUi::shortTime((int)$c->last_message_at)) : '' ?></span>
            </span>
            <span class="sx-chat-row__bottom">
                <span class="sx-chat-row__snippet"><?php if ($m): ?><?= Html::encode(ChatUi::snippet($m, $c)) ?><?php endif; ?></span>
                <?php if ($count): ?><span class="sx-chat-row__badge"><?= $count ?></span><?php endif; ?>
            </span>
            <?php if (($c->waiting_since && !$c->closed_at) || $c->assignedUser): ?>
                <span class="sx-chat-row__meta">
                    <?php if ($c->waiting_since && !$c->closed_at): ?>
                        <span class="sx-chat-row__waiting" title="Клиент ждёт ответа">ждёт <?= Html::encode(ChatUi::waiting((int)$c->waiting_since)) ?></span>
                    <?php endif; ?>
                    <?php if ($c->assignedUser): ?>
                        <span class="sx-chat-row__assignee" title="Ведёт диалог"><?= Html::encode($c->assignedUser->shortDisplayName) ?></span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </span>
    </a>
<?php endforeach; ?>
