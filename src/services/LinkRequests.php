<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommTrack;
use skeeks\cms\communication\models\CmsCommTrackVisit;
use skeeks\cms\crm\LeadOriginEvent;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\services\CmsLeadService;
use yii\base\Event;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * A request of our site (any form) left within a visit by a link of a CRM
 * letter: the browser keeps the visit token in the cookie `sx_visit`
 * (LinkVisits::COOKIE). The core asks where a new request came from
 * (CmsLeadService::EVENT_LEAD_ORIGIN, crm\LeadOriginEvent); this answers by
 * the visit:
 *
 * - the client of the letter (LinkVisits::records(), the first one): its
 *   company or person, whatever phone and email the request has; the core
 *   gives the deal to the main responsible of that client, otherwise to the
 *   employee who sent the letter;
 * - a letter of an open deal: the request goes on in that deal;
 * - a campaign letter with requests on (`task_on_reply`): the request of the
 *   campaign (CampaignTasks::onRequest(), KIND_REQUEST) — the deal by the
 *   campaign rules, once per recipient (`campaign-request:<recipient>`); the
 *   request of the form is added to it.
 *
 * After saving: an entry "Заявка после перехода из письма от … («тема»)" with
 * the pages of the visit and the letter in the feed of the deal, and the open
 * call task of the visit (LinkVisits::callTask()) of its responsible or of
 * the sender on that client is linked to the deal instead of a second one.
 *
 * The visit: the cookie of a visit going on (LinkVisits::IDLE), otherwise the
 * counted visit the browser session remembered (rememberVisit(), within
 * SESSION_LIFETIME): a form on another page or after the visit ended still
 * binds. Neither, a robot or a link check — the request is routed as before.
 * Requests that are not a visitor's (a mailing, an import, a call, a
 * letter, the API, an employee's form) are not asked about.
 */
class LinkRequests
{
    /** Sources of requests that never come from the browser of a visitor. */
    public const NOT_VISITOR = [
        CmsLead::SOURCE_MANUAL, CmsLead::SOURCE_PARTNER, CmsLead::SOURCE_VK, CmsLead::SOURCE_TELEGRAM,
        CmsLead::SOURCE_PHONE, CmsLead::SOURCE_IMPORT, CmsLead::SOURCE_API, CmsLead::SOURCE_EMAIL,
        CmsLead::SOURCE_MAX, CmsLead::SOURCE_MAILING,
    ];

    /** The session key of the counted visit of this browser (rememberVisit()). */
    public const SESSION_KEY = 'cmsCommunication.linkVisit';

    /** A visit remembered by the session binds requests within so long after its last page. */
    public const SESSION_LIFETIME = 24 * 3600;

    /** The campaign request is being created: its own deal is not a visitor's request. */
    private static bool $busy = false;

    /** The core with the origin event (skeeks/cms 6.4.9.83); an older core — nothing. */
    public static function register(): void
    {
        if (!defined(CmsLeadService::class.'::EVENT_LEAD_ORIGIN') || !class_exists(LeadOriginEvent::class)) {
            return;
        }
        Event::on(CmsLeadService::class, CmsLeadService::EVENT_LEAD_ORIGIN, [self::class, 'onOrigin']);
        Event::on(CmsLeadService::class, CmsLeadService::EVENT_LEAD_ORIGIN_SAVED, [self::class, 'onSaved']);
    }

    /** The counted visit of this browser that is still going on (cookie `sx_visit`). */
    public static function currentVisit(?int $now = null): ?CmsCommTrackVisit
    {
        if (!\Yii::$app->request instanceof \yii\web\Request) {
            return null;
        }
        $token = $_COOKIE[LinkVisits::COOKIE] ?? null;
        if (!is_string($token) || !preg_match('~^[A-Za-z0-9_-]{20,48}$~', $token)) {
            return null;
        }
        $now = $now ?? time();

        return CmsCommTrackVisit::find()->andWhere(['token' => $token, 'is_bot' => 0, 'check_reason' => null])
            ->andWhere(['>=', 'last_at', $now - LinkVisits::IDLE])->one();
    }

    /**
     * A counted visit on this site (controllers\VisitController::actionSeen()): the session of the browser keeps
     * its token, so a form on any later page binds the request even when the visit cookie is gone (the visit
     * ended, the cookie was cleared). Only a counted visit gets a token: a robot or a link check never does,
     * and another browser has another session. The code of the letter itself never leaves the CRM.
     */
    public static function rememberVisit(string $token): void
    {
        if (!preg_match('~^[A-Za-z0-9_-]{20,48}$~', $token) || !\Yii::$app->has('session')) {
            return;
        }
        try {
            \Yii::$app->session->set(self::SESSION_KEY, ['token' => $token, 'at' => time()]);
        } catch (\Throwable $e) {
            \Yii::error('Визит по ссылке из письма не запомнен в сессии: '.$e->getMessage(), self::class);
        }
    }

    /** The counted visit this browser session remembered (rememberVisit()), last seen within SESSION_LIFETIME. */
    public static function sessionVisit(?int $now = null): ?CmsCommTrackVisit
    {
        if (!\Yii::$app->request instanceof \yii\web\Request || !\Yii::$app->has('session')) {
            return null;
        }
        $session = \Yii::$app->session;
        if (!$session->getIsActive() && !$session->getHasSessionId()) {
            return null;
        }
        $kept = $session->get(self::SESSION_KEY);
        $token = is_array($kept) ? ($kept['token'] ?? null) : null;
        if (!is_string($token) || !preg_match('~^[A-Za-z0-9_-]{20,48}$~', $token)) {
            return null;
        }
        $now = $now ?? time();

        return CmsCommTrackVisit::find()->andWhere(['token' => $token, 'is_bot' => 0, 'check_reason' => null])
            ->andWhere(['>=', 'last_at', $now - self::SESSION_LIFETIME])->one();
    }

    public static function onOrigin(LeadOriginEvent $event): void
    {
        $lead = $event->lead;
        if (self::$busy || !$lead || in_array((string)$lead->source_type, self::NOT_VISITOR, true)) {
            return;
        }
        // A signed-in employee filling a form of the site is not the client of the letter.
        if (\Yii::$app->has('user', true) && !\Yii::$app->user->isGuest && \Yii::$app->user->identity instanceof CmsUser && \Yii::$app->user->identity->is_worker) {
            return;
        }
        try {
            $visit = self::currentVisit() ?: self::sessionVisit();
            $track = $visit ? $visit->track : null;
            $record = $track ? (LinkVisits::records($track)[0] ?? null) : null;
        } catch (\Throwable $e) {
            if ($e instanceof \yii\db\Exception && CmsLeadService::isLockConflict($e)) {
                throw $e;
            }
            \Yii::error('Заявка: переход из письма не прочитан: '.$e->getMessage(), self::class);
            return;
        }
        if (!$record) {
            return;
        }
        $sender = LinkVisits::senderOf($track);
        $event->reason = 'Заявка пришла после перехода по ссылке из письма от '.self::letterTitle($track);
        $event->executorId = $sender ? (int)$sender->id : null;
        $event->executorRole = 'сотруднику, отправившему письмо';
        $event->origin['linkVisit'] = ['visit' => (int)$visit->id, 'track' => (int)$track->id];

        try {
            $deal = self::campaignDeal($track, $lead);
        } catch (\Throwable $e) {
            // A deadlock or a lock wait rolled the whole transaction back: the owner of the creation repeats it.
            if ($e instanceof \yii\db\Exception && CmsLeadService::isLockConflict($e)) {
                throw $e;
            }
            // Any other failure: the request of the visitor is saved anyway, as a request of the client of the letter.
            \Yii::error('Заявка по рассылке после перехода из письма не создана: '.$e->getMessage(), self::class);
            $deal = null;
        }
        if ($deal) {
            // The campaign has told its responsible (a new deal or a task "Связаться").
            $event->continueLead = $deal;
            $event->notifyContinue = false;
            return;
        }
        if ($record instanceof CmsLead) {
            $open = $record->resolveMerged();
            if (in_array($open->status, [CmsLead::STATUS_NEW, CmsLead::STATUS_IN_WORK], true)) {
                $event->continueLead = $open;
                return;
            }
            $event->companyId = $open->cms_company_id ? (int)$open->cms_company_id : null;
            $event->clientId = $open->cms_user_id ? (int)$open->cms_user_id : null;
        } elseif ($record instanceof CmsCompany) {
            $event->companyId = (int)$record->id;
        } elseif ($record instanceof CmsUser) {
            $event->clientId = (int)$record->id;
        }
    }

    public static function onSaved(LeadOriginEvent $event): void
    {
        $ids = $event->origin['linkVisit'] ?? null;
        $deal = $event->target;
        if (!$ids || !$deal) {
            return;
        }
        // The request is saved whatever happens to the entry and the task: the visitor must not see an error.
        try {
            self::writeVisit($deal, (int)$ids['visit'], (int)$ids['track']);
        } catch (\Throwable $e) {
            \Yii::error('Заявка после перехода из письма: запись в ленту не сделана: '.$e->getMessage(), self::class);
        }
    }

    /** "Заявка после перехода из письма от …": the pages of the visit, the letter, the linked call task. */
    private static function writeVisit(CmsLead $deal, int $visitId, int $trackId): void
    {
        $visit = CmsCommTrackVisit::findOne($visitId);
        $track = CmsCommTrack::findOne($trackId);
        if (!$visit || !$track) {
            return;
        }
        $text = 'Заявка после перехода из письма от '.Html::encode(self::letterTitle($track));
        $pages = LinkVisits::pagesText($visit);
        if ($pages !== '') {
            $text .= ': смотрел '.Html::encode($pages);
        }
        // The feed keeps only absolute links (cms helpers\CommentHtml).
        $text .= '. '.Html::a('Письмо', Url::to(['/cmsCommunication/admin-cms-comm-message/view', 'pk' => (int)$track->cms_comm_message_id], true)).'.';
        if ($task = self::linkCallTask($track, $deal)) {
            $text .= ' Задача «'.Html::encode((string)$task->name).'» привязана к этой сделке.';
        }
        $deal->addSystemActivity($text);
        // The letter belongs to the deal now: it is in the correspondence of the deal card.
        if ($track->message) {
            (new MessageLinker())->link($track->message, [$deal], CmsCommLink::TYPE_THREAD);
        }
    }

    /** "02.11.2026 («Сайт по 152-ФЗ»)": the letter of the link. */
    public static function letterTitle(CmsCommTrack $track): string
    {
        $message = $track->message;
        $date = \Yii::$app->formatter->asDate((int)(($message ? $message->message_at : null) ?: $track->created_at), 'php:d.m.Y');

        return $date.($message && trim((string)$message->subject) !== '' ? ' («'.trim((string)$message->subject).'»)' : '');
    }

    /**
     * A campaign letter: the request of the campaign (CampaignTasks::onRequest) — its new deal, or the open
     * deal it gave a task in. Null — not a campaign letter, requests are off, no deal.
     */
    private static function campaignDeal(CmsCommTrack $track, CmsLead $request): ?CmsLead
    {
        if (!$track->cms_comm_campaign_id) {
            return null;
        }
        $recipient = CmsCommCampaignRecipient::find()->andWhere(['cms_comm_message_id' => (int)$track->cms_comm_message_id])->one();
        $campaign = $recipient ? $recipient->campaign : null;
        if (!$campaign || !$campaign->task_on_reply) {
            return null;
        }
        $what = 'Заявка на сайте: '.trim((string)$request->name);
        $description = trim((string)$request->description);
        if ($description !== '') {
            $what .= "\n".mb_substr($description, 0, 2000);
        }
        self::$busy = true;
        try {
            $result = (new CampaignTasks())->onRequest($campaign, $recipient, $what);
        } finally {
            self::$busy = false;
        }
        if ($result instanceof CmsLead) {
            return $result;
        }

        return $result instanceof CmsTask && $result->cms_lead_id ? CmsLead::findOne((int)$result->cms_lead_id) : null;
    }

    /**
     * The open call task of the visit (LinkVisits::callTask()) on the client of the letter — of the
     * responsible of the deal, else of the sender — goes into the deal instead of staying apart.
     */
    private static function linkCallTask(CmsCommTrack $track, CmsLead $deal): ?CmsTask
    {
        $record = LinkVisits::records($track)[0] ?? null;
        if (!$record) {
            return null;
        }
        $sender = LinkVisits::senderOf($track);
        $executors = array_values(array_unique(array_filter([(int)$deal->executor_id, $sender ? (int)$sender->id : 0])));
        $visits = new LinkVisits();
        foreach ($executors as $executorId) {
            $task = $visits->openCallTask($executorId, $record, LinkVisits::CALL_TASK_NAME);
            if (!$task) {
                continue;
            }
            $link = ['cms_lead_id' => (int)$deal->id];
            if (!$task->cms_company_id && !$task->cms_user_id) {
                $link += array_filter(['cms_company_id' => (int)$deal->cms_company_id, 'cms_user_id' => (int)$deal->cms_user_id]);
            }
            if ((int)$task->cms_lead_id !== (int)$deal->id) {
                $task->updateAttributes($link);
            }

            return $task;
        }

        return null;
    }
}
