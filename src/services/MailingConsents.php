<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use yii\db\IntegrityException;

/**
 * Consent and opt-out of contacts: the only writer of CmsCommContactTopic
 * and CmsCommSuppression. Used by the card window of a manager, the opt-out
 * page and one click of a mail program (services\Unsubscribe), the import of
 * recipients (services\LeadImport) and later mailing services (4C).
 *
 * Rules:
 * - an opt-out of a client is not undone by an import or a cold mark; only
 *   the client (the opt-out page) or a manager who marks a new consent
 *   explicitly (`$force`) brings it back;
 * - a cold mark never weakens a real consent;
 * - the stop list (unsubscribed of all, complaint, undeliverable) is apart
 *   from topics: it stops every advertising letter whatever the topics say.
 */
class MailingConsents
{
    /**
     * Marks the contact subscribed to the topic on this basis.
     *
     * @param bool $force a manager's explicit choice: overrides an opt-out and a stronger basis
     * @return bool whether the state changed
     */
    public static function subscribe(CmsCommContact $contact, int $topicId, string $basis, string $source, ?int $userId = null, ?string $note = null, bool $force = false): bool
    {
        if (!isset(CmsCommContactTopic::bases()[$basis])) {
            throw new \InvalidArgumentException("Неизвестное основание согласия «{$basis}».");
        }
        $state = self::row($contact, $topicId);
        if (!$state->isNewRecord) {
            if ($state->isSubscribed() && $state->basis === $basis) {
                return false;
            }
            // An opt-out stays, a real consent is not weakened to a cold mark.
            if (!$force && (!$state->isSubscribed() || ($basis === CmsCommContactTopic::BASIS_COLD && $state->basis !== CmsCommContactTopic::BASIS_COLD))) {
                return false;
            }
        }
        $state->status = CmsCommContactTopic::STATUS_SUBSCRIBED;
        $state->basis = $basis;
        $state->consent_at = time();
        $state->consent_by = $userId ?: null;
        $state->note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;

        return self::store($state);
    }

    /**
     * Opts the contact out of the topic.
     *
     * @return bool whether the state changed
     */
    public static function unsubscribe(CmsCommContact $contact, int $topicId, string $source, ?int $userId = null, ?int $messageId = null): bool
    {
        $state = self::row($contact, $topicId);
        if (!$state->isNewRecord && !$state->isSubscribed()) {
            return false;
        }
        $state->status = CmsCommContactTopic::STATUS_UNSUBSCRIBED;
        $state->unsubscribed_at = time();
        $state->unsubscribed_by = $userId ?: null;
        $state->unsubscribe_source = $source;
        $state->cms_comm_message_id = $messageId ?: null;

        return self::store($state);
    }

    /** Removes the marks of the topic: the contact has neither consent nor an opt-out for it. */
    public static function clear(CmsCommContact $contact, int $topicId): bool
    {
        return (bool)CmsCommContactTopic::deleteAll(['cms_comm_contact_id' => (int)$contact->id, 'cms_comm_topic_id' => $topicId]);
    }

    /**
     * Puts the contact on the stop list for the reason. Repeating changes nothing.
     *
     * @return bool whether a row was added
     */
    public static function suppress(CmsCommContact $contact, string $reason, string $source, ?int $userId = null, ?int $messageId = null, ?string $note = null): bool
    {
        if (!isset(CmsCommSuppression::reasons()[$reason])) {
            throw new \InvalidArgumentException("Неизвестная причина стоп-листа «{$reason}».");
        }
        if (CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => (int)$contact->id, 'reason' => $reason])->exists()) {
            return false;
        }
        $row = new CmsCommSuppression([
            'created_at' => time(),
            'created_by' => $userId ?: null,
            'cms_site_id' => (int)$contact->cms_site_id,
            'cms_comm_contact_id' => (int)$contact->id,
            'reason' => $reason,
            'source' => $source,
            'cms_comm_message_id' => $messageId ?: null,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
        ]);
        try {
            return $row->save(false);
        } catch (IntegrityException $e) {
            // Put there by a parallel request a moment ago.
            return false;
        }
    }

    /** Takes the contact off the stop list for the reason. */
    public static function unsuppress(CmsCommContact $contact, string $reason): bool
    {
        return (bool)CmsCommSuppression::deleteAll(['cms_comm_contact_id' => (int)$contact->id, 'reason' => $reason]);
    }

    /**
     * Everything recorded for the contact.
     *
     * @return array{topics: CmsCommContactTopic[], suppressions: CmsCommSuppression[]} topics by topic id, suppressions by reason
     */
    public static function state(?CmsCommContact $contact): array
    {
        $result = ['topics' => [], 'suppressions' => []];
        if (!$contact) {
            return $result;
        }
        foreach (CmsCommContactTopic::find()->andWhere(['cms_comm_contact_id' => (int)$contact->id])->all() as $row) {
            $result['topics'][(int)$row->cms_comm_topic_id] = $row;
        }
        foreach (CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => (int)$contact->id])->all() as $row) {
            $result['suppressions'][(string)$row->reason] = $row;
        }

        return $result;
    }

    /**
     * Short state of the contact for a card row: "Отписан от всех рекламных
     * рассылок" or the topics by state ("Подписан: Акции и скидки (устно)").
     *
     * @param CmsCommTopic[] $topics
     * @return string[] lines
     */
    public static function summary(?CmsCommContact $contact, array $topics): array
    {
        $state = self::state($contact);
        $lines = [];
        foreach ($state['suppressions'] as $suppression) {
            $lines[] = $suppression->reasonText();
        }
        $groups = [];
        foreach ($topics as $topic) {
            $row = $state['topics'][(int)$topic->id] ?? null;
            if (!$row) {
                continue;
            }
            $label = $row->stateText();
            $name = (string)$topic->name;
            if ($row->isSubscribed() && $row->basis !== CmsCommContactTopic::BASIS_COLD) {
                $name .= $row->basis === CmsCommContactTopic::BASIS_VERBAL ? ' (устно)' : ' (на сайте)';
            }
            $groups[$label][] = $name;
        }
        foreach ($groups as $label => $names) {
            $lines[] = $label.': '.implode(', ', $names);
        }

        return $lines;
    }

    private static function row(CmsCommContact $contact, int $topicId): CmsCommContactTopic
    {
        $topic = CmsCommTopic::findOne($topicId);
        if (!$topic || (int)$topic->cms_site_id !== (int)$contact->cms_site_id) {
            throw new \InvalidArgumentException('Тема рассылки не найдена.');
        }

        return CmsCommContactTopic::findOne(['cms_comm_contact_id' => (int)$contact->id, 'cms_comm_topic_id' => $topicId])
            ?: new CmsCommContactTopic(['cms_comm_contact_id' => (int)$contact->id, 'cms_comm_topic_id' => $topicId]);
    }

    private static function store(CmsCommContactTopic $state): bool
    {
        try {
            return $state->save(false);
        } catch (IntegrityException $e) {
            // A parallel request made the row: apply the change to it.
            $existing = CmsCommContactTopic::findOne(['cms_comm_contact_id' => $state->cms_comm_contact_id, 'cms_comm_topic_id' => $state->cms_comm_topic_id]);
            if (!$existing) {
                throw $e;
            }
            $existing->setAttributes($state->getDirtyAttributes(), false);

            return $existing->save(false);
        }
    }
}
