<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;

/**
 * Notifies employees about new incoming letters (CmsWebNotify, the standard
 * notification center). Called by the receive job with the letters one run
 * has created; letters collected from the history, the first receipt of a
 * folder, outgoing letters and letters already read are never notified.
 *
 * Recipients: employees with an access row on a mailbox of the letter who
 * read it ("все письма", or "связанные" when the letter is visible to them by
 * CmsCommMessageQuery::forManager()) and did not switch the notification off
 * for that mailbox (CmsCommAccountAccess::$is_notify). Administrators without
 * an access row are not notified: reading every mailbox is not a request for
 * every letter. Several letters of one run make one notification per
 * employee.
 */
class MailNotifier
{
    /** model_code of a notification: the letter it opens; opening the letter marks it read. */
    public const MODEL_CODE = CmsCommMessage::class;

    /**
     * @param int[] $messageIds letters created by one receive run
     * @return int notifications created
     */
    public function notifyNew(array $messageIds): int
    {
        $messages = $messageIds ? CmsCommMessage::find()
            ->andWhere(['id' => array_values(array_unique(array_map('intval', $messageIds)))])
            ->andWhere(['direction' => CmsCommMessage::DIRECTION_IN, 'is_history' => 0])
            ->orderBy(['message_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all() : [];
        if (!$messages) {
            return 0;
        }

        $byUser = [];
        foreach ($this->recipients($messages) as $userId => $ids) {
            $byUser[$userId] = array_values(array_filter($messages, static fn($m) => in_array((int)$m->id, $ids, true)));
        }

        $created = 0;
        foreach ($byUser as $userId => $letters) {
            if ($letters && $this->notify((int)$userId, $letters)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param CmsCommMessage[] $messages
     * @return array user id => ids of letters to notify about
     */
    public function recipients(array $messages): array
    {
        $ids = array_map(static fn($m) => (int)$m->id, $messages);
        // Mailboxes of every letter: its copies and the mailbox it came to.
        $accountsOf = [];
        foreach ($messages as $message) {
            if ($message->cms_comm_account_id) {
                $accountsOf[(int)$message->id][] = (int)$message->cms_comm_account_id;
            }
        }
        foreach (CmsCommMessageMailbox::find()->select(['cms_comm_message_id', 'cms_comm_account_id'])
            ->andWhere(['cms_comm_message_id' => $ids])->asArray()->all() as $row) {
            $accountsOf[(int)$row['cms_comm_message_id']][] = (int)$row['cms_comm_account_id'];
        }
        $accountIds = array_values(array_unique(array_merge([], ...array_values($accountsOf))));
        if (!$accountIds) {
            return [];
        }

        $activeAccounts = CmsCommAccount::find()->select('id')->andWhere(['id' => $accountIds, 'is_active' => 1])->column();
        $accesses = CmsCommAccountAccess::find()
            ->andWhere(['cms_comm_account_id' => $activeAccounts ?: [0], 'is_notify' => 1])
            ->andWhere(['read_level' => [CmsCommAccountAccess::READ_ALL, CmsCommAccountAccess::READ_LINKED]])
            ->andWhere(['cms_user_id' => CmsUser::find()->select('id')->andWhere(['is_active' => 1, 'is_worker' => 1])])
            ->all();

        $read = [];
        foreach ((new \yii\db\Query())->select(['cms_comm_message_id', 'cms_user_id'])->from('{{%cms_comm_message_read}}')
            ->andWhere(['cms_comm_message_id' => $ids])->all() as $row) {
            $read[(int)$row['cms_user_id']][(int)$row['cms_comm_message_id']] = true;
        }

        $result = [];
        $linkedChecks = [];
        foreach ($accesses as $access) {
            $userId = (int)$access->cms_user_id;
            foreach ($messages as $message) {
                $id = (int)$message->id;
                if (!in_array((int)$access->cms_comm_account_id, $accountsOf[$id] ?? [], true) || isset($read[$userId][$id])) {
                    continue;
                }
                if ($access->read_level === CmsCommAccountAccess::READ_ALL) {
                    $result[$userId][$id] = true;
                } else {
                    $linkedChecks[$userId][$id] = true;
                }
            }
        }

        // "Только связанные": the letter must be visible to the employee, as in the section itself.
        foreach ($linkedChecks as $userId => $candidates) {
            $candidates = array_diff_key($candidates, $result[$userId] ?? []);
            $user = $candidates ? \skeeks\cms\models\User::findOne($userId) : null;
            if (!$user) {
                continue;
            }
            try {
                $visible = self::withUserComponent(get_class($user), static fn() => CmsCommMessage::find()->forManager($user)
                    ->andWhere([CmsCommMessage::tableName().'.id' => array_keys($candidates)])
                    ->select(CmsCommMessage::tableName().'.id')->column());
            } catch (\Throwable $e) {
                \Yii::warning('Видимость писем для сотрудника #'.$userId.' не проверена: '.$e->getMessage(), self::class);
                continue;
            }
            foreach ($visible as $id) {
                $result[$userId][(int)$id] = true;
            }
        }

        return array_map(static fn($set) => array_map('intval', array_keys($set)), $result);
    }

    /**
     * One notification about the letters of a run. It refers to the (last)
     * letter as its model: the notification center links it through
     * skeeks->modelsConfig and opens the letter of "Почта" in the panel. No
     * stored URL: the receive job runs in the console, where the project's
     * backend URL prefix is not known.
     *
     * @param CmsCommMessage[] $letters
     */
    public function notify(int $userId, array $letters): bool
    {
        $last = end($letters);
        $from = $last->from_name ?: (string)$last->from_address;
        $count = count($letters);
        $notify = new CmsWebNotify();
        $notify->cms_user_id = $userId;
        $notify->name = mb_substr($count === 1
            ? 'Новое письмо от '.$from
            : $count.' '.self::plural($count, 'новое письмо', 'новых письма', 'новых писем').', последнее от '.$from, 0, 250);
        $notify->model_code = self::MODEL_CODE;
        $notify->model_id = (int)$last->id;

        return $notify->save();
    }

    /**
     * Runs a forManager() check of a given user in the console (the receive
     * job). Core relations of CRM records (CmsCompany::getManagers(),
     * User::getSubordinates() and others) read the user class from the "user"
     * application component, which a console application does not have: a
     * session-less component with only that class is set for the call.
     */
    public static function withUserComponent(string $identityClass, callable $callback)
    {
        if (\Yii::$app->has('user')) {
            return $callback();
        }
        \Yii::$app->set('user', [
            'class' => \yii\web\User::class,
            'identityClass' => $identityClass,
            'enableSession' => false,
            'enableAutoLogin' => false,
        ]);
        try {
            return $callback();
        } finally {
            \Yii::$app->clear('user');
        }
    }

    /** Opening a letter marks its notification read. */
    public static function markRead(int $userId, int $messageId): void
    {
        CmsWebNotify::updateAll(['is_read' => 1], [
            'cms_user_id' => $userId,
            'model_code' => self::MODEL_CODE,
            'model_id' => $messageId,
            'is_read' => 0,
        ]);
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        return $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20) ? $few : $many;
    }
}
