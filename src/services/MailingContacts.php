<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\helpers\PhoneHelper;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompanyEmail;
use skeeks\cms\models\CmsCompanyPhone;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsLeadPhone;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsUserEmail;
use skeeks\cms\models\CmsUserPhone;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\db\IntegrityException;

/**
 * Contacts of mailings: a normalized email or phone of the site
 * (CmsCommContact), the contacts of a CRM record and the records that have
 * a contact. Messenger chats (stage 5) become one more kind here.
 */
class MailingContacts
{
    /** The contact if something was ever recorded for it. */
    public static function find(int $siteId, string $kind, string $value): ?CmsCommContact
    {
        $value = CmsCommContact::normalize($kind, $value);

        return $value === null ? null : CmsCommContact::findOne(['cms_site_id' => $siteId, 'kind' => $kind, 'value' => $value]);
    }

    /**
     * The contact, made when missing (with its personal opt-out code).
     *
     * @throws \InvalidArgumentException when the value is not an email or a phone
     */
    public static function get(int $siteId, string $kind, string $value): CmsCommContact
    {
        $normalized = CmsCommContact::normalize($kind, $value);
        if ($normalized === null) {
            throw new \InvalidArgumentException("«{$value}» — не ".($kind === CmsCommContact::KIND_PHONE ? 'номер телефона' : 'адрес почты').'.');
        }
        $attributes = ['cms_site_id' => $siteId, 'kind' => $kind, 'value' => $normalized];
        if ($contact = CmsCommContact::findOne($attributes)) {
            return $contact;
        }
        $contact = new CmsCommContact($attributes + ['code' => self::newCode()]);
        try {
            $contact->save(false);
        } catch (IntegrityException $e) {
            // Made by a parallel request a moment ago.
            if ($existing = CmsCommContact::findOne($attributes)) {
                return $existing;
            }
            throw $e;
        }

        return $contact;
    }

    /** @return CmsCommContact[] "kind:value" => contact, only those that exist */
    public static function findMany(int $siteId, string $kind, array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (($n = CmsCommContact::normalize($kind, (string)$value)) !== null) {
                $normalized[$n] = true;
            }
        }
        if (!$normalized) {
            return [];
        }
        $result = [];
        foreach (CmsCommContact::find()->andWhere(['cms_site_id' => $siteId, 'kind' => $kind, 'value' => array_keys($normalized)])->all() as $contact) {
            $result[$kind.':'.$contact->value] = $contact;
        }

        return $result;
    }

    /**
     * Emails and phones of a lead, a company or a client, as in their card.
     *
     * @return array[] each: kind, value (normalized), display (as stored), label
     */
    public static function ofRecord(ActiveRecord $record): array
    {
        $rows = [];
        $add = static function (string $kind, $value, $label) use (&$rows) {
            $normalized = CmsCommContact::normalize($kind, (string)$value);
            if ($normalized !== null && !isset($rows[$kind.':'.$normalized])) {
                $rows[$kind.':'.$normalized] = ['kind' => $kind, 'value' => $normalized, 'display' => trim((string)$value), 'label' => trim((string)$label)];
            }
        };
        if ($record instanceof CmsLead || $record instanceof CmsCompany) {
            foreach ($record->emails as $row) {
                $add(CmsCommContact::KIND_EMAIL, $row->value, $row->name);
            }
            foreach ($record->phones as $row) {
                $add(CmsCommContact::KIND_PHONE, $row->value, $row->name);
            }
        } elseif ($record instanceof CmsUser) {
            $emails = $record->cmsUserEmails;
            foreach ($emails as $row) {
                $add(CmsCommContact::KIND_EMAIL, $row->value, $row->name ?? '');
            }
            if (!$emails && $record->email) {
                $add(CmsCommContact::KIND_EMAIL, $record->email, '');
            }
            $phones = $record->cmsUserPhones;
            foreach ($phones as $row) {
                $add(CmsCommContact::KIND_PHONE, $row->value, $row->name ?? '');
            }
            if (!$phones && $record->phone) {
                $add(CmsCommContact::KIND_PHONE, $record->phone, '');
            }
        }

        return array_values($rows);
    }

    /**
     * Leads, companies and clients (not employees) that have this contact.
     * A phone matches whatever its notation in the card.
     *
     * @return ActiveRecord[]
     */
    public static function owners(CmsCommContact $contact): array
    {
        $siteId = (int)$contact->cms_site_id;
        if ($contact->kind === CmsCommContact::KIND_EMAIL) {
            $value = (string)$contact->value;
            $condition = ['=', new Expression('LOWER(value)'), $value];
            $leads = CmsLeadEmail::find()->select('cms_lead_id')->andWhere($condition);
            $companies = CmsCompanyEmail::find()->select('cms_company_id')->andWhere($condition);
            $clients = CmsUserEmail::find()->select('cms_user_id')->andWhere($condition)->andWhere(['cms_site_id' => $siteId]);
        } elseif ($contact->kind === CmsCommContact::KIND_PHONE) {
            $condition = PhoneHelper::equalCondition('value', (string)$contact->value);
            if (!$condition) {
                return [];
            }
            $leads = CmsLeadPhone::find()->select('cms_lead_id')->andWhere($condition);
            $companies = CmsCompanyPhone::find()->select('cms_company_id')->andWhere($condition);
            $clients = CmsUserPhone::find()->select('cms_user_id')->andWhere($condition)->andWhere(['cms_site_id' => $siteId]);
        } else {
            return [];
        }

        $result = CmsLead::find()->andWhere([CmsLead::tableName().'.cms_site_id' => $siteId, CmsLead::tableName().'.id' => $leads])->all();
        $result = array_merge($result, CmsCompany::find()->andWhere([CmsCompany::tableName().'.id' => $companies])->all());
        $users = CmsUser::find()->andWhere([CmsUser::tableName().'.is_worker' => 0, CmsUser::tableName().'.id' => $clients]);

        return array_merge($result, $users->all());
    }

    /**
     * Every contact of the person or company behind this contact: the
     * contacts of its leads (with their company and client), companies and
     * clients. "Отписаться от всех рекламных рассылок по всем каналам".
     *
     * @return CmsCommContact[] including the contact itself
     */
    public static function siblings(CmsCommContact $contact): array
    {
        $records = [];
        foreach (self::owners($contact) as $owner) {
            $records[] = $owner;
            if ($owner instanceof CmsLead) {
                $records[] = $owner->company;
                $records[] = $owner->client;
            }
        }
        $result = [$contact->kind.':'.$contact->value => $contact];
        $siteId = (int)$contact->cms_site_id;
        foreach (array_filter($records) as $record) {
            foreach (self::ofRecord($record) as $row) {
                $key = $row['kind'].':'.$row['value'];
                if (!isset($result[$key])) {
                    $result[$key] = self::get($siteId, $row['kind'], $row['value']);
                }
            }
        }

        return array_values($result);
    }

    private static function newCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
}
