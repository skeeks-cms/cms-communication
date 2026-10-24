<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\mail\MailParser;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\communication\models\CmsCommSyncState;
use yii\helpers\Json;

/**
 * Stores one received letter: the message (once per site), its copies in
 * mailboxes, the conversation, links to CRM records and feed entries.
 * The caller (the receive job) owns the transaction and commits the folder
 * cursor together with the stored letters.
 */
class MailIngestService
{
    private MailParser $parser;
    private AddressBook $addresses;
    private MessageLinker $linker;

    /** Whether the last ingest() created the message (false: the letter was known). */
    public bool $lastCreated = false;

    public function __construct(?MailParser $parser = null, ?AddressBook $addresses = null, ?MessageLinker $linker = null)
    {
        $this->parser = $parser ?? new MailParser();
        $this->addresses = $addresses ?? new AddressBook();
        $this->linker = $linker ?? new MessageLinker();
    }

    /**
     * @param bool $history a letter collected from the mailbox history ("Сбор писем"): a new message
     *                      counts as read; a known one is left as it is
     * @return CmsCommMessage the stored or the already known message
     */
    public function ingest(CmsCommConnection $connection, string $folderRole, string $folder, ?int $uidvalidity, int $uid, string $raw, bool $history = false): CmsCommMessage
    {
        $siteId = (int)$connection->cms_site_id;
        $data = $this->parser->parse($raw);
        $externalId = $data['message_id'] !== '' ? $data['message_id'] : 'sx-noid.'.sha1($raw);

        $accounts = $this->targetAccounts($connection, $folderRole, $data);

        $message = CmsCommMessage::find()->andWhere([
            'cms_site_id' => $siteId,
            'channel' => CmsCommAccount::CHANNEL_EMAIL,
            'external_id' => $externalId,
        ])->one();

        $this->lastCreated = !$message;
        if ($message) {
            // The same letter in another mailbox or folder: only a new copy.
            // A letter sent from the CRM found in "Sent" is confirmed as sent.
            if ($folderRole === CmsCommSyncState::ROLE_SENT
                && in_array($message->status, [CmsCommMessage::STATUS_UNKNOWN, CmsCommMessage::STATUS_SENDING], true)) {
                $message->status = CmsCommMessage::STATUS_SENT;
                $message->error = null;
                $message->save(false, ['status', 'error', 'updated_at']);
            }
        } else {
            $message = $this->createMessage($siteId, $externalId, $data, $folderRole, MailFilePolicy::forAccounts($accounts, $siteId), $history);
        }

        foreach ($accounts as $account) {
            $this->addCopy($message, $account, $connection, $folderRole, $folder, $uidvalidity, $uid);
        }

        return $message;
    }

    private function createMessage(int $siteId, string $externalId, array $data, string $folderRole, ?MailFilePolicy $policy = null, bool $history = false): CmsCommMessage
    {
        $participants = array_unique(array_merge([$data['from_address']], array_keys($data['to']), array_keys($data['cc'])));
        $participants = array_values(array_filter($participants));
        $external = array_values(array_filter($participants, fn($a) => !$this->addresses->isOurs($a, $siteId)));
        $fromOurs = $data['from_address'] !== '' && $this->addresses->isOurs($data['from_address'], $siteId);
        $outgoing = $folderRole === CmsCommSyncState::ROLE_SENT || $fromOurs;

        $conversation = $this->findConversation($siteId, $data) ?? $this->createConversation($siteId, $externalId, $data);

        $message = new CmsCommMessage([
            'cms_site_id' => $siteId,
            'cms_comm_conversation_id' => $conversation->id,
            'channel' => CmsCommAccount::CHANNEL_EMAIL,
            'direction' => $outgoing ? CmsCommMessage::DIRECTION_OUT : CmsCommMessage::DIRECTION_IN,
            'status' => $outgoing ? CmsCommMessage::STATUS_SENT : CmsCommMessage::STATUS_RECEIVED,
            'external_id' => $externalId,
            'in_reply_to' => $data['in_reply_to'] ?: null,
            'references_list' => $data['references'] ? implode(' ', $data['references']) : null,
            'from_address' => mb_substr($data['from_address'], 0, 255) ?: null,
            'from_name' => mb_substr($data['from_name'], 0, 255) ?: null,
            'to_list' => Json::encode($data['to']),
            'cc_list' => $data['cc'] ? Json::encode($data['cc']) : null,
            'subject' => mb_substr($data['subject'], 0, 500),
            'body_text' => $data['text'] !== '' ? $data['text'] : null,
            'body_html' => $data['html'] !== '' ? $data['html'] : null,
            'attachments' => $data['attachments'] ? Json::encode($data['attachments']) : null,
            'is_internal' => (int)!$external,
            'is_auto' => (int)$data['is_auto'],
            'is_history' => (int)$history,
            'message_at' => $data['date'] ?: time(),
        ]);
        if (!$message->save(false)) {
            throw new \RuntimeException('Не удалось сохранить письмо.');
        }
        $this->storeFiles($message, $data['files'] ?? [], $siteId, $policy ?? MailFilePolicy::forAccount(null, $siteId));

        if ((int)$conversation->last_message_at < (int)$message->message_at) {
            $conversation->last_message_at = (int)$message->message_at;
            $conversation->save(false, ['last_message_at', 'updated_at']);
        }

        // Internal correspondence between our addresses stays in the mailbox only.
        if ($external) {
            $this->linker->link($message, $this->addresses->findRecords($external, $siteId), CmsCommLink::TYPE_AUTO);
            $this->linker->inheritFromConversation($message);
        }

        return $message;
    }

    /**
     * Files of a new letter. Every file gets a row (name, size, place in the
     * letter); its bytes go to the CMS storage only when the mailbox file
     * settings keep it (MailFilePolicy), otherwise it is fetched from the
     * mailbox when someone opens it. Content stored already (signature logos,
     * repeated mailings) is reused in any mode: it costs nothing. A letter
     * older than the copy term (keepDays) keeps no new bytes: the daily
     * cleanup would remove them the next day. A file that fails to store
     * never stops receiving the letter.
     */
    private function storeFiles(CmsCommMessage $message, array $files, int $siteId, MailFilePolicy $policy): void
    {
        $expired = $policy->keepDays > 0 && (int)$message->message_at < time() - $policy->keepDays * 86400;
        $priority = 0;
        foreach ($files as $file) {
            $priority += 10;
            $content = (string)($file['content'] ?? '');
            $size = strlen($content);
            $inline = (bool)($file['inline'] ?? false);
            $hash = $content !== '' ? hash('sha256', $content) : null;
            $stored = null;
            try {
                if ($hash) {
                    $stored = MailFiles::findSame($hash, $size, $siteId);
                }
                if (!$stored && !$expired && $content !== '' && $size <= MailFiles::MAX_SIZE && $policy->keeps($inline, $size)) {
                    $stored = MailFiles::storeBytes($content, (string)$file['name'], $file['type'] ?? null, $siteId);
                }
            } catch (\Throwable $e) {
                \Yii::warning('Вложение письма #'.$message->id.' не сохранено: '.$e->getMessage(), self::class);
            }
            MailFiles::attach($message, $stored, (string)$file['name'], $file['type'] ?? null, $size,
                $file['content_id'] ?? null, $inline, $priority, $file['part_index'] ?? null, $hash);
        }
    }

    private function findConversation(int $siteId, array $data): ?CmsCommConversation
    {
        $ids = array_values(array_filter(array_merge([$data['in_reply_to']], array_reverse($data['references']))));
        if (!$ids) {
            return null;
        }
        $parent = CmsCommMessage::find()
            ->andWhere(['cms_site_id' => $siteId, 'channel' => CmsCommAccount::CHANNEL_EMAIL, 'external_id' => $ids])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $parent ? $parent->conversation : $this->serviceReplyConversation($siteId, $data);
    }

    /**
     * A reply to a letter sent through a mailing service: the service gives
     * the letter its own Message-ID, so the reply refers to an id we do not
     * know. It joins the conversation of our latest letter to its author
     * from a mailing service mailbox with the same subject (without "Re:")
     * in the last 60 days.
     */
    private function serviceReplyConversation(int $siteId, array $data): ?CmsCommConversation
    {
        $drivers = \skeeks\cms\communication\mail\MailingServices::driverIds();
        $from = mb_strtolower(trim((string)$data['from_address']));
        $subject = self::baseSubject((string)$data['subject']);
        if (!$drivers || $from === '' || $subject === '') {
            return null;
        }
        $m = CmsCommMessage::tableName();
        $a = CmsCommAccount::tableName();
        $c = CmsCommConnection::tableName();
        $candidates = CmsCommMessage::find()
            ->innerJoin($a, $a.'.id = '.$m.'.cms_comm_account_id')
            ->innerJoin($c, $c.'.id = '.$a.'.cms_comm_connection_id')
            ->andWhere([$m.'.cms_site_id' => $siteId, $m.'.direction' => CmsCommMessage::DIRECTION_OUT, $c.'.driver' => $drivers])
            ->andWhere(['>=', $m.'.message_at', time() - 60 * 86400])
            ->andWhere(['like', $m.'.to_list', $from])
            ->orderBy([$m.'.id' => SORT_DESC])->limit(20)->select($m.'.*')->all();
        foreach ($candidates as $candidate) {
            $to = array_map(static fn($address) => mb_strtolower(trim((string)$address)), array_keys($candidate->getTo()));
            if (in_array($from, $to, true) && self::baseSubject((string)$candidate->subject) === $subject) {
                return $candidate->conversation;
            }
        }

        return null;
    }

    /** The subject without "Re:", "Fwd:", "Ответ:" in front, in lower case. */
    public static function baseSubject(string $subject): string
    {
        $subject = trim($subject);
        do {
            $before = $subject;
            $subject = trim((string)preg_replace('/^(re|fwd?|aw|отв|ответ|пересл)\s*(\[\d+\]|\(\d+\))?\s*:\s*/iu', '', $subject));
        } while ($subject !== $before);

        return mb_strtolower(preg_replace('/\s+/u', ' ', $subject));
    }

    private function createConversation(int $siteId, string $externalId, array $data): CmsCommConversation
    {
        // A reply whose parent is unknown still groups by the root of its chain.
        $threadKey = $data['references'][0] ?? ($data['in_reply_to'] ?: $externalId);
        $conversation = CmsCommConversation::find()->andWhere([
            'cms_site_id' => $siteId, 'channel' => CmsCommAccount::CHANNEL_EMAIL, 'thread_key' => $threadKey,
        ])->one();
        if ($conversation) {
            return $conversation;
        }
        $conversation = new CmsCommConversation([
            'cms_site_id' => $siteId,
            'channel' => CmsCommAccount::CHANNEL_EMAIL,
            'thread_key' => $threadKey,
            'subject' => mb_substr($data['subject'], 0, 500),
            'last_message_at' => $data['date'] ?: time(),
        ]);
        $conversation->save(false);

        return $conversation;
    }

    /**
     * Mailboxes of the connection the letter belongs to: by the sender for
     * the Sent folder, by the recipients for the inbox. A connection with a
     * single mailbox takes every letter of its folders.
     *
     * @return CmsCommAccount[]
     */
    private function targetAccounts(CmsCommConnection $connection, string $folderRole, array $data): array
    {
        $accounts = CmsCommAccount::find()->andWhere(['cms_comm_connection_id' => $connection->id])->all();
        if (count($accounts) <= 1) {
            return $accounts;
        }
        $addresses = $folderRole === CmsCommSyncState::ROLE_SENT ? [$data['from_address']] : $data['recipients'];
        $matched = array_values(array_filter($accounts, static fn(CmsCommAccount $a) => in_array($a->address, $addresses, true)));

        return $matched ?: $accounts;
    }

    /**
     * One copy per mailbox. A known copy takes the new place when the letter
     * has no place yet (sent from the CRM, now found in "Sent"), was moved
     * away (trash, spam, archive, a folder, deleted) and came back, or got a
     * new UID in the same folder. A copy in the other receive folder stays
     * (a letter to ourselves lies in both "Входящие" and "Отправленные").
     */
    private function addCopy(CmsCommMessage $message, CmsCommAccount $account, CmsCommConnection $connection, string $folderRole, string $folder, ?int $uidvalidity, int $uid): void
    {
        $folder = mb_substr($folder, 0, 190);
        $copy = CmsCommMessageMailbox::find()
            ->andWhere(['cms_comm_message_id' => $message->id, 'cms_comm_account_id' => $account->id])
            ->one();
        if ($copy) {
            $away = !in_array($copy->folder_role, CmsCommSyncState::RECEIVE_ROLES, true);
            $sameFolder = $copy->folder === $folder && (int)$copy->cms_comm_connection_id === (int)$connection->id;
            if (!$copy->uid || $away || ($sameFolder && ((int)$copy->uid !== $uid || (int)$copy->uidvalidity !== (int)$uidvalidity))) {
                $copy->cms_comm_connection_id = $connection->id;
                $copy->folder = $folder;
                $copy->uidvalidity = $uidvalidity;
                $copy->uid = $uid;
                $copy->folder_role = $folderRole;
                $copy->save(false);
                if ($away) {
                    // Back in the mailbox: back in the usual folders.
                    MailboxActions::refreshState([(int)$message->id]);
                }
            }
            return;
        }
        (new CmsCommMessageMailbox([
            'cms_comm_message_id' => $message->id,
            'cms_comm_account_id' => $account->id,
            'cms_comm_connection_id' => $connection->id,
            'folder' => $folder,
            'uidvalidity' => $uidvalidity,
            'uid' => $uid,
            'folder_role' => $folderRole,
        ]))->save(false);
        if ($message->box_state) {
            MailboxActions::refreshState([(int)$message->id]);
        }
    }
}
