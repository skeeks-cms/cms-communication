<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommConnection;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommMessage;

/**
 * What a handler gets: the event and the records BotIngest wrote for it.
 * A handler sets `handled` when it took the event (a command, a start key),
 * so later handlers may skip it.
 */
final class BotEventContext
{
    public BotEvent $event;
    public CmsCommConnection $connection;
    public CmsCommAccount $account;
    public CmsCommConversation $conversation;
    public ?CmsCommIdentity $identity;
    public ?CmsCommMessage $message;
    public bool $handled = false;

    public function __construct(BotEvent $event, CmsCommConnection $connection, CmsCommAccount $account,
        CmsCommConversation $conversation, ?CmsCommIdentity $identity, ?CmsCommMessage $message)
    {
        $this->event = $event;
        $this->connection = $connection;
        $this->account = $account;
        $this->conversation = $conversation;
        $this->identity = $identity;
        $this->message = $message;
    }
}
