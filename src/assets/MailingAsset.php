<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\assets;

use yii\web\AssetBundle;

/**
 * Mailings in the administration: the window "Подписки на рассылки" of a
 * contact and the import of recipients into leads. Registered only by those
 * pages; colors come from the shared --sx-* theme.
 */
class MailingAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/communication/assets/src';

    public $css = ['mailing.css'];
}
