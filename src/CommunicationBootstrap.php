<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication;

use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\widgets\admin\CrmContactActionsEvent;
use skeeks\cms\widgets\admin\CrmContactBlockWidget;
use yii\base\BootstrapInterface;
use yii\base\Event;

/**
 * Plugs mail into CRM cards: the "write a letter" button next to an email
 * opens the CRM dialog instead of the mail program when the employee has a
 * mailbox to send from. Registers the letter as a CRM record for links.
 */
class CommunicationBootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        // Notifications about new letters (CmsWebNotify) refer to the letter: the standard notification
        // center links it through modelsConfig and opens it in the panel. Added here, not in the config:
        // configuring the property would replace the core list.
        if ($app->has('skeeks')) {
            $app->skeeks->modelsConfig[\skeeks\cms\communication\models\CmsCommMessage::class] = [
                'name' => 'Письма',
                'name_one' => 'Письмо',
                'controller' => 'cmsCommunication/admin-cms-comm-message',
            ];
        }

        if (!$app instanceof \yii\web\Application || !defined(CrmContactBlockWidget::class.'::EVENT_CONTACT_ACTIONS')) {
            return;
        }

        $canSend = null;
        Event::on(CrmContactBlockWidget::class, CrmContactBlockWidget::EVENT_CONTACT_ACTIONS, static function (CrmContactActionsEvent $event) use (&$canSend) {
            if ($event->contactType !== CrmContactBlockWidget::CONTACT_EMAIL || \Yii::$app->user->isGuest || !\Yii::$app->skeeks->site) {
                return;
            }
            if ($canSend === null) {
                $canSend = (bool)(new MailComposeService(\Yii::$app->user->identity))->sendableAccounts();
            }
            if (!$canSend) {
                return;
            }

            $params = ['to' => $event->value];
            if ($event->owner && method_exists($event->owner, 'getSkeeksModelCode')) {
                $params['context_model_code'] = $event->owner->getSkeeksModelCode();
                $params['context_model_id'] = (int)$event->owner->primaryKey;
            }
            $event->actions['mailto'] = CrmContactBlockWidget::iconButton('mail', 'Написать письмо', [
                'onclick' => MailUi::composeOnClick($params),
            ]);
        });
    }
}
