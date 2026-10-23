<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\communication\services\Unsubscribe;
use skeeks\cms\models\CmsSite;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The opt-out page of advertising mailings by a personal link (`/~unsub/<code>`),
 * without signing in, and the one-click opt-out of mail programs (RFC 8058:
 * `POST` with `List-Unsubscribe=One-Click` to the same address). See
 * services\Unsubscribe.
 *
 * Not indexed, no counters and no scripts of the site: an own layout and the
 * page as `Response::$content` (see client-link-pages of the package skill).
 * No CSRF check: mail programs post without a token; the code is the access.
 */
class UnsubscribeController extends \yii\web\Controller
{
    public $layout = '@skeeks/cms/communication/views/unsubscribe/layout';

    public $enableCsrfValidation = false;

    public function getViewPath()
    {
        return \Yii::getAlias('@skeeks/cms/communication/views/unsubscribe');
    }

    public function beforeAction($action)
    {
        $headers = \Yii::$app->response->headers;
        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        // The code is in the address: it must not leak to other sites through Referer.
        $headers->set('Referrer-Policy', 'no-referrer');

        return parent::beforeAction($action);
    }

    public function actionView($code)
    {
        $contact = Unsubscribe::contactByCode((string)$code);
        if (!$contact) {
            throw new NotFoundHttpException('Ссылка устарела или неверна.');
        }
        $request = \Yii::$app->request;
        $topic = Unsubscribe::topicOf($contact, $request->get('t'));
        $messageId = Unsubscribe::messageOf($contact, $request->get('m'));

        if ($request->isPost && trim((string)$request->post('List-Unsubscribe')) === 'One-Click') {
            Unsubscribe::oneClick($contact, $topic, $messageId);
            $response = \Yii::$app->response;
            $response->format = Response::FORMAT_RAW;
            $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

            return 'Вы отписаны от рассылки.';
        }

        $done = null;
        if ($request->isPost) {
            $done = Unsubscribe::save($contact, (array)$request->post('topics', []), (bool)$request->post('all'), (bool)$request->post('all_channels'), $messageId);
        }

        $site = CmsSite::findOne((int)$contact->cms_site_id);
        $state = MailingConsents::state($contact);
        $this->view->title = 'Подписка на рассылки';
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_HTML;
        $response->data = null;
        $response->content = $this->render('view', [
            'contact' => $contact,
            'topics' => CmsCommTopic::forSite((int)$contact->cms_site_id, true),
            'state' => $state,
            'topic' => $topic,
            'done' => $done,
            'hasSiblings' => count(MailingContacts::owners($contact)) > 0,
            'siteName' => $site ? (string)$site->name : '',
            // The page is light: the logo made for light backgrounds when the site has one.
            'siteLogo' => $site && ($logo = $site->logoLightImage ?: $site->image) ? (string)$logo->src : '',
            'siteUrl' => $site ? (string)$site->url : '',
            'action' => $request->url,
            'allSuppressed' => isset($state['suppressions'][CmsCommSuppression::REASON_ALL]),
        ]);

        return $response;
    }
}
