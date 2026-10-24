<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\assets;

use yii\web\AssetBundle;

/**
 * The visit script of the site of the CRM (step F, services\LinkVisits):
 * registered by CommunicationBootstrap only on a page opened by a marked
 * link (`?sx=`) or within such a visit (cookie `sx_visit`); other pages
 * load nothing. In the head: the code leaves the address bar before the
 * counters read it.
 */
class VisitAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/communication/assets/src/visit';

    public $js = ['visit.js'];

    public $jsOptions = ['position' => \yii\web\View::POS_HEAD];

    public $depends = [LinkSignalAsset::class];
}
