<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\notify;

use skeeks\cms\communication\bot\Bots;
use skeeks\cms\notify\NotifyChannelProviderInterface;

/**
 * One notification channel per installed messenger driver (MAX, Telegram,
 * later others): the code of a channel is the channel of its driver, so
 * settings saved with "max" and "telegram" keep working and a new driver
 * package needs no configuration here. A channel shows itself only while the
 * site has an active bot of it.
 */
class MessengerNotifyChannels implements NotifyChannelProviderInterface
{
    /** @var string[] only these messenger channels (tests); empty — all installed */
    public $only = [];

    public function channels(): array
    {
        $result = [];
        foreach (Bots::channels() as $code => $title) {
            if ($this->only && !in_array($code, $this->only, true)) {
                continue;
            }
            $result[$code] = new MessengerNotifyChannel(['code' => $code, 'name' => $title]);
        }

        return $result;
    }
}
