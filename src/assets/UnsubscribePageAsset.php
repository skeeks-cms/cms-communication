<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\assets;

use yii\web\AssetBundle;

/**
 * The opt-out page for clients: its own light design only. No jQuery,
 * nothing of the site theme or the backend; the layout prints the link
 * itself (views/unsubscribe/layout.php).
 */
class UnsubscribePageAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/communication/assets/src/unsubscribe';

    public $css = ['unsubscribe.css'];
}
