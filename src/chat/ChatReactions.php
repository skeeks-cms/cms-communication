<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\chat;

use skeeks\cms\communication\bot\BotException;
use skeeks\cms\communication\bot\Bots;
use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageReaction;
use skeeks\cms\models\CmsUser;

/**
 * Reactions on chat messages, as in messengers:
 * - a person of the messenger: each reaction event carries the whole set of
 *   their reactions on the message now, so it replaces what was stored;
 * - an employee in the CRM: one emoji (choosing it again removes it); the bot
 *   shows the latest employee reaction in the messenger (a bot may set one
 *   reaction a message), where the messenger allows bots reactions.
 * Reactions change the message's updated_at, so an open chat redraws it.
 * They are not entries of the client history: a thumbs up is no event of
 * the work with the client, the message itself is.
 */
class ChatReactions
{
    /** A reaction event: the person's reactions on the message become exactly these. */
    public static function replace(CmsCommMessage $message, CmsCommIdentity $identity, array $emoji): void
    {
        CmsCommMessageReaction::deleteAll(['cms_comm_message_id' => (int)$message->id, 'cms_comm_identity_id' => (int)$identity->id]);
        foreach (array_unique(array_filter(array_map('strval', $emoji))) as $one) {
            (new CmsCommMessageReaction([
                'cms_comm_message_id' => (int)$message->id,
                'cms_comm_identity_id' => (int)$identity->id,
                'emoji' => mb_substr($one, 0, 32),
            ]))->save(false);
        }
        $message->updateAttributes(['updated_at' => time()]);
    }

    /**
     * The employee's reaction from the CRM: set, or remove when it is the same
     * emoji (or null). The bot puts the latest employee reaction left on the
     * message into the messenger.
     *
     * @throws \DomainException why it cannot be done
     */
    public static function toggle(CmsCommMessage $message, CmsUser $user, ?string $emoji): void
    {
        $conversation = $message->conversation;
        if (!$conversation || !$conversation->isChat() || !$conversation->connection) {
            throw new \DomainException('Это не сообщение чата.');
        }
        if (!(new ChatAccess($user))->canSend($conversation)) {
            throw new \DomainException('Ставить реакции может тот, кому можно писать в этот чат.');
        }
        $choices = $conversation->connection->createDriver(false)->reactionChoices();
        if (!$choices) {
            throw new \DomainException('В '.ChatUi::channelName($conversation).' боты не могут ставить реакции.');
        }
        if ($emoji !== null && !in_array($emoji, $choices, true)) {
            throw new \DomainException('Такую реакцию бот поставить не может.');
        }
        if (!Bots::isChatAllowed((string)$conversation->channel, (string)$conversation->external_chat_id)) {
            throw new \DomainException('Чат не разрешён настройкой allowedChats.');
        }
        $driver = Bots::driver($conversation->connection);
        $external = self::externalMessageId($message);
        if ($external === null) {
            throw new \DomainException('Сообщение ещё не дошло до мессенджера.');
        }
        $mine = CmsCommMessageReaction::find()->andWhere(['cms_comm_message_id' => (int)$message->id, 'cms_user_id' => (int)$user->id])->one();
        $transaction = CmsCommMessageReaction::getDb()->beginTransaction();
        try {
            if ($mine) {
                $mine->delete();
            }
            if ($emoji !== null && (!$mine || $mine->emoji !== $emoji)) {
                (new CmsCommMessageReaction(['cms_comm_message_id' => (int)$message->id, 'cms_user_id' => (int)$user->id, 'emoji' => $emoji]))->save(false);
            }
            $latest = CmsCommMessageReaction::find()->andWhere(['cms_comm_message_id' => (int)$message->id])->andWhere(['not', ['cms_user_id' => null]])
                ->orderBy(['id' => SORT_DESC])->one();
            $driver->react((string)$conversation->external_chat_id, $external, $latest ? (string)$latest->emoji : null);
            $message->updateAttributes(['updated_at' => time()]);
            $transaction->commit();
        } catch (BotException $e) {
            $transaction->rollBack();
            throw new \DomainException(ChatUi::channelName($conversation).' не принял реакцию: '.$e->getMessage());
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Reactions of messages grouped for showing: message id => [[emoji, count, names, mine]].
     *
     * @param int[] $messageIds
     */
    public static function summary(array $messageIds, ?int $userId = null): array
    {
        if (!$messageIds) {
            return [];
        }
        $result = [];
        foreach (CmsCommMessageReaction::find()->andWhere(['cms_comm_message_id' => $messageIds])->with(['identity', 'cmsUser'])->orderBy(['id' => SORT_ASC])->all() as $r) {
            $group = &$result[(int)$r->cms_comm_message_id][$r->emoji];
            $group = $group ?? ['emoji' => $r->emoji, 'count' => 0, 'names' => [], 'mine' => false];
            $group['count']++;
            $group['names'][] = $r->cms_user_id ? ($r->cmsUser ? trim((string)$r->cmsUser->shortDisplayName) : 'Сотрудник') : ($r->identity ? $r->identity->asText() : '');
            $group['mine'] = $group['mine'] || ($userId && (int)$r->cms_user_id === $userId);
            unset($group);
        }

        return array_map('array_values', $result);
    }

    /** The message id in the messenger: the last part of `<connection>:<chat>:<message>`. */
    public static function externalMessageId(CmsCommMessage $message): ?string
    {
        $parts = explode(':', (string)$message->external_id);

        return count($parts) >= 3 && strpos((string)$message->external_id, 'out.') !== 0 ? (string)end($parts) : null;
    }
}
