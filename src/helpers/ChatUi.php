<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\helpers;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use yii\helpers\Html;

/**
 * Small pieces of the "Чаты" screen: avatars, times, authors, the message
 * text with links, statuses of outgoing messages.
 */
class ChatUi
{
    public static function initials(string $name): string
    {
        $words = preg_split('/[\s\/|]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $letters = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $letters .= mb_strtoupper(mb_substr(ltrim($word, '@#«"('), 0, 1));
        }

        return $letters !== '' ? $letters : '?';
    }

    /** A stable color of an avatar by name. */
    public static function hue(string $name): int
    {
        return (int)(crc32($name) % 360);
    }

    public static function channelName(CmsCommConversation $conversation): string
    {
        return Bots::channels()[(string)$conversation->channel] ?? (string)$conversation->channel;
    }

    /** Time in the list: today — hours, this year — day and month, else the date. */
    public static function shortTime(int $time): string
    {
        $f = \Yii::$app->formatter;
        if ($f->asDate($time, 'yyyy-MM-dd') === $f->asDate(time(), 'yyyy-MM-dd')) {
            return $f->asTime($time, 'HH:mm');
        }

        return $f->asDate($time, $f->asDate($time, 'yyyy') === $f->asDate(time(), 'yyyy') ? 'd MMM' : 'dd.MM.yyyy');
    }

    public static function time(int $time): string
    {
        if (!$time) {
            return '';
        }
        $f = \Yii::$app->formatter;

        return $f->asDate($time, 'yyyy-MM-dd') === $f->asDate(time(), 'yyyy-MM-dd') ? $f->asTime($time, 'HH:mm') : $f->asDatetime($time, 'd MMM, HH:mm');
    }

    /** "5 мин", "2 ч", "3 дн". */
    public static function waiting(int $since): string
    {
        $minutes = max(1, (int)floor((time() - $since) / 60));
        if ($minutes < 60) {
            return $minutes.' мин';
        }
        $hours = (int)floor($minutes / 60);

        return $hours < 48 ? $hours.' ч' : (int)floor($hours / 24).' дн';
    }

    public static function author(CmsCommMessage $message): string
    {
        if ($message->direction === CmsCommMessage::DIRECTION_OUT) {
            if ($message->sentBy) {
                return trim(trim((string)$message->sentBy->first_name).' '.trim((string)$message->sentBy->last_name)) ?: (string)$message->sentBy->shortDisplayName;
            }
            return 'Бот';
        }
        if ($message->identity) {
            return $message->identity->asText();
        }

        return (string)($message->from_name ?: 'Собеседник');
    }

    /** The last message in the list: "Вы: …" for ours, the author in groups. */
    public static function snippet(CmsCommMessage $message, CmsCommConversation $conversation): string
    {
        $text = $message->getSnippet(80);
        if ($message->sent_by_user_id) {
            // A reply from the CRM starts with the employee's signature: the list shows what they wrote.
            $text = mb_substr(trim(preg_replace('/\s+/u', ' ', self::body($message))), 0, 80);
        }
        if ($text === '' && $message->attachments) {
            $text = 'Файл';
        }
        if ($message->direction === CmsCommMessage::DIRECTION_OUT) {
            return 'Вы: '.$text;
        }
        if ($conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE) {
            return self::author($message).': '.$text;
        }

        return $text;
    }

    /** Escaped text with links and line breaks. */
    public static function text(string $text): string
    {
        $html = Html::encode($text);
        $html = preg_replace_callback('~https?://[^\s<>"\']+~u', static function ($m) {
            $url = html_entity_decode($m[0], ENT_QUOTES);
            $trail = '';
            if (preg_match('~[.,;:!?)]+$~', $url, $t)) {
                $trail = $t[0];
                $url = substr($url, 0, -strlen($trail));
            }

            return Html::a(Html::encode($url), $url, ['target' => '_blank', 'rel' => 'noopener noreferrer nofollow']).Html::encode($trail);
        }, $html);

        return nl2br((string)$html, false);
    }

    /**
     * The block "Мессенджеры" of a lead, company or client card: chats linked
     * to the record that the employee sees (open in the panel) and
     * "Пригласить в бота". Empty when there is nothing to show or offer.
     */
    public static function cardBlock($record): string
    {
        $ref = \skeeks\cms\communication\helpers\MailingUi::recordRef($record);
        $site = \Yii::$app->skeeks->site;
        $user = \Yii::$app->user->identity;
        if (!$ref || !$site || !$user) {
            return '';
        }
        $code = method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record);
        $access = new \skeeks\cms\communication\chat\ChatAccess($user);
        $c = CmsCommConversation::tableName();
        $chats = $access->query((int)$site->id)
            ->andWhere(['exists', \skeeks\cms\communication\models\CmsCommConversationLink::find()
                ->andWhere([\skeeks\cms\communication\models\CmsCommConversationLink::tableName().'.cms_comm_conversation_id' => new \yii\db\Expression($c.'.id')])
                ->andWhere(['model_code' => $code, 'model_id' => (int)$record->primaryKey])])
            ->orderBy([$c.'.last_message_at' => SORT_DESC])->limit(20)->all();
        $canInvite = false;
        foreach ($access->bots((int)$site->id) as $account) {
            $row = $access->rowOfAccount((int)$account->id);
            $canInvite = $canInvite || ($row && $row->send_level !== \skeeks\cms\communication\models\CmsCommAccountAccess::SEND_NONE);
        }
        if (!$chats && !$canInvite) {
            return '';
        }
        $items = [];
        foreach ($chats as $chat) {
            $note = $chat->waiting_since && !$chat->closed_at ? 'Клиент ждёт ответа '.self::waiting((int)$chat->waiting_since)
                : ($chat->last_message_at ? 'Последнее сообщение '.self::shortTime((int)$chat->last_message_at) : null);
            $items[] = [
                'label' => self::channelName($chat).' · '.(CmsCommConversation::chatTypes()[$chat->chat_type] ?? ''),
                'value' => Html::a(Html::encode($chat->asText()), '#', ['data-pjax' => '0', 'onclick' => self::openChatOnClick((int)$chat->id)]),
                'note' => $note,
            ];
        }

        return \skeeks\cms\widgets\admin\CrmContactBlockWidget::widget(array_filter([
            'title' => 'Мессенджеры',
            'hint' => 'Чаты ботов Telegram и MAX, привязанные к этой записи. «Пригласить в бота» даёт одноразовую ссылку: открывший её человек и его чат с ботом привяжутся сюда.',
            'icon' => 'message',
            'items' => $items,
            'emptyText' => 'Чатов пока нет',
            'addLabel' => $canInvite ? 'Пригласить в бота' : null,
            'addRoute' => $canInvite ? ['/cmsCommunication/admin-cms-comm-chat/invite', 'record' => $ref] : null,
            'addSize' => 'small',
        ], static fn($v) => $v !== null));
    }

    /** The round picture of a chat: its photo from the messenger, else the initials on a color of the name. */
    public static function avatar(CmsCommConversation $conversation): string
    {
        $name = $conversation->asText();
        $style = '--sx-chat-hue: '.self::hue($name);
        if ($conversation->hasAttribute('photo_storage_file_id') && $conversation->photo_storage_file_id) {
            $url = \yii\helpers\Url::to(['/cmsCommunication/admin-cms-comm-chat/photo', 'pk' => (int)$conversation->id, 'v' => (int)$conversation->photo_storage_file_id]);

            return Html::tag('span', Html::img($url, ['alt' => '', 'loading' => 'lazy']), ['class' => 'sx-chat-row__avatar sx-chat-row__avatar--photo', 'style' => $style]);
        }

        return Html::tag('span', Html::encode(self::initials($name)), ['class' => 'sx-chat-row__avatar', 'style' => $style]);
    }

    /** The round photo of a person of a chat, else the initials on a color of the name. */
    public static function personAvatar(?\skeeks\cms\communication\models\CmsCommIdentity $identity, CmsCommConversation $conversation, string $name): string
    {
        $style = '--sx-chat-hue: '.self::hue($name);
        if ($identity && $identity->hasAttribute('photo_storage_file_id') && $identity->photo_storage_file_id) {
            $url = \yii\helpers\Url::to(['/cmsCommunication/admin-cms-comm-chat/person-photo', 'pk' => (int)$conversation->id,
                'identity' => (int)$identity->id, 'v' => (int)$identity->photo_storage_file_id]);

            return Html::tag('span', Html::img($url, ['alt' => '', 'loading' => 'lazy']), ['class' => 'sx-msg__avatar sx-chat-row__avatar--photo', 'style' => $style, 'title' => $name]);
        }

        return Html::tag('span', Html::encode(self::initials($name)), ['class' => 'sx-msg__avatar', 'style' => $style, 'title' => $name]);
    }

    /**
     * The round picture beside a message: the messenger photo of the person,
     * else the CRM photo of the employee (ours), else the initials.
     */
    public static function messageAvatar(CmsCommMessage $message, CmsCommConversation $conversation, string $name): string
    {
        $identity = $message->identity;
        if ($identity && $identity->hasAttribute('photo_storage_file_id') && $identity->photo_storage_file_id) {
            return self::personAvatar($identity, $conversation, $name);
        }
        $user = $message->direction === CmsCommMessage::DIRECTION_OUT ? $message->sentBy : ($identity && $identity->isWorker() ? $identity->cmsUser : null);
        $src = $user ? $user->getAvatarSrc(80, 80) : null;
        if ($src) {
            return Html::tag('span', Html::img($src, ['alt' => '', 'loading' => 'lazy']),
                ['class' => 'sx-msg__avatar sx-chat-row__avatar--photo', 'style' => '--sx-chat-hue: '.self::hue($name), 'title' => $name]);
        }
        if ($message->direction === CmsCommMessage::DIRECTION_OUT && !$message->sentBy) {
            // Sent by the bot itself (a notice, an invitation): its icon instead of a letter.
            return Html::tag('span', \skeeks\cms\backend\helpers\BackendIcon::render('bot'), ['class' => 'sx-msg__avatar sx-msg__avatar--bot', 'title' => $name]);
        }

        return self::personAvatar($identity, $conversation, $name);
    }

    /**
     * The text of a message as the chat shows it: a reply from the CRM starts
     * with the employee's signature line, the chat names the author above.
     */
    public static function body(CmsCommMessage $message): string
    {
        $body = (string)$message->body_text;
        if ($message->sent_by_user_id && ($break = strpos($body, "\n")) !== false && substr(rtrim(substr($body, 0, $break)), -1) === ':') {
            return ltrim(substr($body, $break + 1));
        }

        return $body;
    }

    /** "Сегодня", "Вчера", "23 октября", "23 октября 2025". */
    public static function day(int $time): string
    {
        $f = \Yii::$app->formatter;
        $day = $f->asDate($time, 'yyyy-MM-dd');
        if ($day === $f->asDate(time(), 'yyyy-MM-dd')) {
            return 'Сегодня';
        }
        if ($day === $f->asDate(strtotime('-1 day'), 'yyyy-MM-dd')) {
            return 'Вчера';
        }

        return $f->asDate($time, $f->asDate($time, 'yyyy') === $f->asDate(time(), 'yyyy') ? 'd MMMM' : 'd MMMM yyyy');
    }

    /** The size of a file: "12 КБ", "3,4 МБ". */
    public static function size(?int $bytes): string
    {
        if (!$bytes) {
            return '';
        }
        if ($bytes < 1024 * 1024) {
            return max(1, (int)round($bytes / 1024)).' КБ';
        }

        return str_replace('.', ',', (string)round($bytes / 1024 / 1024, 1)).' МБ';
    }

    /** "1 участник", "3 участника", "6 участников". */
    public static function members(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        $word = $mod10 === 1 && $mod100 !== 11 ? 'участник' : ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) ? 'участника' : 'участников');

        return $n.' '.$word;
    }

    /** "PDF", "DOCX": the extension of a file for its card. */
    public static function extension(string $name): string
    {
        $ext = mb_strtoupper((string)pathinfo($name, PATHINFO_EXTENSION));

        return $ext !== '' && mb_strlen($ext) <= 5 ? $ext : 'FILE';
    }

    /** A CRM record as a standard entity link (opens its card in the panel). */
    public static function recordLink(string $modelCode, $record, string $type): string
    {
        $config = \skeeks\cms\communication\services\CrmRecords::type($type) ?? [];
        $content = Html::tag('span', Html::encode($config['title'] ?? 'Запись'), ['class' => 'sx-chat-links__type'])
            .' '.Html::encode(\skeeks\cms\communication\services\CrmRecords::name($record));
        $controller = \Yii::$app->skeeks->modelsConfig[$modelCode]['controller'] ?? null;

        return $controller
            ? \skeeks\cms\backend\widgets\BackendEntityLink::widget([
                'controllerId' => '/'.ltrim((string)$controller, '/'),
                'modelId' => $record->primaryKey,
                'content' => $content,
                'options' => ['class' => 'sx-chat-links__record', 'title' => 'Открыть карточку'],
            ])
            : Html::tag('span', $content, ['class' => 'sx-chat-links__record']);
    }

    /** Opens a chat in the backend panel (from a feed, a card block). */
    public static function openChatOnClick(int $conversationId): string
    {
        $url = (string)\skeeks\cms\backend\helpers\BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-chat/view', 'pk' => $conversationId])
            ->enableEmptyLayout()->url;

        return 'new sx.classes.backend.widgets.Action('.\yii\helpers\Json::encode(['isOpenNewWindow' => true, 'url' => $url]).').go(); return false;';
    }

    /** A message of a private chat in the feed of a linked record. */
    public static function feedMessage(CmsCommMessage $message, CmsCommConversation $conversation): string
    {
        $incoming = $message->direction === CmsCommMessage::DIRECTION_IN;
        $channel = self::channelName($conversation);
        $title = $incoming ? 'Сообщение в '.$channel : 'Ответ в '.$channel;
        $status = !$incoming && in_array($message->status, [CmsCommMessage::STATUS_FAILED, CmsCommMessage::STATUS_UNKNOWN], true)
            ? ' · '.Html::tag('span', Html::encode($message->getStatusName()), ['class' => 'sx-status sx-status--danger']) : '';
        $files = count($message->getAttachmentList());

        return Html::tag('div',
            Html::tag('div', Html::encode($title).$status, ['class' => 'h5'])
            .Html::tag('div', '<span class="sx-gray">'.($incoming ? 'От:' : 'Написал(а):').'</span> '.Html::encode(self::author($message)))
            .Html::tag('div', Html::encode($message->getSnippet(400)).($files ? ' <span class="sx-gray">· файлов: '.$files.'</span>' : ''))
            .Html::tag('div', Html::a('Открыть чат «'.Html::encode($conversation->asText()).'»', '#', [
                'data-pjax' => '0',
                'onclick' => self::openChatOnClick((int)$conversation->id),
            ])),
            ['class' => 'sx-comm-message-log']
        );
    }

    public static function status(CmsCommMessage $message): string
    {
        return [
            CmsCommMessage::STATUS_QUEUED => 'отправляется…',
            CmsCommMessage::STATUS_SENDING => 'отправляется…',
            CmsCommMessage::STATUS_SENT => '✓',
            CmsCommMessage::STATUS_FAILED => 'не отправлено',
            CmsCommMessage::STATUS_UNKNOWN => 'неизвестно, доставлено ли',
        ][$message->status] ?? '';
    }
}
