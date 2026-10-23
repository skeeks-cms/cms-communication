<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\helpers;

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\services\CrmRecords;
use yii\helpers\Html;
use yii\helpers\Json;

/**
 * Links that open mail dialogs in the standard backend window.
 */
class MailUi
{
    public const ROUTE_MESSAGE = '/cmsCommunication/admin-cms-comm-message';

    /** Icon-font glyphs for names missing in an older cms-backend icon set. */
    private const ICON_FALLBACK = [
        'mail' => 'far fa-envelope', 'users' => 'far fa-address-book', 'help' => 'far fa-question-circle',
        'inbox' => 'fas fa-inbox', 'send' => 'far fa-paper-plane', 'refresh' => 'fas fa-sync-alt',
        'reply' => 'fas fa-reply', 'forward' => 'fas fa-share', 'mail-open' => 'far fa-envelope-open',
        'paperclip' => 'fas fa-paperclip', 'search' => 'fas fa-search', 'file' => 'far fa-file', 'info' => 'fas fa-info-circle',
        'reply-all' => 'fas fa-reply-all', 'image' => 'far fa-image', 'link' => 'fas fa-link',
        'plus' => 'fas fa-plus', 'chevron-down' => 'fas fa-chevron-down', 'user' => 'far fa-user', 'tasks' => 'fas fa-tasks',
        'handshake' => 'far fa-handshake', 'id-card' => 'far fa-id-card', 'building' => 'far fa-building', 'more-vertical' => 'fas fa-ellipsis-v',
        'trash' => 'far fa-trash-alt', 'folder' => 'far fa-folder',
    ];

    /**
     * Where the letter is in its mailbox as the employee sees it (the place
     * asked for wins over the server one): the role of its first copy.
     */
    public static function mailboxRole(\skeeks\cms\communication\models\CmsCommMessage $message): ?string
    {
        foreach ($message->mailboxCopies as $copy) {
            if ($copy->getRole()) {
                return $copy->getRole();
            }
        }

        return null;
    }

    /**
     * Labels and the place of the letter in its mailbox for the list and the
     * letter header: colored labels, "Архив" / a folder name, "Переносится…"
     * while the server has not done it, the last server error.
     */
    public static function mailboxTags(\skeeks\cms\communication\models\CmsCommMessage $message): string
    {
        $html = '';
        foreach ($message->labels as $label) {
            $html .= Html::tag('span', Html::encode($label->name), [
                'class' => 'sx-mail__tag sx-mail__label',
                'style' => '--sx-mail-label: '.$label->color,
                'title' => 'Метка',
            ]);
        }
        $titles = \skeeks\cms\communication\models\CmsCommMessageMailbox::roleTitles();
        $shown = [];
        foreach ($message->mailboxCopies as $copy) {
            if ($copy->target_role) {
                $where = $copy->target_folder ? self::serverFolderTitle((int)$copy->cms_comm_connection_id, (string)$copy->target_folder) : ($titles[$copy->target_role] ?? '');
                $text = 'Переносится: '.$where;
                $class = 'sx-mail__tag sx-mail__tag--pending';
                $title = 'Письмо переносится в почтовом ящике';
            } elseif ($copy->sync_error) {
                $text = 'Не перенесено';
                $class = 'sx-mail__tag sx-mail__tag--status';
                $title = $copy->sync_error;
            } elseif (in_array($copy->folder_role, ['archive', 'other', 'trash', 'junk'], true)) {
                $text = $copy->folder_role === 'other' ? self::serverFolderTitle((int)$copy->cms_comm_connection_id, (string)$copy->folder) : $titles[$copy->folder_role];
                $class = 'sx-mail__tag sx-mail__tag--place';
                $title = 'Папка в почтовом ящике';
            } else {
                continue;
            }
            if (isset($shown[$text])) {
                continue;
            }
            $shown[$text] = true;
            $icon = ['archive' => 'archive', 'trash' => 'trash', 'junk' => 'spam'][$copy->folder_role] ?? 'folder';
            $html .= Html::tag('span', ($copy->target_role || $copy->sync_error ? '' : self::icon($icon, 12).' ').Html::encode($text),
                ['class' => $class, 'title' => $title, 'data-role' => $copy->target_role || $copy->sync_error ? null : $copy->folder_role]);
        }

        return $html;
    }

    private static array $folderTitles = [];

    /** A server folder as people read it ("Клиенты / Иванов"). */
    public static function serverFolderTitle(int $connectionId, string $folder): string
    {
        if (!isset(self::$folderTitles[$connectionId])) {
            self::$folderTitles[$connectionId] = [];
            foreach (\skeeks\cms\communication\models\CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => $connectionId])->all() as $state) {
                self::$folderTitles[$connectionId][(string)$state->folder] = $state->getTitle();
            }
        }

        return self::$folderTitles[$connectionId][$folder] ?? \skeeks\cms\communication\models\CmsCommSyncState::folderTitle($folder);
    }

    /** One or two capital letters of a name for the round avatar. */
    public static function initials(string $name): string
    {
        $words = preg_split('/[\s"«»().,]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $letters = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));

        return $letters !== '' ? $letters : '?';
    }

    /** Stable avatar color (hue) of an address or name. */
    public static function hue(string $key): int
    {
        return hexdec(substr(md5(mb_strtolower($key)), 0, 4)) % 360;
    }

    /** Mail-only glyphs drawn like BackendIcon (24×24, stroke), absent from the shared set. */
    private const OWN_ICONS = [
        'reply-all' => '<path d="m7 17-5-5 5-5"></path><path d="m12 17-5-5 5-5"></path><path d="M22 18v-2a4 4 0 0 0-4-4H7"></path>',
        'image' => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"></rect><circle cx="9" cy="9" r="2"></circle><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>',
        'archive' => '<rect width="20" height="5" x="2" y="3" rx="1"></rect><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"></path><path d="M10 12h4"></path>',
        'spam' => '<path d="M12 16h.01"></path><path d="M12 8v4"></path><path d="M15.312 2a2 2 0 0 1 1.414.586l4.688 4.688A2 2 0 0 1 22 8.688v6.624a2 2 0 0 1-.586 1.414l-4.688 4.688a2 2 0 0 1-1.414.586H8.688a2 2 0 0 1-1.414-.586l-4.688-4.688A2 2 0 0 1 2 15.312V8.688a2 2 0 0 1 .586-1.414l4.688-4.688A2 2 0 0 1 8.688 2z"></path>',
        'tag' => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"></path><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"></circle>',
        'undo' => '<path d="M9 14 4 9l5-5"></path><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"></path>',
        'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M10 9H8"></path><path d="M16 13H8"></path><path d="M16 17H8"></path>',
        'message' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"></path>',
    ];

    /** A thin backend icon, or its icon-font fallback on an older cms-backend. */
    public static function icon(string $name, int $size = 18): string
    {
        if (isset(self::OWN_ICONS[$name]) && !\skeeks\cms\backend\helpers\BackendIcon::supports($name)) {
            return \yii\helpers\Html::tag('svg', self::OWN_ICONS[$name], [
                'class' => 'sx-icon sx-icon--'.$name, 'viewBox' => '0 0 24 24', 'width' => $size, 'height' => $size,
                'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => 2, 'stroke-linecap' => 'round',
                'stroke-linejoin' => 'round', 'focusable' => 'false', 'aria-hidden' => 'true',
            ]);
        }
        if (\skeeks\cms\backend\helpers\BackendIcon::supports($name)) {
            return \skeeks\cms\backend\helpers\BackendIcon::render($name, ['size' => $size]);
        }

        return \yii\helpers\Html::tag('i', '', ['class' => self::ICON_FALLBACK[$name] ?? 'fas fa-circle', 'aria-hidden' => 'true']);
    }

    /** onclick JS of a "write a letter" control. */
    public static function composeOnClick(array $params = []): string
    {
        return self::openWindow(array_merge([self::ROUTE_MESSAGE.'/create'], $params));
    }

    /**
     * The window "Отправить клиенту" of a sendable ("proposal:12"); $record —
     * the card it is opened from (its contacts first, the send in its feed).
     */
    public static function sendUrl(string $sendable, $record = null): string
    {
        return (string)BackendUrlHelper::createByParams([self::ROUTE_MESSAGE.'/send', 'sendable' => $sendable,
            'record' => \skeeks\cms\communication\services\SendService::recordRefOf($record) ?: null])
            // Not _sxb[noa]: it would follow the window into the menu links (see ARCHITECTURE.md, «Известные мелочи»).
            ->enableEmptyLayout()->url;
    }

    /** onclick JS of a "Отправить клиенту" control. */
    public static function sendOnClick(string $sendable, $record = null): string
    {
        return 'new sx.classes.backend.widgets.Action('.Json::encode(['isOpenNewWindow' => true, 'url' => self::sendUrl($sendable, $record)]).').go(); return false;';
    }

    /** @return array[] each: type, title — kinds offered by "Прикрепить из…" */
    public static function sendableSources(): array
    {
        $component = \Yii::$app->has('communication') ? \Yii::$app->communication : null;
        $result = [];
        foreach ($component ? array_keys((array)$component->sendables) : [] as $type) {
            if ($source = $component->getSendableSource((string)$type)) {
                $result[] = ['type' => (string)$type, 'title' => $source->title()];
            }
        }

        return $result;
    }

    /**
     * A thing put into the letter: its title, its files and the hidden "type:id" value.
     * A chip of the shared attachments list (AttachmentsInputWidget, sx-attach__*).
     */
    public static function sendableChip(array $sendable, string $inputName): string
    {
        $files = $sendable['files'] ? ' · '.implode(', ', $sendable['files']) : '';

        return Html::tag('span',
            self::icon('file-text', 14)
            .Html::tag('span', Html::encode($sendable['title']), ['class' => 'sx-attach__name', 'title' => $sendable['title'].$files])
            .Html::tag('span', Html::encode('ссылка'.($sendable['files'] ? ' и '.(count($sendable['files']) > 1 ? 'файлы' : 'файл') : '')), ['class' => 'sx-attach__size'])
            .Html::button('×', ['class' => 'sx-attach__remove', 'data-sx-compose-sendable-remove' => true, 'aria-label' => 'Убрать из письма'])
            .Html::hiddenInput($inputName.'[]', $sendable['ref']),
            ['class' => 'sx-attach__file sx-compose__file--sendable', 'data-sx-compose-sendable' => $sendable['ref']]);
    }

    /** onclick JS that opens a letter. */
    public static function viewOnClick(int $messageId): string
    {
        return self::openWindow([self::ROUTE_MESSAGE.'/view', 'pk' => $messageId]);
    }

    /**
     * Links of a letter to records the current employee can see in the CRM
     * (CrmRecords: leads, companies, clients, deals, tasks). Reading a whole
     * mailbox shows its letters, not its other clients: their names stay
     * hidden, and their cards are never offered (an entity link of a card
     * without access would run its only action, "Удалить").
     *
     * @return \skeeks\cms\communication\models\CmsCommLink[]
     */
    public static function visibleLinks(\skeeks\cms\communication\models\CmsCommMessage $message): array
    {
        $user = \Yii::$app->user->identity;
        if (!$user) {
            return [];
        }

        return array_values(array_filter($message->links, static function ($link) use ($user) {
            return CrmRecords::isVisible((string)$link->model_code, (int)$link->model_id, $user);
        }));
    }

    /**
     * Text of a CRM mark of a letter in the list: the record name, or
     * "Задача №12" for a record created from this letter.
     */
    public static function linkLabel(\skeeks\cms\communication\models\CmsCommLink $link): ?string
    {
        $record = $link->getLinkedModel();
        if (!$record) {
            return null;
        }
        $type = CrmRecords::typeOfCode((string)$link->model_code);
        if ($link->link_type === \skeeks\cms\communication\models\CmsCommLink::TYPE_CREATED && $type) {
            return CrmRecords::number($type, $record);
        }

        return CrmRecords::name($record);
    }

    /**
     * CRM records of a letter as standard entity links: logo (or icon) and
     * name, opening the record card in the backend panel; only records the
     * employee can see (visibleLinks()). Each has "Отвязать" when $unlink.
     * Records created from this letter are shown by createdMarks() instead.
     */
    public static function linkedRecords(\skeeks\cms\communication\models\CmsCommMessage $message, bool $unlink = false): string
    {
        $items = [];
        foreach (self::visibleLinks($message) as $link) {
            if ($link->link_type === \skeeks\cms\communication\models\CmsCommLink::TYPE_CREATED) {
                continue;
            }
            $record = $link->getLinkedModel();
            if (!$record) {
                continue;
            }
            $type = (string)CrmRecords::typeOfCode((string)$link->model_code);
            $config = \Yii::$app->skeeks->modelsConfig[$link->model_code] ?? [];
            $name = CrmRecords::name($record);
            $image = null;
            foreach (['cmsImage', 'image'] as $relation) {
                if ($record->canGetProperty($relation) && $record->$relation) {
                    $image = $record->$relation;
                    break;
                }
            }
            $content = \skeeks\cms\backend\widgets\BackendEntityMedia::widget([
                    'image' => $image,
                    'icon' => CrmRecords::type($type)['icon'] ?? 'link',
                    'size' => 'small',
                ])
                .Html::tag('span',
                    Html::tag('span', Html::encode(CrmRecords::type($type)['title'] ?? ($config['name_one'] ?? 'Запись')), ['class' => 'sx-letter__record-type'])
                    .Html::tag('span', Html::encode($name), ['class' => 'sx-letter__record-name']),
                    ['class' => 'sx-letter__record-text']);

            $item = self::entityLink((string)$link->model_code, $record, $content, 'sx-letter__record');
            if ($unlink) {
                $item .= Html::button('×', [
                    'class' => 'sx-letter__unlink',
                    'title' => 'Отвязать',
                    'aria-label' => 'Отвязать от «'.$name.'»',
                    'data-sx-letter-unlink' => Json::encode(['code' => $link->model_code, 'id' => (int)$link->model_id, 'name' => $name]),
                ]);
            }
            $items[] = Html::tag('span', $item, ['class' => 'sx-letter__record-wrap']);
        }

        return $items ? Html::tag('div', implode('', $items), ['class' => 'sx-letter__records']) : '';
    }

    /**
     * "Задача №12", "Лид №5": records created from the letter, opening their cards.
     *
     * @param array[] $created MessageSorting::createdRecords()
     */
    public static function createdMarks(array $created): string
    {
        $items = [];
        foreach ($created as $item) {
            $record = $item['record'];
            $content = self::icon(CrmRecords::type($item['type'])['icon'] ?? 'link', 15)
                .Html::tag('span', Html::encode(CrmRecords::number($item['type'], $record)));
            $items[] = self::entityLink(CrmRecords::codeOf($item['type']), $record, $content, 'sx-letter__mark');
        }

        return $items ? Html::tag('div', implode('', $items), ['class' => 'sx-letter__marks', 'title' => 'Создано из этого письма']) : '';
    }

    /**
     * "Создать задачу / лид / сделку": the standard create window of the CRM
     * with ?source=message:ID (skeeks/cms RecordSources), only those the
     * employee may open.
     *
     * @return array[] type => title, icon, url
     */
    public static function createActions(int|string $messageId): array
    {
        $result = [];
        foreach (CrmRecords::CREATABLE as $type) {
            $config = CrmRecords::type($type);
            $controller = \Yii::$app->skeeks->modelsConfig[$config['class']]['controller'] ?? null;
            if (!$controller || !self::canOpen(ltrim((string)$controller, '/'), 'create')) {
                continue;
            }
            $result[$type] = [
                'title' => $config['create'],
                'icon' => $config['icon'],
                'url' => (string)BackendUrlHelper::createByParams([
                    '/'.ltrim((string)$controller, '/').'/create',
                    \skeeks\cms\source\RecordSources::PARAM => \skeeks\cms\communication\source\MessageRecordSource::TYPE.':'.$messageId,
                ])->enableEmptyLayout()->url,
            ];
        }

        return $result;
    }

    /** The "Привязать" window of a letter. */
    public static function linkUrl(int|string $messageId): string
    {
        return (string)BackendUrlHelper::createByParams([self::ROUTE_MESSAGE.'/link', 'pk' => $messageId])->enableEmptyLayout()->url;
    }

    /** Access to a backend controller action, the way BackendEntityLink checks a card. */
    private static function canOpen(string $controllerId, string $action): bool
    {
        if (!\Yii::$app->has('authManager') || \Yii::$app->user->isGuest) {
            return false;
        }
        foreach ([$controllerId.'/'.$action, $controllerId] as $permission) {
            if (\Yii::$app->authManager->getPermission($permission)) {
                return \Yii::$app->user->can($permission);
            }
        }

        return true;
    }

    private static function entityLink(string $modelCode, $record, string $content, string $class): string
    {
        $controller = \Yii::$app->skeeks->modelsConfig[$modelCode]['controller'] ?? null;

        return $controller
            ? \skeeks\cms\backend\widgets\BackendEntityLink::widget([
                'controllerId' => '/'.ltrim((string)$controller, '/'),
                'modelId' => $record->primaryKey,
                'content' => $content,
                'options' => ['class' => $class, 'title' => 'Открыть карточку'],
            ])
            : Html::tag('span', $content, ['class' => $class]);
    }


    private static function openWindow(array $route): string
    {
        $url = (string)BackendUrlHelper::createByParams($route)->enableEmptyLayout()->enableNoActions()->url;

        return 'new sx.classes.backend.widgets.Action('.Json::encode(['isOpenNewWindow' => true, 'url' => $url]).').go(); return false;';
    }
}
