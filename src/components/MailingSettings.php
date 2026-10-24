<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\components;

use skeeks\cms\base\Component;
use skeeks\yii2\form\fields\NumberField;

/**
 * Rules of every mailing of the site ("Рассылки" → «Настройки»): how often
 * one contact may get an advertising letter of any campaign. The summary
 * before launch and the check before every letter read it
 * (services\Campaigns); jobs read it through forSite().
 */
class MailingSettings extends Component
{
    /** No more than one advertising letter to a contact in this many days, over all campaigns. 0 — no limit. */
    public $frequencyDays = 7;

    /** @var array site id => days, within one run */
    private static array $cache = [];

    public function rules()
    {
        return array_merge(parent::rules(), [
            [['frequencyDays'], 'integer', 'min' => 0, 'max' => 365],
        ]);
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'frequencyDays' => 'Одному контакту — не больше одной рассылки за, дней',
        ]);
    }

    public function attributeHints()
    {
        return array_merge(parent::attributeHints(), [
            'frequencyDays' => 'По всем кампаниям сайта: кто получил рекламное письмо за этот срок или ждёт его в другой идущей кампании, '
                .'будет исключён с причиной «Недавно получал рассылку». Личные и служебные письма не считаются. 0 — без ограничения.',
        ]);
    }

    /** Own form id: the work timer form in the header is also "w0". */
    public function beginConfigForm()
    {
        return \skeeks\cms\modules\admin\widgets\form\ActiveFormUseTab::begin([
            'id'                     => 'sx-mailing-settings',
            'enableAjaxValidation'   => false,
            'enableClientValidation' => false,
        ]);
    }

    public function getConfigFormFields()
    {
        return ['frequencyDays' => ['class' => NumberField::class]];
    }

    /**
     * Days between advertising letters to one contact for a site, read as the
     * settings page stores them (also in jobs, see component-settings.md).
     */
    public static function frequencyDays(int $siteId): int
    {
        if (!array_key_exists($siteId, self::$cache)) {
            $days = (int)(new \ReflectionClass(self::class))->getDefaultProperties()['frequencyDays'];
            try {
                $settings = new self();
                if ($site = \skeeks\cms\models\CmsSite::findOne($siteId)) {
                    $settings->cmsSite = $site;
                }
                $settings->refresh();
                $days = (int)$settings->frequencyDays;
            } catch (\Throwable $e) {
                // No site schema (isolated tests): the default.
            }
            self::$cache[$siteId] = max(0, $days);
        }

        return self::$cache[$siteId];
    }

    /** After saving, or in tests: the next read loads the stored value. */
    public static function reset(?int $siteId = null, ?int $days = null): void
    {
        if ($siteId === null) {
            self::$cache = [];
        } elseif ($days === null) {
            unset(self::$cache[$siteId]);
        } else {
            self::$cache[$siteId] = $days;
        }
    }
}
