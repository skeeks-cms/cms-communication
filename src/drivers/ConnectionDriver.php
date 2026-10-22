<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\drivers;

use skeeks\cms\IHasConfigForm;
use skeeks\cms\traits\TConfigForm;
use yii\base\Model;

/**
 * Transport of one connection: an IMAP/SMTP login, a bot, a mailing service.
 *
 * Settings are the model's safe attributes. Attributes listed in
 * secretAttributes() (passwords, tokens) are stored encrypted and are never
 * rendered back into forms: an empty value on update keeps the stored one.
 */
abstract class ConnectionDriver extends Model implements IHasConfigForm
{
    use TConfigForm;

    /** Title of the driver in the connection form. */
    abstract public static function title(): string;

    /** Channel of the mailboxes served by this driver, for example `email`. */
    abstract public static function channel(): string;

    /** Secrets must be entered: there are no stored ones yet. */
    public bool $requireSecrets = false;

    /** @return string[] attributes stored encrypted */
    public function secretAttributes(): array
    {
        return [];
    }

    /**
     * Fills empty settings that follow from the mailbox address (login,
     * servers of a known mail service). Never overwrites entered values.
     */
    public function applyDefaultsForAddress(string $address): void
    {
    }

    /** Non-secret settings to store in the connection. */
    public function getPublicConfig(): array
    {
        $values = $this->getAttributes($this->safeAttributes());

        return array_diff_key($values, array_flip($this->secretAttributes()));
    }

    /** Secret settings to encrypt; empty values are omitted (keep stored ones). */
    public function getSecretConfig(): array
    {
        $values = [];
        foreach ($this->secretAttributes() as $attribute) {
            $value = (string)$this->$attribute;
            if ($value !== '') {
                $values[$attribute] = $value;
            }
        }

        return $values;
    }
}
