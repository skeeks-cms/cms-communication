<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommDraft;
use skeeks\cms\communication\models\CmsCommDraftFile;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\User;
use skeeks\cms\services\TemporaryUploads;

/**
 * Files of a draft added through the API: a file the OAuth user uploaded
 * with cms_storage_file_upload becomes an attachment of the draft (or a
 * picture inside its text), exactly as a file put into the letter editor —
 * a CmsCommDraftFile with its own copy in the storage of mail, so the
 * letter carries it as a MIME part (a picture by cid:) and removing it from
 * the draft never touches the uploaded file.
 *
 * - Only a storage file of this site uploaded by this user; never a path.
 * - The type is the content's (finfo), not the name's: PNG, JPEG, GIF, WEBP
 *   and PDF; anything else (SVG, HTML, archives, programs) is refused. The
 *   name gets the extension of the real type.
 * - At most MAX_FILES files, MAX_FILE_SIZE each, MAX_TOTAL together.
 * - The same content added again returns the file already in the draft.
 * - A picture inside the text takes the place of the first "{Картинка}" of
 *   the text, or goes to its end.
 */
class AiDraftFiles
{
    public const MAX_FILES = 10;
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;
    public const MAX_TOTAL = 20 * 1024 * 1024;
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    public const PLACEHOLDER = '{Картинка}';

    private User $user;
    private int $siteId;

    public function __construct(User $user, int $siteId)
    {
        $this->user = $user;
        $this->siteId = $siteId;
    }

    /**
     * An uploaded file the user may attach, read and checked.
     *
     * @return array bytes, mime, name, size, hash
     * @throws AiRefusal
     */
    public function source(int $storageFileId): array
    {
        $file = CmsStorageFile::findOne(['id' => $storageFileId, 'cms_site_id' => $this->siteId]);
        if (!$file || (int)$file->created_by !== (int)$this->user->id) {
            throw new AiRefusal('attachment', 'Файл '.$storageFileId.' не найден среди ваших загрузок этого сайта: загрузите его через cms_storage_file_upload.');
        }
        $path = (string)$file->getRootSrc();
        if ($path === '' || !is_file($path)) {
            throw new AiRefusal('attachment', 'Файл '.$storageFileId.' не найден на диске: загрузите его заново.');
        }
        $size = (int)filesize($path);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new AiRefusal('attachment', 'Файл '.$storageFileId.' пустой или больше '.(self::MAX_FILE_SIZE / 1048576).' МБ.');
        }
        $bytes = (string)file_get_contents($path);
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!isset(self::TYPES[$mime])) {
            throw new AiRefusal('attachment', 'Файл '.$storageFileId.' — '.($mime ?: 'неизвестный тип').': можно прикладывать только PNG, JPEG, GIF, WEBP и PDF.');
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'size' => $size, 'hash' => hash('sha256', $bytes),
            'name' => self::name((string)($file->original_name ?: $file->name ?: 'file'), self::TYPES[$mime])];
    }

    /** A safe file name: no path or control characters, the extension of the real type. */
    public static function name(string $name, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $base = trim((string)preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', ' ', $base));

        return mb_substr($base !== '' ? $base : 'file', 0, 120).'.'.$extension;
    }

    /**
     * Adds an uploaded file to the draft (the same content again — the file already there).
     *
     * @throws AiRefusal
     */
    public function add(CmsCommDraft $draft, int $storageFileId, bool $inline): CmsCommDraftFile
    {
        $source = $this->source($storageFileId);
        if ($inline && !in_array($source['mime'], MailFiles::INLINE_TYPES, true)) {
            throw new AiRefusal('attachment', 'В текст письма можно вставить только картинку (PNG, JPEG, GIF, WEBP).');
        }
        $files = $draft->getFiles()->all();
        foreach ($files as $existing) {
            if ($existing->content_hash === $source['hash'] && (bool)$existing->is_inline === $inline) {
                return $existing;
            }
        }
        if (count($files) >= self::MAX_FILES) {
            throw new AiRefusal('attachment', 'В письме уже '.count($files).' файлов: больше '.self::MAX_FILES.' нельзя.');
        }
        $total = array_sum(array_map(static fn($f) => (int)$f->size, $files)) + $source['size'];
        if ($total > self::MAX_TOTAL) {
            throw new AiRefusal('attachment', 'Файлы письма вместе больше '.(self::MAX_TOTAL / 1048576).' МБ: почтовые серверы такие письма не принимают.');
        }

        return CmsCommDraftFile::getDb()->transaction(function () use ($draft, $source, $inline, $files) {
            // Mail's own copy: removing it from the draft never touches the uploaded file.
            $stored = MailFiles::storeReceived($source['bytes'], $source['name'], $source['mime'], $this->siteId);
            $token = $inline ? (string)TemporaryUploads::putBytes($source['bytes'], $source['name'], 'mail')['token'] : null;
            $file = new CmsCommDraftFile([
                'cms_comm_draft_id' => (int)$draft->id,
                'storage_file_id' => (int)$stored->id,
                'name' => $source['name'],
                'size' => $source['size'],
                'content_hash' => $source['hash'],
                'is_inline' => (int)$inline,
                'token' => $token,
                'priority' => (count($files) + 1) * 10,
            ]);
            $file->save(false);
            if ($inline) {
                $draft->body_html = self::placeImage((string)$draft->body_html, $token);
            }
            $draft->save(false, ['body_html', 'updated_at']);

            return $file;
        });
    }

    /** Removes a file from the draft (and its picture from the text); the uploaded file stays. */
    public function remove(CmsCommDraft $draft, int $fileId): bool
    {
        $file = $draft->getFiles()->andWhere(['id' => $fileId])->one();
        if (!$file) {
            return false;
        }
        if ($file->is_inline && $file->token) {
            $quoted = preg_quote((string)$file->token, '~');
            $draft->body_html = (string)preg_replace('~<img[^>]*data-sx-upload="'.$quoted.'"[^>]*>~i', '', (string)$draft->body_html);
        }
        MailDrafts::forgetFile($file);
        $draft->save(false, ['body_html', 'updated_at']);

        return true;
    }

    /** The first "{Картинка}" of the text becomes the picture, or the picture goes to the end — above the signature. */
    public static function placeImage(string $html, string $token): string
    {
        $img = '<img data-sx-upload="'.htmlspecialchars($token, ENT_QUOTES, 'UTF-8').'" alt="">';
        $position = mb_strpos($html, self::PLACEHOLDER);
        if ($position !== false) {
            return mb_substr($html, 0, $position).$img.mb_substr($html, $position + mb_strlen(self::PLACEHOLDER));
        }
        // The signature block of MailSignatures::block() ("-- " line), as saved.
        if (preg_match('~<div>\s*<div>--(?:&nbsp;|\x{00A0}|\s)*</div>~u', $html, $m, PREG_OFFSET_CAPTURE)) {
            return substr($html, 0, $m[0][1]).'<p>'.$img.'</p>'.substr($html, $m[0][1]);
        }

        return $html.'<p>'.$img.'</p>';
    }

    /**
     * The files of a draft as the API shows them.
     *
     * @return array items [id, name, mime, size, inline], total_size, count, limits
     */
    public static function describe(CmsCommDraft $draft): array
    {
        $items = [];
        foreach ($draft->getFiles()->with('storageFile')->all() as $file) {
            $items[] = [
                'id' => (int)$file->id,
                'name' => (string)$file->name,
                'mime' => $file->storageFile ? (string)$file->storageFile->mime_type : null,
                'size' => (int)$file->size,
                'inline' => (bool)$file->is_inline,
                'sha256' => substr((string)$file->content_hash, 0, 16),
            ];
        }

        return [
            'items' => $items,
            'count' => count($items),
            'total_size' => array_sum(array_column($items, 'size')),
            'limits' => ['max_files' => self::MAX_FILES, 'max_file_size' => self::MAX_FILE_SIZE, 'max_total_size' => self::MAX_TOTAL,
                'types' => array_keys(self::TYPES)],
        ];
    }
}
