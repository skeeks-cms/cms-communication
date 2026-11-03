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
     * @return int how many drafts changed the owner
     * @throws \DomainException
     */
    public function transfer(array $drafts, int $toUserId): int
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
            $count += (int)CmsCommDraft::getDb()->transaction(function () use ($loaded, $toUserId, $compose, $default) {
                $draft = MailDrafts::lock((int)$loaded->id);
                if (!$draft || !$this->canManage((int)$draft->cms_user_id) || (int)$draft->cms_user_id === $toUserId) {
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
     * holds now; without such a block the former signature found as it is now
     * is replaced; otherwise the new signature is added below the text. No new
     * signature — the block goes.
     */
    public static function replaceSignature(string $html, ?string $old, ?string $new): string
    {
        // The same signature of both owners, already in the text: the text stays as it is.
        if ($new !== null && $old === $new && MailSignatures::has($html, $new)) {
            return $html;
        }
        $without = self::withoutSignatureBlock($html);
        if ($without === null && $old !== null) {
            foreach ([MailSignatures::block($old), $old] as $needle) {
                $at = mb_strrpos($html, $needle);
                if ($at !== false) {
                    $without = mb_substr($html, 0, $at).mb_substr($html, $at + mb_strlen($needle));
                    break;
                }
            }
        }
        $html = $without ?? $html;

        return $new === null ? $html : MailSignatures::append($html, $new);
    }

    /**
     * The text without its last signature block: the element whose first child is the "-- " line
     * (the line alone when it is not inside a block: it and everything after it). Null — no block.
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
        $line = null;
        foreach ((new \DOMXPath($doc))->query('.//*', $root) as $element) {
            $text = trim(str_replace("\xC2\xA0", ' ', (string)$element->textContent));
            if ($text === '--' && $element->getElementsByTagName('*')->length === 0) {
                $line = $element;
            }
        }
        if (!$line) {
            return null;
        }
        $parent = $line->parentNode;
        $first = $parent;
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement || trim((string)$child->textContent) !== '') {
                $first = $child;
                break;
            }
        }
        if ($parent !== $root && $first === $line) {
            $parent->parentNode->removeChild($parent);
        } else {
            while ($line->nextSibling) {
                $parent->removeChild($line->nextSibling);
            }
            $parent->removeChild($line);
        }
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }
        // The empty line the form puts above the block goes with it.
        return (string)preg_replace('~(<div><br\s*/?></div>|<p><br\s*/?></p>)\s*$~i', '', rtrim($result));
    }
}
