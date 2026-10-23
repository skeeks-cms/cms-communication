<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommConversation;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\communication\send\SendableInterface;
use skeeks\cms\communication\send\SendContext;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsUserEmail;
use skeeks\cms\models\User;
use yii\helpers\Json;

/**
 * Writing a letter from the CRM: which mailboxes an employee may send from,
 * whom to, and queueing the letter with its links and feed entries. The same
 * operation serves the admin dialog and future API/AI callers.
 */
class MailComposeService
{
    private User $user;
    private int $siteId;
    private AddressBook $addresses;

    public function __construct(User $user, ?int $siteId = null)
    {
        $this->user = $user;
        $this->siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
        $this->addresses = new AddressBook();
    }

    /** @return CmsCommAccount[] active mailboxes with a connection the employee may send from */
    public function sendableAccounts(): array
    {
        $query = CmsCommAccount::find()
            ->andWhere(['cms_site_id' => $this->siteId, 'is_active' => 1])
            ->andWhere(['not', ['cms_comm_connection_id' => null]])
            ->orderBy(['address' => SORT_ASC]);
        // Administrators too: sending follows the employee's own access rows.
        $query->andWhere(['id' => CmsCommAccountAccess::find()->select('cms_comm_account_id')
            ->andWhere(['cms_user_id' => $this->user->id])
            ->andWhere(['!=', 'send_level', CmsCommAccountAccess::SEND_NONE])]);

        return $query->all();
    }

    public function defaultAccountId(): ?int
    {
        $ids = array_map(static fn(CmsCommAccount $a) => (int)$a->id, $this->sendableAccounts());
        $default = CmsCommAccountAccess::find()->select('cms_comm_account_id')
            ->andWhere(['cms_user_id' => $this->user->id, 'is_default' => 1, 'cms_comm_account_id' => $ids ?: [0]])
            ->scalar();

        return $default ? (int)$default : ($ids[0] ?? null);
    }

    public function canSendFrom(int $accountId): bool
    {
        foreach ($this->sendableAccounts() as $account) {
            if ((int)$account->id === $accountId) {
                return true;
            }
        }

        return false;
    }

    /** Text of the first recipient problem, or null. */
    public function recipientsError(ComposeForm $form, array $addresses): ?string
    {
        $component = \Yii::$app->communication;
        foreach ($addresses as $address) {
            if (!$component->isRecipientAllowed($address)) {
                return "Отправка на «{$address}» запрещена настройкой allowedRecipients (на стенде разрешены: ".implode(', ', $component->allowedRecipients).').';
            }
        }

        // An advertising letter: one recipient, and only one the policy allows (stop list, opt-out of the topic).
        if ($form->cms_comm_topic_id) {
            if (count($form->getToAddresses()) !== 1 || $form->getCcAddresses() || $form->getBccAddresses()) {
                return 'Письмо рассылки уходит одному получателю, без копий.';
            }
            $check = MailingPolicy::check($this->siteId, 'email', (string)$form->getToAddresses()[0], (int)$form->cms_comm_topic_id);
            if (!$check['allowed']) {
                return 'Рассылка на «'.$form->getToAddresses()[0].'» не отправляется: '.mb_strtolower($check['message']).'.';
            }
        }

        if ($this->sendLevel((int)$form->cms_comm_account_id) !== CmsCommAccountAccess::SEND_OWN) {
            return null;
        }
        // "Only own": addresses of visible leads, companies, clients, or of the answered conversation.
        $conversationAddresses = $this->replyAddresses($form);
        foreach ($addresses as $address) {
            if (in_array($address, $conversationAddresses, true)) {
                continue;
            }
            if (!$this->addresses->findRecords([$address], $this->siteId, $this->user)) {
                return "С этого ящика вы можете писать только своим лидам, компаниям и клиентам. Адрес «{$address}» не найден среди них.";
            }
        }

        return null;
    }

    public function sendLevel(int $accountId): string
    {
        return (string)(CmsCommAccountAccess::find()->select('send_level')
            ->andWhere(['cms_user_id' => $this->user->id, 'cms_comm_account_id' => $accountId])
            ->scalar() ?: CmsCommAccountAccess::SEND_NONE);
    }

    /**
     * Fills the dialog: recipient, context card, the answered letter (all its
     * participants with $replyAll) or the forwarded one. The text stays empty:
     * the quote or the forwarded letter is appended by queue().
     */
    public function prefill(ComposeForm $form, ?string $to, ?string $contextCode, ?int $contextId, ?int $replyToId, ?int $forwardId = null, bool $replyAll = false): void
    {
        $form->cms_comm_account_id = $form->cms_comm_account_id ?: $this->defaultAccountId();

        if ($forwardId && ($original = $this->visibleMessage($forwardId))) {
            $form->forward_message_id = (int)$original->id;
            $subject = (string)$original->subject;
            $form->subject = preg_match('/^(fwd?|пересл)\s*:/iu', $subject) ? $subject : 'Fwd: '.$subject;
            // The forwarded letter's attachments go with it; the employee may remove them.
            // Attachments kept only in the mailbox are fetched by the form first.
            $form->file_ids = array_values(array_map(static fn($f) => (int)$f->storage_file_id,
                array_filter($original->getAttachmentFiles(), static fn($f) => $f->isStored())));
            $this->answerFromMailboxOf($form, $original);
            return;
        }

        if ($to) {
            $form->to = $to;
        }
        if ($contextCode && $contextId && $this->contextRecord($contextCode, $contextId)) {
            $form->context_model_code = $contextCode;
            $form->context_model_id = $contextId;
        }
        if ($replyToId && ($original = $this->visibleMessage($replyToId))) {
            $form->reply_to_message_id = (int)$original->id;
            if ($replyAll) {
                $form->reply_all = 1;
                [$toList, $ccList] = self::replyAllRecipients($original, $this->ourMailboxAddresses());
                $form->to = implode(', ', $toList);
                $form->cc = implode(', ', $ccList);
            } else {
                $form->to = $original->direction === CmsCommMessage::DIRECTION_IN
                    ? (string)$original->from_address
                    : implode(', ', array_keys($original->getTo()));
            }
            $subject = (string)$original->subject;
            $form->subject = preg_match('/^(re|ответ)\s*:/iu', $subject) ? $subject : 'Re: '.$subject;
            $this->answerFromMailboxOf($form, $original);
        }
    }

    /**
     * Files the employee may put into a letter: uploads of this session still
     * waiting in the temporary area, and attachments of the forwarded letter.
     */
    public function filesError(ComposeForm $form): ?string
    {
        $size = 0;
        foreach (array_merge((array)$form->uploads, $form->getImageUploads()) as $token) {
            $path = MailFiles::tempPath((string)$token);
            if (!$path) {
                return 'Файл «'.MailFiles::tempName((string)$token).'» устарел или недоступен: загрузите его заново.';
            }
            if (in_array($token, (array)$form->uploads, true)) {
                $size += (int)filesize($path);
            }
        }
        foreach ($this->sendableItems($form) as $item) {
            foreach ($item['sendable']->getSendFiles() as $file) {
                $size += $file->size();
            }
        }
        $storageIds = array_map('intval', (array)$form->file_ids);
        if ($storageIds) {
            $source = $form->forward_message_id ? $this->visibleMessage((int)$form->forward_message_id) : null;
            $allowed = $source ? array_map(static fn($f) => (int)$f->storage_file_id, $source->files) : [];
            if (array_diff($storageIds, $allowed)) {
                return 'Вложение пересылаемого письма недоступно.';
            }
            $size += (int)CmsStorageFile::find()->andWhere(['id' => $storageIds])->sum('size');
        }
        if ($size > MailFiles::MAX_SIZE) {
            return 'Вложения больше '.(MailFiles::MAX_SIZE / 1024 / 1024).' МБ: почтовые серверы такие письма не принимают.';
        }

        return null;
    }

    /** Text of the first problem with "Прикрепить из…" items, or null. */
    public function sendablesError(ComposeForm $form): ?string
    {
        $sends = new SendService($this->user, $this->siteId);
        foreach ((array)$form->sendables as $ref) {
            if (!$sends->findRef((string)$ref)) {
                return 'Документ для отправки не найден или у вас нет к нему доступа.';
            }
        }

        return null;
    }

    /**
     * Things of other packages in the letter, found for the employee.
     *
     * @return array[] each: ref, type, id, sendable (SendableInterface)
     */
    public function sendableItems(ComposeForm $form): array
    {
        $sends = new SendService($this->user, $this->siteId);
        $result = [];
        foreach ((array)$form->sendables as $ref) {
            $parsed = SendService::parseRef((string)$ref);
            $sendable = $parsed ? $sends->find($parsed[0], $parsed[1]) : null;
            if ($sendable) {
                $result[] = ['ref' => $parsed[0].':'.$parsed[1], 'type' => $parsed[0], 'id' => $parsed[1], 'sendable' => $sendable];
            }
        }

        return $result;
    }

    /** @return array[] each: ref, title, button, link (editor HTML), files (names) — "Прикрепить из…" chips of the form */
    public function formSendables(ComposeForm $form): array
    {
        return array_map(static fn($item) => [
            'ref' => $item['ref'],
            'title' => $item['sendable']->getSendTitle(),
            'button' => $item['sendable']->getSendButton(),
            'link' => SendService::editorLink($item['type'], $item['id'], $item['sendable']->getSendButton()),
            'files' => array_map(static fn($f) => $f->name, $item['sendable']->getSendFiles()),
        ], $this->sendableItems($form));
    }

    /** @return array[] each: file (CmsStorageFile), name — attachments kept from the forwarded letter */
    private function composeFiles(ComposeForm $form, ?CmsCommMessage $source): array
    {
        $ids = array_map('intval', (array)$form->file_ids);
        if (!$ids) {
            return [];
        }
        $names = [];
        if ($source) {
            foreach ($source->files as $file) {
                $names[(int)$file->storage_file_id] = $file->name;
            }
        }
        $files = CmsStorageFile::find()->andWhere(['id' => $ids])->indexBy('id')->all();
        $result = [];
        foreach ($ids as $id) {
            if (isset($files[$id])) {
                $result[] = ['file' => $files[$id], 'name' => (string)($names[$id] ?? ($files[$id]->original_name ?: 'файл'))];
            }
        }

        return $result;
    }

    /** @return array[] each: id, name, size, type — files of the form for the editor */
    public function formFiles(ComposeForm $form): array
    {
        $source = $form->forward_message_id ? $this->visibleMessage((int)$form->forward_message_id) : null;
        return array_map(static fn($a) => [
            'id' => (int)$a['file']->id,
            'name' => $a['name'],
            'size' => (int)$a['file']->size,
            'type' => (string)$a['file']->mime_type,
        ], $this->composeFiles($form, $source));
    }

    /** Answer or forward from the mailbox the letter lies in, when allowed. */
    private function answerFromMailboxOf(ComposeForm $form, CmsCommMessage $original): void
    {
        $ids = array_map(static fn($copy) => (int)$copy->cms_comm_account_id, $original->mailboxCopies);
        if ($original->cms_comm_account_id) {
            array_unshift($ids, (int)$original->cms_comm_account_id);
        }
        foreach ($ids as $id) {
            if ($this->canSendFrom($id)) {
                $form->cms_comm_account_id = $id;
                return;
            }
        }
    }

    /**
     * "Ответить всем": the sender and every other recipient, without our own
     * mailboxes. An outgoing letter is answered to its recipients again.
     *
     * @param string[] $ourAddresses mailbox addresses in lower case
     * @return array{0: string[], 1: string[]} [to, cc]
     */
    public static function replyAllRecipients(CmsCommMessage $original, array $ourAddresses): array
    {
        $normalize = static fn(array $list) => array_map(static fn($a) => mb_strtolower(trim((string)$a)), $list);
        $ours = array_fill_keys($normalize($ourAddresses), true);
        $to = $original->direction === CmsCommMessage::DIRECTION_IN
            ? array_merge([(string)$original->from_address], array_keys($original->getTo()))
            : array_keys($original->getTo());
        $to = array_values(array_unique(array_filter($normalize($to), static fn($a) => $a !== '' && !isset($ours[$a]))));
        $cc = array_values(array_unique(array_filter($normalize(array_keys($original->getCc())),
            static fn($a) => $a !== '' && !isset($ours[$a]) && !in_array($a, $to, true))));
        // A letter between our own mailboxes only: answer its sender.
        if (!$to && $original->from_address) {
            $to = [mb_strtolower((string)$original->from_address)];
        }

        return [$to, $cc];
    }

    /** @return string[] addresses of this site's mailboxes */
    private function ourMailboxAddresses(): array
    {
        return array_map(static fn($a) => mb_strtolower((string)$a),
            CmsCommAccount::find()->select('address')->andWhere(['cms_site_id' => $this->siteId])->column());
    }

    /**
     * The answered letter quoted, or the forwarded letter attached, below the
     * text: [html, text].
     */
    public function quoteOf(CmsCommMessage $original, bool $forward): array
    {
        $formatter = \Yii::$app->formatter;
        $date = $formatter->asDatetime($original->message_at, 'php:d.m.Y H:i');
        $html = $original->body_html ? \skeeks\cms\communication\helpers\MailHtml::quoteBody((string)$original->body_html) : '';
        if ($html === '') {
            $html = \skeeks\cms\helpers\RichTextHtml::fromText((string)$original->body_text);
        }
        $text = trim((string)($original->body_text ?: \skeeks\cms\helpers\RichTextHtml::toText((string)$original->body_html)));
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        if ($forward) {
            $head = [
                'От' => $original->getFromAsText(),
                'Дата' => $date,
                'Тема' => (string)$original->subject,
                'Кому' => $original->getToAsText(),
            ];
            $headHtml = '';
            $headText = '';
            foreach ($head as $label => $value) {
                $headHtml .= $e($label).': '.$e($value).'<br>';
                $headText .= $label.': '.$value."\n";
            }

            return [
                '<div>---------- Пересланное сообщение ----------<br>'.$headHtml.'</div><br><div>'.$html.'</div>',
                "---------- Пересланное сообщение ----------\n".$headText."\n".$text,
            ];
        }

        $intro = $date.', '.$original->getFromAsText().':';

        return [
            '<div>'.$e($intro).'</div><blockquote style="margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex">'.$html.'</blockquote>',
            $intro."\n".preg_replace('/^/m', '> ', $text),
        ];
    }

    /**
     * Recipients for the "Кому" field: colleagues, visible leads, companies,
     * clients and people from visible letters, matching the typed text.
     *
     * @return array[] each: email, name, type (title of the kind of record)
     */
    public function suggestRecipients(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = ['like', new \yii\db\Expression('LOWER(value)'), mb_strtolower($q)];
        $found = [];
        $add = static function (string $email, string $name, string $type) use (&$found) {
            $email = mb_strtolower(trim($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($found[$email])) {
                $found[$email] = ['email' => $email, 'name' => trim($name), 'type' => $type];
            }
        };

        // Leads and companies: by email and by name.
        $leadIds = CmsLead::find()->forManager($this->user)->andWhere([CmsLead::tableName().'.cms_site_id' => $this->siteId])
            ->andWhere(['or',
                [CmsLead::tableName().'.id' => \skeeks\cms\models\CmsLeadEmail::find()->select('cms_lead_id')->andWhere($like)],
                [CmsLead::tableName().'.id' => CmsLead::find()->select(CmsLead::tableName().'.id')->search($q)],
            ])->select(CmsLead::tableName().'.id')->limit(50)->column();
        foreach (\skeeks\cms\models\CmsLeadEmail::find()->andWhere(['cms_lead_id' => $leadIds ?: [0]])->with('cmsLead')->limit(50)->all() as $row) {
            $add((string)$row->value, $row->name ?: ($row->cmsLead ? (string)$row->cmsLead->asText() : ''), 'Лид');
        }

        $companyIds = CmsCompany::find()->forManager($this->user)
            ->andWhere(['or',
                [CmsCompany::tableName().'.id' => \skeeks\cms\models\CmsCompanyEmail::find()->select('cms_company_id')->andWhere($like)],
                [CmsCompany::tableName().'.id' => CmsCompany::find()->select(CmsCompany::tableName().'.id')->search($q)],
            ])->select(CmsCompany::tableName().'.id')->distinct()->limit(50)->column();
        foreach (\skeeks\cms\models\CmsCompanyEmail::find()->andWhere(['cms_company_id' => $companyIds ?: [0]])->with('cmsCompany')->limit(50)->all() as $row) {
            $add((string)$row->value, $row->cmsCompany ? (string)$row->cmsCompany->asText() : '', 'Компания');
        }

        // Colleagues and visible clients by email or name.
        $u = CmsUser::tableName();
        $userIds = CmsUser::find()->select($u.'.id')
            ->andWhere(['or',
                [$u.'.is_worker' => 1],
                ['and', [$u.'.is_worker' => 0], [$u.'.id' => CmsUser::find()->forManager($this->user)->select($u.'.id')]],
            ])
            ->andWhere(['or',
                [$u.'.id' => CmsUserEmail::find()->select('cms_user_id')->andWhere($like)],
                ['like', $u.'.first_name', $q],
                ['like', $u.'.last_name', $q],
            ])->limit(30)->column();
        foreach (CmsUserEmail::find()->andWhere(['cms_user_id' => $userIds ?: [0]])->with('cmsUser')->limit(50)->all() as $row) {
            $user = $row->cmsUser;
            if ($user) {
                $add((string)$row->value, (string)$user->shortDisplayName, $user->is_worker ? 'Сотрудник' : 'Клиент');
            }
        }

        // People from letters the employee may read.
        $t = CmsCommMessage::tableName();
        $rows = CmsCommMessage::find()->cmsSite($this->siteId)->forManager($this->user)
            ->select([$t.'.from_address', $t.'.from_name'])
            ->andWhere(['direction' => CmsCommMessage::DIRECTION_IN])
            ->andWhere(['or', ['like', $t.'.from_address', $q], ['like', $t.'.from_name', $q]])
            ->orderBy([$t.'.message_at' => SORT_DESC])->limit(30)->asArray()->all();
        foreach ($rows as $row) {
            $add((string)$row['from_address'], (string)$row['from_name'], 'Переписка');
        }

        // Only what visibly matches the typed text; exact starts first.
        $q = mb_strtolower($q);
        $items = array_values(array_filter($found, static fn($i) => mb_strpos($i['email'], $q) !== false || mb_stripos($i['name'], $q) !== false));
        usort($items, static function ($a, $b) use ($q) {
            $rank = static fn($i) => (int)(strpos($i['email'], $q) === 0 || mb_stripos($i['name'], $q) === 0);
            return $rank($b) <=> $rank($a);
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * Queues the letter: message, mailbox copy, links, feed entries and the
     * send job in one transaction.
     */
    public function queue(ComposeForm $form): CmsCommMessage
    {
        $account = CmsCommAccount::findOne((int)$form->cms_comm_account_id);
        $original = $form->reply_to_message_id ? $this->visibleMessage((int)$form->reply_to_message_id) : null;
        $forwarded = $form->forward_message_id ? $this->visibleMessage((int)$form->forward_message_id) : null;
        $domain = substr(strrchr((string)$account->address, '@') ?: '@localhost', 1);
        $externalId = 'sx.'.bin2hex(random_bytes(10)).'@'.$domain;
        $to = array_fill_keys($form->getToAddresses(), '');
        $cc = array_fill_keys($form->getCcAddresses(), '');
        $bcc = array_diff_key(array_fill_keys($form->getBccAddresses(), ''), $to + $cc);

        $text = trim($form->getText());
        $html = $form->getHtml();
        // Images pasted into the text travel inside the letter, referenced by cid:.
        $images = [];
        $html = (string)preg_replace_callback('/<img[^>]*\bdata-sx-upload="([^"]+)"[^>]*>/i', static function ($m) use (&$images, $domain) {
            $token = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // One picture copied twice in the text travels once.
            $cid = array_search($token, $images, true) ?: 'sx'.bin2hex(random_bytes(6)).'@'.$domain;
            $images[$cid] = $token;
            return '<img src="cid:'.$cid.'" alt="" style="max-width:100%">';
        }, $html);
        $quoted = $forwarded ? $this->quoteOf($forwarded, true) : ($original && $form->include_quote ? $this->quoteOf($original, false) : null);
        $sendables = $this->sendableItems($form);
        $attachments = $this->composeFiles($form, $forwarded ?: $original);
        $list = array_map(static fn($a) => ['name' => $a['name'], 'type' => $a['file']->mime_type, 'size' => (int)$a['file']->size], $attachments);
        foreach ((array)$form->uploads as $token) {
            $path = (string)MailFiles::tempPath((string)$token);
            $list[] = ['name' => MailFiles::tempName((string)$token), 'type' => (string)@mime_content_type($path), 'size' => (int)@filesize($path)];
        }

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            // "Прикрепить из…" and "Отправить клиенту": a personal link of this letter, its files.
            // The links are made inside the transaction: a failed letter leaves no send behind.
            $sends = [];
            foreach ($sendables as $item) {
                /** @var SendableInterface $sendable */
                $sendable = $item['sendable'];
                $context = $this->sendContext($form, $item['type'], (int)$item['id']);
                $context->url = $sendable->createSendLink($context);
                [$html, $text] = SendService::applyLink($html, $text, $item['type'], (int)$item['id'], $context->url, $sendable->getSendButton());
                $files = [];
                foreach ($sendable->getSendFiles() as $file) {
                    $files[] = ['file' => SendService::storeFile($file, $this->siteId), 'name' => $file->name];
                    $list[] = ['name' => $file->name, 'type' => $file->type, 'size' => $file->size()];
                }
                $sends[] = ['sendable' => $sendable, 'context' => $context, 'files' => $files];
            }
            if ($quoted) {
                $text = ($text !== '' ? $text."\n\n" : '').$quoted[1];
                $html = ($html !== '' ? $html.'<br><br>' : '').$quoted[0];
            }

            if ($original) {
                $conversation = $original->conversation;
            } else {
                $conversation = new CmsCommConversation([
                    'cms_site_id' => $this->siteId,
                    'channel' => CmsCommAccount::CHANNEL_EMAIL,
                    'thread_key' => $externalId,
                    'subject' => mb_substr($form->subject, 0, 500),
                ]);
            }
            $conversation->last_message_at = time();
            $conversation->save(false);

            $references = [];
            if ($original) {
                $references = array_filter(explode(' ', (string)$original->references_list));
                $references[] = $original->external_id;
            }

            $message = new CmsCommMessage([
                'cms_site_id' => $this->siteId,
                'cms_comm_conversation_id' => $conversation->id,
                'cms_comm_account_id' => $account->id,
                'channel' => CmsCommAccount::CHANNEL_EMAIL,
                'direction' => CmsCommMessage::DIRECTION_OUT,
                'status' => CmsCommMessage::STATUS_QUEUED,
                'external_id' => $externalId,
                'in_reply_to' => $original ? $original->external_id : null,
                'references_list' => $references ? implode(' ', array_unique($references)) : null,
                'from_address' => $account->address,
                'from_name' => $account->name,
                'to_list' => Json::encode($to),
                'cc_list' => $cc ? Json::encode($cc) : null,
                'subject' => mb_substr($form->subject, 0, 500),
                'body_text' => $text,
                'body_html' => '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5">'.$html.'</div>',
                'attachments' => $list ? Json::encode($list) : null,
                'is_internal' => 0,
                'is_auto' => 0,
                'message_at' => time(),
                'sent_by_user_id' => (int)$this->user->id,
            ]);
            if ($bcc && $message->hasAttribute('bcc_list')) {
                $message->bcc_list = Json::encode($bcc);
            }
            $message->save(false);

            // An advertising letter: the personal opt-out link of its recipient, knowing this letter.
            if ($form->cms_comm_topic_id) {
                $contact = MailingContacts::get($this->siteId, CmsCommContact::KIND_EMAIL, (string)array_key_first($to));
                $url = Unsubscribe::url($contact, (int)$form->cms_comm_topic_id, (int)$message->id);
                [$html, $text] = Unsubscribe::apply($html, $text, $url);
                $message->body_text = $text;
                $message->body_html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5">'.$html.'</div>';
                $message->cms_comm_topic_id = (int)$form->cms_comm_topic_id;
                $message->unsubscribe_url = $url;
                $message->save(false);
            }

            (new CmsCommMessageMailbox([
                'cms_comm_message_id' => $message->id,
                'cms_comm_account_id' => $account->id,
                'cms_comm_connection_id' => $account->cms_comm_connection_id,
            ]))->save(false);

            $priority = 0;
            foreach ($attachments as $attachment) {
                MailFiles::attach($message, $attachment['file'], $attachment['name'], null, null, null, false, $priority += 10);
            }
            foreach ($sends as $send) {
                foreach ($send['files'] as $file) {
                    MailFiles::attach($message, $file['file'], $file['name'], null, null, null, false, $priority += 10);
                }
            }
            // Editor uploads leave the temporary area only now, with a letter that owns them.
            foreach ((array)$form->uploads as $token) {
                $file = MailFiles::storeFromTemp((string)$token, $this->siteId);
                MailFiles::attach($message, $file, MailFiles::tempName((string)$token), null, null, null, false, $priority += 10);
            }
            foreach ($images as $cid => $token) {
                $file = MailFiles::storeFromTemp($token, $this->siteId);
                MailFiles::attach($message, $file, MailFiles::tempName($token), null, null, $cid, true, $priority += 10);
            }
            // The quoted or forwarded letter keeps its own pictures (same cid: references).
            $source = $forwarded ?: ($original && $form->include_quote ? $original : null);
            if ($source) {
                foreach ($source->files as $file) {
                    if ($file->is_inline && $file->content_id && $file->storageFile) {
                        MailFiles::attach($message, $file->storageFile, $file->name, $file->mime_type, $file->size, $file->content_id, true, $priority += 10);
                    }
                }
            }

            $linker = new MessageLinker();
            if ($form->context_model_code && $form->context_model_id
                && ($record = $this->contextRecord($form->context_model_code, (int)$form->context_model_id))) {
                $linker->link($message, [$record], CmsCommLink::TYPE_CONTEXT, (int)$this->user->id);
            }
            $external = array_values(array_filter(array_keys($to + $cc + $bcc), fn($a) => !$this->addresses->isOurs($a, $this->siteId)));
            $linker->link($message, $this->addresses->findRecords($external, $this->siteId), CmsCommLink::TYPE_AUTO, (int)$this->user->id);
            $linker->inheritFromConversation($message);

            // The send lands in the feeds of its records; the package learns which letter carries it.
            foreach ($sends as $send) {
                $linker->link($message, (new SendService($this->user, $this->siteId))->records($send['sendable'], $send['context']->record),
                    CmsCommLink::TYPE_CONTEXT, (int)$this->user->id);
                $send['sendable']->afterSend($send['context'], $message);
            }

            \Yii::$app->jobs->push('comm.mail.send', ['messageId' => (int)$message->id], ['siteId' => $this->siteId]);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $message;
    }

    /**
     * One send of a sendable by this letter: to its first recipient, with the
     * CRM record of that address (or the card the letter is written from).
     */
    private function sendContext(ComposeForm $form, string $type, int $id): SendContext
    {
        $recipient = (string)($form->getToAddresses()[0] ?? '');
        $context = new SendContext($type, $id, 'email', $recipient, $this->user);
        $records = $recipient !== '' ? $this->addresses->findRecords([$recipient], $this->siteId, $this->user) : [];
        $context->record = $records[0] ?? ($form->context_model_code && $form->context_model_id
            ? $this->contextRecord((string)$form->context_model_code, (int)$form->context_model_id) : null);
        if ($context->record) {
            $context->recipientName = CrmRecords::name($context->record);
        }

        return $context;
    }

    public function visibleMessage(int $id): ?CmsCommMessage
    {
        return CmsCommMessage::find()->cmsSite($this->siteId)->forManager($this->user)->andWhere([CmsCommMessage::tableName().'.id' => $id])->one();
    }

    /** A lead, company or client the employee can see. */
    public function contextRecord(string $code, int $id)
    {
        $classes = [
            (new CmsLead())->skeeksModelCode => CmsLead::class,
            (new CmsCompany())->skeeksModelCode => CmsCompany::class,
            (new CmsUser())->skeeksModelCode => CmsUser::class,
        ];
        if (!isset($classes[$code])) {
            return null;
        }
        $class = $classes[$code];

        return $class::find()->forManager($this->user)->andWhere([$class::tableName().'.id' => $id])->one();
    }

    /** @return string[] */
    private function replyAddresses(ComposeForm $form): array
    {
        if (!$form->reply_to_message_id || !($original = $this->visibleMessage((int)$form->reply_to_message_id))) {
            return [];
        }
        $addresses = [];
        foreach (CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => $original->cms_comm_conversation_id])->all() as $message) {
            $addresses[] = (string)$message->from_address;
            $addresses = array_merge($addresses, array_keys($message->getTo()), array_keys($message->getCc()));
        }

        return array_values(array_unique(array_filter($addresses)));
    }
}
