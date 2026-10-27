<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\components\AiApiSettings;
use skeeks\cms\communication\controllers\AdminCmsCommCampaignController;
use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommDraft;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\communication\send\SendableInterface;
use skeeks\cms\communication\send\SendHistoryInterface;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;
use yii\db\ActiveRecord;

/**
 * Actions of an AI client (MCP/REST, stage AI-2): personal letters to
 * clients with an offer, prepared for people to send.
 *
 * - A draft in the "Черновики" of the employee responsible for the client
 *   (or of the AI's own user for a mailbox it may send from): recipient,
 *   subject, text of the common editor, the offer put in as "Прикрепить
 *   из…" (its personal link is made when the employee sends the letter).
 *   The draft is saved by MailDrafts and checked by the letter form of its
 *   owner exactly as in the mail screen; the employee edits and sends it.
 * - A campaign of personal letters: a draft campaign and recipients with
 *   their own subject and text (Campaigns::addRecipient()). Only an employee
 *   launches it in the "Рассылки" section; no action here can.
 *
 * Every action runs for the OAuth user, through forManager() and their
 * rights, asks MailingPolicy for the mailing topic, refuses a client who got
 * a letter or this offer recently or already waits in a draft or a campaign,
 * keeps the per-call and per-day limits (CommunicationComponent::$aiLimits)
 * and marks the result "Подготовлено ИИ" (AiMark) with the OAuth client.
 * Without $apply nothing is saved: every row says what would happen.
 */
class AiActions
{
    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_NO_EMAIL = 'no_email';
    public const REASON_NO_MAILBOX = 'no_mailbox';
    public const REASON_NOT_ALLOWED = 'not_allowed';
    public const REASON_RECENT_LETTER = 'recent_letter';
    public const REASON_RECENT_OFFER = 'recent_offer';
    public const REASON_DRAFT_EXISTS = 'draft_exists';
    public const REASON_IN_CAMPAIGN = 'in_campaign';
    public const REASON_OFFER = 'offer';
    public const REASON_INVALID = 'invalid';
    public const REASON_DAY_LIMIT = 'day_limit';

    /** Defaults of CommunicationComponent::$aiLimits; sendPerDay — letters a user sends through the API a day (AiSend). */
    public const LIMITS = ['perCall' => 20, 'perDay' => 100, 'recentDays' => 30, 'sendPerDay' => 20];

    private CmsUser $user;
    private int $siteId;
    private array $mark;

    /**
     * @param string $clientName the OAuth client of the AI ("Claude Code (skeeks)")
     */
    public function __construct(User $user, string $clientName, ?int $siteId = null)
    {
        $this->user = $user instanceof CmsUser ? $user : CmsUser::findOne((int)$user->id);
        $this->siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
        $this->mark = AiMark::make($clientName, (int)$this->user->id);
    }

    /**
     * perCall — rows in one call; perDay — drafts and recipients per user and day; recentDays — no repeat within;
     * sendPerDay — letters sent through the API per user and day. The value set in "Настройки почты" → «Работа ИИ
     * через API» wins, then communication.aiLimits of the site configuration, then LIMITS.
     */
    public static function limits(): array
    {
        return array_map(static fn($row) => $row['value'], self::limitSources(self::LIMITS));
    }

    /**
     * Each limit with where it comes from: crm, config or default.
     *
     * @param array $defaults key => default value (LIMITS, LetterPurpose::DAYS)
     * @return array key => [value, source]
     */
    public static function limitSources(array $defaults): array
    {
        $config = \Yii::$app->has('communication') ? (array)(\Yii::$app->communication->aiLimits ?? []) : [];
        $crm = AiApiSettings::forSite()['limits'];
        $result = [];
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $crm)) {
                $result[$key] = ['value' => (int)$crm[$key], 'source' => 'crm'];
            } elseif (array_key_exists($key, $config) && $config[$key] !== null && $config[$key] !== '') {
                $result[$key] = ['value' => (int)$config[$key], 'source' => 'config'];
            } else {
                $result[$key] = ['value' => (int)$default, 'source' => 'default'];
            }
        }

        return $result;
    }

    /** The mark of this AI client and user: "mcp:<client>". */
    public function mark(): array
    {
        return $this->mark;
    }

    /**
     * Drafts and campaign recipients the user's AI prepared today (in the
     * CRM time zone): drafts still waiting, letters sent from such drafts,
     * personal letters of campaign recipients.
     */
    public function usedToday(): int
    {
        $tz = new \DateTimeZone(\Yii::$app->formatter->timeZone ?: date_default_timezone_get());
        $since = (new \DateTime('today', $tz))->getTimestamp();
        $uid = (int)$this->user->id;
        $drafts = CmsCommDraft::find()->andWhere(['cms_site_id' => $this->siteId, 'prepared_by' => AiMark::BY_AI, 'prepared_user_id' => $uid])
            ->andWhere(['>=', 'prepared_at', $since])->count();
        $letters = CmsCommMessage::find()->andWhere(['cms_site_id' => $this->siteId, 'prepared_by' => AiMark::BY_AI, 'prepared_user_id' => $uid, 'cms_comm_campaign_id' => null])
            ->andWhere(['>=', 'prepared_at', $since])->count();
        $recipients = CmsCommCampaignRecipient::find()->alias('r')
            ->innerJoin(['c' => CmsCommCampaign::tableName()], 'c.id = r.cms_comm_campaign_id')
            ->andWhere(['c.cms_site_id' => $this->siteId, 'r.text_by' => CmsCommCampaignRecipient::TEXT_BY_AI, 'r.text_user_id' => $uid])
            ->andWhere(['>=', 'r.text_at', $since])->count();

        return (int)$drafts + (int)$letters + (int)$recipients;
    }

    /* Drafts */

    /**
     * Drafts of personal letters, one per client.
     *
     * @param array $args purpose (LetterPurpose; advertising when a topic is given), cms_comm_topic_id (advertising),
     *                    offer ("proposal:10", optional), cms_comm_account_id (optional), test_mode,
     *                    items: record_type, record_id, address, subject, body_html, offer, attachments
     * @return array created, refused, items (status created|ready|refused, reason, message, draft …), limits
     */
    public function createDrafts(array $args, bool $apply): array
    {
        $items = $this->items($args);
        $purpose = $this->purpose($args);
        $topic = LetterPurpose::withTopic($purpose) ? $this->topic($args) : null;
        $testMode = $this->testMode($args);
        $accountId = (int)($args['cms_comm_account_id'] ?? 0);
        if ($accountId && !(new MailComposeService($this->user, $this->siteId))->canSendFrom($accountId)) {
            throw new \DomainException('С ящика '.$accountId.' вам нельзя отправлять письма: черновик в нём сделать нельзя. Без cms_comm_account_id черновик ляжет в ящик ответственного за клиента.');
        }
        $limits = self::limits();
        $left = $limits['perDay'] - $this->usedToday();

        $result = [];
        $seen = [];
        foreach ($items as $i => $item) {
            $row = ['index' => $i, 'record' => ['type' => (string)($item['record_type'] ?? ''), 'id' => (int)($item['record_id'] ?? 0)]];
            try {
                $this->once($seen, $item);
                $plan = $this->planDraft($item, $purpose, $topic, $accountId, (string)($item['offer'] ?? $args['offer'] ?? ''), $testMode);
                if ($left <= 0) {
                    throw new AiRefusal(self::REASON_DAY_LIMIT, 'Дневной лимит ИИ исчерпан: не больше '.$limits['perDay'].' черновиков и получателей в день на сотрудника.');
                }
                $left--;
                $row = array_merge($row, $plan['info']);
                if ($apply) {
                    $draft = $this->saveDraft($plan);
                    $row['status'] = 'created';
                    $row['draft_id'] = (int)$draft->id;
                } else {
                    $row['status'] = 'ready';
                }
            } catch (AiRefusal $e) {
                $row += ['status' => 'refused', 'reason' => $e->reason, 'message' => $e->getMessage()];
            }
            $result[] = $row;
        }

        return $this->summary($result, $apply, [
            'purpose' => $purpose,
            'test_mode' => $testMode,
            'note' => 'Черновики лежат в «Черновиках» почты у сотрудника owner: он проверяет текст и отправляет сам. Личная ссылка на предложение создаётся при отправке.',
            'drafts_url' => self::url('/cmsCommunication/admin-cms-comm-message/mail', ['folder' => MailFolders::DRAFTS]),
        ]);
    }

    /** Checks one draft and prepares what to save. @throws AiRefusal */
    private function planDraft(array $item, string $purpose, ?CmsCommTopic $topic, int $accountId, string $offer, bool $testMode, ?CmsCommDraft $draft = null): array
    {
        [$record, $type] = $this->record($item);
        $address = $this->address($record, (string)($item['address'] ?? ''));
        if ($testMode) {
            $this->checkTestRecipient($record, $type, $address);
        }

        if ($accountId) {
            $owner = $this->user;
        } else {
            $responsible = Campaigns::responsibleOf($record);
            $owner = $responsible ? CmsUser::findOne($responsible) : $this->user;
            $owner = $owner ?: $this->user;
            $accountId = (int)(new MailComposeService($owner, $this->siteId))->defaultAccountId();
            if (!$accountId) {
                throw new AiRefusal(self::REASON_NO_MAILBOX, 'У ответственного '.$owner->shortDisplayName.' нет ящика, с которого он может отправлять письма. Укажите cms_comm_account_id своего ящика.');
            }
        }
        $this->checkPurpose($purpose, $topic, $record, $type, $address, $testMode);
        $this->checkNoDraft($record, $draft ? (int)$draft->id : null);
        $this->checkNotInCampaign($record, null);
        // A repeated offer is a mailing concern: personal and service letters may answer with an offer sent before.
        $sendable = $offer !== '' ? $this->offer($offer, $record, $owner, LetterPurpose::withTopic($purpose) && !$testMode) : null;
        $attachments = $this->attachmentSources($item);
        if ($purpose === LetterPurpose::INDIVIDUAL_OFFER && !$sendable) {
            throw new AiRefusal(LetterPurpose::REASON_PURPOSE, 'Индивидуальное предложение отправляется с Презентацией или КП: укажите offer ("proposal:<id>").');
        }

        $subject = trim((string)($item['subject'] ?? ''));
        $html = (string)($item['body_html'] ?? '');
        if ($subject === '' || trim(\skeeks\cms\helpers\RichTextHtml::toText($html)) === '') {
            throw new AiRefusal(self::REASON_INVALID, 'Нужны тема (subject) и текст письма (body_html).');
        }
        if ($sendable) {
            $html = str_replace('{Ссылка}', SendService::editorLink($sendable[0], $sendable[1], $sendable[2]->getSendButton()), $html);
        }
        // Only advertising has the opt-out line; an individual offer drops the placeholder.
        $html = str_replace('{Отписаться}', LetterPurpose::optOut($purpose) ? '<a href="'.Unsubscribe::PLACEHOLDER.'">'.Campaigns::UNSUBSCRIBE_LINK.'</a>' : '', $html);

        $service = new MailComposeService($owner, $this->siteId);
        $form = new ComposeForm(['service' => $service]);
        $form->cms_comm_account_id = $accountId;
        $form->to = $address;
        $form->subject = $subject;
        $form->body_html = $html;
        $form->context_model_code = CrmRecords::codeOf($type);
        $form->context_model_id = (int)$record->primaryKey;
        $form->sendables = $sendable ? [$sendable[0].':'.$sendable[1]] : [];
        $form->cms_comm_topic_id = $topic ? (int)$topic->id : null;
        $form->purpose = $purpose;
        // Editing keeps the files of the draft: its uploads (fresh tokens of this request) go with the form;
        // a new text gets the draft's pictures at its "{Картинка}" places (or at the end).
        if ($draft) {
            $current = new ComposeForm();
            (new MailDrafts($owner, $this->siteId))->fill($current, $draft);
            $form->uploads = $current->uploads;
            if (!empty($item['body_changed'])) {
                foreach (\skeeks\cms\helpers\RichTextHtml::uploadTokens((string)$current->body_html) as $token) {
                    $form->body_html = AiDraftFiles::placeImage((string)$form->body_html, $token);
                }
            } else {
                $form->body_html = (string)$current->body_html;
            }
            // The button of a replaced offer moves to the new one.
            foreach ($draft->getSendableRefs() as $old) {
                $parsed = SendService::parseRef((string)$old);
                if ($parsed && $sendable && ($parsed[0] !== $sendable[0] || $parsed[1] !== $sendable[1])) {
                    $form->body_html = str_replace(SendService::placeholder($parsed[0], $parsed[1]), SendService::placeholder($sendable[0], $sendable[1]), (string)$form->body_html);
                }
            }
        }
        // The sender's signature of this mailbox, once, below the text — as the letter form puts it; pictures
        // without a "{Картинка}" place go above it.
        $signature = MailSignatures::of((int)$owner->id, $accountId);
        if ($signature !== null && !MailSignatures::has((string)$form->body_html, $signature)) {
            $inline = count(array_filter($attachments, static fn($a) => $a['inline']));
            $places = mb_substr_count((string)$form->body_html, AiDraftFiles::PLACEHOLDER);
            $form->body_html = (string)$form->body_html.str_repeat('<p>'.AiDraftFiles::PLACEHOLDER.'</p>', max(0, $inline - $places));
            $form->body_html = MailSignatures::append((string)$form->body_html, $signature);
        }
        // The letter form of the owner checks it as the mail screen will when they send it.
        if (!$form->validate()) {
            throw new AiRefusal(self::REASON_INVALID, implode(' ', $form->getFirstErrors()));
        }

        return [
            'form' => $form, 'owner' => $owner, 'service' => $service, 'topic' => $topic, 'purpose' => $purpose, 'attachments' => $attachments,
            'info' => [
                'record' => ['type' => $type, 'id' => (int)$record->primaryKey, 'name' => CrmRecords::name($record)],
                'address' => $address,
                'owner' => ['id' => (int)$owner->id, 'name' => (string)$owner->shortDisplayName],
                'cms_comm_account_id' => $accountId,
                'signature' => $signature !== null ? trim(\skeeks\cms\helpers\RichTextHtml::toText($signature)) : null,
                'purpose' => $purpose,
                'topic' => $topic ? ['id' => (int)$topic->id, 'name' => (string)$topic->name] : null,
                'offer' => $sendable ? ['ref' => $sendable[0].':'.$sendable[1], 'title' => $sendable[2]->getSendTitle()] : null,
                'attachments' => array_map(static fn($a) => ['cms_storage_file_id' => $a['id'], 'name' => $a['name'], 'mime' => $a['mime'],
                    'size' => $a['size'], 'inline' => $a['inline']], $attachments),
                'test_mode' => $testMode,
            ],
        ];
    }

    private function saveDraft(array $plan, ?CmsCommDraft $existing = null): CmsCommDraft
    {
        /** @var ComposeForm $form */
        $form = $plan['form'];
        return CmsCommDraft::getDb()->transaction(function () use ($plan, $form, $existing) {
            $draft = (new MailDrafts($plan['owner'], $this->siteId))->save($form, $plan['service'], $existing);
            AiMark::apply($draft, $this->mark);
            $draft->prepared_hash = AiMark::hash((string)$draft->subject, (string)$draft->body_html);
            $draft->cms_comm_topic_id = $plan['topic'] ? (int)$plan['topic']->id : null;
            $draft->purpose = $plan['purpose'];
            $draft->save(false, ['prepared_by', 'prepared_client', 'prepared_user_id', 'prepared_at', 'prepared_hash', 'cms_comm_topic_id', 'purpose']);
            $files = new AiDraftFiles($this->user, $this->siteId);
            foreach ($plan['attachments'] as $attachment) {
                $files->add($draft, $attachment['id'], $attachment['inline']);
            }
            if ($plan['attachments']) {
                // "{Картинка}" became a picture: the text AI wrote is the text with it.
                $draft->prepared_hash = AiMark::hash((string)$draft->subject, (string)$draft->body_html);
                $draft->save(false, ['prepared_hash']);
            }

            return $draft;
        });
    }

    /**
     * Changes a draft AI made in the user's own "Черновики": only the given fields (subject, body_html, address,
     * purpose, cms_comm_topic_id, offer); its files stay ("{Картинка}" places its pictures in a new text). The same
     * checks as a new draft; the draft must be checked again before sending.
     *
     * @throws \DomainException
     */
    public function updateDraft(array $args, bool $apply): array
    {
        $draft = (new MailDrafts($this->user, $this->siteId))->find((int)($args['draft_id'] ?? 0));
        if (!$draft) {
            throw new \DomainException('Черновик не найден в ваших «Черновиках».');
        }
        $record = $draft->context_model_code && $draft->context_model_id
            ? (new MailComposeService($this->user, $this->siteId))->contextRecord((string)$draft->context_model_code, (int)$draft->context_model_id) : null;
        if (!$record) {
            throw new \DomainException('Черновик не привязан к клиенту CRM, которого вы видите: такой черновик правят в «Почте».');
        }
        $refs = $draft->getSendableRefs();
        $item = [
            'record_type' => CrmRecords::typeOfCode(method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record)),
            'record_id' => (int)$record->primaryKey,
            'address' => array_key_exists('address', $args) ? (string)$args['address'] : (string)($draft->getToAddresses()[0] ?? ''),
            'subject' => array_key_exists('subject', $args) ? (string)$args['subject'] : (string)$draft->subject,
            'body_html' => array_key_exists('body_html', $args) ? (string)$args['body_html'] : (string)$draft->body_html,
            'body_changed' => array_key_exists('body_html', $args),
        ];
        $purpose = isset($args['purpose']) ? $this->purpose($args) : (LetterPurpose::of($draft->purpose, $draft->cms_comm_topic_id) ?? $this->purpose($args));
        $topicArgs = ['cms_comm_topic_id' => $args['cms_comm_topic_id'] ?? $draft->cms_comm_topic_id];
        $topic = LetterPurpose::withTopic($purpose) ? $this->topic($topicArgs) : null;
        $offer = array_key_exists('offer', $args) ? (string)$args['offer'] : (string)($refs[0] ?? '');
        $testMode = $this->testMode($args);
        try {
            $plan = $this->planDraft($item, $purpose, $topic, (int)$draft->cms_comm_account_id, $offer, $testMode, $draft);
        } catch (AiRefusal $e) {
            return ['success' => false, 'saved' => false, 'reason' => $e->reason, 'message' => $e->getMessage()];
        }
        if ($apply) {
            $draft = $this->saveDraft($plan, $draft);
        }

        return ['success' => true, 'saved' => $apply, 'requires_confirmation' => !$apply, 'draft_id' => (int)$draft->id] + $plan['info']
            + ['note' => 'Черновик изменён: перед отправкой снова cms_comm_draft_check.'];
    }

    /* Files of drafts */

    /** @return array[] id, inline, name, mime, size of the item's files, checked @throws AiRefusal */
    private function attachmentSources(array $item): array
    {
        $result = [];
        $files = new AiDraftFiles($this->user, $this->siteId);
        foreach (array_values((array)($item['attachments'] ?? [])) as $attachment) {
            $id = (int)(is_array($attachment) ? ($attachment['cms_storage_file_id'] ?? 0) : $attachment);
            $inline = is_array($attachment) && !empty($attachment['inline']);
            $source = $files->source($id);
            if ($inline && !in_array($source['mime'], MailFiles::INLINE_TYPES, true)) {
                throw new AiRefusal('attachment', 'В текст письма можно вставить только картинку: файл '.$id.' — '.$source['mime'].'.');
            }
            $result[$source['hash'].'|'.(int)$inline] = ['id' => $id, 'inline' => $inline, 'name' => $source['name'], 'mime' => $source['mime'], 'size' => $source['size']];
        }
        if (count($result) > AiDraftFiles::MAX_FILES || array_sum(array_column($result, 'size')) > AiDraftFiles::MAX_TOTAL) {
            throw new AiRefusal('attachment', 'Не больше '.AiDraftFiles::MAX_FILES.' файлов и '.(AiDraftFiles::MAX_TOTAL / 1048576).' МБ на письмо.');
        }

        return array_values($result);
    }

    /** An own draft of AI with its files. @throws \DomainException */
    public function draftFiles(int $draftId): array
    {
        return ['draft' => $this->ownDraft($draftId)];
    }

    public function ownDraft(int $draftId): CmsCommDraft
    {
        $draft = (new MailDrafts($this->user, $this->siteId))->find($draftId);
        if (!$draft) {
            throw new \DomainException('Черновик не найден в ваших «Черновиках».');
        }

        return $draft;
    }

    /* Purpose and test mode */

    /** The purpose of the letter: given, or advertising when a topic is given. @throws \DomainException */
    private function purpose(array $args): string
    {
        $purpose = trim((string)($args['purpose'] ?? ''));
        if ($purpose === '') {
            if (!empty($args['cms_comm_topic_id'])) {
                return LetterPurpose::ADVERTISING;
            }
            throw new \DomainException('Укажите purpose — назначение письма: advertising (рассылка-предложение, нужна тема, ссылка «Отписаться»), individual_offer (индивидуальное предложение одному человеку: тема для проверки отказов, Презентация или КП, без строки «Отписаться»), personal (рабочая переписка: клиент недавно писал), '
                .'service (служебное: действующая услуга или оплаченный счёт).');
        }
        if (!isset(LetterPurpose::names()[$purpose])) {
            throw new \DomainException('purpose — advertising, individual_offer, personal или service.');
        }
        if (!LetterPurpose::withTopic($purpose) && !empty($args['cms_comm_topic_id'])) {
            throw new \DomainException('У личного и служебного письма нет темы рассылки: уберите cms_comm_topic_id или выберите purpose=advertising.');
        }

        return $purpose;
    }

    /** What the purpose needs; the mailing checks of advertising. @throws AiRefusal */
    private function checkPurpose(string $purpose, ?CmsCommTopic $topic, ActiveRecord $record, string $type, string $address, bool $testMode): void
    {
        $check = LetterPurpose::check($purpose, $topic ? (int)$topic->id : null, $record, $address, $this->siteId);
        if ($check['problems']) {
            throw new AiRefusal((string)$check['problems'][0]['reason'], (string)$check['problems'][0]['message']);
        }
        if (LetterPurpose::withTopic($purpose)) {
            $this->checkContact($record, $type, $address, $topic, $testMode);
        } elseif (!\Yii::$app->communication->isRecipientAllowed($address)) {
            throw new AiRefusal(self::REASON_NOT_ALLOWED, 'Отправка на этот адрес запрещена настройкой allowedRecipients.');
        }
    }

    /**
     * The server list of test recipients: "company:712" => ["asdasd@skeeks.com"] — a record and its exact
     * addresses, never a domain. Set in the site config (`communication.aiTestRecipients`), never by a tool.
     */
    public static function testRecipients(): array
    {
        $list = \Yii::$app->has('communication') ? (array)(\Yii::$app->communication->aiTestRecipients ?? []) : [];
        $result = [];
        foreach ($list as $key => $addresses) {
            $result[strtolower(trim((string)$key))] = array_values(array_unique(array_map(static fn($a) => mb_strtolower(trim((string)$a)), (array)$addresses)));
        }

        return $result;
    }

    /**
     * Test mode is asked explicitly (test_mode=true), only by an administrator, only for the server list.
     *
     * @throws \DomainException
     */
    private function testMode(array $args): bool
    {
        if (empty($args['test_mode'])) {
            return false;
        }
        if (!\Yii::$app->authManager || !\Yii::$app->authManager->checkAccess((int)$this->user->id, \skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            throw new \DomainException('Тестовый режим доступен только администратору.');
        }
        if (!self::testRecipients()) {
            throw new \DomainException('Тестовых получателей на сайте нет (настройка communication.aiTestRecipients): тестовый режим не работает.');
        }

        return true;
    }

    /** @throws AiRefusal */
    private function checkTestRecipient(ActiveRecord $record, string $type, string $address): void
    {
        if (!self::isTestRecipient($type, (int)$record->primaryKey, $address)) {
            throw new AiRefusal('test_mode_not_allowed', 'Тестовый режим только для разрешённых тестовых получателей ('
                .implode('; ', array_map(static fn($k, $v) => $k.' → '.implode(', ', $v), array_keys(self::testRecipients()), self::testRecipients()))
                .'): '.$type.':'.(int)$record->primaryKey.' + '.$address.' среди них нет.');
        }
    }

    public static function isTestRecipient(string $type, int $id, string $address): bool
    {
        $list = self::testRecipients();

        return in_array(mb_strtolower(trim($address)), $list[$type.':'.$id] ?? [], true);
    }

    /* Campaigns */

    /**
     * A draft campaign of personal letters ("Только добавленные получатели").
     * It is never launched here: an employee launches it in "Рассылки".
     *
     * @param array $args name, cms_comm_topic_id, offer ("proposal:10" — one document for all) or offer_template_id
     *                    (a КП from the template for each), cms_comm_account_id, subject, body_html, task_on_open, task_on_reply
     */
    public function createCampaign(array $args, bool $apply): array
    {
        $this->requireCampaignRight();
        $this->campaignPurpose($args);
        $topic = $this->topic($args);
        $campaign = new CmsCommCampaign([
            'cms_site_id' => $this->siteId,
            'name' => trim((string)($args['name'] ?? '')),
            'audience_type' => CmsCommCampaign::AUDIENCE_MANUAL,
            'cms_comm_topic_id' => (int)$topic->id,
        ]);
        if (array_key_exists('task_on_open', $args)) {
            $campaign->task_on_open = (int)(bool)$args['task_on_open'];
        }
        if (array_key_exists('task_on_reply', $args)) {
            $campaign->task_on_reply = (int)(bool)$args['task_on_reply'];
        }
        $campaign->subject = trim((string)($args['subject'] ?? '')) ?: $campaign->name;
        if (trim((string)($args['body_html'] ?? '')) !== '') {
            $campaign->body_html = (string)$args['body_html'];
        }
        $accountId = (int)($args['cms_comm_account_id'] ?? 0);
        if ($accountId) {
            if (!(new MailComposeService($this->user, $this->siteId))->canSendFrom($accountId)) {
                throw new \DomainException('С ящика '.$accountId.' вам нельзя отправлять письма. Без cms_comm_account_id письма уйдут из ящиков ответственных за клиентов.');
            }
            $campaign->sender_mode = CmsCommCampaign::SENDER_ACCOUNT;
            $campaign->cms_comm_account_id = $accountId;
        }
        $offer = trim((string)($args['offer'] ?? ''));
        $template = (int)($args['offer_template_id'] ?? 0);
        if ($offer !== '' && $template) {
            throw new \DomainException('Укажите что-то одно: offer (один документ всем) или offer_template_id (КП каждому из шаблона).');
        }
        if ($offer !== '') {
            $parsed = SendService::parseRef($offer);
            $source = $parsed ? (Campaigns::offerSources()[$parsed[0]] ?? null) : null;
            if (!$source || !isset($source->sharedItems($this->user, $this->siteId)[$parsed[1]])) {
                throw new \DomainException('Предложение «'.$offer.'» не подходит для кампании: нужен документ для всех (общая Презентация), видимый вам.');
            }
            $campaign->offer_kind = CmsCommCampaign::OFFER_SHARED;
            $campaign->offer_type = $parsed[0];
            $campaign->offer_id = $parsed[1];
        } elseif ($template) {
            $type = Campaigns::defaultOfferType();
            $source = $type ? Campaigns::offerSources()[$type] : null;
            if (!$source || !isset($source->templates($this->siteId)[$template])) {
                throw new \DomainException('Шаблон КП '.$template.' не найден или выключен.');
            }
            $campaign->offer_kind = CmsCommCampaign::OFFER_EACH;
            $campaign->offer_type = $type;
            $campaign->offer_id = $template;
        }
        AiMark::apply($campaign, $this->mark);
        if (!$campaign->validate()) {
            throw new \DomainException(implode(' ', $campaign->getFirstErrors()));
        }
        if ($apply) {
            if ($campaign->hasAttribute('created_by') && !$campaign->created_by) {
                $campaign->created_by = (int)$this->user->id;
            }
            $campaign->save(false);
        }

        return [
            'success' => true,
            'requires_confirmation' => !$apply,
            'campaign' => $this->campaignData($campaign),
            'note' => 'Кампания — черновик. Добавьте получателей с личными письмами (cms_comm_campaign_recipient_add). '
                .'Запускает кампанию только сотрудник в разделе «Рассылки»: инструмента запуска нет.',
        ];
    }

    /**
     * Recipients of a draft campaign with their own subject and text, written by AI.
     *
     * @param array $args cms_comm_campaign_id, items: record_type, record_id, address, name, subject, body_html
     */
    /** A campaign is a mailing: advertising only, never an individual offer without the opt-out line. */
    private function campaignPurpose(array $args): void
    {
        $purpose = trim((string)($args['purpose'] ?? ''));
        if ($purpose !== '' && $purpose !== LetterPurpose::ADVERTISING) {
            throw new \DomainException('Кампания — это рассылка: её письма всегда рекламные, со строкой «Отписаться» и заголовками отписки. '
                .'Индивидуальное предложение (individual_offer) готовится черновиком cms_comm_draft_create и отправляется по одному.');
        }
    }

    public function addRecipients(array $args, bool $apply): array
    {
        $this->requireCampaignRight();
        $this->campaignPurpose($args);
        $campaign = CmsCommCampaign::findOne(['id' => (int)($args['cms_comm_campaign_id'] ?? 0), 'cms_site_id' => $this->siteId]);
        if (!$campaign) {
            throw new \DomainException('Кампания не найдена.');
        }
        if (!$campaign->isDraft) {
            throw new \DomainException('Кампания уже запущена: получателей добавляют, пока она черновик.');
        }
        $topic = $campaign->topic;
        if (!$topic || !$topic->is_active) {
            throw new \DomainException('У кампании нет действующей темы рассылки.');
        }
        $items = $this->items($args);
        $limits = self::limits();
        $left = $limits['perDay'] - $this->usedToday();
        $campaigns = new Campaigns();
        $source = Campaigns::offerSource($campaign);

        $result = [];
        $seen = [];
        foreach ($items as $i => $item) {
            $row = ['index' => $i, 'record' => ['type' => (string)($item['record_type'] ?? ''), 'id' => (int)($item['record_id'] ?? 0)]];
            try {
                $this->once($seen, $item);
                [$record, $type] = $this->record($item);
                $address = $this->address($record, (string)($item['address'] ?? ''));
                $this->checkContact($record, $type, $address, $topic);
                $this->checkNoDraft($record);
                $this->checkNotInCampaign($record, (int)$campaign->id);
                if ($campaign->offer_kind === CmsCommCampaign::OFFER_SHARED && $source instanceof SendHistoryInterface) {
                    $this->checkOfferHistory($source, (int)$campaign->offer_id, $record);
                }
                $subject = trim((string)($item['subject'] ?? ''));
                $html = (string)($item['body_html'] ?? '');
                if ($subject === '' || trim(\skeeks\cms\helpers\RichTextHtml::toText($html)) === '') {
                    throw new AiRefusal(self::REASON_INVALID, 'Нужны тема (subject) и текст письма (body_html).');
                }
                // The places of the offer button and of the opt-out link are required: added at the end when missing.
                if ($campaign->offer_kind !== CmsCommCampaign::OFFER_NONE && strpos($html, '{Ссылка}') === false) {
                    $html .= '<p>{Ссылка}</p>';
                }
                if (strpos($html, '{Отписаться}') === false) {
                    $html .= '<p>{Отписаться}</p>';
                }
                if ($errors = Campaigns::textErrors($campaign, $subject, $html)) {
                    throw new AiRefusal(self::REASON_INVALID, implode(' ', $errors));
                }
                if ($left <= 0) {
                    throw new AiRefusal(self::REASON_DAY_LIMIT, 'Дневной лимит ИИ исчерпан: не больше '.$limits['perDay'].' черновиков и получателей в день на сотрудника.');
                }
                $left--;
                $row = array_merge($row, [
                    'record' => ['type' => $type, 'id' => (int)$record->primaryKey, 'name' => CrmRecords::name($record)],
                    'address' => $address,
                ]);
                if ($apply) {
                    $data = ['address' => $address, 'subject' => $subject, 'body_html' => $html,
                        'by' => CmsCommCampaignRecipient::TEXT_BY_AI, 'client' => (string)$this->mark['client']];
                    if (isset($item['name'])) {
                        $data['name'] = (string)$item['name'];
                    }
                    try {
                        $recipient = $campaigns->addRecipient($campaign, $record, $this->user, $data);
                    } catch (\DomainException $e) {
                        throw new AiRefusal(self::REASON_INVALID, $e->getMessage());
                    }
                    $row['status'] = 'created';
                    $row['recipient_id'] = (int)$recipient->id;
                } else {
                    $row['status'] = 'ready';
                }
            } catch (AiRefusal $e) {
                $row += ['status' => 'refused', 'reason' => $e->reason, 'message' => $e->getMessage()];
            }
            $result[] = $row;
        }

        return $this->summary($result, $apply, [
            'campaign' => $this->campaignData($campaign),
            'note' => 'Получатели добавлены в черновик кампании. Сотрудник проверяет письма в карточке кампании и запускает её сам: инструмента запуска нет.',
        ]);
    }

    /* Checks */

    private function items(array $args): array
    {
        $items = array_values(array_filter((array)($args['items'] ?? []), 'is_array'));
        $limit = self::limits()['perCall'];
        if (!$items) {
            throw new \DomainException('Передайте items: хотя бы одного клиента (record_type, record_id) с темой и текстом.');
        }
        if (count($items) > $limit) {
            throw new \DomainException('Не больше '.$limit.' писем за один вызов, передано '.count($items).'. Разбейте на части.');
        }

        return $items;
    }

    private function topic(array $args): CmsCommTopic
    {
        $topic = CmsCommTopic::findOne(['id' => (int)($args['cms_comm_topic_id'] ?? 0), 'cms_site_id' => $this->siteId]);
        if (!$topic || !$topic->is_active) {
            throw new \DomainException('Укажите действующую тему рассылки cms_comm_topic_id (cms_comm_topic_list): по ней проверяются отказы получателя (у рассылки и кампании — ещё и ссылка «Отписаться»).');
        }

        return $topic;
    }

    private function requireCampaignRight(): void
    {
        if (!\Yii::$app->authManager || !\Yii::$app->authManager->checkAccess((int)$this->user->id, AdminCmsCommCampaignController::PERMISSION)) {
            throw new \DomainException('Нет права на раздел «Рассылки»: кампании недоступны.');
        }
    }

    /** One letter per client in a call. @throws AiRefusal */
    private function once(array &$seen, array $item): void
    {
        $key = (string)($item['record_type'] ?? '').':'.(int)($item['record_id'] ?? 0);
        if (isset($seen[$key])) {
            throw new AiRefusal(self::REASON_INVALID, 'Этот клиент уже есть выше в этом вызове: одно письмо на клиента.');
        }
        $seen[$key] = true;
    }

    /** @return array [record, type] @throws AiRefusal */
    private function record(array $item): array
    {
        $type = (string)($item['record_type'] ?? '');
        if (!in_array($type, Campaigns::ADDABLE, true)) {
            throw new AiRefusal(self::REASON_NOT_FOUND, 'record_type — lead, company или client.');
        }
        $record = CrmRecords::find($type, (int)($item['record_id'] ?? 0), $this->user);
        if (!$record) {
            throw new AiRefusal(self::REASON_NOT_FOUND, 'Клиент не найден или вам не виден.');
        }

        return [$record, $type];
    }

    /** @throws AiRefusal */
    private function address(ActiveRecord $record, string $address): string
    {
        $addresses = Campaigns::recordAddresses($record);
        $address = mb_strtolower(trim($address)) ?: (string)(array_key_first($addresses) ?? '');
        if ($address === '' || !isset($addresses[$address])) {
            throw new AiRefusal(self::REASON_NO_EMAIL, $addresses ? 'Адреса '.$address.' нет у этого клиента.' : 'У этого клиента нет email.');
        }

        return $address;
    }

    /**
     * The stand allowlist, the mailing policy of the topic, a recent letter. Test mode (a server-listed test
     * recipient) skips only the recent letter: the policy still decides.
     *
     * @throws AiRefusal
     */
    private function checkContact(ActiveRecord $record, string $type, string $address, CmsCommTopic $topic, bool $testMode = false): void
    {
        if (!\Yii::$app->communication->isRecipientAllowed($address)) {
            throw new AiRefusal(self::REASON_NOT_ALLOWED, 'Отправка на этот адрес запрещена настройкой allowedRecipients.');
        }
        $check = MailingPolicy::check($this->siteId, 'email', $address, (int)$topic->id, MailingPolicy::MODE_MANAGER);
        if (!$check['allowed']) {
            throw new AiRefusal((string)$check['reason'], 'Писать нельзя: '.mb_strtolower((string)$check['message']).'.');
        }
        $days = self::limits()['recentDays'];
        if (!$testMode && $days > 0 && ($at = self::lastLetterAt($record, time() - $days * 86400))) {
            throw new AiRefusal(self::REASON_RECENT_LETTER, 'Клиенту уже писали '.\Yii::$app->formatter->asDate($at, 'php:d.m.Y')
                .' (меньше '.$days.' дней назад): повторное предложение не готовится.');
        }
    }

    /** Time of the latest letter to the record since $since (queued, sent or of unknown fate), or null. */
    public static function lastLetterAt(ActiveRecord $record, int $since): ?int
    {
        $code = method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record);
        $m = CmsCommMessage::tableName();
        $at = CmsCommMessage::find()
            ->innerJoin(['l' => CmsCommLink::tableName()], 'l.cms_comm_message_id = '.$m.'.id')
            ->andWhere(['l.model_code' => $code, 'l.model_id' => (int)$record->primaryKey])
            ->andWhere([$m.'.direction' => CmsCommMessage::DIRECTION_OUT])
            ->andWhere(['not', [$m.'.status' => CmsCommMessage::STATUS_FAILED]])
            ->andWhere(['>=', $m.'.message_at', $since])
            ->max($m.'.message_at');

        return $at ? (int)$at : null;
    }

    /** An AI draft for the client waiting in anybody's "Черновики". @throws AiRefusal */
    private function checkNoDraft(ActiveRecord $record, ?int $exceptDraftId = null): void
    {
        $code = method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record);
        $draft = CmsCommDraft::find()->andWhere(['cms_site_id' => $this->siteId, 'prepared_by' => AiMark::BY_AI,
            'context_model_code' => $code, 'context_model_id' => (int)$record->primaryKey])
            ->andFilterWhere(['!=', 'id', $exceptDraftId])->one();
        if ($draft) {
            $owner = CmsUser::findOne((int)$draft->cms_user_id);
            throw new AiRefusal(self::REASON_DRAFT_EXISTS, 'Черновик ИИ этому клиенту уже ждёт отправки'.($owner ? ' у '.$owner->shortDisplayName : '').' (черновик '.$draft->id.').');
        }
    }

    /** The client waits for a letter of another campaign (added, or fixed and not sent yet). @throws AiRefusal */
    private function checkNotInCampaign(ActiveRecord $record, ?int $exceptCampaignId): void
    {
        $type = CrmRecords::typeOfCode(method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record));
        $query = CmsCommCampaignRecipient::find()->alias('r')
            ->innerJoin(['c' => CmsCommCampaign::tableName()], 'c.id = r.cms_comm_campaign_id')
            ->andWhere(['c.cms_site_id' => $this->siteId, 'r.record_type' => $type, 'r.record_id' => (int)$record->primaryKey])
            ->andWhere(['r.status' => [CmsCommCampaignRecipient::STATUS_DRAFT, CmsCommCampaignRecipient::STATUS_PENDING, CmsCommCampaignRecipient::STATUS_SENDING]])
            ->andWhere(['c.status' => [CmsCommCampaign::STATUS_DRAFT, CmsCommCampaign::STATUS_RUNNING, CmsCommCampaign::STATUS_PAUSED]]);
        if ($exceptCampaignId) {
            $query->andWhere(['!=', 'c.id', $exceptCampaignId]);
        }
        if ($id = $query->select('c.id')->scalar()) {
            throw new AiRefusal(self::REASON_IN_CAMPAIGN, 'Клиент уже ждёт письма кампании '.$id.'.');
        }
    }

    /**
     * The offer for the client: the owner may send it, a КП belongs to this
     * client, it was not sent to the client recently.
     *
     * @return array [type, id, SendableInterface] @throws AiRefusal
     */
    private function offer(string $ref, ActiveRecord $record, User $owner, bool $checkHistory = true): array
    {
        $parsed = SendService::parseRef($ref);
        $sendable = $parsed ? (new SendService($owner, $this->siteId))->find($parsed[0], $parsed[1]) : null;
        if (!$sendable instanceof SendableInterface) {
            throw new AiRefusal(self::REASON_OFFER, 'Предложение «'.$ref.'» не найдено или '.$owner->shortDisplayName.' не может его отправить.');
        }
        $own = $sendable->getSendRecords();
        if ($own && !$this->belongs($record, $own)) {
            throw new AiRefusal(self::REASON_OFFER, 'Предложение «'.$ref.'» сделано для другого клиента.');
        }
        $source = \Yii::$app->communication->getSendableSource($parsed[0]);
        if ($checkHistory && $source instanceof SendHistoryInterface) {
            $this->checkOfferHistory($source, $parsed[1], $record);
        }

        return [$parsed[0], $parsed[1], $sendable];
    }

    /** @throws AiRefusal */
    private function checkOfferHistory(SendHistoryInterface $source, int $id, ActiveRecord $record): void
    {
        $days = self::limits()['recentDays'];
        $at = $source->lastSentAt($id, $record);
        if ($days > 0 && $at && $at >= time() - $days * 86400) {
            throw new AiRefusal(self::REASON_RECENT_OFFER, 'Это предложение уже отправляли клиенту '.\Yii::$app->formatter->asDate($at, 'php:d.m.Y')
                .' (меньше '.$days.' дней назад).');
        }
    }

    /** The record is one of the document's records, or their company is. */
    private function belongs(ActiveRecord $record, array $own): bool
    {
        $key = static fn(ActiveRecord $r) => get_class($r).':'.$r->primaryKey;
        $keys = array_map($key, array_filter($own, static fn($r) => $r instanceof ActiveRecord));
        if (in_array($key($record), $keys, true)) {
            return true;
        }
        $companyId = $record->hasAttribute('cms_company_id') ? (int)$record->cms_company_id : 0;

        return $companyId && in_array(\skeeks\cms\models\CmsCompany::class.':'.$companyId, $keys, true);
    }

    /* Results */

    private function summary(array $rows, bool $apply, array $extra): array
    {
        $refused = count(array_filter($rows, static fn($r) => $r['status'] === 'refused'));

        return array_merge([
            'success' => true,
            'requires_confirmation' => !$apply,
            'created' => $apply ? count($rows) - $refused : 0,
            'ready' => $apply ? 0 : count($rows) - $refused,
            'refused' => $refused,
            'items' => $rows,
            'limits' => self::limits() + ['used_today' => $this->usedToday()],
            'prepared' => AiMark::forApi($this->mark),
        ], $extra);
    }

    private function campaignData(CmsCommCampaign $campaign): array
    {
        return [
            'id' => $campaign->id ? (int)$campaign->id : null,
            'name' => (string)$campaign->name,
            'status' => (string)$campaign->status,
            'cms_comm_topic_id' => (int)$campaign->cms_comm_topic_id,
            'offer' => $campaign->offer_kind === CmsCommCampaign::OFFER_NONE ? null
                : ['kind' => (string)$campaign->offer_kind, 'type' => (string)$campaign->offer_type, 'id' => (int)$campaign->offer_id],
            'sender' => $campaign->sender_mode === CmsCommCampaign::SENDER_ACCOUNT ? ['cms_comm_account_id' => (int)$campaign->cms_comm_account_id] : 'responsible',
            'recipients' => $campaign->id ? (int)CmsCommCampaignRecipient::find()->andWhere(['cms_comm_campaign_id' => (int)$campaign->id])->count() : 0,
            'url' => $campaign->id ? self::url('/cmsCommunication/admin-cms-comm-campaign/view', ['pk' => (int)$campaign->id]) : null,
            'can_launch_here' => false,
        ];
    }

    private static function url(string $route, array $params): ?string
    {
        try {
            return \yii\helpers\Url::to(array_merge([$route], $params), true);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
