<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommListMember;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2user;
use skeeks\cms\models\CmsCompanyEmail;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsUserEmail;
use skeeks\cms\models\User;
use yii\db\ActiveRecord;

/**
 * Lists of mailing recipients (stage 4D): one service for the list card,
 * the "Добавить в список" of CRM cards, the bulk action of CRM sections,
 * imports and AI through MCP.
 *
 * - A member is a CRM record (lead, company, client) the employee sees; the
 *   address is chosen when a letter leaves (Campaigns), so one list serves
 *   every channel. Consent and opt-out stay with the contact (MailingPolicy):
 *   an opted-out member stays in the list and is excluded at sending.
 * - add() keeps who, when and from where (VIA_*, ref) and brings back a
 *   member removed earlier; remove() only marks the member removed.
 * - members(), addresses() and companies() read many records in a few
 *   queries: campaigns take lists of thousands.
 */
class RecipientLists
{
    /** Record types a list takes. */
    public const ADDABLE = [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT];

    /** Most members one bulk call may add. */
    public const MAX_BATCH = 10000;

    public function create(int $siteId, string $name, User $user, ?string $description = null): CmsCommList
    {
        $list = new CmsCommList(['cms_site_id' => $siteId, 'name' => $name, 'description' => $description]);
        if (!$list->validate()) {
            throw new \DomainException(implode(' ', $list->getFirstErrors()));
        }
        $list->save(false);
        // Blameable takes the web user; a job, a console or an AI tool name the employee themselves.
        if ((int)$list->created_by !== (int)$user->id) {
            $list->updateAttributes(['created_by' => (int)$user->id, 'updated_by' => (int)$user->id]);
        }

        return $list;
    }

    /** The CrmRecords type of a record a list may take, or null. */
    public static function recordType(ActiveRecord $record): ?string
    {
        $type = CrmRecords::typeOfCode(method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record));
        if (!in_array($type, self::ADDABLE, true) || ($type === CrmRecords::CLIENT && $record->is_worker)) {
            return null;
        }

        return $type;
    }

    /**
     * Adds a record to a list, or brings back a member removed earlier. The
     * same operation serves the card, the bulk action of a section, imports
     * and MCP tools.
     *
     * @param string      $via   CmsCommListMember::VIA_*
     * @param string|null $ref   the section (companies, leads, clients), "import:7", "mcp:<client>"
     * @param bool        $checkVisible false when the records come from a query already scoped to the employee
     * @throws \DomainException with the reason it cannot be added
     */
    public function add(CmsCommList $list, ActiveRecord $record, User $user, string $via = CmsCommListMember::VIA_MANUAL, ?string $ref = null,
        bool $checkVisible = true): CmsCommListMember
    {
        $type = self::recordType($record);
        if (!$type) {
            throw new \DomainException('В список можно добавить лид, компанию или клиента.');
        }
        if ($checkVisible && !CrmRecords::isVisible(CrmRecords::codeOf($type), (int)$record->primaryKey, $user)) {
            throw new \DomainException('Запись CRM не найдена или вам не видна.');
        }
        if (isset($record->cms_site_id) && (int)$record->cms_site_id && (int)$record->cms_site_id !== (int)$list->cms_site_id) {
            throw new \DomainException('Запись другого сайта.');
        }
        $member = CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$list->id, 'record_type' => $type,
            'record_id' => (int)$record->primaryKey])->one();
        if ($member && !$member->removed_at) {
            return $member;
        }
        // A member removed earlier comes back with the new author: who added it again and from where.
        $member = $member ?: new CmsCommListMember(['cms_comm_list_id' => (int)$list->id, 'record_type' => $type, 'record_id' => (int)$record->primaryKey]);
        $member->added_by = (int)$user->id ?: null;
        $member->added_via = isset(CmsCommListMember::vias()[$via]) ? $via : CmsCommListMember::VIA_MANUAL;
        $member->added_ref = $ref !== null ? mb_substr($ref, 0, 190) : null;
        $member->removed_at = null;
        $member->removed_by = null;
        if (!$member->save(false)) {
            throw new \RuntimeException('Не удалось добавить запись в список.');
        }

        return $member;
    }

    /**
     * Adds many records: those already in the list stay as they are, the
     * refused ones are returned with the reason.
     *
     * @param ActiveRecord[] $records
     * @return array added (new or brought back), already, refused (record ref => reason)
     */
    public function addMany(CmsCommList $list, array $records, User $user, string $via, ?string $ref = null, bool $checkVisible = true): array
    {
        if (count($records) > self::MAX_BATCH) {
            throw new \DomainException('За один раз можно добавить не больше '.self::MAX_BATCH.' записей.');
        }
        $result = ['added' => 0, 'already' => 0, 'refused' => []];
        foreach ($records as $record) {
            $type = self::recordType($record);
            $key = ($type ?: 'record').':'.(int)$record->primaryKey;
            $before = $type ? CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$list->id, 'record_type' => $type,
                'record_id' => (int)$record->primaryKey, 'removed_at' => null])->exists() : false;
            try {
                $this->add($list, $record, $user, $via, $ref, $checkVisible);
                $before ? $result['already']++ : $result['added']++;
            } catch (\DomainException $e) {
                $result['refused'][$key] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Adds records a rule found (ListRules): only those that never were in
     * the list — a member removed by hand stays removed. Many rows at once.
     *
     * @param int[] $ids records of $type, already scoped to the employee by the section query
     * @return int added
     */
    public function addFound(CmsCommList $list, string $type, array $ids, User $user, string $ref): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !in_array($type, self::ADDABLE, true)) {
            return 0;
        }
        $known = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (CmsCommListMember::find()->select('record_id')->andWhere(['cms_comm_list_id' => (int)$list->id, 'record_type' => $type,
                'record_id' => $chunk])->column() as $id) {
                $known[(int)$id] = true;
            }
        }
        $new = array_values(array_filter($ids, static fn($id) => !isset($known[$id])));
        // Leads of another site and employees among clients never join.
        if ($new && $type === CrmRecords::LEAD) {
            $new = array_map('intval', CmsLead::find()->select('id')->andWhere(['id' => $new, 'cms_site_id' => (int)$list->cms_site_id])->column());
        } elseif ($new && $type === CrmRecords::CLIENT) {
            $new = array_map('intval', CmsUser::find()->select(CmsUser::tableName().'.id')->andWhere([CmsUser::tableName().'.id' => $new,
                CmsUser::tableName().'.is_worker' => 0])->column());
        }
        $now = time();
        foreach (array_chunk($new, 500) as $chunk) {
            CmsCommListMember::getDb()->createCommand()->batchInsert(CmsCommListMember::tableName(),
                ['created_at', 'updated_at', 'cms_comm_list_id', 'record_type', 'record_id', 'added_by', 'added_via', 'added_ref'],
                array_map(static fn($id) => [$now, $now, (int)$list->id, $type, $id, (int)$user->id ?: null, CmsCommListMember::VIA_RULE, $ref], $chunk))->execute();
        }

        return count($new);
    }

    /** Marks a member removed: it stays as history and does not come back by itself. */
    public function remove(CmsCommListMember $member, User $user): bool
    {
        if ($member->removed_at) {
            return false;
        }

        return CmsCommListMember::updateAll(['removed_at' => time(), 'removed_by' => (int)$user->id ?: null, 'updated_at' => time()],
                ['id' => (int)$member->id, 'removed_at' => null]) > 0;
    }

    /** @return CmsCommList[] lists of the site the record is in now */
    public static function listsOf(ActiveRecord $record, int $siteId): array
    {
        $type = self::recordType($record);
        if (!$type) {
            return [];
        }
        $m = CmsCommListMember::tableName();
        $l = CmsCommList::tableName();

        return CmsCommList::find()->innerJoin($m, $m.'.cms_comm_list_id = '.$l.'.id')
            ->andWhere([$l.'.cms_site_id' => $siteId, $m.'.record_type' => $type, $m.'.record_id' => (int)$record->primaryKey, $m.'.removed_at' => null])
            ->orderBy([$l.'.name' => SORT_ASC])->all();
    }

    /**
     * Active members of lists, once each.
     *
     * @param int[] $listIds
     * @return array[] "type:id" => [type, id], in the order they were added
     */
    public static function members(array $listIds): array
    {
        $listIds = array_values(array_filter(array_map('intval', $listIds)));
        if (!$listIds) {
            return [];
        }
        $result = [];
        $rows = CmsCommListMember::find()->select(['record_type', 'record_id'])
            ->andWhere(['cms_comm_list_id' => $listIds, 'removed_at' => null])->orderBy(['id' => SORT_ASC])->asArray()->each(1000);
        foreach ($rows as $row) {
            $result[$row['record_type'].':'.(int)$row['record_id']] = [(string)$row['record_type'], (int)$row['record_id']];
        }

        return $result;
    }

    /**
     * Records of one type by id with their email addresses, the name of the
     * person behind each — as Campaigns::recordAddresses() gives them for one
     * record, in a few queries for many.
     *
     * @param int[] $ids
     * @return array records (id => record), addresses (id => [address => name])
     */
    public static function addresses(string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $records = [];
        $addresses = [];
        if (!$ids) {
            return ['records' => [], 'addresses' => []];
        }
        $add = static function (int $id, string $address, string $name) use (&$addresses) {
            $address = mb_strtolower(trim($address));
            if (filter_var($address, FILTER_VALIDATE_EMAIL) && !isset($addresses[$id][$address])) {
                $addresses[$id][$address] = trim($name);
            }
        };
        foreach (array_chunk($ids, 500) as $chunk) {
            if ($type === CrmRecords::LEAD) {
                foreach (CmsLead::find()->andWhere([CmsLead::tableName().'.id' => $chunk])->with(['client'])->all() as $lead) {
                    $records[(int)$lead->id] = $lead;
                }
                foreach (CmsLeadEmail::find()->andWhere(['cms_lead_id' => $chunk])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all() as $row) {
                    if (isset($records[(int)$row->cms_lead_id])) {
                        $add((int)$row->cms_lead_id, (string)$row->value, Campaigns::personName($records[(int)$row->cms_lead_id], $row));
                    }
                }
            } elseif ($type === CrmRecords::COMPANY) {
                foreach (CmsCompany::find()->andWhere([CmsCompany::tableName().'.id' => $chunk])->all() as $company) {
                    $records[(int)$company->id] = $company;
                }
                foreach (CmsCompanyEmail::find()->andWhere(['cms_company_id' => $chunk])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all() as $row) {
                    $add((int)$row->cms_company_id, (string)$row->value, '');
                }
                // The people of the company after its own emails; not $company->users (web user component).
                $links = CmsCompany2user::find()->select(['cms_company_id', 'cms_user_id'])->andWhere(['cms_company_id' => $chunk])->asArray()->all();
                $peopleIds = array_values(array_unique(array_map(static fn($r) => (int)$r['cms_user_id'], $links)));
                $people = $peopleIds ? CmsUser::find()->andWhere([CmsUser::tableName().'.id' => $peopleIds])->indexBy('id')->all() : [];
                $emails = [];
                foreach ($peopleIds ? CmsUserEmail::find()->andWhere(['cms_user_id' => $peopleIds])->orderBy(['id' => SORT_ASC])->all() : [] as $row) {
                    $emails[(int)$row->cms_user_id][] = (string)$row->value;
                }
                usort($links, static fn($a, $b) => [(int)$a['cms_company_id'], (int)$a['cms_user_id']] <=> [(int)$b['cms_company_id'], (int)$b['cms_user_id']]);
                foreach ($links as $link) {
                    $person = $people[(int)$link['cms_user_id']] ?? null;
                    foreach ($person ? ($emails[(int)$person->id] ?? []) : [] as $value) {
                        $add((int)$link['cms_company_id'], $value, trim((string)$person->first_name.' '.(string)($person->patronymic ?? '')));
                    }
                }
            } elseif ($type === CrmRecords::CLIENT) {
                foreach (CmsUser::find()->andWhere([CmsUser::tableName().'.id' => $chunk])->all() as $user) {
                    $records[(int)$user->id] = $user;
                }
                foreach (CmsUserEmail::find()->andWhere(['cms_user_id' => $chunk])->orderBy(['id' => SORT_ASC])->all() as $row) {
                    if ($user = $records[(int)$row->cms_user_id] ?? null) {
                        $add((int)$row->cms_user_id, (string)$row->value, trim((string)$user->first_name.' '.(string)($user->patronymic ?? '')));
                    }
                }
            }
        }

        return ['records' => $records, 'addresses' => $addresses];
    }

    /**
     * Companies of records: a company is its own, a lead its company, a
     * client every company it is a person of.
     *
     * @param array[] $refs "type:id" => [type, id]
     * @param ActiveRecord[] $records "type:id" => record, when already loaded
     * @return int[][] "type:id" => company ids
     */
    public static function companies(array $refs, array $records = []): array
    {
        $result = [];
        $leads = [];
        $clients = [];
        foreach ($refs as $key => [$type, $id]) {
            if ($type === CrmRecords::COMPANY) {
                $result[$key] = [(int)$id];
            } elseif ($type === CrmRecords::LEAD) {
                $record = $records[$key] ?? null;
                if ($record instanceof CmsLead) {
                    $result[$key] = $record->cms_company_id ? [(int)$record->cms_company_id] : [];
                } else {
                    $leads[(int)$id] = $key;
                }
            } elseif ($type === CrmRecords::CLIENT) {
                $clients[(int)$id] = $key;
            }
        }
        foreach (array_chunk(array_keys($leads), 500) as $chunk) {
            foreach (CmsLead::find()->select(['id', 'cms_company_id'])->andWhere(['id' => $chunk])->asArray()->all() as $row) {
                $result[$leads[(int)$row['id']]] = $row['cms_company_id'] ? [(int)$row['cms_company_id']] : [];
            }
        }
        foreach (array_chunk(array_keys($clients), 500) as $chunk) {
            foreach (CmsCompany2user::find()->select(['cms_company_id', 'cms_user_id'])->andWhere(['cms_user_id' => $chunk])->asArray()->all() as $row) {
                $result[$clients[(int)$row['cms_user_id']]][] = (int)$row['cms_company_id'];
            }
        }
        $ordered = [];
        foreach ($refs as $key => $ref) {
            $ordered[$key] = array_values(array_unique($result[$key] ?? []));
        }

        return $ordered;
    }

    /**
     * Counters of a list for its card and the list of lists: members, those
     * an email can go to now, opted out of all mailings or on the stop list,
     * without email.
     *
     * @return array members, email (has an address, not on the stop list), stopped, noEmail, byType (type => count)
     */
    public static function stats(CmsCommList $list): array
    {
        $members = self::members([(int)$list->id]);
        $byType = [];
        foreach ($members as [$type, $id]) {
            $byType[$type][] = $id;
        }
        $first = [];
        foreach ($byType as $type => $ids) {
            foreach (self::addresses($type, $ids)['addresses'] as $id => $addresses) {
                $first[$type.':'.$id] = (string)array_key_first($addresses);
            }
        }
        $stopped = self::stoppedAddresses((int)$list->cms_site_id, $first);
        $result = ['members' => count($members), 'email' => 0, 'stopped' => 0, 'noEmail' => 0,
            'byType' => array_map('count', $byType)];
        foreach ($members as $key => $ref) {
            $address = $first[$key] ?? '';
            if ($address === '') {
                $result['noEmail']++;
            } elseif (isset($stopped[$address])) {
                $result['stopped']++;
            } else {
                $result['email']++;
            }
        }

        return $result;
    }

    /**
     * Rows of the CSV of a list: one per active member with its first email,
     * whether it may get mailings and who added it.
     *
     * @return \Generator of string[]
     */
    public static function exportRows(CmsCommList $list): \Generator
    {
        yield ['Тип', 'Номер', 'Название', 'Email', 'Имя', 'Стоп-лист', 'Добавлен', 'Кто добавил', 'Откуда'];
        $types = CrmRecords::types();
        $query = CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$list->id, 'removed_at' => null])->with(['addedBy'])->orderBy(['id' => SORT_ASC]);
        foreach ($query->batch(500) as $batch) {
            $byType = [];
            foreach ($batch as $member) {
                $byType[$member->record_type][] = (int)$member->record_id;
            }
            $data = [];
            foreach ($byType as $type => $ids) {
                $data[$type] = self::addresses($type, $ids);
            }
            $all = [];
            foreach ($data as $d) {
                foreach ($d['addresses'] as $addresses) {
                    $all[] = (string)array_key_first($addresses);
                }
            }
            $stopped = self::stoppedAddresses((int)$list->cms_site_id, $all);
            foreach ($batch as $member) {
                $record = $data[$member->record_type]['records'][(int)$member->record_id] ?? null;
                $addresses = $data[$member->record_type]['addresses'][(int)$member->record_id] ?? [];
                $address = (string)(array_key_first($addresses) ?? '');
                yield [
                    $types[$member->record_type]['title'] ?? $member->record_type,
                    (string)(int)$member->record_id,
                    $record ? CrmRecords::name($record) : 'удалена из CRM',
                    $address,
                    $address !== '' ? (string)$addresses[$address] : '',
                    isset($stopped[$address]) ? 'да' : '',
                    \Yii::$app->formatter->asDatetime((int)$member->created_at, 'php:d.m.Y H:i'),
                    $member->addedBy ? (string)$member->addedBy->shortDisplayName : '',
                    $member->getViaText(),
                ];
            }
        }
    }

    /** @return true[] address => true: addresses on the stop list of the site */
    public static function stoppedAddresses(int $siteId, array $addresses): array
    {
        $addresses = array_values(array_unique(array_filter(array_map('strval', $addresses))));
        if (!$addresses) {
            return [];
        }
        $contacts = MailingContacts::findMany($siteId, CmsCommContact::KIND_EMAIL, $addresses);
        if (!$contacts) {
            return [];
        }
        $byId = [];
        foreach ($contacts as $contact) {
            $byId[(int)$contact->id] = (string)$contact->value;
        }
        $result = [];
        foreach (CmsCommSuppression::find()->select('cms_comm_contact_id')->andWhere(['cms_comm_contact_id' => array_keys($byId)])->column() as $id) {
            $result[$byId[(int)$id]] = true;
        }

        return $result;
    }
}
