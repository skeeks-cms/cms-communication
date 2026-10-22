<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;
use skeeks\cms\models\User;
use skeeks\cms\rbac\CmsManager;
use yii\db\ActiveRecord;

/**
 * Sorting letters by hand, one operation each for the "Почта" screen, the
 * future rules and API tools:
 * - link a letter or its whole conversation to a lead, company, client or
 *   deal the employee sees, and unlink it (a record unlinked from the whole
 *   conversation is not linked to its later letters automatically);
 * - the person responsible for the conversation: any active employee who
 *   may read the letter; they get a CmsWebNotify.
 *
 * Links are written by MessageLinker exactly like automatic ones (cms_log of
 * type `message` in the record's feed).
 */
class MessageSorting
{
    private User $user;
    private MessageLinker $linker;

    public function __construct(User $user, ?MessageLinker $linker = null)
    {
        $this->user = $user;
        $this->linker = $linker ?? new MessageLinker();
    }

    /**
     * @param bool $conversation every letter of the conversation, not only this one
     * @return int letters newly linked
     */
    public function link(CmsCommMessage $message, string $type, int $recordId, bool $conversation): int
    {
        if (!in_array($type, CrmRecords::LINKABLE, true)) {
            throw new \InvalidArgumentException('Письмо нельзя привязать к «'.$type.'».');
        }
        $record = CrmRecords::find($type, $recordId, $this->user);
        if (!$record) {
            throw new \DomainException('Запись не найдена или у вас нет к ней доступа.');
        }
        $code = CrmRecords::codeOf($type);

        return $this->transaction(function () use ($message, $record, $code, $conversation) {
            $this->linker->include((int)$message->cms_comm_conversation_id, $code, (int)$record->primaryKey);
            $count = 0;
            foreach ($this->letters($message, $conversation) as $letter) {
                $had = $this->hasLink($letter, $code, (int)$record->primaryKey);
                $this->linker->link($letter, [$record], CmsCommLink::TYPE_MANUAL, (int)$this->user->id);
                $count += $had ? 0 : 1;
            }

            return $count;
        });
    }

    /**
     * @return int letters unlinked
     */
    public function unlink(CmsCommMessage $message, string $modelCode, int $recordId, bool $conversation): int
    {
        if (!$this->canSeeRecord($modelCode, $recordId)) {
            throw new \DomainException('Запись не найдена или у вас нет к ней доступа.');
        }

        return $this->transaction(function () use ($message, $modelCode, $recordId, $conversation) {
            $count = 0;
            foreach ($this->letters($message, $conversation) as $letter) {
                $count += (int)$this->linker->unlink($letter, $modelCode, $recordId);
            }
            if ($conversation) {
                $this->linker->exclude((int)$message->cms_comm_conversation_id, $modelCode, $recordId, (int)$this->user->id);
            }

            return $count;
        });
    }

    /**
     * Active employees who may read the letter: the choice of a responsible person.
     *
     * @return CmsUser[]
     */
    public function assignableUsers(CmsCommMessage $message): array
    {
        $result = [];
        /** @var CmsUser $worker */
        foreach (CmsUser::find()->andWhere(['is_worker' => 1, 'is_active' => 1])->orderBy(['first_name' => SORT_ASC, 'last_name' => SORT_ASC])->all() as $worker) {
            if ($this->canRead($worker, $message)) {
                $result[(int)$worker->id] = $worker;
            }
        }

        return $result;
    }

    /**
     * Sets or clears (null) the person responsible for the letter's conversation.
     *
     * @return bool whether it changed
     */
    public function assign(CmsCommMessage $message, ?int $userId): bool
    {
        $conversation = $message->conversation;
        if (!$conversation) {
            throw new \DomainException('Переписка не найдена.');
        }
        $assignee = null;
        if ($userId) {
            $assignee = CmsUser::find()->andWhere(['id' => $userId, 'is_worker' => 1, 'is_active' => 1])->one();
            if (!$assignee || !$this->canRead($assignee, $message)) {
                throw new \DomainException('Этому сотруднику письмо не видно: выберите того, у кого есть доступ к ящику или к клиенту.');
            }
        }
        if ((int)$conversation->assigned_user_id === (int)$userId) {
            return false;
        }

        return $this->transaction(function () use ($conversation, $assignee, $message) {
            $conversation->assigned_user_id = $assignee ? (int)$assignee->id : null;
            $attributes = ['assigned_user_id', 'updated_at'];
            if ($conversation->hasAttribute('assigned_at')) {
                $conversation->assigned_at = $assignee ? time() : null;
                $conversation->assigned_by = $assignee ? (int)$this->user->id : null;
                $attributes = array_merge($attributes, ['assigned_at', 'assigned_by']);
            }
            if (!$conversation->save(false, $attributes)) {
                throw new \RuntimeException('Не удалось назначить ответственного.');
            }
            if ($assignee && (int)$assignee->id !== (int)$this->user->id) {
                $this->notifyAssignee($assignee, $message);
            }

            return true;
        });
    }

    /** "Вам назначена переписка": opens the letter like a new-letter notification. */
    private function notifyAssignee(CmsUser $assignee, CmsCommMessage $message): void
    {
        $notify = new CmsWebNotify();
        $notify->cms_user_id = (int)$assignee->id;
        $notify->name = mb_substr('Вас назначили ответственным за переписку «'.$message->getAsText().'» ('.$this->user->shortDisplayName.')', 0, 250);
        $notify->model_code = MailNotifier::MODEL_CODE;
        $notify->model_id = (int)$message->id;
        if (!$notify->save()) {
            throw new \RuntimeException('Не удалось уведомить сотрудника.');
        }
    }

    /**
     * Records created from the letter that the employee sees:
     * [type, record] for "Задача №…" marks.
     *
     * @return array[]
     */
    public function createdRecords(CmsCommMessage $message): array
    {
        $result = [];
        foreach ($message->links as $link) {
            if ($link->link_type !== CmsCommLink::TYPE_CREATED) {
                continue;
            }
            $type = CrmRecords::typeOfCode((string)$link->model_code);
            if ($type && ($record = CrmRecords::find($type, (int)$link->model_id, $this->user))) {
                $result[] = ['type' => $type, 'record' => $record];
            }
        }

        return $result;
    }

    public function canSeeRecord(string $modelCode, int $recordId): bool
    {
        return CrmRecords::isVisible($modelCode, $recordId, $this->user);
    }

    private function canRead(User $user, CmsCommMessage $message): bool
    {
        if (\Yii::$app->authManager->checkAccess($user->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            return true;
        }

        return CmsCommMessage::find()->forManager($user)
            ->andWhere([CmsCommMessage::tableName().'.id' => (int)$message->id])
            ->exists();
    }

    /** @return CmsCommMessage[] */
    private function letters(CmsCommMessage $message, bool $conversation): array
    {
        if (!$conversation) {
            return [$message];
        }

        return CmsCommMessage::find()
            ->andWhere(['cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id])
            ->orderBy(['message_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    private function hasLink(CmsCommMessage $message, string $code, int $id): bool
    {
        return CmsCommLink::find()->andWhere(['cms_comm_message_id' => (int)$message->id, 'model_code' => $code, 'model_id' => $id])->exists();
    }

    private function transaction(callable $callback)
    {
        $transaction = CmsCommMessage::getDb()->beginTransaction();
        try {
            $result = $callback();
            $transaction->commit();
            return $result;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
