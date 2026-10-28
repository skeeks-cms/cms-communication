<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommAudience;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommImport;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveRecord;

/**
 * The mailing state of CRM records in the new model (П5): the cold base is
 * an audience of companies and contacts, a deal comes from interest.
 *
 * - origin(): a card made by a mailing import remembers its source
 *   («Рассылка: база …») and starts cold; consent stays with the contact
 *   (MailingConsents, basis cold).
 * - warm(): a click on a link of a letter or a view of a presentation or an
 *   offer marks the company or the contact warm, with the campaign and the
 *   letter. It never creates a deal: the call task is the campaign setting
 *   (task_on_open, LinkVisits::callTask(), cms-proposal ProposalTasks), the
 *   deal comes from a reply (CampaignTasks::onReply()) or from the manager.
 *   An opening of a letter is only a mark in the campaign report.
 */
class Audience
{
    /** The card a signal belongs to: a lead's company or contact, else the lead itself. */
    public static function cardOf(ActiveRecord $record): ?ActiveRecord
    {
        if ($record instanceof CmsLead) {
            if ($record->cms_company_id && ($company = CmsCompany::findOne((int)$record->cms_company_id))) {
                return $company;
            }
            if ($record->cms_user_id && ($client = CmsUser::findOne((int)$record->cms_user_id))) {
                return $client;
            }

            return $record;
        }
        if ($record instanceof CmsUser && $record->is_worker) {
            return null;
        }

        return RecipientLists::recordType($record) ? $record : null;
    }

    /** The state row of a record, or null. */
    public static function of(int $siteId, ActiveRecord $record): ?CmsCommAudience
    {
        if (!CmsCommImport::audienceReady()) {
            return null;
        }
        $type = RecipientLists::recordType($record);

        return $type ? CmsCommAudience::find()->andWhere(['cms_site_id' => $siteId, 'record_type' => $type, 'record_id' => (int)$record->primaryKey])->one() : null;
    }

    /**
     * A card made by a mailing import: its source and the cold state. A record
     * that already has a state keeps it (a warm one stays warm).
     */
    public static function origin(int $siteId, ActiveRecord $record, string $sourceName, ?int $importId = null): CmsCommAudience
    {
        $type = RecipientLists::recordType($record);
        if (!$type) {
            throw new \DomainException('Источник рассылки ставится только компании, контакту или сделке.');
        }
        $row = self::of($siteId, $record) ?: new CmsCommAudience([
            'cms_site_id' => $siteId,
            'record_type' => $type,
            'record_id' => (int)$record->primaryKey,
            'state' => CmsCommAudience::STATE_COLD,
        ]);
        if ($row->source_name === null || $row->source_name === '') {
            $row->source_name = mb_substr($sourceName, 0, 255);
            $row->cms_comm_import_id = $importId;
        }
        $row->save(false);

        return $row;
    }

    /**
     * A signal of interest of a letter's recipient: the card becomes warm
     * (the first signal is kept). Records of a campaign letter are its
     * recipient; of another letter — the CRM records linked to it.
     *
     * @param string $by CmsCommAudience::WARM_*
     * @return CmsCommAudience[] the rows that became warm now
     */
    public static function warmByMessage(int $messageId, string $by, ?int $at = null): array
    {
        if (!CmsCommImport::audienceReady()) {
            return [];
        }
        $message = CmsCommMessage::findOne($messageId);
        if (!$message) {
            return [];
        }
        $records = [];
        $recipient = CmsCommCampaignRecipient::find()->andWhere(['cms_comm_message_id' => $messageId])->one();
        if ($recipient && ($record = $recipient->getRecord())) {
            $records[] = $record;
        } else {
            foreach (CmsCommLink::find()->andWhere(['cms_comm_message_id' => $messageId,
                'model_code' => [CmsLead::class, CmsCompany::class, CmsUser::class]])->all() as $link) {
                /** @var ActiveRecord $class */
                $class = (string)$link->model_code;
                if ($record = $class::findOne((int)$link->model_id)) {
                    $records[] = $record;
                }
            }
        }

        return self::warm((int)$message->cms_site_id, $records, $by, $message->cms_comm_campaign_id ? (int)$message->cms_comm_campaign_id : null, $messageId, $at);
    }

    /**
     * @param ActiveRecord[] $records
     * @return CmsCommAudience[] the rows that became warm now
     */
    public static function warm(int $siteId, array $records, string $by, ?int $campaignId = null, ?int $messageId = null, ?int $at = null): array
    {
        if (!CmsCommImport::audienceReady()) {
            return [];
        }
        $at = $at ?? time();
        $result = [];
        $seen = [];
        foreach ($records as $record) {
            $card = self::cardOf($record);
            $type = $card ? RecipientLists::recordType($card) : null;
            if (!$type || isset($seen[$type.':'.$card->primaryKey])) {
                continue;
            }
            $seen[$type.':'.$card->primaryKey] = true;
            $row = self::of($siteId, $card) ?: new CmsCommAudience([
                'cms_site_id' => $siteId,
                'record_type' => $type,
                'record_id' => (int)$card->primaryKey,
                'state' => CmsCommAudience::STATE_COLD,
            ]);
            if ($row->isWarm()) {
                continue;
            }
            $row->state = CmsCommAudience::STATE_WARM;
            $row->warm_at = $at;
            $row->warm_by = isset(CmsCommAudience::warmReasons()[$by]) ? $by : CmsCommAudience::WARM_VISIT;
            $row->warm_campaign_id = $campaignId;
            $row->warm_message_id = $messageId;
            try {
                $row->save(false);
            } catch (\yii\db\IntegrityException $e) {
                // Two signals at once (two tabs): the other one made it warm.
                continue;
            }
            $result[] = $row;
        }

        return $result;
    }

    /** "Тёплый: смотрел Презентацию 28.10.2026, рассылка «…»" / "Холодный, согласия нет · Рассылка: база …". */
    public static function text(CmsCommAudience $row): string
    {
        $parts = [];
        if ($row->isWarm()) {
            $warm = 'Тёплый: '.(CmsCommAudience::warmReasons()[(string)$row->warm_by] ?? 'интересовался');
            if ($row->warm_at) {
                $warm .= ' '.\Yii::$app->formatter->asDatetime((int)$row->warm_at, 'php:d.m.Y H:i');
            }
            if ($row->warmCampaign) {
                $warm .= ', рассылка «'.$row->warmCampaign->name.'»';
            }
            $parts[] = $warm;
        } else {
            $parts[] = 'Холодный';
        }
        if ($row->source_name) {
            $parts[] = 'Источник: '.$row->source_name;
        }

        return implode(' · ', $parts);
    }

    /**
     * Warm cards of records at once (the campaign report, lists).
     * @param array[] $refs [type, id]
     * @return CmsCommAudience[] "type:id" => row
     */
    public static function many(int $siteId, array $refs): array
    {
        if (!CmsCommImport::audienceReady()) {
            return [];
        }
        $byType = [];
        foreach ($refs as [$type, $id]) {
            $byType[(string)$type][] = (int)$id;
        }
        $result = [];
        foreach ($byType as $type => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
                foreach (CmsCommAudience::find()->andWhere(['cms_site_id' => $siteId, 'record_type' => $type, 'record_id' => $chunk])->all() as $row) {
                    $result[$type.':'.(int)$row->record_id] = $row;
                }
            }
        }

        return $result;
    }
}
