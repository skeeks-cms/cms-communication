<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\campaign\CampaignChannelInterface;
use skeeks\cms\communication\campaign\CampaignLetter;
use skeeks\cms\communication\campaign\MailboxCampaignChannel;
use skeeks\cms\communication\components\MailingSettings;
use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommImport;
use skeeks\cms\communication\models\CmsCommImportRow;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\send\CampaignSourceInterface;
use skeeks\cms\communication\send\SendableInterface;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\helpers\RichTextHtml;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2manager;
use skeeks\cms\models\CmsCompanyEmail;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsUser2manager;
use skeeks\cms\models\CmsUserEmail;
use skeeks\cms\models\User;
use skeeks\cms\rbac\CmsManager;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\helpers\Html;

/**
 * Mailing campaigns (stage 4B): one service for the CRM section, future API
 * and AI callers.
 *
 * - The audience — members of lists of recipients (RecipientLists), leads by
 *   a filter or the leads of an import — is read in
 *   the launching employee's web request through forManager(), together with
 *   the recipients added by hand or by AI before launch (addRecipient():
 *   a lead, a company or a client, usually with a personal letter); summary()
 *   says who will get the letter and who is excluded and why (no address,
 *   the stop list, opted out of the topic, no mailbox to send from, the
 *   stand allowlist); launch() fixes the recipients, one per CRM record.
 * - Rules that protect every mailing (protect()): members of excluding lists
 *   and the people and leads of companies in them get nothing; one
 *   advertising letter to a contact in MailingSettings::$frequencyDays over
 *   all campaigns; "не мешать менеджеру" — nobody we talked to in the
 *   campaign's quiet_days; one letter per company with one_per_company. The
 *   frequency and the quiet days are asked again before every letter.
 * - Every letter is made when it leaves (letter()): the placeholders are
 *   filled for the recipient, the offer is the document for everybody or
 *   the recipient's own document made from a template, the opt-out link is
 *   personal. The channel of the campaign sends it (CampaignChannelInterface):
 *   an ordinary message in the lead's feed with the campaign mark.
 * - refresh() collects the report: the letter left or failed, the offer
 *   was opened or accepted (CampaignSourceInterface::states()), the client
 *   replied or opted out.
 */
class Campaigns
{
    /** Most recipients one launch may fix: larger audiences go through a mailing service. */
    public const MAX_RECIPIENTS = 5000;

    public const UNSUBSCRIBE_LINK = 'Отписаться от рассылки';

    /** @return CampaignSourceInterface[] type => source: sendable sources campaigns can use as the offer */
    public static function offerSources(): array
    {
        $component = \Yii::$app->communication;
        $result = [];
        foreach (array_keys((array)$component->sendables) as $type) {
            $source = $component->getSendableSource((string)$type);
            if ($source instanceof CampaignSourceInterface) {
                $result[(string)$type] = $source;
            }
        }

        return $result;
    }

    public static function defaultOfferType(): ?string
    {
        $types = array_keys(self::offerSources());

        return $types[0] ?? null;
    }

    public static function offerSource(CmsCommCampaign $campaign): ?CampaignSourceInterface
    {
        return $campaign->offer_type ? (self::offerSources()[$campaign->offer_type] ?? null) : null;
    }

    public static function channel(CmsCommCampaign $campaign): CampaignChannelInterface
    {
        $channel = \Yii::$app->communication->getCampaignChannel((string)$campaign->channel);
        if (!$channel) {
            throw new \DomainException('Канал кампании «'.$campaign->channel.'» не установлен.');
        }

        return $channel;
    }

    /* Audience */

    /** Leads of the audience the employee sees. */
    public function audienceQuery(CmsCommCampaign $campaign, User $user): ActiveQuery
    {
        $t = CmsLead::tableName();
        $query = CmsLead::find()->forManager($user)->andWhere([$t.'.cms_site_id' => (int)$campaign->cms_site_id]);
        $audience = $campaign->getAudienceArray();
        if ($campaign->audience_type === CmsCommCampaign::AUDIENCE_IMPORT) {
            $import = $this->import((int)($audience['import'] ?? 0), $user, (int)$campaign->cms_site_id);
            $rows = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => $import ? (int)$import->id : 0]);
            $ids = array_merge(
                (clone $rows)->select('cms_lead_id')->andWhere(['not', ['cms_lead_id' => null]])->column(),
                (clone $rows)->select('match_id')->andWhere(['match_code' => CmsLead::class])->andWhere(['not', ['match_id' => null]])->column()
            );

            return $query->andWhere([$t.'.id' => array_values(array_unique(array_map('intval', $ids))) ?: [0]]);
        }

        $query->andWhere([$t.'.status' => !empty($audience['statuses']) ? $audience['statuses'] : [CmsLead::STATUS_NEW, CmsLead::STATUS_IN_WORK]]);
        if (!empty($audience['stages'])) {
            $query->andWhere([$t.'.cms_lead_stage_id' => $audience['stages']]);
        }
        if (!empty($audience['sources'])) {
            $query->andWhere([$t.'.source_type' => $audience['sources']]);
        }
        if (!empty($audience['executors'])) {
            $query->andWhere([$t.'.executor_id' => $audience['executors']]);
        }

        return $query;
    }

    /** An import the employee may use: own, or any of the site for an administrator. */
    public function import(int $id, User $user, int $siteId): ?CmsCommImport
    {
        $query = CmsCommImport::find()->andWhere(['id' => $id, 'cms_site_id' => $siteId]);
        if (!\Yii::$app->authManager->checkAccess($user->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            $query->andWhere(['created_by' => (int)$user->id]);
        }

        return $query->one();
    }

    /** @return string[] id => title: imports the employee may use as an audience */
    public function imports(User $user, int $siteId): array
    {
        $query = CmsCommImport::find()->andWhere(['cms_site_id' => $siteId, 'status' => CmsCommImport::STATUS_DONE])->orderBy(['id' => SORT_DESC])->limit(100);
        if (!\Yii::$app->authManager->checkAccess($user->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            $query->andWhere(['created_by' => (int)$user->id]);
        }
        $result = [];
        foreach ($query->all() as $import) {
            $result[(int)$import->id] = '№'.(int)$import->id.' · '.($import->file_name ?: 'вставленная таблица')
                .' · '.\Yii::$app->formatter->asDate((int)$import->created_at, 'php:d.m.Y').' · сделок '.((int)$import->created_count + (int)$import->duplicate_count);
        }

        return $result;
    }

    /**
     * Who gets the letter and who does not: one recipient per lead of the
     * audience (its first email) and every recipient added before launch
     * (its address, name and personal letter win), excluded ones with the
     * reason. Nothing is saved.
     *
     * @return CmsCommCampaignRecipient[]
     */
    public function candidates(CmsCommCampaign $campaign, User $user): array
    {
        $recipients = $campaign->audience_type === CmsCommCampaign::AUDIENCE_MANUAL ? [] : $this->audienceRecipients($campaign, $user);
        foreach (CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id, 'status' => CmsCommCampaignRecipient::STATUS_DRAFT])
                     ->orderBy(['id' => SORT_ASC])->all() as $added) {
            $key = $added->record_type.':'.(int)$added->record_id;
            if (isset($recipients[$key])) {
                $added->populateRelation('record', $recipients[$key]->getRecord());
            }
            $added->status = CmsCommCampaignRecipient::STATUS_PENDING;
            $added->reason = null;
            if (!$added->getRecord() || (!isset($recipients[$key])
                    && !CrmRecords::isVisible(CrmRecords::codeOf((string)$added->record_type), (int)$added->record_id, $user))) {
                $this->excludeRow($added, CmsCommCampaignRecipient::REASON_HIDDEN);
            }
            $recipients[$key] = $added;
        }
        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new \DomainException('В аудитории '.count($recipients).' получателей: из ящиков менеджеров за один запуск — не больше '.self::MAX_RECIPIENTS
                .'. Сузьте фильтр; для больших баз будет сервис рассылок.');
        }
        $recipients = array_values($recipients);
        $this->exclude($campaign, $recipients);
        $this->protect($campaign, $recipients);
        $pending = array_filter($recipients, static fn($r) => $r->status === CmsCommCampaignRecipient::STATUS_PENDING);
        if ($pending) {
            self::channel($campaign)->assignSenders($campaign, array_values($pending), $user);
        }

        return $recipients;
    }

    /** @return CmsCommCampaignRecipient[] "type:id" => recipient: the members of the lists, the leads of the filter or of the import */
    private function audienceRecipients(CmsCommCampaign $campaign, User $user): array
    {
        if ($campaign->audience_type === CmsCommCampaign::AUDIENCE_LISTS) {
            return $this->listRecipients($campaign, $user);
        }
        $query = $this->audienceQuery($campaign, $user);
        $t = CmsLead::tableName();
        $count = (int)(clone $query)->count();
        if ($count > self::MAX_RECIPIENTS) {
            throw new \DomainException('В аудитории '.$count.' сделок: из ящиков менеджеров за один запуск — не больше '.self::MAX_RECIPIENTS
                .'. Сузьте фильтр; для больших баз будет сервис рассылок.');
        }
        /** @var CmsLead[] $leads */
        $leads = $query->with(['company', 'client'])->orderBy([$t.'.id' => SORT_ASC])->all();
        $emails = [];
        foreach (CmsLeadEmail::find()->andWhere(['cms_lead_id' => array_map(static fn($l) => (int)$l->id, $leads) ?: [0]])
                     ->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all() as $row) {
            $emails[(int)$row->cms_lead_id] = $emails[(int)$row->cms_lead_id] ?? $row;
        }

        $recipients = [];
        foreach ($leads as $lead) {
            $email = $emails[(int)$lead->id] ?? null;
            $recipient = new CmsCommCampaignRecipient([
                'record_type' => CrmRecords::LEAD,
                'record_id' => (int)$lead->id,
                'address' => $email ? mb_strtolower(trim((string)$email->value)) : null,
                'name' => mb_substr(self::personName($lead, $email), 0, 255) ?: null,
                'status' => CmsCommCampaignRecipient::STATUS_PENDING,
            ]);
            $recipient->populateRelation('record', $lead);
            $recipients[CrmRecords::LEAD.':'.(int)$lead->id] = $recipient;
        }

        return $recipients;
    }

    /** @return int[] the lists of the audience and the excluding lists of the campaign */
    public static function listIds(CmsCommCampaign $campaign): array
    {
        $audience = $campaign->getAudienceArray();

        return self::siteLists($campaign, array_merge(
            $campaign->audience_type === CmsCommCampaign::AUDIENCE_LISTS ? (array)($audience['lists'] ?? []) : [],
            (array)($audience['exclude_lists'] ?? [])
        ));
    }

    /**
     * Counts the rules of the campaign's lists not counted for $maxAge
     * seconds (ListRules): before the summary and, with 0, at launch.
     *
     * @return int records added
     */
    public function refreshLists(CmsCommCampaign $campaign, int $maxAge): int
    {
        $ids = self::listIds($campaign);

        return $ids ? (new ListRules())->runDue($ids, $maxAge) : 0;
    }

    /** @return int[] ids of the given lists that belong to the campaign's site */
    public static function siteLists(CmsCommCampaign $campaign, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        return $ids ? array_map('intval', CmsCommList::find()->select('id')->andWhere(['id' => $ids, 'cms_site_id' => (int)$campaign->cms_site_id])->column()) : [];
    }

    /**
     * One recipient per active member of the lists: its first email (a
     * company — its own, then its people's). Members the employee does not
     * see stay in the summary as excluded.
     *
     * @return CmsCommCampaignRecipient[] "type:id" => recipient
     */
    private function listRecipients(CmsCommCampaign $campaign, User $user): array
    {
        $members = RecipientLists::members(self::siteLists($campaign, (array)($campaign->getAudienceArray()['lists'] ?? [])));
        if (count($members) > self::MAX_RECIPIENTS) {
            throw new \DomainException('В списках '.count($members).' участников: из ящиков менеджеров за один запуск — не больше '.self::MAX_RECIPIENTS
                .'. Разделите список; для больших баз будет сервис рассылок.');
        }
        $byType = [];
        foreach ($members as [$type, $id]) {
            $byType[$type][] = $id;
        }
        $data = [];
        $visible = [];
        foreach ($byType as $type => $ids) {
            $data[$type] = RecipientLists::addresses($type, $ids);
            $class = CrmRecords::codeOf($type);
            foreach (array_chunk($ids, 1000) as $chunk) {
                foreach (CrmRecords::visibleQuery($type, $user)->select([$class::tableName().'.id'])->andWhere([$class::tableName().'.id' => $chunk])->column() as $id) {
                    $visible[$type.':'.(int)$id] = true;
                }
            }
        }

        $recipients = [];
        foreach ($members as $key => [$type, $id]) {
            $record = $data[$type]['records'][$id] ?? null;
            $addresses = $data[$type]['addresses'][$id] ?? [];
            $address = array_key_first($addresses);
            $recipient = new CmsCommCampaignRecipient([
                'record_type' => $type,
                'record_id' => $id,
                'address' => $address !== null ? (string)$address : null,
                'name' => $address !== null ? (mb_substr((string)$addresses[$address], 0, 255) ?: null) : null,
                'status' => CmsCommCampaignRecipient::STATUS_PENDING,
            ]);
            if ($record) {
                $recipient->populateRelation('record', $record);
            }
            if (!$record || !isset($visible[$key])) {
                $this->excludeRow($recipient, CmsCommCampaignRecipient::REASON_HIDDEN);
            }
            $recipients[$key] = $recipient;
        }

        return $recipients;
    }

    /** Exclusions that do not depend on the channel: no address, closed lead, repeated address, the policy, the stand allowlist. */
    private function exclude(CmsCommCampaign $campaign, array $recipients): void
    {
        $seen = [];
        $toCheck = [];
        foreach ($recipients as $recipient) {
            if ($recipient->status === CmsCommCampaignRecipient::STATUS_EXCLUDED) {
                continue;
            }
            $lead = $recipient->getRecord();
            if (!$recipient->address || !filter_var($recipient->address, FILTER_VALIDATE_EMAIL)) {
                $this->excludeRow($recipient, MailingPolicy::REASON_INVALID);
            } elseif ($campaign->audience_type === CmsCommCampaign::AUDIENCE_IMPORT && $lead instanceof CmsLead
                && in_array($lead->status, [CmsLead::STATUS_SUCCESS, CmsLead::STATUS_REJECTED], true)) {
                $this->excludeRow($recipient, CmsCommCampaignRecipient::REASON_CLOSED);
            } elseif (isset($seen[$recipient->address])) {
                $this->excludeRow($recipient, CmsCommCampaignRecipient::REASON_DUPLICATE_ADDRESS);
            } else {
                $seen[$recipient->address] = true;
                $toCheck[] = $recipient;
            }
        }
        if (!$toCheck) {
            return;
        }
        $checks = MailingPolicy::checkMany((int)$campaign->cms_site_id, 'email', array_map(static fn($r) => (string)$r->address, $toCheck),
            (int)$campaign->cms_comm_topic_id, self::channel($campaign)->policyMode());
        $component = \Yii::$app->communication;
        foreach ($toCheck as $recipient) {
            $check = $checks[(string)$recipient->address] ?? null;
            if ($check && !$check['allowed']) {
                $this->excludeRow($recipient, (string)$check['reason']);
            } elseif (!$component->isRecipientAllowed((string)$recipient->address)) {
                $this->excludeRow($recipient, CmsCommCampaignRecipient::REASON_NOT_ALLOWED);
            }
        }
    }

    private function excludeRow(CmsCommCampaignRecipient $recipient, string $reason): void
    {
        $recipient->status = CmsCommCampaignRecipient::STATUS_EXCLUDED;
        $recipient->reason = $reason;
    }

    /**
     * Rules that protect every mailing, over the recipients still waiting:
     * excluding lists, the frequency per contact, "не мешать менеджеру", one
     * letter per company.
     *
     * @param CmsCommCampaignRecipient[] $recipients
     */
    private function protect(CmsCommCampaign $campaign, array $recipients): void
    {
        $pending = static fn() => array_filter($recipients, static fn($r) => $r->status === CmsCommCampaignRecipient::STATUS_PENDING);
        if (!$pending()) {
            return;
        }
        $refs = [];
        $records = [];
        foreach ($pending() as $recipient) {
            $key = $recipient->record_type.':'.(int)$recipient->record_id;
            $refs[$key] = [(string)$recipient->record_type, (int)$recipient->record_id];
            if ($recipient->isRelationPopulated('record') && $recipient->getRecord()) {
                $records[$key] = $recipient->getRecord();
            }
        }
        $companies = RecipientLists::companies($refs, $records);
        $key = static fn(CmsCommCampaignRecipient $r) => $r->record_type.':'.(int)$r->record_id;

        // Excluding lists: their members, and the people and leads of the companies in them.
        if ($excludeLists = self::siteLists($campaign, (array)($campaign->getAudienceArray()['exclude_lists'] ?? []))) {
            $excluded = RecipientLists::members($excludeLists);
            $excludedCompanies = [];
            foreach ($excluded as [$type, $id]) {
                if ($type === CrmRecords::COMPANY) {
                    $excludedCompanies[$id] = true;
                }
            }
            foreach ($pending() as $recipient) {
                if (isset($excluded[$key($recipient)]) || array_intersect_key(array_flip($companies[$key($recipient)] ?? []), $excludedCompanies)) {
                    $this->excludeRow($recipient, CmsCommCampaignRecipient::REASON_EXCLUDED_LIST);
                }
            }
        }

        // One advertising letter to a contact in so many days, over every campaign of the site.
        if (($days = MailingSettings::frequencyDays((int)$campaign->cms_site_id)) > 0 && $pending()) {
            $recent = self::recentAddresses((int)$campaign->cms_site_id, array_map(static fn($r) => (string)$r->address, $pending()),
                (int)$campaign->id, time() - $days * 86400, true);
            foreach ($pending() as $recipient) {
                if (isset($recent[(string)$recipient->address])) {
                    $this->excludeRow($recipient, CmsCommCampaignRecipient::REASON_RECENT);
                }
            }
        }

        // "Не мешать менеджеру": nobody we talked to lately, about the record or its company.
        if (($quiet = (int)$campaign->quiet_days) > 0 && $pending()) {
            $talked = self::talkedTo($refs, $companies, time() - $quiet * 86400);
            foreach ($pending() as $recipient) {
                if (isset($talked[$key($recipient)])) {
                    $this->excludeRow($recipient, CmsCommCampaignRecipient::REASON_QUIET);
                }
            }
        }

        // One letter per company: the recipient added one by one, then the company itself, then the first of its people and leads.
        if ($campaign->one_per_company && $pending()) {
            $order = array_values($pending());
            $position = array_flip(array_map('spl_object_id', $order));
            usort($order, static fn($a, $b) => [$a->id ? 0 : 1, $a->record_type === CrmRecords::COMPANY ? 0 : 1, $position[spl_object_id($a)]]
                <=> [$b->id ? 0 : 1, $b->record_type === CrmRecords::COMPANY ? 0 : 1, $position[spl_object_id($b)]]);
            $taken = [];
            foreach ($order as $recipient) {
                $own = $companies[$key($recipient)] ?? [];
                if (array_intersect_key(array_flip($own), $taken)) {
                    $this->excludeRow($recipient, CmsCommCampaignRecipient::REASON_SAME_COMPANY);
                    continue;
                }
                foreach ($own as $companyId) {
                    $taken[$companyId] = true;
                }
            }
        }
    }

    /**
     * Addresses that got an advertising letter of another campaign of the
     * site since the moment, or are getting it now; with $waiting also those
     * waiting in another running or paused campaign.
     *
     * @return true[] address => true
     */
    public static function recentAddresses(int $siteId, array $addresses, int $campaignId, int $since, bool $waiting): array
    {
        $addresses = array_values(array_unique(array_filter(array_map('strval', $addresses))));
        $r = CmsCommCampaignRecipient::tableName();
        $c = CmsCommCampaign::tableName();
        $result = [];
        foreach (array_chunk($addresses, 1000) as $chunk) {
            $condition = ['or',
                ['and', [$r.'.status' => CmsCommCampaignRecipient::STATUS_SENT], ['>=', $r.'.sent_at', $since]],
                [$r.'.status' => CmsCommCampaignRecipient::STATUS_SENDING],
            ];
            if ($waiting) {
                $condition[] = ['and', [$r.'.status' => CmsCommCampaignRecipient::STATUS_PENDING],
                    [$c.'.status' => [CmsCommCampaign::STATUS_RUNNING, CmsCommCampaign::STATUS_PAUSED]]];
            }
            $rows = CmsCommCampaignRecipient::find()->select([$r.'.address'])->distinct()
                ->innerJoin($c, $c.'.id = '.$r.'.cms_comm_campaign_id')
                ->andWhere([$c.'.cms_site_id' => $siteId, $r.'.address' => $chunk])
                ->andWhere(['!=', $r.'.cms_comm_campaign_id', $campaignId])
                ->andWhere($condition)->column();
            foreach ($rows as $address) {
                $result[(string)$address] = true;
            }
        }

        return $result;
    }

    /**
     * Records we talked to since the moment — a letter or a message in their
     * feed or in a chat linked to them (not a mailing letter, not an internal
     * note), a call — about themselves or their company.
     *
     * @param array[] $refs "type:id" => [type, id]
     * @param int[][] $companies "type:id" => company ids (RecipientLists::companies())
     * @return true[] "type:id" => true
     */
    public static function talkedTo(array $refs, array $companies, int $since): array
    {
        $ask = $refs;
        foreach ($companies as $ids) {
            foreach ($ids as $companyId) {
                $ask[CrmRecords::COMPANY.':'.$companyId] = [CrmRecords::COMPANY, (int)$companyId];
            }
        }
        $byType = [];
        foreach ($ask as [$type, $id]) {
            $byType[$type][] = (int)$id;
        }
        $m = CmsCommMessage::tableName();
        $chatLinks = class_exists(CmsCommConversationLink::class)
            && \Yii::$app->db->getTableSchema(CmsCommConversationLink::tableName()) !== null;
        $talked = [];
        foreach ($byType as $type => $ids) {
            $code = CrmRecords::codeOf($type);
            foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
                $recent = static fn($query) => $query->andWhere(['>=', $m.'.message_at', $since])->andWhere([$m.'.cms_comm_campaign_id' => null])
                    ->andWhere(['!=', $m.'.is_internal', 1])->andWhere(['!=', $m.'.status', CmsCommMessage::STATUS_FAILED]);
                $l = CmsCommLink::tableName();
                $found = $recent(CmsCommLink::find()->select([$l.'.model_id'])->distinct()->innerJoin($m, $m.'.id = '.$l.'.cms_comm_message_id')
                    ->andWhere([$l.'.model_code' => $code, $l.'.model_id' => $chunk]))->column();
                // Chats of bots linked to the record as a whole (stage 5), where the site has them.
                if ($chatLinks) {
                    $cl = CmsCommConversationLink::tableName();
                    $found = array_merge($found, $recent(CmsCommConversationLink::find()->select([$cl.'.model_id'])->distinct()
                        ->innerJoin($m, $m.'.cms_comm_conversation_id = '.$cl.'.cms_comm_conversation_id')
                        ->andWhere([$cl.'.model_code' => $code, $cl.'.model_id' => $chunk]))->column());
                }
                foreach ($found as $id) {
                    $talked[$type.':'.(int)$id] = true;
                }
            }
        }
        // Calls of the telephony of the CRM (core), when the site has it.
        $call = \skeeks\cms\models\CmsTelephonyCall::class;
        if (class_exists($call) && \Yii::$app->db->getTableSchema($call::tableName()) !== null) {
            foreach ([CrmRecords::LEAD => 'cms_lead_id', CrmRecords::COMPANY => 'cms_company_id', CrmRecords::CLIENT => 'cms_user_id'] as $type => $column) {
                foreach (array_chunk(array_values(array_unique($byType[$type] ?? [])), 1000) as $chunk) {
                    foreach ($call::find()->select([$column])->distinct()->andWhere([$column => $chunk])->andWhere(['>=', 'started_at', $since])->column() as $id) {
                        $talked[$type.':'.(int)$id] = true;
                    }
                }
            }
        }

        $result = [];
        foreach ($refs as $key => $ref) {
            if (isset($talked[$key])) {
                $result[$key] = true;
                continue;
            }
            foreach ($companies[$key] ?? [] as $companyId) {
                if (isset($talked[CrmRecords::COMPANY.':'.$companyId])) {
                    $result[$key] = true;
                    break;
                }
            }
        }

        return $result;
    }

    /* Recipients added by hand or by AI, personal letters */

    /** Record types a recipient may be added for. */
    public const ADDABLE = [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT];

    /**
     * Adds a recipient to a campaign not launched yet, or changes the one
     * added before: a lead, a company or a client the employee sees, one of
     * its email addresses (the first by default), the name for "{Имя}" and an
     * optional personal letter. The same operation serves the campaign card,
     * MCP tools and AI.
     *
     * @param array $data address, name, subject, body_html, by (TEXT_BY_USER|TEXT_BY_AI), client ("crm", "mcp:<client>")
     * @throws \DomainException with the reason it cannot be added
     */
    public function addRecipient(CmsCommCampaign $campaign, ActiveRecord $record, User $user, array $data = []): CmsCommCampaignRecipient
    {
        if (!$campaign->isDraft) {
            throw new \DomainException('Получателей можно добавлять, пока кампания не запущена.');
        }
        $type = CrmRecords::typeOfCode(method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record));
        if (!in_array($type, self::ADDABLE, true) || ($type === CrmRecords::CLIENT && $record->is_worker)) {
            throw new \DomainException('Получателем может быть сделка, компания или клиент.');
        }
        if (!CrmRecords::isVisible(CrmRecords::codeOf($type), (int)$record->primaryKey, $user)) {
            throw new \DomainException('Запись CRM не найдена или вам не видна.');
        }
        $addresses = self::recordAddresses($record);
        $address = mb_strtolower(trim((string)($data['address'] ?? '')));
        if ($address === '') {
            $address = (string)(array_key_first($addresses) ?? '');
        }
        if ($address === '' || !isset($addresses[$address])) {
            throw new \DomainException($addresses ? 'Адрес «'.$address.'» не найден у этой записи: '.implode(', ', array_keys($addresses)).'.'
                : 'У этой записи нет email.');
        }
        $recipient = CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id,
                'record_type' => $type, 'record_id' => (int)$record->primaryKey])->one()
            ?: new CmsCommCampaignRecipient(['cms_comm_campaign_id' => (int)$campaign->id, 'record_type' => $type, 'record_id' => (int)$record->primaryKey]);
        $recipient->status = CmsCommCampaignRecipient::STATUS_DRAFT;
        $recipient->address = $address;
        $name = array_key_exists('name', $data) ? trim((string)$data['name']) : (trim((string)$recipient->name) ?: (string)$addresses[$address]);
        $recipient->name = mb_substr($name, 0, 255) ?: null;
        if (isset($data['subject']) || isset($data['body_html'])) {
            $this->applyText($campaign, $recipient, (string)($data['subject'] ?? ''), (string)($data['body_html'] ?? ''), $user,
                (string)($data['by'] ?? CmsCommCampaignRecipient::TEXT_BY_USER), (string)($data['client'] ?? 'crm'));
        }
        if (!$recipient->save(false)) {
            throw new \RuntimeException('Не удалось сохранить получателя кампании.');
        }

        return $recipient;
    }

    /**
     * The personal letter of a recipient not sent yet; an empty subject and
     * text return it to the campaign template.
     *
     * @throws \DomainException with the problem of the text
     */
    public function setText(CmsCommCampaignRecipient $recipient, string $subject, string $html, User $user,
        string $by = CmsCommCampaignRecipient::TEXT_BY_USER, string $client = 'crm'): void
    {
        $campaign = $recipient->campaign;
        if (!in_array($recipient->status, [CmsCommCampaignRecipient::STATUS_DRAFT, CmsCommCampaignRecipient::STATUS_PENDING], true)
            || !$campaign || !in_array($campaign->status, [CmsCommCampaign::STATUS_DRAFT, CmsCommCampaign::STATUS_RUNNING, CmsCommCampaign::STATUS_PAUSED], true)) {
            throw new \DomainException('Письмо этому получателю уже ушло или кампания закончена: текст менять нельзя.');
        }
        $this->applyText($campaign, $recipient, $subject, $html, $user, $by, $client);
        // A waiting letter may be taken by the sending job: change it only while it still waits.
        CmsCommCampaignRecipient::updateAll($recipient->getDirtyAttributes(['subject', 'body_html', 'text_by', 'text_client', 'text_user_id', 'text_at', 'text_edited_by']) + ['updated_at' => time()],
            ['id' => (int)$recipient->id, 'status' => [CmsCommCampaignRecipient::STATUS_DRAFT, CmsCommCampaignRecipient::STATUS_PENDING]]);
    }

    /** Removes a recipient added to a campaign not launched yet. */
    public function removeRecipient(CmsCommCampaignRecipient $recipient): bool
    {
        return $recipient->status === CmsCommCampaignRecipient::STATUS_DRAFT && (bool)$recipient->delete();
    }

    /**
     * Problems of a personal letter: some text, the opt-out link, the offer
     * button when the campaign has an offer.
     *
     * @return string[] field => error
     */
    public static function textErrors(CmsCommCampaign $campaign, string $subject, string $html): array
    {
        $errors = [];
        if (mb_strlen($subject) > 500) {
            $errors['subject'] = 'Тема письма длиннее 500 знаков.';
        }
        if (trim(RichTextHtml::toText($html)) === '') {
            $errors['body_html'] = 'Напишите текст письма.';
        } elseif (strpos($html, '{Отписаться}') === false) {
            $errors['body_html'] = 'В личном тексте нужна ссылка {Отписаться}: без неё рекламное письмо отправлять нельзя.';
        } elseif ($campaign->offer_kind !== CmsCommCampaign::OFFER_NONE && strpos($html, '{Ссылка}') === false) {
            $errors['body_html'] = 'Вставьте {Ссылка} — кнопку с личной ссылкой на предложение кампании.';
        }

        return $errors;
    }

    private function applyText(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, string $subject, string $html, User $user, string $by, string $client): void
    {
        $subject = trim($subject);
        $html = RichTextHtml::clean($html, ComposeForm::EDITOR_PRESET);
        if ($subject === '' && trim(RichTextHtml::toText($html)) === '') {
            // Back to the campaign template.
            $recipient->subject = null;
            $recipient->body_html = null;
            $recipient->text_by = null;
            $recipient->text_client = null;
            $recipient->text_user_id = null;
            $recipient->text_at = null;
            $recipient->text_edited_by = null;
            return;
        }
        if ($errors = self::textErrors($campaign, $subject, $html)) {
            throw new \DomainException(implode(' ', $errors));
        }
        $recipient->subject = $subject !== '' ? $subject : null;
        $recipient->body_html = $html;
        // An employee changing a text AI wrote keeps "Подготовлено ИИ" and is named as the one who changed it.
        if ($by !== CmsCommCampaignRecipient::TEXT_BY_AI && $recipient->text_by === CmsCommCampaignRecipient::TEXT_BY_AI) {
            $recipient->text_edited_by = (int)$user->id;
            return;
        }
        $recipient->text_edited_by = null;
        $recipient->text_by = $by === CmsCommCampaignRecipient::TEXT_BY_AI ? CmsCommCampaignRecipient::TEXT_BY_AI : CmsCommCampaignRecipient::TEXT_BY_USER;
        $recipient->text_client = mb_substr(trim($client) ?: 'crm', 0, 190);
        $recipient->text_user_id = (int)$user->id;
        $recipient->text_at = time();
    }

    /**
     * Email addresses of a CRM record for a campaign letter, the name of the
     * person behind each: a lead's emails; a company's emails and those of
     * the people of the company; a client's emails.
     *
     * @return string[] address in lower case => name ("" when unknown)
     */
    public static function recordAddresses(ActiveRecord $record): array
    {
        $result = [];
        $add = static function (string $address, string $name) use (&$result) {
            $address = mb_strtolower(trim($address));
            if (filter_var($address, FILTER_VALIDATE_EMAIL) && !isset($result[$address])) {
                $result[$address] = trim($name);
            }
        };
        if ($record instanceof CmsLead) {
            foreach (CmsLeadEmail::find()->andWhere(['cms_lead_id' => (int)$record->id])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all() as $row) {
                $add((string)$row->value, self::personName($record, $row));
            }
        } elseif ($record instanceof CmsCompany) {
            foreach (CmsCompanyEmail::find()->andWhere(['cms_company_id' => (int)$record->id])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all() as $row) {
                $add((string)$row->value, '');
            }
            // Not $record->users: that relation reads the web user component, absent in console jobs.
            $people = CmsUser::find()->andWhere([CmsUser::tableName().'.id' => \skeeks\cms\models\CmsCompany2user::find()->select('cms_user_id')
                ->andWhere(['cms_company_id' => (int)$record->id])])->orderBy([CmsUser::tableName().'.id' => SORT_ASC])->all();
            foreach ($people as $person) {
                foreach (CmsUserEmail::find()->andWhere(['cms_user_id' => (int)$person->id])->orderBy(['id' => SORT_ASC])->all() as $row) {
                    $add((string)$row->value, trim((string)$person->first_name.' '.(string)($person->patronymic ?? '')));
                }
            }
        } elseif ($record instanceof CmsUser) {
            foreach (CmsUserEmail::find()->andWhere(['cms_user_id' => (int)$record->id])->orderBy(['id' => SORT_ASC])->all() as $row) {
                $add((string)$row->value, trim((string)$record->first_name.' '.(string)($record->patronymic ?? '')));
            }
        }

        return $result;
    }

    /**
     * The employee responsible for a CRM record, whose mailbox writes to it:
     * a lead's responsible employee, the first manager of a company or of a
     * client.
     */
    public static function responsibleOf(?ActiveRecord $record): ?int
    {
        if ($record instanceof CmsLead) {
            return $record->executor_id ? (int)$record->executor_id : null;
        }
        if ($record instanceof CmsCompany) {
            $id = CmsCompany2manager::find()->select('cms_user_id')->andWhere(['cms_company_id' => (int)$record->id])->orderBy(['id' => SORT_ASC])->scalar();

            return $id ? (int)$id : null;
        }
        if ($record instanceof CmsUser) {
            $id = CmsUser2manager::find()->select('worker_id')->andWhere(['client_id' => (int)$record->id])->orderBy(['id' => SORT_ASC])->scalar();

            return $id ? (int)$id : null;
        }

        return null;
    }

    /**
     * The summary before launch.
     *
     * @return array recipients, total, pending, excluded, reasons (reason => count), accounts (account id => count)
     */
    public function summary(CmsCommCampaign $campaign, User $user): array
    {
        $recipients = $this->candidates($campaign, $user);

        return self::summarize($recipients);
    }

    /** @param CmsCommCampaignRecipient[] $recipients */
    public static function summarize(array $recipients): array
    {
        $reasons = [];
        $accounts = [];
        $pending = 0;
        foreach ($recipients as $recipient) {
            if ($recipient->status === CmsCommCampaignRecipient::STATUS_EXCLUDED) {
                $reasons[(string)$recipient->reason] = ($reasons[(string)$recipient->reason] ?? 0) + 1;
            } else {
                $pending++;
                $accounts[(int)$recipient->cms_comm_account_id] = ($accounts[(int)$recipient->cms_comm_account_id] ?? 0) + 1;
            }
        }
        arsort($reasons);

        return [
            'recipients' => $recipients,
            'total' => count($recipients),
            'pending' => $pending,
            'excluded' => count($recipients) - $pending,
            'reasons' => $reasons,
            'accounts' => $accounts,
        ];
    }

    /** @return string[] what prevents the launch, empty when it can go */
    public function launchErrors(CmsCommCampaign $campaign, User $user): array
    {
        $errors = [];
        if (!$campaign->isDraft) {
            $errors[] = 'Кампания уже запущена.';
        }
        $topic = $campaign->topic;
        if (!$topic || !$topic->is_active) {
            $errors[] = 'Выберите тему рассылки: без неё клиент не сможет отписаться.';
        }
        if (trim((string)$campaign->subject) === '') {
            $errors[] = 'Напишите тему письма.';
        }
        if (trim(\skeeks\cms\helpers\RichTextHtml::toText((string)$campaign->body_html)) === '') {
            $errors[] = 'Напишите текст письма.';
        }
        if ($campaign->offer_kind !== CmsCommCampaign::OFFER_NONE && ($error = $this->offerError($campaign, $user))) {
            $errors[] = $error;
        }
        try {
            if ($reason = self::channel($campaign)->unavailableReason($campaign, $user)) {
                $errors[] = $reason;
            }
        } catch (\DomainException $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /** Why the offer of the campaign cannot be used, or null. */
    public function offerError(CmsCommCampaign $campaign, User $user): ?string
    {
        $source = self::offerSource($campaign);
        if (!$source) {
            return 'Предложения недоступны: пакет документов не установлен.';
        }
        if ($campaign->offer_kind === CmsCommCampaign::OFFER_SHARED) {
            return isset($source->sharedItems($user, (int)$campaign->cms_site_id)[(int)$campaign->offer_id])
                ? null : 'Документ для всех не найден или больше не действует: выберите другой.';
        }

        return isset($source->templates((int)$campaign->cms_site_id)[(int)$campaign->offer_id]) ? null : 'Шаблон КП не найден или выключен.';
    }

    /**
     * Fixes the recipients and starts sending.
     *
     * @return int letters to send
     * @throws \DomainException with the reason the campaign cannot start
     */
    public function launch(CmsCommCampaign $campaign, User $user): int
    {
        if ($errors = $this->launchErrors($campaign, $user)) {
            throw new \DomainException(implode(' ', $errors));
        }
        // Lists with rules take the records their filters find right now.
        $this->refreshLists($campaign, 0);
        $recipients = $this->candidates($campaign, $user);
        $summary = self::summarize($recipients);
        if (!$summary['pending']) {
            throw new \DomainException('Некому отправить: все получатели исключены. Посмотрите причины в сводке.');
        }

        CmsCommCampaign::getDb()->transaction(function () use ($campaign, $recipients, $user) {
            // One launch only: two clicks or two windows do not fix the recipients twice.
            if (!CmsCommCampaign::updateAll(['status' => CmsCommCampaign::STATUS_RUNNING, 'launched_at' => time(), 'launched_by' => (int)$user->id,
                'updated_at' => time()], ['id' => (int)$campaign->id, 'status' => CmsCommCampaign::STATUS_DRAFT])) {
                throw new \DomainException('Кампания уже запущена.');
            }
            foreach ($recipients as $recipient) {
                $recipient->cms_comm_campaign_id = (int)$campaign->id;
                if (!$recipient->save(false)) {
                    throw new \RuntimeException('Не удалось сохранить получателя кампании.');
                }
            }
        });
        $campaign->refresh();
        self::channel($campaign)->start($campaign);

        return (int)$summary['pending'];
    }

    public function pause(CmsCommCampaign $campaign): bool
    {
        $done = CmsCommCampaign::updateAll(['status' => CmsCommCampaign::STATUS_PAUSED, 'updated_at' => time()],
                ['id' => (int)$campaign->id, 'status' => CmsCommCampaign::STATUS_RUNNING]) > 0;
        $campaign->refresh();

        return $done;
    }

    public function resume(CmsCommCampaign $campaign): bool
    {
        $done = CmsCommCampaign::updateAll(['status' => CmsCommCampaign::STATUS_RUNNING, 'updated_at' => time()],
                ['id' => (int)$campaign->id, 'status' => CmsCommCampaign::STATUS_PAUSED]) > 0;
        $campaign->refresh();
        if ($done) {
            self::channel($campaign)->start($campaign);
        }

        return $done;
    }

    /** Stops the campaign for good: letters not sent yet are not sent. */
    public function cancel(CmsCommCampaign $campaign): bool
    {
        $done = CmsCommCampaign::getDb()->transaction(function () use ($campaign) {
            $now = time();
            $changed = CmsCommCampaign::updateAll(['status' => CmsCommCampaign::STATUS_CANCELLED, 'finished_at' => $now, 'updated_at' => $now],
                ['id' => (int)$campaign->id, 'status' => [CmsCommCampaign::STATUS_RUNNING, CmsCommCampaign::STATUS_PAUSED]]);
            if ($changed) {
                CmsCommCampaignRecipient::updateAll(['status' => CmsCommCampaignRecipient::STATUS_CANCELLED, 'updated_at' => $now],
                    ['cms_comm_campaign_id' => (int)$campaign->id, 'status' => CmsCommCampaignRecipient::STATUS_PENDING]);
            }

            return $changed > 0;
        });
        $campaign->refresh();

        return $done;
    }

    /** A running campaign with nothing left to send becomes "Завершена". */
    public static function finishIfDone(CmsCommCampaign $campaign): bool
    {
        if ($campaign->status !== CmsCommCampaign::STATUS_RUNNING) {
            return false;
        }
        $left = CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id,
            'status' => [CmsCommCampaignRecipient::STATUS_PENDING, CmsCommCampaignRecipient::STATUS_SENDING]])->exists();
        if ($left) {
            return false;
        }

        return CmsCommCampaign::updateAll(['status' => CmsCommCampaign::STATUS_DONE, 'finished_at' => time(), 'updated_at' => time()],
                ['id' => (int)$campaign->id, 'status' => CmsCommCampaign::STATUS_RUNNING]) > 0;
    }

    /* The letter */

    /**
     * Fills the placeholders. An unknown name disappears with the comma or
     * space before it: "Здравствуйте, {Имя}!" → "Здравствуйте!"; at the start of
     * the text or of a paragraph with the comma after it: "{Имя}, добрый день" →
     * "Добрый день".
     *
     * @param string[] $values placeholder => value (plain text)
     */
    public static function fill(string $text, array $values, bool $html): string
    {
        foreach ($values as $key => $value) {
            $value = (string)$value;
            if ($value === '') {
                $quoted = preg_quote($key, '/');
                $text = (string)preg_replace_callback('/(^|<p[^>]*>|<div[^>]*>|<br\s*\/?>|\n)(?:\s|&nbsp;)*'.$quoted.',?(?:[ \x{00A0}]|&nbsp;)*(\p{L})?/u',
                    static fn($m) => $m[1].(isset($m[2]) ? mb_strtoupper($m[2]) : ''), $text);
                $text = (string)preg_replace('/,?(?:[ \x{00A0}]|&nbsp;)*'.$quoted.'/u', '', $text);
            } else {
                $text = str_replace($key, $html ? Html::encode($value) : $value, $text);
            }
        }

        return $text;
    }

    /** @return string[] placeholder => value of the recipient, without the link placeholders */
    public static function values(CmsCommCampaignRecipient $recipient, ?ActiveRecord $record, ?User $sender): array
    {
        return [
            '{Имя}' => (string)$recipient->name,
            '{Компания}' => $record instanceof CmsLead ? self::companyName($record, (string)$recipient->name)
                : ($record instanceof CmsCompany ? trim((string)$record->name) : ''),
            '{Менеджер}' => $sender ? trim((string)$sender->shortDisplayName) : '',
        ];
    }

    /** The person of a lead for "{Имя}": the name at its email, otherwise its client's name. */
    public static function personName(CmsLead $lead, ?CmsLeadEmail $email): string
    {
        if ($email && trim((string)$email->name) !== '') {
            return trim((string)$email->name);
        }
        $client = $lead->client;
        if ($client && trim((string)$client->first_name) !== '') {
            return trim(trim((string)$client->first_name).' '.trim((string)($client->patronymic ?? '')));
        }

        return '';
    }

    /** The company of a lead for "{Компания}": its company, otherwise its name without the person ("ООО Ромашка — Иван"). */
    public static function companyName(CmsLead $lead, string $person): string
    {
        if ($lead->cms_company_id && $lead->company) {
            return trim((string)$lead->company->name);
        }
        $name = trim((string)$lead->name);
        if ($person !== '' && str_ends_with($name, ' — '.$person)) {
            return trim(mb_substr($name, 0, mb_strlen($name) - mb_strlen(' — '.$person)));
        }

        return $person !== '' && $name === $person ? '' : $name;
    }

    /**
     * The letter of a recipient. With $create the offer of "each" is made
     * now for the recipient (call it inside the transaction of the letter);
     * without it (preview) the offer of "each" has no document yet.
     *
     * @throws SendException when the offer cannot be sent now
     */
    public function letter(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, ActiveRecord $record, User $user, bool $create): CampaignLetter
    {
        $letter = new CampaignLetter($campaign, $recipient, $record, $user);
        $values = self::values($recipient, $record, $user);
        // A personal letter of the recipient instead of the template; an empty personal subject keeps the campaign's.
        $subject = trim((string)$recipient->subject) !== '' ? (string)$recipient->subject : (string)$campaign->subject;
        $body = trim((string)$recipient->body_html) !== '' ? (string)$recipient->body_html : (string)$campaign->body_html;
        $letter->subject = trim(self::fill($subject, $values, false));
        $html = self::fill($body, $values, true);

        $source = self::offerSource($campaign);
        $label = 'Открыть предложение';
        if ($campaign->offer_kind === CmsCommCampaign::OFFER_SHARED && $source) {
            $letter->sendableType = (string)$campaign->offer_type;
            $letter->sendableId = (int)$campaign->offer_id;
        } elseif ($campaign->offer_kind === CmsCommCampaign::OFFER_EACH && $source && $create) {
            $letter->sendableType = (string)$campaign->offer_type;
            $letter->sendableId = $source->createFromTemplate((int)$campaign->offer_id, $record, $user);
        }
        if ($letter->sendableId) {
            $letter->sendable = \Yii::$app->communication->getSendableSource((string)$letter->sendableType)->find((int)$letter->sendableId, $user);
            if (!$letter->sendable instanceof SendableInterface) {
                throw new SendException(['sendable' => 'Документ предложения не найден или недоступен отправителю.']);
            }
            $label = $letter->sendable->getSendButton();
            $link = MailboxCampaignChannel::linkPlaceholder((string)$letter->sendableType, (int)$letter->sendableId, $label);
        } else {
            $link = $campaign->offer_kind === CmsCommCampaign::OFFER_NONE ? '' : MailboxCampaignChannel::linkPlaceholder('preview', 0, $label);
        }
        $html = str_replace('{Ссылка}', $link, $html);
        $html = str_replace('{Отписаться}', '<a href="'.Unsubscribe::PLACEHOLDER.'">'.self::UNSUBSCRIBE_LINK.'</a>', $html);
        // A paragraph left empty by a removed placeholder goes too.
        $letter->html = trim((string)preg_replace('~<p>(?:\s|&nbsp;|<br\s*/?>)*</p>~i', '', $html));

        return $letter;
    }

    /**
     * How the letter will look for the recipient: the button and the opt-out
     * line where they will be, links not active.
     *
     * @return array subject, html, from (text), to, sender, check (MailingPolicy result)
     */
    public function preview(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, User $viewer): array
    {
        $record = $recipient->getRecord();
        $sender = $recipient->sender_user_id ? CmsUser::findOne((int)$recipient->sender_user_id) : null;
        $letter = $this->letter($campaign, $recipient, $record, $sender ?: $viewer, false);
        $html = $letter->html;
        if ($letter->sendableId || $campaign->offer_kind !== CmsCommCampaign::OFFER_NONE) {
            [$html] = SendService::applyLink($html, '', (string)($letter->sendableType ?: 'preview'), (int)$letter->sendableId, '#',
                $letter->sendable ? $letter->sendable->getSendButton() : 'Открыть предложение');
        }
        [$html] = Unsubscribe::apply($html, '', '#');
        $account = $recipient->cms_comm_account_id ? CmsCommAccount::findOne((int)$recipient->cms_comm_account_id) : null;

        return [
            'subject' => $letter->subject,
            'html' => $html,
            'from' => $account ? $account->asText() : '',
            'to' => trim(($recipient->name ? $recipient->name.' ' : '').'<'.$recipient->address.'>'),
            'sender' => $sender ? (string)$sender->shortDisplayName : '',
        ];
    }

    /**
     * "Тестовое письмо себе": the letter of a recipient as it looks, to the
     * employee's own address from a mailbox they may write from. Links do not
     * work in it; nothing is recorded for the recipient.
     *
     * @throws \DomainException
     */
    public function sendTest(CmsCommCampaign $campaign, CmsCommCampaignRecipient $sample, User $user): CmsCommMessage
    {
        $email = mb_strtolower(trim((string)$user->email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('У вас в профиле нет email: тестовое письмо некуда отправить.');
        }
        $preview = $this->preview($campaign, $sample, $user);
        $compose = new MailComposeService($user, (int)$campaign->cms_site_id);
        $accountId = $sample->cms_comm_account_id && $compose->canSendFrom((int)$sample->cms_comm_account_id)
            ? (int)$sample->cms_comm_account_id : $compose->defaultAccountId();
        if (!$accountId) {
            throw new \DomainException('У вас нет ящика, с которого можно отправить тестовое письмо.');
        }
        $form = new ComposeForm();
        $form->service = $compose;
        $form->cms_comm_account_id = $accountId;
        $form->to = $email;
        $form->subject = mb_substr('[Тест] '.$preview['subject'], 0, 500);
        $form->body_html = '<p><em>Тестовое письмо кампании «'.Html::encode((string)$campaign->name).'» — так его увидит '
            .Html::encode($preview['to']).($preview['from'] ? ', отправитель '.Html::encode($preview['from']) : '').'. Ссылки в тесте не работают.</em></p>'
            .$preview['html'];
        if (!$form->validate()) {
            throw new \DomainException(implode(' ', $form->getFirstErrors()));
        }

        return $compose->queue($form);
    }

    /**
     * Sends the letter of one waiting recipient: claims it, checks the
     * policy again (an opt-out after the launch counts), makes the letter
     * and the offer and hands it to the channel in one transaction. A
     * refusal or an error stays on the recipient; nothing is retried.
     */
    public function deliver(CmsCommCampaignRecipient $recipient): void
    {
        $campaign = $recipient->campaign;
        if (!$campaign || $campaign->status !== CmsCommCampaign::STATUS_RUNNING) {
            return;
        }
        $record = $recipient->getRecord();
        $reason = null;
        $error = null;
        if (!$record) {
            $error = 'Сделка удалена.';
        } else {
            $check = MailingPolicy::check((int)$campaign->cms_site_id, 'email', (string)$recipient->address, (int)$campaign->cms_comm_topic_id,
                self::channel($campaign)->policyMode());
            if (!$check['allowed']) {
                $reason = (string)$check['reason'];
            } elseif (!\Yii::$app->communication->isRecipientAllowed((string)$recipient->address)) {
                $reason = CmsCommCampaignRecipient::REASON_NOT_ALLOWED;
            } else {
                $reason = $this->protectBeforeLetter($campaign, $recipient, $record);
            }
        }
        $sender = $recipient->sender_user_id ? CmsUser::findOne((int)$recipient->sender_user_id) : null;
        if (!$reason && !$error && !$sender) {
            $error = 'Отправитель письма больше не сотрудник.';
        }
        if ($reason || $error) {
            CmsCommCampaignRecipient::updateAll(array_filter([
                'status' => $reason ? CmsCommCampaignRecipient::STATUS_EXCLUDED : CmsCommCampaignRecipient::STATUS_FAILED,
                'reason' => $reason,
                'error' => $error,
                'updated_at' => time(),
            ], static fn($v) => $v !== null), ['id' => (int)$recipient->id, 'status' => CmsCommCampaignRecipient::STATUS_PENDING]);
            self::finishIfDone($campaign);
            return;
        }

        try {
            CmsCommCampaignRecipient::getDb()->transaction(function () use ($campaign, $recipient, $record, $sender) {
                // The claim is in the transaction: a crash before the commit leaves the recipient waiting and nothing sent.
                if (!CmsCommCampaignRecipient::updateAll(['status' => CmsCommCampaignRecipient::STATUS_SENDING, 'updated_at' => time()],
                    ['id' => (int)$recipient->id, 'status' => CmsCommCampaignRecipient::STATUS_PENDING])) {
                    return;
                }
                $letter = $this->letter($campaign, $recipient, $record, $sender, true);
                $message = self::channel($campaign)->deliver($letter);
                CmsCommCampaignRecipient::updateAll([
                    'status' => CmsCommCampaignRecipient::STATUS_SENT,
                    'cms_comm_message_id' => (int)$message->id,
                    'send_ref' => $letter->sendableRef(),
                    'sent_at' => time(),
                    'error' => null,
                    'updated_at' => time(),
                ], ['id' => (int)$recipient->id]);
            });
        } catch (SendException $e) {
            $this->fail($recipient, implode(' ', $e->errors ?: [$e->getMessage()]));
        } catch (\Throwable $e) {
            \Yii::error('Письмо кампании #'.(int)$campaign->id.' получателю #'.(int)$recipient->id.' не отправлено: '.$e->getMessage(), self::class);
            $this->fail($recipient, mb_substr($e->getMessage(), 0, 900));
        }
        self::finishIfDone($campaign);
    }

    /**
     * The rules asked again right before a letter: another campaign wrote to
     * this contact meanwhile, or the manager has talked to the client since
     * the launch. Returns the reason to exclude, or null.
     */
    private function protectBeforeLetter(CmsCommCampaign $campaign, CmsCommCampaignRecipient $recipient, ActiveRecord $record): ?string
    {
        if (($days = MailingSettings::frequencyDays((int)$campaign->cms_site_id)) > 0
            && self::recentAddresses((int)$campaign->cms_site_id, [(string)$recipient->address], (int)$campaign->id, time() - $days * 86400, false)) {
            return CmsCommCampaignRecipient::REASON_RECENT;
        }
        if (($quiet = (int)$campaign->quiet_days) > 0) {
            $key = $recipient->record_type.':'.(int)$recipient->record_id;
            $refs = [$key => [(string)$recipient->record_type, (int)$recipient->record_id]];
            // Since the launch only: what the summary saw before it was already excluded there.
            $since = max(time() - $quiet * 86400, (int)$campaign->launched_at);
            if (self::talkedTo($refs, RecipientLists::companies($refs, [$key => $record]), $since)) {
                return CmsCommCampaignRecipient::REASON_QUIET;
            }
        }

        return null;
    }

    private function fail(CmsCommCampaignRecipient $recipient, string $error): void
    {
        CmsCommCampaignRecipient::updateAll(['status' => CmsCommCampaignRecipient::STATUS_FAILED, 'error' => $error, 'updated_at' => time()],
            ['id' => (int)$recipient->id, 'status' => [CmsCommCampaignRecipient::STATUS_PENDING, CmsCommCampaignRecipient::STATUS_SENDING]]);
    }

    /* The report */

    /**
     * Brings the report of the recipients up to date: letters that failed
     * to leave, offers opened and accepted, replies, opt-outs.
     */
    public function refresh(CmsCommCampaign $campaign): void
    {
        /** @var CmsCommCampaignRecipient[] $sent */
        $sent = CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id, 'status' => CmsCommCampaignRecipient::STATUS_SENT])
            ->andWhere(['not', ['cms_comm_message_id' => null]])->indexBy('cms_comm_message_id')->all();
        if (!$sent) {
            return;
        }
        $messageIds = array_map('intval', array_keys($sent));
        $changes = [];

        $messages = CmsCommMessage::find()->select(['id', 'status', 'error', 'cms_comm_conversation_id', 'message_at'])
            ->andWhere(['id' => $messageIds])->indexBy('id')->asArray()->all();
        foreach ($messages as $id => $message) {
            if (in_array($message['status'], [CmsCommMessage::STATUS_FAILED, CmsCommMessage::STATUS_UNKNOWN], true)) {
                $changes[$id]['status'] = CmsCommCampaignRecipient::STATUS_FAILED;
                $changes[$id]['error'] = $message['status'] === CmsCommMessage::STATUS_UNKNOWN
                    ? 'Неизвестно, ушло ли письмо: '.(string)$message['error'] : (string)$message['error'];
            }
        }

        // Replies: the first incoming letter of the conversation after ours.
        $conversations = [];
        foreach ($messages as $id => $message) {
            $conversations[(int)$message['cms_comm_conversation_id']][] = (int)$id;
        }
        if ($conversations) {
            $rows = CmsCommMessage::find()->select(['cms_comm_conversation_id', 'message_at'])
                ->andWhere(['cms_comm_conversation_id' => array_keys($conversations), 'direction' => CmsCommMessage::DIRECTION_IN])
                ->orderBy(['message_at' => SORT_ASC])->asArray()->all();
            foreach ($rows as $row) {
                foreach ($conversations[(int)$row['cms_comm_conversation_id']] as $id) {
                    $recipient = $sent[$id];
                    if (!$recipient->replied_at && empty($changes[$id]['replied_at']) && (int)$row['message_at'] >= (int)$recipient->sent_at - 60) {
                        $changes[$id]['replied_at'] = (int)$row['message_at'];
                    }
                }
            }
        }

        if ($source = self::offerSource($campaign)) {
            foreach ($source->states($messageIds) as $id => $state) {
                $recipient = $sent[$id] ?? null;
                if (!$recipient) {
                    continue;
                }
                foreach (['opened_at', 'accepted_at'] as $field) {
                    if (!empty($state[$field]) && (int)$recipient->$field !== (int)$state[$field]) {
                        $changes[$id][$field] = (int)$state[$field];
                    }
                }
            }
        }

        // Opt-outs after the letter: of the topic, of all advertising, a complaint.
        $contacts = MailingContacts::findMany((int)$campaign->cms_site_id, CmsCommContact::KIND_EMAIL,
            array_map(static fn($r) => (string)$r->address, $sent));
        if ($contacts) {
            $byContact = [];
            foreach ($contacts as $contact) {
                $byContact[(int)$contact->id] = (string)$contact->value;
            }
            $optOuts = [];
            foreach (CmsCommContactTopic::find()->andWhere(['cms_comm_contact_id' => array_keys($byContact), 'cms_comm_topic_id' => (int)$campaign->cms_comm_topic_id,
                'status' => CmsCommContactTopic::STATUS_UNSUBSCRIBED])->all() as $row) {
                $optOuts[$byContact[(int)$row->cms_comm_contact_id]][] = (int)$row->unsubscribed_at;
            }
            foreach (CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => array_keys($byContact),
                'reason' => [CmsCommSuppression::REASON_ALL, CmsCommSuppression::REASON_COMPLAINT]])->all() as $row) {
                $optOuts[$byContact[(int)$row->cms_comm_contact_id]][] = (int)$row->created_at;
            }
            foreach ($sent as $id => $recipient) {
                foreach ($optOuts[(string)$recipient->address] ?? [] as $at) {
                    if ($at >= (int)$recipient->sent_at && !$recipient->unsubscribed_at) {
                        $changes[$id]['unsubscribed_at'] = min($at, $changes[$id]['unsubscribed_at'] ?? PHP_INT_MAX);
                    }
                }
            }
        }

        foreach ($changes as $id => $attributes) {
            $attributes['updated_at'] = time();
            CmsCommCampaignRecipient::updateAll($attributes, ['id' => (int)$sent[$id]->id]);
        }
    }

    /**
     * Totals of the campaign. Percents of opened, visited the site, replied,
     * accepted and opted out are of the letters sent.
     *
     * @return array total, excluded, pending, sent, failed, cancelled, opened, visited, replied, accepted, unsubscribed, delivered, bounced, percent (field => %)
     */
    public static function stats(CmsCommCampaign $campaign): array
    {
        $byStatus = CmsCommCampaignRecipient::find()->select(['status', 'c' => 'COUNT(*)'])
            ->andWhere(['cms_comm_campaign_id' => (int)$campaign->id])->groupBy('status')->asArray()->all();
        $counts = [];
        foreach ($byStatus as $row) {
            $counts[(string)$row['status']] = (int)$row['c'];
        }
        $sentQuery = CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id, 'status' => CmsCommCampaignRecipient::STATUS_SENT]);
        $result = [
            'total' => array_sum($counts),
            'excluded' => $counts[CmsCommCampaignRecipient::STATUS_EXCLUDED] ?? 0,
            'pending' => ($counts[CmsCommCampaignRecipient::STATUS_PENDING] ?? 0) + ($counts[CmsCommCampaignRecipient::STATUS_SENDING] ?? 0),
            'sent' => $counts[CmsCommCampaignRecipient::STATUS_SENT] ?? 0,
            'failed' => $counts[CmsCommCampaignRecipient::STATUS_FAILED] ?? 0,
            'cancelled' => $counts[CmsCommCampaignRecipient::STATUS_CANCELLED] ?? 0,
        ];
        // "Перешёл на сайт": the first counted visit by a link of the letter (LinkVisits).
        foreach (['opened' => 'opened_at', 'visited' => 'visited_at', 'replied' => 'replied_at', 'accepted' => 'accepted_at', 'unsubscribed' => 'unsubscribed_at'] as $key => $field) {
            $result[$key] = (int)(clone $sentQuery)->andWhere(['not', [$field => null]])->count();
        }
        // Delivery confirmed or refused by a mailing service (stage 4C): managers' mailboxes report neither.
        $result['delivered'] = $result['bounced'] = 0;
        if ((new CmsCommCampaignRecipient())->hasAttribute('delivered_at')) {
            $result['delivered'] = (int)(clone $sentQuery)->andWhere(['not', ['delivered_at' => null]])->count();
            $result['bounced'] = (int)(clone $sentQuery)->andWhere(['not', ['bounced_at' => null]])->count();
        }
        $result['personal'] = (int)CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id])
            ->andWhere(['not', ['body_html' => null]])->count();
        $result['percent'] = [];
        foreach (['opened', 'visited', 'replied', 'accepted', 'unsubscribed', 'delivered', 'bounced'] as $key) {
            $result['percent'][$key] = $result['sent'] ? round($result[$key] * 100 / $result['sent'], 1) : 0;
        }

        return $result;
    }

    /* Rules of the campaign */

    /**
     * Does the campaign of this letter want the task "клиент открыл
     * предложение": true or false by its setting, null when the letter is
     * not a campaign letter (the package's own setting decides).
     */
    public static function taskOnOpen(?int $messageId): ?bool
    {
        if (!$messageId) {
            return null;
        }
        $campaignId = CmsCommMessage::find()->select('cms_comm_campaign_id')->andWhere(['id' => $messageId])->scalar();
        if (!$campaignId) {
            return null;
        }
        $setting = CmsCommCampaign::find()->select('task_on_open')->andWhere(['id' => (int)$campaignId])->scalar();

        return $setting === false || $setting === null ? null : (bool)$setting;
    }

    /**
     * New incoming letters of a mail receive: a reply to a campaign letter
     * marks its recipient "ответил" and, by the campaign setting, gives the
     * sender a task.
     *
     * @param int[] $messageIds
     * @return int replies found
     */
    public function onIncoming(array $messageIds): int
    {
        if (!$messageIds) {
            return 0;
        }
        $m = CmsCommMessage::tableName();
        $incoming = CmsCommMessage::find()->andWhere([$m.'.id' => $messageIds, $m.'.direction' => CmsCommMessage::DIRECTION_IN])->all();
        $found = 0;
        foreach ($incoming as $message) {
            $r = CmsCommCampaignRecipient::tableName();
            /** @var CmsCommCampaignRecipient[] $recipients */
            $recipients = CmsCommCampaignRecipient::find()
                ->innerJoin($m, $m.'.id = '.$r.'.cms_comm_message_id')
                ->andWhere([$m.'.cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id])
                ->andWhere([$r.'.status' => CmsCommCampaignRecipient::STATUS_SENT, $r.'.replied_at' => null])
                ->select($r.'.*')->all();
            foreach ($recipients as $recipient) {
                if (!CmsCommCampaignRecipient::updateAll(['replied_at' => (int)$message->message_at ?: time(), 'updated_at' => time()],
                    ['id' => (int)$recipient->id, 'replied_at' => null])) {
                    continue;
                }
                $found++;
                $campaign = $recipient->campaign;
                if ($campaign && $campaign->task_on_reply) {
                    try {
                        (new CampaignTasks())->onReply($campaign, $recipient, $message);
                    } catch (\Throwable $e) {
                        \Yii::error('Задача по ответу на рассылку не поставлена: '.$e->getMessage(), self::class);
                    }
                }
            }
        }

        return $found;
    }
}
