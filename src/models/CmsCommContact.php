<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\communication\services\SendContacts;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A contact mailings reach: an email address or a phone number (later a bot
 * chat), normalized, once per site. Consent and opt-out belong to the
 * contact, not to a lead: one address may be a lead's, a company's and a
 * client's at once, and consent to letters is not consent to SMS.
 *
 * The contact row is made when something is recorded for it (consent,
 * opt-out, a personal opt-out link); a contact without a row has no marks.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_site_id
 * @property string      $kind  email, phone
 * @property string      $value email in lower case, phone as digits 79991234567
 * @property string      $code  code of the personal opt-out link
 *
 * @property CmsCommContactTopic[] $topicStates
 * @property CmsCommSuppression[]  $suppressions
 */
class CmsCommContact extends ActiveRecord
{
    public const KIND_EMAIL = 'email';
    public const KIND_PHONE = 'phone';

    public static function tableName()
    {
        return '{{%cms_comm_contact}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    /** @return string[] kind => title */
    public static function kinds(): array
    {
        return [self::KIND_EMAIL => 'Почта', self::KIND_PHONE => 'Телефон'];
    }

    /**
     * The normalized value of a contact, or null when it is not one: email
     * in lower case, phone as digits with the country code.
     */
    public static function normalize(string $kind, string $value): ?string
    {
        $value = trim($value);
        if ($kind === self::KIND_EMAIL) {
            $value = mb_strtolower(trim($value, " \t\n\r\0\x0B<>\"'"));
            return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
        }
        if ($kind === self::KIND_PHONE) {
            return SendContacts::normalizePhone($value);
        }

        return null;
    }

    /** "i***@skeeks.com", "+7 999 ***-**-67": the contact on a page that anyone with the link sees. */
    public function masked(): string
    {
        if ($this->kind === self::KIND_EMAIL) {
            [$local, $domain] = array_pad(explode('@', (string)$this->value, 2), 2, '');
            return mb_substr($local, 0, 1).str_repeat('*', max(3, min(6, mb_strlen($local) - 1))).'@'.$domain;
        }
        // The first four and the last two digits stay: "+7 999 ***-**-67".
        $formatted = $this->display();
        $total = strlen((string)$this->value);
        $seen = 0;

        return (string)preg_replace_callback('/\d/', static function ($m) use (&$seen, $total) {
            $seen++;
            return $seen <= 4 || $seen > $total - 2 ? $m[0] : '*';
        }, $formatted);
    }

    /** The contact as people read it: the address or "+7 999 123-45-67". */
    public function display(): string
    {
        return $this->kind === self::KIND_PHONE ? SendContacts::formatPhone((string)$this->value) : (string)$this->value;
    }

    public function getTopicStates()
    {
        return $this->hasMany(CmsCommContactTopic::class, ['cms_comm_contact_id' => 'id']);
    }

    public function getSuppressions()
    {
        return $this->hasMany(CmsCommSuppression::class, ['cms_comm_contact_id' => 'id']);
    }

    public function asText()
    {
        return $this->display();
    }
}
