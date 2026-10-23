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

/**
 * "Можно ли отправить рекламу этой темы на этот контакт этим каналом":
 * the one check every advertising send asks (campaigns of 4B, mailing
 * services of 4C), with the reason of a refusal for the summary of excluded
 * recipients. Service messages and personal letters never ask it.
 *
 * Two ways of sending:
 * - MODE_MANAGER — small personal letters from a manager's mailbox: a cold
 *   contact (no consent, no marks) may get them, with an opt-out link;
 * - MODE_SERVICE — a mailing service: only a real consent (38-ФЗ; services
 *   block accounts for cold mailings).
 *
 * The result: ['allowed' => bool, 'reason' => REASON_*|null, 'message' => text].
 */
class MailingPolicy
{
    public const MODE_MANAGER = 'manager';
    public const MODE_SERVICE = 'service';

    public const REASON_INVALID = 'invalid';
    public const REASON_TOPIC = 'topic';
    public const REASON_UNSUBSCRIBED_ALL = CmsCommSuppression::REASON_ALL;
    public const REASON_COMPLAINT = CmsCommSuppression::REASON_COMPLAINT;
    public const REASON_BOUNCE = CmsCommSuppression::REASON_BOUNCE;
    public const REASON_UNSUBSCRIBED = 'unsubscribed';
    public const REASON_NO_CONSENT = 'no_consent';

    /** Channels of advertising and the kind of contact they reach. */
    public const CHANNEL_KINDS = ['email' => CmsCommContact::KIND_EMAIL, 'sms' => CmsCommContact::KIND_PHONE];

    /** @return string[] reason => text for the summary of excluded recipients */
    public static function reasons(): array
    {
        return [
            self::REASON_INVALID => 'Нет адреса или номера для этого канала',
            self::REASON_TOPIC => 'Тема рассылки не найдена или выключена',
            self::REASON_UNSUBSCRIBED_ALL => 'Отписан от всех рекламных рассылок',
            self::REASON_COMPLAINT => 'Пожаловался на рассылку',
            self::REASON_BOUNCE => 'Письма не доставляются',
            self::REASON_UNSUBSCRIBED => 'Отписан от этой темы',
            self::REASON_NO_CONSENT => 'Нет согласия на рассылку (для сервиса рассылок оно обязательно)',
        ];
    }

    /** The kind of contact of a channel ("email" → email, "sms" → phone), null for an unknown channel. */
    public static function kindOf(string $channel): ?string
    {
        return self::CHANNEL_KINDS[$channel] ?? null;
    }

    public static function check(int $siteId, string $channel, string $value, int $topicId, string $mode = self::MODE_MANAGER): array
    {
        return self::checkMany($siteId, $channel, [$value], $topicId, $mode)[$value];
    }

    /**
     * The same check for many contacts of one channel in a few queries.
     *
     * @param string[] $values as given by the caller
     * @return array[] given value => result
     */
    public static function checkMany(int $siteId, string $channel, array $values, int $topicId, string $mode = self::MODE_MANAGER): array
    {
        $kind = self::kindOf($channel);
        $topic = $topicId > 0 ? CmsCommTopic::findOne(['id' => $topicId, 'cms_site_id' => $siteId]) : null;
        $result = [];
        $normalized = [];
        foreach ($values as $value) {
            $value = (string)$value;
            $n = $kind ? CmsCommContact::normalize($kind, $value) : null;
            if ($n === null) {
                $result[$value] = self::refuse(self::REASON_INVALID);
            } elseif (!$topic || !$topic->is_active) {
                $result[$value] = self::refuse(self::REASON_TOPIC);
            } else {
                $normalized[$value] = $n;
            }
        }
        if (!$normalized) {
            return $result;
        }

        $contacts = MailingContacts::findMany($siteId, $kind, array_values($normalized));
        $ids = array_map(static fn($c) => (int)$c->id, $contacts);
        $suppressed = [];
        $states = [];
        if ($ids) {
            // The order of reasons when a contact has several: the client's own word first.
            $order = array_flip([self::REASON_UNSUBSCRIBED_ALL, self::REASON_COMPLAINT, self::REASON_BOUNCE]);
            foreach (CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => $ids])->all() as $row) {
                $id = (int)$row->cms_comm_contact_id;
                if (!isset($suppressed[$id]) || ($order[$row->reason] ?? 9) < ($order[$suppressed[$id]] ?? 9)) {
                    $suppressed[$id] = (string)$row->reason;
                }
            }
            foreach (CmsCommContactTopic::find()->andWhere(['cms_comm_contact_id' => $ids, 'cms_comm_topic_id' => (int)$topic->id])->all() as $row) {
                $states[(int)$row->cms_comm_contact_id] = $row;
            }
        }

        foreach ($normalized as $value => $n) {
            $contact = $contacts[$kind.':'.$n] ?? null;
            $id = $contact ? (int)$contact->id : 0;
            $state = $states[$id] ?? null;
            if (isset($suppressed[$id])) {
                $result[$value] = self::refuse($suppressed[$id]);
            } elseif ($state && !$state->isSubscribed()) {
                $result[$value] = self::refuse(self::REASON_UNSUBSCRIBED);
            } elseif ($mode === self::MODE_SERVICE && (!$state || !$state->hasConsent())) {
                $result[$value] = self::refuse(self::REASON_NO_CONSENT);
            } else {
                $result[$value] = [
                    'allowed' => true,
                    'reason' => null,
                    'message' => $state && $state->hasConsent() ? 'Есть согласие' : 'Холодный контакт: только личные письма менеджера',
                    'consent' => $state ? (string)$state->basis : null,
                ];
            }
        }

        return $result;
    }

    /**
     * Contacts on the stop list, for the warning in "Написать" and "Отправить
     * клиенту": the personal letter is not blocked, the manager is told.
     *
     * @param string[] $values emails or phones as given
     * @return string[] given value => why ("Отписан от всех рекламных рассылок")
     */
    public static function warnings(int $siteId, string $kind, array $values): array
    {
        $contacts = MailingContacts::findMany($siteId, $kind, $values);
        if (!$contacts) {
            return [];
        }
        $byId = [];
        foreach ($contacts as $contact) {
            $byId[(int)$contact->id] = $contact;
        }
        $reasons = [];
        foreach (CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => array_keys($byId)])->all() as $row) {
            $reasons[(string)$byId[(int)$row->cms_comm_contact_id]->value][] = $row->reasonText();
        }
        $result = [];
        foreach ($values as $value) {
            $n = CmsCommContact::normalize($kind, (string)$value);
            if ($n !== null && isset($reasons[$n])) {
                $result[(string)$value] = implode('; ', array_unique($reasons[$n]));
            }
        }

        return $result;
    }

    private static function refuse(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason, 'message' => self::reasons()[$reason] ?? $reason, 'consent' => null];
    }
}
