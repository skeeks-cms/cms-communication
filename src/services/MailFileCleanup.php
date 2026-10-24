<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use yii\db\Expression;

/**
 * Removes CRM copies of letter files. The letter, its file rows and the
 * mailbox keep everything: a removed copy is fetched from the mailbox when the
 * letter or the file is opened (MailFileFetcher). Never removes a copy of a
 * letter whose place on the mail server is unknown (a letter sent from the
 * CRM not yet found in "Sent", a letter deleted from all mailboxes): there
 * the CRM copy is the only one.
 *
 * Criteria of a manual run: olderThanDays (by letter date), images,
 * attachments, minSizeMb, accountId. The automatic daily run follows each
 * mailbox's storage settings (keepDays by letter date, cacheDays after the
 * last opening of a fetched copy).
 */
class MailFileCleanup
{
    public int $batchSize = 200;

    /** @var callable|null fn(int $done, int $freed): bool — false stops (cancelled); also heartbeat */
    public $onProgress;

    /** Normalized criteria of a manual run. */
    public static function criteria(array $input): array
    {
        return [
            'olderThanDays' => max(0, (int)($input['olderThanDays'] ?? 365)),
            'images' => !empty($input['images']),
            'attachments' => !empty($input['attachments']),
            'minSizeMb' => max(0, (int)($input['minSizeMb'] ?? 0)),
            'accountId' => !empty($input['accountId']) ? (int)$input['accountId'] : null,
        ];
    }

    /** Files with a CRM copy matching manual criteria, of letters the mail server still has. */
    public function manualQuery(array $criteria, int $siteId): \yii\db\ActiveQuery
    {
        $f = CmsCommMessageFile::tableName();
        $m = CmsCommMessage::tableName();
        $query = CmsCommMessageFile::find()
            ->innerJoin($m, $m.'.id = '.$f.'.cms_comm_message_id')
            ->andWhere([$m.'.cms_site_id' => $siteId])
            ->andWhere(['not', [$f.'.storage_file_id' => null]])
            ->andWhere(self::onServer($criteria['accountId'] ?? null));
        if ($criteria['olderThanDays'] > 0) {
            $query->andWhere(['<', $m.'.message_at', time() - $criteria['olderThanDays'] * 86400]);
        }
        if ($criteria['images'] && !$criteria['attachments']) {
            $query->andWhere([$f.'.is_inline' => 1]);
        } elseif ($criteria['attachments'] && !$criteria['images']) {
            $query->andWhere([$f.'.is_inline' => 0]);
        } elseif (!$criteria['images'] && !$criteria['attachments']) {
            $query->andWhere('0=1');
        }
        if ($criteria['minSizeMb'] > 0) {
            $query->andWhere(['>=', $f.'.size', $criteria['minSizeMb'] * 1024 * 1024]);
        }

        return $query;
    }

    /** @return array count, bytes of CRM copies a manual run would remove */
    public function preview(array $criteria, int $siteId): array
    {
        $f = CmsCommMessageFile::tableName();
        $query = $this->manualQuery($criteria, $siteId);

        return [
            'count' => (int)(clone $query)->count($f.'.id'),
            'bytes' => (int)(clone $query)->sum($f.'.size'),
        ];
    }

    /** @return array removed, freed (bytes) */
    public function runManual(array $criteria, int $siteId): array
    {
        return $this->remove($this->manualQuery($criteria, $siteId));
    }

    /**
     * The daily run: for every mailbox of the site its own rules. A letter in
     * several mailboxes is kept as long as the most keeping of them wants.
     *
     * @return array removed, freed (bytes)
     */
    public function runAuto(int $siteId): array
    {
        $f = CmsCommMessageFile::tableName();
        $m = CmsCommMessage::tableName();
        $removed = 0;
        $freed = 0;
        foreach (CmsCommAccount::findMailboxes()->andWhere(['cms_site_id' => $siteId])->all() as $account) {
            $policy = MailFilePolicy::forAccount($account, $siteId);
            $conditions = ['or',
                // Copies fetched on demand: some days after the last opening.
                ['and', [$f.'.is_cached' => 1], ['<', new Expression('COALESCE('.$f.'.last_access_at, '.$f.'.created_at)'), time() - $policy->cacheDays * 86400]],
            ];
            if ($policy->keepDays > 0) {
                $conditions[] = ['and', [$f.'.is_cached' => 0], ['<', $m.'.message_at', time() - $policy->keepDays * 86400]];
            }
            $query = CmsCommMessageFile::find()
                ->innerJoin($m, $m.'.id = '.$f.'.cms_comm_message_id')
                ->andWhere([$m.'.cms_site_id' => $siteId])
                ->andWhere(['not', [$f.'.storage_file_id' => null]])
                ->andWhere(self::onServer((int)$account->id))
                ->andWhere($conditions);
            $result = $this->remove($query, function (CmsCommMessageFile $row) use ($siteId, $policy) {
                // The letter's other mailboxes may keep it longer.
                $accounts = CmsCommAccount::findMailboxes()->andWhere(['id' => CmsCommMessageMailbox::find()
                    ->select('cms_comm_account_id')->andWhere(['cms_comm_message_id' => (int)$row->cms_comm_message_id])])->all();
                $all = MailFilePolicy::forAccounts($accounts, $siteId);
                if ($row->is_cached) {
                    return ((int)($row->last_access_at ?: $row->created_at)) < time() - $all->cacheDays * 86400;
                }
                return $all->keepDays > 0 && (int)$row->message->message_at < time() - $all->keepDays * 86400;
            });
            $removed += $result['removed'];
            $freed += $result['freed'];
            if (!empty($result['stopped'])) {
                break;
            }
        }

        return ['removed' => $removed, 'freed' => $freed];
    }

    /** The letter has a known place on the mail server (in this mailbox, when given). */
    public static function onServer(?int $accountId): array
    {
        $f = CmsCommMessageFile::tableName();
        $copies = CmsCommMessageMailbox::find()->alias('c')
            ->andWhere(new Expression('c.cms_comm_message_id = '.$f.'.cms_comm_message_id'))
            ->andWhere(['not', ['c.uid' => null]])
            ->andWhere(['not', ['c.folder' => null]]);
        if ($accountId) {
            $copies->andWhere(['c.cms_comm_account_id' => $accountId]);
        }

        return ['exists', $copies];
    }

    private function remove(\yii\db\ActiveQuery $query, ?callable $filter = null): array
    {
        $f = CmsCommMessageFile::tableName();
        $removed = 0;
        $freed = 0;
        $lastId = 0;
        while (true) {
            $rows = (clone $query)->andWhere(['>', $f.'.id', $lastId])->orderBy([$f.'.id' => SORT_ASC])
                ->with('message')->limit($this->batchSize)->all();
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int)$row->id;
                if ($filter && !$filter($row)) {
                    continue;
                }
                $freed += MailFiles::forget($row);
                $removed++;
            }
            if ($this->onProgress && call_user_func($this->onProgress, $removed, $freed) === false) {
                return ['removed' => $removed, 'freed' => $freed, 'stopped' => true];
            }
        }

        return ['removed' => $removed, 'freed' => $freed];
    }
}
