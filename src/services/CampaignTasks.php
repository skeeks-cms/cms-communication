<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\helpers\CmsScheduleHelper;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use yii\helpers\Html;

/**
 * The rule "ответ клиента на рассылку — в сделку" (П2а, П2д). A reply of a
 * recipient (`task_on_reply`), a request from the letter (a question or an
 * acceptance on the page of its offer) and — when the campaign says so
 * (`lead_on_view`) — the first view of its presentation or offer go one way:
 * - the recipient's deal is open, or the recipient company/person has exactly
 *   one open deal — it continues: its responsible (else the sender) gets a
 *   core task "Ответить" / "Связаться" inside it for today within working
 *   hours, otherwise for the start of the nearest working period (the
 *   employee's schedule, Mon–Fri 9–18 without one); a view only writes into
 *   the deal's feed;
 * - otherwise a new deal (source mailing, "campaign-<kind>:<recipient>",
 *   the campaign letter and the reply linked): its responsible is the one the
 *   letter went from — when it went from his own mailbox (no other employee
 *   sends from it) and the client has a main responsible; a shared mailbox
 *   (promo@…) or a client of the common pool — «Неразобранное».
 * Once per recipient and kind: the source reference never makes a second
 * deal. Opening a letter or a link creates no deal; the call task on viewing
 * the offer is the offer package's rule, it asks Campaigns::taskOnOpen().
 */
class CampaignTasks
{
    public const DURATION = 15 * 60;

    public const DEFAULT_SCHEDULE = [['day' => [1, 2, 3, 4, 5], 'startHour' => '09', 'startMinutes' => '00', 'endHour' => '18', 'endMinutes' => '00']];

    /** A task may not start sooner than this (CmsTask rule for plan_start_at). */
    public const MIN_LEAD_SECONDS = 31 * 60;

    public const KIND_REPLY = 'reply';
    public const KIND_REQUEST = 'request';
    public const KIND_VIEW = 'view';

    /**
     * A reply to a campaign letter.
     *
     * @return CmsTask|CmsLead|null the task inside an open deal or the new deal
     */
    public function onReply(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, CmsCommMessage $reply, ?int $now = null)
    {
        return $this->handle($campaign, $recipient, self::KIND_REPLY, $reply, '', $now);
    }

    /**
     * A request from the letter: a question or an acceptance on the page of
     * its offer ($what — "Вопрос: …", "Принял КП №…"; $message — the incoming
     * message of the question, if any).
     *
     * @return CmsTask|CmsLead|null
     */
    public function onRequest(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, string $what, ?CmsCommMessage $message = null, ?int $now = null)
    {
        return $this->handle($campaign, $recipient, self::KIND_REQUEST, $message, $what, $now);
    }

    /**
     * The first view of the presentation or offer of the letter, when the
     * campaign creates a deal on a view (`lead_on_view`, off by default).
     *
     * @return CmsLead|null the open deal written into or the new deal
     */
    public function onView(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, ?int $now = null): ?CmsLead
    {
        if (!$campaign->hasAttribute('lead_on_view') || !$campaign->lead_on_view) {
            return null;
        }
        $result = $this->handle($campaign, $recipient, self::KIND_VIEW, null, '', $now);

        return $result instanceof CmsLead ? $result : null;
    }

    /** @return CmsTask|CmsLead|null */
    private function handle(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, string $kind, ?CmsCommMessage $message, string $what, ?int $now)
    {
        $record = $recipient->getRecord();
        if (!$record) {
            return null;
        }
        $open = $this->openLeadOf($record, (int)$campaign->cms_site_id);
        if ($open === null) {
            return $this->leadFrom($campaign, $recipient, $kind, $message, $what, $record, $now);
        }
        if ($kind === self::KIND_VIEW) {
            // A view adds to the open deal: an entry of its feed, the call task is the offer package's setting.
            if (method_exists($open, 'addSystemActivity')) {
                $open->addSystemActivity('Получатель рассылки «'.Html::encode($campaign->name).'» открыл Презентацию или КП из письма.');
            }

            return $open;
        }

        return $this->replyTask($campaign, $recipient, $kind, $message, $what, $open, $now);
    }

    /**
     * Who gets a new deal of a reply or a request: the one the letter went
     * from, when it went from his own mailbox (no other employee may send
     * from it) and the client has a main responsible. Null — «Неразобранное»:
     * a shared mailbox, a client of the common pool (П2д).
     */
    public static function senderResponsible(CmsCommCampaignRecipient $recipient, $record): ?int
    {
        $senderId = (int)$recipient->sender_user_id;
        $accountId = (int)$recipient->cms_comm_account_id;
        if (!$senderId || !$accountId) {
            return null;
        }
        $senders = array_values(array_unique(array_map('intval', CmsCommAccountAccess::find()->select('cms_user_id')
            ->andWhere(['cms_comm_account_id' => $accountId])
            ->andWhere(['<>', 'send_level', CmsCommAccountAccess::SEND_NONE])->column())));
        if ($senders !== [$senderId]) {
            return null;
        }
        if (!self::hasMainResponsible($record)) {
            return null;
        }

        return CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => $senderId, CmsUser::tableName().'.is_active' => 1])->exists()
            ? $senderId : null;
    }

    /** The recipient company or person (of a deal — its client) has a main responsible; nobody — the common pool. */
    private static function hasMainResponsible($record): bool
    {
        if ($record instanceof CmsLead) {
            $record = $record->cms_company_id ? $record->company : ($record->cms_user_id ? $record->client : null);
        }
        if (!$record instanceof CmsCompany && !$record instanceof CmsUser) {
            return false;
        }
        $access = \skeeks\cms\crm\CrmAccess::class;
        if (class_exists($access) && method_exists($access, 'mainResponsibleId')) {
            $code = $record instanceof CmsCompany ? 'company' : 'client';

            return $access::instance()->mainResponsibleId($code, (int)$record->id) !== null;
        }

        return Campaigns::responsibleOf($record) !== null;
    }

    /**
     * The open deal the reply continues: the recipient deal itself, or the
     * only open deal of the recipient company or person. Several open deals —
     * none: a person sorts the new request.
     */
    private function openLeadOf($record, int $siteId): ?CmsLead
    {
        $open = [CmsLead::STATUS_NEW, CmsLead::STATUS_IN_WORK];
        if ($record instanceof CmsLead) {
            if (in_array($record->status, $open, true)) {
                return $record;
            }
            $record = $record->cms_company_id ? $record->company : ($record->cms_user_id ? $record->client : null);
            if (!$record) {
                return null;
            }
        }
        $query = CmsLead::find()->andWhere(['status' => $open])->andFilterWhere(['cms_site_id' => $siteId ?: null]);
        if ($record instanceof CmsCompany) {
            $query->andWhere(['cms_company_id' => (int)$record->id]);
        } elseif ($record instanceof CmsUser) {
            $query->andWhere(['cms_user_id' => (int)$record->id]);
        } else {
            return null;
        }
        $leads = $query->limit(2)->all();

        return count($leads) === 1 ? $leads[0] : null;
    }

    private function leadFrom(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, string $kind, ?CmsCommMessage $message, string $what, $record, ?int $now): CmsLead
    {
        $companyId = null;
        $clientId = null;
        if ($record instanceof CmsLead) {
            $companyId = $record->cms_company_id ? (int)$record->cms_company_id : null;
            $clientId = $record->cms_user_id ? (int)$record->cms_user_id : null;
        } elseif ($record instanceof CmsCompany) {
            $companyId = (int)$record->id;
        } elseif ($record instanceof CmsUser) {
            $clientId = (int)$record->id;
        }
        $who = $recipient->name ?: (string)$recipient->address;
        $at = \Yii::$app->formatter->asDatetime(($message ? (int)$message->message_at : 0) ?: ($now ?? time()), 'php:d.m.Y в H:i');
        $texts = [
            self::KIND_REPLY => ['Ответ на рассылку: ', 'Клиент ответил на письмо рассылки «'.$campaign->name.'» '.$at.".\n".'Тема ответа: '.($message ? (string)$message->subject : '')],
            self::KIND_REQUEST => ['Заявка из рассылки: ', 'Клиент оставил заявку по письму рассылки «'.$campaign->name.'» '.$at.".\n".$what],
            self::KIND_VIEW => ['Смотрел Презентацию: ', 'Получатель рассылки «'.$campaign->name.'» открыл Презентацию или КП из письма '.$at.'.'],
        ][$kind];
        $executorId = self::senderResponsible($recipient, $record);
        $lead = (new \skeeks\cms\services\CmsLeadService())->createFromSource([
            'cms_site_id' => (int)$campaign->cms_site_id ?: null,
            'source_type' => CmsLead::SOURCE_MAILING,
            'source_ref' => 'campaign-'.$kind.':'.(int)$recipient->id,
            'source_name' => mb_substr('Рассылка: '.$campaign->name, 0, 255),
            'submitted_by_id' => $recipient->sender_user_id ? (int)$recipient->sender_user_id : null,
            'cms_company_id' => $companyId,
            'cms_user_id' => $clientId,
            'name' => mb_substr($texts[0].$who, 0, 255),
            'description' => $texts[1],
            'emails' => $recipient->address && strpos((string)$recipient->address, '@') !== false ? [(string)$recipient->address] : [],
            'source_data' => [
                'campaign_id' => (int)$campaign->id,
                'recipient_id' => (int)$recipient->id,
                'message_id' => $message ? (int)$message->id : null,
            ],
            // The sender's own client — at once his deal; otherwise «Неразобранное».
            'executor_id' => $executorId,
            'status' => $executorId ? CmsLead::STATUS_IN_WORK : CmsLead::STATUS_NEW,
        ]);
        // The history of this send: the campaign letter (with its personal offer) and the reply.
        $linker = new MessageLinker();
        if ($message) {
            $linker->link($message, [$lead], \skeeks\cms\communication\models\CmsCommLink::TYPE_CREATED);
        }
        if ($recipient->cms_comm_message_id && ($sent = CmsCommMessage::findOne((int)$recipient->cms_comm_message_id))) {
            $linker->link($sent, [$lead], \skeeks\cms\communication\models\CmsCommLink::TYPE_THREAD);
        }

        return $lead;
    }

    private function replyTask(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, string $kind, ?CmsCommMessage $message, string $what, CmsLead $record, ?int $now): ?CmsTask
    {
        // The responsible of the deal answers; the sender of the campaign when the deal has none.
        $executorId = $record->executor_id ?: $recipient->sender_user_id;
        $executor = $executorId ? CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => (int)$executorId])->one() : null;
        if (!$executor) {
            return null;
        }
        $now = $now ?? time();
        $task = new CmsTask();
        $who = $recipient->name ?: (string)$recipient->address;
        $task->name = mb_substr($kind === self::KIND_REQUEST
            ? 'Связаться: '.$who.' оставил заявку по рассылке «'.$campaign->name.'»'
            : 'Ответить: '.$who.' ответил на рассылку «'.$campaign->name.'»', 0, 255);
        $task->executor_id = (int)$executor->id;
        // A task to oneself is accepted at once; the mail receive has no signed-in user.
        $task->created_by = (int)$executor->id;
        $task->cms_lead_id = (int)$record->id;
        if ($record->cms_company_id) {
            $task->cms_company_id = (int)$record->cms_company_id;
        } elseif ($record->cms_user_id) {
            $task->cms_user_id = (int)$record->cms_user_id;
        }
        $task->plan_start_at = self::dueAt(is_array($executor->work_shedule) ? $executor->work_shedule : null, $now);
        $task->plan_duration = self::DURATION;
        $at = \Yii::$app->formatter->asDatetime(($message ? (int)$message->message_at : 0) ?: $now, 'php:d.m.Y в H:i');
        $lines = $kind === self::KIND_REQUEST
            ? ['Клиент оставил заявку по письму рассылки «'.$campaign->name.'» '.$at.'.', $what]
            : [
                'Клиент ответил на письмо рассылки «'.$campaign->name.'» '.$at.($message ? ' ('.$message->getFromAsText().')' : '').'.',
                'Тема ответа: '.($message ? (string)$message->subject : ''),
                'Ответ — в ленте сделки и в «Почте».',
            ];
        $task->description = implode('', array_map(static fn($line) => Html::tag('p', Html::encode($line)), array_filter($lines)));
        if (!$task->save()) {
            throw new \RuntimeException('Не удалось поставить задачу по ответу на рассылку: '.implode(' ', $task->getFirstErrors()));
        }
        // The blameable behavior writes the signed-in user, none in the receive job.
        if ((int)$task->created_by !== (int)$executor->id) {
            $task->updateAttributes(['created_by' => (int)$executor->id]);
        }

        return $task;
    }

    /** Today within the working hours, otherwise the start of the nearest working period; never sooner than MIN_LEAD_SECONDS. */
    public static function dueAt(?array $schedule, int $now): int
    {
        $schedule = $schedule ?: self::DEFAULT_SCHEDULE;
        $earliest = (int)(ceil(($now + self::MIN_LEAD_SECONDS) / 300) * 300);
        for ($i = 0; $i <= 31; $i++) {
            $date = date('Y-m-d', strtotime('+'.$i.' day', $now));
            $periods = CmsScheduleHelper::getSchedulesByWorktimeForDate($schedule, $date);
            usort($periods, static fn($a, $b) => (int)$a->start_at <=> (int)$b->start_at);
            foreach ($periods as $period) {
                if ((int)$period->end_at > $earliest) {
                    return max((int)$period->start_at, $earliest);
                }
            }
        }

        return $earliest;
    }
}
