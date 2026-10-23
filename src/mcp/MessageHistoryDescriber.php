<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\mcp\crm\CrmActor;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\HistoryLogDescriberInterface;
use skeeks\cms\models\CmsLog;
use yii\helpers\ArrayHelper;

/**
 * A letter, an SMS or a message from the site in the client history
 * (cms_crm_history_get): who wrote, the subject and the own text. Access
 * follows the feed (MessageLogRenderer): a letter of a mailbox the
 * employee may not read is listed without content.
 */
class MessageHistoryDescriber implements HistoryLogDescriberInterface
{
    public const TITLES = [
        'email_in' => 'Входящее письмо', 'email_out' => 'Исходящее письмо',
        'sms_in' => 'Входящее СМС', 'sms_out' => 'СМС',
        'site_in' => 'Сообщение с сайта', 'site_out' => 'Сообщение на сайт',
    ];

    /** @var array message id => CmsCommMessage|false of one request */
    private $messages = [];

    public function describe(CmsLog $log, CrmReadContext $context): ?array
    {
        $id = (int)ArrayHelper::getValue((array)$log->data, 'id');
        if (!array_key_exists($id, $this->messages)) {
            $this->messages[$id] = CmsCommMessage::find()->forManager()->andWhere([CmsCommMessage::tableName().'.id' => $id])->one() ?: false;
        }
        $m = $this->messages[$id];
        if (!$m) {
            $exists = CmsCommMessage::find()->andWhere(['id' => $id])->exists();
            return [
                'title' => 'Письмо',
                'summary' => $exists ? 'Письмо из ящика, к которому у сотрудника нет доступа.' : 'Письмо удалено.',
                'access' => false,
                'dedup_key' => 'message:'.$id,
            ];
        }
        $kind = MailReadService::kind($m);
        $incoming = $m->direction === CmsCommMessage::DIRECTION_IN;
        $title = self::TITLES[$kind] ?? 'Письмо';
        $link = $log->id ? CmsCommLink::find()->andWhere(['cms_log_id' => (int)$log->id])->one() : null;
        if ($link && $link->link_type === CmsCommLink::TYPE_CREATED) {
            $title = 'Создано из '.($incoming ? 'входящего' : 'исходящего').' письма';
        }
        $service = new MailReadService();
        $text = $service->ownText($m)[0];
        $subject = trim((string)$m->subject);
        $summary = ($subject !== '' && strpos($kind, 'sms') !== 0 ? $subject.' — ' : '').$text;
        if ($incoming) {
            $actor = CrmActor::client(\skeeks\cms\communication\helpers\MailText::name($m->from_name) ?: null, $context);
        } else {
            $actor = $m->sent_by_user_id ? CrmActor::user((int)$m->sent_by_user_id, $context) : ['kind' => CrmActor::SYSTEM];
        }
        $details = array_filter([
            'subject' => $context->text($subject, 300),
            'status' => $incoming ? null : (string)$m->status,
            'cms_comm_conversation_id' => (int)$m->cms_comm_conversation_id,
            'attachments_count' => count($m->getAttachmentList()) ?: null,
            'link_type' => $link ? (string)$link->link_type : null,
            'cms_comm_campaign_id' => $m->hasAttribute('cms_comm_campaign_id') && $m->cms_comm_campaign_id ? (int)$m->cms_comm_campaign_id : null,
            'is_auto' => $m->is_auto ? true : null,
            // "Подготовлено ИИ": the AI client and user; actor stays the employee who sent it.
            'prepared' => \skeeks\cms\communication\services\AiMark::forApi(\skeeks\cms\communication\services\AiMark::of($m)),
        ], static function ($v) { return $v !== null && $v !== ''; });
        return [
            'subtype' => $kind,
            'title' => $title,
            'summary' => $context->text($summary),
            'direction' => $incoming ? 'in' : 'out',
            'channel' => explode('_', $kind)[0],
            'actor' => $actor,
            'details' => $details,
            'ref' => ['tool' => 'cms_comm_message_get', 'id' => (int)$m->id],
            'dedup_key' => 'message:'.(int)$m->id,
        ];
    }
}
