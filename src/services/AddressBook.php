<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompanyEmail;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsUserEmail;
use skeeks\cms\models\User;
use yii\db\Expression;

/**
 * Who is behind an email address: our mailboxes and employees, or leads,
 * companies and clients of the CRM. Matching is exact on the normalized
 * (lowercased) address; names never link records.
 */
class AddressBook
{
    /** Public mail services: a mailbox there does not make the whole domain ours. */
    public const PUBLIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yandex.ru', 'ya.ru', 'yandex.com', 'mail.ru', 'bk.ru', 'inbox.ru', 'list.ru',
        'internet.ru', 'rambler.ru', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'me.com', 'yahoo.com', 'proton.me',
    ];

    /** @var array site id => ['addresses' => address => true, 'domains' => domain => true] */
    private array $ours = [];

    /**
     * Our addresses: the site's mailboxes, its employees, and any address on
     * the corporate domain of a mailbox (colleagues without a mailbox in CRM).
     */
    public function isOurs(string $address, int $siteId): bool
    {
        if (!isset($this->ours[$siteId])) {
            $mailboxes = CmsCommAccount::find()->select('address')->andWhere(['cms_site_id' => $siteId])->column();
            $workers = CmsUser::find()->select(CmsUser::tableName().'.id')->andWhere(['is_worker' => 1]);
            $addresses = array_merge($mailboxes,
                CmsUserEmail::find()->select('value')->andWhere(['cms_user_id' => $workers])->column()
            );
            $domains = [];
            foreach ($mailboxes as $mailbox) {
                $domain = mb_strtolower((string)substr((string)strrchr((string)$mailbox, '@'), 1));
                if ($domain !== '' && !in_array($domain, self::PUBLIC_DOMAINS, true)) {
                    $domains[$domain] = true;
                }
            }
            $this->ours[$siteId] = [
                'addresses' => array_fill_keys(array_map(static fn($a) => mb_strtolower(trim((string)$a)), $addresses), true),
                'domains' => $domains,
            ];
        }
        $address = mb_strtolower(trim($address));
        $domain = (string)substr((string)strrchr($address, '@'), 1);

        return isset($this->ours[$siteId]['addresses'][$address]) || isset($this->ours[$siteId]['domains'][$domain]);
    }

    /**
     * CRM records with one of these addresses: active leads (new, in work),
     * companies and clients (not employees).
     *
     * @param string[] $addresses
     * @param User|null $visibleTo limit to records this employee can see
     * @return \yii\db\ActiveRecord[]
     */
    public function findRecords(array $addresses, int $siteId, ?User $visibleTo = null): array
    {
        $addresses = array_values(array_unique(array_filter(array_map(static fn($a) => mb_strtolower(trim((string)$a)), $addresses))));
        if (!$addresses) {
            return [];
        }

        $leads = CmsLead::find()
            ->andWhere([CmsLead::tableName().'.cms_site_id' => $siteId])
            ->andWhere([CmsLead::tableName().'.status' => [CmsLead::STATUS_NEW, CmsLead::STATUS_IN_WORK]])
            ->andWhere([CmsLead::tableName().'.id' => CmsLeadEmail::find()->select('cms_lead_id')
                ->andWhere(['in', new Expression('LOWER(value)'), $addresses])]);

        $companies = CmsCompany::find()
            ->andWhere([CmsCompany::tableName().'.id' => CmsCompanyEmail::find()->select('cms_company_id')
                ->andWhere(['in', new Expression('LOWER(value)'), $addresses])]);

        $clients = CmsUser::find()
            ->andWhere([CmsUser::tableName().'.is_worker' => 0])
            ->andWhere([CmsUser::tableName().'.id' => CmsUserEmail::find()->select('cms_user_id')
                ->andWhere(['cms_site_id' => $siteId])
                ->andWhere(['in', new Expression('LOWER(value)'), $addresses])]);

        if ($visibleTo) {
            $leads->forManager($visibleTo);
            $companies->forManager($visibleTo);
            $clients->forManager($visibleTo);
        }

        return array_merge($leads->all(), $companies->distinct()->all(), $clients->distinct()->all());
    }
}
