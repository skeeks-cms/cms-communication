<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\forms;

/**
 * "Сохранить файлы в CRM": which files of earlier letters to keep for good.
 * The period is the same as in "Сбор писем" (MailHistoryForm).
 */
class MailFileSaveForm extends MailHistoryForm
{
    public $period = self::PERIOD_ALL;

    /** Empty — every mailbox. */
    public $accountId;

    public $images = 1;

    public $attachments = 1;

    /** Do not save files bigger than this, MB; 0 — any size. */
    public $maxSizeMb = 25;

    public function rules()
    {
        return array_merge(parent::rules(), [
            [['accountId'], 'integer'],
            [['images', 'attachments'], 'boolean'],
            [['maxSizeMb'], 'integer', 'min' => 0, 'max' => 1024],
            [['attachments'], function () {
                if (!$this->images && !$this->attachments) {
                    $this->addError('attachments', 'Отметьте, что сохранять.');
                }
            }],
        ]);
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'period' => 'Письма',
            'accountId' => 'Почтовый ящик',
            'images' => 'Картинки из текста писем',
            'attachments' => 'Вложения',
            'maxSizeMb' => 'Не сохранять файлы больше, МБ',
        ]);
    }

    /** @return array MailFileSave criteria */
    public function criteria(): array
    {
        return [
            'accountId' => $this->accountId ? (int)$this->accountId : null,
            'since' => $this->since(),
            'images' => (bool)$this->images,
            'attachments' => (bool)$this->attachments,
            'maxSizeMb' => (int)$this->maxSizeMb,
        ];
    }
}
