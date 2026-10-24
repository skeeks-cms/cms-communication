<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTrackVisit;
use skeeks\cms\communication\services\LinkChecks;
use skeeks\cms\communication\services\LinkVisits;
use skeeks\cms\mcp\crm\CrmActor;
use skeeks\cms\mcp\crm\CrmDate;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\HistoryLogDescriberInterface;
use skeeks\cms\models\CmsLog;
use yii\helpers\ArrayHelper;

/**
 * A visit of our site by a link of a letter in the client history
 * (cms_crm_history_get): when, by which letter, the pages of the visit.
 * Only what the feed shows (VisitLogRenderer): the subject of a letter the
 * employee may not read is left out. Reading never counts as a visit: the
 * page addresses here carry no personal code, and links with the code are
 * hidden from API clients (personalLinkPatterns of the package config).
 */
class VisitHistoryDescriber implements HistoryLogDescriberInterface
{
    public function describe(CmsLog $log, CrmReadContext $context): ?array
    {
        $data = (array)$log->data;
        $visit = CmsCommTrackVisit::find()->andWhere(['id' => (int)ArrayHelper::getValue($data, 'visit')])->with('track')->one();
        if (!$visit || !$visit->track) {
            return ['title' => 'Переход на сайт из письма', 'summary' => 'Данные перехода удалены.', 'dedup_key' => 'visit:'.(int)ArrayHelper::getValue($data, 'visit')];
        }
        $track = $visit->track;
        $checked = ArrayHelper::getValue($data, 'event') === LinkVisits::EVENT_CHECKED;
        $message = CmsCommMessage::find()->forManager()->andWhere([CmsCommMessage::tableName().'.id' => (int)$track->cms_comm_message_id])->one();
        $sentAt = (int)(CmsCommMessage::find()->select('message_at')->andWhere(['id' => (int)$track->cms_comm_message_id])->scalar() ?: $track->created_at);
        $letter = 'письма от '.\Yii::$app->formatter->asDate($sentAt, 'php:d.m.Y').($message && $message->subject ? ' «'.$message->subject.'»' : '');
        $summary = $checked
            ? 'Ссылку из '.$letter.' открыла, похоже, почтовая служба или защитная система ('.LinkChecks::reasonName((string)$visit->check_reason).'); не засчитано как переход клиента.'
            : 'Перешёл на сайт из '.$letter.': '.LinkVisits::pagesText($visit).'.';

        return [
            'subtype' => $checked ? 'link_check' : 'site_visit',
            'title' => $checked ? 'Ссылка из письма: возможно, проверка ссылок' : 'Перешёл на сайт из письма',
            'summary' => $context->text($summary),
            'direction' => 'in',
            'channel' => 'site',
            'actor' => $checked ? ['kind' => CrmActor::SYSTEM] : CrmActor::client(null, $context),
            'details' => array_filter([
                'cms_comm_message_id' => $message ? (int)$message->id : null,
                'cms_comm_campaign_id' => $track->cms_comm_campaign_id ? (int)$track->cms_comm_campaign_id : null,
                'offer' => $track->offer_ref ?: null,
                'utm_campaign' => $track->utm_campaign ?: null,
                'pages' => $checked ? null : array_map(static fn($page) => [
                    'url' => (string)$page['url'],
                    'title' => (string)($page['title'] ?? ''),
                    'at' => CrmDate::format((int)($page['at'] ?? 0)),
                ], $visit->getPageList()),
                'pages_count' => $checked ? null : (int)$visit->pages_count,
                'started_at' => CrmDate::format((int)$visit->created_at),
                'last_at' => $checked ? null : CrmDate::format((int)$visit->last_at),
                'check_reason' => $checked ? (string)$visit->check_reason : null,
            ], static fn($v) => $v !== null && $v !== '' && $v !== []),
            'ref' => $message ? ['tool' => 'cms_comm_message_get', 'id' => (int)$message->id] : null,
            'dedup_key' => 'visit:'.(int)$visit->id,
        ];
    }
}
