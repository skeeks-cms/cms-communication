<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use skeeks\cms\models\CmsStorageFile;

/**
 * A file of a sendable: a file of the CMS storage, or a local file that the
 * channel puts into the storage when it sends (a PDF generated on the fly).
 */
final class SendFile
{
    public string $name;
    public ?CmsStorageFile $storageFile = null;
    public ?string $path = null;
    public ?string $type = null;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function storage(CmsStorageFile $file, ?string $name = null): self
    {
        $result = new self($name ?: ((string)$file->original_name ?: 'файл'));
        $result->storageFile = $file;
        $result->type = $file->mime_type ? (string)$file->mime_type : null;

        return $result;
    }

    public static function path(string $path, string $name, ?string $type = null): self
    {
        $result = new self($name);
        $result->path = $path;
        $result->type = $type;

        return $result;
    }

    public function size(): int
    {
        if ($this->storageFile) {
            return (int)$this->storageFile->size;
        }

        return $this->path && is_file($this->path) ? (int)filesize($this->path) : 0;
    }
}
