<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\OverviewContributorInterface;

/**
 * Section "mailing" of the client overview: whether its contacts may get
 * advertising — the stop list (opted out of all, complaint, bounces), topics
 * it opted out of and consents. Whether a concrete letter may go is
 * cms_comm_mailing_check (MailingPolicy) with a topic.
 */
class MailingOverviewContributor implements OverviewContributorInterface
{
    public function contribute(array $records, CrmReadContext $context): array
    {
        $siteId = \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : 0;
        $topics = CmsCommTopic::find()->select(['name', 'id'])->andWhere(['cms_site_id' => $siteId])->indexBy('id')->column();
        $result = [];
        foreach ($records as $key => $record) {
            $byKind = [];
            $count = ['email' => 0, 'phone' => 0];
            foreach (MailingContacts::ofRecord($record->model) as $row) {
                $byKind[$row['kind']][] = $row['value'];
                $count[$row['kind']] = ($count[$row['kind']] ?? 0) + 1;
            }
            $ids = [];
            $stopped = [];
            foreach ($byKind as $kind => $values) {
                foreach (MailingContacts::findMany($siteId, $kind, $values) as $contact) {
                    $ids[(int)$contact->id] = $contact;
                }
            }
            $section = ['emails' => $count['email'], 'phones' => $count['phone'], 'stop_list' => [], 'unsubscribed_topics' => [], 'consent_topics' => []];
            if ($ids) {
                foreach (CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => array_keys($ids)])->all() as $row) {
                    $section['stop_list'][] = $row->reasonText().($ids[(int)$row->cms_comm_contact_id]->kind === CmsCommContact::KIND_PHONE ? ' (телефон)' : ' (email)');
                    $stopped[(int)$row->cms_comm_contact_id] = true;
                }
                foreach (CmsCommContactTopic::find()->andWhere(['cms_comm_contact_id' => array_keys($ids)])->all() as $row) {
                    $name = $topics[(int)$row->cms_comm_topic_id] ?? '#'.$row->cms_comm_topic_id;
                    if (!$row->isSubscribed()) {
                        $section['unsubscribed_topics'][] = $name;
                    } elseif ($row->hasConsent()) {
                        $section['consent_topics'][] = $name;
                    }
                }
            }
            $section['stop_list'] = array_values(array_unique($section['stop_list']));
            $section['unsubscribed_topics'] = array_values(array_unique($section['unsubscribed_topics']));
            $section['consent_topics'] = array_values(array_unique($section['consent_topics']));
            // Emails a manager may still write to personally with an advertising letter (cold or consent, not on the stop list).
            $emailIds = array_keys(array_filter($ids, static function ($c) { return $c->kind === CmsCommContact::KIND_EMAIL; }));
            $section['emails_on_stop_list'] = count(array_intersect($emailIds, array_keys($stopped)));
            $result[$key] = ['mailing' => $section];
        }
        return $result;
    }
}
