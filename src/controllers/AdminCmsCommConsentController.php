<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\controllers;

use skeeks\cms\backend\BackendAction;
use skeeks\cms\backend\BackendController;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\models\CmsLead;
use skeeks\cms\rbac\CmsManager;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * "Подписки на рассылки" of one email or phone of a lead, company or
 * client, opened from the card: the state by topics with the basis of the
 * consent, the stop list; a manager marks a consent (with its basis) or an
 * opt-out. Only for records the employee sees; a lead only for those who
 * may work with it, as its contacts.
 */
class AdminCmsCommConsentController extends BackendController
{
    public function init()
    {
        $this->name = 'Подписки на рассылки';
        $this->generateAccessActions = false;
        $this->permissionNames = [CmsManager::PERMISSION_ADMIN_ACCESS => 'Доступ к системе администрирования'];
        $this->defaultAction = 'contact';

        parent::init();
    }

    public function actions()
    {
        return [
            'contact' => [
                'class' => BackendAction::class,
                'name' => 'Подписки на рассылки',
                'isVisible' => false,
                'callback' => [$this, 'contact'],
            ],
        ];
    }

    public function contact()
    {
        $request = \Yii::$app->request;
        $user = \Yii::$app->user->identity;
        $siteId = (int)\Yii::$app->skeeks->site->id;
        [$type, $id] = array_pad(explode(':', (string)$request->get('record'), 2), 2, 0);
        $record = CrmRecords::find((string)$type, (int)$id, $user);
        $kind = (string)$request->get('kind');
        $value = CmsCommContact::normalize($kind, (string)$request->get('value'));
        if (!$record || !in_array($type, [CrmRecords::LEAD, CrmRecords::COMPANY, CrmRecords::CLIENT], true) || $value === null) {
            throw new NotFoundHttpException('Контакт не найден или у вас нет к нему доступа.');
        }
        $known = false;
        foreach (MailingContacts::ofRecord($record) as $row) {
            $known = $known || ($row['kind'] === $kind && $row['value'] === $value);
        }
        if (!$known) {
            throw new NotFoundHttpException('У записи нет такого контакта.');
        }
        $canEdit = !$record instanceof CmsLead || $record->canBeWorkedBy((int)$user->id);

        $topics = CmsCommTopic::forSite($siteId);
        $contact = MailingContacts::find($siteId, $kind, $value);
        $saved = false;
        if ($request->isPost) {
            if (!$canEdit) {
                throw new ForbiddenHttpException('Подписки контакта лида меняет ответственный менеджер или администратор.');
            }
            $contact = $contact ?: MailingContacts::get($siteId, $kind, $value);
            $this->save($contact, $topics, (array)$request->post('topics', []), (array)$request->post('suppression', []), (string)$request->post('note'), (int)$user->id);
            $saved = true;
        }

        return $this->render('@skeeks/cms/communication/views/consent/contact', [
            'record' => $record,
            'kind' => $kind,
            'value' => $value,
            'display' => $contact ? $contact->display() : ($kind === CmsCommContact::KIND_PHONE ? \skeeks\cms\communication\services\SendContacts::formatPhone($value) : $value),
            'topics' => $topics,
            'state' => MailingConsents::state($contact),
            'canEdit' => $canEdit,
            'saved' => $saved,
        ]);
    }

    /**
     * The manager's choice per topic: "" (no marks), a basis of consent or
     * "unsubscribed"; the stop list reasons that stay checked.
     */
    private function save(CmsCommContact $contact, array $topics, array $choices, array $suppression, string $note, int $userId): void
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $state = MailingConsents::state($contact);
            foreach ($topics as $topic) {
                $id = (int)$topic->id;
                $choice = (string)($choices[$id] ?? '');
                $row = $state['topics'][$id] ?? null;
                if ($choice === CmsCommContactTopic::STATUS_UNSUBSCRIBED) {
                    MailingConsents::unsubscribe($contact, $id, CmsCommContactTopic::SOURCE_MANAGER, $userId);
                } elseif (isset(CmsCommContactTopic::bases()[$choice])) {
                    // An explicit choice of the manager: a consent given again after an opt-out counts.
                    MailingConsents::subscribe($contact, $id, $choice, CmsCommContactTopic::SOURCE_MANAGER, $userId,
                        $row && $row->isSubscribed() && $row->basis === $choice ? $row->note : $note, true);
                } elseif ($choice === '' && $row) {
                    MailingConsents::clear($contact, $id);
                }
            }
            $keep = array_flip(array_map('strval', $suppression));
            foreach (array_keys(CmsCommSuppression::reasons()) as $reason) {
                $has = isset($state['suppressions'][$reason]);
                if (isset($keep[$reason]) && !$has) {
                    MailingConsents::suppress($contact, $reason, CmsCommContactTopic::SOURCE_MANAGER, $userId, null, $note);
                } elseif (!isset($keep[$reason]) && $has) {
                    MailingConsents::unsuppress($contact, $reason);
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
