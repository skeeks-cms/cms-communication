<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\components\AiApiSettings;
use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommApiSend;
use skeeks\cms\communication\models\CmsCommContact;
use skeeks\cms\communication\models\CmsCommDraft;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommSuppression;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\communication\send\SendHistoryInterface;
use skeeks\cms\communication\send\SendRefusalInterface;
use skeeks\cms\helpers\RichTextHtml;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;

/**
 * Sending a personal letter through MCP/REST: only a saved draft of the
 * OAuth user, only after a check, only with an idempotency key.
 *
 * - check() reads the draft as the letter form of the mail screen does
 *   (MailDrafts::fill(), applyAiDraft(), ComposeForm::validate(): mailbox
 *   rights, recipients, "own clients only", MailingPolicy of the topic, the
 *   documents) and adds the AI rules (the address belongs to the client, no
 *   letter or send of the offer within recentDays, the offer may be sent
 *   now, the day limit of sends). It sends nothing, makes no personal link
 *   and no opening; the preview has a dead button. A passed check returns
 *   check_token — the fingerprint of the draft, sender, recipients, subject,
 *   text, offer and topic.
 * - send() takes the draft id, that token and an idempotency key. The token
 *   is recomputed from the draft: any change after the check refuses the
 *   send until a new check. The letter is queued by MailComposeService::queue()
 *   (personal link, opt-out, feed, links, the sending job) in one
 *   transaction with the CmsCommApiSend row of the key, then the draft is
 *   removed. A repeated key with the same draft and token returns the same
 *   letter; with other data it is refused. A refused send does not take the
 *   key.
 * - status() tells what is known, never more: queued, sending, sent (accepted
 *   by the outgoing mail server — not a delivery), failed, unknown (may or
 *   may not have left: never resent automatically). Delivery is not
 *   confirmed by mail; a bounce is reported when an automatic "undelivered"
 *   letter came back in the conversation or the address was put on the stop
 *   list as undeliverable after the send.
 */
class AiSend
{
    public const REASON_NOT_OWN = 'not_own';
    public const REASON_NO_TOPIC = 'no_topic';
    public const REASON_NO_CLIENT = 'no_client';
    public const REASON_ADDRESS = 'address_not_of_client';
    public const REASON_CHANGED = 'changed_after_check';
    public const REASON_KEY_REUSED = 'idempotency_key_reused';
    public const REASON_SEND_LIMIT = 'send_day_limit';
    public const REASON_SEND_DISABLED = 'api_send_disabled';

    private CmsUser $user;
    private int $siteId;
    private string $clientName;

    public function __construct(User $user, string $clientName, ?int $siteId = null)
    {
        $this->user = $user instanceof CmsUser ? $user : CmsUser::findOne((int)$user->id);
        $this->siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
        $this->clientName = $clientName;
    }

    /* Check */

    /**
     * @return array ok, check_token (when ok), problems [reason, message], draft, recipient, sender, topic, offers, preview, prepared
     */
    public function check(int $draftId, bool $testMode = false): array
    {
        $prepared = $this->prepare($draftId, $testMode);
        $form = $prepared['form'];
        $problems = $prepared['problems'];
        $result = [
            'ok' => !$problems,
            'draft' => ['id' => $draftId, 'owner' => ['id' => (int)$this->user->id, 'name' => (string)$this->user->shortDisplayName]],
            'test_mode' => $testMode,
        ];
        if ($form) {
            $result += $this->describe($form, $prepared);
        }
        if (!$problems) {
            $result['check_token'] = $prepared['token'];
            $result['next'] = 'Покажите получателя, тему, текст и предложение человеку. После его согласия: cms_comm_draft_send с draft_id, этим check_token, '
                .'новым idempotency_key и confirm=true. Если черновик изменится, проверку нужно повторить.';
        } else {
            $result['next'] = 'Исправьте причины (письмо в «Черновиках», cms_comm_draft_create заново или настройки CRM) и повторите проверку.';
        }
        $result['problems'] = $problems;

        return $result;
    }

    /**
     * The draft as the letter that would leave, with every problem found.
     *
     * @return array form (ComposeForm|null), draft, record, problems, token, sendables
     */
    private function prepare(int $draftId, bool $testMode = false): array
    {
        $problem = static fn(string $reason, string $message) => ['reason' => $reason, 'message' => $message];
        $drafts = new MailDrafts($this->user, $this->siteId);
        $draft = $drafts->find($draftId);
        if (!$draft) {
            $other = CmsCommDraft::find()->andWhere(['id' => $draftId, 'cms_site_id' => $this->siteId])->one();
            $owner = $other ? CmsUser::findOne((int)$other->cms_user_id) : null;
            $message = $other
                ? 'Черновик лежит в «Черновиках» сотрудника '.($owner ? $owner->shortDisplayName : '№'.$other->cms_user_id)
                    .': отправить его может только он. Для отправки через API создайте черновик в своём ящике (cms_comm_draft_create с cms_comm_account_id).'
                : 'Черновик не найден: он отправлен, удалён или не существует.';

            return ['form' => null, 'draft' => null, 'record' => null, 'problems' => [$problem($other ? self::REASON_NOT_OWN : AiActions::REASON_NOT_FOUND, $message)], 'token' => null];
        }

        $service = new MailComposeService($this->user, $this->siteId);
        $form = new ComposeForm(['service' => $service]);
        $drafts->fill($form, $draft);
        $form->cms_comm_account_id = $form->cms_comm_account_id ?: $service->defaultAccountId();
        $drafts->applyAiDraft($form);
        $problems = [];
        $warnings = [];

        // The purpose decides what the letter needs: a topic and the opt-out link, or a dialog, or cooperation.
        $purpose = LetterPurpose::of($draft->purpose, $draft->cms_comm_topic_id ? (int)$draft->cms_comm_topic_id : null);
        $form->purpose = $purpose;
        if (!$purpose) {
            $problems[] = $problem(self::REASON_NO_TOPIC, 'У черновика не задано назначение: через API отправляются письма ИИ с purpose (advertising с темой, individual_offer с темой и предложением, personal, service). '
                .'Задайте его: cms_comm_draft_update с purpose.');
        }
        if (!$form->validate()) {
            foreach ($form->getFirstErrors() as $error) {
                $problems[] = $problem(AiActions::REASON_INVALID, $error);
            }
        }

        // The client of the letter and the address of the client.
        $record = $form->context_model_code && $form->context_model_id ? $service->contextRecord((string)$form->context_model_code, (int)$form->context_model_id) : null;
        $to = $form->getToAddresses();
        if (!$record) {
            $problems[] = $problem(self::REASON_NO_CLIENT, 'Черновик не привязан к клиенту CRM, которого вы видите: через API пишут только лиду, компании или клиенту из CRM.');
        } elseif (count($to) !== 1 || !isset(Campaigns::recordAddresses($record)[$to[0]])) {
            $problems[] = $problem(self::REASON_ADDRESS, 'Получатель должен быть одним адресом из карточки клиента ('.implode(', ', array_keys(Campaigns::recordAddresses($record))).').');
        }
        if ($form->getCcAddresses() || $form->getBccAddresses()) {
            $problems[] = $problem(AiActions::REASON_INVALID, 'Через API письмо уходит одному получателю, без копий.');
        }
        if ($purpose && $record) {
            $warnings = LetterPurpose::check($purpose, $form->cms_comm_topic_id ? (int)$form->cms_comm_topic_id : null, $record,
                (string)($to[0] ?? ''), $this->siteId, $form->reply_to_message_id ? (int)$form->reply_to_message_id : null)['warnings'];
        }

        // Test mode: an administrator, a server-listed record and address; it skips only the repetition checks.
        if ($testMode) {
            $type = $record ? CrmRecords::typeOfCode(method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record)) : '';
            if (!\Yii::$app->authManager || !\Yii::$app->authManager->checkAccess((int)$this->user->id, \skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
                $problems[] = $problem('test_mode_not_allowed', 'Тестовый режим доступен только администратору.');
            } elseif (!$record || count($to) !== 1 || !AiActions::isTestRecipient((string)$type, (int)$record->primaryKey, (string)$to[0])) {
                $problems[] = $problem('test_mode_not_allowed', 'Тестовый режим только для разрешённых тестовых получателей (communication.aiTestRecipients): '
                    .($record ? $type.':'.$record->primaryKey : 'без клиента').' + '.implode(', ', $to).' среди них нет.');
            }
        }
        // A repetition is a mailing concern: personal and service letters continue a dialog.
        $repetition = LetterPurpose::withTopic($purpose) && !$testMode;
        if ($record && $repetition) {
            $days = AiActions::limits()['recentDays'];
            if ($days > 0 && ($at = AiActions::lastLetterAt($record, time() - $days * 86400))) {
                $problems[] = $problem(AiActions::REASON_RECENT_LETTER, 'Клиенту писали '.\Yii::$app->formatter->asDate($at, 'php:d.m.Y').' (меньше '.$days.' дней назад).');
            }
        }

        // The offer: still may be sent, not sent to this client recently.
        $sendables = $service->sendableItems($form);
        if (count($sendables) !== count((array)$form->sendables)) {
            $problems[] = $problem(AiActions::REASON_OFFER, 'Предложение черновика не найдено или вам его нельзя отправить.');
        }
        foreach ($sendables as $item) {
            if ($item['sendable'] instanceof SendRefusalInterface && ($refusal = $item['sendable']->getSendRefusal())) {
                $problems[] = $problem(AiActions::REASON_OFFER, $refusal);
            }
            $source = \Yii::$app->communication->getSendableSource($item['type']);
            $days = AiActions::limits()['recentDays'];
            if ($record && $repetition && $days > 0 && $source instanceof SendHistoryInterface && ($at = $source->lastSentAt((int)$item['id'], $record)) && $at >= time() - $days * 86400) {
                $problems[] = $problem(AiActions::REASON_RECENT_OFFER, 'Это предложение отправляли клиенту '.\Yii::$app->formatter->asDate($at, 'php:d.m.Y').'.');
            }
        }
        // An individual offer is an offer: a presentation or КП, and no opt-out line of its own.
        if ($purpose === LetterPurpose::INDIVIDUAL_OFFER) {
            if (!$form->sendables) {
                $problems[] = $problem(LetterPurpose::REASON_PURPOSE, 'Индивидуальное предложение отправляется с Презентацией или КП: укажите offer (cms_comm_draft_update).');
            }
            if (mb_strpos($form->getHtml(), Unsubscribe::PLACEHOLDER) !== false) {
                $problems[] = $problem(LetterPurpose::REASON_PURPOSE, 'В индивидуальном предложении нет строки «Отписаться»: уберите её из текста или выберите advertising.');
            }
        }

        // The signature: never invented by AI; the employee's own for this mailbox, or a warning.
        $signature = self::signature((int)$this->user->id, (int)$form->cms_comm_account_id, $form->getHtml());
        if ($signature === null) {
            $warnings[] = 'У вас нет подписи для этого ящика («Почта» → «Мои настройки почты» → «Подписи»): письмо уйдёт без подписи.';
        } elseif (!$signature['in_letter']) {
            $warnings[] = 'Вашей подписи этого ящика нет в тексте: письмо уйдёт без неё (cms_comm_draft_update с body_html добавит её).';
        }

        // Files: what the draft holds now, within the limits.
        $files = AiDraftFiles::describe($draft);
        if ($files['count'] > AiDraftFiles::MAX_FILES || $files['total_size'] > AiDraftFiles::MAX_TOTAL) {
            $problems[] = $problem('attachment', 'Не больше '.AiDraftFiles::MAX_FILES.' файлов и '.(AiDraftFiles::MAX_TOTAL / 1048576).' МБ на письмо.');
        }

        // The switch and limit of whoever manages mail ("Настройки почты" → «Работа ИИ через API»).
        if (!AiApiSettings::forSite($this->siteId)['sendEnabled']) {
            $problems[] = $problem(self::REASON_SEND_DISABLED, 'Отправка писем через API на этом сайте выключена. Включает сотрудник с правом управлять почтой (по умолчанию администратор): '
                .'«Настройки» → «Настройки почты» → «Работа ИИ через API» → «Разрешить отправку писем через API». Черновик остаётся в «Черновиках»: его можно отправить из «Почты».');
        }
        $limit = AiActions::limits()['sendPerDay'];
        if ($this->sentToday() >= $limit) {
            $problems[] = $problem(self::REASON_SEND_LIMIT, $limit > 0
                ? 'Дневной лимит отправок через API исчерпан: не больше '.$limit.' писем в день на сотрудника («Настройки почты» → «Работа ИИ через API»).'
                : 'Отправка через API ограничена нулём писем в день («Настройки почты» → «Работа ИИ через API»).');
        }

        return ['form' => $form, 'draft' => $draft, 'record' => $record, 'problems' => $problems, 'warnings' => $warnings, 'purpose' => $purpose,
            'files' => $files, 'token' => $this->token($draft, $form, $purpose, $testMode), 'sendables' => $sendables];
    }

    /**
     * Fingerprint of what would leave: draft, sender, recipients, subject, text, offers, purpose and topic,
     * client, files (by content) and the test mode. Upload tokens of pictures change with every request:
     * the text is taken without them, the pictures count by their content.
     */
    private function token(CmsCommDraft $draft, ComposeForm $form, ?string $purpose, bool $testMode): string
    {
        $sendables = array_map('strval', (array)$form->sendables);
        sort($sendables);
        $html = RichTextHtml::clean($form->getHtml(), ComposeForm::EDITOR_PRESET);
        $html = (string)preg_replace('~<img[^>]*data-sx-upload="[^"]*"[^>]*>~i', '<img data-sx-upload>', $html);
        $files = array_map(static fn($f) => [(string)$f->content_hash, (int)$f->is_inline, (string)$f->name], $draft->getFiles()->all());

        return hash('sha256', json_encode([
            (int)$draft->id, (int)$form->cms_comm_account_id,
            $form->getToAddresses(), $form->getCcAddresses(), $form->getBccAddresses(),
            trim((string)$form->subject), preg_replace('/\s+/u', ' ', trim($html)),
            $sendables, (string)$purpose, (int)$form->cms_comm_topic_id, (string)$form->context_model_code, (int)$form->context_model_id,
            $files, $testMode, $form->withOptOut(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * The signature of the employee for the mailbox and whether the letter has it: html and text, or null
     * when the employee has no signature for this mailbox ("Мои настройки почты" → «Подписи»).
     */
    public static function signature(int $userId, int $accountId, string $html): ?array
    {
        $signature = MailSignatures::of($userId, $accountId);

        return $signature === null ? null : ['html' => $signature, 'text' => trim(RichTextHtml::toText($signature)),
            'in_letter' => MailSignatures::has($html, $signature)];
    }

    /** What the person approves: who, from where, the subject, the text with a dead button, the offer. */
    private function describe(ComposeForm $form, array $prepared): array
    {
        $account = \skeeks\cms\communication\models\CmsCommAccount::findOne((int)$form->cms_comm_account_id);
        $html = $form->getHtml();
        $text = trim($form->getText());
        $offers = [];
        foreach ($prepared['sendables'] ?? [] as $item) {
            // A dead link: the preview never makes or shows a personal link.
            [$html, $text] = SendService::applyLink($html, $text, $item['type'], (int)$item['id'], '#offer-preview', $item['sendable']->getSendButton());
            $offers[] = ['ref' => $item['type'].':'.$item['id'], 'title' => $item['sendable']->getSendTitle(), 'button' => $item['sendable']->getSendButton()];
        }
        $topic = $form->cms_comm_topic_id ? CmsCommTopic::findOne((int)$form->cms_comm_topic_id) : null;
        $record = $prepared['record'];

        return [
            'recipient' => [
                'address' => (string)($form->getToAddresses()[0] ?? ''),
                'client' => $record ? ['type' => CrmRecords::typeOfCode(get_class($record)), 'id' => (int)$record->primaryKey, 'name' => CrmRecords::name($record)] : null,
            ],
            'sender' => $account ? ['cms_comm_account_id' => (int)$account->id, 'address' => (string)$account->address, 'name' => (string)$account->name] : null,
            'purpose' => $prepared['purpose'] ? ['code' => $prepared['purpose'], 'name' => LetterPurpose::names()[$prepared['purpose']],
                'opt_out' => $form->withOptOut()] : null,
            // The sender's signature of this mailbox: it is in the text above, the preview shows the letter as it leaves.
            'signature' => self::signature((int)$this->user->id, (int)$form->cms_comm_account_id, $form->getHtml()),
            // Will the letter carry the opt-out line and the List-Unsubscribe headers.
            'unsubscribe' => ['line' => $form->withOptOut(), 'headers' => $form->withOptOut()],
            'topic' => $topic ? ['id' => (int)$topic->id, 'name' => (string)$topic->name,
                'role' => $form->withOptOut() ? 'рассылка: ссылка «Отписаться» и заголовки отписки' : 'только проверка отказов получателя, в письмо не выводится'] : null,
            'offers' => $offers,
            'attachments' => $prepared['files'] ?? null,
            'warnings' => $prepared['warnings'] ?? [],
            'preview' => [
                'subject' => (string)$form->subject,
                'text' => $text,
                'html' => $html,
                'note' => 'Предпросмотр: кнопка предложения не ведёт на личную ссылку'
                    .($form->withOptOut() ? ', ссылка «Отписаться» добавится при отправке'
                        : ($prepared['purpose'] === LetterPurpose::INDIVIDUAL_OFFER ? ', строки «Отписаться» и заголовков отписки не будет (индивидуальное предложение)' : ', блока отписки нет — это не рассылка'))
                    .'; картинки и вложения — из attachments. Ничего не отправлено.',
            ],
            'prepared' => AiMark::forApi(is_array($form->aiMark) ? $form->aiMark : null),
        ];
    }

    /* Send */

    /**
     * @return array state (see status()), repeated, problems when refused
     */
    public function send(int $draftId, string $checkToken, string $key, bool $confirm, bool $testMode = false): array
    {
        $key = trim($key);
        if (!preg_match('/^[A-Za-z0-9._:\-]{8,128}$/', $key)) {
            throw new \DomainException('idempotency_key обязателен: 8–128 символов (латиница, цифры, . _ : -), новый для каждого письма. Сохраните его до ответа: по нему узнают результат.');
        }
        $hash = hash('sha256', $draftId.'|'.$checkToken);
        $existing = $this->byKey($key);
        if ($existing) {
            if ($existing->request_hash !== $hash) {
                return ['sent' => false, 'state' => 'refused', 'repeated' => true, 'problems' => [[
                    'reason' => self::REASON_KEY_REUSED,
                    'message' => 'Этот idempotency_key уже использован для другого письма (черновик '.$existing->cms_comm_draft_id.'). Для нового письма нужен новый ключ.',
                ]]];
            }

            return ['repeated' => true] + $this->status($existing);
        }

        $prepared = $this->prepare($draftId, $testMode);
        if ($prepared['problems']) {
            return ['sent' => false, 'state' => 'refused', 'repeated' => false, 'problems' => $prepared['problems'],
                'next' => 'Ничего не отправлено. Исправьте причины и повторите cms_comm_draft_check.'];
        }
        if (!hash_equals((string)$prepared['token'], $checkToken)) {
            return ['sent' => false, 'state' => 'refused', 'repeated' => false, 'problems' => [[
                'reason' => self::REASON_CHANGED,
                'message' => 'Черновик, отправитель, получатель, текст или предложение изменились после проверки (или check_token от другого черновика).',
            ]], 'next' => 'Ничего не отправлено. Повторите cms_comm_draft_check, покажите изменения человеку и отправьте с новым check_token.'];
        }
        if (!$confirm) {
            return ['sent' => false, 'state' => 'not_sent', 'requires_confirmation' => true, 'repeated' => false,
                'next' => 'Проверка пройдена, ничего не отправлено. Отправка — тот же вызов с confirm=true после явного согласия человека.'];
        }

        $row = null;
        try {
            CmsCommApiSend::getDb()->transaction(function () use ($prepared, $key, $hash, $draftId, $testMode, &$row) {
                $row = new CmsCommApiSend([
                    'cms_site_id' => $this->siteId,
                    'cms_user_id' => (int)$this->user->id,
                    'client' => mb_substr('mcp:'.($this->clientName !== '' ? $this->clientName : 'клиент'), 0, 190),
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                    'cms_comm_draft_id' => $draftId,
                    'purpose' => $prepared['purpose'],
                    'test_mode' => (int)$testMode,
                ]);
                $row->save(false);
                // The send of the offer learns that AI sent it (its feed entry says so); the letter's own mark stays as prepared.
                if (is_array($prepared['form']->aiMark)) {
                    $prepared['form']->aiMark['sent_via'] = $row->client;
                }
                $message = (new MailComposeService($this->user, $this->siteId))->queue($prepared['form']);
                $row->cms_comm_message_id = (int)$message->id;
                $row->save(false, ['cms_comm_message_id', 'updated_at']);
                MailDrafts::delete($prepared['draft']);
            });
        } catch (SendException $e) {
            return ['sent' => false, 'state' => 'refused', 'repeated' => false,
                'problems' => array_map(static fn($m) => ['reason' => AiActions::REASON_OFFER, 'message' => (string)$m], array_values($e->errors)),
                'next' => 'Ничего не отправлено: документ отказал в отправке.'];
        } catch (\yii\db\IntegrityException $e) {
            // The same key is being sent by a parallel request: its result is the answer.
            $existing = $this->byKey($key);
            if ($existing && $existing->request_hash === $hash) {
                return ['repeated' => true] + $this->status($existing);
            }
            throw $e;
        }

        return ['repeated' => false] + $this->status($row);
    }

    private function byKey(string $key): ?CmsCommApiSend
    {
        return CmsCommApiSend::find()->andWhere(['cms_site_id' => $this->siteId, 'cms_user_id' => (int)$this->user->id, 'idempotency_key' => $key])->one();
    }

    /** Letters the user sent through the API today (CRM time zone). */
    public function sentToday(): int
    {
        $tz = new \DateTimeZone(\Yii::$app->formatter->timeZone ?: date_default_timezone_get());

        return (int)CmsCommApiSend::find()->andWhere(['cms_site_id' => $this->siteId, 'cms_user_id' => (int)$this->user->id])
            ->andWhere(['not', ['cms_comm_message_id' => null]])
            ->andWhere(['>=', 'created_at', (new \DateTime('today', $tz))->getTimestamp()])->count();
    }

    /* Status */

    /** The send of a key of this user, or null. */
    public function find(?string $key, ?int $messageId): ?CmsCommApiSend
    {
        $query = CmsCommApiSend::find()->andWhere(['cms_site_id' => $this->siteId, 'cms_user_id' => (int)$this->user->id]);
        if ($key !== null && $key !== '') {
            return $query->andWhere(['idempotency_key' => trim($key)])->one();
        }

        return $messageId ? $query->andWhere(['cms_comm_message_id' => $messageId])->one() : null;
    }

    /**
     * What is known about a send: never more than the CRM knows.
     *
     * @return array sent, state, state_text, delivery, message, offers, replies
     */
    public function status(CmsCommApiSend $row): array
    {
        $message = $row->cms_comm_message_id ? CmsCommMessage::findOne((int)$row->cms_comm_message_id) : null;
        $result = [
            'idempotency_key' => (string)$row->idempotency_key,
            'cms_comm_draft_id' => $row->cms_comm_draft_id ? (int)$row->cms_comm_draft_id : null,
            'created_at' => self::date((int)$row->created_at),
        ];
        if (!$message) {
            return $result + ['sent' => false, 'state' => 'unknown', 'state_text' => 'Письмо этой отправки не найдено (удалено).'];
        }
        $states = [
            CmsCommMessage::STATUS_QUEUED => 'Письмо в очереди на отправку.',
            CmsCommMessage::STATUS_SENDING => 'Письмо отправляется.',
            CmsCommMessage::STATUS_SENT => 'Письмо принято почтовым сервером отправителя. Это не подтверждение доставки получателю.',
            CmsCommMessage::STATUS_FAILED => 'Письмо не отправлено: ошибка отправки.',
            CmsCommMessage::STATUS_UNKNOWN => 'Неизвестно, ушло ли письмо (сервер не ответил). Повторно не отправляется: проверьте «Отправленные» ящика.',
        ];
        $result += [
            'sent' => true,
            'state' => (string)$message->status,
            'state_text' => $states[$message->status] ?? $message->getStatusName(),
            'error' => $message->error ? mb_substr((string)$message->error, 0, 500) : null,
            'message' => [
                'id' => (int)$message->id,
                'cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id,
                'subject' => (string)$message->subject,
                'from' => (string)$message->from_address,
                'to' => array_keys($message->getTo()),
                'sent_by' => $message->sent_by_user_id ? (int)$message->sent_by_user_id : null,
                'message_at' => self::date((int)$message->message_at),
                'purpose' => $row->purpose,
                'test_mode' => (bool)$row->test_mode,
                'opt_out' => (bool)$message->unsubscribe_url,
                // The files the letter carries as MIME parts (pictures inside the text by cid:).
                'attachments' => array_map(static fn($f) => ['name' => (string)$f->name, 'mime' => $f->mime_type, 'size' => $f->size !== null ? (int)$f->size : null,
                    'inline' => (bool)$f->is_inline], \skeeks\cms\communication\models\CmsCommMessageFile::find()->andWhere(['cms_comm_message_id' => (int)$message->id])
                    ->orderBy(['id' => SORT_ASC])->all()),
            ],
            'delivery' => $this->delivery($message),
            'offers' => $this->offers((int)$message->id),
            'replies' => $this->replies($message),
        ];

        return $result;
    }

    /** Delivery as far as mail tells: never "delivered" without a proof. */
    private function delivery(CmsCommMessage $message): array
    {
        $after = (int)$message->message_at;
        $bounce = CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id, 'direction' => CmsCommMessage::DIRECTION_IN])
            ->andWhere(['>=', 'message_at', $after])
            ->andWhere(['or', ['like', 'from_address', 'mailer-daemon'], ['like', 'from_address', 'postmaster'],
                ['and', ['is_auto' => 1], ['or', ['like', 'subject', 'undeliver'], ['like', 'subject', 'Delivery Status'], ['like', 'subject', 'не доставлено'], ['like', 'subject', 'Недоставлен']]]])
            ->orderBy(['id' => SORT_ASC])->one();
        if ($bounce) {
            return ['state' => 'bounced', 'text' => 'Пришло автоматическое сообщение о недоставке.', 'cms_comm_message_id' => (int)$bounce->id];
        }
        $address = (string)(array_key_first($message->getTo()) ?? '');
        $contact = $address !== '' ? CmsCommContact::find()->andWhere(['cms_site_id' => (int)$message->cms_site_id, 'kind' => CmsCommContact::KIND_EMAIL, 'value' => mb_strtolower($address)])->one() : null;
        if ($contact && CmsCommSuppression::find()->andWhere(['cms_comm_contact_id' => (int)$contact->id, 'reason' => CmsCommSuppression::REASON_BOUNCE])
                ->andWhere(['>=', 'created_at', $after])->exists()) {
            return ['state' => 'bounced', 'text' => 'Адрес отмечен как недоставляемый после отправки.'];
        }

        return ['state' => 'not_confirmed', 'text' => 'Почта не подтверждает доставку. Сообщения о недоставке нет.'];
    }

    /** The sends of offers of the letter: honest openings, link checks apart. */
    private function offers(int $messageId): array
    {
        $result = [];
        foreach (Campaigns::offerSources() as $type => $source) {
            foreach ($source->states([$messageId]) as $state) {
                $result[] = array_filter([
                    'type' => $type,
                    'proposal_id' => $state['proposal_id'] ?? null,
                    'send_id' => $state['send_id'] ?? null,
                    'opened' => !empty($state['opened_at']),
                    'opened_at' => self::date($state['opened_at'] ?? null),
                    'last_viewed_at' => self::date($state['last_viewed_at'] ?? null),
                    'views_count' => $state['views_count'] ?? null,
                    'link_checks_count' => $state['link_checks_count'] ?? null,
                    'accepted_at' => self::date($state['accepted_at'] ?? null),
                ], static fn($v) => $v !== null);
            }
        }

        return $result;
    }

    /** ISO 8601 in the time zone of the CRM formatter, null for no time. */
    private static function date($timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }
        $tz = new \DateTimeZone(\Yii::$app->formatter->timeZone ?: date_default_timezone_get());

        return (new \DateTime('@'.(int)$timestamp))->setTimezone($tz)->format('c');
    }

    /** Answers of people (not automatic letters) in the conversation after the send. */
    private function replies(CmsCommMessage $message): array
    {
        $query = CmsCommMessage::find()->andWhere(['cms_comm_conversation_id' => (int)$message->cms_comm_conversation_id, 'direction' => CmsCommMessage::DIRECTION_IN, 'is_auto' => 0])
            ->andWhere(['>=', 'message_at', (int)$message->message_at]);

        return ['count' => (int)(clone $query)->count(), 'last_id' => ($id = (clone $query)->max('id')) ? (int)$id : null,
            'note' => 'Текст ответа — cms_comm_message_get.'];
    }
}
