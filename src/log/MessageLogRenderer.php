<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\log;

use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\log\CmsLogRendererInterface;
use skeeks\cms\models\CmsLog;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * A letter in the activity feed of a lead, company or client. The letter is
 * shown only to employees who may read it.
 */
class MessageLogRenderer implements CmsLogRendererInterface
{
    public function render(CmsLog $log): string
    {
        $id = (int)ArrayHelper::getValue($log->data, 'id');
        $query = CmsCommMessage::find()->andWhere([CmsCommMessage::tableName().'.id' => $id]);
        if (\Yii::$app->has('user') && !\Yii::$app->user->isGuest) {
            $query->forManager();
        }
        $message = $query->one();
        if (!$message) {
            return Html::tag('div', CmsCommMessage::find()->andWhere(['id' => $id])->exists()
                ? 'Письмо из ящика, к которому у вас нет доступа.'
                : 'Письмо удалено.', ['class' => 'sx-gray']);
        }

        $incoming = $message->direction === CmsCommMessage::DIRECTION_IN;
        $sms = $message->channel === \skeeks\cms\communication\send\SmsSendChannel::CHANNEL;
        $title = $sms ? ($incoming ? 'Входящее СМС' : 'СМС') : ($incoming ? 'Входящее письмо' : 'Исходящее письмо');
        if ($message->channel === \skeeks\cms\communication\services\SiteMessages::CHANNEL) {
            $title = 'Сообщение с сайта';
        }
        // The record was created from this letter ("Создать задачу / лид / сделку").
        $link = $log->id ? CmsCommLink::find()->andWhere(['cms_log_id' => (int)$log->id])->one() : null;
        if ($link && $link->link_type === CmsCommLink::TYPE_CREATED) {
            $title = 'Создано из '.($incoming ? 'входящего' : 'исходящего').' письма';
        } elseif ($link && $link->link_type === CmsCommLink::TYPE_MANUAL && $link->created_by && ($by = \skeeks\cms\models\CmsUser::findOne((int)$link->created_by))) {
            $title .= ' · привязано вручную ('.$by->shortDisplayName.')';
        }
        $who = $incoming
            ? '<span class="sx-gray">От:</span> '.Html::encode($message->getFromAsText())
            : '<span class="sx-gray">Кому:</span> '.Html::encode($sms
                ? implode(', ', array_map(static fn($phone, $name) => \skeeks\cms\communication\services\SendContacts::formatPhone((string)$phone).($name ? ' ('.$name.')' : ''),
                    array_keys($message->getTo()), $message->getTo()))
                : $message->getToAsText());
        $status = '';
        if (!$incoming && $message->status !== CmsCommMessage::STATUS_SENT) {
            $status = ' · '.Html::tag('span', Html::encode($message->getStatusName()), [
                'class' => in_array($message->status, [CmsCommMessage::STATUS_FAILED, CmsCommMessage::STATUS_UNKNOWN], true) ? 'sx-status sx-status--danger' : 'sx-status',
            ]);
        }
        $attachments = count($message->getAttachmentList());

        return Html::tag('div',
            Html::tag('div', Html::encode($title).$status, ['class' => 'h5'])
            .Html::tag('div', $who)
            .Html::tag('div', Html::a(Html::encode($message->subject ?: '(без темы)'), '#', [
                'data-pjax' => '0',
                'onclick' => MailUi::viewOnClick((int)$message->id),
                'style' => 'font-weight:600',
            ]).($attachments ? ' <span class="sx-gray">· вложений: '.$attachments.'</span>' : ''))
            .Html::tag('div', Html::encode($message->getSnippet(300)), ['class' => 'sx-gray']),
            ['class' => 'sx-comm-message-log']
        );
    }
}
