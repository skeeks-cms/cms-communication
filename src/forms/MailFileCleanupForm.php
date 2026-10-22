<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\forms;

use yii\base\Model;

/**
 * What an administrator removes in "Настройки почты" → «Очистка файлов»
 * (see MailFileCleanup::criteria()).
 */
class MailFileCleanupForm extends Model
{
    public $olderThanDays = 365;
    public $attachments = 1;
    public $images = 0;
    public $minSizeMb = 0;
    public $accountId;

    public function rules()
    {
        return [
            [['olderThanDays', 'minSizeMb'], 'integer', 'min' => 0, 'max' => 36500],
            [['attachments', 'images'], 'boolean'],
            [['accountId'], 'integer'],
            [['attachments'], 'validateKinds', 'skipOnEmpty' => false],
        ];
    }

    public function attributeLabels()
    {
        return [
            'olderThanDays' => 'Письма старше, дней',
            'attachments' => 'Вложения',
            'images' => 'Картинки из текста писем',
            'minSizeMb' => 'Только файлы больше, МБ',
            'accountId' => 'Ящик',
        ];
    }

    public function validateKinds($attribute)
    {
        if (!$this->attachments && !$this->images) {
            $this->addError($attribute, 'Выберите, что удалять: вложения, картинки или и то и другое.');
        }
    }
}
