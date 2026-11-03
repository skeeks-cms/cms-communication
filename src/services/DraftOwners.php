<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommDraft;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;

/**
 * Whose drafts an employee may work with (owner's decision 03.11.2026): his
 * own and those of the employees he manages — his subordinates by departments,
 * every employee for an administrator. The rule is the core one
 * (CmsUserQuery::managedBy() over CmsDepartment::subordinateIds()), never a
 * copy of it. No consent of the employee is asked: the leader looks at, edits,
 * sends and hands over the drafts himself.
 *
 * Working with a draft of another employee means acting as its owner: the
 * letter form, the mailbox rights, "own clients only", the signature and the
 * day limits are the owner's (MailDrafts, MailComposeService, AiSend of the
 * owner); the letter keeps who actually sent it (acted_by_user_id, "по
 * поручению"). Handing a draft over changes its owner only to an employee the
 * actor manages (himself included).
 */
class DraftOwners
{
    private User $actor;
    private int $siteId;
    private ?array $ids = null;
    private ?array $targetIds = null;

    public function __construct(User $actor, ?int $siteId = null)
    {
        $this->actor = $actor;
        $this->siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
    }

    /**
     * @return int[] the actor and every employee he manages — inactive ones too: their drafts stay
     *     available to be read, sent or handed over (the core managedBy() has no activity condition)
     */
    public function ids(): array
    {
        if ($this->ids === null) {
            $u = CmsUser::tableName();
            $ids = CmsUser::find()->managedBy($this->actor)->select($u.'.id')->column();
            $this->ids = array_values(array_unique(array_merge([(int)$this->actor->id], array_map('intval', $ids))));
        }

        return $this->ids;
    }

    /** @return int[] who may receive a draft: the actor and the active employees he manages */
    public function targetIds(): array
    {
        if ($this->targetIds === null) {
            $active = CmsUser::find()->andWhere(['id' => $this->ids(), 'is_active' => 1])->select('id')->column();
            $this->targetIds = array_values(array_unique(array_merge([(int)$this->actor->id], array_map('intval', $active))));
        }

        return $this->targetIds;
    }

    /** May the actor work with drafts of this employee (himself included). */
    public function canManage(int $userId): bool
    {
        return $userId > 0 && in_array($userId, $this->ids(), true);
    }

    /** May the actor hand a draft over to this employee: himself or an active employee he manages. */
    public function canReceive(int $userId): bool
    {
        return $userId > 0 && in_array($userId, $this->targetIds(), true);
    }

    /** Does the actor see drafts of anyone but himself: the switch "Мои / сотрудник" is shown. */
    public function hasOthers(): bool
    {
        return count($this->employees()) > 1;
    }

    /**
     * Employees for the switch "Мои / сотрудник": the actor first, then the active
     * employees he manages and the inactive ones that still have drafts, by name.
     *
     * @return CmsUser[] indexed by id
     */
    public function employees(): array
    {
        $withDrafts = CmsCommDraft::find()->select('cms_user_id')->distinct()
            ->andWhere(['cms_site_id' => $this->siteId, 'cms_user_id' => $this->ids()])->column();

        return $this->users(array_unique(array_merge($this->targetIds(), array_map('intval', $withDrafts))));
    }

    /** @return CmsUser[] indexed by id — whom "Передать" offers: the actor first, then the active employees */
    public function targets(): array
    {
        return $this->users($this->targetIds());
    }

    /** @return CmsUser[] indexed by id, the actor first, the others by name */
    private function users(array $ids): array
    {
        $users = CmsUser::find()->andWhere(['id' => $ids ?: [0]])->indexBy('id')->all();
        $me = $users[(int)$this->actor->id] ?? null;
        unset($users[(int)$this->actor->id]);
        uasort($users, static fn(CmsUser $a, CmsUser $b) => strcasecmp((string)$a->shortDisplayName, (string)$b->shortDisplayName));

        return $me ? [(int)$me->id => $me] + $users : $users;
    }

    /** A draft of this site the actor may work with, or null. */
    public function find(?int $draftId): ?CmsCommDraft
    {
        if (!$draftId) {
            return null;
        }

        return CmsCommDraft::find()->andWhere(['id' => $draftId, 'cms_site_id' => $this->siteId, 'cms_user_id' => $this->ids()])->one();
    }

    /** @return CmsCommDraft[] drafts of this site the actor may work with, by the given ids */
    public function findAll(array $draftIds): array
    {
        $draftIds = array_values(array_filter(array_map('intval', $draftIds)));

        return $draftIds ? CmsCommDraft::find()->andWhere(['id' => $draftIds, 'cms_site_id' => $this->siteId, 'cms_user_id' => $this->ids()])->all() : [];
    }

    /**
     * Why a draft id is not the actor's to work with: not_found (sent, deleted,
     * another site) or not_own (a draft of an employee he does not manage).
     *
     * @return array reason, message
     */
    public function refusal(int $draftId): array
    {
        $other = $draftId ? CmsCommDraft::find()->andWhere(['id' => $draftId, 'cms_site_id' => $this->siteId])->one() : null;

        return $other
            ? ['reason' => AiSend::REASON_NOT_OWN, 'message' => AiSend::notOwnMessage($other)]
            : ['reason' => AiActions::REASON_NOT_FOUND, 'message' => 'Черновик не найден: он отправлен, удалён или не существует.'];
    }

    public const REASON_MOVED = 'moved';

    /**
     * Runs an operation on a draft with its row locked to the end of the
     * transaction (MailDrafts::lock()): the rights are checked on the locked
     * row, so a hand-over, a send or a change made meanwhile is seen, and none
     * can come between the check and the write (files included).
     *
     * @param callable $operation function (CmsCommDraft $locked): mixed
     * @param int|null $expectedOwnerId the owner the caller worked as (a window, a tool call): another one — moved
     * @param string|null $expectedVersion MailDrafts::version() the caller saw: another one — changed meanwhile
     * @throws AiRefusal not_found, not_own, moved, changed_after_check (nothing is written)
     */
    public function withLocked(int $draftId, callable $operation, ?int $expectedOwnerId = null, ?string $expectedVersion = null)
    {
        return CmsCommDraft::getDb()->transaction(function () use ($draftId, $operation, $expectedOwnerId, $expectedVersion) {
            $draft = $draftId ? MailDrafts::lock($draftId) : null;
            if (!$draft || (int)$draft->cms_site_id !== $this->siteId || !$this->canManage((int)$draft->cms_user_id)) {
                $refusal = $this->refusal($draftId);
                throw new AiRefusal($refusal['reason'], $refusal['message']);
            }
            if ($expectedOwnerId !== null && (int)$draft->cms_user_id !== $expectedOwnerId) {
                $owner = self::owner($draft);
                throw new AiRefusal(self::REASON_MOVED, 'Черновик передан сотруднику '.($owner ? $owner->shortDisplayName : '№'.$draft->cms_user_id)
                    .': откройте его заново.');
            }
            if ($expectedVersion !== null && $expectedVersion !== '' && !hash_equals(MailDrafts::version($draft), $expectedVersion)) {
                throw new AiRefusal(AiSend::REASON_CHANGED, 'Черновик изменён в другом окне или через API: откройте его заново, чтобы не потерять эти правки.');
            }

            return $operation($draft);
        });
    }

    /** The owner of a draft: the user the letter form, the mailbox rights and the signature belong to. */
    public static function owner(CmsCommDraft $draft): ?CmsUser
    {
        return CmsUser::findOne((int)$draft->cms_user_id);
    }

    /** Is this draft someone else's for the actor (it is sent "по поручению"). */
    public function isOthers(CmsCommDraft $draft): bool
    {
        return (int)$draft->cms_user_id !== (int)$this->actor->id;
    }

    /**
     * Hands drafts over to another employee: the owner changes, nothing else
     * of the letter. The mailbox stays when the new owner may send from it,
     * otherwise it becomes his default one; the signature block of the former
     * owner gives way to the new owner's one (or the new one is added). Each
     * draft is locked first (MailDrafts::lock()): a send of the same draft
     * waits, and a draft sent meanwhile is skipped. A draft of an employee the
     * actor does not manage, or to one he may not hand it to, is refused.
     *
     * @param CmsCommDraft[] $drafts
     * @param int|null $expectedOwnerId the owner the caller saw (a window, a list of one employee): a draft
     *     that has another owner now is skipped — a stale screen never hands over someone else's draft
     * @return int how many drafts changed the owner
     * @throws \DomainException
     */
    public function transfer(array $drafts, int $toUserId, ?int $expectedOwnerId = null): int
    {
        if (!$this->canReceive($toUserId)) {
            throw new \DomainException('Передать черновик можно только себе или работающему сотруднику, которым вы руководите.');
        }
        $to = CmsUser::findOne($toUserId);
        if (!$to) {
            throw new \DomainException('Сотрудник не найден.');
        }
        foreach ($drafts as $draft) {
            if (!$this->canManage((int)$draft->cms_user_id) || (int)$draft->cms_site_id !== $this->siteId) {
                throw new \DomainException('Черновик №'.$draft->id.' — не ваш и не вашего сотрудника.');
            }
        }
        $compose = new MailComposeService($to, $this->siteId);
        $default = $compose->defaultAccountId();
        $count = 0;
        foreach ($drafts as $loaded) {
            $count += (int)CmsCommDraft::getDb()->transaction(function () use ($loaded, $toUserId, $compose, $default, $expectedOwnerId) {
                $draft = MailDrafts::lock((int)$loaded->id);
                if (!$draft || !$this->canManage((int)$draft->cms_user_id) || (int)$draft->cms_user_id === $toUserId
                    || ($expectedOwnerId !== null && (int)$draft->cms_user_id !== $expectedOwnerId)) {
                    return false;
                }
                $fromId = (int)$draft->cms_user_id;
                $oldAccount = (int)$draft->cms_comm_account_id;
                $account = $oldAccount && $compose->canSendFrom($oldAccount) ? $oldAccount : $default;
                // A text of AI nobody changed stays "not changed": the new signature is not an edit of the employee.
                $untouched = $draft->prepared_hash && AiMark::hash((string)$draft->subject, (string)$draft->body_html) === (string)$draft->prepared_hash;
                $draft->cms_user_id = $toUserId;
                $draft->cms_comm_account_id = $account ?: null;
                $draft->body_html = self::replaceSignature((string)$draft->body_html,
                    $oldAccount ? MailSignatures::of($fromId, $oldAccount) : null, $account ? MailSignatures::of($toUserId, (int)$account) : null);
                if (\skeeks\cms\helpers\RichTextHtml::isEmpty((string)$draft->body_html)) {
                    $draft->body_html = null;
                }
                if ($untouched) {
                    $draft->prepared_hash = AiMark::hash((string)$draft->subject, (string)$draft->body_html);
                }
                // updated_at changes: the draft is fresh for its new owner (and the cleanup of old drafts).
                $draft->save(false, ['cms_user_id', 'cms_comm_account_id', 'body_html', 'prepared_hash', 'updated_at']);
                $loaded->refresh();

                return true;
            });
        }

        return $count;
    }

    /**
     * The text with the new owner's signature: the signature block of the
     * letter form ("-- " and the signature below it, MailSignatures::block()
     * and mail.js setSignature(); the editor drops its marker attribute, the
     * "-- " line stays) is replaced by the new one, whatever signature it
     * holds now; without such a block the block of the former signature written
     * as it is now is replaced; otherwise the author's text stays whole and the
     * new signature is added below it. No new signature — the block goes.
     */
    public static function replaceSignature(string $html, ?string $old, ?string $new): string
    {
        // The same signature of both owners, already in the text: the text stays as it is.
        if ($new !== null && $old === $new && MailSignatures::has($html, $new)) {
            return $html;
        }
        $without = self::withoutSignatureBlock($html);
        // The block exactly as MailSignatures::block() wrote it with the former signature (a text the
        // structure check did not take); never a bare fragment that may be the author's.
        if ($without === null && $old !== null && ($at = mb_strrpos($html, MailSignatures::block($old))) !== false) {
            $without = mb_substr($html, 0, $at).mb_substr($html, $at + mb_strlen(MailSignatures::block($old)));
        }
        $html = $without ?? $html;

        return $new === null ? $html : MailSignatures::append($html, $new);
    }

    /**
     * The text without its signature block, or null when it has none that can be told for sure. The
     * block is what the letter form and MailSignatures::block() put below the text: a <div> at the end
     * of the text (nothing but empty lines after it) whose first element is a <div> line "-- " and
     * which has more inside. A "--" written by the author elsewhere — a lone line, a paragraph, a block
     * in the middle — is the author's text and stays.
     */
    public static function withoutSignatureBlock(string $html): ?string
    {
        if (trim($html) === '' || mb_strpos($html, '--') === false) {
            return null;
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="sx-signature-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('sx-signature-root');
        if (!$root) {
            return null;
        }
        $empty = static fn(\DOMNode $node) => trim(str_replace("\xC2\xA0", ' ', (string)$node->textContent)) === ''
            && !($node instanceof \DOMElement && $node->getElementsByTagName('img')->length);
        // The last meaningful top-level node: the block must be it.
        $block = null;
        for ($node = $root->lastChild; $node; $node = $node->previousSibling) {
            if (!$empty($node)) {
                $block = $node;
                break;
            }
        }
        if (!$block instanceof \DOMElement || strtolower($block->nodeName) !== 'div') {
            return null;
        }
        $first = null;
        $more = false;
        foreach ($block->childNodes as $child) {
            if ($first === null) {
                if ($child instanceof \DOMElement) {
                    $first = $child;
                } elseif (!$empty($child)) {
                    return null;
                }
            } elseif (!$empty($child)) {
                $more = true;
            }
        }
        if (!$first || strtolower($first->nodeName) !== 'div' || !$more
            || trim(str_replace("\xC2\xA0", ' ', (string)$first->textContent)) !== '--' || $first->getElementsByTagName('*')->length > 0) {
            return null;
        }
        // The block, the empty lines after it and the empty line the form puts above it go.
        while ($block->nextSibling) {
            $root->removeChild($block->nextSibling);
        }
        $above = $block->previousSibling;
        $root->removeChild($block);
        if ($above instanceof \DOMElement && $empty($above)) {
            $root->removeChild($above);
        }
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return rtrim($result);
    }
}
