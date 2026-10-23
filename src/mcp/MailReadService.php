<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\mcp;

use skeeks\cms\communication\helpers\MailText;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommContactTopic;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\send\SmsSendChannel;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MailingConsents;
use skeeks\cms\communication\services\MailingContacts;
use skeeks\cms\communication\services\MailingPolicy;
use skeeks\cms\communication\services\MailSearch;
use skeeks\cms\communication\services\SiteMessages;
use skeeks\cms\mcp\crm\CrmDate;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\CrmRecord;
use skeeks\cms\mcp\crm\PersonalData;
use skeeks\cms\mcp\services\AbstractCmsService;
use yii\base\Exception;
use yii\db\Expression;

/**
 * Read API of mail and mailings for AI clients (cms-mcp, scope
 * cms.communication.read): letters and conversations with the own text of
 * every letter (no quoted history, signature, HTML), attachments by name
 * only; mailboxes without connections or passwords; mailing topics, the
 * MailingPolicy check and the consent state of contacts. Visibility is
 * CmsCommMessageQuery::forManager() of the OAuth employee, as in "Почта".
 * Addresses and phones are masked unless include_contacts=true.
 */
class MailReadService extends AbstractCmsService
{
    public $textLength = 4000;
    public $conversationTextLength = 1500;
    public $conversationLimit = 50;
    /**
     * Lines that start a signature, a mobile footer or a quoted letter that
     * MailText did not recognise (a line of dashes, "От: … / Кому: …" headers):
     * the rest of the letter is dropped.
     */
    public $signatureStarts = [
        '/^-{2,}\s*$/u', '/^__+\s*$/u',
        '/^(от|from|кому|to|дата|date|отправлено|sent)\s*:\s*\S.*$/iu',
        '/^(с уважением|с наилучшими пожеланиями|всего доброго|всего хорошего|best regards|kind regards|regards|sincerely|thanks and regards)\b.*$/iu',
        '/^(отправлено|sent) (с|из|from) .*(iphone|ipad|android|mail|почт|яндекс|samsung|outlook).*$/iu',
    ];

    /* ------------------------------------------------------------- messages */

    public function messageList(array $a): array
    {
        $context = $this->context($a);
        $t = CmsCommMessage::tableName();
        $q = CmsCommMessage::find()->forManager();
        if ($this->hasRecord($a)) {
            $record = CrmRecord::resolve($a);
            $owners = [[CrmRecords::codeOf($record->type), $record->getId()]];
            if (!empty($a['include_related'])) {
                foreach ($record->relatedFeedOwners() as $owner) {
                    $owners[] = [$owner['model_code'], (int)$owner['model_id']];
                }
            }
            $or = ['or'];
            foreach ($owners as [$code, $id]) {
                $or[] = ['and', ['model_code' => $code], ['model_id' => $id]];
            }
            $q->andWhere([$t.'.id' => CmsCommLink::find()->select('cms_comm_message_id')->andWhere($or)]);
        }
        if (!empty($a['cms_comm_account_id'])) {
            $q->inAccount((int)$a['cms_comm_account_id']);
        }
        if (!empty($a['cms_comm_conversation_id'])) {
            $q->andWhere([$t.'.cms_comm_conversation_id' => (int)$a['cms_comm_conversation_id']]);
        }
        foreach (['direction', 'channel', 'status'] as $attribute) {
            if (!empty($a[$attribute])) {
                $q->andWhere([$t.'.'.$attribute => $a[$attribute]]);
            }
        }
        if (isset($a['is_auto']) && $a['is_auto'] !== '' && $a['is_auto'] !== null) {
            $q->andWhere([$t.'.is_auto' => $a['is_auto'] ? 1 : 0]);
        }
        if (!empty($a['q'])) {
            $siteId = $this->siteId();
            foreach (MailSearch::terms((string)$a['q']) as $term) {
                $q->andWhere([$t.'.id' => MailSearch::messages($term, $siteId)]);
            }
        }
        $time = new Expression('COALESCE('.$t.'.message_at, '.$t.'.created_at)');
        if (!empty($a['date_from'])) {
            $q->andWhere(['>=', $time, $this->timestamp($a['date_from'])]);
        }
        if (!empty($a['date_to'])) {
            $q->andWhere(['<=', $time, $this->timestamp($a['date_to'], true)]);
        }
        $q->andWhere(['or', [$t.'.box_state' => null], ['not', [$t.'.box_state' => ['trash', 'junk']]]]);
        $limit = max(1, min(100, (int)($a['limit'] ?? 30)));
        $offset = max(0, (int)($a['offset'] ?? 0));
        $total = (int)(clone $q)->count();
        $items = [];
        foreach ($q->orderBy([$time->expression => SORT_DESC, $t.'.id' => SORT_DESC])->limit($limit)->offset($offset)->with(['links', 'files'])->all() as $m) {
            $items[] = $this->messageData($m, $context, false);
        }
        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'include_contacts' => $context->includeContacts];
    }

    public function messageGet(array $a): array
    {
        $context = $this->context($a);
        $m = $this->visibleMessage((int)($a['id'] ?? 0));
        return $this->messageData($m, $context, true, max(500, min(20000, (int)($a['max_length'] ?? $this->textLength))));
    }

    public function conversationGet(array $a): array
    {
        $context = $this->context($a);
        $id = (int)($a['id'] ?? 0);
        if (!$id && !empty($a['cms_comm_message_id'])) {
            $id = (int)$this->visibleMessage((int)$a['cms_comm_message_id'])->cms_comm_conversation_id;
        }
        $conversation = $id ? CmsCommConversation::findOne($id) : null;
        $t = CmsCommMessage::tableName();
        $q = CmsCommMessage::find()->forManager()->andWhere([$t.'.cms_comm_conversation_id' => $id]);
        $total = $conversation ? (int)(clone $q)->count() : 0;
        if (!$conversation || !$total) {
            throw new Exception('Conversation #'.$id.' not found or unavailable.');
        }
        $limit = max(1, min(100, (int)($a['limit'] ?? $this->conversationLimit)));
        $length = max(300, min(10000, (int)($a['max_length'] ?? $this->conversationTextLength)));
        // The newest letters of a long conversation, shown in the order they were written.
        $messages = array_reverse($q->orderBy([new Expression('COALESCE('.$t.'.message_at, '.$t.'.created_at) DESC'), $t.'.id' => SORT_DESC])->limit($limit)->with(['links', 'files'])->all());
        return [
            'id' => (int)$conversation->id,
            'subject' => $context->text((string)$conversation->subject, 300),
            'channel' => (string)$conversation->channel,
            'last_message_at' => CrmDate::format($conversation->last_message_at ? (int)$conversation->last_message_at : null),
            'assigned_user' => $conversation->assignedUser ? ['id' => (int)$conversation->assigned_user_id, 'name' => (string)$conversation->assignedUser->shortDisplayName] : null,
            'messages_total' => $total,
            'messages' => array_map(function (CmsCommMessage $m) use ($context, $length) { return $this->messageData($m, $context, true, $length); }, $messages),
            'is_truncated' => $total > count($messages),
            'include_contacts' => $context->includeContacts,
        ];
    }

    public function accountList(array $a): array
    {
        $userId = (int)\Yii::$app->user->id;
        $accesses = [];
        foreach (CmsCommAccountAccess::find()->andWhere(['cms_user_id' => $userId])->all() as $row) {
            $accesses[(int)$row->cms_comm_account_id] = $row;
        }
        $items = [];
        $q = CmsCommAccount::find()->andWhere(['cms_site_id' => $this->siteId(), 'id' => array_keys($accesses) ?: [0]])->orderBy(['id' => SORT_ASC]);
        foreach ($q->all() as $account) {
            $access = $accesses[(int)$account->id];
            $items[] = [
                'id' => (int)$account->id,
                'channel' => (string)$account->channel,
                'address' => (string)$account->address,
                'name' => (string)$account->name,
                'is_active' => (bool)$account->is_active,
                'read_level' => (string)$access->read_level,
                'send_level' => (string)$access->send_level,
                'is_default' => (bool)$access->is_default,
            ];
        }
        return ['items' => $items, 'total' => count($items), 'note' => 'Ящики, к которым у сотрудника есть доступ. Письма других ящиков видны, если привязаны к его клиентам (как в разделе «Почта»).'];
    }

    /* -------------------------------------------------------------- mailing */

    public function topicList(array $a): array
    {
        $items = array_map(static function (CmsCommTopic $t) {
            return ['id' => (int)$t->id, 'name' => (string)$t->name, 'description' => (string)$t->description, 'is_active' => (bool)$t->is_active];
        }, CmsCommTopic::forSite($this->siteId(), !empty($a['active_only'])));
        return ['items' => $items, 'total' => count($items)];
    }

    /**
     * MailingPolicy::checkMany() for the given addresses or for all contacts
     * of a client (of the channel, or email and SMS).
     */
    public function mailingCheck(array $a): array
    {
        $context = $this->context($a);
        $topicId = (int)($a['cms_comm_topic_id'] ?? $a['topic_id'] ?? 0);
        $mode = (string)($a['mode'] ?? MailingPolicy::MODE_MANAGER);
        if (!in_array($mode, [MailingPolicy::MODE_MANAGER, MailingPolicy::MODE_SERVICE], true)) {
            throw new Exception('mode must be manager or service.');
        }
        $siteId = $this->siteId();
        $byChannel = [];
        $record = null;
        if ($this->hasRecord($a)) {
            $record = CrmRecord::resolve($a);
            foreach (MailingContacts::ofRecord($record->model) as $row) {
                $channel = $row['kind'] === CmsCommContact::KIND_PHONE ? 'sms' : 'email';
                if (empty($a['channel']) || $a['channel'] === $channel) {
                    $byChannel[$channel][] = (string)$row['value'];
                }
            }
        } else {
            $values = array_values(array_filter(array_map('trim', (array)($a['values'] ?? ($a['value'] ?? [])))));
            if (!$values) {
                throw new Exception('Pass values (addresses or phones) or a client: record_type + record_id.');
            }
            if (count($values) > 200) {
                throw new Exception('At most 200 values per call.');
            }
            $byChannel[(string)($a['channel'] ?? 'email')] = $values;
        }
        $items = [];
        $allowed = 0;
        foreach ($byChannel as $channel => $values) {
            if (!MailingPolicy::kindOf($channel)) {
                throw new Exception('channel must be email or sms.');
            }
            foreach (MailingPolicy::checkMany($siteId, $channel, $values, $topicId, $mode) as $value => $result) {
                $allowed += $result['allowed'] ? 1 : 0;
                $items[] = [
                    'channel' => $channel,
                    'contact' => $channel === 'sms' ? $context->phone((string)$value) : $context->email((string)$value),
                    'allowed' => (bool)$result['allowed'],
                    'reason' => $result['reason'],
                    'message' => (string)$result['message'],
                    'consent' => $result['consent'] ?? null,
                ];
            }
        }
        $topic = $topicId ? CmsCommTopic::findOne(['id' => $topicId, 'cms_site_id' => $siteId]) : null;
        return [
            'topic' => $topic ? ['id' => (int)$topic->id, 'name' => (string)$topic->name, 'is_active' => (bool)$topic->is_active] : null,
            'mode' => $mode,
            'record' => $record ? $record->reference($context) : null,
            'items' => $items,
            'allowed_count' => $allowed,
            'refused_count' => count($items) - $allowed,
            'reasons' => MailingPolicy::reasons(),
            'note' => 'manager — личные письма менеджера из своего ящика (холодный контакт допустим, со ссылкой отписки); service — сервис рассылок (только при согласии). Служебные и личные письма эта проверка не ограничивает.',
        ];
    }

    /** Consent, opt-outs and the stop list of the contacts of a client or of given addresses. */
    public function contactState(array $a): array
    {
        $context = $this->context($a);
        $siteId = $this->siteId();
        $rows = [];
        $record = null;
        if ($this->hasRecord($a)) {
            $record = CrmRecord::resolve($a);
            $rows = MailingContacts::ofRecord($record->model);
        } else {
            foreach (array_filter(array_map('trim', (array)($a['values'] ?? ($a['value'] ?? [])))) as $value) {
                $kind = strpos($value, '@') !== false ? CmsCommContact::KIND_EMAIL : CmsCommContact::KIND_PHONE;
                if (($n = CmsCommContact::normalize($kind, $value)) !== null) {
                    $rows[] = ['kind' => $kind, 'value' => $n];
                }
            }
            if (!$rows) {
                throw new Exception('Pass values (addresses or phones) or a client: record_type + record_id.');
            }
        }
        $topics = [];
        foreach (CmsCommTopic::forSite($siteId) as $topic) {
            $topics[(int)$topic->id] = $topic;
        }
        $items = [];
        foreach ($rows as $row) {
            $contact = MailingContacts::find($siteId, $row['kind'], $row['value']);
            $state = MailingConsents::state($contact);
            $items[] = [
                'kind' => $row['kind'],
                'contact' => $row['kind'] === CmsCommContact::KIND_PHONE ? $context->phone($row['value']) : $context->email($row['value']),
                'has_marks' => (bool)($state['topics'] || $state['suppressions']),
                'stop_list' => array_values(array_map(static function (CmsCommSuppression $s) {
                    return ['reason' => (string)$s->reason, 'text' => $s->reasonText(), 'at' => CrmDate::format($s->created_at ? (int)$s->created_at : null)];
                }, $state['suppressions'])),
                'topics' => array_values(array_map(static function (CmsCommContactTopic $s) use ($topics) {
                    return array_filter([
                        'topic_id' => (int)$s->cms_comm_topic_id,
                        'topic' => isset($topics[(int)$s->cms_comm_topic_id]) ? (string)$topics[(int)$s->cms_comm_topic_id]->name : null,
                        'status' => (string)$s->status,
                        'state' => $s->stateText(),
                        'basis' => $s->basis,
                        'consent_at' => CrmDate::format($s->consent_at ? (int)$s->consent_at : null),
                        'unsubscribed_at' => CrmDate::format($s->unsubscribed_at ? (int)$s->unsubscribed_at : null),
                        'unsubscribe_source' => $s->unsubscribe_source,
                    ], static function ($v) { return $v !== null; });
                }, $state['topics'])),
            ];
        }
        return ['record' => $record ? $record->reference($context) : null, 'items' => $items, 'total' => count($items), 'include_contacts' => $context->includeContacts];
    }

    /* ---------------------------------------------------------------- shared */

    public function visibleMessage(int $id): CmsCommMessage
    {
        $m = $id ? CmsCommMessage::find()->forManager()->andWhere([CmsCommMessage::tableName().'.id' => $id])->one() : null;
        if (!$m) {
            throw new Exception('Message #'.$id.' not found or unavailable.');
        }
        return $m;
    }

    public function messageData(CmsCommMessage $m, CrmReadContext $context, bool $full, int $length = 0): array
    {
        $incoming = $m->direction === CmsCommMessage::DIRECTION_IN;
        $data = [
            'id' => (int)$m->id,
            'date' => CrmDate::format((int)($m->message_at ?: $m->created_at)),
            'kind' => self::kind($m),
            'direction' => (string)$m->direction,
            'channel' => (string)$m->channel,
            'status' => (string)$m->status,
            'status_text' => $m->getStatusName(),
            'subject' => $context->text((string)$m->subject, 300),
            'from' => $this->party((string)$m->from_address, MailText::name($m->from_name), $m->channel, $context),
            'to' => $this->parties($m->getTo(), $m->channel, $context),
            'cms_comm_conversation_id' => (int)$m->cms_comm_conversation_id,
            'cms_comm_account_id' => $m->cms_comm_account_id ? (int)$m->cms_comm_account_id : null,
            'sent_by' => $m->sentBy ? ['id' => (int)$m->sent_by_user_id, 'name' => (string)$m->sentBy->shortDisplayName] : null,
            'is_auto' => (bool)$m->is_auto,
            'attachments' => array_map(static function ($f) { return (string)$f->name; }, $m->getAttachmentFiles()) ?: array_values(array_filter(array_map(static function ($f) { return (string)($f['name'] ?? ''); }, $m->getAttachmentList()))),
            'links' => $this->links($m),
        ];
        if ($m->hasAttribute('cms_comm_campaign_id') && $m->cms_comm_campaign_id) {
            $data['cms_comm_campaign_id'] = (int)$m->cms_comm_campaign_id;
        }
        if ($full) {
            $cc = $m->getCc();
            if ($cc) {
                $data['cc'] = $this->parties($cc, $m->channel, $context);
            }
            [$text, $cut] = $this->ownText($m);
            $data['text'] = $context->text($text, $length ?: $this->textLength);
            $data['text_is_cut'] = $cut || mb_strlen($text) > ($length ?: $this->textLength);
            if ($m->error && !$incoming) {
                $data['error'] = PersonalData::cut((string)$m->error, 300);
            }
        } else {
            $data['snippet'] = $context->text($this->ownText($m)[0], 300);
        }
        return $data;
    }

    /** "email_in", "email_out", "sms_out", "site_in": the kind of a letter for histories. */
    public static function kind(CmsCommMessage $m): string
    {
        $channel = $m->channel === SmsSendChannel::CHANNEL ? 'sms' : ($m->channel === SiteMessages::CHANNEL ? 'site' : 'email');
        return $channel.'_'.($m->direction === CmsCommMessage::DIRECTION_IN ? 'in' : 'out');
    }

    /**
     * The own text of a letter: the quoted history removed (MailText), then
     * the signature and mobile footer cut off.
     *
     * @return array [text, whether something was cut]
     */
    public function ownText(CmsCommMessage $m): array
    {
        $text = PersonalData::plain(MailText::withoutQuotes($m));
        $lines = preg_split('/\R/u', $text);
        $cut = false;
        // A signature starts in the lower part of a letter, not in its first lines.
        for ($i = max(1, (int)floor(count($lines) * 0.3)); $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            foreach ($this->signatureStarts as $pattern) {
                if ($line !== '' && preg_match($pattern, $line)) {
                    $lines = array_slice($lines, 0, $i);
                    $cut = true;
                    break 2;
                }
            }
        }
        return [trim(implode("\n", $lines)), $cut];
    }

    protected function party(string $address, string $name, string $channel, CrmReadContext $context): array
    {
        $phone = $channel === SmsSendChannel::CHANNEL;
        return array_filter([
            'name' => $name !== '' ? $context->text($name, 200) : null,
            'address' => $address !== '' ? ($phone ? $context->phone($address) : $context->email($address)) : null,
        ], static function ($v) { return $v !== null; });
    }

    protected function parties(array $list, string $channel, CrmReadContext $context): array
    {
        $result = [];
        foreach ($list as $address => $name) {
            $result[] = $this->party((string)$address, MailText::name(is_string($name) ? $name : ''), $channel, $context);
        }
        return $result;
    }

    /** CRM records of a letter the employee may see. */
    protected function links(CmsCommMessage $m): array
    {
        $user = \Yii::$app->user->identity;
        $result = [];
        foreach ($m->links as $link) {
            $type = CrmRecords::typeOfCode((string)$link->model_code);
            if ($type && $user && CrmRecords::isVisible((string)$link->model_code, (int)$link->model_id, $user)) {
                $result[] = ['type' => $type, 'id' => (int)$link->model_id, 'link_type' => (string)$link->link_type];
            }
        }
        return $result;
    }

    protected function hasRecord(array $a): bool
    {
        return !empty($a['record_type']) || !empty($a['cms_lead_id']) || !empty($a['cms_company_id']) || !empty($a['cms_user_id']);
    }

    protected function context(array $a): CrmReadContext
    {
        return new CrmReadContext(['includeContacts' => !empty($a['include_contacts'])]);
    }

    protected function siteId(): int
    {
        if (!\Yii::$app->skeeks->site) {
            throw new Exception('Site is not resolved.');
        }
        return (int)\Yii::$app->skeeks->site->id;
    }
}
