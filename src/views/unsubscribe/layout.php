<?php
/**
 * The own layout of the opt-out page: light only, not indexed.
 *
 * It deliberately calls none of beginPage()/head()/beginBody()/endBody()/
 * endPage(): site packages hook those view events (theme scripts, cookie
 * banner, counters), and none of that belongs on a personal page of a
 * client. The page has only its own style.
 *
 * @var yii\web\View $this
 * @var string $content
 */

use skeeks\cms\communication\assets\UnsubscribePageAsset;
use yii\helpers\Html;

$assets = \Yii::$app->assetManager;
$bundle = $assets->getBundle(UnsubscribePageAsset::class);
$css = $bundle->basePath.'/unsubscribe.css';
$cssUrl = $bundle->baseUrl.'/unsubscribe.css'.(is_file($css) ? '?v='.filemtime($css) : '');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light">
    <title><?= Html::encode($this->title); ?></title>
    <link rel="stylesheet" href="<?= Html::encode($cssUrl); ?>">
</head>
<body class="sx-un-body">
<?= $content; ?>
</body>
</html>
