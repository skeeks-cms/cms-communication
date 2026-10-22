<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\components;

use skeeks\cms\base\Component;

/**
 * Personal settings of the "Почта" section, stored per employee in
 * cms_component_settings (user level). Changed right where they matter,
 * e.g. "Показывать всегда" next to the hidden images of a letter.
 */
class MailUserSettings extends Component
{
    /** Show external images of letters at once instead of "Показать картинки". */
    public $showImages = false;

    public function rules()
    {
        return [
            [['showImages'], 'boolean'],
        ];
    }

    /** Saves one setting of the current employee. */
    public static function saveForCurrentUser(array $values): bool
    {
        $settings = new static();
        $settings->setAttributes($values);
        $settings->setOverride(self::OVERRIDE_USER);

        return $settings->save(true, array_keys($values));
    }
}
