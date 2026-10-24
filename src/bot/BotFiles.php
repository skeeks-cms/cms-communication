<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\services\MailFiles;

/**
 * Files of incoming chat messages go into the CMS storage right after the
 * message (the job comm.bot.fetch-file): messenger links do not live long
 * (MAX does not say how long, Telegram gives bots files up to 20 MB). The
 * same content is stored once per site, as with letters. A file the
 * messenger refuses keeps the reason in `fetch_error`.
 */
class BotFiles
{
    /** @return array{stored: int, failed: int} */
    public static function fetch(int $messageId, ?MessengerDriver $driver = null): array
    {
        $result = ['stored' => 0, 'failed' => 0];
        $message = CmsCommMessage::findOne($messageId);
        $connection = $message && $message->conversation ? $message->conversation->connection : null;
        if (!$connection) {
            return $result;
        }
        /** @var CmsCommMessageFile[] $rows */
        $rows = CmsCommMessageFile::find()->andWhere(['cms_comm_message_id' => $messageId, 'storage_file_id' => null, 'fetch_error' => null])
            ->andWhere(['not', ['external_ref' => null]])->orderBy(['part_index' => SORT_ASC])->all();
        if (!$rows) {
            return $result;
        }
        $driver = $driver ?? Bots::driver($connection);
        foreach ($rows as $row) {
            if ($row->size && (int)$row->size > MailFiles::MAX_SIZE) {
                self::fail($row, 'Файл больше 25 МБ: открыть его можно только в мессенджере.');
                $result['failed']++;
                continue;
            }
            try {
                $file = $driver->downloadFile((string)$row->external_ref);
            } catch (BotException $e) {
                self::fail($row, $e->getMessage());
                $result['failed']++;
                continue;
            }
            $path = (string)$file['path'];
            try {
                $size = (int)filesize($path);
                if ($size > MailFiles::MAX_SIZE) {
                    self::fail($row, 'Файл больше 25 МБ: открыть его можно только в мессенджере.');
                    $result['failed']++;
                    continue;
                }
                $bytes = (string)file_get_contents($path);
                $name = (string)$row->name;
                if (!empty($file['name']) && in_array($name, ['', 'файл'], true)) {
                    $name = (string)$file['name'];
                }
                $type = $row->mime_type ?: ($file['type'] ?? null);
                $stored = MailFiles::storeReceived($bytes, $name, $type, (int)$message->cms_site_id);
                $row->name = mb_substr($name, 0, 255);
                $row->size = $size;
                $row->content_hash = hash('sha256', $bytes);
                if (!$row->mime_type && $stored->mime_type) {
                    $row->mime_type = mb_substr(strtolower((string)$stored->mime_type), 0, 255);
                }
                // A photo named by the driver gets the extension of its real format (MAX gives WebP as "photo").
                $real = ['image/webp' => 'webp', 'image/png' => 'png', 'image/gif' => 'gif', 'image/jpeg' => 'jpg'][strtolower((string)$row->mime_type)] ?? null;
                if ($real && preg_match('/^photo-[\w-]+\.(jpe?g|png|webp|gif)$/i', (string)$row->name) && strtolower((string)pathinfo((string)$row->name, PATHINFO_EXTENSION)) !== $real) {
                    $row->name = preg_replace('/\.[a-z]+$/i', '.'.$real, (string)$row->name);
                }
                $row->save(false, ['name', 'size', 'content_hash', 'mime_type']);
                MailFiles::keep($row, $stored, false);
                $result['stored']++;
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        return $result;
    }

    private static function fail(CmsCommMessageFile $row, string $error): void
    {
        $row->fetch_error = mb_substr($error, 0, 1000);
        $row->save(false, ['fetch_error']);
    }
}
