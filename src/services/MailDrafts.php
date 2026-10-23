<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommDraft;
use skeeks\cms\communication\models\CmsCommDraftFile;
use skeeks\cms\helpers\RichTextHtml;
use skeeks\cms\models\User;
use skeeks\cms\services\TemporaryUploads;

/**
 * Drafts of one employee: the letter form saves itself while the employee
 * types, the "Черновики" folder lists the drafts, a draft opens in the form
 * again, sending removes it.
 *
 * Files: the form works with temporary uploads of its session
 * (TemporaryUploads tokens, removed by the hourly cleanup). Saving a draft
 * copies every new upload into the CMS storage as a draft file (the same
 * content already stored by a letter or a draft is reused); opening the draft
 * later puts the stored bytes back into the temporary area under a fresh
 * token, so the form and sending work exactly as with new uploads. A storage
 * file of a draft is removed when the file leaves the draft, the draft is
 * deleted (sent, removed by hand, or expired: cleanupExpired()) and nothing
 * else refers to it (MailFiles::deleteUnused()).
 */
class MailDrafts
{
    /** A draft keeps at most this many files, as a letter (ComposeForm::validateFiles). */
    public const MAX_FILES = 30;

    private User $user;
    private int $siteId;

    public function __construct(User $user, ?int $siteId = null)
    {
        $this->user = $user;
        $this->siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
    }

    /** Drafts of the employee on this site, latest first. */
    public function query(string $search = ''): \yii\db\ActiveQuery
    {
        $query = CmsCommDraft::find()
            ->andWhere(['cms_site_id' => $this->siteId, 'cms_user_id' => (int)$this->user->id])
            ->orderBy(['updated_at' => SORT_DESC, 'id' => SORT_DESC]);
        $search = trim($search);
        if ($search !== '') {
            $query->andWhere(['or',
                ['like', 'subject', $search],
                ['like', 'to_list', $search],
                ['like', 'body_html', $search],
            ]);
        }

        return $query;
    }

    public function count(): int
    {
        return (int)$this->query()->count();
    }

    /** An own draft, or null. */
    public function find(?int $id): ?CmsCommDraft
    {
        return $id ? $this->query()->andWhere(['id' => $id])->one() : null;
    }

    /** The latest own draft answering (or forwarding) a letter: "Ответить" continues it. */
    public function findFor(int $messageId, bool $forward = false): ?CmsCommDraft
    {
        return $this->query()->andWhere([$forward ? 'forward_message_id' : 'reply_to_message_id' => $messageId])->one();
    }

    /**
     * Saves the form as a draft (new or the given one). Nothing is validated
     * as for sending: recipients may be half typed. Only values the employee
     * may use are kept: their mailbox, letters and records they can see,
     * files of their session.
     */
    public function save(ComposeForm $form, MailComposeService $compose, ?CmsCommDraft $draft = null): CmsCommDraft
    {
        $draft = $draft ?? new CmsCommDraft(['cms_site_id' => $this->siteId, 'cms_user_id' => (int)$this->user->id]);
        $text = static fn($v, int $max) => mb_substr(trim((string)$v), 0, $max);

        $accountId = (int)$form->cms_comm_account_id;
        $draft->cms_comm_account_id = $accountId && $compose->canSendFrom($accountId) ? $accountId : null;
        $draft->to_list = $text($form->to, 5000) ?: null;
        $draft->cc_list = $text($form->cc, 5000) ?: null;
        $draft->bcc_list = $text($form->bcc, 5000) ?: null;
        $draft->subject = $text($form->subject, 500) ?: null;
        $html = RichTextHtml::clean(mb_substr((string)$form->body_html, 0, 500000), ComposeForm::EDITOR_PRESET);
        $draft->body_html = RichTextHtml::isEmpty($html) ? null : $html;

        $replyTo = $form->reply_to_message_id ? $compose->visibleMessage((int)$form->reply_to_message_id) : null;
        $forward = $form->forward_message_id ? $compose->visibleMessage((int)$form->forward_message_id) : null;
        $draft->reply_to_message_id = $replyTo ? (int)$replyTo->id : null;
        $draft->forward_message_id = $forward ? (int)$forward->id : null;
        $draft->is_reply_all = (int)($replyTo && $form->reply_all);
        $draft->include_quote = (int)(bool)$form->include_quote;
        $context = $form->context_model_code && $form->context_model_id
            ? $compose->contextRecord((string)$form->context_model_code, (int)$form->context_model_id) : null;
        $draft->context_model_code = $context ? (string)$form->context_model_code : null;
        $draft->context_model_id = $context ? (int)$form->context_model_id : null;
        // Attachments of the forwarded letter only.
        $allowed = $forward ? array_map(static fn($f) => (int)$f->storage_file_id, $forward->files) : [];
        $draft->setFileIds(array_intersect(array_map('intval', (array)$form->file_ids), $allowed));
        // "Прикрепить из…": only what the employee may still send.
        $sends = new SendService($this->user, $this->siteId);
        $draft->setSendableRefs(array_filter((array)$form->sendables, static fn($ref) => (bool)$sends->findRef((string)$ref)));

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $draft->save(false);
            $this->syncFiles($draft, array_map('strval', (array)$form->uploads), RichTextHtml::uploadTokens((string)$draft->body_html));
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $draft;
    }

    /**
     * Keeps the draft's files in step with the form: new uploads are copied
     * into the storage, files removed from the form leave the draft.
     *
     * @param string[] $attachments upload tokens of attachments
     * @param string[] $images upload tokens of pictures inside the text
     */
    private function syncFiles(CmsCommDraft $draft, array $attachments, array $images): void
    {
        $wanted = [];
        foreach ($attachments as $token) {
            $wanted[$token] = false;
        }
        foreach ($images as $token) {
            $wanted[$token] = true;
        }
        $existing = [];
        foreach ($draft->getFiles()->all() as $file) {
            if ($file->token !== null && array_key_exists($file->token, $wanted) && !isset($existing[$file->token])) {
                $existing[$file->token] = $file;
                if ((int)$file->is_inline !== (int)$wanted[$file->token]) {
                    $file->is_inline = (int)$wanted[$file->token];
                    $file->save(false, ['is_inline']);
                }
                continue;
            }
            $this->forgetFile($file);
        }

        $priority = 0;
        foreach ($wanted as $token => $inline) {
            $priority += 10;
            if (isset($existing[$token]) || count($existing) >= self::MAX_FILES) {
                continue;
            }
            $path = MailFiles::tempPath($token);
            if (!$path || filesize($path) > MailFiles::MAX_SIZE) {
                continue;
            }
            $hash = hash_file('sha256', $path);
            $size = (int)filesize($path);
            $name = MailFiles::tempName($token);
            // The upload stays in the temporary area: the open form goes on using it.
            $stored = MailFiles::findSame($hash, $size, $this->siteId)
                ?? MailFiles::storeBytes((string)file_get_contents($path), $name, null, $this->siteId);
            $file = new CmsCommDraftFile([
                'cms_comm_draft_id' => (int)$draft->id,
                'storage_file_id' => (int)$stored->id,
                'name' => mb_substr($name, 0, 255),
                'size' => $size,
                'content_hash' => $hash,
                'is_inline' => (int)$inline,
                'token' => mb_substr($token, 0, 255),
                'priority' => $priority,
            ]);
            $file->save(false);
            $existing[$token] = $file;
        }
    }

    /**
     * Fills the form with a draft. Its files go back into the temporary area
     * of this session under fresh tokens when the old ones are gone.
     *
     * @return array[] attachments for the form: token, name, size
     */
    public function fill(ComposeForm $form, CmsCommDraft $draft): array
    {
        $form->draft_id = (int)$draft->id;
        $form->cms_comm_account_id = $draft->cms_comm_account_id ?: $form->cms_comm_account_id;
        $form->to = (string)$draft->to_list;
        $form->cc = (string)$draft->cc_list;
        $form->bcc = (string)$draft->bcc_list;
        $form->subject = (string)$draft->subject;
        $form->reply_to_message_id = $draft->reply_to_message_id;
        $form->forward_message_id = $draft->forward_message_id;
        $form->reply_all = (int)$draft->is_reply_all;
        $form->include_quote = (int)$draft->include_quote;
        $form->context_model_code = (string)$draft->context_model_code;
        $form->context_model_id = $draft->context_model_id;
        $form->file_ids = $draft->getFileIds();
        $form->sendables = $draft->getSendableRefs();

        $tokens = [];
        $attachments = [];
        foreach ($draft->getFiles()->with('storageFile')->all() as $file) {
            $token = $this->reopen($file);
            if ($token === null) {
                // The stored copy is gone: the draft loses the file.
                $this->forgetFile($file);
                continue;
            }
            $tokens[(string)$file->getOldAttribute('token') ?: $token] = $token;
            if (!$file->is_inline) {
                $attachments[] = ['token' => $token, 'name' => (string)$file->name, 'size' => (int)$file->size];
            }
        }
        $form->uploads = array_column($attachments, 'token');
        // The stored text follows the fresh tokens at once: a draft reopened again before
        // the next autosave still finds its pictures. updateAttributes() keeps updated_at.
        $body = (string)$draft->body_html;
        $stored = RichTextHtml::replaceUploads($body, static function ($old) use ($tokens) {
            return isset($tokens[$old]) ? '<img data-sx-upload="'.htmlspecialchars($tokens[$old], ENT_QUOTES, 'UTF-8').'" alt="">' : '';
        });
        if ($draft->body_html !== null && $stored !== $body) {
            $draft->updateAttributes(['body_html' => $stored]);
        }
        $form->body_html = RichTextHtml::replaceUploads($body, static function ($old) use ($tokens) {
            $token = $tokens[$old] ?? null;

            return $token ? '<img src="'.htmlspecialchars(MailFiles::uploadUrl($token), ENT_QUOTES, 'UTF-8')
                .'" data-sx-upload="'.htmlspecialchars($token, ENT_QUOTES, 'UTF-8').'" alt="">' : '';
        });

        return $attachments;
    }

    /** The draft file as an upload of this session: its token, or null when the bytes are gone. */
    private function reopen(CmsCommDraftFile $file): ?string
    {
        if ($file->token && MailFiles::tempPath((string)$file->token)) {
            return (string)$file->token;
        }
        $path = $file->storageFile ? (string)$file->storageFile->rootSrc : '';
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $upload = TemporaryUploads::putBytes((string)file_get_contents($path), (string)$file->name, 'mail');
        $old = (string)$file->token;
        $file->token = mb_substr($upload['token'], 0, 255);
        $file->save(false, ['token']);
        // The text refers to the old token: fill() maps it to the new one.
        $file->setOldAttribute('token', $old);

        return $upload['token'];
    }

    /** Removes a draft with its files (sent, deleted by hand or expired). */
    public static function delete(CmsCommDraft $draft): void
    {
        foreach ($draft->getFiles()->all() as $file) {
            self::forgetFile($file);
        }
        $draft->delete();
    }

    /** A file leaves its draft; the storage file goes when nothing else refers to it. */
    public static function forgetFile(CmsCommDraftFile $file): int
    {
        $storageId = (int)$file->storage_file_id;
        $file->delete();

        return MailFiles::deleteUnused($storageId);
    }

    /**
     * The daily cleanup: drafts not changed for $days days are removed with
     * their files. Works without a session (the agent job).
     *
     * @return array removed drafts, freed bytes
     */
    public static function cleanupExpired(int $siteId, int $days, ?callable $onProgress = null): array
    {
        $removed = 0;
        $freed = 0;
        if ($days <= 0) {
            return ['removed' => 0, 'freed' => 0];
        }
        $query = CmsCommDraft::find()->andWhere(['cms_site_id' => $siteId])->andWhere(['<', 'updated_at', time() - $days * 86400]);
        foreach ($query->each(100) as $draft) {
            foreach ($draft->getFiles()->all() as $file) {
                $freed += self::forgetFile($file);
            }
            $draft->delete();
            $removed++;
            if ($onProgress && $onProgress($removed, $freed) === false) {
                break;
            }
        }

        return ['removed' => $removed, 'freed' => $freed];
    }
}
