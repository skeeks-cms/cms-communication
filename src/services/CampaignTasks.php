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
 * The flow of a campaign (П2а, П2д: a bot through MCP/API, a mailing, a
 * mailing service — always a campaign) makes deals. A reply of a recipient
 * and a request from the letter (a question or an acceptance on the page of
 * its offer; `task_on_reply`) always; the first view of its presentation or
 * offer (`lead_on_view`) and the first visit by a link (`lead_on_visit`) when
 * the campaign says so. Opening a letter is only a report mark.
 * - The recipient's deal is open, or the recipient company/person has exactly
 *   one open deal — it continues: its responsible (else the sender) gets a
 *   core task "Ответить" / "Связаться" inside it for today within working
 *   hours, otherwise for the start of the nearest working period (the
 *   employee's schedule, Mon–Fri 9–18 without one); a view or a visit only
 *   writes into the deal's feed. The deal moves forward to the stage of the
 *   event when it is in the campaign pipeline.
 * - Otherwise a new deal (source mailing, "campaign-<kind>:<recipient>",
 *   the campaign letter and the reply linked) in the campaign pipeline
 *   (empty — «Исходящие продажи», core OutboundSales) at the stage of the
 *   event («Ответил» / «Смотрел»). Who gets it — `lead_to`: «Неразобранное»,
 *   the sender of the letter, an employee. A protected client of another
 *   main responsible goes to that main responsible; a client without a main
 *   responsible gets the receiver as its main one («Взять в работу»).
 * Once per recipient and kind: the source reference never makes a second
 * deal. The call task on a view or a visit that makes no deal is the offer
 * package's and LinkVisits' rule, they ask Campaigns::taskOnOpen().
 * Campaigns before the flow migration: the sender of his own mailbox when
 * the client has a main responsible, otherwise «Неразобранное».
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
    public const KIND_VISIT = 'visit';

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

    /** The first visit by a link of the letter, when the campaign creates a deal on a visit (`lead_on_visit`). */
    public function onVisit(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, ?int $now = null): ?CmsLead
    {
        if (!$campaign->hasAttribute('lead_on_visit') || !$campaign->lead_on_visit) {
            return null;
        }
        $result = $this->handle($campaign, $recipient, self::KIND_VISIT, null, '', $now);

        return $result instanceof CmsLead ? $result : null;
    }

    /** @return CmsTask|CmsLead|null */
    private function handle(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, string $kind, ?CmsCommMessage $message, string $what, ?int $now)
    {
        $record = $recipient->getRecord();
        if (!$record) {
            return null;
        }
        // One event of a client at a time: the client row is locked, so two events (a reply and a view, two
        // workers) never make two deals or two main responsibles; the state is read under the lock.
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            self::lockClient($record);
            $open = $this->openLeadOf($record, (int)$campaign->cms_site_id);
            $result = $open === null
                ? $this->leadFrom($campaign, $recipient, $kind, $message, $what, $record, $now)
                : $this->continueLead($campaign, $recipient, $kind, $message, $what, $open, $now);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $result;
    }

    /** SELECT … FOR UPDATE of the recipient company or person (of a deal — its client). */
    private static function lockClient($record): void
    {
        [$code, $id] = self::clientOf($record);
        if ($code) {
            $table = $code === 'company' ? CmsCompany::tableName() : CmsUser::tableName();
            \Yii::$app->db->createCommand('SELECT id FROM '.$table.' WHERE id = :id FOR UPDATE', [':id' => $id])->queryScalar();
        }
    }

    /** @return CmsTask|CmsLead|null an event of a client with an open deal */
    private function continueLead(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, string $kind, ?CmsCommMessage $message, string $what, CmsLead $open, ?int $now)
    {
        self::moveForward($campaign, $open, $kind);
        if ($kind === self::KIND_VIEW || $kind === self::KIND_VISIT) {
            // A view or a visit adds to the open deal: an entry of its feed.
            if (method_exists($open, 'addSystemActivity')) {
                $open->addSystemActivity('Получатель рассылки «'.Html::encode($campaign->name).'» '
                    .($kind === self::KIND_VIEW ? 'открыл Презентацию или КП из письма.' : 'перешёл на сайт по ссылке из письма.'));
            }

            return $open;
        }

        return $this->replyTask($campaign, $recipient, $kind, $message, $what, $open, $now);
    }

    /**
     * Who gets a new deal of the flow: `lead_to` of the campaign («Неразобранное»
     * — null, the sender, an employee); a protected client of another main
     * responsible — that main responsible. Before the flow migration —
     * senderResponsible().
     */
    public static function receiverOf(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, $record): ?int
    {
        if (!$campaign->hasFlow()) {
            return self::senderResponsible($recipient, $record);
        }
        $receiverId = null;
        if ($campaign->lead_to === CmsCommCampaign::OWNER_SENDER) {
            $receiverId = (int)$recipient->sender_user_id ?: null;
        } elseif ($campaign->lead_to === CmsCommCampaign::OWNER_WORKER) {
            $receiverId = (int)$campaign->lead_worker_id ?: null;
        }
        [$code, $id] = self::clientOf($record);
        $access = \skeeks\cms\crm\CrmAccess::class;
        $protection = \skeeks\cms\crm\CrmProtection::class;
        if ($code && class_exists($access) && class_exists($protection) && $protection::ready()
            && $protection::state($code, $id) !== $protection::FREE) {
            $mainId = $access::instance()->mainResponsibleId($code, $id);
            if ($mainId && $mainId !== $receiverId) {
                // Under protection: the deal is its main responsible's, the flow never takes it over.
                return $mainId;
            }
        }
        if ($receiverId && !CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => $receiverId, CmsUser::tableName().'.is_active' => 1])->exists()) {
            return null;
        }

        return $receiverId;
    }

    /** ['company'|'client', id] of the recipient record (of a deal — its client), [null, 0] for none. */
    private static function clientOf($record): array
    {
        if ($record instanceof CmsLead) {
            $record = $record->cms_company_id ? $record->company : ($record->cms_user_id ? $record->client : null);
        }
        if ($record instanceof CmsCompany) {
            return ['company', (int)$record->id];
        }
        if ($record instanceof CmsUser) {
            return ['client', (int)$record->id];
        }

        return [null, 0];
    }

    /**
     * Whether the client is in the common pool BEFORE its deal is made (П2д):
     * the new deal is a history of the client itself, so after it the client
     * is never «free» any more. Read under the lock of handle(); a client with
     * a main responsible or with a history is not taken.
     */
    private static function poolClient($record): ?array
    {
        [$code, $id] = self::clientOf($record);
        $access = \skeeks\cms\crm\CrmAccess::class;
        if (!$code || !class_exists($access) || !method_exists($access, 'canTakeIntoWork')) {
            return null;
        }

        return $access::instance()->canTakeIntoWork($code, $id) ? [$code, $id] : null;
    }

    /** The pipeline of the campaign's deals: its own, otherwise «Исходящие продажи» (core OutboundSales); null — none. */
    private static function pipelineOf(CmsCommCampaign $campaign): ?\skeeks\cms\models\CmsLeadPipeline
    {
        if (!$campaign->hasFlow() || !CmsLead::pipelineReady() || !class_exists(\skeeks\cms\crm\OutboundSales::class)) {
            return null;
        }
        foreach (\skeeks\cms\models\CmsLeadPipeline::forSite((int)$campaign->cms_site_id ?: null) as $pipeline) {
            if ($campaign->lead_pipeline_id && (int)$pipeline->id === (int)$campaign->lead_pipeline_id) {
                return $pipeline;
            }
        }

        return \skeeks\cms\crm\OutboundSales::pipeline((int)$campaign->cms_site_id);
    }

    /** The stage of an event in the pipeline: «Ответил» for a reply or a request, «Смотрел» for a view or a visit. */
    private static function stageOf(\skeeks\cms\models\CmsLeadPipeline $pipeline, string $kind): ?\skeeks\cms\models\CmsLeadStage
    {
        $sales = \skeeks\cms\crm\OutboundSales::class;
        $role = $kind === self::KIND_VIEW || $kind === self::KIND_VISIT ? $sales::ROLE_VIEWED
            : (defined($sales.'::ROLE_REPLIED') ? constant($sales.'::ROLE_REPLIED') : null);

        return $role ? $sales::stage($pipeline, $role) : null;
    }

    /** An open deal in work of the campaign pipeline moves forward (never back) to the stage of the event. */
    private static function moveForward(CmsCommCampaign $campaign, CmsLead $lead, string $kind): void
    {
        $pipeline = self::pipelineOf($campaign);
        if (!$pipeline || $lead->status !== CmsLead::STATUS_IN_WORK) {
            return;
        }
        $current = $lead->getEffectivePipeline();
        $target = self::stageOf($pipeline, $kind);
        if (!$current || (int)$current->id !== (int)$pipeline->id || !$target || (int)$lead->cms_lead_stage_id === (int)$target->id) {
            return;
        }
        $order = array_map(static fn($stage) => (int)$stage->id, \skeeks\cms\models\CmsLeadStage::forPipeline($pipeline, (int)$lead->cms_site_id));
        $from = $lead->cms_lead_stage_id ? array_search((int)$lead->cms_lead_stage_id, $order, true) : -1;
        $to = array_search((int)$target->id, $order, true);
        if ($to !== false && ($from === false || $from < $to)) {
            $lead->cms_lead_stage_id = (int)$target->id;
            $lead->save(false);
        }
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
            self::KIND_VISIT => ['Перешёл на сайт: ', 'Получатель рассылки «'.$campaign->name.'» перешёл на сайт по ссылке из письма '.$at.'.'],
        ][$kind];
        $executorId = self::receiverOf($campaign, $recipient, $record);
        $pipeline = self::pipelineOf($campaign);
        $stage = $pipeline && $executorId ? self::stageOf($pipeline, $kind) : null;
        $place = $pipeline ? array_filter(['cms_lead_pipeline_id' => (int)$pipeline->id, 'cms_lead_stage_id' => $stage ? (int)$stage->id : null]) : [];
        // Before the deal: afterwards the deal itself is the client's history and the client is not «free».
        $pool = $executorId ? self::poolClient($record) : null;
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
            // The receiver of the campaign — at once his deal; nobody — «Неразобранное».
            'executor_id' => $executorId,
            'status' => $executorId ? CmsLead::STATUS_IN_WORK : CmsLead::STATUS_NEW,
        ] + $place);
        // A client of the pool: the receiver of its deal becomes its main responsible («Взять в работу»), in the
        // same transaction and under the lock; a main responsible set meanwhile (the core) is never replaced.
        if ($pool) {
            $access = \skeeks\cms\crm\CrmAccess::instance();
            if ($access->mainResponsibleId($pool[0], $pool[1]) === null) {
                $access->setMain($pool[0], $pool[1], $executorId);
            }
        }
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
