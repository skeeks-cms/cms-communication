<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\chat;

use skeeks\cms\communication\bot\BotEvent;
use skeeks\cms\communication\bot\BotEventContext;
use skeeks\cms\communication\bot\BotEventHandlerInterface;
use skeeks\cms\communication\bot\BotSend;
use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\models\CmsCommChatTask;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MessageRecordFactory;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use yii\db\AfterSaveEvent;

/**
 * Tasks asked for in a chat:
 * - `/task текст`, `/task` in answer to a message, or a mention of the bot
 *   with the word "задача" creates a CRM task from the message
 *   (MessageRecordFactory, as "Создать задачу" in "Чаты");
 * - our employees may ask in any chat, other people only in a chat linked
 *   to a company;
 * - the task goes to the employee leading the chat, else to the company's
 *   manager, else to the employee who asked; the bot answers with its number;
 * - while the chat allows it (`task_notify`), the bot tells the chat when
 *   the task is accepted, done or cancelled.
 * Runs inside the event transaction (communication.botHandlers): a repeat of
 * the event finds the row of the command message and does nothing.
 */
class ChatTasks implements BotEventHandlerInterface
{
    /** Statuses the chat hears about, with the words it hears. */
    public const TOLD = [
        CmsTask::STATUS_ACCEPTED => 'принята в работу',
        CmsTask::STATUS_READY => 'выполнена',
        CmsTask::STATUS_CANCELED => 'отменена',
    ];

    public function handle(BotEventContext $context): void
    {
        $message = $context->message;
        if ($context->event->type !== BotEvent::MESSAGE || !$message || $message->direction !== CmsCommMessage::DIRECTION_IN) {
            return;
        }
        $text = self::command((string)$message->body_text, (string)$context->connection->createDriver(false)->botUsername);
        if ($text === null || CmsCommChatTask::find()->andWhere(['cms_comm_message_id' => (int)$message->id])->exists()) {
            return;
        }
        $context->handled = true;
        $conversation = $context->conversation;
        $reply = static function (string $answer) use ($conversation, $message) {
            try {
                BotSend::queue($conversation, $answer, ['replyTo' => $message, 'sign' => false]);
            } catch (SendException $e) {
                // A stand or a chat the bot may not write to: the task is enough.
            }
        };

        $identity = $context->identity;
        $worker = $identity && $identity->isWorker() ? $identity->cmsUser : null;
        $company = self::company($conversation);
        if (!$worker && !$company) {
            $reply('Задачи из этого чата ставят сотрудники студии. Напишите, что нужно сделать, — менеджер поставит задачу.');
            return;
        }
        $source = $message;
        if ($text === '' && $message->reply_to_message_id && ($answered = CmsCommMessage::findOne((int)$message->reply_to_message_id))) {
            // "/task" in answer to a message: the task is that message.
            $source = $answered;
            $text = trim(ChatUi::body($answered));
        }
        if ($text === '') {
            $reply('Напишите после /task, что нужно сделать, или ответьте /task на сообщение с задачей.');
            return;
        }
        $executor = self::executor($conversation, $company, $worker);
        if (!$executor) {
            $reply('Не нашли, кому поручить задачу: у компании нет менеджера. Напишите менеджеру напрямую.');
            return;
        }

        $task = self::create($source, $text, $executor, $worker ?: $executor);
        (new CmsCommChatTask([
            'cms_comm_conversation_id' => (int)$conversation->id,
            'cms_comm_message_id' => (int)$message->id,
            'cms_task_id' => (int)$task->id,
            'last_status' => (string)$task->status,
        ]))->save(false);
        $reply('Задача №'.(int)$task->id.' «'.mb_substr((string)$task->name, 0, 100).'» '
            .($task->status === CmsTask::STATUS_ACCEPTED ? 'принята' : 'поставлена')
            .'. Ответственный — '.trim((string)$executor->shortDisplayName).'.');
    }

    /**
     * The task text of a command message, '' for a bare command, null when
     * the message is not a command: "/task …", "/task@bot …", or a mention
     * "@bot" together with the word "задача".
     */
    public static function command(string $text, string $botUsername): ?string
    {
        $text = trim($text);
        if (preg_match('~^/task(?:@[\w.]+)?(?:\s+(.*))?$~su', $text, $m)) {
            return trim($m[1] ?? '');
        }
        $botUsername = ltrim($botUsername, '@');
        if ($botUsername !== '' && mb_stripos($text, '@'.$botUsername) !== false && preg_match('~задач~iu', $text)) {
            $rest = trim(preg_replace('~@'.preg_quote($botUsername, '~').'\b[,:]?~iu', '', $text));
            $rest = trim(preg_replace('~^(поставь(те)?\s+)?задачу?[,:]?\s*~iu', '', $rest));

            return $rest;
        }

        return null;
    }

    /** The company of a chat: the first company it is linked to. */
    public static function company(CmsCommConversation $conversation): ?CmsCompany
    {
        foreach (ChatFeed::links($conversation) as $link) {
            if (CrmRecords::typeOfCode((string)$link->model_code) === CrmRecords::COMPANY && ($company = CmsCompany::findOne((int)$link->model_id))) {
                return $company;
            }
        }

        return null;
    }

    /** Who gets the task: the employee leading the chat, the company's manager, the employee who asked. */
    public static function executor(CmsCommConversation $conversation, ?CmsCompany $company, ?CmsUser $worker): ?CmsUser
    {
        $candidates = [];
        if ($conversation->assigned_user_id) {
            $candidates[] = (int)$conversation->assigned_user_id;
        }
        if ($company) {
            foreach ($company->getCmsCompany2managers()->orderBy(['id' => SORT_ASC])->all() as $row) {
                $candidates[] = (int)$row->cms_user_id;
            }
        }
        if ($worker) {
            $candidates[] = (int)$worker->id;
        }
        foreach ($candidates as $id) {
            $user = CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => $id, CmsUser::tableName().'.is_active' => 1])->one();
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * The task from the message, linked to it as "Создано из сообщения". The
     * author is the employee who asked, or the executor for a client (a task
     * to oneself is accepted at once; the job has no signed-in user).
     */
    public static function create(CmsCommMessage $source, string $text, CmsUser $executor, CmsUser $author): CmsTask
    {
        $line = trim((string)strtok($text, "\n"));

        /** @var CmsTask $task */
        $task = (new MessageRecordFactory($author))->create($source, CrmRecords::TASK, [
            'name' => mb_substr($line, 0, 255),
            'executor_id' => (int)$executor->id,
            'created_by' => (int)$author->id,
        ]);
        // The blameable behavior writes the signed-in user, none in a job.
        if ((int)$task->created_by !== (int)$author->id) {
            $task->updateAttributes(['created_by' => (int)$author->id]);
        }

        return $task;
    }

    /** A task asked for in a chat changed its status: the chat hears about it once. */
    public static function onTaskUpdate(AfterSaveEvent $event): void
    {
        /** @var CmsTask $task */
        $task = $event->sender;
        if (!array_key_exists('status', $event->changedAttributes) || !isset(self::TOLD[(string)$task->status])) {
            return;
        }
        try {
            foreach (CmsCommChatTask::find()->andWhere(['cms_task_id' => (int)$task->id])->all() as $row) {
                if ($row->last_status === (string)$task->status) {
                    continue;
                }
                $row->updateAttributes(['last_status' => (string)$task->status]);
                $conversation = $row->conversation;
                if (!$conversation || !(int)$conversation->task_notify) {
                    continue;
                }
                try {
                    BotSend::queue($conversation, 'Задача №'.(int)$task->id.' «'.mb_substr((string)$task->name, 0, 100).'» '.self::TOLD[(string)$task->status].'.',
                        ['replyTo' => $row->message, 'sign' => false]);
                } catch (SendException $e) {
                    \Yii::info('Chat task #'.$task->id.': '.$e->getMessage(), self::class);
                }
            }
        } catch (\Throwable $e) {
            // The task's own change must not fail because of the chat.
            \Yii::error('Chat task status #'.$task->id.': '.$e->getMessage(), self::class);
        }
    }
}
