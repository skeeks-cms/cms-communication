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
use skeeks\yii2\form\fields\SelectField;

/**
 * How letter files are kept in the CRM, for the whole site ("Настройки
 * почты"). A mailbox may override it (CmsCommAccount::file_settings). A file
 * not kept in the CRM stays in the mailbox and is fetched when someone opens
 * it; the letter text is always kept.
 */
class MailFileSettings extends Component
{
    public const MODE_FULL = 'full';
    public const MODE_ECONOMY = 'economy';
    public const MODE_MINIMAL = 'minimal';
    public const MODE_CUSTOM = 'custom';

    /** By default nothing is copied at receipt: files come from the mailbox when opened. */
    public $mode = self::MODE_MINIMAL;

    /** "Выборочно": keep images inside the text. */
    public $storeImages = 1;

    /** "Выборочно": keep attachments. */
    public $storeAttachments = 1;

    /** Files bigger than this (MB) are not kept: fetched from the mailbox on demand. 0 — no limit. */
    public $maxStoreSizeMb = 25;

    /** Kept copies older than this (days, by letter date) are removed by the daily cleanup: a year by default. 0 — keep forever. */
    public $keepDays = 365;

    /** Copies fetched from the mailbox on demand are removed this many days after the last opening. */
    public $cacheDays = 30;

    public static function modes(): array
    {
        return [
            self::MODE_FULL => 'Полный: хранить картинки и вложения в CRM',
            self::MODE_ECONOMY => 'Экономный: хранить картинки, вложения брать из ящика при открытии',
            self::MODE_MINIMAL => 'Минимальный: картинки и вложения брать из ящика при открытии',
            self::MODE_CUSTOM => 'Выборочно: отметить, что хранить в CRM',
        ];
    }

    public function rules()
    {
        return array_merge(parent::rules(), self::fieldRules());
    }

    /** Rules shared with the mailbox form. */
    public static function fieldRules(): array
    {
        return [
            [['mode'], 'in', 'range' => array_keys(self::modes())],
            [['storeImages', 'storeAttachments'], 'boolean'],
            [['maxStoreSizeMb'], 'integer', 'min' => 0, 'max' => 1024],
            [['keepDays'], 'integer', 'min' => 0, 'max' => 36500],
            [['cacheDays'], 'integer', 'min' => 1, 'max' => 3650],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), self::fieldLabels());
    }

    public static function fieldLabels(): array
    {
        return [
            'mode' => 'Хранение файлов писем',
            'storeImages' => 'Хранить картинки из текста писем',
            'storeAttachments' => 'Хранить вложения',
            'maxStoreSizeMb' => 'Не хранить файлы больше, МБ',
            'keepDays' => 'Хранить копии файлов, дней',
            'cacheDays' => 'Хранить файлы, открытые из ящика, дней',
        ];
    }

    public function attributeHints()
    {
        return array_merge(parent::attributeHints(), self::fieldHints());
    }

    public static function fieldHints(): array
    {
        return [
            'mode' => 'Текст писем хранится всегда. Файл, которого нет в CRM, догружается из почтового ящика, когда письмо или вложение открывают.',
            'maxStoreSizeMb' => '0 — без ограничения. Большие файлы будут открываться из почтового ящика.',
            'keepDays' => 'Ежедневная уборка удаляет копии старше этого срока (по дате письма), если письмо есть в почтовом ящике. 0 — хранить всегда.',
            'cacheDays' => 'Для файлов, которые по этим настройкам не хранятся в CRM: через сколько дней после последнего открытия удалить их копию, догруженную из ящика.',
        ];
    }

    /**
     * Own form id: the work timer form in the header is also "w0", and the
     * dynamic reload of a field finds the first "#w0" — it would post the timer.
     */
    public function beginConfigForm()
    {
        return \skeeks\cms\modules\admin\widgets\form\ActiveFormUseTab::begin([
            'id'                     => 'sx-mail-file-settings',
            'enableAjaxValidation'   => false,
            'enableClientValidation' => false,
        ]);
    }

    public function getConfigFormFields()
    {
        return self::formFields((string)$this->mode, '', null, (bool)$this->storeImages, (bool)$this->storeAttachments, (int)$this->maxStoreSizeMb);
    }

    /**
     * Fields of the settings form: only what matters for the chosen mode.
     * The switches appear for "Выборочно"; the size limit and the copy term
     * only when something is kept in the CRM; the term of copies opened
     * from the mailbox in every mode. $prefix: 'files.' in the mailbox form,
     * where mode '' ("Как в настройках почты") shows the choice alone.
     */
    public static function formFields(string $mode, string $prefix, ?array $modes = null, bool $storeImages = true,
        bool $storeAttachments = true, int $maxStoreSizeMb = 0): array
    {
        $fields = [
            $prefix.'mode' => [
                'class' => SelectField::class,
                'items' => $modes ?? self::modes(),
                'allowNull' => false,
                'elementOptions' => [\skeeks\cms\helpers\RequestResponse::DYNAMIC_RELOAD_FIELD_ELEMENT => 'true'],
            ],
        ];
        if ($mode === '') {
            return $fields;
        }
        $switch = ['class' => BoolField::class, 'allowNull' => false,
            'elementOptions' => [\skeeks\cms\helpers\RequestResponse::DYNAMIC_RELOAD_FIELD_ELEMENT => 'true']];
        if ($mode === self::MODE_CUSTOM) {
            $fields[$prefix.'storeImages'] = $switch;
            $fields[$prefix.'storeAttachments'] = $switch;
        }
        $keepsSomething = match ($mode) {
            self::MODE_MINIMAL => false,
            self::MODE_CUSTOM => $storeImages || $storeAttachments,
            default => true,
        };
        $keepsEverything = match ($mode) {
            self::MODE_FULL => true,
            self::MODE_CUSTOM => $storeImages && $storeAttachments,
            default => false,
        } && $maxStoreSizeMb === 0;
        if ($keepsSomething) {
            $fields[$prefix.'maxStoreSizeMb'] = ['class' => NumberField::class,
                'elementOptions' => [\skeeks\cms\helpers\RequestResponse::DYNAMIC_RELOAD_FIELD_ELEMENT => 'true']];
            $fields[$prefix.'keepDays'] = ['class' => NumberField::class];
        }
        // Nothing is opened from the mailbox when everything is kept: no term for such copies.
        if (!$keepsEverything) {
            $fields[$prefix.'cacheDays'] = ['class' => NumberField::class];
        }

        return $fields;
    }

    /** @return array the settings as MailFilePolicy reads them */
    public function values(): array
    {
        return [
            'mode' => (string)$this->mode,
            'storeImages' => (bool)$this->storeImages,
            'storeAttachments' => (bool)$this->storeAttachments,
            'maxStoreSizeMb' => (int)$this->maxStoreSizeMb,
            'keepDays' => (int)$this->keepDays,
            'cacheDays' => (int)$this->cacheDays,
        ];
    }

    /**
     * Saves the site settings at the level the administration uses: the
     * default record on the default site, the site record elsewhere.
     */
    public static function saveForSite(array $values): bool
    {
        $settings = new static();
        $site = \Yii::$app->skeeks->site;
        if ($site->is_default) {
            $settings->setOverride(self::OVERRIDE_DEFAULT);
        } else {
            $settings->setCmsSite($site);
            $settings->setOverride(self::OVERRIDE_SITE);
        }
        $settings->setAttributes($values);

        return $settings->save(true, array_keys($values));
    }
}
