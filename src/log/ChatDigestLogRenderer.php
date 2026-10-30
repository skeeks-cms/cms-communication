<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\log;

use skeeks\cms\communication\chat\ChatAccess;
use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\log\CmsLogEntry;
use skeeks\cms\log\CmsLogEntryRenderer;
use skeeks\cms\models\CmsLog;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * A day of a group chat in the feed of a linked record (chat\ChatFeed):
 * how many messages, who wrote, the first lines, "Открыть чат". Shown only
 * to employees who see the chat.
 */
class ChatDigestLogRenderer extends CmsLogEntryRenderer
{
    public function entry(CmsLog $log): CmsLogEntry
    {
        $data = (array)$log->data;
        $conversation = $this->conversation((int)ArrayHelper::getValue($data, 'conversation_id'));
        if (!$conversation) {
            return new CmsLogEntry(['icon' => 'chat', 'title' => 'Сводка чата', 'details' => ['Чат удалён.']]);
        }
        $user = \Yii::$app->has('user') && !\Yii::$app->user->isGuest ? \Yii::$app->user->identity : null;
        if (!$user || !$this->canRead($user, $conversation)) {
            return new CmsLogEntry(['icon' => 'chat', 'title' => 'Сводка чата', 'details' => ['Чат бота, к которому у вас нет доступа.']]);
        }
        $count = (int)ArrayHelper::getValue($data, 'count');
        $day = \Yii::$app->formatter->asDate((int)ArrayHelper::getValue($data, 'day', $log->created_at), 'd MMMM');
        $authors = array_keys((array)ArrayHelper::getValue($data, 'authors', []));
        $lines = '';
        foreach ((array)ArrayHelper::getValue($data, 'lines', []) as $line) {
            $lines .= Html::tag('div', '<b>'.Html::encode((string)($line[0] ?? '')).':</b> '.Html::encode((string)($line[1] ?? '')), ['class' => 'sx-gray']);
        }
        $more = $count > count((array)ArrayHelper::getValue($data, 'lines', [])) ? Html::tag('div', '…', ['class' => 'sx-gray']) : '';

        return new CmsLogEntry([
            'icon'    => 'chat',
            'tone'    => CmsLogEntry::TONE_NEUTRAL,
            'title'   => 'Чат «'.$conversation->asText().'» в '.ChatUi::channelName($conversation).' за '.$day,
            'summary' => Html::encode($count.' '.self::plural($count, 'сообщение', 'сообщения', 'сообщений').': '.implode(', ', array_slice($authors, 0, 6)).(count($authors) > 6 ? ' и другие' : '')),
            'body'    => $lines.$more,
            'details' => [Html::a('Открыть чат', '#', ['data-pjax' => '0', 'onclick' => ChatUi::openChatOnClick((int)$conversation->id)])],
        ]);
    }

    /*
     * A group chat gives a digest a day, so a feed shows the same chat many times (audit final4: 40
     * identical checks on a company card): the chat and the answer are kept in the request memo of
     * CrmAccess, keyed by the employee and dropped with resetMemo() (FeedAnswers, audit F5-01).
     */
    private function conversation(int $id): ?CmsCommConversation
    {
        return FeedAnswers::remember('chat:'.$id, static fn() => CmsCommConversation::findOne($id));
    }

    private function canRead($user, CmsCommConversation $conversation): bool
    {
        return FeedAnswers::remember('chatRead:'.(int)$user->id.':'.(int)$conversation->id,
            static fn() => (new ChatAccess($user))->canRead($conversation));
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        return $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20) ? $few : $many;
    }
}
