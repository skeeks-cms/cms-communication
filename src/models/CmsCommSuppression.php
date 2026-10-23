<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\models\CmsUser;
use yii\db\ActiveRecord;

/**
 * The stop list: a contact no advertising reaches, whatever its topics say.
 * The client opted out of all advertising mailings, complained about a
 * letter, or the address does not take letters. Service messages and
 * personal letters of a manager are not blocked (a manager only sees a
 * warning). Written only by services\MailingConsents.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $created_by
 * @property int         $cms_site_id
 * @property int         $cms_comm_contact_id
 * @property string      $reason
 * @property string|null $source CmsCommContactTopic::SOURCE_* or provider
 * @property int|null    $cms_comm_message_id
 * @property string|null $note
 *
 * @property CmsCommContact $contact
 * @property CmsUser|null   $createdBy
 */
class CmsCommSuppression extends ActiveRecord
{
    public const REASON_ALL = 'unsubscribed_all';
    public const REASON_COMPLAINT = 'complaint';
    public const REASON_BOUNCE = 'bounce';

    /** Events of a mailing service (stage 4C). */
    public const SOURCE_PROVIDER = 'provider';

    public static function tableName()
    {
        return '{{%cms_comm_suppression}}';
    }

    /** @return string[] reason => title */
    public static function reasons(): array
    {
        return [
            self::REASON_ALL => 'Отписан от всех рекламных рассылок',
            self::REASON_COMPLAINT => 'Пожаловался на рассылку',
            self::REASON_BOUNCE => 'Письма не доставляются',
        ];
    }

    public function reasonText(): string
    {
        return self::reasons()[$this->reason] ?? (string)$this->reason;
    }

    public function getContact()
    {
        return $this->hasOne(CmsCommContact::class, ['id' => 'cms_comm_contact_id']);
    }

    public function getCreatedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'created_by']);
    }
}
