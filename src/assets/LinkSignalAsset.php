<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\assets;

use yii\web\AssetBundle;

/**
 * `window.sxLinkSignal`: the honest "a person saw the page" signal and the
 * browser signals for LinkChecks, shared by pages of personal links of
 * other packages (skeeks/cms-proposal) and the visit script. No jQuery.
 */
class LinkSignalAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/communication/assets/src/visit';

    public $js = ['link-signal.js'];

    public $jsOptions = ['position' => \yii\web\View::POS_HEAD];

    /** The address of the script for a layout that prints its own tags (no View page events). */
    public static function scriptUrl(): string
    {
        $bundle = \Yii::$app->assetManager->getBundle(self::class);
        $path = \Yii::getAlias($bundle->sourcePath).'/link-signal.js';

        return $bundle->baseUrl.'/link-signal.js'.(is_file($path) ? '?v='.filemtime($path) : '');
    }
}
