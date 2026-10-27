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
 * The rule "задача менеджеру, когда клиент ответил на рассылку" (a setting
 * of the campaign, the same rule as "задача при первом открытии" of stage 3):
 * the employee the letter was from gets a core task "Ответить: клиент
 * ответил на рассылку …" for today within working hours, otherwise for the
 * start of the nearest working period (the employee's schedule, Mon–Fri
 * 9–18 without one), bound to the lead, company or client of the recipient. Once per recipient: the first reply
 * marks it. The task on opening the offer is the offer package's rule; it
 * asks Campaigns::taskOnOpen().
 */
class CampaignTasks
{
    public const DURATION = 15 * 60;

    public const DEFAULT_SCHEDULE = [['day' => [1, 2, 3, 4, 5], 'startHour' => '09', 'startMinutes' => '00', 'endHour' => '18', 'endMinutes' => '00']];

    /** A task may not start sooner than this (CmsTask rule for plan_start_at). */
    public const MIN_LEAD_SECONDS = 31 * 60;

    public function onReply(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, CmsCommMessage $reply, ?int $now = null): ?CmsTask
    {
        $executor = $recipient->sender_user_id ? CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => (int)$recipient->sender_user_id])->one() : null;
        $record = $recipient->getRecord();
        if (!$executor || !$record) {
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
