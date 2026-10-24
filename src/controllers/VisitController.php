<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\communication\components\LinkTrackingSettings;
use skeeks\cms\communication\services\LinkMarks;
use skeeks\cms\communication\services\LinkVisits;
use skeeks\cms\rbac\CmsManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Signals of the visit script of our sites (services\LinkVisits, step F):
 * `POST /~comm-visit/seen` — a page opened by a link of a letter was shown
 * (the code, the page, the browser signals); `POST /~comm-visit/page` — the
 * next page of the visit (its token); `GET /~comm-visit/visit.js` — the
 * script for a site on another installation.
 *
 * A site on another installation posts here across sites: answered only
 * for origins on our domains (LinkTrackingSettings), no cookies are read or
 * set (the script keeps the token in a cookie of its own site and sends it).
 * No CSRF check: a page of another site has no token; the code and the token
 * are the access.
 */
class VisitController extends \yii\web\Controller
{
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => \yii\filters\VerbFilter::class,
                'actions' => ['seen' => ['post'], 'page' => ['post'], 'script' => ['get', 'head']],
            ],
        ];
    }

    public function beforeAction($action)
    {
        $response = \Yii::$app->response;
        $headers = $response->headers;
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        if ($action->id !== 'script') {
            $headers->set('Cache-Control', 'no-store');
            $origin = (string)\Yii::$app->request->headers->get('Origin', '');
            if ($origin !== '') {
                if (!$this->isAllowedOrigin($origin)) {
                    throw new ForbiddenHttpException('Сайт не в списке наших сайтов.');
                }
                $headers->set('Access-Control-Allow-Origin', $origin);
                $headers->add('Vary', 'Origin');
            }
            $response->format = Response::FORMAT_JSON;
        }

        return parent::beforeAction($action);
    }

    /** The same site, or a site on our domains (where marked links lead). */
    private function isAllowedOrigin(string $origin): bool
    {
        $host = (string)parse_url($origin, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }
        if (strcasecmp($host, (string)\Yii::$app->request->hostName) === 0) {
            return true;
        }
        $site = \Yii::$app->skeeks->site;

        return $site && LinkMarks::isOurHost($host, LinkTrackingSettings::forSite((int)$site->id)['domains']);
    }

    public static function isStaff(): bool
    {
        $user = \Yii::$app->has('user') ? \Yii::$app->user : null;

        return $user && !$user->isGuest && $user->can(CmsManager::PERMISSION_ADMIN_ACCESS);
    }

    public function actionSeen()
    {
        $request = \Yii::$app->request;
        $track = LinkVisits::findTrack((string)$request->post('code', ''));
        if (!$track) {
            return ['result' => LinkVisits::RESULT_UNKNOWN];
        }
        // What the page script saw of the browser: automated browsers of link checks give themselves away.
        $signals = [];
        foreach (['webdriver', 'languages', 'width', 'height'] as $key) {
            if (($value = $request->post($key)) !== null && is_scalar($value)) {
                $signals[$key] = mb_substr((string)$value, 0, 16);
            }
        }
        $token = $request->post('token');

        return (new LinkVisits())->seen($track, (string)$request->post('url', ''), (string)$request->post('title', ''),
            (string)$request->userIP, (string)$request->userAgent, $signals, is_string($token) ? mb_substr($token, 0, 48) : null, self::isStaff());
    }

    public function actionPage()
    {
        $request = \Yii::$app->request;
        $token = $request->post('token');
        if (!is_string($token) || $token === '') {
            return ['result' => LinkVisits::RESULT_UNKNOWN];
        }

        return (new LinkVisits())->page(mb_substr($token, 0, 48), (string)$request->post('url', ''), (string)$request->post('title', ''));
    }

    /** The shared signal code and the visit script in one file, for a site on another installation. */
    public function actionScript()
    {
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/javascript; charset=UTF-8');
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $dir = \Yii::getAlias('@skeeks/cms/communication/assets/src/visit');

        return file_get_contents($dir.'/link-signal.js')."\n".file_get_contents($dir.'/visit.js');
    }
}
