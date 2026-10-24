<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\bot;

/**
 * A messenger user as a driver sees it in an update.
 */
final class BotUser
{
    public string $id;
    public string $name = '';
    public ?string $username = null;
    public bool $isBot = false;
    /** Role in a group chat when the update tells it: member, admin, creator. */
    public ?string $role = null;

    public function __construct(string $id, string $name = '', ?string $username = null, bool $isBot = false)
    {
        $this->id = $id;
        $this->name = trim($name);
        $this->username = $username !== null && $username !== '' ? ltrim($username, '@') : null;
        $this->isBot = $isBot;
    }
}
