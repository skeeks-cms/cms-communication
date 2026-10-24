<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\models\CmsCommBotUpdate;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommSyncState;
use yii\db\IntegrityException;

/**
 * Durable receipt of bot updates: the webhook and the poll only store raw
 * updates (one row per update key, a repeated delivery is not written) and
 * wake the job comm.bot.apply. A poll cursor is stored in the same
 * transaction as its updates: an update is acknowledged only once it is in
 * the database.
 */
class BotUpdates
{
    /** Folder of the poll cursor in cms_comm_sync_state. */
    public const CURSOR_FOLDER = '#bot-updates';

    /** Raw updates are kept this long for the journal of the bot card. */
    public const KEEP_DAYS = 30;

    /**
     * @param array[] $updates raw updates
     * @return array{stored: int, repeated: int, ignored: int}
     */
    public static function store(CmsCommConnection $connection, MessengerDriver $driver, array $updates, ?string $cursor = null): array
    {
        $result = ['stored' => 0, 'repeated' => 0, 'ignored' => 0];
        $transaction = CmsCommBotUpdate::getDb()->beginTransaction();
        try {
            foreach ($updates as $update) {
                $key = is_array($update) ? $driver->updateKey($update) : null;
                if ($key === null || $key === '') {
                    $result['ignored']++;
                    continue;
                }
                $key = mb_substr($key, 0, 190);
                if (CmsCommBotUpdate::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'update_key' => $key])->exists()) {
                    $result['repeated']++;
                    continue;
                }
                $row = new CmsCommBotUpdate([
                    'cms_comm_connection_id' => (int)$connection->id,
                    'update_key' => $key,
                    'update_type' => mb_substr((string)$driver->updateType($update), 0, 64) ?: null,
                    'payload' => json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    'status' => CmsCommBotUpdate::STATUS_NEW,
                ]);
                try {
                    $row->save(false);
                    $result['stored']++;
                } catch (IntegrityException $e) {
                    // The same update delivered twice at once: the other request stored it.
                    $result['repeated']++;
                }
            }
            if ($cursor !== null) {
                self::saveCursor($connection, $cursor);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        if ($result['stored']) {
            self::wake($connection);
        }

        return $result;
    }

    /** The stored poll cursor of a bot, or null before the first poll. */
    public static function cursor(CmsCommConnection $connection): ?string
    {
        $state = CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'folder' => self::CURSOR_FOLDER])->one();

        return $state && (int)$state->last_uid > 0 ? (string)$state->last_uid : null;
    }

    /** Starts applying stored updates; a failure to queue leaves them for the hourly check. */
    public static function wake(CmsCommConnection $connection): void
    {
        try {
            \Yii::$app->jobs->push('comm.bot.apply', ['connectionId' => (int)$connection->id], ['siteId' => (int)$connection->cms_site_id]);
        } catch (\Throwable $e) {
            \Yii::error('Не удалось поставить обработку событий бота #'.(int)$connection->id.': '.$e->getMessage(), self::class);
        }
    }

    /** Removes old rows of the journal; failed ones too, after the same time. */
    public static function cleanup(int $days = self::KEEP_DAYS): int
    {
        return CmsCommBotUpdate::deleteAll(['and',
            ['<', 'created_at', time() - $days * 86400],
            ['!=', 'status', CmsCommBotUpdate::STATUS_NEW],
        ]);
    }

    private static function saveCursor(CmsCommConnection $connection, string $cursor): void
    {
        $state = CmsCommSyncState::find()->andWhere(['cms_comm_connection_id' => (int)$connection->id, 'folder' => self::CURSOR_FOLDER])->one()
            ?: new CmsCommSyncState(['cms_comm_connection_id' => (int)$connection->id, 'folder' => self::CURSOR_FOLDER, 'folder_role' => 'bot']);
        $state->last_uid = (int)$cursor;
        $state->last_success_at = time();
        $state->last_error = null;
        $state->save(false);
    }
}
