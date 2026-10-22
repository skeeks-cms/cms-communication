<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\forms;

use yii\base\Model;

/**
 * "Сбор писем" of a mailbox: the period whose letters are collected.
 */
class MailHistoryForm extends Model
{
    public const PERIOD_ALL = 'all';
    public const PERIOD_DATE = 'date';

    public $period = '365';

    /** Y-m-d, for "С даты…". */
    public $date;

    public static function periods(): array
    {
        return [
            '90' => 'За 3 месяца',
            '180' => 'За полгода',
            '365' => 'За год',
            '730' => 'За 2 года',
            '1095' => 'За 3 года',
            self::PERIOD_ALL => 'Все письма ящика',
            self::PERIOD_DATE => 'С даты…',
        ];
    }

    public function rules()
    {
        return [
            [['period'], 'required'],
            [['period'], 'in', 'range' => array_keys(self::periods())],
            [['date'], 'required', 'when' => fn() => $this->period === self::PERIOD_DATE, 'message' => 'Укажите дату.'],
            [['date'], 'date', 'format' => 'php:Y-m-d'],
        ];
    }

    public function attributeLabels()
    {
        return ['period' => 'Собрать письма', 'date' => 'С даты'];
    }

    /** @return int the earliest letter date to collect (0 — every letter) */
    public function since(): int
    {
        if ($this->period === self::PERIOD_ALL) {
            return 0;
        }
        if ($this->period === self::PERIOD_DATE) {
            return (int)strtotime((string)$this->date.' 00:00:00');
        }

        return strtotime('today') - (int)$this->period * 86400;
    }

    /** "за год", "с 01.03.2025", "за всё время" — for titles and history. */
    public static function describe(int $since): string
    {
        if ($since <= 0) {
            return 'за всё время';
        }

        return 'с '.\Yii::$app->formatter->asDate($since, 'php:d.m.Y');
    }
}
