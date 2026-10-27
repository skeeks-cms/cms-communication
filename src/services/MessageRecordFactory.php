<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\behaviors\RichTextBehavior;
use skeeks\cms\communication\chat\ChatFeed;
use skeeks\cms\communication\chat\ChatIdentities;
use skeeks\cms\communication\helpers\ChatUi;
use skeeks\cms\communication\helpers\MailText;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommIdentity;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\helpers\RichTextHtml;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;
use yii\db\ActiveRecord;

/**
 * "Создать задачу / лид / сделку" from a letter: one operation for the
 * standard create window (MessageRecordSource, ?source=message:ID), the
 * future mail rules and API tools.
 *
 * - prefill(): subject, the letter's own text without quotes, the sender,
 *   and the lead, company or client the letter is linked to (only those the
 *   actor sees);
 * - enforce(): attributes the letter owns (the source of a lead);
 * - created(): every letter of the conversation is linked to the new record
 *   (this one as "created from"), so it shows the conversation in its feed
 *   and the letter shows "Задача №…"; a new lead gets the sender's address.
 *
 * Deal descriptions are visible to clients: a deal gets only its name and
 * client, never the letter text.
 */
class MessageRecordFactory
{
    /** The employee who creates the record; null: a system rule (all links count). */
    private ?User $actor;
    private MessageLinker $linker;

    public function __construct(?User $actor = null, ?MessageLinker $linker = null)
    {
        $this->actor = $actor;
        $this->linker = $linker ?? new MessageLinker();
    }

    public function supports(string $modelClass): bool
    {
        return CrmRecords::typeOfCode($modelClass) !== null && in_array(CrmRecords::typeOfCode($modelClass), CrmRecords::CREATABLE, true);
    }

    /**
     * Creates and saves a record of the type from the letter in one
     * transaction (for rules and tools; the UI uses the standard window).
     *
     * @param array $attributes overrides, set as safe attributes
     * @throws \RuntimeException when the record does not validate
     */
    public function create(CmsCommMessage $message, string $type, array $attributes = []): ActiveRecord
    {
        if (!in_array($type, CrmRecords::CREATABLE, true)) {
            throw new \InvalidArgumentException('Из письма нельзя создать «'.$type.'».');
        }
        $class = CrmRecords::codeOf($type);
        /** @var ActiveRecord $model */
        $model = new $class();
        $model->loadDefaultValues();
        $this->prefill($message, $model);
        $model->setAttributes($attributes);
        $this->enforce($message, $model);

        $transaction = $model->getDb()->beginTransaction();
        try {
            if (!$model->save()) {
                throw new \RuntimeException(implode(' ', $model->getFirstErrors()) ?: 'Запись не сохранилась.');
            }
            $this->created($message, $model);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $model;
    }

    public function prefill(CmsCommMessage $message, ActiveRecord $model): void
    {
        if ($this->isChat($message)) {
            $this->prefillChat($message, $model);
            return;
        }
        $subject = trim((string)$message->subject);
        $text = MailText::withoutQuotes($message);
        $related = $this->related($message);

        if ($model instanceof CmsTask) {
            $model->name = mb_substr($subject !== '' ? $subject : 'Письмо от '.$this->senderName($message), 0, 255);
            $model->description = $this->description($message, $text, RichTextBehavior::covers($model, 'description'));
            if ($related['lead']) {
                $model->cms_lead_id = (int)$related['lead']->id;
            }
            if ($related['company']) {
                $model->cms_company_id = (int)$related['company']->id;
            } elseif ($related['client']) {
                $model->cms_user_id = (int)$related['client']->id;
            }
        } elseif ($model instanceof CmsLead) {
            $model->name = mb_substr('Письмо'.($subject !== '' ? ' «'.$subject.'»' : '').': '.$this->senderName($message, true), 0, 255);
            $model->description = $this->description($message, $text, false);
            $model->source_type = CmsLead::SOURCE_EMAIL;
            $model->source_name = $this->mailboxAddress($message);
        } elseif ($model instanceof CmsDeal) {
            $model->name = mb_substr($subject !== '' ? $subject : 'Услуга из письма от '.$this->senderName($message), 0, 255);
            if ($related['company']) {
                $model->cms_company_id = (int)$related['company']->id;
            } elseif ($related['client']) {
                $model->cms_user_id = (int)$related['client']->id;
            }
        }
    }

    public function enforce(CmsCommMessage $message, ActiveRecord $model): void
    {
        if (!$model instanceof CmsLead) {
            return;
        }
        $related = $this->related($message);
        if ($this->isChat($message)) {
            $model->cms_site_id = (int)$message->cms_site_id;
            $model->source_type = $this->chatLeadSource($message);
            $model->source_data = [
                'message_id' => (int)$message->id,
                'external_id' => (string)$message->external_id,
                'chat' => (string)$message->conversation->asText(),
                'from' => ChatUi::author($message),
            ];
            foreach (['company' => 'cms_company_id', 'client' => 'cms_user_id'] as $key => $attribute) {
                if ($related[$key]) {
                    $model->{$attribute} = (int)$related[$key]->id;
                }
            }
            return;
        }
        $model->cms_site_id = (int)$message->cms_site_id;
        $model->source_type = CmsLead::SOURCE_EMAIL;
        $model->source_data = [
            'message_id' => (int)$message->id,
            'external_id' => (string)$message->external_id,
            'from' => $this->fromText($message),
            'subject' => (string)$message->subject,
        ];
        if ($related['company']) {
            $model->cms_company_id = (int)$related['company']->id;
        }
        if ($related['client']) {
            $model->cms_user_id = (int)$related['client']->id;
        }
    }

    public function created(CmsCommMessage $message, ActiveRecord $model): void
    {
        $actorId = $this->actor ? (int)$this->actor->id : null;
        $this->linker->link($message, [$model], CmsCommLink::TYPE_CREATED, $actorId);
        if ($this->isChat($message)) {
            $this->createdFromChat($message, $model, $actorId);
            return;
        }
        foreach (CmsCommMessage::find()
                     ->andWhere(['cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id])
                     ->andWhere(['!=', 'id', (int)$message->id])
                     ->each(50) as $other) {
            $this->linker->link($other, [$model], CmsCommLink::TYPE_THREAD, $actorId);
        }

        $address = $this->externalAddress($message);
        if ($model instanceof CmsLead && $address && !(new AddressBook())->isOurs($address, (int)$message->cms_site_id)) {
            $email = new CmsLeadEmail([
                'cms_lead_id' => (int)$model->id,
                'value' => $address,
                'name' => $message->direction === CmsCommMessage::DIRECTION_IN && MailText::name($message->from_name) !== '' ? mb_substr(MailText::name($message->from_name), 0, 255) : null,
            ]);
            // A malformed address stays in the description and the letter: the lead is still created.
            if (!$email->validate() || !$email->save(false)) {
                \Yii::warning('Lead email from letter #'.$message->id.' was not saved: '.implode(' ', $email->getFirstErrors()), self::class);
            }
        }
    }

    /** Plain text above the create form. */
    public function describe(CmsCommMessage $message): string
    {
        if ($this->isChat($message)) {
            return 'Из сообщения '.$this->chatPlace($message).' от '.ChatUi::author($message).', '
                .\Yii::$app->formatter->asDatetime((int)$message->message_at, 'php:d.m.Y H:i')
                .'. После сохранения сообщение будет привязано к записи.';
        }
        $subject = trim((string)$message->subject);

        return 'Из письма'.($subject !== '' ? ' «'.$subject.'»' : '').' '
            .($message->direction === CmsCommMessage::DIRECTION_IN ? 'от '.$this->senderName($message) : 'для '.($this->externalAddress($message) ?: '—'))
            .', '.\Yii::$app->formatter->asDatetime((int)$message->message_at, 'php:d.m.Y H:i')
            .'. После сохранения запись привяжется к переписке.';
    }

    /**
     * Lead, company and client of the letter the actor sees. A lead's
     * company or client counts when the letter has none of its own.
     *
     * @return array lead, company, client: ActiveRecord|null
     */
    public function related(CmsCommMessage $message): array
    {
        $result = ['lead' => null, 'company' => null, 'client' => null];
        $order = [CrmRecords::LEAD => 'lead', CrmRecords::COMPANY => 'company', CrmRecords::CLIENT => 'client'];
        $links = CmsCommLink::find()->andWhere(['cms_comm_message_id' => (int)$message->id])
            ->orderBy(['id' => SORT_ASC])->all();
        if ($this->isChat($message)) {
            // A chat belongs to records as a whole; the person of a chat may be a client.
            $links = array_merge(ChatFeed::links($message->conversation), $links);
            $identity = $message->identity;
            if ($identity && $identity->cms_user_id && !$identity->isWorker()) {
                $links[] = new CmsCommLink(['model_code' => CmsUser::class, 'model_id' => (int)$identity->cms_user_id]);
            }
        }
        foreach ($links as $link) {
            $type = CrmRecords::typeOfCode((string)$link->model_code);
            if (!isset($order[$type]) || $result[$order[$type]]) {
                continue;
            }
            $record = $this->actor
                ? CrmRecords::find($type, (int)$link->model_id, $this->actor)
                : ChatFeed::record((string)$link->model_code, (int)$link->model_id);
            if ($record) {
                $result[$order[$type]] = $record;
            }
        }
        $lead = $result['lead'];
        if ($lead instanceof CmsLead && !$result['company'] && !$result['client']) {
            if ($lead->cms_company_id) {
                $result['company'] = CmsCompany::findOne((int)$lead->cms_company_id);
            } elseif ($lead->cms_user_id) {
                $result['client'] = CmsUser::findOne((int)$lead->cms_user_id);
            }
        }

        return $result;
    }

    public function isChat(CmsCommMessage $message): bool
    {
        return $message->conversation && $message->conversation->isChat();
    }

    /** A task, lead or deal from a chat message: its text, its author, the chat. */
    private function prefillChat(CmsCommMessage $message, ActiveRecord $model): void
    {
        $text = trim(ChatUi::body($message));
        $line = trim((string)strtok($text, "\n"));
        $related = $this->related($message);
        $author = ChatUi::author($message);
        if ($model instanceof CmsTask) {
            $model->name = mb_substr($line !== '' ? $line : 'Сообщение от '.$author, 0, 255);
            $model->description = $this->chatDescription($message, $text, RichTextBehavior::covers($model, 'description'));
            if ($related['lead']) {
                $model->cms_lead_id = (int)$related['lead']->id;
            }
            if ($related['company']) {
                $model->cms_company_id = (int)$related['company']->id;
            } elseif ($related['client']) {
                $model->cms_user_id = (int)$related['client']->id;
            }
        } elseif ($model instanceof CmsLead) {
            $model->name = mb_substr('Сообщение в '.ChatUi::channelName($message->conversation).': '.$author, 0, 255);
            $model->description = $this->chatDescription($message, $text, false);
            $model->source_type = $this->chatLeadSource($message);
            $model->source_name = mb_substr((string)$message->conversation->asText(), 0, 255);
        } elseif ($model instanceof CmsDeal) {
            $model->name = mb_substr('Услуга из чата «'.$message->conversation->asText().'»', 0, 255);
            if ($related['company']) {
                $model->cms_company_id = (int)$related['company']->id;
            } elseif ($related['client']) {
                $model->cms_user_id = (int)$related['client']->id;
            }
        }
    }

    /**
     * Created from a chat message: in a group the message goes into the feed
     * of the chat's records on its own (the day summary does not say which
     * message became a task); a lead from a private chat gets the person.
     */
    private function createdFromChat(CmsCommMessage $message, ActiveRecord $model, ?int $actorId): void
    {
        $conversation = $message->conversation;
        if ($conversation->chat_type !== CmsCommConversation::CHAT_PRIVATE) {
            $records = array_values(array_filter($this->related($message)));
            if ($records) {
                $this->linker->link($message, $records, CmsCommLink::TYPE_MANUAL, $actorId);
            }
        }
        if ($model instanceof CmsLead && $conversation->chat_type === CmsCommConversation::CHAT_PRIVATE
            && $message->identity && !$message->identity->cms_user_id && !$message->identity->isWorker()) {
            ChatIdentities::linkTo($message->identity, CrmRecords::LEAD, (int)$model->id, CmsCommIdentity::LINK_MANUAL, $actorId, $conversation);
        }
    }

    /** "в MAX, чат «Компания / сайт.ru»" or "в личном диалоге MAX". */
    private function chatPlace(CmsCommMessage $message): string
    {
        $conversation = $message->conversation;
        $channel = ChatUi::channelName($conversation);

        return $conversation->chat_type === CmsCommConversation::CHAT_PRIVATE
            ? 'в личном диалоге '.$channel
            : 'в '.$channel.', чат «'.$conversation->asText().'»';
    }

    private function chatDescription(CmsCommMessage $message, string $text, bool $html): string
    {
        $head = 'Сообщение '.$this->chatPlace($message).' от '.ChatUi::author($message).', '
            .\Yii::$app->formatter->asDatetime((int)$message->message_at, 'php:d.m.Y H:i');
        if (!$html) {
            return $head."\n\n".$text;
        }

        return '<div><i>'.htmlspecialchars($head, ENT_QUOTES, 'UTF-8').'</i></div><div><br></div><div>'.RichTextHtml::fromText($text).'</div>';
    }

    private function chatLeadSource(CmsCommMessage $message): string
    {
        return [
            'max' => CmsLead::SOURCE_MAX,
            'telegram' => CmsLead::SOURCE_TELEGRAM,
        ][(string)$message->channel] ?? CmsLead::SOURCE_MANUAL;
    }

    private function description(CmsCommMessage $message, string $text, bool $html): string
    {
        $head = ($message->direction === CmsCommMessage::DIRECTION_IN ? 'Письмо от '.$this->fromText($message) : 'Письмо для '.$message->getToAsText())
            .', '.\Yii::$app->formatter->asDatetime((int)$message->message_at, 'php:d.m.Y H:i');
        if (!$html) {
            return $head."\n\n".$text;
        }

        return '<div><i>'.htmlspecialchars($head, ENT_QUOTES, 'UTF-8').'</i></div><div><br></div><div>'.RichTextHtml::fromText($text).'</div>';
    }

    private function senderName(CmsCommMessage $message, bool $withAddress = false): string
    {
        if ($message->direction !== CmsCommMessage::DIRECTION_IN) {
            return (string)($this->externalAddress($message) ?: '—');
        }
        $name = MailText::name($message->from_name);
        if ($name === '') {
            return (string)$message->from_address;
        }

        return $withAddress && $message->from_address ? $name.', '.$message->from_address : $name;
    }

    /** "Иван Петров <ivan@example.com>" with an encoded name decoded. */
    private function fromText(CmsCommMessage $message): string
    {
        $name = MailText::name($message->from_name);

        return $name !== '' ? $name.' <'.$message->from_address.'>' : (string)$message->from_address;
    }

    /** Our mailbox of the letter: the one it was sent from or received into. */
    private function mailboxAddress(CmsCommMessage $message): ?string
    {
        $account = $message->account;
        if (!$account && ($copy = $message->getMailboxCopies()->orderBy(['id' => SORT_ASC])->one())) {
            $account = \skeeks\cms\communication\models\CmsCommAccount::findOne((int)$copy->cms_comm_account_id);
        }

        return $account ? mb_substr((string)$account->address, 0, 255) : null;
    }

    /** The client's address: the sender of an incoming letter, the first recipient of an outgoing one. */
    private function externalAddress(CmsCommMessage $message): ?string
    {
        if ($message->direction === CmsCommMessage::DIRECTION_IN) {
            return $message->from_address ? (string)$message->from_address : null;
        }
        $to = array_keys($message->getTo());

        return $to ? (string)$to[0] : null;
    }
}
