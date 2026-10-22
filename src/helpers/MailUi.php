<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\helpers;

use skeeks\cms\backend\helpers\BackendUrlHelper;
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
    ];

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

    /** onclick JS that opens a letter. */
    public static function viewOnClick(int $messageId): string
    {
        return self::openWindow([self::ROUTE_MESSAGE.'/view', 'pk' => $messageId]);
    }

    /** @var array model code => record id => visible to the current employee */
    private static array $visible = [];

    /**
     * Links of a letter to records the current employee can see in the CRM.
     * Reading a whole mailbox shows its letters, not its other clients: their
     * names stay hidden, and their cards are never offered (an entity link of
     * a card without access would run its only action, "Удалить").
     *
     * @return \skeeks\cms\communication\models\CmsCommLink[]
     */
    public static function visibleLinks(\skeeks\cms\communication\models\CmsCommMessage $message): array
    {
        $user = \Yii::$app->user->identity;
        if (!$user) {
            return [];
        }
        if (\Yii::$app->user->can(\skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            return $message->links;
        }
        $classes = [
            (new \skeeks\cms\models\CmsLead())->skeeksModelCode => \skeeks\cms\models\CmsLead::class,
            (new \skeeks\cms\models\CmsCompany())->skeeksModelCode => \skeeks\cms\models\CmsCompany::class,
            (new \skeeks\cms\models\CmsUser())->skeeksModelCode => \skeeks\cms\models\CmsUser::class,
        ];

        return array_values(array_filter($message->links, static function ($link) use ($classes, $user) {
            $class = $classes[$link->model_code] ?? null;
            if (!$class) {
                return false;
            }
            $id = (int)$link->model_id;
            if (!isset(self::$visible[$link->model_code][$id])) {
                self::$visible[$link->model_code][$id] = $class::find()->forManager($user)
                    ->andWhere([$class::tableName().'.id' => $id])->exists();
            }

            return self::$visible[$link->model_code][$id];
        }));
    }

    /**
     * CRM records of a letter as standard entity links: logo (or icon) and
     * name, opening the record card in the backend panel; only records the
     * employee can see (visibleLinks()).
     */
    public static function linkedRecords(\skeeks\cms\communication\models\CmsCommMessage $message): string
    {
        $icons = [
            (new \skeeks\cms\models\CmsCompany())->skeeksModelCode => 'building',
            (new \skeeks\cms\models\CmsUser())->skeeksModelCode => 'user',
            (new \skeeks\cms\models\CmsLead())->skeeksModelCode => 'id-card',
        ];
        $items = [];
        foreach (self::visibleLinks($message) as $link) {
            $record = $link->getLinkedModel();
            if (!$record) {
                continue;
            }
            $config = \Yii::$app->skeeks->modelsConfig[$link->model_code] ?? [];
            $name = method_exists($record, 'asText') ? (string)$record->asText() : '#'.$record->primaryKey;
            $image = null;
            foreach (['cmsImage', 'image'] as $relation) {
                if ($record->canGetProperty($relation) && $record->$relation) {
                    $image = $record->$relation;
                    break;
                }
            }
            $content = \skeeks\cms\backend\widgets\BackendEntityMedia::widget([
                    'image' => $image,
                    'icon' => $icons[$link->model_code] ?? 'link',
                    'size' => 'small',
                ])
                .\yii\helpers\Html::tag('span',
                    \yii\helpers\Html::tag('span', \yii\helpers\Html::encode($config['name_one'] ?? 'Запись'), ['class' => 'sx-letter__record-type'])
                    .\yii\helpers\Html::tag('span', \yii\helpers\Html::encode($name), ['class' => 'sx-letter__record-name']),
                    ['class' => 'sx-letter__record-text']);

            $items[] = empty($config['controller'])
                ? \yii\helpers\Html::tag('span', $content, ['class' => 'sx-letter__record'])
                : \skeeks\cms\backend\widgets\BackendEntityLink::widget([
                    'controllerId' => '/'.ltrim((string)$config['controller'], '/'),
                    'modelId' => $record->primaryKey,
                    'content' => $content,
                    'options' => ['class' => 'sx-letter__record', 'title' => 'Открыть карточку'],
                ]);
        }

        return $items ? \yii\helpers\Html::tag('div', implode('', $items), ['class' => 'sx-letter__records']) : '';
    }

    private static function openWindow(array $route): string
    {
        $url = (string)BackendUrlHelper::createByParams($route)->enableEmptyLayout()->enableNoActions()->url;

        return 'new sx.classes.backend.widgets.Action('.Json::encode(['isOpenNewWindow' => true, 'url' => $url]).').go(); return false;';
    }
}
