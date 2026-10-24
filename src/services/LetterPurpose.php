<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveRecord;

/**
 * The purpose of a letter prepared by AI, and what each purpose needs. The
 * same rules apply when the API checks a draft and when an employee sends
 * the draft from "Почта" (ComposeForm::$purpose, set by
 * MailDrafts::applyAiDraft()).
 *
 * - advertising — an offer the client did not ask for: a mailing topic is
 *   required, MailingPolicy decides (opt-out, complaint, undeliverable), the
 *   letter carries the personal opt-out link and List-Unsubscribe.
 * - personal — work correspondence with a client who is in a dialog with us:
 *   the client wrote to us (a letter from the client, its company or its
 *   leads) within personalDays, or the draft answers the client's letter.
 * - service — a letter about our work with the client: an active deal, or a
 *   bill paid within serviceDays.
 * - individual_offer — one offer to one person: its own subject and text, a
 *   presentation or КП, reviewed and sent one by one. Everything of
 *   advertising (a topic kept inside for the opt-outs, MailingPolicy, the
 *   repetition checks) except the visible opt-out line and List-Unsubscribe.
 *   Chosen only explicitly, never in a campaign.
 *
 * Personal and service letters have no topic and no opt-out block — they are
 * not mailings; an opt-out of advertising is shown as a warning and never
 * changed, a complaint or an undeliverable address still blocks. Writing an
 * individual text, sending by hand or attaching a presentation decides
 * nothing: without a dialog or cooperation the letter is advertising.
 */
class LetterPurpose
{
    public const ADVERTISING = 'advertising';
    public const PERSONAL = 'personal';
    public const SERVICE = 'service';
    public const INDIVIDUAL_OFFER = 'individual_offer';

    public const REASON_PURPOSE = 'purpose';
    public const REASON_NO_TOPIC = 'no_topic';
    public const REASON_NO_DIALOG = 'no_dialog';
    public const REASON_NO_COOPERATION = 'no_cooperation';

    /** Days a client's own letter keeps a dialog open; days a paid bill keeps cooperation. */
    public const DAYS = ['personalDays' => 90, 'serviceDays' => 365];

    public static function names(): array
    {
        return [
            self::ADVERTISING => 'Рекламное предложение (тема рассылки, ссылка «Отписаться»)',
            self::PERSONAL => 'Личная рабочая переписка (клиент недавно писал нам)',
            self::SERVICE => 'Служебное письмо (действующая сделка или оплаченный счёт)',
            self::INDIVIDUAL_OFFER => 'Индивидуальное предложение одному человеку (тема рассылки — только для проверки отказов, без строки «Отписаться»)',
        ];
    }

    /** Purposes checked by a mailing topic (MailingPolicy, repetition): advertising and an individual offer. */
    public static function withTopic(?string $purpose): bool
    {
        return $purpose === self::ADVERTISING || $purpose === self::INDIVIDUAL_OFFER;
    }

    /** Does the letter carry the opt-out line and List-Unsubscribe: advertising only. */
    public static function optOut(?string $purpose): bool
    {
        return $purpose === self::ADVERTISING;
    }

    /** The purpose of a draft: its own, or advertising when it has a topic (drafts made before purposes). */
    public static function of(?string $purpose, ?int $topicId): ?string
    {
        if ($purpose && isset(self::names()[$purpose])) {
            return $purpose;
        }

        return $topicId ? self::ADVERTISING : null;
    }

    /** personalDays, serviceDays: "Настройки почты" → «Работа ИИ через API», then communication.aiLimits, then DAYS. */
    public static function days(): array
    {
        return array_map(static fn($row) => $row['value'], AiActions::limitSources(self::DAYS));
    }

    /**
     * What the purpose lacks for this letter.
     *
     * @param ActiveRecord|null $record the client (lead, company, client person)
     * @param int|null $replyToId the client's letter the draft answers
     * @return array problems [reason, message], warnings [message]
     */
    public static function check(string $purpose, ?int $topicId, ?ActiveRecord $record, string $address, int $siteId, ?int $replyToId = null): array
    {
        $problems = [];
        $warnings = [];
        if (!isset(self::names()[$purpose])) {
            return ['problems' => [['reason' => self::REASON_PURPOSE, 'message' => 'Назначение письма — advertising, individual_offer, personal или service.']], 'warnings' => []];
        }

        if (self::withTopic($purpose)) {
            $topic = $topicId ? CmsCommTopic::findOne(['id' => $topicId, 'cms_site_id' => $siteId]) : null;
            if (!$topic || !$topic->is_active) {
                $problems[] = ['reason' => self::REASON_NO_TOPIC, 'message' => $purpose === self::ADVERTISING
                    ? 'Рекламному предложению нужна действующая тема рассылки (cms_comm_topic_list): по ней проверяется отписка и добавляется ссылка «Отписаться».'
                    : 'Индивидуальному предложению нужна действующая тема рассылки (cms_comm_topic_list): по ней проверяются отказы получателя; строка «Отписаться» в письмо не выводится.'];
            }

            return ['problems' => $problems, 'warnings' => $warnings];
        }

        if ($topicId) {
            $problems[] = ['reason' => self::REASON_PURPOSE, 'message' => 'У личного и служебного письма нет темы рассылки: уберите тему или выберите advertising.'];
        }
        if (!$record) {
            $problems[] = ['reason' => self::REASON_PURPOSE, 'message' => 'Личное и служебное письмо пишется клиенту CRM.'];
        } elseif ($purpose === self::PERSONAL && !self::inDialog($record, $siteId, $replyToId)) {
            $problems[] = ['reason' => self::REASON_NO_DIALOG, 'message' => 'Клиент не писал нам за '.self::days()['personalDays'].' дней, и черновик не отвечает на его письмо: '
                .'это не рабочая переписка, а предложение — выберите purpose=advertising с темой рассылки.'];
        } elseif ($purpose === self::SERVICE && !self::cooperates($record)) {
            $problems[] = ['reason' => self::REASON_NO_COOPERATION, 'message' => 'У клиента нет действующей сделки и оплаченного счёта за '.self::days()['serviceDays'].' дней: '
                .'служебное письмо писать не о чем — выберите personal (если клиент недавно писал) или advertising.'];
        }

        // Not a mailing: an opt-out of advertising only warns; a complaint or a dead address still blocks.
        if ($address !== '') {
            $contact = \skeeks\cms\communication\models\CmsCommContact::find()
                ->andWhere(['cms_site_id' => $siteId, 'kind' => \skeeks\cms\communication\models\CmsCommContact::KIND_EMAIL, 'value' => mb_strtolower($address)])->one();
            $suppressions = $contact ? \skeeks\cms\communication\models\CmsCommSuppression::find()->select('reason')
                ->andWhere(['cms_comm_contact_id' => (int)$contact->id])->column() : [];
            foreach (array_intersect($suppressions, [MailingPolicy::REASON_COMPLAINT, MailingPolicy::REASON_BOUNCE]) as $reason) {
                $problems[] = ['reason' => $reason, 'message' => 'Писать нельзя: '.mb_strtolower(MailingPolicy::reasons()[$reason] ?? $reason).'.'];
            }
            if (in_array(MailingPolicy::REASON_UNSUBSCRIBED_ALL, $suppressions, true)) {
                $warnings[] = 'Адрес отписан от всех рекламных рассылок: это не мешает рабочему письму, но предложение без запроса клиента сюда не отправляйте.';
            }
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    /** The client wrote to us lately (its record, its company's leads, the company of a lead), or the draft answers its letter. */
    public static function inDialog(ActiveRecord $record, int $siteId, ?int $replyToId = null): bool
    {
        if ($replyToId && CmsCommMessage::find()->andWhere(['id' => $replyToId, 'direction' => CmsCommMessage::DIRECTION_IN])->exists()) {
            return true;
        }
        $m = CmsCommMessage::tableName();
        $since = time() - self::days()['personalDays'] * 86400;
        $owners = ['or'];
        foreach (self::related($record) as $code => $ids) {
            $owners[] = ['l.model_code' => $code, 'l.model_id' => $ids];
        }

        return CmsCommMessage::find()->innerJoin(['l' => CmsCommLink::tableName()], 'l.cms_comm_message_id = '.$m.'.id')
            ->andWhere([$m.'.cms_site_id' => $siteId, $m.'.direction' => CmsCommMessage::DIRECTION_IN, $m.'.is_auto' => 0])
            ->andWhere(['>=', $m.'.message_at', $since])->andWhere($owners)->exists();
    }

    /** An active deal or a bill paid within serviceDays of the client or its company. */
    public static function cooperates(ActiveRecord $record): bool
    {
        [$companyId, $userId] = self::owner($record);
        if (!$companyId && !$userId) {
            return false;
        }
        $owner = ['or', $companyId ? ['cms_company_id' => $companyId] : '0=1', $userId ? ['cms_user_id' => $userId] : '0=1'];
        $deal = CmsDeal::find()->andWhere(['is_active' => 1])->andWhere(['or', ['end_at' => null], ['>', 'end_at', time()]])->andWhere($owner)->exists();
        if ($deal) {
            return true;
        }
        $bill = '\skeeks\cms\shop\models\ShopBill';

        return class_exists($bill) && $bill::find()->andWhere(['>=', 'paid_at', time() - self::days()['serviceDays'] * 86400])->andWhere($owner)->exists();
    }

    /** @return array [company id, client person id] whose cooperation counts */
    private static function owner(ActiveRecord $record): array
    {
        if ($record instanceof CmsCompany) {
            return [(int)$record->id, null];
        }
        if ($record instanceof CmsLead) {
            return [$record->cms_company_id ? (int)$record->cms_company_id : null, $record->cms_user_id ? (int)$record->cms_user_id : null];
        }
        if ($record instanceof CmsUser) {
            return [null, (int)$record->id];
        }

        return [null, null];
    }

    /** @return array model code => ids whose letters count as the client's */
    private static function related(ActiveRecord $record): array
    {
        $code = method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record);
        $result = [$code => [(int)$record->primaryKey]];
        [$companyId] = self::owner($record);
        if ($companyId) {
            $result[CmsCompany::class][] = $companyId;
            $result[CmsLead::class] = array_merge($result[CmsLead::class] ?? [],
                array_map('intval', CmsLead::find()->select('id')->andWhere(['cms_company_id' => $companyId])->column()));
        }

        return $result;
    }
}
