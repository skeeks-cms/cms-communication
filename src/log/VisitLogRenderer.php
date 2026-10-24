<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\log;

use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTrackVisit;
use skeeks\cms\communication\services\LinkChecks;
use skeeks\cms\communication\services\LinkVisits;
use skeeks\cms\log\CmsLogEntry;
use skeeks\cms\log\CmsLogEntryRenderer;
use skeeks\cms\models\CmsLog;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * A visit of our site by a link of a letter in the activity feed: "Перешёл
 * на сайт из письма от <дата>: <страница> → <страница> → …" — one entry per
 * visit, its pages read when the feed is shown (the visit grows while the
 * client walks the site). A link check of a mail service is "возможно,
 * проверка ссылок". The letter is named and opened only for employees who
 * may read it.
 */
class VisitLogRenderer extends CmsLogEntryRenderer
{
    public function entry(CmsLog $log): CmsLogEntry
    {
        $data = (array)$log->data;
        $visit = CmsCommTrackVisit::find()->andWhere(['id' => (int)ArrayHelper::getValue($data, 'visit')])->with('track')->one();
        if (!$visit || !$visit->track) {
            return new CmsLogEntry(['icon' => 'pointer-click', 'title' => 'Переход на сайт по ссылке из письма', 'details' => ['Данные удалены.']]);
        }
        $track = $visit->track;
        $checked = ArrayHelper::getValue($data, 'event') === LinkVisits::EVENT_CHECKED;

        $query = CmsCommMessage::find()->andWhere([CmsCommMessage::tableName().'.id' => (int)$track->cms_comm_message_id]);
        if (\Yii::$app->has('user') && !(\Yii::$app instanceof \yii\console\Application) && !\Yii::$app->user->isGuest) {
            $query->forManager();
        }
        $message = $query->one();
        $date = \Yii::$app->formatter->asDate((int)(CmsCommMessage::find()->select('message_at')->andWhere(['id' => (int)$track->cms_comm_message_id])->scalar() ?: $track->created_at), 'php:d.m.Y');
        $letter = $message
            ? Html::a(Html::encode($message->subject ?: '(без темы)'), '#', ['data-pjax' => '0', 'onclick' => MailUi::viewOnClick((int)$message->id)])
            : 'письмо из ящика, к которому у вас нет доступа';

        $entry = new CmsLogEntry([
            'icon'  => $checked ? 'shield' : 'pointer-click',
            'tone'  => $checked ? CmsLogEntry::TONE_NEUTRAL : CmsLogEntry::TONE_SUCCESS,
            'title' => $checked ? 'Ссылка из письма: возможно, проверка ссылок' : 'Перешёл на сайт из письма',
        ]);

        $pages = [];
        foreach ($visit->getPageList() as $page) {
            $pages[] = Html::a(Html::encode(LinkVisits::pageLabel($page)), (string)$page['url'], [
                'target' => '_blank', 'rel' => 'noopener noreferrer', 'data-pjax' => '0', 'title' => LinkVisits::displayUrl((string)$page['url']),
            ]);
        }
        $more = (int)$visit->pages_count - count($pages);
        if ($pages) {
            $entry->summary = ($checked ? '<span class="sx-gray">Открыта:</span> ' : '').implode(' <span class="sx-gray">→</span> ', $pages)
                .($more > 0 ? ' <span class="sx-gray">и ещё '.$more.'</span>' : '');
        }

        $entry->details[] = 'Письмо от '.Html::encode($date).': '.$letter
            .($track->recipient ? ' · '.Html::encode((string)$track->recipient) : '');
        if ($checked) {
            $entry->details[] = Html::encode('Ссылку открыла, похоже, почтовая служба или защитная система ('
                .LinkChecks::reasonName((string)$visit->check_reason).'). Это не засчитано как переход клиента.');
        } else {
            $facts = [];
            $facts[] = \Yii::t('app', '{n, plural, one{# страница} few{# страницы} many{# страниц} other{# страницы}}', ['n' => (int)$visit->pages_count]);
            $minutes = (int)round(((int)$visit->last_at - (int)$visit->created_at) / 60);
            if ($minutes > 0) {
                $facts[] = $minutes.' мин на сайте';
            }
            if ($track->cms_comm_campaign_id && ($name = CmsCommCampaign::find()->select('name')->andWhere(['id' => (int)$track->cms_comm_campaign_id])->scalar())) {
                $facts[] = 'рассылка «'.$name.'»';
            }
            $entry->details[] = Html::encode(implode(' · ', $facts));
        }

        return $entry;
    }
}
