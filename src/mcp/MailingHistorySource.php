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
use skeeks\cms\mcp\crm\CrmActor;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\HistorySourceInterface;

/**
 * Opt-outs and consents of the client's contacts in the client history:
 * "unsubscribe" — an opt-out of a topic, of all mailings, a complaint or an
 * address that does not take letters (the stop list); "consent" — a
 * consent to a topic. They belong to the contact (CmsCommContact), so a
 * client sees the marks of every email and phone of its card.
 */
class MailingHistorySource implements HistorySourceInterface
{
    /** @var array record key => contact ids */
    private $contacts = [];

    public function types(): array
    {
        return ['unsubscribe', 'consent'];
    }

    public function entries(CrmReadContext $context): array
    {
        $ids = $this->contactIds($context);
        if (!$ids) {
            return [];
        }
        $contacts = CmsCommContact::find()->andWhere(['id' => $ids])->indexBy('id')->all();
        $topics = CmsCommTopic::find()->select(['name', 'id'])->indexBy('id')->column();
        $value = static function (CmsCommContact $c) use ($context) {
            return $c->kind === CmsCommContact::KIND_PHONE ? $context->phone((string)$c->value) : $context->email((string)$c->value);
        };
        $result = [];
        if ($context->wants('unsubscribe')) {
            foreach ($this->topicQuery($context, 'unsubscribed_at')->all() as $row) {
                $contact = $contacts[(int)$row->cms_comm_contact_id];
                $result[] = [
                    'at' => (int)$row->unsubscribed_at,
                    'type' => 'unsubscribe',
                    'subtype' => 'topic',
                    'title' => 'Отписка от рассылки «'.($topics[(int)$row->cms_comm_topic_id] ?? '#'.$row->cms_comm_topic_id).'»',
                    'summary' => 'Контакт '.$value($contact).'; '.(CmsCommContactTopic::sources()[$row->unsubscribe_source] ?? (string)$row->unsubscribe_source),
                    'channel' => $contact->kind === CmsCommContact::KIND_PHONE ? 'sms' : 'email',
                    'actor' => $row->unsubscribed_by ? CrmActor::user((int)$row->unsubscribed_by, $context) : ['kind' => CrmActor::CLIENT],
                ];
            }
            foreach ($this->periodQuery(CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => $ids]), 'created_at', $context)->all() as $row) {
                $contact = $contacts[(int)$row->cms_comm_contact_id];
                $result[] = [
                    'at' => (int)$row->created_at,
                    'type' => 'unsubscribe',
                    'subtype' => (string)$row->reason,
                    'title' => $row->reasonText(),
                    'summary' => 'Контакт '.$value($contact).' в стоп-листе рекламных рассылок',
                    'channel' => $contact->kind === CmsCommContact::KIND_PHONE ? 'sms' : 'email',
                    'actor' => $row->created_by ? CrmActor::user((int)$row->created_by, $context) : ['kind' => CrmActor::CLIENT],
                ];
            }
        }
        if ($context->wants('consent')) {
            foreach ($this->topicQuery($context, 'consent_at')->andWhere(['not', ['basis' => CmsCommContactTopic::BASIS_COLD]])->all() as $row) {
                $contact = $contacts[(int)$row->cms_comm_contact_id];
                $result[] = [
                    'at' => (int)$row->consent_at,
                    'type' => 'consent',
                    'subtype' => (string)$row->basis,
                    'title' => 'Согласие на рассылку «'.($topics[(int)$row->cms_comm_topic_id] ?? '#'.$row->cms_comm_topic_id).'»',
                    'summary' => 'Контакт '.$value($contact).'; '.(CmsCommContactTopic::bases()[$row->basis] ?? (string)$row->basis),
                    'channel' => $contact->kind === CmsCommContact::KIND_PHONE ? 'sms' : 'email',
                    'actor' => $row->consent_by ? CrmActor::user((int)$row->consent_by, $context) : ['kind' => CrmActor::CLIENT],
                ];
            }
        }
        usort($result, static function ($x, $y) { return $y['at'] <=> $x['at']; });
        return array_slice($result, 0, $context->fetchLimit);
    }

    public function count(CrmReadContext $context): int
    {
        $ids = $this->contactIds($context);
        if (!$ids) {
            return 0;
        }
        $count = 0;
        if ($context->wants('unsubscribe')) {
            $count += (int)$this->topicQuery($context, 'unsubscribed_at')->count();
            $count += (int)$this->periodQuery(CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => $ids]), 'created_at', $context)->count();
        }
        if ($context->wants('consent')) {
            $count += (int)$this->topicQuery($context, 'consent_at')->andWhere(['not', ['basis' => CmsCommContactTopic::BASIS_COLD]])->count();
        }
        return $count;
    }

    protected function topicQuery(CrmReadContext $context, string $attribute)
    {
        $query = CmsCommContactTopic::find()->andWhere(['cms_comm_contact_id' => $this->contactIds($context)])->andWhere(['not', [$attribute => null]]);
        if ($attribute === 'unsubscribed_at') {
            $query->andWhere(['status' => CmsCommContactTopic::STATUS_UNSUBSCRIBED]);
        }
        return $this->periodQuery($query, $attribute, $context);
    }

    protected function periodQuery($query, string $attribute, CrmReadContext $context)
    {
        [$from, $to] = $context->period();
        if ($from) {
            $query->andWhere(['>=', $attribute, $from]);
        }
        if ($to) {
            $query->andWhere(['<=', $attribute, $to]);
        }
        return $query->orderBy([$attribute => SORT_DESC])->limit($context->fetchLimit);
    }

    /** Contacts that have marks, of the emails and phones of the record card. */
    protected function contactIds(CrmReadContext $context): array
    {
        $record = $context->record;
        if (!$record) {
            return [];
        }
        if (!isset($this->contacts[$record->getKey()])) {
            $siteId = \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : 0;
            $ids = [];
            $byKind = [];
            foreach (MailingContacts::ofRecord($record->model) as $row) {
                $byKind[$row['kind']][] = $row['value'];
            }
            foreach ($byKind as $kind => $values) {
                foreach (MailingContacts::findMany($siteId, $kind, $values) as $contact) {
                    $ids[] = (int)$contact->id;
                }
            }
            $this->contacts[$record->getKey()] = $ids;
        }
        return $this->contacts[$record->getKey()];
    }
}
