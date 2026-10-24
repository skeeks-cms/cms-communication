<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\chat;

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * Folders of the "Чаты" screen over the chats an employee sees:
 * - "Новые" — private dialogs nobody took, not closed;
 * - "Мои" — dialogs the employee took, not closed;
 * - "Группы" — group chats and channels;
 * - "Все", "Закрытые" and one folder per bot.
 * Unread — incoming messages after the employee's read mark of the chat.
 */
class ChatFolders
{
    public const NEW = 'new';
    public const MINE = 'mine';
    public const GROUPS = 'groups';
    public const ALL = 'all';
    public const CLOSED = 'closed';

    private ChatAccess $access;
    private int $siteId;

    public function __construct(CmsUser $user, int $siteId)
    {
        $this->access = new ChatAccess($user);
        $this->siteId = $siteId;
    }

    /**
     * @return array[] key, title, count of chats with unread messages
     */
    public function folders(): array
    {
        $result = [];
        foreach ([self::NEW => 'Новые', self::MINE => 'Мои', self::GROUPS => 'Группы', self::ALL => 'Все', self::CLOSED => 'Закрытые'] as $key => $title) {
            $result[] = ['key' => $key, 'title' => $title, 'group' => null,
                'unread' => $key === self::CLOSED ? 0 : (int)$this->query($key)->andWhere($this->unreadCondition())->count()];
        }
        $bots = $this->access->bots($this->siteId);
        if (count($bots) > 1) {
            foreach ($bots as $bot) {
                $key = 'bot:'.(int)$bot->cms_comm_connection_id;
                $result[] = ['key' => $key, 'title' => (string)($bot->name ?: $bot->address), 'group' => 'Боты',
                    'unread' => (int)$this->query($key)->andWhere($this->unreadCondition())->count()];
            }
        }

        return $result;
    }

    public function isKnown(string $folder): bool
    {
        return in_array($folder, array_column($this->folders(), 'key'), true);
    }

    /** Chats of a folder, newest first; $search — in titles and message texts. */
    public function query(string $folder, string $search = ''): ActiveQuery
    {
        $c = CmsCommConversation::tableName();
        $userId = (int)$this->access->user()->id;
        $query = $this->access->query($this->siteId);
        switch ($folder) {
            case self::NEW:
                $query->andWhere([$c.'.chat_type' => CmsCommConversation::CHAT_PRIVATE, $c.'.assigned_user_id' => null, $c.'.closed_at' => null]);
                break;
            case self::MINE:
                $query->andWhere([$c.'.assigned_user_id' => $userId, $c.'.closed_at' => null]);
                break;
            case self::GROUPS:
                $query->andWhere([$c.'.chat_type' => [CmsCommConversation::CHAT_GROUP, CmsCommConversation::CHAT_CHANNEL]]);
                break;
            case self::CLOSED:
                $query->andWhere(['not', [$c.'.closed_at' => null]]);
                break;
            default:
                if (str_starts_with($folder, 'bot:')) {
                    $query->andWhere([$c.'.cms_comm_connection_id' => (int)substr($folder, 4)]);
                }
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%'.addcslashes(mb_substr($search, 0, 100), '%_\\').'%';
            $query->andWhere(['or',
                ['like', $c.'.title', $like, false],
                ['exists', CmsCommMessage::find()->andWhere([CmsCommMessage::tableName().'.cms_comm_conversation_id' => new Expression($c.'.id')])
                    ->andWhere(['or', ['like', 'body_text', $like, false], ['like', 'from_name', $like, false]])],
            ]);
        }

        return $query->orderBy([$c.'.last_message_at' => SORT_DESC, $c.'.id' => SORT_DESC]);
    }

    /**
     * Unread incoming messages of these chats for the employee.
     *
     * @return int[] conversation id => count
     */
    public function unreadCounts(array $conversationIds): array
    {
        if (!$conversationIds) {
            return [];
        }
        $m = CmsCommMessage::tableName();
        $rows = CmsCommMessage::find()->select(['cid' => $m.'.cms_comm_conversation_id', 'n' => 'COUNT(*)'])
            ->andWhere([$m.'.cms_comm_conversation_id' => $conversationIds])
            ->andWhere($this->unreadMessageCondition($m.'.cms_comm_conversation_id'))
            ->groupBy($m.'.cms_comm_conversation_id')->asArray()->all();

        return array_map('intval', array_column($rows, 'n', 'cid'));
    }

    /** A chat has unread incoming messages (condition on the conversation table). */
    private function unreadCondition(): array
    {
        $c = CmsCommConversation::tableName();

        return ['exists', CmsCommMessage::find()->andWhere([CmsCommMessage::tableName().'.cms_comm_conversation_id' => new Expression($c.'.id')])
            ->andWhere($this->unreadMessageCondition(CmsCommConversation::tableName().'.id'))];
    }

    private function unreadMessageCondition(string $conversationColumn): array
    {
        $m = CmsCommMessage::tableName();
        $userId = (int)$this->access->user()->id;

        return ['and',
            [$m.'.direction' => CmsCommMessage::DIRECTION_IN],
            [$m.'.is_history' => 0],
            new Expression($m.'.id > COALESCE((SELECT r.last_message_id FROM {{%cms_comm_conversation_read}} r WHERE r.cms_comm_conversation_id = '
                .$conversationColumn.' AND r.cms_user_id = '.$userId.'), 0)'),
        ];
    }
}
