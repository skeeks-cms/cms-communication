<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\helpers;

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\communication\services\SendContacts;
use skeeks\cms\widgets\admin\CrmContactBlockWidget;
use yii\db\ActiveRecord;
use yii\helpers\Html;
use yii\helpers\Json;

/**
 * Mailings in CRM cards, through the core places only (CrmCardBlocksWidget,
 * CrmContactBlockWidget): the block "Рассылки" with the state of every email
 * and phone of the card by topics, and a mark at a contact row that is on
 * the stop list.
 */
class MailingUi
{
    /** "lead:12" of a lead, company or client card; null for other records. */
    public static function recordRef($record): ?string
    {
        if (!$record instanceof ActiveRecord) {
            return null;
        }
        $code = method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record);
        $type = CrmRecords::typeOfCode($code);
        if (!in_array($type, [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT], true)) {
            return null;
        }
        if ($type === CrmRecords::CLIENT && $record->is_worker) {
            return null;
        }

        return $type.':'.$record->primaryKey;
    }

    /** onclick of the window "Подписки на рассылки" of one contact of the card. */
    public static function consentOnClick(string $recordRef, string $kind, string $value): string
    {
        $url = (string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-consent/contact',
            'record' => $recordRef, 'kind' => $kind, 'value' => $value])->enableEmptyLayout()->url;

        return 'new sx.classes.backend.widgets.Action('.Json::encode(['isOpenNewWindow' => true, 'url' => $url, 'size' => 'small']).').go(); return false;';
    }

    /**
     * onclick of an import card in the standard backend window (the tab
     * "Импорт" of the leads section); the tab reloads when the window that
     * ran the import closes.
     */
    public static function importOnClick(int $importId): string
    {
        $url = (string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-import/view', 'pk' => $importId])->enableEmptyLayout()->url;

        return 'new sx.classes.backend.widgets.Action('.Json::encode(['isOpenNewWindow' => true, 'url' => $url]).').go(); return false;';
    }

    /** onclick of "Загрузить таблицу": step 1 of an import in the standard backend window. */
    public static function importUploadOnClick(): string
    {
        $url = (string)BackendUrlHelper::createByParams(['/cmsCommunication/admin-cms-comm-import/create'])->enableEmptyLayout()->url;

        return 'new sx.classes.backend.widgets.Action('.Json::encode(['isOpenNewWindow' => true, 'url' => $url]).').go(); return false;';
    }

    /** The block "Рассылки" of a card, or "" when there is nothing to show. */
    public static function cardBlock($record): string
    {
        $ref = self::recordRef($record);
        $siteId = \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : 0;
        if (!$ref || !$siteId) {
            return '';
        }
        $contacts = MailingContacts::ofRecord($record);
        if (!$contacts) {
            return '';
        }
        $topics = CmsCommTopic::forSite($siteId);
        $items = [];
        $anyMarks = false;
        foreach ($contacts as $row) {
            $contact = MailingContacts::find($siteId, $row['kind'], $row['value']);
            $lines = MailingConsents::summary($contact, $topics);
            $anyMarks = $anyMarks || (bool)$lines;
            $display = $row['kind'] === CmsCommContact::KIND_PHONE ? SendContacts::formatPhone($row['value']) : $row['value'];
            $items[] = [
                'label' => $row['kind'] === CmsCommContact::KIND_PHONE ? 'Телефон' : 'Email',
                'value' => Html::encode($display),
                'note' => $lines ? implode(' · ', $lines) : 'Нет отметок о согласии и отписке',
                'actions' => CrmContactBlockWidget::iconButton('edit', 'Подписки на рассылки', [
                    'onclick' => self::consentOnClick($ref, $row['kind'], $row['value']),
                ]),
            ];
        }
        // Without topics and marks the block would only say "nothing": the site does not run mailings.
        if (!$topics && !$anyMarks) {
            return '';
        }

        return CrmContactBlockWidget::widget([
            'title' => 'Рассылки',
            'hint' => 'Согласие на рекламные рассылки и отписка по темам у каждого email и телефона. Отписка не мешает служебным и личным письмам.',
            'icon' => 'send',
            'items' => $items,
        ]);
    }

    /**
     * A mark at a contact row of a card when the contact is on the stop list
     * (opted out of all advertising, complained, undeliverable).
     */
    public static function contactMarker(string $contactType, string $value, $owner): string
    {
        $kind = $contactType === CrmContactBlockWidget::CONTACT_PHONE ? CmsCommContact::KIND_PHONE : CmsCommContact::KIND_EMAIL;
        $ref = self::recordRef($owner);
        $siteId = \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : 0;
        if (!$ref || !$siteId || !($contact = MailingContacts::find($siteId, $kind, $value))) {
            return '';
        }
        $reasons = array_map(static fn(CmsCommSuppression $s) => $s->reasonText(), MailingConsents::state($contact)['suppressions']);
        if (!$reasons) {
            return '';
        }

        return CrmContactBlockWidget::iconButton('stop', implode('; ', $reasons).'. Рекламные рассылки сюда не уходят.', [
            'class' => 'sx-comm-optout-mark',
            // Card pages do not load the mail styles: the color travels with the button.
            'style' => 'color: var(--sx-color-danger, #d64545)',
            'onclick' => self::consentOnClick($ref, $kind, (string)$contact->value),
        ]);
    }
}
