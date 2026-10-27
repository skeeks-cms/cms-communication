<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommImport;
use skeeks\cms\communication\models\CmsCommImportRow;
use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommListMember;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsWebNotify;
use skeeks\cms\services\CmsLeadService;
use yii\behaviors\BlameableBehavior;
use yii\helpers\Json;

/**
 * Import of mailing recipients into leads: "загрузить → сопоставить колонки
 * → предпросмотр с повторами → импорт".
 *
 * - A CSV file or a table pasted from Excel / Google Sheets becomes an
 *   import with its rows in the database (no files are kept between the
 *   steps).
 * - Every row is checked against the CRM (CrmDuplicates: email, phone, INN,
 *   name of leads, companies, clients) and against earlier rows of the same
 *   file. A duplicate is not created again; a row without an email and a
 *   phone is skipped with the reason.
 * - A new lead has the source "Рассылка" (CmsLead::SOURCE_MAILING, "Импорт"
 *   with an older core), the responsible employee, the file values in the
 *   description and the source data, and a stable source_ref per row, so a
 *   repeated run creates nothing twice.
 * - The topic and the consent basis of the file are put on the contacts of
 *   every new and duplicate row (MailingConsents: an opt-out of a client is
 *   never undone, a real consent is never weakened to a cold mark).
 * - Up to SYNC_LIMIT rows run at once; larger files go to the job
 *   comm.import.leads. The rows of a finished import are its report.
 */
class LeadImport
{
    /** Rows imported in the request itself; more go to the background job. */
    public const SYNC_LIMIT = 200;

    /** Rows of one file at most. */
    public const MAX_ROWS = 20000;

    /** Rows read and checked against the CRM at once. */
    public const CHUNK = 200;

    /** @return string[] field => title; email and phone may take several columns */
    public static function fields(): array
    {
        return [
            'company' => 'Компания',
            'name' => 'Контактное лицо',
            'email' => 'Email',
            'phone' => 'Телефон',
            'inn' => 'ИНН',
            'website' => 'Сайт',
            'city' => 'Город',
            'comment' => 'Комментарий',
        ];
    }

    /**
     * Rows of a CSV file or a pasted table: the encoding (UTF-8 or
     * Windows-1251) and the separator (tab, semicolon, comma) are detected.
     *
     * @return string[][] rows of trimmed values, empty rows dropped
     */
    public static function parse(string $content): array
    {
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string)mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $first = (string)strtok($content, "\n");
        $delimiter = ',';
        $best = 0;
        foreach (["\t", ';', ','] as $candidate) {
            if (($count = substr_count($first, $candidate)) > $best) {
                $best = $count;
                $delimiter = $candidate;
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $row = array_map(static fn($v) => trim((string)$v), (array)$row);
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($stream);

        return $rows;
    }

    /** The field of a column by its header: "E-mail" → email, "Телефон 2" → phone. */
    public static function guessField(string $header): string
    {
        $h = mb_strtolower(trim($header));
        $rules = [
            'email' => '/e-?mail|почт|емейл|имейл/u',
            'phone' => '/телеф|phone|тел\b|тел\.|моб|сотов/u',
            'inn' => '/инн|inn\b/u',
            'website' => '/сайт|site|url|домен|www/u',
            'city' => '/город|city|регион/u',
            'company' => '/компан|организац|назван|фирма|юр.*лиц|company|клиент/u',
            'name' => '/имя|фио|контакт|name|ф\.и\.о|руководит|директор/u',
            'comment' => '/коммент|примеч|заметк|описан|comment|note/u',
        ];
        foreach ($rules as $field => $pattern) {
            if (preg_match($pattern, $h)) {
                return $field;
            }
        }

        return '';
    }

    /**
     * Step 1: the import and its rows from the uploaded content.
     *
     * @throws \DomainException with a message for the employee
     */
    public static function create(int $siteId, string $content, ?string $fileName, bool $hasHeader): CmsCommImport
    {
        $rows = self::parse($content);
        if (!$rows) {
            throw new \DomainException('В файле нет строк. Загрузите CSV или вставьте таблицу.');
        }
        $width = max(array_map('count', $rows));
        $headers = $hasHeader ? array_shift($rows) : [];
        if (!$rows) {
            throw new \DomainException('Кроме заголовков, в таблице нет строк.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new \DomainException('В файле больше '.self::MAX_ROWS.' строк. Разделите его на части.');
        }
        if ($fileName !== null && !mb_check_encoding($fileName, 'UTF-8')) {
            // Some programs send the file name in the Windows encoding.
            $fileName = (string)mb_convert_encoding($fileName, 'UTF-8', 'Windows-1251');
        }
        $titles = [];
        $mapping = [];
        for ($i = 0; $i < $width; $i++) {
            $titles[$i] = trim((string)($headers[$i] ?? '')) !== '' ? trim((string)$headers[$i]) : 'Колонка '.($i + 1);
            $mapping[$i] = $hasHeader ? self::guessField($titles[$i]) : '';
        }
        if (!$hasHeader) {
            // Without headers the values tell: a column of emails, of phones.
            foreach (array_slice($rows, 0, 20) as $row) {
                foreach ($row as $i => $value) {
                    if ($mapping[$i] === '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $mapping[$i] = 'email';
                    } elseif ($mapping[$i] === '' && preg_match('/^[\d\s()+\-]{10,20}$/', $value) && SendContacts::normalizePhone($value)) {
                        $mapping[$i] = 'phone';
                    }
                }
            }
        }

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $import = new CmsCommImport([
                'cms_site_id' => $siteId,
                'file_name' => $fileName !== null && trim($fileName) !== '' ? mb_substr(trim($fileName), 0, 255) : null,
                'status' => CmsCommImport::STATUS_DRAFT,
                'columns' => Json::encode($titles),
                'mapping' => Json::encode($mapping),
                'row_count' => count($rows),
                'consent_basis' => CmsCommContactTopic::BASIS_COLD,
            ]);
            $import->save(false);
            foreach (array_chunk($rows, 500, true) as $chunk) {
                $insert = [];
                foreach ($chunk as $n => $row) {
                    $insert[] = [(int)$import->id, $n + 1 + ($hasHeader ? 1 : 0), Json::encode(array_map('strval', array_pad($row, $width, '')))];
                }
                \Yii::$app->db->createCommand()->batchInsert(CmsCommImportRow::tableName(), ['cms_comm_import_id', 'row_num', 'data'], $insert)->execute();
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $import;
    }

    /**
     * A row as lead data.
     *
     * @return array name, company, emails, phones (digits), bad (values that are not an email or a phone), inn, website, city, comment, values (title => value)
     */
    public static function rowData(CmsCommImport $import, array $values): array
    {
        $titles = $import->getColumnTitles();
        $data = ['name' => '', 'company' => '', 'emails' => [], 'phones' => [], 'bad' => [], 'inn' => '', 'website' => '', 'city' => '', 'comment' => '', 'values' => []];
        $mapping = $import->getMappingArray();
        foreach ($values as $i => $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $data['values'][$titles[$i] ?? 'Колонка '.($i + 1)] = $value;
            $field = $mapping[$i] ?? '';
            if ($field === 'email' || $field === 'phone') {
                // One cell may hold several: "a@b.ru, c@d.ru", "8 900 …; 8 901 …".
                foreach (preg_split($field === 'email' ? '/[\s,;]+/' : '/[,;\/]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                    $kind = $field === 'email' ? CmsCommContact::KIND_EMAIL : CmsCommContact::KIND_PHONE;
                    $normalized = CmsCommContact::normalize($kind, $part);
                    if ($normalized === null) {
                        $data['bad'][] = trim($part);
                    } elseif (!in_array($normalized, $data[$field.'s'], true)) {
                        $data[$field.'s'][] = $normalized;
                    }
                }
            } elseif ($field !== '' && isset(self::fields()[$field])) {
                $data[$field] = $data[$field] === '' ? $value : $data[$field].', '.$value;
            }
        }

        return $data;
    }

    /**
     * Step 3: what will become of the rows, without writing anything.
     *
     * @return array counts (created, duplicate, skipped) and rows: the first $limit rows with their outcome
     */
    public static function preview(CmsCommImport $import, int $limit = 100): array
    {
        $result = ['counts' => [CmsCommImportRow::STATUS_CREATED => 0, CmsCommImportRow::STATUS_DUPLICATE => 0, CmsCommImportRow::STATUS_SKIPPED => 0], 'rows' => []];
        $duplicates = new CrmDuplicates((int)$import->cms_site_id);
        $seen = [];
        $query = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id])->orderBy(['row_num' => SORT_ASC]);
        foreach ($query->batch(self::CHUNK) as $rows) {
            foreach (self::outcomes($import, $rows, $duplicates, $seen) as $outcome) {
                $result['counts'][$outcome['status']]++;
                if (count($result['rows']) < $limit) {
                    $result['rows'][] = $outcome;
                }
            }
        }

        return $result;
    }

    /**
     * Step 4: the import itself (in the request for small files, in the job
     * comm.import.leads for large ones). Rows already done are skipped, so a
     * repeated or resumed run goes on where it stopped.
     */
    public static function run(CmsCommImport $import, ?JobReporterInterface $reporter = null): void
    {
        $import->status = CmsCommImport::STATUS_RUNNING;
        $import->save(false, ['status', 'updated_at']);
        $duplicates = new CrmDuplicates((int)$import->cms_site_id);
        // Leads made by earlier rows of this file: a later row with their contact is a repeat in the file.
        $seen = [];
        foreach (CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id, 'status' => CmsCommImportRow::STATUS_CREATED])->all() as $done) {
            $seen['lead:'.$done->cms_lead_id] = (int)$done->row_num;
        }
        if ($reporter) {
            $reporter->setTotal((int)$import->row_count);
            $reporter->setStage('import', 'Импорт строк в сделки');
        }

        try {
            while (true) {
                $rows = CmsCommImportRow::find()->andWhere(['cms_comm_import_id' => (int)$import->id, 'status' => null])
                    ->orderBy(['row_num' => SORT_ASC])->limit(self::CHUNK)->all();
                if (!$rows) {
                    break;
                }
                if ($reporter && $reporter->isCancelled()) {
                    self::finish($import, CmsCommImport::STATUS_FAILED, 'Импорт остановлен. Сделанные строки сохранены, запустите его снова, чтобы продолжить.');
                    return;
                }
                foreach (self::outcomes($import, $rows, $duplicates, $seen) as $outcome) {
                    self::apply($import, $outcome, $duplicates, $seen);
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
            $reporter->setResult(['importId' => (int)$import->id, 'created' => (int)$import->created_count,
                'duplicates' => (int)$import->duplicate_count, 'skipped' => (int)$import->skipped_count]);
        }
    }

    /**
     * What becomes of each row: created (would be), a duplicate (of a CRM
     * record or of an earlier row) or skipped with the reason.
     *
     * @param CmsCommImportRow[] $rows
     * @param array $seen contact or lead key => row number, of rows earlier in this file
     * @return array[] each: row, data, status, reason, match (type, id, by, value) or null, sameFile (row number) or null
     */
    private static function outcomes(CmsCommImport $import, array $rows, CrmDuplicates $duplicates, array &$seen): array
    {
        $datas = [];
        $candidates = [];
        foreach ($rows as $row) {
            $data = self::rowData($import, $row->getValues());
            $datas[(int)$row->id] = $data;
            $candidates[(int)$row->id] = [
                'emails' => $data['emails'],
                'phones' => $data['phones'],
                'inn' => $data['inn'],
                'name' => $data['company'] !== '' ? $data['company'] : '',
            ];
        }
        $matches = $duplicates->findMany($candidates);

        $result = [];
        foreach ($rows as $row) {
            $data = $datas[(int)$row->id];
            $outcome = ['row' => $row, 'data' => $data, 'status' => CmsCommImportRow::STATUS_CREATED, 'reason' => null, 'match' => null, 'sameFile' => null];
            $keys = array_merge(array_map(static fn($e) => 'email:'.$e, $data['emails']), array_map(static fn($p) => 'phone:'.$p, $data['phones']),
                $data['inn'] !== '' && CrmDuplicates::normalizeInn($data['inn']) !== '' ? ['inn:'.CrmDuplicates::normalizeInn($data['inn'])] : []);
            $earlier = null;
            foreach ($keys as $key) {
                if (isset($seen[$key])) {
                    $earlier = $seen[$key];
                    break;
                }
            }
            $match = $matches[(int)$row->id] ?? null;
            if ($match && $match['type'] === CrmRecords::LEAD && isset($seen['lead:'.$match['id']])) {
                $earlier = $earlier ?? $seen['lead:'.$match['id']];
            }

            if (!$data['emails'] && !$data['phones']) {
                $outcome['status'] = CmsCommImportRow::STATUS_SKIPPED;
                $outcome['reason'] = $data['bad']
                    ? 'Неверный email или телефон: '.implode(', ', array_slice($data['bad'], 0, 3))
                    : 'Нет ни email, ни телефона';
            } elseif ($earlier !== null) {
                $outcome['status'] = CmsCommImportRow::STATUS_DUPLICATE;
                $outcome['sameFile'] = $earlier;
                $outcome['match'] = $match;
                $outcome['reason'] = 'Повтор строки '.$earlier.' этого файла';
            } elseif ($match) {
                $outcome['status'] = CmsCommImportRow::STATUS_DUPLICATE;
                $outcome['match'] = $match;
                $outcome['reason'] = self::matchText($match);
            } else {
                // The next rows of the file meet this one.
                foreach ($keys as $key) {
                    $seen[$key] = (int)$row->row_num;
                }
            }
            $result[] = $outcome;
        }

        return $result;
    }

    /** "уже есть в CRM, совпал email info@…" (the record itself is shown beside it). */
    public static function matchText(array $match): string
    {
        $by = ['email' => 'совпал email', 'phone' => 'совпал телефон', 'inn' => 'совпал ИНН', 'name' => 'совпало название'][$match['by']] ?? 'совпадение';
        $value = $match['by'] === 'phone' ? SendContacts::formatPhone((string)$match['value']) : (string)$match['value'];

        return 'уже есть в CRM, '.$by.' '.$value;
    }

    private static function apply(CmsCommImport $import, array $outcome, CrmDuplicates $duplicates, array &$seen): void
    {
        /** @var CmsCommImportRow $row */
        $row = $outcome['row'];
        $data = $outcome['data'];
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $row->status = $outcome['status'];
            $row->reason = $outcome['reason'] !== null ? mb_substr($outcome['reason'], 0, 500) : null;
            if ($outcome['status'] === CmsCommImportRow::STATUS_CREATED) {
                $lead = self::createLead($import, $row, $data);
                $row->cms_lead_id = (int)$lead->id;
                $seen['lead:'.$lead->id] = (int)$row->row_num;
                $duplicates->remember((int)$lead->id, $data['phones']);
            } elseif ($outcome['status'] === CmsCommImportRow::STATUS_DUPLICATE) {
                $row->matched_by = $outcome['sameFile'] !== null ? 'file' : (string)$outcome['match']['by'];
                if ($outcome['match']) {
                    $row->match_code = CrmRecords::codeOf($outcome['match']['type']);
                    $row->match_id = (int)$outcome['match']['id'];
                }
            }
            if ($outcome['status'] !== CmsCommImportRow::STATUS_SKIPPED) {
                self::applyConsent($import, $data);
                self::addToList($import, $row);
            }
            $row->save(false);
            $counter = [CmsCommImportRow::STATUS_CREATED => 'created_count', CmsCommImportRow::STATUS_DUPLICATE => 'duplicate_count',
                CmsCommImportRow::STATUS_SKIPPED => 'skipped_count'][$outcome['status']];
            $import->updateCounters([$counter => 1]);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * The list of recipients chosen for the import gets the new lead or the
     * CRM record the row matched (a lead, company or client), "импорт №N",
     * in the transaction of the row.
     */
    private static function addToList(CmsCommImport $import, CmsCommImportRow $row): void
    {
        if (!$import->cms_comm_list_id) {
            return;
        }
        $list = CmsCommList::findOne((int)$import->cms_comm_list_id);
        $user = $import->created_by ? \skeeks\cms\models\CmsUser::findOne((int)$import->created_by) : null;
        if (!$list || !$user) {
            return;
        }
        $record = $row->cms_lead_id ? CmsLead::findOne((int)$row->cms_lead_id) : null;
        if (!$record && $row->match_code && $row->match_id && ($type = CrmRecords::typeOfCode((string)$row->match_code))) {
            $class = CrmRecords::codeOf($type);
            $record = $class::findOne((int)$row->match_id);
        }
        if ($record && RecipientLists::recordType($record)) {
            // The employee runs the import on records of the import: the list needs no second visibility check (also in jobs).
            (new RecipientLists())->add($list, $record, $user, CmsCommListMember::VIA_IMPORT, 'import:'.(int)$import->id, false);
        }
    }

    private static function createLead(CmsCommImport $import, CmsCommImportRow $row, array $data): CmsLead
    {
        $sourceType = defined(CmsLead::class.'::SOURCE_MAILING') ? CmsLead::SOURCE_MAILING : CmsLead::SOURCE_IMPORT;
        $sourceRef = 'comm-import:'.(int)$import->id.':'.(int)$row->row_num;
        $siteId = (int)$import->cms_site_id;
        if ($existing = CmsLead::find()->fromSource($sourceType, $sourceRef, $siteId)->one()) {
            return $existing;
        }

        $name = $data['company'] !== '' && $data['name'] !== '' ? $data['company'].' — '.$data['name']
            : ($data['company'] ?: ($data['name'] ?: ($data['emails'][0] ?? SendContacts::formatPhone((string)($data['phones'][0] ?? '')))));
        $lines = [];
        foreach ($data['values'] as $title => $value) {
            $lines[] = $title.': '.$value;
        }
        $lines[] = '';
        $lines[] = 'Импорт №'.(int)$import->id.($import->file_name ? ' («'.$import->file_name.'»)' : '').', строка '.(int)$row->row_num.'.';

        $lead = new CmsLead();
        // Created without the responsible, so that core does not send a notification per lead;
        // the responsible gets one notification about the whole import (finish()).
        $lead->deferManagerNotifications = true;
        $lead->setAttributes([
            'cms_site_id' => $siteId,
            'name' => mb_substr($name, 0, 255),
            'description' => implode("\n", $lines),
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'source_name' => 'Рассылка: импорт №'.(int)$import->id,
            'submitted_by_id' => $import->created_by ?: null,
            'source_data' => [
                'import_id' => (int)$import->id,
                'file' => (string)$import->file_name,
                'row' => (int)$row->row_num,
                'labels' => array_keys($data['values']),
                'values' => array_values($data['values']),
            ],
        ], false);
        if ($import->created_by && ($blameable = $lead->getBehavior(BlameableBehavior::class))) {
            // In a job there is no web user: the lead is the importing employee's.
            $blameable->value = (int)$import->created_by;
        }
        if (!$lead->save()) {
            throw new \RuntimeException('Не удалось создать сделку из строки '.(int)$row->row_num.': '.implode('; ', $lead->getFirstErrors()));
        }
        if ($import->executor_id) {
            $lead->updateAttributes(['executor_id' => (int)$import->executor_id, 'status' => CmsLead::STATUS_IN_WORK]);
        }
        $person = $data['name'] !== '' ? $data['name'] : null;
        (new CmsLeadService())->syncContacts($lead,
            array_map(static fn($p) => ['value' => SendContacts::formatPhone($p), 'name' => $person], $data['phones']),
            array_map(static fn($e) => ['value' => $e, 'name' => $person], $data['emails']));

        return $lead;
    }

    /** The topic and basis of the file on every contact of the row. */
    private static function applyConsent(CmsCommImport $import, array $data): void
    {
        if (!$import->cms_comm_topic_id || !$import->consent_basis) {
            return;
        }
        $siteId = (int)$import->cms_site_id;
        $note = 'Импорт №'.(int)$import->id.($import->file_name ? ' «'.$import->file_name.'»' : '');
        foreach ([CmsCommContact::KIND_EMAIL => $data['emails'], CmsCommContact::KIND_PHONE => $data['phones']] as $kind => $values) {
            foreach ($values as $value) {
                MailingConsents::subscribe(MailingContacts::get($siteId, $kind, (string)$value), (int)$import->cms_comm_topic_id,
                    (string)$import->consent_basis, CmsCommContactTopic::SOURCE_IMPORT, $import->created_by ?: null, $note);
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

        $text = 'Создано сделок: '.(int)$import->created_count.', уже были в CRM: '.(int)$import->duplicate_count.', пропущено: '.(int)$import->skipped_count.'.';
        // The notification center opens the import card (CmsCommImport in Skeeks::$modelsConfig, see the bootstrap).
        $notify = static function (int $userId, string $name) use ($text, $import) {
            \Yii::$app->notifications->notify($userId, 'import.done', ['name' => $name, 'comment' => $text,
                'model_code' => CmsCommImport::class, 'model_id' => (int)$import->id]);
        };
        $current = \Yii::$app->has('user') && !\Yii::$app->user->isGuest ? (int)\Yii::$app->user->id : 0;
        if ($status === CmsCommImport::STATUS_DONE && $import->executor_id && (int)$import->created_count > 0 && (int)$import->executor_id !== $current) {
            $notify((int)$import->executor_id, 'Вам назначены сделки из импорта №'.(int)$import->id);
        }
        // A run in the background: the employee who started it learns that it is over.
        if ($import->cms_job_run_id && $import->created_by && (int)$import->created_by !== $current
            && (int)$import->created_by !== (int)$import->executor_id) {
            $notify((int)$import->created_by, ($status === CmsCommImport::STATUS_DONE ? 'Готов импорт №' : 'Ошибка импорта №').(int)$import->id);
        }
    }
}
