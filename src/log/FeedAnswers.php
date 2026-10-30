<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\log;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommConversationLink;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\crm\CrmAccess;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * Answers of feed renderers about letters and chats (audit final4: the same
 * chat checked 40 times on a card) are kept in the one request memo of
 * CrmAccess (remember()). They go with every resetMemo() and on a change of
 * the signed-in identity. The console and workers keep nothing. The rights
 * of mail and bots also change here: access rows of mailboxes and bots, links
 * of letters and chats, a taken or exposed chat. Saving or deleting one of
 * them drops the memo too (audit F5-01).
 */
final class FeedAnswers
{
    private static bool $watched = false;

    /** @return mixed */
    public static function remember(string $key, callable $compute)
    {
        if (!self::$watched && CrmAccess::keepsAnswers()) {
            self::$watched = true;
            $reset = static fn() => CrmAccess::instance()->resetMemo();
            foreach ([CmsCommAccountAccess::class, CmsCommAccount::class, CmsCommConversationLink::class, CmsCommLink::class, CmsCommConversation::class] as $class) {
                foreach ([BaseActiveRecord::EVENT_AFTER_INSERT, BaseActiveRecord::EVENT_AFTER_UPDATE, BaseActiveRecord::EVENT_AFTER_DELETE] as $event) {
                    Event::on($class, $event, $reset);
                }
            }
        }

        return CrmAccess::instance()->remember('comm:'.$key, $compute);
    }
}
