<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\chat;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommLinkToken;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\CrmDuplicates;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\models\CmsUser;

/**
 * Who a person in a messenger is in the CRM:
 * - by hand — an employee picks a lead, company or client;
 * - by an invitation link (`?start=<key>`) made in a CRM card: the person who
 *   opens it is that record's;
 * - by the person's own phone shared with "Поделиться номером" (the driver
 *   checks it is the sender's own) — one lead, company or client with that
 *   phone.
 * A client (a CMS user) is stored on the identity; a lead or a company links
 * the person's private chats. Either way the private chats get the record
 * (ChatLinks::attach()), so the dialog appears in the record's feed.
 */
class ChatIdentities
{
    /** An invitation link lives this long. */
    public const INVITE_DAYS = 30;

    /**
     * Links a person to a record (lead, company, client) and their private chats to it.
     * $chat — the chat of the event (a chat opened with "Начать" has no messages of the person yet).
     */
    public static function linkTo(CmsCommIdentity $identity, string $type, int $id, string $method, ?int $by, ?CmsCommConversation $chat = null): void
    {
        $class = CrmRecords::codeOf($type);
        $transaction = CmsCommIdentity::getDb()->beginTransaction();
        try {
            if ($type === CrmRecords::CLIENT) {
                $identity->cms_user_id = $id;
            }
            $identity->link_method = $method;
            $identity->linked_at = time();
            $identity->linked_by = $by;
            $identity->save(false, ['cms_user_id', 'link_method', 'linked_at', 'linked_by', 'updated_at']);
            $chats = self::privateChats($identity);
            if ($chat && $chat->chat_type === CmsCommConversation::CHAT_PRIVATE) {
                $chats[] = $chat;
            }
            foreach ($chats as $conversation) {
                ChatLinks::attach($conversation, $class, $id, $by);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * "Это наш сотрудник": the person is an employee (their messages in groups
     * are ours, they never wait for an answer, they may ask for tasks), or,
     * with null, nobody again. Only an active employee may be chosen.
     */
    public static function markWorker(CmsCommIdentity $identity, ?int $userId, ?int $by): void
    {
        if ($userId !== null && !\skeeks\cms\models\CmsUser::find()->isWorker()
                ->andWhere([\skeeks\cms\models\CmsUser::tableName().'.id' => $userId, \skeeks\cms\models\CmsUser::tableName().'.is_active' => 1])->exists()) {
            throw new \DomainException('Выберите действующего сотрудника.');
        }
        if ($userId === null && !$identity->isWorker()) {
            return;
        }
        $identity->cms_user_id = $userId;
        $identity->link_method = $userId ? CmsCommIdentity::LINK_MANUAL : null;
        $identity->linked_at = $userId ? time() : null;
        $identity->linked_by = $userId ? $by : null;
        $identity->save(false, ['cms_user_id', 'link_method', 'linked_at', 'linked_by', 'updated_at']);
    }

    /** "Отвязать": the person is nobody's again; their chats keep links an employee made by hand to the chat. */
    public static function unlink(CmsCommIdentity $identity, ChatLinks $links): void
    {
        if ($identity->cms_user_id) {
            foreach (self::privateChats($identity) as $conversation) {
                $links->unlink($conversation, CmsUser::class, (int)$identity->cms_user_id);
            }
        }
        $identity->cms_user_id = null;
        $identity->link_method = null;
        $identity->linked_at = null;
        $identity->linked_by = null;
        $identity->save(false, ['cms_user_id', 'link_method', 'linked_at', 'linked_by', 'updated_at']);
    }

    /** The person of a private chat: the author of its incoming messages. */
    public static function ofChat(CmsCommConversation $conversation): ?CmsCommIdentity
    {
        if ($conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE) {
            return null;
        }
        $id = CmsCommMessage::find()->select('cms_comm_identity_id')->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'direction' => CmsCommMessage::DIRECTION_IN])
            ->andWhere(['not', ['cms_comm_identity_id' => null]])->orderBy(['id' => SORT_DESC])->limit(1)->scalar();

        return $id ? CmsCommIdentity::findOne((int)$id) : null;
    }

    /** @return CmsCommConversation[] private chats of the person with any bot of the site */
    public static function privateChats(CmsCommIdentity $identity): array
    {
        return CmsCommConversation::find()->andWhere([
            'cms_site_id' => (int)$identity->cms_site_id,
            'channel' => $identity->channel,
            'chat_type' => CmsCommConversation::CHAT_PRIVATE,
            'id' => CmsCommMessage::find()->select('cms_comm_conversation_id')->distinct()->andWhere(['cms_comm_identity_id' => (int)$identity->id]),
        ])->all();
    }

    /**
     * An invitation of a record to a bot: a one-time key and the link that
     * opens the bot with it. The key carries no personal data.
     *
     * @return array{token: CmsCommLinkToken, url: string|null}
     */
    public static function invite(CmsCommConnection $connection, string $type, int $id, ?int $by): array
    {
        if (!in_array($type, [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT], true)) {
            throw new \DomainException('Пригласить можно контакт сделки, компании или клиента.');
        }
        $token = new CmsCommLinkToken([
            'cms_site_id' => (int)$connection->cms_site_id,
            'cms_comm_connection_id' => (int)$connection->id,
            'token' => CmsCommLinkToken::generate(),
            'purpose' => CmsCommLinkToken::PURPOSE_LINK_RECORD,
            'model_code' => CrmRecords::codeOf($type),
            'model_id' => $id,
            'expires_at' => time() + self::INVITE_DAYS * 86400,
            'created_by' => $by,
        ]);
        $token->save(false);

        return ['token' => $token, 'url' => Bots::driver($connection)->startLink((string)$token->token)];
    }

    /**
     * The person opened an invitation: the key is used once, the person and
     * their private chats become the record's.
     *
     * @return string|null the type of the record linked, null — the key is unknown or used
     */
    public static function acceptInvite(CmsCommIdentity $identity, CmsCommConnection $connection, string $key, ?CmsCommConversation $chat = null): ?string
    {
        $token = CmsCommLinkToken::find()->andWhere(['token' => $key, 'purpose' => CmsCommLinkToken::PURPOSE_LINK_RECORD])->one();
        if (!$token || !$token->isUsable() || ($token->cms_comm_connection_id && (int)$token->cms_comm_connection_id !== (int)$connection->id)
            || (int)$token->cms_site_id !== (int)$identity->cms_site_id) {
            return null;
        }
        $type = CrmRecords::typeOfCode((string)$token->model_code);
        $class = $type ? CrmRecords::codeOf($type) : null;
        if (!$class || !$class::findOne((int)$token->model_id)) {
            return null;
        }
        $token->used_at = time();
        $token->used_identity_id = (int)$identity->id;
        $token->save(false, ['used_at', 'used_identity_id']);
        self::linkTo($identity, $type, (int)$token->model_id, CmsCommIdentity::LINK_START, $token->created_by ? (int)$token->created_by : null, $chat);

        return $type;
    }

    /**
     * The person's own phone (verified by the driver): stored on the identity;
     * when exactly one lead, company or client has it, the person becomes theirs.
     *
     * @return array|null ['type' => …, 'id' => …] of the record linked
     */
    public static function acceptPhone(CmsCommIdentity $identity, string $phone, ?CmsCommConversation $chat = null): ?array
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }
        $identity->phone = mb_substr($digits, 0, 32);
        $identity->phone_verified_at = time();
        $identity->save(false, ['phone', 'phone_verified_at', 'updated_at']);
        if ($identity->cms_user_id) {
            return null;
        }
        $found = (new CrmDuplicates((int)$identity->cms_site_id))->findMany(['p' => ['emails' => [], 'phones' => [$digits], 'inn' => '', 'name' => '']]);
        $match = $found['p'] ?? null;
        if (!$match || ($match['by'] ?? '') !== 'phone' || !in_array($match['type'], [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT], true)) {
            return null;
        }
        self::linkTo($identity, $match['type'], (int)$match['id'], CmsCommIdentity::LINK_CONTACT, null, $chat);

        return ['type' => $match['type'], 'id' => (int)$match['id']];
    }
}
