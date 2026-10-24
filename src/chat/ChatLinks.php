<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\chat;

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompanyLink;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveRecord;

/**
 * A whole chat linked to CRM records ("Привязать" in a chat): a lead, a
 * company, a client or a deal the employee sees. The link makes the chat the
 * record's: its messages or daily summaries go to the record's feed
 * (ChatFeed), employees who see the record see the chat (ChatAccess).
 * Suggestions come from the chat title "Компания / сайт.ru": a company with
 * that site or name.
 */
class ChatLinks
{
    public const TYPES = [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT, CrmRecords::DEAL];

    private CmsUser $user;
    private ChatAccess $access;

    public function __construct(CmsUser $user)
    {
        $this->user = $user;
        $this->access = new ChatAccess($user);
    }

    /**
     * Records the chat is linked to, as the employee sees them.
     *
     * @return array[] each: type, record (null — the employee does not see it), link
     */
    public function records(CmsCommConversation $conversation): array
    {
        $result = [];
        foreach (ChatFeed::links($conversation) as $link) {
            $type = CrmRecords::typeOfCode($link->model_code);
            if (!$type) {
                continue;
            }
            $result[] = [
                'type' => $type,
                'record' => CrmRecords::find($type, (int)$link->model_id, $this->user),
                'link' => $link,
            ];
        }

        return $result;
    }

    public function link(CmsCommConversation $conversation, string $type, int $id): ActiveRecord
    {
        $this->requireRead($conversation);
        if (!in_array($type, self::TYPES, true)) {
            throw new \DomainException('К этому чат привязать нельзя.');
        }
        $record = CrmRecords::find($type, $id, $this->user);
        if (!$record || ($type === CrmRecords::CLIENT && (int)$record->is_worker === 1)) {
            throw new \DomainException('Запись не найдена или у вас нет к ней доступа.');
        }
        self::attach($conversation, CrmRecords::codeOf($type), (int)$record->primaryKey, (int)$this->user->id);

        return $record;
    }

    public function unlink(CmsCommConversation $conversation, string $code, int $id): void
    {
        $this->requireRead($conversation);
        $link = CmsCommConversationLink::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'model_code' => $code, 'model_id' => $id])->one();
        if (!$link) {
            return;
        }
        $transaction = CmsCommConversationLink::getDb()->beginTransaction();
        try {
            $link->delete();
            ChatFeed::unlinkConversation($conversation, $code, $id);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Links a chat to a record without checks (identity linking, imports):
     * the link row and the feed in one transaction; a repeat does nothing.
     */
    public static function attach(CmsCommConversation $conversation, string $code, int $id, ?int $createdBy): bool
    {
        if (CmsCommConversationLink::find()->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'model_code' => $code, 'model_id' => $id])->exists()) {
            return false;
        }
        $transaction = CmsCommConversationLink::getDb()->beginTransaction();
        try {
            (new CmsCommConversationLink([
                'cms_comm_conversation_id' => (int)$conversation->id,
                'model_code' => $code,
                'model_id' => $id,
                'created_by' => $createdBy,
            ]))->save(false);
            ChatFeed::linkConversation($conversation, $code, $id, $createdBy);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Companies the chat title points at: a site domain ("… / plitka-lab.ru")
     * among company links, or the company name before the slash. Only
     * companies the employee sees and that are not linked yet.
     *
     * @return CmsCompany[]
     */
    public function suggest(CmsCommConversation $conversation, int $limit = 3): array
    {
        $title = (string)$conversation->title;
        if ($title === '' || $conversation->chat_type === CmsCommConversation::CHAT_PRIVATE) {
            return [];
        }
        $linked = CmsCommConversationLink::find()->select('model_id')->andWhere(['cms_comm_conversation_id' => (int)$conversation->id, 'model_code' => CmsCompany::class])->column();
        $visible = CrmRecords::visibleQuery(CrmRecords::COMPANY, $this->user);
        $t = CmsCompany::tableName();
        $ids = [];
        foreach (self::domains($title) as $domain) {
            $ids = array_merge($ids, CmsCompanyLink::find()->select('cms_company_id')
                ->andWhere(['or', ['like', 'url', '://'.$domain, false], ['like', 'url', '://'.$domain.'/%', false], ['like', 'url', '.'.$domain, false], ['url' => $domain]])
                ->andWhere(['like', 'url', $domain])->column());
        }
        $name = trim((string)preg_split('~\s[/|]\s|\s—\s~u', $title)[0]);
        $query = (clone $visible)->andWhere(['not', [$t.'.id' => $linked ?: [0]]]);
        if ($ids) {
            $byDomain = (clone $query)->andWhere([$t.'.id' => array_unique($ids)])->limit($limit)->all();
            if ($byDomain) {
                return $byDomain;
            }
        }
        if (mb_strlen($name) >= 3) {
            return $query->andWhere(['like', $t.'.name', $name])->limit($limit)->all();
        }

        return [];
    }

    /** Domains written in a chat title: "КС Интерьер / plitka-62.ru" → plitka-62.ru. */
    public static function domains(string $title): array
    {
        preg_match_all('~(?:https?://)?((?:[a-z0-9а-яё-]+\.)+(?:[a-z]{2,}|рф))~iu', mb_strtolower($title), $m);

        return array_values(array_unique(array_map(static fn($d) => preg_replace('~^www\.~', '', $d), $m[1])));
    }

    private function requireRead(CmsCommConversation $conversation): void
    {
        if (!$this->access->canRead($conversation)) {
            throw new \DomainException('Чат не найден или у вас нет к нему доступа.');
        }
    }
}
