<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\models\CmsUser;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * The state of a contact for one topic: subscribed (with the basis of the
 * consent, the date and who marked it) or unsubscribed (when, where from:
 * the opt-out page, one click in the mail program, a manager). The consent
 * fields stay after an opt-out, so the history of the consent is kept.
 * Written only by services\MailingConsents.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_comm_contact_id
 * @property int         $cms_comm_topic_id
 * @property string      $status
 * @property string|null $basis
 * @property int|null    $consent_at
 * @property int|null    $consent_by
 * @property int|null    $unsubscribed_at
 * @property int|null    $unsubscribed_by
 * @property string|null $unsubscribe_source
 * @property int|null    $cms_comm_message_id
 * @property string|null $note
 *
 * @property CmsCommContact $contact
 * @property CmsCommTopic   $topic
 * @property CmsUser|null   $consentBy
 * @property CmsUser|null   $unsubscribedBy
 */
class CmsCommContactTopic extends ActiveRecord
{
    public const STATUS_SUBSCRIBED = 'subscribed';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    /** Subscribed on the site: a form, the opt-out page (a client checked a topic again). */
    public const BASIS_SITE = 'site';
    /** The client agreed in a call or a meeting; the manager marked it. */
    public const BASIS_VERBAL = 'verbal';
    /** No consent: a cold contact. Only personal letters of a manager, never a mailing service. */
    public const BASIS_COLD = 'cold';

    /** Where the state came from. */
    public const SOURCE_LINK = 'link';
    public const SOURCE_ONE_CLICK = 'one_click';
    public const SOURCE_MANAGER = 'manager';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_API = 'api';
    /** The opt-out link of a mailing service (its webhook, services\DeliveryEvents). */
    public const SOURCE_PROVIDER = 'provider';

    public static function tableName()
    {
        return '{{%cms_comm_contact_topic}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    /** @return string[] basis => title */
    public static function bases(): array
    {
        return [
            self::BASIS_SITE => 'Подписался на сайте',
            self::BASIS_VERBAL => 'Дал согласие устно',
            self::BASIS_COLD => 'Нет согласия — холодный контакт',
        ];
    }

    /** Bases that are a real consent (38-ФЗ): a mailing service may send only to them. */
    public static function consentBases(): array
    {
        return [self::BASIS_SITE, self::BASIS_VERBAL];
    }

    /** @return string[] source => title */
    public static function sources(): array
    {
        return [
            self::SOURCE_LINK => 'по ссылке из письма',
            self::SOURCE_ONE_CLICK => 'кнопкой «Отписаться» почтовой программы',
            self::SOURCE_MANAGER => 'отметил сотрудник',
            self::SOURCE_IMPORT => 'при импорте',
            self::SOURCE_API => 'через API',
            self::SOURCE_PROVIDER => 'по ссылке сервиса рассылок',
        ];
    }

    public function isSubscribed(): bool
    {
        return $this->status === self::STATUS_SUBSCRIBED;
    }

    public function hasConsent(): bool
    {
        return $this->isSubscribed() && in_array($this->basis, self::consentBases(), true);
    }

    /** "Подписан: дал согласие устно", "Отписан". */
    public function stateText(): string
    {
        if (!$this->isSubscribed()) {
            return 'Отписан';
        }

        return $this->basis === self::BASIS_COLD ? 'Холодный контакт' : 'Подписан';
    }

    public function getContact()
    {
        return $this->hasOne(CmsCommContact::class, ['id' => 'cms_comm_contact_id']);
    }

    public function getTopic()
    {
        return $this->hasOne(CmsCommTopic::class, ['id' => 'cms_comm_topic_id']);
    }

    public function getConsentBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'consent_by']);
    }

    public function getUnsubscribedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'unsubscribed_by']);
    }
}
