<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\jobs;

use skeeks\cms\communication\bot\BotChats;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

/**
 * The title and the picture of one bot chat from the messenger (bot\BotChats).
 */
class BotChatInfoJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $conversation = CmsCommConversation::findOne((int)$context->get('conversationId'));
        if (!$conversation) {
            $reporter->setStage('complete', 'Чат удалён');
            return;
        }
        $changed = BotChats::refresh($conversation);
        $reporter->setResult(['photo' => $changed]);
        $reporter->setStage('complete', $changed ? 'Фото чата обновлено' : 'Сведения о чате проверены');
    }
}
