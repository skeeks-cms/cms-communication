<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\log;

use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SmsSendChannel;
use skeeks\cms\communication\services\AiMark;
use skeeks\cms\communication\services\SendContacts;
use skeeks\cms\communication\services\SiteMessages;
use skeeks\cms\log\CmsLogEntry;
use skeeks\cms\log\CmsLogEntryRenderer;
use skeeks\cms\models\CmsLog;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * A letter, an SMS or a message from the site in the
 * activity feed of a lead, company or client: the content of the shared
 * feed entry (CmsLogEntry). The letter is shown only to employees who may
 * read it.
 */
class MessageLogRenderer extends CmsLogEntryRenderer
{
    public function entry(CmsLog $log): CmsLogEntry
    {
        $id = (int)ArrayHelper::getValue($log->data, 'id');
        $query = CmsCommMessage::find()->andWhere([CmsCommMessage::tableName().'.id' => $id]);
        if (\Yii::$app->has('user') && !\Yii::$app->user->isGuest) {
            $query->forManager();
        }
        $message = $query->one();
        if (!$message) {
            return new CmsLogEntry([
                'icon'    => 'mail',
                'title'   => 'Письмо',
                'details' => [CmsCommMessage::find()->andWhere(['id' => $id])->exists()
                    ? 'Письмо из ящика, к которому у вас нет доступа.'
                    : 'Письмо удалено.'],
            ]);
        }

        $incoming = $message->direction === CmsCommMessage::DIRECTION_IN;
        $sms = $message->channel === SmsSendChannel::CHANNEL;
        $entry = new CmsLogEntry([
            'icon'  => $sms ? 'sms' : ($incoming ? 'mail-in' : 'mail-out'),
            'tone'  => $incoming ? CmsLogEntry::TONE_SUCCESS : CmsLogEntry::TONE_ACCENT,
            'title' => $sms ? ($incoming ? 'Входящее СМС' : 'СМС') : ($incoming ? 'Входящее письмо' : 'Исходящее письмо'),
        ]);
        if ($message->channel === SiteMessages::CHANNEL) {
            $entry->icon = 'globe';
            $entry->title = 'Сообщение с сайта';
        }
        // The record was created from this letter ("Создать задачу / лид / сделку").
        $link = $log->id ? CmsCommLink::find()->andWhere(['cms_log_id' => (int)$log->id])->one() : null;
        if ($link && $link->link_type === CmsCommLink::TYPE_CREATED) {
            $entry->title = 'Создано из '.($incoming ? 'входящего' : 'исходящего').' письма';
        } elseif ($link && $link->link_type === CmsCommLink::TYPE_MANUAL && $link->created_by && ($by = \skeeks\cms\models\CmsUser::findOne((int)$link->created_by))) {
            $entry->badges[] = CmsLogEntry::badge('Привязано вручную', 'Привязал '.$by->shortDisplayName, CmsLogEntry::TONE_NEUTRAL);
        }
        if (!$incoming && $message->status !== CmsCommMessage::STATUS_SENT) {
            $failed = in_array($message->status, [CmsCommMessage::STATUS_FAILED, CmsCommMessage::STATUS_UNKNOWN], true);
            $entry->badges[] = CmsLogEntry::badge($message->getStatusName(), '', $failed ? CmsLogEntry::TONE_DANGER : CmsLogEntry::TONE_NEUTRAL);
            if ($failed) {
                $entry->tone = CmsLogEntry::TONE_DANGER;
            }
        }
        // "Подготовлено ИИ": the AI client, who asked and who sent it are in the tooltip.
        if ($aiMark = AiMark::of($message)) {
            $entry->badges[] = CmsLogEntry::badge('Подготовлено ИИ', AiMark::text($aiMark, $message->sent_by_user_id ? (int)$message->sent_by_user_id : null));
        }

        $attachments = count($message->getAttachmentList());
        $entry->summary = Html::a(Html::encode($message->subject ?: '(без темы)'), '#', [
                'data-pjax' => '0',
                'onclick'   => MailUi::viewOnClick((int)$message->id),
            ])
            .($attachments ? ' '.Html::tag('span', '· вложений: '.$attachments, ['class' => 'sx-gray']) : '');
        $entry->details[] = $incoming
            ? 'От: '.Html::encode($message->getFromAsText())
            : 'Кому: '.Html::encode($sms
                ? implode(', ', array_map(static fn($phone, $name) => SendContacts::formatPhone((string)$phone).($name ? ' ('.$name.')' : ''),
                    array_keys($message->getTo()), $message->getTo()))
                : $message->getToAsText());
        if ($message->cms_comm_campaign_id && ($name = CmsCommCampaign::find()->select('name')->andWhere(['id' => (int)$message->cms_comm_campaign_id])->scalar())) {
            $entry->details[] = 'Рассылка «'.Html::encode((string)$name).'»';
        }
        $entry->snippet = (string)$message->getSnippet(300);

        return $entry;
    }
}
