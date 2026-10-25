<?php
/**
 * The open chat as in messengers: the header (picture, name, members, the
 * dialog bar), linked CRM records, messages, the reply form; the side panel
 * with the members.
 *
 * @var $this yii\web\View
 * @var $conversation skeeks\cms\communication\models\CmsCommConversation
 * @var $messages skeeks\cms\communication\models\CmsCommMessage[]
 * @var $more bool older messages exist
 * @var $canSend bool
 * @var $bar array parameters of _bar
 */

use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommConversation;
use yii\helpers\Html;
use yii\helpers\Url;

// Polling asks for messages written after this one: the largest id of the chat, not the last shown (an imported history is not numbered in time order).
$last = (int)\skeeks\cms\communication\models\CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->max('id');
$config = [
    'id' => (int)$conversation->id,
    'last' => $last,
    'messagesUrl' => Url::to(['messages', 'pk' => (int)$conversation->id]),
    'sendUrl' => Url::to(['send', 'pk' => (int)$conversation->id]),
    'dialogUrl' => Url::to(['dialog', 'pk' => (int)$conversation->id]),
    'unlinkUrl' => Url::to(['unlink', 'pk' => (int)$conversation->id]),
    'linkWindow' => (string)\skeeks\cms\backend\helpers\BackendUrlHelper::createByParams(['link', 'pk' => (int)$conversation->id])->enableEmptyLayout()->url,
    'personWindow' => (string)\skeeks\cms\backend\helpers\BackendUrlHelper::createByParams(['link', 'pk' => (int)$conversation->id, 'person' => 1])->enableEmptyLayout()->url,
    // "Создать задачу" from a message: the standard create window with ?source=message:{id}.
    'taskWindow' => \skeeks\cms\communication\helpers\MailUi::createActions('{id}')['task']['url'] ?? null,
    // Reactions the bot may put from the CRM; empty — the messenger gives bots none (MAX).
    'reactions' => $canSend && $conversation->connection && ($driver = $conversation->connection->createDriver(false)) instanceof \skeeks\cms\communication\bot\MessengerDriver
        ? $driver->reactionChoices() : [],
    'channelName' => ChatUi::channelName($conversation),
    // "Ссылка на сообщение": the chat in "Чаты" scrolled to the message.
    'messageLink' => Url::to(['chats', 'folder' => 'all', 'id' => (int)$conversation->id, 'msg' => '{msg}'], true),
    // A message to show when the chat opens (a link to a message).
    'focus' => (int)\Yii::$app->request->get('msg') ?: null,
    'csrfParam' => \Yii::$app->request->csrfParam,
    'csrf' => \Yii::$app->request->getCsrfToken(),
];
$participants = $conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE
    ? $conversation->getParticipants()->andWhere(['left_at' => null])->count() : 0;
?>
<div class="sx-chat" data-sx-chat-pane="<?= Html::encode(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <div class="sx-chat__column">
        <header class="sx-chat__head">
            <button type="button" class="sx-chat__back" data-sx-chat-back aria-label="К списку чатов">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="sx-chat__title" data-sx-chat-info-toggle title="Сведения о чате">
                <?= ChatUi::avatar($conversation) ?>
                <span class="sx-chat__title-text">
                    <span class="sx-chat__name"><?= Html::encode($conversation->asText()) ?></span>
                    <span class="sx-chat__sub">
                        <?= Html::encode(ChatUi::channelName($conversation)) ?> ·
                        <?= $participants ? Html::encode(ChatUi::members((int)$participants)) : Html::encode(CmsCommConversation::chatTypes()[$conversation->chat_type] ?? '') ?>
                        <?php if ($conversation->bot_status === CmsCommConversation::BOT_LEFT): ?> · <span class="sx-chat__warn">бота нет в чате</span><?php endif; ?>
                    </span>
                </span>
            </button>
            <div class="sx-chat__bar" data-sx-chat-bar>
                <?= $this->render('_bar', $bar) ?>
            </div>
            <button type="button" class="sx-chat__icon-btn" data-sx-chat-info-toggle title="Сведения о чате" aria-label="Сведения о чате">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 11v6M12 7.5v.1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        </header>
        <div class="sx-chat__links" data-sx-chat-links>
            <?= $linksHtml ?>
        </div>
        <div class="sx-chat__messages" data-sx-chat-messages>
            <?php if ($more): ?><button type="button" class="sx-chat__older" data-sx-chat-older>Показать более ранние сообщения</button><?php endif; ?>
            <?php if (!$messages): ?><div class="sx-chats__none">Сообщений пока нет</div><?php endif; ?>
            <?= $this->render('_messages', ['messages' => $messages, 'conversation' => $conversation]) ?>
        </div>
        <?php // Scrolled up: back to the newest, with the number of messages that came meanwhile. ?>
        <button type="button" class="sx-chat__down" data-sx-chat-down hidden title="К последним сообщениям" aria-label="К последним сообщениям">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="sx-chat__down-count" data-sx-chat-down-count></span>
        </button>
        <?php if ($canSend): ?>
            <form class="sx-chat__reply" data-sx-chat-reply enctype="multipart/form-data">
                <div class="sx-chat__replying" data-sx-chat-replying hidden>
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z" fill="currentColor"/></svg>
                    <span class="sx-chat__replying-body">
                        <b data-sx-chat-replying-author>Ответ</b>
                        <span data-sx-chat-replying-text></span>
                    </span>
                    <button type="button" class="sx-chat__icon-btn" data-sx-chat-replying-cancel aria-label="Не отвечать на сообщение">×</button>
                </div>
                <div class="sx-chat__files" data-sx-chat-files></div>
                <div class="sx-chat__compose">
                    <label class="sx-chat__icon-btn sx-chat__attach" title="Прикрепить файлы">
                        <input type="file" name="files[]" multiple data-sx-chat-file hidden>
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M20 11.5l-8.3 8.3a5 5 0 01-7.1-7.1l8.5-8.5a3.3 3.3 0 014.7 4.7l-8.5 8.5a1.7 1.7 0 01-2.4-2.4l7.9-7.9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="sx-visually-hidden">Прикрепить файлы</span>
                    </label>
                    <textarea name="text" rows="1" placeholder="Сообщение" title="Enter — отправить, Shift+Enter — новая строка" data-sx-chat-text></textarea>
                    <button type="submit" class="sx-chat__send" data-sx-chat-send title="Отправить (Enter)" aria-label="Отправить">
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M3.4 20.4l17.4-7.5a1 1 0 000-1.8L3.4 3.6a.9.9 0 00-1.3 1L4 11l9 1-9 1-1.9 6.4a.9.9 0 001.3 1z" fill="currentColor"/></svg>
                    </button>
                </div>
                <div class="sx-chat__error" data-sx-chat-error hidden></div>
            </form>
        <?php else: ?>
            <div class="sx-chat__readonly">Писать в этот чат вам нельзя: нужен доступ к боту на отправку.</div>
        <?php endif; ?>
    </div>
    <aside class="sx-chat__info" data-sx-chat-info aria-label="Сведения о чате">
        <div class="sx-chat__info-head">
            <span>Сведения</span>
            <button type="button" class="sx-chat__icon-btn" data-sx-chat-info-toggle aria-label="Закрыть сведения">×</button>
        </div>
        <div data-sx-chat-info-body><?= $infoHtml ?></div>
    </aside>
</div>
