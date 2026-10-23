<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\assets;

use yii\web\AssetBundle;

/**
 * Layout of the "Почта" section: folders, letter list, reading pane.
 * Registered only by that page; colors come from the shared --sx-* theme.
 */
class MailClientAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/communication/assets/src';

    public $css = ['mail.css'];

    public $js = ['mail.js'];

    public $depends = [
        \yii\web\JqueryAsset::class,
        \yii\web\YiiAsset::class,
        // Compose, reply and record links open windows through sx.classes.backend.widgets.Action.
        \skeeks\cms\backend\widgets\assets\ControllerActionsWidgetAsset::class,
        // The letter editor; the quick reply is inserted later without its own assets.
        \skeeks\cms\widgets\formInputs\richText\RichTextAsset::class,
        // Letter attachments (shared field); also loads before mail.css, which tunes the "Прикрепить из…" chips.
        \skeeks\cms\widgets\formInputs\attachments\AttachmentsAsset::class,
    ];
}
