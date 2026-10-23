<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use skeeks\cms\models\User;
use yii\db\ActiveRecord;

/**
 * A source of sendable documents (send\SendableSourceInterface) that
 * mailing campaigns can use as their offer:
 *
 * - one document for everybody (a presentation for everybody): sharedItems()
 *   lists them, the campaign sends the document found by find() to every
 *   recipient, each with a personal link;
 * - every recipient's own document from a template (a КП from a template):
 *   templates() lists the templates, createFromTemplate() makes the document
 *   for the CRM record of the recipient when the letter leaves, inside the
 *   transaction of the letter;
 * - the report of a campaign asks states() what became of the sends of its
 *   letters (opened, accepted).
 *
 * The campaign rule "задача менеджеру при первом открытии" is the package's
 * own rule; it asks services\Campaigns::taskOnOpen() whether the campaign of
 * the letter wants the task.
 */
interface CampaignSourceInterface
{
    /** @return string[] id => title: documents for everybody the employee may send */
    public function sharedItems(User $user, int $siteId): array;

    /** @return string[] id => title: active templates of the site */
    public function templates(int $siteId): array;

    /**
     * Makes the document of one recipient from the template (responsible —
     * $user) and returns its id: the campaign then sends it as find() of
     * the source returns it.
     *
     * @throws SendException when the template cannot be used now: the recipient fails with the reason
     */
    public function createFromTemplate(int $templateId, ActiveRecord $record, User $user): int;

    /**
     * What became of the sends carried by these letters.
     *
     * @param int[] $messageIds cms_comm_message ids
     * @return array[] message id => ['opened_at' => int|null, 'accepted_at' => int|null]
     */
    public function states(array $messageIds): array;
}
