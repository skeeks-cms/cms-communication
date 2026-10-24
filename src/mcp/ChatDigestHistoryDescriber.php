<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\communication\chat\ChatAccess;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\mcp\crm\CrmActor;
use skeeks\cms\mcp\crm\CrmDate;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\HistoryLogDescriberInterface;
use skeeks\cms\models\CmsLog;
use yii\helpers\ArrayHelper;

/**
 * A day of a client's group chat in the client history (cms_crm_history_get),
 * as the feed shows it (chat\ChatFeed): how many messages, who wrote, the
 * first lines. A chat the employee may not read is named without content;
 * the whole chat — cms_comm_chat_get.
 */
class ChatDigestHistoryDescriber implements HistoryLogDescriberInterface
{
    public function describe(CmsLog $log, CrmReadContext $context): ?array
    {
        $data = (array)$log->data;
        $id = (int)ArrayHelper::getValue($data, 'conversation_id');
        $chat = CmsCommConversation::findOne($id);
        $user = \Yii::$app->user->identity;
        if (!$chat || !$user || !(new ChatAccess($user))->canRead($chat)) {
            return ['title' => 'Сводка чата', 'summary' => $chat ? 'Чат, к которому у сотрудника нет доступа.' : 'Чат удалён.', 'access' => false,
                'dedup_key' => 'chat_digest:'.$id.':'.(int)ArrayHelper::getValue($data, 'day')];
        }
        $authors = (array)ArrayHelper::getValue($data, 'authors', []);
        $lines = array_map(static fn($line) => (string)($line[0] ?? '').': '.(string)($line[1] ?? ''), (array)ArrayHelper::getValue($data, 'lines', []));
        $count = (int)ArrayHelper::getValue($data, 'count');

        return [
            'subtype' => 'chat_digest',
            'title' => 'Переписка в чате «'.$chat->asText().'» за день',
            'summary' => $context->text($count.' сообщ. Писали: '.implode(', ', array_keys($authors)).'. '.implode(' / ', $lines)),
            'direction' => 'in',
            'channel' => (string)$chat->channel,
            'actor' => CrmActor::client(null, $context),
            'details' => array_filter([
                'cms_comm_conversation_id' => (int)$chat->id,
                'messages_count' => $count,
                'incoming_count' => (int)ArrayHelper::getValue($data, 'in'),
                'authors' => array_map(static fn($name, $n) => ['name' => $context->text((string)$name, 200), 'count' => (int)$n], array_keys($authors), array_values($authors)),
                'last_at' => CrmDate::format((int)ArrayHelper::getValue($data, 'last_at')),
            ], static fn($v) => $v !== null && $v !== '' && $v !== []),
            'ref' => ['tool' => 'cms_comm_chat_get', 'id' => (int)$chat->id],
            'dedup_key' => 'chat_digest:'.(int)$chat->id.':'.(int)ArrayHelper::getValue($data, 'day'),
        ];
    }
}
