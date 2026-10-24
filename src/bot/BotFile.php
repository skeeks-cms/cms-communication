<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

/**
 * A file of an incoming chat message. `ref` is what the driver downloads it
 * by (a Telegram file_id, a MAX payload URL or token); it is stored with the
 * message file and never shown to people.
 */
final class BotFile
{
    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_AUDIO = 'audio';
    public const KIND_FILE = 'file';
    public const KIND_STICKER = 'sticker';

    public string $ref;
    public string $name;
    public string $kind = self::KIND_FILE;
    public ?string $mime = null;
    public ?int $size = null;

    public function __construct(string $ref, string $name, string $kind = self::KIND_FILE, ?string $mime = null, ?int $size = null)
    {
        $this->ref = $ref;
        $this->name = $name !== '' ? $name : 'файл';
        $this->kind = $kind;
        $this->mime = $mime;
        $this->size = $size;
    }
}
