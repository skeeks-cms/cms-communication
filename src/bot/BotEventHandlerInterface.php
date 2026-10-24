<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

/**
 * Reacts to a bot event after BotIngest has stored it (registered in
 * `communication.botHandlers`): links a person by a `?start=` key, takes a
 * command in a group, shows a bot menu of a package. Runs inside the
 * transaction of the event: an exception rolls the event back and it is
 * tried again, so a handler must be repeatable and may only queue external
 * work (BotSend::queue()), never call a messenger itself.
 *
 * History messages (the import of a chat history) never reach handlers.
 */
interface BotEventHandlerInterface
{
    public function handle(BotEventContext $context): void;
}
