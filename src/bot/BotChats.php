<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommParticipant;
use skeeks\cms\communication\services\MailFiles;
use skeeks\cms\models\CmsStorageFile;

/**
 * What the messenger tells about a chat, kept in the CRM: the title of a
 * group, the picture of the chat (a group's icon, the photo of the person of
 * a private dialog) and the photos of the people (members of a group with
 * their roles; the person of a dialog). Pictures go to the CMS storage; the
 * same picture is not downloaded twice. Asked for a new chat (the job
 * comm.bot.chat-info) and again once a week (comm.bot.maintain).
 */
class BotChats
{
    /** Chats are asked again after this many days. */
    public const REFRESH_DAYS = 7;
    /** A photo larger than this is not kept. */
    public const MAX_PHOTO = 5 * 1024 * 1024;

    /** @return bool some picture changed */
    public static function refresh(CmsCommConversation $conversation, ?MessengerDriver $driver = null): bool
    {
        $connection = $conversation->connection;
        if (!$connection || !$conversation->isChat()) {
            return false;
        }
        $driver = $driver ?? Bots::driver($connection);
        $siteId = (int)$conversation->cms_site_id;
        $info = $driver->chatInfo((string)$conversation->external_chat_id);
        $conversation->info_checked_at = time();
        $changed = false;
        if ($info) {
            $title = trim((string)($info['title'] ?? ''));
            if ($title !== '' && $conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE && $title !== (string)$conversation->title) {
                $conversation->title = mb_substr($title, 0, 255);
                $conversation->subject = $conversation->title;
            }
            $photo = trim((string)($info['photo'] ?? ''));
            if ($photo !== '' && $photo !== (string)$conversation->photo_ref && ($stored = self::store($driver, $photo, $siteId, 'chat-'.(int)$conversation->id))) {
                $conversation->photo_storage_file_id = (int)$stored->id;
                $conversation->photo_ref = mb_substr($photo, 0, 1000);
                $changed = true;
            }
            // The person of a private dialog has the same photo everywhere.
            if ($photo !== '' && !empty($info['userId'])) {
                $identity = CmsCommIdentity::find()->andWhere(['cms_site_id' => $siteId, 'channel' => $conversation->channel, 'external_id' => (string)$info['userId']])->one();
                if ($identity) {
                    $changed = self::identityPhoto($identity, $photo, $driver) || $changed;
                }
            }
        }
        $conversation->save(false);

        if ($conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE) {
            $changed = self::members($conversation, $driver) || $changed;
        }

        return $changed;
    }

    /**
     * Members of a group: identities with names and photos, participants with
     * their roles.
     *
     * @return bool some photo changed
     */
    public static function members(CmsCommConversation $conversation, MessengerDriver $driver): bool
    {
        $changed = false;
        $ingest = new BotIngest($conversation->connection, $driver);
        foreach ($driver->members((string)$conversation->external_chat_id) as $member) {
            /** @var BotUser $user */
            $user = $member['user'];
            if ((string)$driver->botId !== '' && $user->id === (string)$driver->botId) {
                continue;
            }
            $identity = $ingest->identity($user);
            $participant = CmsCommParticipant::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'cms_comm_identity_id' => (int)$identity->id])->one()
                ?: new CmsCommParticipant(['cms_comm_conversation_id' => (int)$conversation->id, 'cms_comm_identity_id' => (int)$identity->id, 'joined_at' => time()]);
            $participant->role = $user->role ?: CmsCommParticipant::ROLE_MEMBER;
            $participant->left_at = null;
            $participant->save(false);
            if (!empty($member['photo'])) {
                $changed = self::identityPhoto($identity, (string)$member['photo'], $driver) || $changed;
            }
        }

        return $changed;
    }

    /** The photo of a person, when it is new. */
    public static function identityPhoto(CmsCommIdentity $identity, string $photo, MessengerDriver $driver): bool
    {
        if (!$identity->hasAttribute('photo_ref') || $photo === (string)$identity->photo_ref) {
            return false;
        }
        $stored = self::store($driver, $photo, (int)$identity->cms_site_id, 'person-'.(int)$identity->id);
        if (!$stored) {
            return false;
        }
        $identity->photo_storage_file_id = (int)$stored->id;
        $identity->photo_ref = mb_substr($photo, 0, 1000);
        $identity->save(false, ['photo_storage_file_id', 'photo_ref', 'updated_at']);

        return true;
    }

    /** The picture of a chat in the storage, or null. */
    public static function photo(CmsCommConversation $conversation): ?CmsStorageFile
    {
        return $conversation->hasAttribute('photo_storage_file_id') ? self::file($conversation->photo_storage_file_id) : null;
    }

    /** The photo of a person in the storage, or null. */
    public static function identityPhotoFile(CmsCommIdentity $identity): ?CmsStorageFile
    {
        return $identity->hasAttribute('photo_storage_file_id') ? self::file($identity->photo_storage_file_id) : null;
    }

    /** Chats of a bot to ask again: never asked or asked long ago. */
    public static function stale(int $connectionId, int $limit = 50): array
    {
        return CmsCommConversation::find()->andWhere(['cms_comm_connection_id' => $connectionId])
            ->andWhere(['or', ['info_checked_at' => null], ['<', 'info_checked_at', time() - self::REFRESH_DAYS * 86400]])
            ->orderBy(['last_message_at' => SORT_DESC])->limit($limit)->all();
    }

    private static function file($id): ?CmsStorageFile
    {
        $file = $id ? CmsStorageFile::findOne((int)$id) : null;

        return $file && is_file((string)$file->rootSrc) ? $file : null;
    }

    /** Downloads a picture into the storage; a refused or too large one gives null. */
    private static function store(MessengerDriver $driver, string $ref, int $siteId, string $name): ?CmsStorageFile
    {
        try {
            $file = $driver->downloadFile($ref);
        } catch (BotException $e) {
            \Yii::warning('Фото не получено: '.$e->getMessage(), self::class);
            return null;
        }
        try {
            $bytes = (string)file_get_contents((string)$file['path']);
            if ($bytes === '' || strlen($bytes) > self::MAX_PHOTO) {
                return null;
            }

            return MailFiles::storeReceived($bytes, $name.'.jpg', $file['type'] ?? null, $siteId);
        } finally {
            if (is_file((string)$file['path'])) {
                unlink((string)$file['path']);
            }
        }
    }
}
