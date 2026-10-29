<?php

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\chat\ChatFeed;
use skeeks\cms\communication\chat\ChatLinks;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\crm\CrmInboundAttacherInterface;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveRecord;

/**
 * An incoming letter, chat message or site message and the deal of its client
 * (core crm\CrmInbound, owner's decision 29.10.2026), and the attacher
 * `message` of `crmInbound.attachers`.
 *
 * route() runs after the usual linking (by the addresses, the records of the
 * page, the earlier letters of the conversation): a message that lies in a
 * deal already — a reply in the conversation, the deal's own e-mail — stays
 * as it is; otherwise the companies and persons it is linked to give their one
 * open deal (linked automatically) or, with several, a notification of the
 * executors.
 */
class InboundLeads implements CrmInboundAttacherInterface
{
    public const TYPE = 'message';

    public static function route(CmsCommMessage $message, string $verb, ?string $eventKey = null): ?CmsLead
    {
        $inbound = '\skeeks\cms\crm\CrmInbound';
        if ($message->direction !== CmsCommMessage::DIRECTION_IN || !class_exists($inbound) || !$inbound::instance()->attacher(self::TYPE)) {
            return null;
        }
        $attacher = new self();
        if (self::linkedCodes($message, (new CmsLead())->skeeksModelCode)) {
            return null;
        }
        $clients = $attacher->clients($message);
        if (!$clients) {
            return null;
        }
        // A message older than a day (a history import) only goes to the one deal, nobody is notified.
        $recent = (int)$message->message_at === 0 || (int)$message->message_at > time() - 86400;
        $lead = $inbound::instance()->route(self::TYPE, $message, $clients, $verb, $eventKey, $recent);
        if ($lead) {
            (new MessageLinker())->link($message, [$lead], CmsCommLink::TYPE_AUTO);
        }

        return $lead;
    }

    /** @return int[] ids of the records of this model code the message is linked to */
    private static function linkedCodes(CmsCommMessage $message, string $code): array
    {
        return array_map('intval', CmsCommLink::find()->select('model_id')
            ->andWhere(['cms_comm_message_id' => (int)$message->id, 'model_code' => $code])->column());
    }

    public function find(int $id): ?ActiveRecord
    {
        return CmsCommMessage::findOne($id);
    }

    public function clients(ActiveRecord $inbound): array
    {
        /** @var CmsCommMessage $inbound */
        $companies = self::linkedCodes($inbound, (new CmsCompany())->skeeksModelCode);
        $persons = self::linkedCodes($inbound, (new CmsUser())->skeeksModelCode);

        return array_merge(
            $companies ? CmsCompany::find()->andWhere(['id' => $companies])->all() : [],
            $persons ? CmsUser::find()->andWhere(['id' => $persons, 'is_worker' => 0])->all() : []
        );
    }

    public function isLinked(ActiveRecord $inbound, CmsLead $lead): bool
    {
        return in_array((int)$lead->id, self::linkedCodes($inbound, $lead->skeeksModelCode), true);
    }

    /**
     * As the manual linking: the letter (its replies follow by the conversation); a dialog of a chat is linked
     * whole, so its next messages go to the deal too.
     */
    public function attach(ActiveRecord $inbound, CmsLead $lead, int $actorId): void
    {
        /** @var CmsCommMessage $inbound */
        $conversation = $inbound->cms_comm_conversation_id ? CmsCommConversation::findOne((int)$inbound->cms_comm_conversation_id) : null;
        if ($conversation && $conversation->isChat() && $conversation->chat_type === CmsCommConversation::CHAT_PRIVATE) {
            ChatLinks::attach($conversation, $lead->skeeksModelCode, (int)$lead->id, $actorId);

            return;
        }
        (new MessageLinker())->link($inbound, [$lead], CmsCommLink::TYPE_MANUAL, $actorId);
    }

    public function describe(ActiveRecord $inbound): string
    {
        /** @var CmsCommMessage $inbound */
        $conversation = $inbound->cms_comm_conversation_id ? CmsCommConversation::findOne((int)$inbound->cms_comm_conversation_id) : null;
        if ($conversation && $conversation->isChat()) {
            return 'Сообщение в чате: «'.mb_substr(trim((string)$inbound->body_text), 0, 120).'»';
        }

        return 'Письмо «'.((string)$inbound->subject ?: 'без темы').'»'.($inbound->from_address ? ' от '.$inbound->from_address : '');
    }
}
