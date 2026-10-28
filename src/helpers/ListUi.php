<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\helpers;

use skeeks\cms\assets\admin\CrmContactsAsset;
use skeeks\cms\backend\actions\BackendModelMultiWindowAction;
use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\backend\widgets\BackendSurfaceWidget;
use skeeks\cms\communication\controllers\AdminCmsCommCampaignController;
use skeeks\cms\communication\forms\ListAddForm;
use skeeks\cms\communication\services\RecipientLists;
use skeeks\cms\widgets\admin\CrmContactBlockWidget;
use yii\db\ActiveQuery;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

/**
 * Lists of recipients in CRM sections and cards, through core places only:
 * the bulk action "В список рассылки" of "Компании", "Лиды", "Клиенты"
 * (actionsMap of their controllers, CommunicationBootstrap), the block
 * "Списки рассылок" of lead, company and client cards (CrmCardBlocksWidget)
 * and the action of the lead card menu.
 */
class ListUi
{
    public static function canUse(): bool
    {
        return !\Yii::$app->user->isGuest && \Yii::$app->user->can(AdminCmsCommCampaignController::PERMISSION);
    }

    /** A base into a list (AudienceImport): rights to mailings and the migrated audience schema. */
    public static function canImportAudience(): bool
    {
        return self::canUse() && \skeeks\cms\communication\models\CmsCommImport::audienceReady();
    }

    /** onclick of a page of this package in the standard backend window. */
    public static function windowOnClick(array $route, ?string $size = null): string
    {
        $action = ['isOpenNewWindow' => true, 'url' => (string)BackendUrlHelper::createByParams($route)->enableEmptyLayout()->url];
        if ($size) {
            $action['size'] = $size;
        }

        return 'new sx.classes.backend.widgets.Action('.Json::encode($action).').go(); return false;';
    }

    /** The window of "Добавить в список" for a card record ("lead:12"). */
    public static function addRecordUrl(string $recordRef): string
    {
        return (string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-list/add-record', 'record' => $recordRef])->enableEmptyLayout()->url;
    }

    /**
     * The bulk action of a CRM section over its own filters: the marked rows
     * or "для всех" (every row of the filtered list). The records are read
     * again through the section's scope.
     *
     * @param string $section companies, leads, clients
     */
    public static function bulkAction(string $section): array
    {
        return [
            'class' => BackendModelMultiWindowAction::class,
            'name' => 'В список рассылки',
            'icon' => 'fas fa-list-ul',
            'priority' => 300,
            'size' => 'small',
            'permissionName' => AdminCmsCommCampaignController::PERMISSION,
            'buttons' => ['save'],
            'successMessage' => 'Готово: записи в списке рассылки.',
            'formModel' => ['class' => ListAddForm::class, 'section' => $section],
            'fields' => [ListAddForm::class, 'formFields'],
            'modelsQueryCallback' => static function (ActiveQuery $query) use ($section) {
                $query->forManager();
                if ($section !== 'companies' && method_exists($query, 'cmsSite')) {
                    $query->cmsSite();
                }
                if ($section === 'clients') {
                    $query->distinct();
                }

                return $query;
            },
            'applyCallback' => static fn($record, ListAddForm $form) => $form->apply($record),
        ];
    }

    /** The block "Списки рассылок" of a card: the lists the record is in and "+ Добавить в список". */
    public static function cardBlock($record): string
    {
        $ref = MailingUi::recordRef($record);
        $site = \Yii::$app->skeeks->site;
        if (!$ref || !$site || !self::canUse()) {
            return '';
        }
        CrmContactsAsset::register(\Yii::$app->view);
        // The markup of CrmContactBlockWidget; its add button would open the window with _sxb[noa], so the block is built here.
        $rows = '';
        foreach (RecipientLists::listsOf($record, (int)$site->id) as $list) {
            $route = ['/cmsCommunication/admin-cms-comm-list/view', 'pk' => (int)$list->id];
            $rows .= Html::tag('div',
                Html::tag('span', CrmContactBlockWidget::icon('users', 16), ['class' => 'sx-contact-icon'])
                .Html::tag('div', Html::tag('span', 'Список', ['class' => 'sx-contact-label'])
                    .Html::tag('span', Html::a(Html::encode($list->name), Url::to($route), ['data-pjax' => 0, 'onclick' => self::windowOnClick($route)]),
                        ['class' => 'sx-contact-value']), ['class' => 'sx-contact-body']),
                ['class' => 'sx-contact-row']);
        }
        if ($rows === '') {
            $rows = Html::tag('div', 'Ни в одном списке', ['class' => 'sx-contact-empty']);
        }
        $rows .= Html::a(CrmContactBlockWidget::icon('plus', 16).' '.Html::encode('Добавить в список'), '#', [
            'class' => 'sx-contact-add',
            'data-pjax' => '0',
            'onclick' => 'new sx.classes.backend.widgets.Action('.Json::encode(['isOpenNewWindow' => true, 'size' => 'small',
                    'url' => self::addRecordUrl($ref)]).').go(); return false;',
        ]);

        return BackendSurfaceWidget::widget([
            'title' => 'Списки рассылок',
            'titleTag' => 'h2',
            'raised' => true,
            'responsive' => true,
            'content' => Html::tag('div', $rows, ['class' => 'sx-contact-list']),
            'actions' => CrmContactBlockWidget::hintIcon('Списки получателей рассылок, в которых есть эта запись. Кампания по списку пишет на её email; '
                .'отписка контакта действует и в списках.'),
        ]);
    }

    /** The lead card menu "Действие": "Добавить в список рассылки". */
    public static function cardAction($record): ?array
    {
        $ref = MailingUi::recordRef($record);
        if (!$ref || !self::canUse()) {
            return null;
        }

        return ['label' => 'Добавить в список рассылки', 'url' => self::addRecordUrl($ref), 'icon' => 'plus'];
    }
}
