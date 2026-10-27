<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\campaign;

use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\mail\MailingServices;
use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommCampaign;
use skeeks\cms\communication\models\CmsCommCampaignRecipient;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\communication\services\MailboxPace;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\communication\services\MailingPolicy;
use skeeks\cms\communication\services\SendService;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;

/**
 * Campaign letters as ordinary personal letters from the managers'
 * mailboxes: from the mailbox of the lead's responsible employee (the
 * letter is from them, the answer comes to them) or from one chosen
 * mailbox. Cold contacts are allowed (MailingPolicy::MODE_MANAGER) because
 * the letters are few and personal: every mailbox keeps its pace
 * (services\MailboxPace) and the job `comm.campaign.mailbox` of the mailbox
 * sends one letter at a time, then waits a random pause or until the next
 * working period.
 *
 * Launching a campaign from a manager's mailbox needs the campaign right,
 * not the employee's own send right on that mailbox: writing from the
 * responsible employee's mailbox is the meaning of this channel.
 */
class MailboxCampaignChannel implements CampaignChannelInterface
{
    public const ID = 'mailbox';
    public const JOB = 'comm.campaign.mailbox';

    public function title(): string
    {
        return 'Ящики менеджеров';
    }

    public function hint(): string
    {
        return 'Обычные личные письма из почтовых ящиков: десятки писем в день с ящика, паузы между письмами, только в рабочие часы '
            .'(настраивается в карточке ящика, вкладка «Рассылки»). Можно писать и «холодным» контактам — с отпиской в каждом письме.';
    }

    public function policyMode(): string
    {
        return MailingPolicy::MODE_MANAGER;
    }

    public function unavailableReason(CmsCommCampaign $campaign, User $user): ?string
    {
        if ($campaign->sender_mode === CmsCommCampaign::SENDER_ACCOUNT) {
            $account = $campaign->account;
            if (!$account || !$account->is_active || !$account->cms_comm_connection_id) {
                return 'Выбранный ящик не подключён или выключен.';
            }
            // Cold contacts are allowed here, and a mailing service bans them.
            if (MailingServices::isService($account)) {
                return 'Выбранный ящик отправляет через сервис рассылок: выберите канал «Сервис рассылок» — он пишет только тем, кто дал согласие.';
            }
            if (!(new MailComposeService($user, (int)$campaign->cms_site_id))->canSendFrom((int)$account->id)) {
                return 'Вам нельзя отправлять письма с выбранного ящика: выберите свой ящик или «Ящик ответственного за сделку».';
            }
        }

        return null;
    }

    public function assignSenders(CmsCommCampaign $campaign, array $recipients, User $launcher): void
    {
        if ($campaign->sender_mode === CmsCommCampaign::SENDER_ACCOUNT) {
            foreach ($recipients as $recipient) {
                $recipient->cms_comm_account_id = (int)$campaign->cms_comm_account_id;
                $recipient->sender_user_id = (int)$launcher->id;
            }

            return;
        }

        $executors = [];
        foreach ($recipients as $recipient) {
            $executorId = (int)Campaigns::responsibleOf($recipient->getRecord());
            if (!array_key_exists($executorId, $executors)) {
                $executors[$executorId] = $executorId ? self::mailboxOf($executorId, (int)$campaign->cms_site_id) : null;
            }
            if ($executors[$executorId]) {
                $recipient->cms_comm_account_id = (int)$executors[$executorId]->id;
                $recipient->sender_user_id = $executorId;
            } else {
                $recipient->status = CmsCommCampaignRecipient::STATUS_EXCLUDED;
                $recipient->reason = CmsCommCampaignRecipient::REASON_NO_MAILBOX;
            }
        }
    }

    /**
     * The mailbox an employee writes from: their default one with a send
     * right, otherwise the first; active and connected.
     */
    public static function mailboxOf(int $userId, int $siteId): ?CmsCommAccount
    {
        $a = CmsCommAccount::tableName();
        $access = CmsCommAccountAccess::tableName();

        return CmsCommAccount::findMailboxes()
            ->innerJoin($access, $access.'.cms_comm_account_id = '.$a.'.id')
            ->andWhere([$a.'.cms_site_id' => $siteId, $a.'.is_active' => 1])
            ->andWhere(['not', [$a.'.cms_comm_connection_id' => null]])
            // A working mailbox: never the sender address of a mailing service.
            ->andWhere(MailingServices::notServiceCondition())
            ->andWhere([$access.'.cms_user_id' => $userId])
            ->andWhere(['!=', $access.'.send_level', CmsCommAccountAccess::SEND_NONE])
            ->orderBy([$access.'.is_default' => SORT_DESC, $a.'.id' => SORT_ASC])
            ->select($a.'.*')
            ->one();
    }

    public function start(CmsCommCampaign $campaign): void
    {
        $accounts = CmsCommCampaignRecipient::find()->select('cms_comm_account_id')->distinct()
            ->andWhere(['cms_comm_campaign_id' => (int)$campaign->id, 'status' => CmsCommCampaignRecipient::STATUS_PENDING])
            ->andWhere(['not', ['cms_comm_account_id' => null]])->column();
        foreach ($accounts as $accountId) {
            self::push((int)$accountId, (int)$campaign->cms_site_id);
        }
    }

    /** The sending job of the mailbox; a running or waiting one is enough (skip). */
    public static function push(int $accountId, int $siteId, int $delay = 0): void
    {
        \Yii::$app->jobs->push(self::JOB, ['accountId' => $accountId], [
            'siteId' => $siteId,
            'delay' => $delay,
            'triggerType' => 'manual',
        ]);
    }

    /**
     * One step of the mailbox job: sends the next letter of the running
     * campaigns of the mailbox when its pace allows.
     *
     * @return int|null seconds until the next step; null when nothing is left to send
     */
    public function sendNext(CmsCommAccount $account, ?int $now = null): ?int
    {
        $now = $now ?? time();
        $recipient = self::nextRecipient((int)$account->id);
        if (!$recipient) {
            return null;
        }
        $slot = MailboxPace::nextSlotFor($account, $now);
        if ($slot > $now) {
            return $slot - $now;
        }
        (new Campaigns())->deliver($recipient);

        return self::nextRecipient((int)$account->id) ? max(1, MailboxPace::pause(MailboxPace::settings($account))) : null;
    }

    /** The next waiting recipient of the running campaigns of the mailbox: the earlier campaign first. */
    public static function nextRecipient(int $accountId): ?CmsCommCampaignRecipient
    {
        $r = CmsCommCampaignRecipient::tableName();
        $c = CmsCommCampaign::tableName();

        return CmsCommCampaignRecipient::find()
            ->innerJoin($c, $c.'.id = '.$r.'.cms_comm_campaign_id')
            ->andWhere([$r.'.cms_comm_account_id' => $accountId, $r.'.status' => CmsCommCampaignRecipient::STATUS_PENDING])
            ->andWhere([$c.'.status' => CmsCommCampaign::STATUS_RUNNING, $c.'.channel' => self::ID])
            ->orderBy([$c.'.launched_at' => SORT_ASC, $r.'.id' => SORT_ASC])
            ->select($r.'.*')
            ->one();
    }

    public function deliver(CampaignLetter $letter): CmsCommMessage
    {
        $form = new ComposeForm();
        $form->cms_comm_account_id = (int)$letter->recipient->cms_comm_account_id;
        $form->to = (string)$letter->recipient->address;
        $form->subject = $letter->subject;
        $form->body_html = $letter->html;
        $form->cms_comm_topic_id = (int)$letter->campaign->cms_comm_topic_id;
        $form->cms_comm_campaign_id = (int)$letter->campaign->id;
        $form->record = $letter->record;
        // A personal letter AI wrote for this recipient keeps "Подготовлено ИИ".
        $form->aiMark = \skeeks\cms\communication\services\AiMark::ofRecipient($letter->recipient);
        if ($letter->sendable) {
            $form->sendables = [$letter->sendableRef()];
            $form->sendableInstances = [$letter->sendableRef() => $letter->sendable];
        }

        return (new MailComposeService(self::userOf($letter->user), (int)$letter->campaign->cms_site_id))->queue($form);
    }

    private static function userOf(User $user): User
    {
        return $user instanceof CmsUser ? $user : (CmsUser::findOne((int)$user->id) ?: $user);
    }

    /** The placeholder link of the offer in the letter editor (SendService::applyLink() makes it a button). */
    public static function linkPlaceholder(string $type, int $id, string $label): string
    {
        return '<a href="'.htmlspecialchars(SendService::placeholder($type, $id), ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a>';
    }
}
