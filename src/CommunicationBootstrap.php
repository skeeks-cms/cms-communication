<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication;

use skeeks\cms\communication\helpers\MailingUi;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\rbac\CmsManager;
use skeeks\cms\widgets\admin\CrmCardBlocksEvent;
use skeeks\cms\widgets\admin\CrmCardBlocksWidget;
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
            // "Готов импорт №…", "Вам назначены лиды из импорта №…" open the import card.
            $app->skeeks->modelsConfig[\skeeks\cms\communication\models\CmsCommImport::class] = [
                'name' => 'Импорт в лиды',
                'name_one' => 'Импорт',
                'controller' => 'cmsCommunication/admin-cms-comm-import',
            ];
        }

        if (!$app instanceof \yii\web\Application || !defined(CrmContactBlockWidget::class.'::EVENT_CONTACT_ACTIONS')) {
            return;
        }

        // "Импорт" — a tab of the core section "Лиды" beside "Отчёт": BackendController::$actionsMap through
        // the container definition of the controller, so core knows nothing of imports.
        $leads = \skeeks\cms\controllers\AdminCmsLeadController::class;
        if (class_exists($leads)) {
            $definition = \Yii::$container->getDefinitions()[$leads] ?? [];
            $definition['actionsMap']['comm-import'] = [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Импорт',
                'icon' => 'fas fa-file-import',
                'priority' => 28,
                'callback' => [\skeeks\cms\communication\controllers\AdminCmsCommImportController::class, 'renderLeadsTab'],
            ];
            \Yii::$container->set($leads, $definition);
        }

        // Mailings: the block "Рассылки" in lead, company and client cards, a mark at opted-out contacts.
        if (defined(CrmCardBlocksWidget::class.'::EVENT_BLOCKS')) {
            Event::on(CrmCardBlocksWidget::class, CrmCardBlocksWidget::EVENT_BLOCKS, static function (CrmCardBlocksEvent $event) {
                if ($event->position !== CrmCardBlocksWidget::POSITION_ASIDE || \Yii::$app->user->isGuest
                    || !\Yii::$app->user->can(CmsManager::PERMISSION_ADMIN_ACCESS)) {
                    return;
                }
                $event->blocks['comm-mailing'] = MailingUi::cardBlock($event->owner);
                $event->priority['comm-mailing'] = 150;
            });
        }
        Event::on(CrmContactBlockWidget::class, CrmContactBlockWidget::EVENT_CONTACT_ACTIONS, static function (CrmContactActionsEvent $event) {
            if (\Yii::$app->user->isGuest || !\Yii::$app->skeeks->site || !\Yii::$app->user->can(CmsManager::PERMISSION_ADMIN_ACCESS)) {
                return;
            }
            $mark = MailingUi::contactMarker($event->contactType, $event->value, $event->owner);
            if ($mark !== '') {
                $event->actions = ['comm-optout' => $mark] + $event->actions;
            }
        });

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
