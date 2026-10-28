<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommListMember;
use skeeks\cms\crm\CrmMatcher;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsUser2manager;
use skeeks\cms\models\CmsUserEmail;
use skeeks\cms\models\CmsUserPhone;

/**
 * Old members of lists that are deals (record_type lead) become companies
 * and contacts, the audience of the new model (П5). Repeatable: a moved
 * member is marked removed, its card joins the same list with the same
 * author and origin; a second run finds nothing to move. Deals are never
 * deleted or changed.
 *
 * The card of a member: the deal's company, else its contact, else one card
 * the core duplicate check finds by the deal's emails and phones (several —
 * left for a person), else a new contact with the deal's emails and phones,
 * the deal's responsible (or the list's author) responsible for it, the
 * source «Рассылка: перенос из сделки №…».
 *
 *   php yii comm-audience/lead-members            # what would be done
 *   php yii comm-audience/lead-members --apply=1  # do it
 */
class AudienceMigration
{
    /**
     * @return array[] one row per member: list, lead, action (company, client, found, created, conflict, empty, missing), target "type:id", note
     */
    public static function leadMembers(bool $apply): array
    {
        $result = [];
        $members = CmsCommListMember::find()->andWhere(['record_type' => CrmRecords::LEAD, 'removed_at' => null])->orderBy(['id' => SORT_ASC])->all();
        foreach ($members as $member) {
            $list = CmsCommList::findOne((int)$member->cms_comm_list_id);
            $lead = CmsLead::findOne((int)$member->record_id);
            $row = ['list' => (int)$member->cms_comm_list_id, 'lead' => (int)$member->record_id, 'action' => '', 'target' => '', 'note' => ''];
            if (!$list || !$lead) {
                $row['action'] = 'missing';
                $row['note'] = 'Сделка или список удалены — участник остаётся как есть.';
                $result[] = $row;
                continue;
            }
            $transaction = \Yii::$app->db->beginTransaction();
            try {
                [$card, $row['action'], $row['note']] = self::cardOf($lead, $list, $apply);
                if ($card) {
                    $row['target'] = RecipientLists::recordType($card).':'.(int)$card->primaryKey;
                    if ($apply) {
                        self::move($member, $list, $card);
                    }
                }
                $apply ? $transaction->commit() : $transaction->rollBack();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                $row['action'] = 'error';
                $row['note'] = $e->getMessage();
            }
            $result[] = $row;
        }

        return $result;
    }

    /** @return array [card or null, action, note] */
    private static function cardOf(CmsLead $lead, CmsCommList $list, bool $apply): array
    {
        if ($lead->cms_company_id && ($company = CmsCompany::findOne((int)$lead->cms_company_id))) {
            return [$company, 'company', 'Компания сделки'];
        }
        if ($lead->cms_user_id && ($client = CmsUser::findOne((int)$lead->cms_user_id)) && !$client->is_worker) {
            return [$client, 'client', 'Контакт сделки'];
        }
        $emails = array_map(static fn($e) => (string)$e->value, $lead->emails);
        $phones = array_map(static fn($p) => (string)$p->value, $lead->phones);
        if (!$emails && !$phones) {
            return [null, 'empty', 'У сделки нет email и телефона — перенести нечего.'];
        }
        $found = CrmMatcher::instance()->rawFind(['siteId' => (int)$lead->cms_site_id, 'emails' => $emails, 'phones' => $phones,
            'types' => [CrmMatcher::TYPE_COMPANY, CrmMatcher::TYPE_CLIENT]]);
        $strong = ['company' => [], 'client' => []];
        foreach ($found as $candidate) {
            if (!empty($candidate['strong'])) {
                $strong[$candidate['type']][] = (int)$candidate['id'];
            }
        }
        if (count($strong['company']) > 1 || (!$strong['company'] && count($strong['client']) > 1)) {
            return [null, 'conflict', 'Контакты сделки совпадают с несколькими карточками — разберите вручную.'];
        }
        if ($strong['company']) {
            return [CmsCompany::findOne($strong['company'][0]), 'found', 'Найдена компания по контактам сделки'];
        }
        if ($strong['client']) {
            return [CmsUser::findOne($strong['client'][0]), 'found', 'Найден контакт по контактам сделки'];
        }
        if (!$apply) {
            return [null, 'created', 'Будет создан контакт: '.implode(', ', array_merge($emails, $phones))];
        }

        $name = '';
        foreach (array_merge($lead->emails, $lead->phones) as $contact) {
            if (trim((string)$contact->name) !== '') {
                $name = trim((string)$contact->name);
                break;
            }
        }
        $person = new CmsUser(['is_worker' => 0, 'cms_site_id' => (int)$lead->cms_site_id] + AudienceImport::splitName($name));
        // The main email and phone through the core: a contact without a name is shown by them.
        if ($emails) {
            $person->email = mb_strtolower((string)array_shift($emails), 'UTF-8');
        }
        if ($phones) {
            $person->phone = (string)array_shift($phones);
        }
        if (!$person->save()) {
            throw new \RuntimeException('Не удалось создать контакт: '.implode('; ', $person->getFirstErrors()));
        }
        $managerId = (int)($lead->executor_id ?: $list->created_by);
        if ($managerId) {
            (new CmsUser2manager(['client_id' => (int)$person->id, 'worker_id' => $managerId]))->save(false);
        }
        foreach ($emails as $email) {
            (new CmsUserEmail(['cms_user_id' => (int)$person->id, 'cms_site_id' => (int)$lead->cms_site_id, 'value' => mb_strtolower($email, 'UTF-8')]))->save();
        }
        foreach ($phones as $phone) {
            (new CmsUserPhone(['cms_user_id' => (int)$person->id, 'cms_site_id' => (int)$lead->cms_site_id, 'value' => $phone]))->save();
        }
        Audience::origin((int)$lead->cms_site_id, $person, 'Рассылка: перенос из сделки №'.(int)$lead->id);

        return [$person, 'created', 'Создан контакт из сделки'];
    }

    /** The card joins the list in the member's place: the same author, way and origin; the deal member is marked removed. */
    private static function move(CmsCommListMember $member, CmsCommList $list, \yii\db\ActiveRecord $card): void
    {
        $type = RecipientLists::recordType($card);
        $existing = CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$list->id, 'record_type' => $type, 'record_id' => (int)$card->primaryKey])->one();
        if (!$existing) {
            $existing = new CmsCommListMember([
                'cms_comm_list_id' => (int)$list->id,
                'record_type' => $type,
                'record_id' => (int)$card->primaryKey,
                'added_by' => $member->added_by,
                'added_via' => $member->added_via,
                'added_ref' => $member->added_ref,
            ]);
            $existing->save(false);
        } elseif ($existing->removed_at && !$member->removed_at) {
            // Removed by hand earlier: the deal member was the active one, the card comes back in its place.
            $existing->removed_at = null;
            $existing->removed_by = null;
            $existing->save(false, ['removed_at', 'removed_by', 'updated_at']);
        }
        CmsCommListMember::updateAll(['removed_at' => time(), 'updated_at' => time()], ['id' => (int)$member->id, 'removed_at' => null]);
    }
}
