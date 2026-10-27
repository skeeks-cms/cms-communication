<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\components\LinkTrackingSettings;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTrack;
use skeeks\cms\communication\models\CmsCommTrackVisit;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;
use yii\db\ActiveRecord;
use yii\helpers\Html;
use yii\helpers\Json;

/**
 * Visits of our sites by the personal code of a letter (step F), with the
 * same honest counting as the page of an offer: opening a link is not a
 * visit. The script of our site (`visit.js`, controllers\VisitController)
 * sends a signal only after the page was shown in a browser; robots (the
 * shared list LinkChecks::ROBOT_PATTERNS) and link checks of mail services
 * (LinkChecks::reason()) are kept for history and never counted; a
 * signed-in employee is not a visit.
 *
 * A counted visit gets a token the browser keeps in the cookie `sx_visit`
 * for the visit (the code itself leaves the address bar: a link copied
 * from it and forwarded is not the recipient's); the next pages of the
 * visit are added to the same row, and one feed entry of the visit shows
 * them all ("перешёл на сайт из письма от …: страница, затем …"). A visit
 * ends after IDLE seconds without pages.
 *
 * A new visit: feed entries in the cards of the recipient (CmsLog of type
 * LOG_TYPE), a notification to the employee who sent the letter, the first
 * visit by a letter marks its campaign recipient "перешёл на сайт" and,
 * by the setting, gives the sender a call task (never a second one while
 * an open "Позвонить" task on that client exists, an offer's included).
 */
class LinkVisits
{
    public const LOG_TYPE = 'comm_visit';

    public const EVENT_VISIT = 'visit';
    public const EVENT_CHECKED = 'checked';

    public const RESULT_FIRST = 'first';
    public const RESULT_COUNTED = 'counted';
    public const RESULT_CONTINUED = 'continued';
    public const RESULT_PAGE = 'page';
    public const RESULT_BOT = 'bot';
    public const RESULT_CHECK = 'check';
    public const RESULT_STAFF = 'staff';
    public const RESULT_UNKNOWN = 'unknown';
    public const RESULT_ENDED = 'ended';
    public const RESULT_FOREIGN = 'foreign';

    /** The cookie of a visit in the browser (set by the script, read by the site to load it). */
    public const COOKIE = 'sx_visit';

    /** A visit ends after so many seconds without a new page. */
    public const IDLE = 1800;

    /** Pages kept in one visit; later ones only extend it. */
    public const MAX_PAGES = 50;

    /** A call task to the sender lasts so long. */
    public const CALL_DURATION = 15 * 60;

    public static function findTrack(string $code): ?CmsCommTrack
    {
        if (!preg_match('~^'.LinkMarks::CODE_PATTERN.'$~', $code)) {
            return null;
        }

        return CmsCommTrack::find()->andWhere(['code' => $code])->one();
    }

    /** When the link reached the client: the letter left (message_at of a sent message), otherwise when it was queued. */
    public static function sentAt(CmsCommTrack $track): int
    {
        $sentAt = (int)$track->created_at;
        $message = CmsCommMessage::find()->select(['status', 'message_at'])->andWhere(['id' => (int)$track->cms_comm_message_id])->asArray()->one();
        if ($message && $message['status'] === CmsCommMessage::STATUS_SENT && (int)$message['message_at'] > $sentAt) {
            $sentAt = (int)$message['message_at'];
        }

        return $sentAt;
    }

    /**
     * The address of a page as kept in the visit: http(s), without the code;
     * null for anything else.
     */
    public static function cleanUrl(string $url): ?string
    {
        $url = trim($url);
        if (!preg_match('~^https?://[^/\s]+~i', $url) || mb_strlen($url) > 2000) {
            return null;
        }

        return mb_substr(LinkMarks::removeParam($url, LinkMarks::CODE_PARAM), 0, 1000);
    }

    /** "skeeks.com/solutions/plitka": the address of a page without the scheme and the marks utm_*. */
    public static function displayUrl(string $url): string
    {
        $query = (string)parse_url(explode('#', $url, 2)[0], PHP_URL_QUERY);
        foreach (explode('&', $query) as $pair) {
            $name = rawurldecode(explode('=', $pair, 2)[0]);
            if (stripos($name, 'utm_') === 0) {
                $url = LinkMarks::removeParam($url, $name);
            }
        }

        return rtrim((string)preg_replace('~^https?://(www\.)?~i', '', $url), '/');
    }

    /** The title of a page of a visit, otherwise its address. */
    public static function pageLabel(array $page): string
    {
        $title = trim((string)($page['title'] ?? ''));

        return $title !== '' ? $title : self::displayUrl((string)($page['url'] ?? ''));
    }

    /** "Решения для магазинов плитки, затем Портфолио, затем …": the pages of a visit in one line. */
    public static function pagesText(CmsCommTrackVisit $visit): string
    {
        $labels = array_map(static fn($page) => self::pageLabel($page).' ('.self::displayUrl((string)$page['url']).')', $visit->getPageList());
        $more = (int)$visit->pages_count - count($labels);

        return implode(', затем ', $labels).($more > 0 ? ' и ещё '.$more : '');
    }

    /** Is the address on one of the domains of the site of the code (where marked links lead). */
    public static function isOurUrl(CmsCommTrack $track, string $url): bool
    {
        return LinkMarks::isOurHost((string)parse_url($url, PHP_URL_HOST), LinkTrackingSettings::forSite((int)$track->cms_site_id)['domains']);
    }

    /**
     * The signal of the page opened by a link with the code.
     *
     * @param array       $signals of the page script for LinkChecks: webdriver, languages, width, height
     * @param string|null $token   the visit this browser is in (cookie of the site)
     * @return array result (RESULT_*), token of the counted visit
     */
    public function seen(CmsCommTrack $track, string $url, string $title, string $ip, string $userAgent, array $signals = [],
        ?string $token = null, bool $isStaff = false, ?int $now = null): array
    {
        if ($isStaff) {
            return ['result' => self::RESULT_STAFF];
        }
        $now = $now ?? time();
        $url = self::cleanUrl($url);
        if ($url === null || !self::isOurUrl($track, $url)) {
            return ['result' => self::RESULT_FOREIGN];
        }
        $page = ['url' => $url, 'title' => mb_substr(trim($title), 0, 200), 'at' => $now];
        $userAgent = mb_substr($userAgent, 0, 500);
        $ip = mb_substr($ip, 0, 45);

        $bot = LinkChecks::isRobot($userAgent);
        $check = $bot ? null : LinkChecks::reason(self::sentAt($track), $ip, $userAgent, $signals, $now);
        if ($bot || $check !== null) {
            return ['result' => $this->recordUncounted($track, $page, $ip, $userAgent, $bot, $check, $now)];
        }

        // The same browser in a visit by this letter: another link of the letter, a reload, a second tab.
        $visit = $token !== null && $token !== '' ? CmsCommTrackVisit::find()->andWhere(['token' => $token, 'cms_comm_track_id' => (int)$track->id])->one() : null;
        if (!$visit || (int)$visit->last_at < $now - self::IDLE) {
            // The cookie may be blocked: the same browser and address within the visit time.
            $visit = CmsCommTrackVisit::find()->andWhere([
                'cms_comm_track_id' => (int)$track->id, 'ip' => $ip, 'user_agent' => $userAgent, 'is_bot' => 0, 'check_reason' => null,
            ])->andWhere(['not', ['token' => null]])->andWhere(['>=', 'last_at', $now - self::IDLE])->orderBy(['id' => SORT_DESC])->one();
        }
        if ($visit) {
            $this->addPage($visit, $page, $now);

            return ['result' => self::RESULT_CONTINUED, 'token' => (string)$visit->token];
        }

        [$visit, $first] = CmsCommTrackVisit::getDb()->transaction(function () use ($track, $page, $ip, $userAgent, $now) {
            $visit = new CmsCommTrackVisit([
                'cms_comm_track_id' => (int)$track->id,
                'token' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
                'ip' => $ip,
                'user_agent' => $userAgent,
                'is_bot' => 0,
                'host' => mb_substr((string)parse_url($page['url'], PHP_URL_HOST), 0, 255),
                'pages' => Json::encode([$page]),
                'pages_count' => 1,
                'last_at' => $now,
            ]);
            if (!$visit->save(false)) {
                throw new \RuntimeException('Не удалось записать переход по ссылке из письма.');
            }
            CmsCommTrack::updateAllCounters(['visits_count' => 1], ['id' => (int)$track->id]);
            CmsCommTrack::updateAll(['last_visit_at' => $now], ['id' => (int)$track->id]);
            // Two tabs at once: only one of them is the first visit.
            $first = CmsCommTrack::updateAll(['first_visit_at' => $now], ['id' => (int)$track->id, 'first_visit_at' => null]) > 0;
            $this->writeFeed($track, self::EVENT_VISIT, ['visit' => (int)$visit->id], $now);
            if ($first && $track->cms_comm_campaign_id) {
                CmsCommCampaignRecipient::updateAll(['visited_at' => $now, 'updated_at' => $now],
                    ['cms_comm_message_id' => (int)$track->cms_comm_message_id, 'visited_at' => null]);
            }

            return [$visit, $first];
        });
        $track->refresh();

        // The visit stays recorded whatever happens to the notification and the task: the client must not see an error.
        try {
            $this->notify($track, $visit, $first);
        } catch (\Throwable $e) {
            \Yii::error('Уведомление о переходе из письма не отправлено: '.$e->getMessage(), self::class);
        }
        if ($first) {
            try {
                // "Вам поставлена новая задача" of the call task is one entry with the visit.
                \Yii::$app->notifications->group('link-visit:'.(int)$track->id.':'.(int)$visit->id, fn() => $this->callTask($track, $now));
            } catch (\Throwable $e) {
                \Yii::error('Задача по переходу из письма не поставлена: '.$e->getMessage(), self::class);
            }
        }

        return ['result' => $first ? self::RESULT_FIRST : self::RESULT_COUNTED, 'token' => (string)$visit->token];
    }

    /**
     * The next page of a visit (the browser has its token).
     *
     * @return array result: page, ended (the visit is over: the browser forgets it), unknown, foreign
     */
    public function page(string $token, string $url, string $title, ?int $now = null): array
    {
        $now = $now ?? time();
        $visit = $token !== '' ? CmsCommTrackVisit::find()->andWhere(['token' => $token])->one() : null;
        if (!$visit || !$visit->track) {
            return ['result' => self::RESULT_UNKNOWN];
        }
        if ((int)$visit->last_at < $now - self::IDLE) {
            return ['result' => self::RESULT_ENDED];
        }
        $url = self::cleanUrl($url);
        if ($url === null || !self::isOurUrl($visit->track, $url)) {
            return ['result' => self::RESULT_FOREIGN];
        }
        $this->addPage($visit, ['url' => $url, 'title' => mb_substr(trim($title), 0, 200), 'at' => $now], $now);

        return ['result' => self::RESULT_PAGE];
    }

    /** A new page of the visit; a reload of the last page only extends it. */
    private function addPage(CmsCommTrackVisit $visit, array $page, int $now): void
    {
        $pages = $visit->getPageList();
        $last = end($pages);
        if ($pages && $last && (string)$last['url'] === $page['url']) {
            $visit->last_at = $now;
            $visit->save(false, ['last_at', 'updated_at']);
            return;
        }
        if (count($pages) < self::MAX_PAGES) {
            $pages[] = $page;
            $visit->pages = Json::encode($pages);
        }
        $visit->pages_count = (int)$visit->pages_count + 1;
        $visit->last_at = $now;
        $visit->save(false, ['pages', 'pages_count', 'last_at', 'updated_at']);
    }

    /** A robot or a link check: kept once per browser within the window, the check written to the feed once per letter. */
    private function recordUncounted(CmsCommTrack $track, array $page, string $ip, string $userAgent, bool $bot, ?string $check, int $now): string
    {
        $result = $bot ? self::RESULT_BOT : self::RESULT_CHECK;
        $recent = CmsCommTrackVisit::find()->andWhere([
            'cms_comm_track_id' => (int)$track->id, 'ip' => $ip, 'user_agent' => $userAgent, 'is_bot' => $bot ? 1 : 0,
        ])->andWhere($check === null ? ['check_reason' => null] : ['not', ['check_reason' => null]])
            ->andWhere(['>=', 'created_at', $now - self::IDLE])->exists();
        if ($recent) {
            return $result;
        }
        $visit = new CmsCommTrackVisit([
            'cms_comm_track_id' => (int)$track->id,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'is_bot' => $bot ? 1 : 0,
            'check_reason' => $check,
            'host' => mb_substr((string)parse_url($page['url'], PHP_URL_HOST), 0, 255),
            'pages' => Json::encode([$page]),
            'pages_count' => 1,
            'last_at' => $now,
        ]);
        if (!$visit->save(false)) {
            throw new \RuntimeException('Не удалось записать открытие ссылки из письма.');
        }
        if ($check !== null) {
            // Once per letter in the feed: several checkers open one letter.
            $earlier = CmsCommTrackVisit::find()->andWhere(['cms_comm_track_id' => (int)$track->id])
                ->andWhere(['not', ['check_reason' => null]])->andWhere(['<', 'id', (int)$visit->id])->exists();
            if (!$earlier) {
                $this->writeFeed($track, self::EVENT_CHECKED, ['visit' => (int)$visit->id, 'reason' => $check], $now);
            }
        }

        return $result;
    }

    /**
     * Whose cards show the visit: the CRM record of the recipient (and the
     * company of a lead), otherwise the leads, companies and clients the
     * letter is linked to.
     *
     * @return ActiveRecord[]
     */
    public static function records(CmsCommTrack $track): array
    {
        $records = [];
        if ($record = $track->getRecord()) {
            $records[] = $record;
            if ($record instanceof CmsLead && $record->cms_company_id && ($company = CmsCompany::findOne((int)$record->cms_company_id))) {
                $records[] = $company;
            }
            return $records;
        }
        $links = CmsCommLink::find()->andWhere(['cms_comm_message_id' => (int)$track->cms_comm_message_id,
            'model_code' => [CmsLead::class, CmsCompany::class, CmsUser::class]])->all();
        foreach ($links as $link) {
            /** @var ActiveRecord $class */
            $class = (string)$link->model_code;
            if (($record = $class::findOne((int)$link->model_id)) && !($record instanceof CmsUser && $record->is_worker)) {
                $records[get_class($record).':'.$record->primaryKey] = $record;
            }
        }

        return array_values($records);
    }

    /** @return CmsLog[] */
    private function writeFeed(CmsCommTrack $track, string $event, array $data, int $now): array
    {
        $result = [];
        foreach (self::records($track) as $record) {
            $log = new CmsLog();
            $log->model_code = get_class($record);
            $log->model_id = (int)$record->primaryKey;
            $log->model_as_text = method_exists($record, 'asText') ? mb_substr((string)$record->asText(), 0, 255) : null;
            $log->log_type = self::LOG_TYPE;
            $log->comment = $event === self::EVENT_CHECKED ? 'Возможно, проверка ссылок' : 'Перешёл на сайт из письма';
            $log->data = array_merge($data, ['event' => $event, 'track' => (int)$track->id, 'message' => (int)$track->cms_comm_message_id]);
            if ($record instanceof CmsCompany) {
                $log->cms_company_id = (int)$record->id;
            } elseif ($record instanceof CmsUser) {
                $log->cms_user_id = (int)$record->id;
            }
            if (!$log->save(false)) {
                throw new \RuntimeException('Не удалось записать переход в ленту.');
            }
            $result[] = $log;
        }

        return $result;
    }

    /** The employee who sent the letter, if they still work here. */
    public static function senderOf(CmsCommTrack $track): ?CmsUser
    {
        $id = (int)CmsCommMessage::find()->select('sent_by_user_id')->andWhere(['id' => (int)$track->cms_comm_message_id])->scalar();

        return $id ? CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => $id])->one() : null;
    }

    /** "Иван Петров перешёл на сайт из письма от 24.10.2026" to the sender, opening the card of the client. */
    private function notify(CmsCommTrack $track, CmsCommTrackVisit $visit, bool $first): ?CmsWebNotify
    {
        $sender = self::senderOf($track);
        if (!$sender) {
            return null;
        }
        $record = self::records($track)[0] ?? null;
        $who = $record ? CrmRecords::name($record) : (string)$track->recipient;
        $message = $track->message;
        $date = \Yii::$app->formatter->asDate((int)($message->message_at ?? $track->created_at), 'php:d.m.Y');
        $page = $visit->getPageList()[0] ?? null;
        $data = [
            'name' => ($who !== '' ? $who.': к' : 'К').'лиент '.($first ? '' : 'снова ').'перешёл на сайт из письма от '.$date,
            'comment' => mb_substr(($message && $message->subject ? 'Письмо «'.$message->subject.'». ' : '')
                .($page ? 'Страница: '.((string)$page['title'] !== '' ? $page['title'].' — ' : '').$page['url'] : ''), 0, 1000),
            'eventKey' => 'link.visit:'.(int)$visit->id,
        ];
        if ($record) {
            $data['model_code'] = get_class($record);
            $data['model_id'] = (int)$record->primaryKey;
        } elseif ($message) {
            $data['model_code'] = CmsCommMessage::class;
            $data['model_id'] = (int)$message->id;
        }
        // The call task of a first visit (callTask() in the same group) joins this notification in one entry.
        $rows = \Yii::$app->notifications->notify((int)$sender->id, 'link.visit', $data + ['groupKey' => 'link-visit:'.(int)$track->id.':'.(int)$visit->id]);

        return $rows[0] ?? null;
    }

    /**
     * The call task at the first visit by a letter: by the campaign setting
     * for a campaign letter, otherwise by LinkTrackingSettings::$taskOnVisit.
     */
    public function callTask(CmsCommTrack $track, ?int $now = null): ?CmsTask
    {
        $byCampaign = Campaigns::taskOnOpen((int)$track->cms_comm_message_id);
        if ($byCampaign === false || ($byCampaign === null && !LinkTrackingSettings::forSite((int)$track->cms_site_id)['taskOnVisit'])) {
            return null;
        }
        $executor = self::senderOf($track);
        $record = self::records($track)[0] ?? null;
        if (!$executor || !$record || $this->openCallTask((int)$executor->id, $record)) {
            return null;
        }
        $now = $now ?? time();
        $task = new CmsTask();
        $who = CrmRecords::name($record);
        $task->name = mb_substr('Позвонить: клиент перешёл на сайт из письма'.($who !== '' ? ' — '.$who : ''), 0, 255);
        $task->executor_id = (int)$executor->id;
        // A task to oneself is accepted at once; the site has no signed-in user.
        $task->created_by = (int)$executor->id;
        self::fillClient($task, $record);
        $task->plan_start_at = CampaignTasks::dueAt(is_array($executor->work_shedule) ? $executor->work_shedule : null, $now);
        $task->plan_duration = self::CALL_DURATION;
        $message = $track->message;
        $visit = CmsCommTrackVisit::find()->andWhere(['cms_comm_track_id' => (int)$track->id, 'is_bot' => 0, 'check_reason' => null])
            ->andWhere(['not', ['token' => null]])->orderBy(['id' => SORT_ASC])->one();
        $page = $visit ? ($visit->getPageList()[0] ?? null) : null;
        $lines = array_filter([
            'Клиент перешёл на сайт по ссылке из письма'.($message && $message->subject ? ' «'.$message->subject.'»' : '')
                .' '.\Yii::$app->formatter->asDatetime($now, 'php:d.m.Y в H:i').($track->recipient ? ' ('.$track->recipient.')' : '').'.',
            $page ? 'Страница: '.((string)$page['title'] !== '' ? $page['title'].' — ' : '').$page['url'] : null,
            ($record instanceof CmsLead && ($phone = $record->mainPhone)) ? 'Телефон: '.$phone->value : null,
            'Что он смотрел дальше — в ленте карточки. Позвоните, пока интерес свежий.',
        ]);
        $task->description = implode('', array_map(static fn($line) => Html::tag('p', Html::encode($line)), $lines));
        if (!$task->save()) {
            throw new \RuntimeException('Не удалось поставить задачу по переходу из письма: '.implode(' ', $task->getFirstErrors()));
        }
        // The blameable behavior writes the signed-in user, none on the site.
        if ((int)$task->created_by !== (int)$executor->id) {
            $task->updateAttributes(['created_by' => (int)$executor->id]);
        }

        return $task;
    }

    /**
     * An open "Позвонить" task of the employee on this client: a call task of
     * an opened offer or of an earlier visit. A new one is not made.
     */
    public function openCallTask(int $executorId, ActiveRecord $record): ?CmsTask
    {
        $t = CmsTask::tableName();
        $query = CmsTask::find()->andWhere([$t.'.executor_id' => $executorId, $t.'.status' => CmsTask::activeStatuses()])
            ->andWhere(['like', $t.'.name', 'Позвонить%', false]);
        if ($record instanceof CmsLead) {
            $query->andWhere([$t.'.cms_lead_id' => (int)$record->id]);
        } elseif ($record instanceof CmsCompany) {
            $query->andWhere([$t.'.cms_company_id' => (int)$record->id]);
        } elseif ($record instanceof CmsUser) {
            $query->andWhere([$t.'.cms_user_id' => (int)$record->id]);
        } else {
            return null;
        }

        return $query->limit(1)->one();
    }

    private static function fillClient(CmsTask $task, ActiveRecord $record): void
    {
        if ($record instanceof CmsLead) {
            $task->cms_lead_id = (int)$record->id;
            if ($record->cms_company_id) {
                $task->cms_company_id = (int)$record->cms_company_id;
            } elseif ($record->cms_user_id) {
                $task->cms_user_id = (int)$record->cms_user_id;
            }
        } elseif ($record instanceof CmsCompany) {
            $task->cms_company_id = (int)$record->id;
        } elseif ($record instanceof CmsUser) {
            $task->cms_user_id = (int)$record->id;
        }
    }
}
