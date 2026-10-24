<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\components;

use skeeks\cms\base\Component;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\TextareaField;

/**
 * Visits of our sites by links of letters ("Настройки почты" → «Переходы
 * из писем», step F): whether letters get marks and the personal code of
 * the recipient in links to our domains, which domains are ours, and
 * whether the sender gets a call task when the client comes. Read for a
 * site through forSite() (also in jobs, see component-settings.md).
 */
class LinkTrackingSettings extends Component
{
    /** Marks and the personal code in links to our domains. */
    public $enabled = 1;

    /** Our domains, one per line; empty — the domains of the site. Subdomains are included. */
    public $domains = '';

    /** "Позвонить: клиент перешёл на сайт из письма" for the sender at the first visit by a letter. */
    public $taskOnVisit = 1;

    /** @var array site id => [enabled, domains, taskOnVisit], within one run */
    private static array $cache = [];

    public function rules()
    {
        return array_merge(parent::rules(), [
            [['enabled', 'taskOnVisit'], 'boolean'],
            [['domains'], 'string', 'max' => 2000],
            [['domains'], function ($attribute) {
                foreach (self::parseDomains((string)$this->$attribute) as $domain) {
                    if (!preg_match('~^(?=.{1,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$~', $domain)) {
                        $this->addError($attribute, 'Не похоже на домен: '.$domain);
                    }
                }
            }],
        ]);
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'enabled' => 'Размечать ссылки на наши сайты в письмах',
            'domains' => 'Наши сайты',
            'taskOnVisit' => 'Задача менеджеру, когда клиент перешёл на сайт',
        ]);
    }

    public function attributeHints()
    {
        return array_merge(parent::attributeHints(), [
            'enabled' => 'При отправке любого письма из CRM к ссылкам на эти сайты добавляются метки utm_source=crm, utm_medium=email, '
                .'utm_campaign (номер кампании, предложения или personal) и личный код получателя. Метки, которые поставил менеджер, '
                .'не меняются; ссылки на другие сайты, почту и телефоны остаются как есть.',
            'domains' => 'По одному домену в строке, например skeeks.com. Поддомены входят сами. Пусто — домены этого сайта. '
                .'Сайт на другой установке должен подключить скрипт учёта переходов этого сайта (см. описание пакета).',
            'taskOnVisit' => 'Тому, кто отправил письмо, при первом переходе клиента по ссылке ставится задача «Позвонить» на сегодня '
                .'(в нерабочее время — на ближайший рабочий день). Не ставится, если у него уже есть открытая задача «Позвонить» '
                .'по этому клиенту, например по открытому предложению. Для писем рассылки решает настройка кампании.',
        ]);
    }

    /** Own form id: the work timer form in the header is also "w0". */
    public function beginConfigForm()
    {
        return \skeeks\cms\modules\admin\widgets\form\ActiveFormUseTab::begin([
            'id'                     => 'sx-link-tracking-settings',
            'enableAjaxValidation'   => false,
            'enableClientValidation' => false,
        ]);
    }

    public function getConfigFormFields()
    {
        return [
            'enabled' => ['class' => BoolField::class, 'allowNull' => false],
            'domains' => ['class' => TextareaField::class],
            'taskOnVisit' => ['class' => BoolField::class, 'allowNull' => false],
        ];
    }

    /** @return string[] lower-case domains without "www." and a scheme */
    public static function parseDomains(string $value): array
    {
        $result = [];
        foreach (preg_split('~[\s,;]+~u', mb_strtolower($value)) as $domain) {
            $domain = (string)preg_replace('~^[a-z]+://~', '', trim($domain));
            $domain = rtrim((string)preg_replace('~[/:].*$~', '', $domain), '.');
            $domain = (string)preg_replace('~^www\.~', '', $domain);
            if ($domain !== '') {
                $result[$domain] = $domain;
            }
        }

        return array_values($result);
    }

    /**
     * The settings of a site as the settings page stores them.
     *
     * @return array enabled (bool), domains (string[], the site domains when none is set), taskOnVisit (bool)
     */
    public static function forSite(int $siteId): array
    {
        if (!array_key_exists($siteId, self::$cache)) {
            $defaults = (new \ReflectionClass(self::class))->getDefaultProperties();
            $values = ['enabled' => $defaults['enabled'], 'domains' => $defaults['domains'], 'taskOnVisit' => $defaults['taskOnVisit']];
            try {
                $settings = new self();
                if ($site = \skeeks\cms\models\CmsSite::findOne($siteId)) {
                    $settings->cmsSite = $site;
                }
                $settings->refresh();
                $values = ['enabled' => $settings->enabled, 'domains' => $settings->domains, 'taskOnVisit' => $settings->taskOnVisit];
            } catch (\Throwable $e) {
                // No site schema (isolated tests): the defaults.
            }
            $domains = self::parseDomains((string)$values['domains']);
            if (!$domains) {
                try {
                    $domains = self::parseDomains(implode("\n", \skeeks\cms\models\CmsSiteDomain::find()->select('domain')
                        ->andWhere(['cms_site_id' => $siteId])->column()));
                } catch (\Throwable $e) {
                    $domains = [];
                }
            }
            self::$cache[$siteId] = [
                'enabled' => (bool)$values['enabled'],
                'domains' => $domains,
                'taskOnVisit' => (bool)$values['taskOnVisit'],
            ];
        }

        return self::$cache[$siteId];
    }

    /** After saving, or in tests: the next read loads the stored values (or takes the given ones). */
    public static function reset(?int $siteId = null, ?array $values = null): void
    {
        if ($siteId === null) {
            self::$cache = [];
        } elseif ($values === null) {
            unset(self::$cache[$siteId]);
        } else {
            self::$cache[$siteId] = $values + ['enabled' => true, 'domains' => [], 'taskOnVisit' => true];
        }
    }
}
