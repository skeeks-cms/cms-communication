<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\components;

use skeeks\cms\base\Component;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\NumberField;

/**
 * Work of AI clients through MCP/REST ("Настройки почты" → «Работа ИИ через
 * API»): whether letters may be sent through the API at all, and the limits
 * of services\AiActions and services\LetterPurpose. A value set here wins;
 * an empty field takes the site configuration (communication.aiLimits), then
 * the defaults (AiActions::LIMITS, LetterPurpose::DAYS). Read for a site
 * through forSite() (component-settings.md).
 */
class AiApiSettings extends Component
{
    /** Letters may leave through the API (cms_comm_draft_send) on this site. */
    public $sendEnabled = 1;

    /** Letters one employee sends through the API a day; empty — the configuration. */
    public $sendPerDay;

    /** Drafts and campaign recipients one employee prepares through the API a day. */
    public $perDay;

    /** Drafts or campaign recipients in one call. */
    public $perCall;

    /** No new letter or offer to a client written to or offered within these days; 0 — no such check. */
    public $recentDays;

    /** Days a client's own letter keeps work correspondence open (purpose personal). */
    public $personalDays;

    /** Days a paid bill keeps cooperation (purpose service). */
    public $serviceDays;

    /** The limits this page sets; the keys of AiActions::LIMITS and LetterPurpose::DAYS. */
    public const LIMITS = ['sendPerDay', 'perDay', 'perCall', 'recentDays', 'personalDays', 'serviceDays'];

    /** @var array site id => [sendEnabled, limits set in the CRM], within one run */
    private static array $cache = [];

    public function rules()
    {
        return array_merge(parent::rules(), [
            [['sendEnabled'], 'boolean'],
            [self::LIMITS, 'filter', 'filter' => static fn($v) => trim((string)$v) === '' ? null : $v],
            [['sendPerDay', 'perDay', 'perCall', 'recentDays'], 'integer', 'min' => 0, 'max' => 10000],
            [['personalDays', 'serviceDays'], 'integer', 'min' => 1, 'max' => 3650],
        ]);
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'sendEnabled' => 'Разрешить отправку писем через API',
            'sendPerDay' => 'Писем в день на сотрудника',
            'perDay' => 'Черновиков и получателей кампаний в день на сотрудника',
            'perCall' => 'Черновиков или получателей за один запрос',
            'recentDays' => 'Не писать повторно, дней',
            'personalDays' => 'Рабочая переписка открыта, дней',
            'serviceDays' => 'Сотрудничество после оплаты, дней',
        ]);
    }

    public function attributeHints()
    {
        $empty = ' Пусто — значение из конфигурации сайта или по умолчанию (%s).';

        return array_merge(parent::attributeHints(), [
            'sendEnabled' => 'Выключено — подключённый помощник может готовить черновики, но отправить письмо клиенту не может: '
                .'проверка и отправка отказывают с причиной «отправка через API выключена». Сотрудники отправляют письма из «Почты» как обычно.',
            'sendPerDay' => 'Сколько писем клиентам один сотрудник может отправить через подключённого помощника за сутки (по часовому поясу CRM). '
                .'0 — отправка через API никому не доступна.'.sprintf($empty, 20),
            'perDay' => 'Сколько черновиков писем и получателей кампаний вместе один сотрудник может подготовить через помощника за сутки. '
                .'0 — помощник ничего не готовит.'.sprintf($empty, 100),
            'perCall' => 'Сколько писем или получателей можно передать в одном запросе: остальные строки получат отказ.'.sprintf($empty, 20),
            'recentDays' => 'Если клиенту писали или отправляли это предложение за указанное число дней, новое рекламное письмо и '
                .'индивидуальное предложение не готовятся и не отправляются. 0 — без этой проверки.'.sprintf($empty, 30),
            'personalDays' => 'Письмо «рабочая переписка» можно отправить клиенту, который сам писал нам за это число дней.'.sprintf($empty, 90),
            'serviceDays' => 'Служебное письмо можно отправить клиенту с действующей сделкой или со счётом, оплаченным за это число дней.'.sprintf($empty, 365),
        ]);
    }

    /** Own form id: the work timer form in the header is also "w0". */
    public function beginConfigForm()
    {
        return \skeeks\cms\modules\admin\widgets\form\ActiveFormUseTab::begin([
            'id'                     => 'sx-ai-api-settings',
            'enableAjaxValidation'   => false,
            'enableClientValidation' => false,
        ]);
    }

    public function getConfigFormFields()
    {
        $number = static fn(int $min) => ['class' => NumberField::class, 'elementOptions' => ['min' => $min, 'step' => 1, 'placeholder' => 'по умолчанию']];

        return [
            'sendEnabled' => ['class' => BoolField::class, 'allowNull' => false],
            'sendPerDay' => $number(0),
            'perDay' => $number(0),
            'perCall' => $number(0),
            'recentDays' => $number(0),
            'personalDays' => $number(1),
            'serviceDays' => $number(1),
        ];
    }

    /**
     * The settings of a site as the page stores them.
     *
     * @return array sendEnabled (bool), limits (int[] — only the limits set in the CRM)
     */
    public static function forSite(?int $siteId = null): array
    {
        try {
            $siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
        } catch (\Throwable $e) {
            $siteId = 0;
        }
        if (!array_key_exists($siteId, self::$cache)) {
            $result = ['sendEnabled' => true, 'limits' => []];
            try {
                $settings = new self();
                if ($site = \skeeks\cms\models\CmsSite::findOne($siteId)) {
                    $settings->cmsSite = $site;
                }
                $settings->refresh();
                $result['sendEnabled'] = (bool)$settings->sendEnabled;
                foreach (self::LIMITS as $key) {
                    if ($settings->$key !== null && trim((string)$settings->$key) !== '') {
                        $result['limits'][$key] = (int)$settings->$key;
                    }
                }
            } catch (\Throwable $e) {
                // No site schema (isolated tests): the configuration and the defaults.
            }
            self::$cache[$siteId] = $result;
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
            self::$cache[$siteId] = $values + ['sendEnabled' => true, 'limits' => []];
        }
    }
}
