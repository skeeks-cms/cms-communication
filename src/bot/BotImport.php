<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\chat\ChatLinks;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\models\CmsCompany;

/**
 * Chats a bot already is in, brought into the CRM when the site starts
 * receiving (stage 5.7): the history of each chat for a period through the
 * messenger (MessengerDriver::history(), archived: no notifications, no
 * tasks, no dialog state, never doubled by the message key), the company of
 * the chat from a registry, the title, picture and members.
 *
 * A registry row: chat_id, title, type (dialog/chat/channel), cms_company_id.
 */
class BotImport
{
    /** Pages of one chat at most: 100 messages a page. */
    public const MAX_PAGES = 50;

    private CmsCommConnection $connection;
    private MessengerDriver $driver;
    private BotIngest $ingest;

    public function __construct(CmsCommConnection $connection, ?MessengerDriver $driver = null)
    {
        $this->connection = $connection;
        $this->driver = $driver ?? Bots::driver($connection);
        $this->ingest = new BotIngest($connection, $this->driver);
    }

    /**
     * @param int|null $by the employee the company links are made for
     * @return array chat, messages (new), conversation id, company (linked id or null), error
     */
    public function chat(array $row, int $days, ?int $by = null): array
    {
        $chatId = (string)($row['chat_id'] ?? '');
        $result = ['chat' => $chatId, 'title' => (string)($row['title'] ?? ''), 'messages' => 0, 'conversation' => null, 'company' => null, 'error' => null];
        if ($chatId === '') {
            $result['error'] = 'нет chat_id';
            return $result;
        }
        try {
            $since = time() - $days * 86400;
            // Pages go back in time: from the newest moment ($cursor) to the oldest one ($since).
            $cursor = null;
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $events = $this->driver->history($chatId, 100, $cursor, $since);
                if (!$events) {
                    break;
                }
                usort($events, static fn(BotEvent $a, BotEvent $b) => $a->date <=> $b->date);
                $result['messages'] += $this->ingest->applyHistory(array_values(array_filter($events, static fn(BotEvent $e) => $e->date >= $since)));
                $oldest = (int)$events[0]->date;
                // A page may come shorter than asked: only an empty page or the period's start ends the chat.
                if ($oldest <= $since || ($cursor !== null && $oldest >= $cursor)) {
                    break;
                }
                $cursor = $oldest - 1;
            }
            $conversation = CmsCommConversation::find()->andWhere(['cms_site_id' => (int)$this->connection->cms_site_id,
                'thread_key' => (int)$this->connection->id.':'.$chatId])->one();
            if (!$conversation) {
                $result['error'] = 'в чате нет сообщений за '.$days.' дн.: появится с первым новым сообщением';
                return $result;
            }
            $result['conversation'] = (int)$conversation->id;
            $companyId = (int)($row['cms_company_id'] ?? 0);
            if ($companyId && CmsCompany::find()->andWhere(['id' => $companyId])->exists()) {
                ChatLinks::attach($conversation, CmsCompany::class, $companyId, $by);
                $result['company'] = $companyId;
            }
            BotChats::refresh($conversation, $this->driver);
        } catch (\Throwable $e) {
            $result['error'] = get_class($e).': '.mb_substr($e->getMessage(), 0, 300);
        }

        return $result;
    }

    /**
     * Rows of a registry file: a list of rows or {"chats": [...]}; the same
     * chat in several files — the later file wins for the company.
     *
     * @param string[] $files
     * @return array[] chat id => row
     */
    public static function registry(array $files): array
    {
        $rows = [];
        foreach ($files as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Не JSON: '.$file);
            }
            foreach ((array)($data['chats'] ?? $data) as $row) {
                if (!is_array($row) || empty($row['chat_id'])) {
                    continue;
                }
                $id = (string)$row['chat_id'];
                $old = $rows[$id] ?? [];
                $rows[$id] = [
                    'chat_id' => $id,
                    'title' => (string)($row['title'] ?? ($old['title'] ?? '')),
                    'type' => (string)($row['type'] ?? ($old['type'] ?? '')),
                    'cms_company_id' => !empty($row['cms_company_id']) ? (int)$row['cms_company_id'] : ($old['cms_company_id'] ?? null),
                ];
            }
        }

        return $rows;
    }
}
