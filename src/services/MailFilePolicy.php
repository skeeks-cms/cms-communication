<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\components\MailFileSettings;
use skeeks\cms\communication\models\CmsCommAccount;

/**
 * The effective file storage rules of a mailbox: its own settings
 * (CmsCommAccount::file_settings) or the site settings of "Почта"
 * (MailFileSettings). A letter that lies in several mailboxes follows the
 * most keeping rule of them.
 */
class MailFilePolicy
{
    public bool $storeImages = true;
    public bool $storeAttachments = true;
    /** 0 — no limit. */
    public int $maxBytes = 0;
    /** 0 — keep forever. */
    public int $keepDays = 0;
    public int $cacheDays = 30;

    /** @var array site id => site settings values */
    private static array $siteValues = [];

    public static function fromValues(array $values): self
    {
        $policy = new self();
        $mode = (string)($values['mode'] ?? MailFileSettings::MODE_MINIMAL);
        [$policy->storeImages, $policy->storeAttachments] = match ($mode) {
            MailFileSettings::MODE_ECONOMY => [true, false],
            MailFileSettings::MODE_MINIMAL => [false, false],
            MailFileSettings::MODE_CUSTOM => [(bool)($values['storeImages'] ?? true), (bool)($values['storeAttachments'] ?? true)],
            default => [true, true],
        };
        $policy->maxBytes = max(0, (int)($values['maxStoreSizeMb'] ?? 0)) * 1024 * 1024;
        $policy->keepDays = max(0, (int)($values['keepDays'] ?? 0));
        $policy->cacheDays = max(1, (int)($values['cacheDays'] ?? 30));

        return $policy;
    }

    /** Settings of "Почта" for a site. */
    public static function siteValues(int $siteId): array
    {
        if (!isset(self::$siteValues[$siteId])) {
            // The stored values load on refresh() once the site is set (as the settings page does).
            $settings = new MailFileSettings();
            if ($site = \skeeks\cms\models\CmsSite::findOne($siteId)) {
                $settings->cmsSite = $site;
            }
            $settings->refresh();
            self::$siteValues[$siteId] = $settings->values();
        }

        return self::$siteValues[$siteId];
    }

    public static function forAccount(?CmsCommAccount $account, int $siteId): self
    {
        $own = $account ? $account->getFileSettings() : null;

        return self::fromValues($own ?? self::siteValues($siteId));
    }

    /** @param CmsCommAccount[] $accounts */
    public static function forAccounts(array $accounts, int $siteId): self
    {
        if (!$accounts) {
            return self::forAccount(null, $siteId);
        }
        $result = null;
        foreach ($accounts as $account) {
            $policy = self::forAccount($account, $siteId);
            if (!$result) {
                $result = $policy;
                continue;
            }
            $result->storeImages = $result->storeImages || $policy->storeImages;
            $result->storeAttachments = $result->storeAttachments || $policy->storeAttachments;
            $result->maxBytes = ($result->maxBytes === 0 || $policy->maxBytes === 0) ? 0 : max($result->maxBytes, $policy->maxBytes);
            $result->keepDays = ($result->keepDays === 0 || $policy->keepDays === 0) ? 0 : max($result->keepDays, $policy->keepDays);
            $result->cacheDays = max($result->cacheDays, $policy->cacheDays);
        }

        return $result;
    }

    /** Whether a received file is kept in the CRM right away. */
    public function keeps(bool $inline, int $size): bool
    {
        if ($this->maxBytes > 0 && $size > $this->maxBytes) {
            return false;
        }

        return $inline ? $this->storeImages : $this->storeAttachments;
    }

    /** Forget cached settings (after saving them). */
    public static function reset(): void
    {
        self::$siteValues = [];
    }
}
