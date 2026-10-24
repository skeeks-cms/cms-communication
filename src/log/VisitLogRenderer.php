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
use skeeks\cms\log\CmsLogRendererInterface;
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
class VisitLogRenderer implements CmsLogRendererInterface
{
    public function render(CmsLog $log): string
    {
        $data = (array)$log->data;
        $visit = CmsCommTrackVisit::find()->andWhere(['id' => (int)ArrayHelper::getValue($data, 'visit')])->with('track')->one();
        if (!$visit || !$visit->track) {
            return Html::tag('div', 'Переход на сайт по ссылке из письма: данные удалены.', ['class' => 'sx-gray']);
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
            ? Html::a(Html::encode($message->subject ?: '(без темы)'), '#', ['data-pjax' => '0', 'onclick' => MailUi::viewOnClick((int)$message->id), 'style' => 'font-weight:600'])
            : Html::tag('span', 'письмо из ящика, к которому у вас нет доступа', ['class' => 'sx-gray']);
        $title = $checked ? 'Ссылка из письма: возможно, проверка ссылок' : 'Перешёл на сайт из письма';

        $pages = [];
        foreach ($visit->getPageList() as $page) {
            $pages[] = Html::a(Html::encode(LinkVisits::pageLabel($page)), (string)$page['url'], [
                'target' => '_blank', 'rel' => 'noopener noreferrer', 'data-pjax' => '0', 'title' => LinkVisits::displayUrl((string)$page['url']),
            ]);
        }
        $more = (int)$visit->pages_count - count($pages);
        $facts = [];
        if (!$checked) {
            $facts[] = \Yii::t('app', '{n, plural, one{# страница} few{# страницы} many{# страниц} other{# страницы}}', ['n' => (int)$visit->pages_count]);
            $minutes = (int)round(((int)$visit->last_at - (int)$visit->created_at) / 60);
            if ($minutes > 0) {
                $facts[] = $minutes.' мин на сайте';
            }
            if ($track->cms_comm_campaign_id && ($name = CmsCommCampaign::find()->select('name')->andWhere(['id' => (int)$track->cms_comm_campaign_id])->scalar())) {
                $facts[] = 'рассылка «'.$name.'»';
            }
        }

        return Html::tag('div',
            Html::tag('div', Html::encode($title), ['class' => 'h5'])
            .Html::tag('div', '<span class="sx-gray">Письмо от '.Html::encode($date).':</span> '.$letter
                .($track->recipient ? ' <span class="sx-gray">· '.Html::encode((string)$track->recipient).'</span>' : ''))
            .($pages ? Html::tag('div', ($checked ? '<span class="sx-gray">Открыта:</span> ' : '').implode(' <span class="sx-gray">→</span> ', $pages)
                .($more > 0 ? ' <span class="sx-gray">и ещё '.$more.'</span>' : ''), ['class' => 'sx-comm-visit-log__pages']) : '')
            .($checked
                ? Html::tag('div', Html::encode('Ссылку открыла, похоже, почтовая служба или защитная система ('
                    .LinkChecks::reasonName((string)$visit->check_reason).'). Это не засчитано как переход клиента.'), ['class' => 'sx-gray'])
                : ($facts ? Html::tag('div', Html::encode(implode(' · ', $facts)), ['class' => 'sx-gray']) : '')),
            ['class' => 'sx-comm-visit-log', 'style' => 'overflow-wrap:anywhere']
        );
    }
}
