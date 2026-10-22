<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommDraftFile;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\services\TemporaryUploads;
use yii\helpers\Url;

/**
 * Files of letters in the CMS storage: received attachments and images,
 * files an employee attaches or pastes into a letter. Editor uploads wait in
 * the CMS temporary upload area and reach the storage only with a sent
 * letter. The storage cluster is public by path, so the section never shows
 * storage URLs: every file goes through the access-checked download action.
 */
class MailFiles
{
    /** The largest file kept or sent with a letter. Bigger received files keep only their name. */
    public const MAX_SIZE = 25 * 1024 * 1024;

    /** Image types shown in the page itself; everything else is downloaded. */
    public const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public const ROUTE_FILE = '/cmsCommunication/admin-cms-comm-message/file';

    /**
     * A received file: the stored copy of the same content on this site when
     * there is one (a signature logo, a file of a repeated mailing), otherwise
     * a new storage file. Shared storage files are never deleted together with
     * one letter: only when no letter file refers to them any more.
     */
    public static function storeReceived(string $bytes, string $name, ?string $type, int $siteId): CmsStorageFile
    {
        return self::findSame(hash('sha256', $bytes), strlen($bytes), $siteId) ?? self::storeBytes($bytes, $name, $type, $siteId);
    }

    /** A stored letter or draft file of this site with this content, still present on disk. */
    public static function findSame(string $hash, int $size, int $siteId): ?CmsStorageFile
    {
        $ids = CmsCommMessageFile::find()->select('storage_file_id')->distinct()
            ->andWhere(['content_hash' => $hash, 'size' => $size])->andWhere(['not', ['storage_file_id' => null]])->limit(5)->column();
        $ids = array_merge($ids, CmsCommDraftFile::find()->select('storage_file_id')->distinct()
            ->andWhere(['content_hash' => $hash, 'size' => $size])->andWhere(['not', ['storage_file_id' => null]])->limit(5)->column());
        foreach (CmsStorageFile::find()->andWhere(['id' => $ids ?: [0], 'cms_site_id' => $siteId])->all() as $file) {
            if ((int)$file->size === $size && is_file((string)$file->rootSrc)) {
                return $file;
            }
        }

        return null;
    }

    /** Stores bytes of a received file as a new storage file. */
    public static function storeBytes(string $bytes, string $name, ?string $type, int $siteId): CmsStorageFile
    {
        $dir = \Yii::getAlias('@runtime/cms-communication');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $path = $dir.'/'.bin2hex(random_bytes(12)).(preg_match('/^[a-z0-9]{1,10}$/', $extension) ? '.'.$extension : '');
        file_put_contents($path, $bytes);
        try {
            // upload() moves the file into the storage.
            return self::checked(\Yii::$app->storage->upload($path, self::data($name, $type, $siteId)));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * A file attached or pasted into the letter editor. It goes to the CMS
     * temporary upload area (skeeks\cms\services\TemporaryUploads), not to the
     * storage: a letter that is never sent leaves nothing behind, the daily
     * "cms.cleanup-temporary-files" job removes it. Only this session may use
     * the returned token.
     *
     * @return array token, name, size
     */
    public static function storeTemp(\yii\web\UploadedFile $file): array
    {
        return TemporaryUploads::put($file, 'mail');
    }

    /** Path of an editor upload of this session, or null. */
    public static function tempPath(string $token): ?string
    {
        return TemporaryUploads::path($token);
    }

    /** Moves an editor upload into the storage when its letter is queued. */
    public static function storeFromTemp(string $token, int $siteId): CmsStorageFile
    {
        $path = self::tempPath($token);
        if (!$path) {
            throw new \RuntimeException('Файл устарел: загрузите его заново.');
        }
        // The same content stored already (a logo or a price list sent again) is reused.
        $file = self::findSame(hash_file('sha256', $path), (int)filesize($path), $siteId);
        if ($file) {
            TemporaryUploads::forget($token);

            return $file;
        }

        return self::checked(TemporaryUploads::store($token, self::data(TemporaryUploads::name($token), null, $siteId)));
    }

    /** Name of a temporary upload as typed: no path parts or control characters. */
    public static function tempName(string $token): string
    {
        return TemporaryUploads::name($token);
    }

    /**
     * A file row of a letter. $file null: the file stays only in the mailbox
     * (storage settings) and is fetched from it by part_index when opened.
     */
    public static function attach(CmsCommMessage $message, ?CmsStorageFile $file, string $name, ?string $type,
        ?int $size, ?string $contentId = null, bool $inline = false, int $priority = 100,
        ?int $partIndex = null, ?string $hash = null): CmsCommMessageFile
    {
        $storedType = $file && $file->mime_type ? (string)$file->mime_type : null;
        $row = new CmsCommMessageFile([
            'cms_comm_message_id' => (int)$message->id,
            'storage_file_id' => $file ? (int)$file->id : null,
            'name' => mb_substr($name !== '' ? $name : 'файл', 0, 255),
            'mime_type' => $storedType ? mb_substr(strtolower($storedType), 0, 255) : ($type ? mb_substr(strtolower($type), 0, 255) : null),
            'size' => $size ?? ($file ? ((int)$file->size ?: null) : null),
            'content_id' => $contentId !== null && $contentId !== '' ? mb_substr(trim($contentId, '<> '), 0, 255) : null,
            'is_inline' => (int)$inline,
            'priority' => $priority,
        ]);
        if ($row->hasAttribute('part_index')) {
            $row->part_index = $partIndex;
        }
        if ($row->hasAttribute('content_hash')) {
            // The fingerprint lets later letters reuse this stored file.
            $row->content_hash = $hash ?: ($file ? (CmsCommMessageFile::find()->select('content_hash')
                ->andWhere(['storage_file_id' => (int)$file->id])->andWhere(['not', ['content_hash' => null]])->scalar()
                ?: (is_file((string)$file->rootSrc) ? hash_file('sha256', (string)$file->rootSrc) : null)) : null);
        }
        $row->save(false);

        return $row;
    }

    /** Puts a copy of a letter file into the CRM (fetched from the mailbox or kept again). */
    public static function keep(CmsCommMessageFile $row, CmsStorageFile $file, bool $cached): void
    {
        $row->storage_file_id = (int)$file->id;
        $row->is_cached = (int)$cached;
        $row->last_access_at = time();
        $row->fetch_error = null;
        $row->save(false, ['storage_file_id', 'is_cached', 'last_access_at', 'fetch_error']);
    }

    /**
     * Removes the CRM copy of a letter file. The storage file itself is
     * deleted only when no letter file refers to it any more (files are shared
     * between letters with the same content).
     *
     * @return int bytes freed in the storage
     */
    public static function forget(CmsCommMessageFile $row): int
    {
        $storageId = (int)$row->storage_file_id;
        if (!$storageId) {
            return 0;
        }
        $row->storage_file_id = null;
        $row->is_cached = 0;
        $row->save(false, ['storage_file_id', 'is_cached']);

        return self::deleteUnused($storageId);
    }

    /** A storage file of mail is shared: it is used while any letter or draft file refers to it. */
    public static function isUsed(int $storageId): bool
    {
        return CmsCommMessageFile::find()->andWhere(['storage_file_id' => $storageId])->exists()
            || CmsCommDraftFile::find()->andWhere(['storage_file_id' => $storageId])->exists();
    }

    /**
     * Deletes a storage file of mail once no letter or draft refers to it.
     *
     * @return int bytes freed in the storage
     */
    public static function deleteUnused(int $storageId): int
    {
        if (!$storageId || self::isUsed($storageId)) {
            return 0;
        }
        $file = CmsStorageFile::findOne($storageId);
        if (!$file) {
            return 0;
        }
        $size = (int)$file->size;

        return $file->delete() ? $size : 0;
    }

    /** Download (or show) URL of a letter file. */
    public static function url(CmsCommMessageFile $file): string
    {
        return Url::to([self::ROUTE_FILE, 'id' => $file->id]);
    }

    /** URL of a file the employee has just uploaded into the editor (preview). */
    public static function uploadUrl(string $token): string
    {
        return Url::to([self::ROUTE_FILE, 't' => $token]);
    }

    /** @return array content id (lower case) => URL, for images inside the letter text */
    public static function cidMap(CmsCommMessage $message): array
    {
        $map = [];
        foreach ($message->files as $file) {
            if ($file->content_id && $file->storage_file_id) {
                $map[mb_strtolower($file->content_id)] = self::url($file);
            }
        }

        return $map;
    }

    public static function isInlineType(?string $type): bool
    {
        return in_array(strtolower((string)$type), self::INLINE_TYPES, true);
    }

    /** The storage detects the type itself: a declared type is never trusted for showing a file. */
    private static function data(string $name, ?string $type, int $siteId): array
    {
        return ['original_name' => mb_substr($name, 0, 255), 'cms_site_id' => $siteId];
    }

    private static function checked($file): CmsStorageFile
    {
        if (!$file || !$file->id) {
            throw new \RuntimeException('Файл не сохранён в хранилище.');
        }

        return $file instanceof CmsStorageFile ? $file : CmsStorageFile::findOne((int)$file->id);
    }
}
