<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\chat\ChatLinks;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\models\CmsLead;

/**
 * Letters and chats of a sorted request go where the work continues (core
 * П2а, application event `crm.leadTriaged`, bound in config/common.php):
 * "добавить в открытую сделку" — to the target deal; "это поддержка" — to the
 * service of the support task. The links of the closed request stay: its
 * card keeps its history. Nothing else is touched.
 */
class TriageLinks
{
    public static function onTriaged($event): void
    {
        $lead = $event->lead ?? null;
        if (!$lead instanceof CmsLead) {
            return;
        }
        $target = null;
        if ($event->result === CmsLead::TRIAGE_MERGED && $event->target instanceof CmsLead) {
            $target = $event->target;
        } elseif ($event->result === CmsLead::TRIAGE_SUPPORT && $event->task && $event->task->cms_deal_id && $event->task->cmsDeal) {
            $target = $event->task->cmsDeal;
        }
        if (!$target) {
            return;
        }

        try {
            (new self())->copy($lead, $target, $event->actorId ? (int)$event->actorId : null);
        } catch (\Throwable $e) {
            // The sorting is committed; a missing link is visible and can be added by hand.
            \Yii::error('Связи писем разобранной сделки №'.(int)$lead->id.' не перенесены: '.$e->getMessage(), self::class);
        }
    }

    public function copy(CmsLead $lead, $target, ?int $actorId): void
    {
        $code = $lead->skeeksModelCode;
        $linker = new MessageLinker();
        $messageIds = CmsCommLink::find()->select('cms_comm_message_id')
            ->andWhere(['model_code' => $code, 'model_id' => (int)$lead->id])->column();
        foreach (CmsCommMessage::find()->andWhere(['id' => $messageIds])->each(50) as $message) {
            $linker->link($message, [$target], CmsCommLink::TYPE_MANUAL, $actorId);
        }

        $conversationIds = CmsCommConversationLink::find()->select('cms_comm_conversation_id')
            ->andWhere(['model_code' => $code, 'model_id' => (int)$lead->id])->column();
        $targetCode = method_exists($target, 'getSkeeksModelCode') ? $target->getSkeeksModelCode() : get_class($target);
        foreach (CmsCommConversation::find()->andWhere(['id' => $conversationIds])->all() as $conversation) {
            ChatLinks::attach($conversation, (string)$targetCode, (int)$target->primaryKey, $actorId);
        }
    }
}
