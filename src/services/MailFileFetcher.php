<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\mail\MailDriverInterface;
use skeeks\cms\communication\mail\MailParser;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\models\CmsCommMessageMailbox;

/**
 * Gets files of a letter that are not kept in the CRM from its mailbox:
 * the letter is read again (read-only) by its folder and UID, its files are
 * found by their place in the letter and kept: for good when the mailbox
 * settings keep such a file (a letter received before files were kept, in
 * the full mode), otherwise as a cache copy (is_cached, last_access_at) that
 * the cleanup removes after cacheDays.
 * Runs in the comm.mail.fetch-files job, never in a web request.
 */
class MailFileFetcher
{
    public const KIND_IMAGES = 'images';
    public const KIND_FILE = 'file';
    public const KIND_ALL = 'all';

    /** @var callable|null fn(CmsCommMessageMailbox $copy): ?string raw letter — tests replace the server; a bulk job reuses its sessions */
    public $rawLoader;

    /** @var int[]|null only these file rows (KIND_ALL), e.g. the files the bulk save chose */
    public ?array $onlyIds = null;

    /** Keep fetched files for good whatever the mailbox settings ("Сохранить файлы в CRM"). */
    public bool $keepForGood = false;

    /**
     * @return array stored (int), error (string|null), files (id => stored bool)
     */
    public function fetch(int $messageId, string $kind, ?int $fileId = null): array
    {
        $message = CmsCommMessage::findOne($messageId);
        if (!$message) {
            return ['stored' => 0, 'error' => 'Письмо не найдено.', 'files' => []];
        }
        $wanted = $this->wanted($message, $kind, $fileId);
        if (!$wanted && $message->files) {
            return ['stored' => 0, 'error' => null, 'files' => []];
        }

        [$raw, $error] = $this->loadRaw($message);
        if ($raw === null) {
            $this->fail($wanted, $error);
            return ['stored' => 0, 'error' => $error, 'files' => array_fill_keys(array_map(static fn($f) => (int)$f->id, $wanted), false)];
        }

        $parsed = (new MailParser())->parse($raw)['files'] ?? [];
        // A letter received before files were kept has no file rows: describe them now.
        if (!$message->files && $parsed) {
            $priority = 0;
            foreach ($parsed as $part) {
                MailFiles::attach($message, null, (string)$part['name'], $part['type'] ?? null, (int)$part['size'],
                    $part['content_id'] ?? null, (bool)$part['inline'], $priority += 10, (int)$part['part_index'],
                    $part['content'] !== '' ? hash('sha256', $part['content']) : null);
            }
            unset($message->files);
            $wanted = $this->wanted($message, $kind, $fileId);
        }

        $stored = 0;
        $states = [];
        $siteId = (int)$message->cms_site_id;
        // A file the mailbox settings keep (e.g. "Полный") stays for good; others are a cache copy.
        $policy = MailFilePolicy::forAccounts(\skeeks\cms\communication\models\CmsCommAccount::find()->andWhere(['id' => CmsCommMessageMailbox::find()
            ->select('cms_comm_account_id')->andWhere(['cms_comm_message_id' => (int)$message->id])])->all(), $siteId);
        foreach ($wanted as $row) {
            $part = $this->findPart($row, $parsed);
            if (!$part || (string)$part['content'] === '') {
                $this->fail([$row], 'Файл не найден в письме на почтовом сервере.');
                $states[(int)$row->id] = false;
                continue;
            }
            try {
                $cached = !$this->keepForGood && !$policy->keeps((bool)$row->is_inline, strlen((string)$part['content']));
                MailFiles::keep($row, MailFiles::storeReceived((string)$part['content'], $row->name, $row->mime_type, $siteId), $cached);
                $stored++;
                $states[(int)$row->id] = true;
            } catch (\Throwable $e) {
                $this->fail([$row], 'Не удалось сохранить файл: '.mb_substr($e->getMessage(), 0, 150));
                $states[(int)$row->id] = false;
            }
        }

        return ['stored' => $stored, 'error' => null, 'files' => $states];
    }

    /** @return CmsCommMessageFile[] rows without a CRM copy that this request asks for */
    private function wanted(CmsCommMessage $message, string $kind, ?int $fileId): array
    {
        $onlyIds = $this->onlyIds;

        return array_values(array_filter($message->files, static function (CmsCommMessageFile $file) use ($kind, $fileId, $onlyIds) {
            if ($file->isStored()) {
                return false;
            }
            if ($onlyIds !== null && !in_array((int)$file->id, $onlyIds, true)) {
                return false;
            }
            if ($kind === self::KIND_IMAGES) {
                return (bool)$file->is_inline;
            }
            if ($kind === self::KIND_FILE) {
                return (int)$file->id === (int)$fileId;
            }
            return true;
        }));
    }

    /** The parsed part of a row: by its place, checked by name and size; then by Content-ID; then by name and size. */
    private function findPart(CmsCommMessageFile $row, array $parsed): ?array
    {
        $byIndex = $row->part_index !== null ? ($parsed[(int)$row->part_index] ?? null) : null;
        if ($byIndex && $byIndex['name'] === $row->name && (!$row->size || (int)$byIndex['size'] === (int)$row->size)) {
            return $byIndex;
        }
        foreach ($parsed as $part) {
            if ($row->content_id && ($part['content_id'] ?? '') !== '' && strcasecmp($part['content_id'], $row->content_id) === 0) {
                return $part;
            }
        }
        foreach ($parsed as $part) {
            if ($part['name'] === $row->name && (!$row->size || (int)$part['size'] === (int)$row->size)) {
                return $part;
            }
        }

        return null;
    }

    /** @return array [raw|null, error|null] the letter from the first mailbox copy that still has it */
    private function loadRaw(CmsCommMessage $message): array
    {
        $copies = array_values(array_filter($message->mailboxCopies, static fn(CmsCommMessageMailbox $c) => $c->uid && $c->folder && $c->cms_comm_connection_id));
        if (!$copies) {
            return [null, 'Неизвестно, где письмо лежит на почтовом сервере: файл недоступен.'];
        }
        $error = 'Письмо не найдено в почтовом ящике: его удалили или перенесли.';
        foreach ($copies as $copy) {
            try {
                $raw = $this->rawLoader ? call_user_func($this->rawLoader, $copy) : $this->loadFromServer($copy);
            } catch (\Throwable $e) {
                $error = 'Не удалось подключиться к почтовому ящику: '.mb_substr($e->getMessage(), 0, 150);
                continue;
            }
            if ($raw !== null && $raw !== '') {
                return [$raw, null];
            }
        }

        return [null, $error];
    }

    private function loadFromServer(CmsCommMessageMailbox $copy): ?string
    {
        $connection = CmsCommConnection::findOne((int)$copy->cms_comm_connection_id);
        if (!$connection || !$connection->is_active) {
            throw new \RuntimeException('подключение ящика выключено');
        }
        $driver = $connection->createDriver(true);
        if (!$driver instanceof MailDriverInterface) {
            throw new \RuntimeException('драйвер не поддерживает почту');
        }
        $session = $driver->openSession();
        try {
            $selected = $session->select((string)$copy->folder);
            // A renumbered folder (other UIDVALIDITY): the stored UID points to another letter.
            if ($copy->uidvalidity && !empty($selected['uidvalidity']) && (int)$selected['uidvalidity'] !== (int)$copy->uidvalidity) {
                return null;
            }
            $raws = $session->fetch([(int)$copy->uid]);

            return $raws[(int)$copy->uid] ?? null;
        } finally {
            $session->close();
        }
    }

    /** @param CmsCommMessageFile[] $rows */
    private function fail(array $rows, ?string $error): void
    {
        foreach ($rows as $row) {
            $row->fetch_error = $error ? mb_substr($error, 0, 255) : null;
            $row->save(false, ['fetch_error']);
        }
    }
}
