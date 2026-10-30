<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommLinkExclusion;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveRecord;

/**
 * Links a message to CRM records and puts it into their activity feeds.
 * The message is stored once; every linked record gets a cms_log entry of
 * type `message` that points to it, like calls do. The caller owns the
 * transaction.
 */
class MessageLinker
{
    /** Which link type wins when a letter is linked to the same record again. */
    private const STRENGTH = [
        CmsCommLink::TYPE_THREAD => 1,
        CmsCommLink::TYPE_AUTO => 1,
        CmsCommLink::TYPE_CONTEXT => 2,
        CmsCommLink::TYPE_MANUAL => 3,
        CmsCommLink::TYPE_CREATED => 4,
    ];

    /**
     * @param ActiveRecord[] $records
     */
    public function link(CmsCommMessage $message, array $records, string $type, ?int $createdBy = null): void
    {
        foreach ($records as $record) {
            $code = method_exists($record, 'getSkeeksModelCode') ? $record->getSkeeksModelCode() : get_class($record);
            $this->linkModel($message, (string)$code, (int)$record->primaryKey, $type, $createdBy, $record);
        }
    }

    /** Links an employee chose for a letter: they follow the whole conversation. */
    private const CHOSEN = [CmsCommLink::TYPE_CONTEXT, CmsCommLink::TYPE_MANUAL, CmsCommLink::TYPE_CREATED];

    /**
     * Earlier letters of the conversation pass their links on.
     *
     * Letters (email): links an employee chose (manual, created, written
     * from the card) follow the whole conversation. Links found by address
     * or inherited pass only from earlier letters with a common outside
     * participant: an old letter answered or forwarded to another client
     * (a reused invoice thread) must not drag the previous clients along.
     * An incoming reply from an address nobody had in the conversation still
     * follows the letter it answers (a reply from another mailbox of the
     * same person).
     *
     * Messenger and site conversations belong to one person: everything passes.
     */
    public function inheritFromConversation(CmsCommMessage $message): void
    {
        $earlier = CmsCommMessage::find()
            ->andWhere(['cms_comm_conversation_id' => $message->cms_comm_conversation_id])
            ->andWhere(['!=', 'id', $message->id]);
        $rows = CmsCommLink::find()
            ->select(['cms_comm_message_id', 'model_code', 'model_id', 'link_type'])
            ->andWhere(['cms_comm_message_id' => (clone $earlier)->select('id')])
            ->asArray()
            ->all();
        if (!$rows) {
            return;
        }

        if ($message->channel === \skeeks\cms\communication\models\CmsCommAccount::CHANNEL_EMAIL) {
            $from = [];
            $book = new AddressBook();
            $own = self::outsideAddresses($book, $message);
            $parentId = null;
            $letters = (clone $earlier)->andWhere(['id' => array_unique(array_column($rows, 'cms_comm_message_id'))])->all();
            foreach ($letters as $letter) {
                if (array_intersect($own, self::outsideAddresses($book, $letter))) {
                    $from[(int)$letter->id] = true;
                }
                if ($message->in_reply_to && $letter->external_id === $message->in_reply_to) {
                    $parentId = (int)$letter->id;
                }
            }
            if (!$from && $parentId && $message->direction === CmsCommMessage::DIRECTION_IN) {
                $from[$parentId] = true;
            }
            $rows = array_filter($rows, static fn($row) => in_array($row['link_type'], self::CHOSEN, true)
                || isset($from[(int)$row['cms_comm_message_id']]));
        }

        $done = [];
        foreach ($rows as $row) {
            $key = $row['model_code'].':'.$row['model_id'];
            if (!isset($done[$key])) {
                $done[$key] = true;
                $this->linkModel($message, $row['model_code'], (int)$row['model_id'], CmsCommLink::TYPE_THREAD, null);
            }
        }
    }

    /** Addresses of a letter outside our site: sender and recipients. */
    private static function outsideAddresses(AddressBook $book, CmsCommMessage $message): array
    {
        $addresses = array_merge([(string)$message->from_address], array_keys($message->getTo()), array_keys($message->getCc()), array_keys($message->getBcc()));
        $result = [];
        foreach ($addresses as $address) {
            $address = mb_strtolower(trim((string)$address));
            if ($address !== '' && strpos($address, '@') !== false && !$book->isOurs($address, (int)$message->cms_site_id)) {
                $result[] = $address;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Removes the link of a letter to a record together with its feed entry.
     * The caller owns the transaction.
     */
    public function unlink(CmsCommMessage $message, string $code, int $id): bool
    {
        $link = CmsCommLink::find()->andWhere(['cms_comm_message_id' => $message->id, 'model_code' => $code, 'model_id' => $id])->one();
        if (!$link) {
            return false;
        }
        $logId = $link->cms_log_id;
        if ($link->delete() === false) {
            throw new \RuntimeException('Не удалось отвязать письмо.');
        }
        if ($logId && ($log = CmsLog::findOne((int)$logId)) && $log->delete() === false) {
            throw new \RuntimeException('Не удалось убрать письмо из ленты.');
        }

        return true;
    }

    /** Later letters of the conversation are not linked to the record automatically. */
    public function exclude(int $conversationId, string $code, int $id, ?int $createdBy): void
    {
        if ($this->isExcluded($conversationId, $code, $id)) {
            return;
        }
        $row = new CmsCommLinkExclusion([
            'cms_comm_conversation_id' => $conversationId,
            'model_code' => $code,
            'model_id' => $id,
            'created_by' => $createdBy,
        ]);
        if (!$row->save(false)) {
            throw new \RuntimeException('Не удалось запомнить отвязку.');
        }
    }

    /** A record linked by hand again: automatic linking may bring it back. */
    public function include(int $conversationId, string $code, int $id): void
    {
        CmsCommLinkExclusion::deleteAll(['cms_comm_conversation_id' => $conversationId, 'model_code' => $code, 'model_id' => $id]);
    }

    public function isExcluded(int $conversationId, string $code, int $id): bool
    {
        if (!self::exclusionsReady()) {
            return false;
        }

        return CmsCommLinkExclusion::find()
            ->andWhere(['cms_comm_conversation_id' => $conversationId, 'model_code' => $code, 'model_id' => $id])
            ->exists();
    }

    private static bool $exclusionsReady = false;

    /**
     * Receiving runs in long-lived workers: right after a package update the
     * migration may not be applied yet, and receiving must go on without it.
     */
    private static function exclusionsReady(): bool
    {
        if (!self::$exclusionsReady) {
            self::$exclusionsReady = CmsCommLinkExclusion::getDb()->getTableSchema(CmsCommLinkExclusion::tableName(), true) !== null;
        }

        return self::$exclusionsReady;
    }

    private function linkModel(CmsCommMessage $message, string $code, int $id, string $type, ?int $createdBy, ?ActiveRecord $record = null): void
    {
        $existing = CmsCommLink::find()->andWhere(['cms_comm_message_id' => $message->id, 'model_code' => $code, 'model_id' => $id])->one();
        if ($existing) {
            // A link chosen by an employee is stronger than an automatic one; automatic linking never weakens it.
            if (self::STRENGTH[$type] > (self::STRENGTH[$existing->link_type] ?? 0)) {
                $existing->link_type = $type;
                $existing->created_by = $createdBy ?? $existing->created_by;
                $existing->save(false, ['link_type', 'created_by']);
            }
            return;
        }
        // An employee unlinked the record from this conversation: automatic linking does not bring it back.
        if (self::STRENGTH[$type] < self::STRENGTH[CmsCommLink::TYPE_MANUAL]
            && $this->isExcluded((int)$message->cms_comm_conversation_id, $code, $id)) {
            return;
        }

        $log = new CmsLog();
        $log->model_code = $code;
        $log->model_id = $id;
        $log->log_type = CmsCommMessage::LOG_TYPE;
        $log->comment = (string)$message->subject;
        $log->data = ['id' => (int)$message->id];
        if ($record) {
            $log->model_as_text = method_exists($record, 'asText') ? (string)$record->asText() : null;
        }
        if ($code === CmsCompany::class) {
            $log->cms_company_id = $id;
        } elseif ($code === CmsUser::class) {
            $log->cms_user_id = $id;
        }
        if (!$log->save(false)) {
            throw new \RuntimeException('Не удалось записать письмо в ленту.');
        }
        // The feed is ordered by date: an imported letter takes its own date.
        if ($message->message_at) {
            $log->updateAttributes(['created_at' => (int)$message->message_at]);
        }

        $link = new CmsCommLink([
            'cms_comm_message_id' => $message->id,
            'model_code' => $code,
            'model_id' => $id,
            'link_type' => $type,
            'created_by' => $createdBy,
            'cms_log_id' => $log->id,
        ]);
        if (!$link->save(false)) {
            throw new \RuntimeException('Не удалось связать письмо.');
        }
    }
}
