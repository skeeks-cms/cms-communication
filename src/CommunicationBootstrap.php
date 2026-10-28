<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication;

use skeeks\cms\communication\assets\VisitAsset;
use skeeks\cms\communication\helpers\MailingUi;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\services\LinkMarks;
use skeeks\cms\communication\services\LinkVisits;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\rbac\CmsManager;
use skeeks\cms\widgets\admin\CrmCardBlocksEvent;
use skeeks\cms\widgets\admin\CrmCardBlocksWidget;
use skeeks\cms\widgets\admin\CrmContactActionsEvent;
use skeeks\cms\widgets\admin\CrmContactBlockWidget;
use yii\base\BootstrapInterface;
use yii\base\Event;
use yii\web\View;

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
            // A chat of a bot ("Новое сообщение от …", "Вам передан диалог") opens in "Чаты".
            $app->skeeks->modelsConfig[\skeeks\cms\communication\models\CmsCommConversation::class] = [
                'name' => 'Чаты',
                'name_one' => 'Чат',
                'controller' => 'cmsCommunication/admin-cms-comm-chat',
            ];
            // "Готов импорт №…", "Вам назначены лиды из импорта №…" open the import card.
            $app->skeeks->modelsConfig[\skeeks\cms\communication\models\CmsCommImport::class] = [
                'name' => 'Импорт в сделки',
                'name_one' => 'Импорт',
                'controller' => 'cmsCommunication/admin-cms-comm-import',
            ];
        }

        // A task asked for in a chat by "/task": the chat hears when it is accepted, done or cancelled.
        Event::on(\skeeks\cms\models\CmsTask::class, \yii\db\ActiveRecord::EVENT_AFTER_UPDATE, [\skeeks\cms\communication\chat\ChatTasks::class, 'onTaskUpdate']);

        if ($app instanceof \yii\web\Application) {
            // Visits by links of letters (step F): fixed endpoints go before the CMS tree rule, whose fallback
            // would take the last part of the path as a section code (client-link-pages of the package skill).
            $app->urlManager->addRules([
                'POST ~comm-visit/<action:(seen|page)>' => 'cmsCommunication/visit/<action>',
                '~comm-visit/visit.js' => 'cmsCommunication/visit/script',
            ], false);
            $app->view->on(View::EVENT_END_BODY, [self::class, 'registerVisitScript']);
            // The canonical address of skeeks/cms-seo redirects away unknown parameters: the code must reach the page script.
            $app->on(\yii\base\Application::EVENT_BEFORE_ACTION, static function () use ($app) {
                if ($app->has('seo') && !(class_exists(\skeeks\cms\backend\BackendComponent::class) && \skeeks\cms\backend\BackendComponent::getCurrent())
                    && ($canUrl = $app->seo->canUrl) && method_exists($canUrl, 'ADDminor_pname')) {
                    $canUrl->ADDminor_pname(LinkMarks::CODE_PARAM);
                }
            });
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
                'icon' => 'fa fa-upload',
                'priority' => 28,
                'callback' => [\skeeks\cms\communication\controllers\AdminCmsCommImportController::class, 'renderLeadsTab'],
            ];
            \Yii::$container->set($leads, $definition);
        }
        // "Импорт" — a tab of "Компании": a cold base becomes companies and contacts of a list, without deals.
        $companies = \skeeks\cms\controllers\AdminCmsCompanyController::class;
        if (class_exists($companies)) {
            $definition = \Yii::$container->getDefinitions()[$companies] ?? [];
            $definition['actionsMap']['comm-import'] = [
                'class' => \skeeks\cms\backend\ViewBackendAction::class,
                'name' => 'Импорт',
                'icon' => 'fa fa-upload',
                'priority' => 900,
                'accessCallback' => [\skeeks\cms\communication\helpers\ListUi::class, 'canImportAudience'],
                'callback' => [\skeeks\cms\communication\controllers\AdminCmsCommImportController::class, 'renderCompaniesTab'],
            ];
            \Yii::$container->set($companies, $definition);
        }

        // Lists of recipients: the bulk action "В список рассылки" of the core sections over their own filters
        // (marked rows or "для всех"), added the same way; the leads table gets its checkbox column by default.
        foreach (['leads' => $leads, 'companies' => \skeeks\cms\controllers\AdminCmsCompanyController::class,
                     'clients' => \skeeks\cms\controllers\AdminUserController::class] as $section => $controller) {
            if (!class_exists($controller)) {
                continue;
            }
            $definition = \Yii::$container->getDefinitions()[$controller] ?? [];
            $definition['actionsMap']['comm-list-add'] = \skeeks\cms\communication\helpers\ListUi::bulkAction($section);
            if ($section === 'leads') {
                $definition['actionsMap']['index']['grid']['visibleColumns'] = ['checkbox'];
            }
            \Yii::$container->set($controller, $definition);
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
                // "Мессенджеры": chats of bots linked to the record, "Пригласить в бота".
                $event->blocks['comm-chats'] = \skeeks\cms\communication\helpers\ChatUi::cardBlock($event->owner);
                $event->priority['comm-chats'] = 140;
                // "Списки рассылок": the lists the record is in, "+ Добавить в список".
                $event->blocks['comm-lists'] = \skeeks\cms\communication\helpers\ListUi::cardBlock($event->owner);
                $event->priority['comm-lists'] = 155;
            });
        }
        // «Каналы связи» of a client person ("Сделка как услуга"): mailing state of emails and phones, chats with bots.
        if (defined(\skeeks\cms\widgets\admin\ClientChannelsWidget::class.'::EVENT_CHANNELS')) {
            Event::on(\skeeks\cms\widgets\admin\ClientChannelsWidget::class, \skeeks\cms\widgets\admin\ClientChannelsWidget::EVENT_CHANNELS, static function ($event) {
                if (!\Yii::$app->user->isGuest && \Yii::$app->user->can(CmsManager::PERMISSION_ADMIN_ACCESS)) {
                    \skeeks\cms\communication\helpers\ClientChannels::fill($event);
                }
            });
        }
        // "Добавить в список рассылки" in the menu "Действие" of the lead card; an older core has no actions event.
        if (defined(CrmCardBlocksWidget::class.'::EVENT_ACTIONS')) {
            Event::on(CrmCardBlocksWidget::class, CrmCardBlocksWidget::EVENT_ACTIONS, static function (CrmCardBlocksEvent $event) {
                if ($action = \skeeks\cms\communication\helpers\ListUi::cardAction($event->owner)) {
                    $event->actions['comm-list-add'] = $action;
                    $event->priority['comm-list-add'] = 300;
                }
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

    /**
     * The visit script on a page of the site opened by a marked link of a
     * letter (`?sx=`) or within such a visit (cookie `sx_visit`): every other
     * page, the administration and AJAX answers load nothing.
     */
    public static function registerVisitScript(Event $event): void
    {
        $request = \Yii::$app->request;
        if (!$request instanceof \yii\web\Request || !$request->isGet || $request->isAjax || $request->isPjax
            || (class_exists(\skeeks\cms\backend\BackendComponent::class) && \skeeks\cms\backend\BackendComponent::getCurrent())) {
            return;
        }
        $code = $request->get(LinkMarks::CODE_PARAM);
        $cookie = $_COOKIE[LinkVisits::COOKIE] ?? null;
        if (!(is_string($code) && preg_match('~^'.LinkMarks::CODE_PATTERN.'$~', $code)) && !(is_string($cookie) && preg_match('~^[A-Za-z0-9_-]{20,48}$~', $cookie))) {
            return;
        }
        if ($event->sender instanceof View) {
            VisitAsset::register($event->sender);
        }
    }
}
