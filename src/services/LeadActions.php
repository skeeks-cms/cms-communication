<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\models\CmsLead;

/**
 * A new action from a deal — a letter, an SMS, a proposal, a presentation
 * (platform-plan crm/links-contract.md п. 13а): who works on the deal (core
 * CrmAccess::isLeadWorker()), and while the deal is closed only who acts on
 * its client anyway (an administrator, "full", the main responsible:
 * CrmAccess::canActOnClient() without a deal). «Свои сделки» and "edit" act
 * only in their open deal: a closed one is returned to work first. Reading
 * the history of a closed deal is not limited here.
 *
 * One rule for ComposeForm, SendService and cms-proposal; core CrmAccess
 * owns the parts it is built from.
 */
final class LeadActions
{
    public const CLOSED = 'Сделка закрыта: новые письма, СМС и КП по ней не отправляются. Чтобы продолжить работу с клиентом по этой сделке, верните её в работу.';

    /** Why the employee may not start an action from the deal, null when allowed (or no core rules are installed). */
    public static function error($user, CmsLead $lead): ?string
    {
        $access = \skeeks\cms\crm\CrmAccess::class;
        if (!$user || !method_exists($access, 'isLeadWorker')) {
            return null;
        }
        $crm = $access::instance();
        if (!$crm->isLeadWorker($user, $lead)) {
            return $access::LEAD_WORK_DENIED;
        }
        if (!$lead->isTerminal) {
            return null;
        }
        $client = $lead->cms_company_id ? $lead->company : ($lead->cms_user_id ? $lead->client : null);

        return ($client ? $crm->canActOnClient($user, $client) : $crm->isAdmin($user)) ? null : self::CLOSED;
    }

    /** error() for the employee who acts now (a web or API session); the console and system work are not limited. */
    public static function actingError(CmsLead $lead): ?string
    {
        $access = \skeeks\cms\crm\CrmAccess::class;

        return method_exists($access, 'actingWorker') ? self::error($access::actingWorker(), $lead) : null;
    }
}
