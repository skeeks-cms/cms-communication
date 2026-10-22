<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageFile;

/**
 * "Сохранить файлы в CRM": keeps files of earlier letters for good, the
 * reverse of MailFileCleanup. A file without a CRM copy is fetched from the
 * mailbox (each letter is read once for all its files); a copy fetched on
 * demand (is_cached) only stops being temporary. Only letters with a known
 * place on the mail server can be fetched.
 *
 * Criteria: accountId, since (letter date, 0 — any), images, attachments,
 * maxSizeMb (0 — any size).
 */
class MailFileSave
{
    /** Normalized criteria. */
    public static function criteria(array $input): array
    {
        return [
            'accountId' => !empty($input['accountId']) ? (int)$input['accountId'] : null,
            'since' => max(0, (int)($input['since'] ?? 0)),
            'images' => !empty($input['images']),
            'attachments' => !empty($input['attachments']),
            'maxSizeMb' => max(0, (int)($input['maxSizeMb'] ?? 0)),
        ];
    }

    /** Files of the criteria that are not kept for good: without a CRM copy or with a temporary one. */
    public static function query(array $criteria, int $siteId): \yii\db\ActiveQuery
    {
        $f = CmsCommMessageFile::tableName();
        $m = CmsCommMessage::tableName();
        $query = CmsCommMessageFile::find()
            ->innerJoin($m, $m.'.id = '.$f.'.cms_comm_message_id')
            ->andWhere([$m.'.cms_site_id' => $siteId])
            ->andWhere(['or', [$f.'.storage_file_id' => null], [$f.'.is_cached' => 1]])
            ->andWhere(MailFileCleanup::onServer($criteria['accountId'] ?? null));
        if ($criteria['since'] > 0) {
            $query->andWhere(['>=', $m.'.message_at', $criteria['since']]);
        }
        if ($criteria['images'] && !$criteria['attachments']) {
            $query->andWhere([$f.'.is_inline' => 1]);
        } elseif ($criteria['attachments'] && !$criteria['images']) {
            $query->andWhere([$f.'.is_inline' => 0]);
        } elseif (!$criteria['images'] && !$criteria['attachments']) {
            $query->andWhere('0=1');
        }
        if ($criteria['maxSizeMb'] > 0) {
            $query->andWhere(['<=', $f.'.size', $criteria['maxSizeMb'] * 1024 * 1024]);
        }

        return $query;
    }

    /**
     * @return array fetch — files to load from the mailbox, bytes — their size,
     *               cached — temporary copies that become permanent, letters
     */
    public static function preview(array $criteria, int $siteId): array
    {
        $f = CmsCommMessageFile::tableName();
        $query = self::query($criteria, $siteId);
        $missing = (clone $query)->andWhere([$f.'.storage_file_id' => null]);

        return [
            'fetch' => (int)(clone $missing)->count($f.'.id'),
            'bytes' => (int)(clone $missing)->sum($f.'.size'),
            'cached' => (int)(clone $query)->andWhere(['not', [$f.'.storage_file_id' => null]])->count($f.'.id'),
            'letters' => (int)(clone $query)->select($f.'.cms_comm_message_id')->distinct()->count(),
        ];
    }
}
