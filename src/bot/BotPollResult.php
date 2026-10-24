<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

/**
 * Updates of one poll and the protocol cursor to ask with next time
 * (Telegram offset, MAX marker). The cursor is stored in the same
 * transaction as the updates (BotUpdates::store()).
 */
final class BotPollResult
{
    /** @var array[] raw updates as the messenger gave them */
    public array $updates;
    public ?string $cursor;

    public function __construct(array $updates, ?string $cursor)
    {
        $this->updates = $updates;
        $this->cursor = $cursor;
    }
}
