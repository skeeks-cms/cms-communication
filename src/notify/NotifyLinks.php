<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\chat\ChatIdentities;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommLinkToken;
use skeeks\cms\communication\models\CmsCommNotifyChannel;
use skeeks\cms\models\CmsUser;

/**
 * An employee connects a messenger for notifications: "Подключить" gives a
 * one-time link to the private chat with the bot (the start key of the client
 * invitation, step 5.4, with its own purpose); "Старт" in the messenger makes
 * the person this employee and their chat the channel.
 */
class NotifyLinks
{
    public const PURPOSE = 'notify_worker';

    /** A link lives briefly: it is opened right away from the settings page. */
    public const TTL = 1800;

    public static function channelRow(int $userId, string $channel): ?CmsCommNotifyChannel
    {
        $row = CmsCommNotifyChannel::find()->andWhere(['cms_user_id' => $userId, 'channel' => $channel])->one();

        return $row ?: self::adopt($userId, $channel);
    }

    /**
     * A person already marked as this employee ("Это наш сотрудник") who has a
     * private chat with an active bot of the channel: that chat is the channel.
     */
    public static function adopt(int $userId, string $channel): ?CmsCommNotifyChannel
    {
        if (!CmsUser::find()->andWhere(['id' => $userId, 'is_worker' => 1, 'is_active' => 1])->exists()) {
            return null;
        }
        $identities = CmsCommIdentity::find()->andWhere(['cms_user_id' => $userId, 'channel' => $channel, 'is_bot' => 0])->all();
        foreach ($identities as $identity) {
            foreach (ChatIdentities::privateChats($identity) as $conversation) {
                if ($conversation->bot_status === CmsCommConversation::BOT_LEFT || !$conversation->connection || !(int)$conversation->connection->is_active) {
                    continue;
                }

                return self::save($userId, $channel, $conversation, $identity);
            }
        }

        return null;
    }

    /** @return array{token: CmsCommLinkToken, url: string|null} */
    public static function connect(int $userId, CmsCommConnection $connection): array
    {
        if (!Bots::isBot($connection) || !(int)$connection->is_active) {
            throw new \DomainException('Бот недоступен.');
        }
        // Earlier unused links of the person to this bot stop working.
        CmsCommLinkToken::updateAll(['expires_at' => time()], [
            'purpose' => self::PURPOSE, 'model_code' => CmsUser::class, 'model_id' => $userId,
            'cms_comm_connection_id' => (int)$connection->id, 'used_at' => null,
        ]);
        $token = new CmsCommLinkToken([
            'cms_site_id' => (int)$connection->cms_site_id,
            'cms_comm_connection_id' => (int)$connection->id,
            'token' => CmsCommLinkToken::generate(),
            'purpose' => self::PURPOSE,
            'model_code' => CmsUser::class,
            'model_id' => $userId,
            'expires_at' => time() + self::TTL,
            'created_by' => $userId,
        ]);
        $token->save(false);

        return ['token' => $token, 'url' => Bots::driver($connection)->startLink((string)$token->token)];
    }

    /** The unused link of the person to a bot of the channel: ['url', 'expires_at'] or null. */
    public static function pending(int $userId, string $channel): ?array
    {
        $tokens = CmsCommLinkToken::find()->andWhere([
            'purpose' => self::PURPOSE, 'model_code' => CmsUser::class, 'model_id' => $userId, 'used_at' => null,
        ])->andWhere(['>', 'expires_at', time()])->orderBy(['id' => SORT_DESC])->all();
        foreach ($tokens as $token) {
            $connection = $token->cms_comm_connection_id ? CmsCommConnection::findOne((int)$token->cms_comm_connection_id) : null;
            if (!$connection || !Bots::isBot($connection)) {
                continue;
            }
            $driver = Bots::driver($connection);
            if ($driver::channel() === $channel) {
                return ['url' => $driver->startLink((string)$token->token), 'expires_at' => (int)$token->expires_at];
            }
        }

        return null;
    }

    /**
     * "Старт" with a key of this purpose: the person becomes the employee and
     * the chat their channel. Null — not such a key (other handlers go on).
     */
    public static function accept(CmsCommIdentity $identity, CmsCommConnection $connection, CmsCommConversation $chat, string $key): ?CmsCommNotifyChannel
    {
        $token = CmsCommLinkToken::find()->andWhere(['token' => $key, 'purpose' => self::PURPOSE])->one();
        if (!$token || !$token->isUsable() || (int)$token->cms_comm_connection_id !== (int)$connection->id
            || (int)$token->cms_site_id !== (int)$identity->cms_site_id || $token->model_code !== CmsUser::class) {
            return null;
        }
        $userId = (int)$token->model_id;
        // A person linked to a client of the CRM is not taken over by an employee's link.
        if ($identity->cms_user_id && (int)$identity->cms_user_id !== $userId && !$identity->isWorker()) {
            throw new \DomainException('Этот чат привязан к клиенту CRM, поэтому уведомления сотрудника сюда не подключить. Откройте ссылку из своего аккаунта мессенджера.');
        }
        $token->used_at = time();
        $token->used_identity_id = (int)$identity->id;
        $token->save(false, ['used_at', 'used_identity_id']);
        ChatIdentities::markWorker($identity, $userId, $userId);
        if ($identity->is_blocked) {
            $identity->is_blocked = 0;
            $identity->save(false, ['is_blocked', 'updated_at']);
        }

        return self::save($userId, Bots::driver($connection)::channel(), $chat, $identity);
    }

    public static function setActive(int $userId, string $channel, bool $active): bool
    {
        $row = CmsCommNotifyChannel::find()->andWhere(['cms_user_id' => $userId, 'channel' => $channel])->one();
        if (!$row) {
            return false;
        }
        $row->is_active = (int)$active;

        return $row->save(false);
    }

    public static function markResult(CmsCommNotifyChannel $row, bool $ok, ?string $error = null): void
    {
        $row->updateAttributes($ok
            ? ['last_ok_at' => time(), 'updated_at' => time()]
            : ['last_error_at' => time(), 'last_error' => mb_substr((string)$error, 0, 1000), 'updated_at' => time()]);
    }

    /** Ids of the private chats used for notifications of other employees (hidden in "Чаты"). */
    public static function chatsOfOthers(int $userId)
    {
        return CmsCommNotifyChannel::find()->select('cms_comm_conversation_id')
            ->andWhere(['not', ['cms_comm_conversation_id' => null]])
            ->andWhere(['<>', 'cms_user_id', $userId]);
    }

    /** Ids of the employee's own notification chats (always visible to them). */
    public static function ownChats(int $userId)
    {
        return CmsCommNotifyChannel::find()->select('cms_comm_conversation_id')
            ->andWhere(['not', ['cms_comm_conversation_id' => null]])
            ->andWhere(['cms_user_id' => $userId]);
    }

    private static function save(int $userId, string $channel, CmsCommConversation $chat, CmsCommIdentity $identity): CmsCommNotifyChannel
    {
        // A chat and a person serve one employee: another employee's channel there stops.
        CmsCommNotifyChannel::deleteAll(['and',
            ['or', ['cms_comm_conversation_id' => (int)$chat->id], ['cms_comm_identity_id' => (int)$identity->id]],
            ['<>', 'cms_user_id', $userId],
        ]);
        $row = CmsCommNotifyChannel::find()->andWhere(['cms_user_id' => $userId, 'channel' => $channel])->one()
            ?: new CmsCommNotifyChannel(['cms_user_id' => $userId, 'channel' => $channel]);
        $row->cms_comm_connection_id = (int)$chat->cms_comm_connection_id;
        $row->cms_comm_conversation_id = (int)$chat->id;
        $row->cms_comm_identity_id = (int)$identity->id;
        $row->is_active = 1;
        $row->last_error = null;
        $row->last_error_at = null;
        $row->save(false);

        return $row;
    }
}
