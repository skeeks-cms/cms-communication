<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\assets;

use yii\web\AssetBundle;

/**
 * Layout of the "Чаты" section: the chat list, the open chat, the reply.
 * Registered only by that page; colors come from the shared --sx-* theme.
 */
class ChatAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/communication/assets/src';

    public $css = ['chats.css'];

    public $js = ['chats.js'];

    public $depends = [
        \yii\web\JqueryAsset::class,
        \yii\web\YiiAsset::class,
        // Linked records open their cards, "Привязать…" opens a window: the chat pane is loaded by AJAX,
        // so the scripts of entity links are on the page beforehand.
        \skeeks\cms\backend\widgets\assets\ControllerActionsWidgetAsset::class,
        \skeeks\cms\backend\widgets\assets\AjaxControllerActionsWidgetAsset::class,
    ];
}
