<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use yii\db\ActiveRecord;

/**
 * Optional for a SendableSourceInterface: when this document was last sent
 * to a CRM record by any channel. AI actions (services\AiActions) refuse to
 * offer the same document to the same client again too soon.
 */
interface SendHistoryInterface
{
    /**
     * Time of the latest sending of the document to the record (a lead, a
     * company, a client) or to its company, or null when it was never sent.
     */
    public function lastSentAt(int $id, ActiveRecord $record): ?int;
}
