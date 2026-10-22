<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\forms;

use skeeks\cms\communication\components\MailFileSettings;
use skeeks\cms\communication\models\CmsCommAccount;
use yii\base\Model;

/**
 * File storage settings of one mailbox: "Как в настройках почты" or its own
 * values (stored in CmsCommAccount::file_settings).
 */
class MailboxFileSettingsForm extends Model
{
    /** '' — as the site settings of "Почта". */
    public $mode = '';
    public $storeImages = 1;
    public $storeAttachments = 1;
    public $maxStoreSizeMb = 25;
    public $keepDays = 365;
    public $cacheDays = 30;

    public static function fromAccount(CmsCommAccount $account): self
    {
        $form = new self();
        if ($own = $account->getFileSettings()) {
            $form->setAttributes($own);
        }

        return $form;
    }

    /** @return array|null values for CmsCommAccount::setFileSettings() */
    public function values(): ?array
    {
        if ($this->mode === '' || $this->mode === null) {
            return null;
        }

        return [
            'mode' => (string)$this->mode,
            'storeImages' => (bool)$this->storeImages,
            'storeAttachments' => (bool)$this->storeAttachments,
            'maxStoreSizeMb' => (int)$this->maxStoreSizeMb,
            'keepDays' => (int)$this->keepDays,
            'cacheDays' => (int)$this->cacheDays,
        ];
    }

    public static function modes(): array
    {
        return ['' => 'Как в настройках почты'] + MailFileSettings::modes();
    }

    public function rules()
    {
        $rules = MailFileSettings::fieldRules();
        $rules[0] = [['mode'], 'in', 'range' => array_keys(self::modes())];

        return $rules;
    }

    public function attributeLabels()
    {
        return MailFileSettings::fieldLabels();
    }

    public function attributeHints()
    {
        return ['mode' => 'Настройки этого ящика заменяют общие настройки почты.'] + MailFileSettings::fieldHints();
    }
}
