<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommImport;
use skeeks\cms\communication\models\CmsCommImportRow;
use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommListMember;
use skeeks\cms\crm\CrmAccess;
use skeeks\cms\crm\CrmContactParser;
use skeeks\cms\crm\CrmMatcher;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2manager;
use skeeks\cms\models\CmsCompany2user;
use skeeks\cms\models\CmsCompanyEmail;
use skeeks\cms\models\CmsCompanyLink;
use skeeks\cms\models\CmsCompanyPhone;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsUser2manager;
use skeeks\cms\models\CmsUserEmail;
use skeeks\cms\models\CmsUserPhone;
use skeeks\cms\models\User;
use yii\db\Query;
use yii\helpers\Json;

/**
 * A base for mailings into a list (П5, mode CmsCommImport::MODE_AUDIENCE):
 * «загрузить → колонки и список → предпросмотр → импорт», the same steps and
 * rows as LeadImport, but no deals.
 *
 * Every row goes through the one duplicate check of the core (CrmMatcher,
 * companies and contacts of the whole base):
 * - one company or one contact by email, phone or INN — that card joins the
 *   list; the import does not change its responsible, contacts or details;
 * - nothing found — a company (when the row names one) and a contact are
 *   created, linked, with the responsible chosen in the import («Я» by
 *   default or another employee; never none, п. 13а), the source
 *   «Рассылка: база …» and the cold state (Audience::origin(); the contacts
 *   get the topic with the basis of the file, «холодный, согласия нет»);
 * - rows left out with the reason (STATUS_EXCLUDED): several cards match,
 *   only a similar company name, a client of another manager (only "название,
 *   ответственный" is shown), a current client (an active service or a
 *   payment in ACTIVE_PAYMENT_DAYS), a refusal in REFUSAL_PAUSE_DAYS, every
 *   email of the row opted out or on the stop list.
 *
 * The preview counts the same outcomes without writing. "Добавить адреса" of
 * a list is an audience import of pasted lines (fromLines()).
 */
class AudienceImport
{
    /** A closed deal "отказ" this recent keeps the client out of a new cold list. */
    public const REFUSAL_PAUSE_DAYS = 90;

    /** A payment this recent makes a company a current client. */
    public const ACTIVE_PAYMENT_DAYS = 365;

    /** Most pasted lines of "Добавить адреса" at once. */
    public const MAX_LINES = 2000;

    /** The columns of an import made of pasted lines. */
    private const LINE_COLUMNS = ['Компания', 'Контакт', 'Email', 'Телефон', 'ИНН', 'Сайт'];

    /**
     * "Добавить адреса": each line ("Ромашка, Иван, info@…, +7 …" or just an
     * email) is split by the core parser (CrmContactParser) into the columns
     * of a new audience import of the list; $executorId — the responsible of
     * new cards, null — the importing employee (п. 13а).
     *
     * @throws \DomainException
     */
    public static function fromLines(CmsCommList $list, string $text, User $user, ?int $executorId = null): CmsCommImport
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parsed = CrmContactParser::parse($line);
            $name = $parsed['name'];
            $person = $parsed['person'];
            if ($person === '' && $name !== '' && !self::looksLikeCompany($name)) {
                // One text beside a contact is a person's name, unless it looks like a company: «ООО …», «Ромашка».
                [$person, $name] = [$name, ''];
            }
            $rows[] = [$name, $person, implode(', ', $parsed['emails']), implode(', ', $parsed['phones']), implode(', ', $parsed['inns']), implode(', ', $parsed['sites'])];
        }
        if (!$rows) {
            throw new \DomainException('Вставьте хотя бы один адрес: email, телефон или строку «Компания, контакт, email, телефон».');
        }
        if (count($rows) > self::MAX_LINES) {
            throw new \DomainException('За один раз — не больше '.self::MAX_LINES.' строк. Для большой базы загрузите таблицу.');
        }
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::LINE_COLUMNS, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';', '"', '');
        }
        rewind($out);
        $content = (string)stream_get_contents($out);
        fclose($out);

        $import = LeadImport::create((int)$list->cms_site_id, $content, null, true);
        $import->mode = CmsCommImport::MODE_AUDIENCE;
        $import->cms_comm_list_id = (int)$list->id;
        $import->executor_id = $executorId ?: (int)$user->id;
        $import->mapping = Json::encode(['company', 'name', 'email', 'phone', 'inn', 'website']);
        $import->save(false, ['mode', 'cms_comm_list_id', 'executor_id', 'mapping', 'updated_at']);

        return $import;
    }

    /** «ООО Ромашка», «"Ромашка"», «ИП Петров», a name with digits or a dot: a company, not a person. */
    public static function looksLikeCompany(string $text): bool
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($text), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$words) {
            return false;
        }
        $forms = CrmMatcher::instance()->legalForms;
        foreach ($words as $word) {
            if (in_array(trim($word, '«»"\'.,'), $forms, true)) {
                return true;
            }
        }

        return (bool)preg_match('/[«»"\d]/u', $text) || count($words) === 1 && !preg_match('/^[а-яёa-z-]+$/iu', $text);
    }

    /**
     * What will become of the rows, without writing anything.
     *
     * @return array counts (status => n), reasons (text => n of excluded rows), rows: the first $limit outcomes
     */
    public static function preview(CmsCommImport $import, int $limit = 100): array
    {
        $result = ['counts' => array_fill_keys(array_keys(CmsCommImportRow::statuses(CmsCommImport::MODE_AUDIENCE)), 0), 'reasons' => [], 'rows' => []];
        $viewer = self::viewer($import);
        $seen = [];
        $query = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id])->orderBy(['row_num' => SORT_ASC]);
        foreach ($query->batch(LeadImport::CHUNK) as $rows) {
            foreach (self::outcomes($import, $rows, $viewer, $seen) as $outcome) {
                $result['counts'][$outcome['status']]++;
                if ($outcome['status'] === CmsCommImportRow::STATUS_EXCLUDED) {
                    $key = (string)$outcome['rule'];
                    $result['reasons'][$key] = ($result['reasons'][$key] ?? 0) + 1;
                }
                if (count($result['rows']) < $limit) {
                    $result['rows'][] = $outcome;
                }
            }
        }

        return $result;
    }

    /** The import itself; rows already done are skipped, so a repeated run goes on where it stopped. */
    public static function run(CmsCommImport $import, ?JobReporterInterface $reporter = null): void
    {
        $list = $import->cms_comm_list_id ? CmsCommList::findOne((int)$import->cms_comm_list_id) : null;
        $viewer = self::viewer($import);
        if (!$list || !$viewer) {
            throw new \DomainException('Выберите список рассылки: база загружается в список.');
        }
        $import->status = CmsCommImport::STATUS_RUNNING;
        $import->save(false, ['status', 'updated_at']);
        // Cards of earlier rows of this file: a later row with their contact or company name joins them.
        $seen = [];
        foreach (CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id])->andWhere(['not', ['record_id' => null]])->all() as $done) {
            $data = LeadImport::rowData($import, $done->getValues());
            self::remember($seen, $data, (int)$done->row_num, [(string)$done->record_type, (int)$done->record_id]);
        }
        if ($reporter) {
            $reporter->setTotal((int)$import->row_count);
            $reporter->setStage('import', 'Импорт строк в список');
        }
        try {
            while (true) {
                $rows = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id, 'status' => null])
                    ->orderBy(['row_num' => SORT_ASC])->limit(LeadImport::CHUNK)->all();
                if (!$rows) {
                    break;
                }
                if ($reporter && $reporter->isCancelled()) {
                    self::finish($import, CmsCommImport::STATUS_FAILED, 'Импорт остановлен. Сделанные строки сохранены, запустите его снова, чтобы продолжить.');
                    return;
                }
                foreach (self::outcomes($import, $rows, $viewer, $seen) as $outcome) {
                    self::apply($import, $list, $viewer, $outcome, $seen);
                }
                if ($reporter) {
                    $reporter->advance(count($rows));
                    $reporter->heartbeat();
                }
            }
            self::finish($import, CmsCommImport::STATUS_DONE, null);
        } catch (\Throwable $e) {
            self::finish($import, CmsCommImport::STATUS_FAILED, mb_substr($e->getMessage(), 0, 1000));
            throw $e;
        }
        if ($reporter) {
            $reporter->setResult(['importId' => (int)$import->id, 'created' => (int)$import->created_count, 'found' => (int)$import->duplicate_count,
                'excluded' => (int)$import->excluded_count, 'skipped' => (int)$import->skipped_count]);
        }
    }

    /** The employee the import works for: who loaded it (the visibility of found cards is his). */
    private static function viewer(CmsCommImport $import): ?CmsUser
    {
        $id = (int)($import->created_by ?: $import->executor_id);

        return $id ? CmsUser::findOne($id) : null;
    }

    /**
     * @param CmsCommImportRow[] $rows
     * @param array $seen "email:…", "phone:…", "inn:…", "name:…" => [row number, [type, id] or null]
     * @return array[] row, data, status, rule (excluded: a short key), reason, record ([type, id] or null),
     *                 visible, title (what to show for the record), sameFile (row number), joinCompany (company id of an earlier row)
     */
    private static function outcomes(CmsCommImport $import, array $rows, ?User $viewer, array &$seen): array
    {
        $siteId = (int)$import->cms_site_id;
        $matcher = CrmMatcher::instance();
        $datas = [];
        $inputs = [];
        foreach ($rows as $row) {
            $data = LeadImport::rowData($import, $row->getValues());
            $datas[(int)$row->id] = $data;
            $inputs[(int)$row->id] = [
                'emails' => $data['emails'],
                'phones' => $data['phones'],
                'inns' => $data['inn'] !== '' ? preg_split('/[\s,;]+/', $data['inn'], -1, PREG_SPLIT_NO_EMPTY) : [],
                'name' => $data['company'],
                'types' => [CrmMatcher::TYPE_COMPANY, CrmMatcher::TYPE_CLIENT],
            ];
        }
        $found = $matcher->findMany($inputs, $siteId);
        $optedOut = self::optedOut($import, $datas);

        $result = [];
        foreach ($rows as $row) {
            $data = $datas[(int)$row->id];
            $outcome = ['row' => $row, 'data' => $data, 'status' => CmsCommImportRow::STATUS_CREATED, 'rule' => null, 'reason' => null,
                'record' => null, 'visible' => true, 'title' => null, 'sameFile' => null, 'joinCompany' => null];
            $keys = self::keys($data);
            $earlier = null;
            foreach ($keys as $key) {
                if (isset($seen[$key]) && strpos($key, 'name:') !== 0) {
                    $earlier = $seen[$key];
                    break;
                }
            }

            if (!$data['emails'] && !$data['phones']) {
                $outcome['status'] = CmsCommImportRow::STATUS_SKIPPED;
                $outcome['reason'] = $data['bad'] ? 'Неверный email или телефон: '.implode(', ', array_slice($data['bad'], 0, 3)) : 'Нет ни email, ни телефона';
            } elseif ($earlier !== null) {
                $outcome['status'] = CmsCommImportRow::STATUS_DUPLICATE;
                $outcome['sameFile'] = $earlier[0];
                $outcome['record'] = $earlier[1];
                $outcome['reason'] = 'Повтор строки '.$earlier[0].' этого файла';
            } elseif ($emails = $optedOut[(int)$row->id] ?? null) {
                $outcome['status'] = CmsCommImportRow::STATUS_EXCLUDED;
                $outcome['rule'] = 'Отписался или в стоп-листе';
                $outcome['reason'] = 'Отписался: '.implode('; ', $emails);
            } else {
                self::resolve($outcome, $found[(int)$row->id] ?? [], $viewer, $siteId, $seen);
            }
            if ($outcome['status'] === CmsCommImportRow::STATUS_CREATED || ($outcome['status'] === CmsCommImportRow::STATUS_DUPLICATE && $outcome['sameFile'] === null)) {
                // Later rows of the file meet this one; a new card is known after apply(), which fills in the record.
                self::remember($seen, $data, (int)$row->row_num, $outcome['record']);
            }
            $result[] = $outcome;
        }

        return $result;
    }

    /** Found cards → the record of the row, or an exclusion with its reason. */
    private static function resolve(array &$outcome, array $candidates, ?User $viewer, int $siteId, array $seen): void
    {
        $data = $outcome['data'];
        $strong = ['company' => [], 'client' => []];
        $weakCompanies = [];
        foreach ($candidates as $candidate) {
            if (!empty($candidate['strong'])) {
                $strong[$candidate['type']][] = $candidate;
            } elseif ($candidate['type'] === CrmMatcher::TYPE_COMPANY
                && array_filter($candidate['reasons'], static fn($r) => $r['by'] === CrmMatcher::BY_NAME)) {
                $weakCompanies[] = $candidate;
            }
        }
        $exclude = static function (string $rule, string $reason) use (&$outcome) {
            $outcome['status'] = CmsCommImportRow::STATUS_EXCLUDED;
            $outcome['rule'] = $rule;
            $outcome['reason'] = $reason;
        };

        $chosen = null;
        if (count($strong['company']) > 1) {
            $exclude('Несколько совпадений', 'Совпадает с несколькими компаниями CRM: '.self::titles($strong['company'], $viewer, $siteId).'. Разберите строку вручную.');
            return;
        }
        if ($strong['company']) {
            $chosen = $strong['company'][0];
        } elseif (count($strong['client']) > 1) {
            $exclude('Несколько совпадений', 'Совпадает с несколькими контактами CRM. Разберите строку вручную.');
            return;
        } elseif ($strong['client']) {
            $chosen = $strong['client'][0];
        }

        if ($chosen === null) {
            $name = CrmMatcher::normalizeName((string)$data['company'], CrmMatcher::instance()->legalForms);
            if ($name !== '' && isset($seen['name:'.$name]) && ($seen['name:'.$name][1][0] ?? null) === CrmRecords::COMPANY) {
                // A company created by an earlier row of this file: the contact joins it.
                $outcome['joinCompany'] = (int)$seen['name:'.$name][1][1];
                $outcome['reason'] = 'Контакт компании из строки '.$seen['name:'.$name][0];
                return;
            }
            if ($name !== '' && isset($seen['name:'.$name])) {
                $outcome['joinCompany'] = -1;
                $outcome['reason'] = 'Контакт компании из строки '.$seen['name:'.$name][0];
                return;
            }
            if ($weakCompanies) {
                // A name is a hint, not a link: a new card would be a duplicate, an existing one may be another company.
                $exclude('Похожая компания уже есть', 'Похожая компания уже есть: '.self::titles($weakCompanies, $viewer, $siteId).'. Проверьте и добавьте её в список вручную.');
            }
            return;
        }

        $type = $chosen['type'] === CrmMatcher::TYPE_COMPANY ? CrmRecords::COMPANY : CrmRecords::CLIENT;
        $id = (int)$chosen['id'];
        $visible = $viewer && CrmMatcher::instance()->visibleIds($chosen['type'], [$id], $viewer);
        $outcome['record'] = [$type, $id];
        $outcome['visible'] = (bool)$visible;
        $outcome['status'] = CmsCommImportRow::STATUS_DUPLICATE;
        $outcome['reason'] = self::reasonText($chosen);
        foreach ($chosen['reasons'] as $reason) {
            if (!empty($reason['strong'])) {
                $outcome['by'] = (string)$reason['by'];
                break;
            }
        }
        if (!$visible) {
            $presented = $viewer ? CrmAccess::instance()->presentCandidate($chosen, false, $viewer) : [];
            $who = $presented['title'] ? '«'.$presented['title'].'»' : 'частное лицо';
            $responsible = $presented['responsible'] ?? [];
            $exclude('Клиент другого менеджера', 'Клиент другого менеджера: '.$who.($responsible ? ', ответственный '.implode(', ', array_column($responsible, 'name')) : '').'.');
            return;
        }
        if ($active = self::activeClient($type, $id)) {
            $exclude('Действующий клиент', 'Действующий клиент: '.$active.'. В холодную рассылку не включается.');
            return;
        }
        if ($refusal = self::recentRefusal($type, $id, $siteId)) {
            $exclude('Недавний отказ', $refusal);
        }
    }

    private static function apply(CmsCommImport $import, CmsCommList $list, CmsUser $viewer, array $outcome, array &$seen): void
    {
        /** @var CmsCommImportRow $row */
        $row = $outcome['row'];
        $data = $outcome['data'];
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $row->status = $outcome['status'];
            $row->reason = $outcome['reason'] !== null ? mb_substr($outcome['reason'], 0, 500) : null;
            $record = null;
            if ($outcome['status'] === CmsCommImportRow::STATUS_CREATED) {
                $joinId = (int)$outcome['joinCompany'];
                if ($joinId < 0) {
                    // The company of the earlier row is made in this run: its id is known now.
                    $name = CrmMatcher::normalizeName((string)$data['company'], CrmMatcher::instance()->legalForms);
                    $joinId = (int)($seen['name:'.$name][1][1] ?? 0);
                }
                $record = self::createCards($import, $data, $joinId ?: null);
            } elseif ($outcome['status'] === CmsCommImportRow::STATUS_DUPLICATE && $outcome['record']) {
                $class = CrmRecords::codeOf($outcome['record'][0]);
                $record = $class::findOne((int)$outcome['record'][1]);
                if ($outcome['sameFile'] === null) {
                    $row->matched_by = (string)($outcome['by'] ?? '');
                    $row->match_code = CrmRecords::codeOf($outcome['record'][0]);
                    $row->match_id = (int)$outcome['record'][1];
                }
            }
            if ($record) {
                $row->record_type = RecipientLists::recordType($record);
                $row->record_id = (int)$record->primaryKey;
                LeadImport::applyConsent($import, $data);
                // The employee runs the import on records of the import: no second visibility check (also in jobs).
                (new RecipientLists())->add($list, $record, $viewer, CmsCommListMember::VIA_IMPORT, 'import:'.(int)$import->id, false);
                self::remember($seen, $data, (int)$row->row_num, [(string)$row->record_type, (int)$row->record_id]);
                if ($record instanceof CmsCompany) {
                    self::remember($seen, ['emails' => [], 'phones' => [], 'inn' => '', 'company' => $data['company']], (int)$row->row_num, [CrmRecords::COMPANY, (int)$record->id]);
                }
            }
            $row->save(false);
            $counter = [CmsCommImportRow::STATUS_CREATED => 'created_count', CmsCommImportRow::STATUS_DUPLICATE => 'duplicate_count',
                CmsCommImportRow::STATUS_EXCLUDED => 'excluded_count', CmsCommImportRow::STATUS_SKIPPED => 'skipped_count'][$outcome['status']];
            $import->updateCounters([$counter => 1]);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * A new company (when the row names one) and a contact person linked to
     * it; the contacts go to the person, or to the company without one. The
     * responsible is the one of the import, else its author; no password, no invitation.
     *
     * @return CmsCompany|CmsUser the card that joins the list: the company, else the person
     */
    private static function createCards(CmsCommImport $import, array $data, ?int $companyId): \yii\db\ActiveRecord
    {
        $cards = self::newClient($import, $data, $companyId);

        return $cards['company'] ?: $cards['person'];
    }

    /**
     * The cards of createCards() (also for LeadImport: a deal of an import is made with its client, П2г).
     *
     * @return array company (CmsCompany|null), person (CmsUser|null); at least one of them
     */
    public static function newClient(CmsCommImport $import, array $data, ?int $companyId = null): array
    {
        $siteId = (int)$import->cms_site_id;
        // A CRM client always has a main responsible (п. 13а): an import without a chosen one — its author.
        $managerId = (int)($import->executor_id ?: $import->created_by);
        $source = 'Рассылка: база '.($import->file_name ? '«'.$import->file_name.'»' : ($import->list ? '«'.$import->list->name.'»' : '№'.(int)$import->id));
        $company = $companyId ? CmsCompany::findOne($companyId) : null;
        if (!$company && $data['company'] !== '') {
            $lines = array_filter([
                $data['city'] !== '' ? 'Город: '.$data['city'] : null,
                $data['inn'] !== '' ? 'ИНН: '.$data['inn'] : null,
                $data['comment'] !== '' ? $data['comment'] : null,
                $source.', строка импорта №'.(int)$import->id.'.',
            ]);
            $company = new CmsCompany(['name' => mb_substr($data['company'], 0, 255), 'description' => implode("\n", $lines)]);
            if (!$company->save()) {
                throw new \RuntimeException('Не удалось создать компанию «'.$data['company'].'»: '.implode('; ', $company->getFirstErrors()));
            }
            if ($managerId) {
                (new CmsCompany2manager(['cms_company_id' => (int)$company->id, 'cms_user_id' => $managerId]))->save(false);
            }
            if ($data['website'] !== '') {
                $url = preg_match('~^https?://~i', $data['website']) ? $data['website'] : 'https://'.$data['website'];
                $link = new CmsCompanyLink(['cms_company_id' => (int)$company->id, 'url' => mb_substr($url, 0, 255)]);
                $link->save();
            }
            Audience::origin($siteId, $company, $source, (int)$import->id);
        }

        $person = null;
        $emails = $data['emails'];
        // A phone the card does not accept (CmsUserPhone/CmsCompanyPhone rules) is left in the row, not a reason to lose the recipient.
        $phones = array_values(array_filter($data['phones'], static fn($p) => (new CmsUserPhone(['value' => SendContacts::formatPhone((string)$p)]))->validate(['value'])));
        if ($data['name'] !== '' || !$company) {
            $person = new CmsUser(['is_worker' => 0, 'cms_site_id' => $siteId] + self::splitName($data['name']));
            // The main email and phone through the core (User::setEmail()): a contact without a name is shown by them.
            if ($emails) {
                $person->email = (string)array_shift($emails);
            }
            if ($phones) {
                $person->phone = SendContacts::formatPhone((string)array_shift($phones));
            }
            if (!$person->save()) {
                throw new \RuntimeException('Не удалось создать контакт: '.implode('; ', $person->getFirstErrors()));
            }
            if ($managerId) {
                (new CmsUser2manager(['client_id' => (int)$person->id, 'worker_id' => $managerId]))->save(false);
            }
            if ($company) {
                (new CmsCompany2user(['cms_company_id' => (int)$company->id, 'cms_user_id' => (int)$person->id]))->save(false);
            }
            Audience::origin($siteId, $person, $source, (int)$import->id);
        }

        foreach ($person ? $emails : $data['emails'] as $i => $email) {
            $contact = $person
                ? new CmsUserEmail(['cms_user_id' => (int)$person->id, 'cms_site_id' => $siteId, 'value' => $email])
                : new CmsCompanyEmail(['cms_company_id' => (int)$company->id, 'value' => $email, 'sort' => 100 + $i]);
            if (!$contact->save()) {
                throw new \RuntimeException('Email '.$email.': '.implode('; ', $contact->getFirstErrors()));
            }
        }
        foreach ($phones as $i => $phone) {
            $value = SendContacts::formatPhone((string)$phone);
            $contact = $person
                ? new CmsUserPhone(['cms_user_id' => (int)$person->id, 'cms_site_id' => $siteId, 'value' => $value])
                : new CmsCompanyPhone(['cms_company_id' => (int)$company->id, 'value' => $value, 'sort' => 100 + $i]);
            if (!$contact->save()) {
                throw new \RuntimeException('Телефон '.$value.': '.implode('; ', $contact->getFirstErrors()));
            }
        }

        return ['company' => $company, 'person' => $person];
    }

    /** "Андрей Смирнов" → first, last; "Иванов Иван Иванович" → last, first, patronymic. */
    public static function splitName(string $name): array
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) >= 3) {
            return ['last_name' => $words[0], 'first_name' => $words[1], 'patronymic' => implode(' ', array_slice($words, 2))];
        }
        if (count($words) === 2) {
            return ['first_name' => $words[0], 'last_name' => $words[1]];
        }

        return ['first_name' => $words[0] ?? null];
    }

    /**
     * Rows whose every email opted out of the topic of the file or of all
     * mailings, complained or bounces (MailingPolicy, MailingConsents).
     *
     * @param array[] $datas row id => rowData()
     * @return string[][] row id => "email — reason"
     */
    private static function optedOut(CmsCommImport $import, array $datas): array
    {
        $emails = [];
        foreach ($datas as $data) {
            foreach ($data['emails'] as $email) {
                $emails[$email] = true;
            }
        }
        if (!$emails) {
            return [];
        }
        $siteId = (int)$import->cms_site_id;
        $refused = [];
        if ($import->cms_comm_topic_id) {
            foreach (MailingPolicy::checkMany($siteId, 'email', array_keys($emails), (int)$import->cms_comm_topic_id) as $email => $check) {
                if (!$check['allowed'] && $check['reason'] !== MailingPolicy::REASON_INVALID && $check['reason'] !== MailingPolicy::REASON_TOPIC) {
                    $refused[$email] = (string)$check['message'];
                }
            }
        } else {
            foreach (MailingPolicy::warnings($siteId, CmsCommContact::KIND_EMAIL, array_keys($emails)) as $email => $why) {
                $refused[$email] = $why;
            }
        }
        $result = [];
        foreach ($datas as $id => $data) {
            if ($data['emails'] && !array_diff($data['emails'], array_keys($refused))) {
                $result[$id] = array_map(static fn($e) => $e.' — '.mb_strtolower($refused[$e], 'UTF-8'), $data['emails']);
            }
        }

        return $result;
    }

    /** "активная услуга «Хостинг»" or "оплата от 12.03.2026", null for a company or person that is not a current client. */
    public static function activeClient(string $type, int $id): ?string
    {
        $column = $type === CrmRecords::COMPANY ? 'cms_company_id' : 'cms_user_id';
        $now = time();
        $service = (new Query())->from('{{%cms_deal}}')->select('name')->andWhere([$column => $id, 'is_active' => 1])
            ->andWhere(['or', ['end_at' => null], ['>', 'end_at', $now]])->orderBy(['id' => SORT_DESC])->limit(1)->scalar();
        if ($service !== false && $service !== null) {
            return 'активная услуга «'.$service.'»';
        }
        if (\Yii::$app->db->getTableSchema('{{%shop_payment}}') !== null) {
            $paid = (new Query())->from('{{%shop_payment}}')->select('created_at')->andWhere([$column => $id, 'is_debit' => 1])
                ->andWhere(['>', 'created_at', $now - self::ACTIVE_PAYMENT_DAYS * 86400])->orderBy(['created_at' => SORT_DESC])->limit(1)->scalar();
            if ($paid) {
                return 'оплата от '.\Yii::$app->formatter->asDate((int)$paid, 'php:d.m.Y');
            }
        }

        return null;
    }

    /** "Недавний отказ: сделка №12 закрыта 01.10.2026 (дорого)", null when none in the pause. */
    public static function recentRefusal(string $type, int $id, int $siteId): ?string
    {
        $column = $type === CrmRecords::COMPANY ? 'cms_company_id' : 'cms_user_id';
        $since = time() - self::REFUSAL_PAUSE_DAYS * 86400;
        $query = CmsLead::find()->andWhere(['cms_site_id' => $siteId, $column => $id, 'status' => CmsLead::STATUS_REJECTED])
            ->andWhere(['>', new \yii\db\Expression('COALESCE(processed_at, updated_at)'), $since])->orderBy(['id' => SORT_DESC]);
        if (CmsLead::getTableSchema()->getColumn('triage_result')) {
            // Spam, a merged request, support are not refusals of a client.
            $query->andWhere(['triage_result' => null]);
        }
        $lead = $query->limit(1)->one();
        if (!$lead) {
            return null;
        }
        $at = (int)($lead->processed_at ?: $lead->updated_at);

        return 'Недавний отказ: сделка №'.(int)$lead->id.' закрыта '.\Yii::$app->formatter->asDate($at, 'php:d.m.Y')
            .($lead->reject_reason ? ' («'.mb_substr((string)$lead->reject_reason, 0, 80).'»)' : '')
            .'. Пауза '.self::REFUSAL_PAUSE_DAYS.' дней.';
    }

    /** "совпал email info@…, телефон …" of a candidate. */
    private static function reasonText(array $candidate): string
    {
        $by = ['email' => 'email', 'phone' => 'телефон', 'inn' => 'ИНН'];
        $parts = [];
        foreach ($candidate['reasons'] as $reason) {
            if (!empty($reason['strong']) && isset($by[$reason['by']])) {
                $parts[] = $by[$reason['by']].' '.($reason['by'] === 'phone' ? SendContacts::formatPhone((string)$reason['value']) : $reason['value']);
            }
        }

        return 'совпал '.implode(', ', array_unique($parts));
    }

    /** Names of candidates as the employee may see them: own — the name, others — the name and the responsible. */
    private static function titles(array $candidates, ?User $viewer, int $siteId): string
    {
        $names = [];
        foreach (array_slice($candidates, 0, 3) as $candidate) {
            if (!$viewer) {
                continue;
            }
            $visible = (bool)CrmMatcher::instance()->visibleIds($candidate['type'], [(int)$candidate['id']], $viewer);
            $p = CrmAccess::instance()->presentCandidate($candidate, $visible, $viewer);
            $names[] = ($p['title'] ? '«'.$p['title'].'»' : 'частное лицо').($visible ? ' (№'.(int)$candidate['id'].')' : ($p['responsible'] ? ', ответственный '.implode(', ', array_column($p['responsible'], 'name')) : ''));
        }

        return $names ? implode('; ', $names) : 'запись CRM';
    }

    private static function keys(array $data): array
    {
        $keys = array_merge(array_map(static fn($e) => 'email:'.$e, $data['emails']), array_map(static fn($p) => 'phone:'.$p, $data['phones']));
        foreach (preg_split('/[\s,;]+/', (string)$data['inn'], -1, PREG_SPLIT_NO_EMPTY) as $inn) {
            if (($inn = CrmMatcher::normalizeInn($inn)) !== '') {
                $keys[] = 'inn:'.$inn;
            }
        }
        $name = CrmMatcher::normalizeName((string)$data['company'], CrmMatcher::instance()->legalForms);
        if ($name !== '') {
            $keys[] = 'name:'.$name;
        }

        return $keys;
    }

    private static function remember(array &$seen, array $data, int $rowNum, ?array $record): void
    {
        foreach (self::keys($data) as $key) {
            if (!isset($seen[$key]) || ($seen[$key][1] === null && $record !== null)) {
                $seen[$key] = [$seen[$key][0] ?? $rowNum, $record];
            }
        }
    }

    private static function finish(CmsCommImport $import, string $status, ?string $error): void
    {
        $import->refresh();
        $import->status = $status;
        $import->error = $error;
        $import->finished_at = time();
        $import->save(false, ['status', 'error', 'finished_at', 'updated_at']);
        if (!$import->cms_job_run_id || !$import->created_by) {
            return;
        }
        $current = \Yii::$app->has('user') && !\Yii::$app->user->isGuest ? (int)\Yii::$app->user->id : 0;
        if ((int)$import->created_by !== $current) {
            // A run in the background: the employee who started it learns that it is over.
            \Yii::$app->notifications->notify((int)$import->created_by, 'import.done', [
                'name' => ($status === CmsCommImport::STATUS_DONE ? 'Готов импорт в список №' : 'Ошибка импорта в список №').(int)$import->id,
                'comment' => 'Новых: '.(int)$import->created_count.', найдено в CRM: '.(int)$import->duplicate_count
                    .', исключено: '.(int)$import->excluded_count.', пропущено: '.(int)$import->skipped_count.'.',
                'model_code' => CmsCommImport::class,
                'model_id' => (int)$import->id,
            ]);
        }
    }
}
