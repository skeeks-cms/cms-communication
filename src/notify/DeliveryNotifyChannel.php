<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use skeeks\cms\communication\models\CmsCommNotifyDelivery;
use skeeks\cms\notify\NotifyChannel;

/**
 * A notification channel delivered by this package (NotifyDeliveries): the job
 * asks it to send one message of an entry.
 */
abstract class DeliveryNotifyChannel extends NotifyChannel
{
    /**
     * Sends the message. A refusal (nothing left) lets the next channel try;
     * an unknown outcome does not.
     *
     * @return array [status (CmsCommNotifyDelivery::STATUS_SENT|SENDING|FAILED|UNKNOWN), error|null, chat message id|null]
     */
    abstract public function send(CmsCommNotifyDelivery $delivery, NotifyMessage $message): array;
}
