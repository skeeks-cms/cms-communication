<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use yii\helpers\Json;

/**
 * How fast campaigns may write from a mailbox, so that a manager's letters
 * stay personal letters for the mail services: not more than dailyLimit
 * letters a day counting the manager's own letters of the day (sent from
 * the CRM or from the mail service itself), a random pause of pauseMin to
 * pauseMax seconds between campaign letters, only in working hours (hourFrom
 * to hourTo on the days), the rest on the next working day. Settings are kept
 * at the mailbox (cms_comm_account.mailing_settings), "Рассылки" in its card.
 */
class MailboxPace
{
    public const DEFAULTS = [
        'dailyLimit' => 30,
        'pauseMin' => 60,
        'pauseMax' => 180,
        'hourFrom' => 9,
        'hourTo' => 18,
        'days' => [1, 2, 3, 4, 5],
    ];

    public static function dayNames(): array
    {
        return [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
    }

    /** The settings of the mailbox: its own values over DEFAULTS. */
    public static function settings(?CmsCommAccount $account): array
    {
        $own = [];
        if ($account && $account->hasAttribute('mailing_settings') && $account->mailing_settings) {
            $own = Json::decode((string)$account->mailing_settings);
        }

        return self::normalize(is_array($own) ? $own + self::DEFAULTS : self::DEFAULTS);
    }

    /**
     * Checks and saves the settings of the mailbox.
     *
     * @return string[] field => error; empty when saved
     */
    public static function save(CmsCommAccount $account, array $values): array
    {
        $errors = [];
        $limit = (int)($values['dailyLimit'] ?? 0);
        if ($limit < 1 || $limit > 2000) {
            $errors['dailyLimit'] = 'От 1 до 2000 писем в день.';
        }
        $min = (int)($values['pauseMin'] ?? -1);
        $max = (int)($values['pauseMax'] ?? -1);
        if ($min < 0 || $max < $min || $max > 3600) {
            $errors['pauseMin'] = 'Пауза — от 0 до 3600 секунд, «до» не меньше «от».';
        }
        $from = (int)($values['hourFrom'] ?? -1);
        $to = (int)($values['hourTo'] ?? -1);
        if ($from < 0 || $from > 23 || $to < 1 || $to > 24 || $to <= $from) {
            $errors['hourFrom'] = 'Часы — с 0 до 24, конец позже начала.';
        }
        $days = array_values(array_intersect(array_map('intval', (array)($values['days'] ?? [])), array_keys(self::dayNames())));
        if (!$days) {
            $errors['days'] = 'Выберите хотя бы один день.';
        }
        if ($errors) {
            return $errors;
        }
        $account->mailing_settings = Json::encode(self::normalize([
            'dailyLimit' => $limit, 'pauseMin' => $min, 'pauseMax' => $max, 'hourFrom' => $from, 'hourTo' => $to, 'days' => $days,
        ]));
        $account->save(false, ['mailing_settings', 'updated_at']);

        return [];
    }

    private static function normalize(array $s): array
    {
        $days = array_values(array_unique(array_map('intval', (array)$s['days'])));
        sort($days);

        return [
            'dailyLimit' => max(1, (int)$s['dailyLimit']),
            'pauseMin' => max(0, (int)$s['pauseMin']),
            'pauseMax' => max((int)$s['pauseMin'], (int)$s['pauseMax']),
            'hourFrom' => min(23, max(0, (int)$s['hourFrom'])),
            'hourTo' => min(24, max(1, (int)$s['hourTo'])),
            'days' => $days ?: self::DEFAULTS['days'],
        ];
    }

    /** "30 писем в день, паузы 1–3 мин, пн–пт 9:00–18:00". */
    public static function describe(array $s): string
    {
        $pause = $s['pauseMax'] >= 120 && $s['pauseMin'] % 60 === 0 && $s['pauseMax'] % 60 === 0
            ? ($s['pauseMin'] / 60).'–'.($s['pauseMax'] / 60).' мин'
            : $s['pauseMin'].'–'.$s['pauseMax'].' с';
        $names = self::dayNames();
        $days = $s['days'] === [1, 2, 3, 4, 5] ? 'пн–пт' : ($s['days'] === [1, 2, 3, 4, 5, 6, 7] ? 'каждый день'
            : implode(', ', array_map(static fn($d) => $names[$d], $s['days'])));

        return $s['dailyLimit'].' писем в день, паузы '.$pause.', '.$days.' '.$s['hourFrom'].':00–'.$s['hourTo'].':00';
    }

    /**
     * Letters that left the mailbox since the start of the day of $now: the
     * CRM letters of the mailbox and the letters received from its "Sent"
     * folder (written in the mail service), not failed ones.
     */
    public static function sentToday(int $accountId, int $now): int
    {
        $dayStart = self::dayStart($now);
        $t = CmsCommMessage::tableName();

        return (int)CmsCommMessage::find()
            ->andWhere([$t.'.direction' => CmsCommMessage::DIRECTION_OUT])
            ->andWhere(['>=', $t.'.message_at', $dayStart])
            ->andWhere(['not', [$t.'.status' => CmsCommMessage::STATUS_FAILED]])
            ->andWhere(['or',
                [$t.'.cms_comm_account_id' => $accountId],
                [$t.'.id' => CmsCommMessageMailbox::find()->select('cms_comm_message_id')->andWhere(['cms_comm_account_id' => $accountId])],
            ])->count();
    }

    /** When the last campaign letter of the mailbox left, or null. */
    public static function lastCampaignAt(int $accountId): ?int
    {
        $last = CmsCommCampaignRecipient::find()->andWhere(['cms_comm_account_id' => $accountId])
            ->andWhere(['not', ['sent_at' => null]])->max('sent_at');

        return $last ? (int)$last : null;
    }

    /** The earliest moment the next campaign letter may leave the mailbox. */
    public static function nextSlotFor(CmsCommAccount $account, int $now): int
    {
        return self::nextSlot(self::settings($account), self::sentToday((int)$account->id, $now), self::lastCampaignAt((int)$account->id), $now);
    }

    /**
     * The earliest moment the next letter may leave: after the pause since
     * the last campaign letter, not over the daily limit (then on the next
     * working day), within working hours.
     *
     * @param int $sentToday letters of the day of $now
     */
    public static function nextSlot(array $settings, int $sentToday, ?int $lastAt, int $now): int
    {
        $at = $now;
        if ($lastAt !== null) {
            $at = max($at, $lastAt + (int)$settings['pauseMin']);
        }
        if ($sentToday >= (int)$settings['dailyLimit']) {
            $at = max($at, self::dayStart($now, 1));
        }

        return self::workingTime($settings, $at);
    }

    /** $at if it is within working hours, otherwise the start of the next working period. */
    public static function workingTime(array $settings, int $at): int
    {
        for ($i = 0; $i <= 8; $i++) {
            $day = self::dayStart($at, $i);
            if (!in_array((int)(new \DateTimeImmutable('@'.$day))->setTimezone(self::timeZone())->format('N'), $settings['days'], true)) {
                continue;
            }
            $start = self::dayStart($at, $i, (int)$settings['hourFrom']);
            $end = self::dayStart($at, $i, (int)$settings['hourTo']);
            if ($at < $end) {
                return max($at, $start);
            }
        }

        return $at;
    }

    /**
     * Days and hours are those of the CRM (the formatter's time zone, as core
     * reports and task schedules count them): a console worker may run in UTC.
     */
    public static function timeZone(): \DateTimeZone
    {
        $formatter = \Yii::$app->has('formatter') ? \Yii::$app->formatter : null;

        return new \DateTimeZone(($formatter && $formatter->timeZone) ? (string)$formatter->timeZone : (string)\Yii::$app->timeZone);
    }

    /** The moment $hour o'clock of the day of $at plus $days days, in the CRM time zone. */
    public static function dayStart(int $at, int $days = 0, int $hour = 0): int
    {
        $date = (new \DateTimeImmutable('@'.$at))->setTimezone(self::timeZone())->setTime(0, 0);
        if ($days) {
            $date = $date->modify('+'.$days.' day');
        }

        return $date->modify('+'.$hour.' hour')->getTimestamp();
    }

    /** A random pause before the next letter, seconds. */
    public static function pause(array $settings): int
    {
        return random_int((int)$settings['pauseMin'], (int)$settings['pauseMax']);
    }
}
