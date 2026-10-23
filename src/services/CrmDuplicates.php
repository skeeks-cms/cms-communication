<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

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
use yii\db\Expression;
use yii\db\Query;

/**
 * Is a recipient already in the CRM: a lead, a company or a client (not an
 * employee) with the same email, phone, INN or name. Exact matches only:
 * email in lower case, phone by its significant digits, INN by digits, name
 * case-insensitive without quotes and extra spaces (companies and leads;
 * people's names repeat too often to count).
 *
 * Built for imports: many candidates at once in a few queries. Phones of
 * the CRM are read once per instance (their notation differs, an index does
 * not help), so keep one instance for a whole import.
 */
class CrmDuplicates
{
    /** The order in which evidence wins: the strongest first. */
    public const BY = ['email', 'phone', 'inn', 'name'];

    private int $siteId;

    /** @var array|null significant digits => [type, id] */
    private ?array $phones = null;

    public function __construct(int $siteId)
    {
        $this->siteId = $siteId;
    }

    /** "ООО «Ромашка»  " → "ооо ромашка". */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = str_replace(['«', '»', '"', '“', '”', '„', "'"], ' ', $name);

        return trim((string)preg_replace('/\s+/u', ' ', $name));
    }

    public static function normalizeInn(string $inn): string
    {
        $digits = PhoneHelper::digits($inn);

        return in_array(strlen($digits), [10, 12], true) ? $digits : '';
    }

    /**
     * @param array[] $candidates key => ['emails' => [], 'phones' => [] (digits), 'inn' => '', 'name' => '']
     * @return array[] key => ['type' => CrmRecords type, 'id' => int, 'by' => email|phone|inn|name, 'value' => matched value], only found ones
     */
    public function findMany(array $candidates): array
    {
        $emails = [];
        $inns = [];
        $names = [];
        foreach ($candidates as $candidate) {
            foreach ((array)($candidate['emails'] ?? []) as $email) {
                $emails[mb_strtolower((string)$email)] = true;
            }
            if (($inn = self::normalizeInn((string)($candidate['inn'] ?? ''))) !== '') {
                $inns[$inn] = true;
            }
            if (($name = self::normalizeName((string)($candidate['name'] ?? ''))) !== '') {
                $names[$name] = true;
            }
        }
        $byEmail = $emails ? $this->emails(array_keys($emails)) : [];
        $byInn = $inns ? $this->inns(array_keys($inns)) : [];
        $byName = $names ? $this->names(array_keys($names)) : [];

        $result = [];
        foreach ($candidates as $key => $candidate) {
            foreach ((array)($candidate['emails'] ?? []) as $email) {
                if ($match = $byEmail[mb_strtolower((string)$email)] ?? null) {
                    $result[$key] = $match + ['by' => 'email', 'value' => (string)$email];
                    continue 2;
                }
            }
            foreach ((array)($candidate['phones'] ?? []) as $phone) {
                if ($match = $this->phone((string)$phone)) {
                    $result[$key] = $match + ['by' => 'phone', 'value' => (string)$phone];
                    continue 2;
                }
            }
            $inn = self::normalizeInn((string)($candidate['inn'] ?? ''));
            if ($inn !== '' && ($match = $byInn[$inn] ?? null)) {
                $result[$key] = $match + ['by' => 'inn', 'value' => $inn];
                continue;
            }
            $name = self::normalizeName((string)($candidate['name'] ?? ''));
            if ($name !== '' && ($match = $byName[$name] ?? null)) {
                $result[$key] = $match + ['by' => 'name', 'value' => (string)$candidate['name']];
            }
        }

        return $result;
    }

    /** Remembers a lead made during the import: later rows find it like any CRM record. */
    public function remember(int $leadId, array $phones): void
    {
        $this->loadPhones();
        foreach ($phones as $phone) {
            $digits = PhoneHelper::searchDigits((string)$phone);
            if (strlen($digits) >= 10 && !isset($this->phones[$digits])) {
                $this->phones[$digits] = ['type' => CrmRecords::LEAD, 'id' => $leadId];
            }
        }
    }

    /** @return array[] email => [type, id] */
    private function emails(array $emails): array
    {
        $result = [];
        foreach ($this->contactSources('email') as [$type, $query, $column]) {
            foreach ($query->andWhere(['in', new Expression('LOWER('.$column.')'), $emails])->all() as $row) {
                $email = mb_strtolower((string)$row['value']);
                $result[$email] = $result[$email] ?? ['type' => $type, 'id' => (int)$row['id']];
            }
        }

        return $result;
    }

    /**
     * Where emails or phones of leads, companies and clients are kept (a
     * client's own email and phone are rows of cms_user_email / cms_user_phone).
     *
     * @return array[] each: [CrmRecords type, Query selecting id and value, value column]
     */
    private function contactSources(string $kind): array
    {
        $lead = $kind === 'email' ? CmsLeadEmail::tableName() : CmsLeadPhone::tableName();
        $company = $kind === 'email' ? CmsCompanyEmail::tableName() : CmsCompanyPhone::tableName();
        $user = $kind === 'email' ? CmsUserEmail::tableName() : CmsUserPhone::tableName();

        return [
            [CrmRecords::LEAD, (new Query())->from(['c' => $lead])->innerJoin(CmsLead::tableName().' l', 'l.id = c.cms_lead_id')
                ->select(['id' => 'c.cms_lead_id', 'value' => 'c.value'])->andWhere(['l.cms_site_id' => $this->siteId]), 'c.value'],
            [CrmRecords::COMPANY, (new Query())->from(['c' => $company])->select(['id' => 'c.cms_company_id', 'value' => 'c.value']), 'c.value'],
            [CrmRecords::CLIENT, (new Query())->from(['c' => $user])->innerJoin(CmsUser::tableName().' u', 'u.id = c.cms_user_id')
                ->select(['id' => 'c.cms_user_id', 'value' => 'c.value'])->andWhere(['c.cms_site_id' => $this->siteId, 'u.is_worker' => 0]), 'c.value'],
        ];
    }

    /** @return array[] inn => [type, id] */
    private function inns(array $inns): array
    {
        $result = [];
        $maps = [
            [CrmRecords::LEAD, '{{%cms_lead2contractor}}', 'cms_lead_id'],
            [CrmRecords::COMPANY, '{{%cms_company2contractor}}', 'cms_company_id'],
            [CrmRecords::CLIENT, '{{%cms_contractor_map}}', 'cms_user_id'],
        ];
        foreach ($maps as [$type, $table, $column]) {
            $rows = (new Query())->from(['m' => $table])->innerJoin('{{%cms_contractor}} c', 'c.id = m.cms_contractor_id')
                ->select(['id' => 'm.'.$column, 'inn' => 'c.inn'])->andWhere(['c.inn' => $inns])->all();
            foreach ($rows as $row) {
                $inn = (string)$row['inn'];
                $result[$inn] = $result[$inn] ?? ['type' => $type, 'id' => (int)$row['id']];
            }
        }

        return $result;
    }

    /** @return array[] normalized name => [type, id] */
    private function names(array $names): array
    {
        $result = [];
        // Compared in PHP after a coarse SQL filter: quotes and spaces differ in the cards.
        $words = [];
        foreach ($names as $name) {
            $longest = '';
            foreach (explode(' ', $name) as $word) {
                $longest = mb_strlen($word) > mb_strlen($longest) ? $word : $longest;
            }
            $words[$name] = $longest;
        }
        $sources = [
            [CrmRecords::COMPANY, (new Query())->from(CmsCompany::tableName())->select(['id', 'name'])],
            [CrmRecords::LEAD, (new Query())->from(CmsLead::tableName())->select(['id', 'name'])->andWhere(['cms_site_id' => $this->siteId])],
        ];
        foreach (array_chunk($words, 50, true) as $chunk) {
            foreach ($sources as [$type, $query]) {
                $like = ['or'];
                foreach (array_unique($chunk) as $word) {
                    // Also capitalized: not every database compares Cyrillic case-insensitively.
                    $like[] = ['like', 'name', $word];
                    $like[] = ['like', 'name', mb_convert_case($word, MB_CASE_TITLE)];
                }
                foreach ((clone $query)->andWhere($like)->all() as $row) {
                    $name = self::normalizeName((string)$row['name']);
                    if (isset($chunk[$name])) {
                        $result[$name] = $result[$name] ?? ['type' => $type, 'id' => (int)$row['id']];
                    }
                }
            }
        }

        return $result;
    }

    private function phone(string $phone): ?array
    {
        $this->loadPhones();
        $digits = PhoneHelper::searchDigits($phone);

        return strlen($digits) >= 10 ? ($this->phones[$digits] ?? null) : null;
    }

    private function loadPhones(): void
    {
        if ($this->phones !== null) {
            return;
        }
        $this->phones = [];
        foreach ($this->contactSources('phone') as [$type, $query]) {
            foreach ($query->each(1000) as $row) {
                $digits = PhoneHelper::searchDigits((string)$row['value']);
                if (strlen($digits) >= 10 && !isset($this->phones[$digits])) {
                    $this->phones[$digits] = ['type' => $type, 'id' => (int)$row['id']];
                }
            }
        }
    }
}
