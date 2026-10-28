<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\helpers\CmsScheduleHelper;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use yii\helpers\Html;

/**
 * The rule "ответ клиента на рассылку — в сделку" (a setting of the campaign,
 * `task_on_reply`). A reply is a request (П2а): when the recipient's deal is
 * open, or the recipient company/person has exactly one open deal, its
 * responsible (else the sender) gets a core task "Ответить: клиент ответил на
 * рассылку …" inside that deal for today within working hours, otherwise for
 * the start of the nearest working period (the employee's schedule, Mon–Fri
 * 9–18 without one). Otherwise the reply becomes a deal in «Неразобранное»
 * (source mailing, "campaign-reply:<recipient>") with the campaign letter and
 * the reply linked. Once per recipient: the first reply marks it. Opening a
 * letter, a link or the offer creates no deal: the task on opening the offer
 * is the offer package's rule; it asks Campaigns::taskOnOpen().
 */
class CampaignTasks
{
    public const DURATION = 15 * 60;

    public const DEFAULT_SCHEDULE = [['day' => [1, 2, 3, 4, 5], 'startHour' => '09', 'startMinutes' => '00', 'endHour' => '18', 'endMinutes' => '00']];

    /** A task may not start sooner than this (CmsTask rule for plan_start_at). */
    public const MIN_LEAD_SECONDS = 31 * 60;

    /**
     * A reply to a campaign is a request (П2а): it continues the open deal of
     * the recipient — a task "Ответить" inside it — or becomes a new deal in
     * «Неразобранное» with the source "Рассылка: <campaign>" and the campaign
     * letter and the reply linked to it. The same reply (or a second one of
     * the recipient) never makes a second deal: source mailing +
     * "campaign-reply:<recipient>".
     *
     * @return CmsTask|CmsLead|null the task inside an open deal or the new deal
     */
    public function onReply(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, CmsCommMessage $reply, ?int $now = null)
    {
        $record = $recipient->getRecord();
        if (!$record) {
            return null;
        }
        $open = $this->openLeadOf($record, (int)$campaign->cms_site_id);
        if ($open === null) {
            return $this->leadFromReply($campaign, $recipient, $reply, $record);
        }

        return $this->replyTask($campaign, $recipient, $reply, $open, $now);
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
        if ($record instanceof \skeeks\cms\models\CmsCompany) {
            $query->andWhere(['cms_company_id' => (int)$record->id]);
        } elseif ($record instanceof CmsUser) {
            $query->andWhere(['cms_user_id' => (int)$record->id]);
        } else {
            return null;
        }
        $leads = $query->limit(2)->all();

        return count($leads) === 1 ? $leads[0] : null;
    }

    private function leadFromReply(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, CmsCommMessage $reply, $record): CmsLead
    {
        $companyId = null;
        $clientId = null;
        if ($record instanceof CmsLead) {
            $companyId = $record->cms_company_id ? (int)$record->cms_company_id : null;
            $clientId = $record->cms_user_id ? (int)$record->cms_user_id : null;
        } elseif ($record instanceof \skeeks\cms\models\CmsCompany) {
            $companyId = (int)$record->id;
        } elseif ($record instanceof CmsUser) {
            $clientId = (int)$record->id;
        }
        $who = $recipient->name ?: (string)$recipient->address;
        $lead = (new \skeeks\cms\services\CmsLeadService())->createFromSource([
            'cms_site_id' => (int)$campaign->cms_site_id ?: null,
            'source_type' => CmsLead::SOURCE_MAILING,
            'source_ref' => 'campaign-reply:'.(int)$recipient->id,
            'source_name' => mb_substr('Рассылка: '.$campaign->name, 0, 255),
            'submitted_by_id' => $recipient->sender_user_id ? (int)$recipient->sender_user_id : null,
            'cms_company_id' => $companyId,
            'cms_user_id' => $clientId,
            'name' => mb_substr('Ответ на рассылку: '.$who, 0, 255),
            'description' => 'Клиент ответил на письмо рассылки «'.$campaign->name.'» '
                .\Yii::$app->formatter->asDatetime((int)$reply->message_at ?: time(), 'php:d.m.Y в H:i').".\n"
                .'Тема ответа: '.(string)$reply->subject,
            'emails' => $recipient->address && strpos((string)$recipient->address, '@') !== false ? [(string)$recipient->address] : [],
            'source_data' => [
                'campaign_id' => (int)$campaign->id,
                'recipient_id' => (int)$recipient->id,
                'message_id' => (int)$reply->id,
            ],
            'status' => CmsLead::STATUS_NEW,
        ]);
        // The history of this send: the campaign letter (with its personal offer) and the reply.
        $linker = new MessageLinker();
        $linker->link($reply, [$lead], \skeeks\cms\communication\models\CmsCommLink::TYPE_CREATED);
        if ($recipient->cms_comm_message_id && ($sent = CmsCommMessage::findOne((int)$recipient->cms_comm_message_id))) {
            $linker->link($sent, [$lead], \skeeks\cms\communication\models\CmsCommLink::TYPE_THREAD);
        }

        return $lead;
    }

    private function replyTask(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, CmsCommMessage $reply, CmsLead $record, ?int $now): ?CmsTask
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
        $task->name = mb_substr('Ответить: '.$who.' ответил на рассылку «'.$campaign->name.'»', 0, 255);
        $task->executor_id = (int)$executor->id;
        // A task to oneself is accepted at once; the mail receive has no signed-in user.
        $task->created_by = (int)$executor->id;
        if ($record instanceof CmsLead) {
            $task->cms_lead_id = (int)$record->id;
            if ($record->cms_company_id) {
                $task->cms_company_id = (int)$record->cms_company_id;
            } elseif ($record->cms_user_id) {
                $task->cms_user_id = (int)$record->cms_user_id;
            }
        } elseif ($record instanceof \skeeks\cms\models\CmsCompany) {
            $task->cms_company_id = (int)$record->id;
        } elseif ($record instanceof CmsUser) {
            $task->cms_user_id = (int)$record->id;
        }
        $task->plan_start_at = self::dueAt(is_array($executor->work_shedule) ? $executor->work_shedule : null, $now);
        $task->plan_duration = self::DURATION;
        $lines = [
            'Клиент ответил на письмо рассылки «'.$campaign->name.'» '.\Yii::$app->formatter->asDatetime((int)$reply->message_at ?: $now, 'php:d.m.Y в H:i')
                .' ('.$reply->getFromAsText().').',
            'Тема ответа: '.(string)$reply->subject,
            'Ответ — в ленте сделки и в «Почте».',
        ];
        $task->description = implode('', array_map(static fn($line) => Html::tag('p', Html::encode($line)), $lines));
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
