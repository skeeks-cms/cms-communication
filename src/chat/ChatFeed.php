<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\chat;

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MessageLinker;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveRecord;

/**
 * Chats in the activity feeds of linked records (a lead, company, client,
 * deal):
 * - a private dialog — every message, as letters (cms_comm_link + a cms_log
 *   entry of type `message`, MessageLinker);
 * - a group or a channel — one summary per chat and day (cms_log of type
 *   `chat_digest`): how many messages, who wrote, the first lines; updated
 *   by every new message of that day. Noisy groups do not flood the feed.
 * The day is the CRM's (the formatter time zone).
 */
class ChatFeed
{
    public const LOG_DIGEST = 'chat_digest';
    /** Lines of a day shown in the summary. */
    public const DIGEST_LINES = 3;

    /** A new message of a chat (received, sent from the CRM, imported). */
    public static function onMessage(CmsCommMessage $message, CmsCommConversation $conversation): void
    {
        foreach (self::links($conversation) as $link) {
            if ($conversation->chat_type === CmsCommConversation::CHAT_PRIVATE) {
                $record = self::record($link->model_code, (int)$link->model_id);
                if ($record) {
                    (new MessageLinker())->link($message, [$record], CmsCommLink::TYPE_THREAD);
                }
            } else {
                self::digest($conversation, $link->model_code, (int)$link->model_id, (int)$message->message_at ?: time());
            }
        }
        // A dialog of a client not linked to a deal: the client's one open deal gets the message (core CrmInbound),
        // with several the executors are asked once per dialog.
        if ($conversation->chat_type === CmsCommConversation::CHAT_PRIVATE) {
            \skeeks\cms\communication\services\InboundLeads::route($message, 'написал в чат', 'chat:'.(int)$conversation->id);
        }
    }

    /** A chat linked to a record: its messages (a dialog) or its days (a group) go to the record's feed. */
    public static function linkConversation(CmsCommConversation $conversation, string $code, int $id, ?int $createdBy): void
    {
        if ($conversation->chat_type === CmsCommConversation::CHAT_PRIVATE) {
            $record = self::record($code, $id);
            if (!$record) {
                return;
            }
            $linker = new MessageLinker();
            foreach (CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->orderBy(['id' => SORT_ASC])->batch(200) as $messages) {
                foreach ($messages as $message) {
                    $linker->link($message, [$record], CmsCommLink::TYPE_MANUAL, $createdBy);
                }
            }
            return;
        }
        $days = [];
        foreach (CmsCommMessage::find()->select('message_at')->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->column() as $at) {
            $days[self::dayStart((int)$at)] = (int)$at;
        }
        foreach ($days as $at) {
            self::digest($conversation, $code, $id, $at);
        }
    }

    /** The chat is no longer linked: its messages and summaries leave the record's feed. */
    public static function unlinkConversation(CmsCommConversation $conversation, string $code, int $id): void
    {
        $linker = new MessageLinker();
        foreach (CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])
            ->andWhere(['id' => CmsCommLink::find()->select('cms_comm_message_id')->andWhere(['model_code' => $code, 'model_id' => $id])])->all() as $message) {
            $linker->unlink($message, $code, $id);
        }
        foreach (self::digestLogs($conversation, $code, $id) as $log) {
            $log->delete();
        }
    }

    /**
     * The summary of one chat day in a record's feed: written once, then
     * updated (counts, authors, first lines, the time of the last message).
     */
    public static function digest(CmsCommConversation $conversation, string $code, int $id, int $at): ?CmsLog
    {
        $from = self::dayStart($at);
        $to = $from + 86400;
        $messages = CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])
            ->andWhere(['>=', 'message_at', $from])->andWhere(['<', 'message_at', $to])
            ->with(['identity', 'sentBy'])->orderBy(['message_at' => SORT_ASC, 'id' => SORT_ASC])->all();
        $log = null;
        foreach (self::digestLogs($conversation, $code, $id, $from, $to) as $candidate) {
            $log = $candidate;
        }
        if (!$messages) {
            if ($log) {
                $log->delete();
            }
            return null;
        }
        $authors = [];
        $lines = [];
        $in = 0;
        foreach ($messages as $message) {
            $name = \skeeks\cms\communication\helpers\ChatUi::author($message);
            $authors[$name] = ($authors[$name] ?? 0) + 1;
            $in += (int)($message->direction === CmsCommMessage::DIRECTION_IN);
            if (count($lines) < self::DIGEST_LINES) {
                $lines[] = [$name, $message->getSnippet(140)];
            }
        }
        $last = end($messages);
        if (!$log) {
            $log = new CmsLog();
            $log->model_code = $code;
            $log->model_id = $id;
            $log->log_type = self::LOG_DIGEST;
            if ($code === CmsCompany::class) {
                $log->cms_company_id = $id;
            } elseif ($code === CmsUser::class) {
                $log->cms_user_id = $id;
            }
            $record = self::record($code, $id);
            if ($record && method_exists($record, 'asText')) {
                $log->model_as_text = (string)$record->asText();
            }
        }
        $log->comment = $conversation->asText();
        $log->data = [
            'conversation_id' => (int)$conversation->id,
            'day' => $from,
            'count' => count($messages),
            'in' => $in,
            'authors' => $authors,
            'lines' => $lines,
            'last_at' => (int)$last->message_at,
        ];
        if (!$log->save(false)) {
            throw new \RuntimeException('Не удалось записать сводку чата в ленту.');
        }
        // The feed is ordered by date: the summary stands at the last message of the day.
        $log->updateAttributes(['created_at' => (int)$last->message_at]);

        return $log;
    }

    /** @return CmsLog[] summaries of a chat in a record's feed (of one day when given) */
    public static function digestLogs(CmsCommConversation $conversation, string $code, int $id, ?int $from = null, ?int $to = null): array
    {
        $query = CmsLog::find()->andWhere(['model_code' => $code, 'model_id' => $id, 'log_type' => self::LOG_DIGEST]);
        if ($from !== null) {
            // The summary stands at a time of its day.
            $query->andWhere(['>=', 'created_at', $from])->andWhere(['<', 'created_at', $to]);
        }

        return array_values(array_filter($query->all(), static fn(CmsLog $log) => (int)\yii\helpers\ArrayHelper::getValue($log->data, 'conversation_id') === (int)$conversation->id));
    }

    /** Start of the CRM day of a moment. */
    public static function dayStart(int $time): int
    {
        $zone = new \DateTimeZone(\Yii::$app->formatter->timeZone ?: date_default_timezone_get());
        $date = (new \DateTimeImmutable('@'.$time))->setTimezone($zone)->setTime(0, 0);

        return $date->getTimestamp();
    }

    /** @return CmsCommConversationLink[] */
    public static function links(CmsCommConversation $conversation): array
    {
        return CmsCommConversationLink::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id])->all();
    }

    public static function record(string $code, int $id): ?ActiveRecord
    {
        $type = CrmRecords::typeOfCode($code);
        $class = $type ? CrmRecords::codeOf($type) : null;

        return $class ? $class::findOne($id) : null;
    }
}
