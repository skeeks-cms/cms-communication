<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommSignature;
use skeeks\cms\communication\models\CmsCommTemplate;
use skeeks\cms\helpers\RichTextHtml;
use skeeks\cms\models\User;
use skeeks\cms\rbac\CmsManager;

/**
 * Signatures and quick reply templates of one employee.
 *
 * A signature belongs to the employee and a mailbox they may send from; the
 * letter form puts it below the text and swaps it with "От кого". A template
 * is personal (any mailbox) or shared by a mailbox: everyone who sends from
 * the mailbox uses it, its managers (can_manage) and administrators change it
 * (the template model checks it on save, AdminCmsCommTemplateController
 * opens only editable ones).
 * Both are cleaned with EDITOR_PRESET: the letter's formatting without
 * pictures, because temporary uploads would expire inside a stored text.
 */
class MailTemplates
{
    /** Formatting of signatures and templates: the letter editor without pictures. */
    public const EDITOR_PRESET = ['preset' => 'standard', 'fonts' => true, 'images' => false];

    /** Toolbar of their editors. */
    public const EDITOR_TOOLS = ['undo', 'redo', '|', 'bold', 'italic', 'underline', 'strike', '|', 'font', 'size', 'color', 'background', '|',
        'clear', '|', 'emoji', 'link', 'unlink', 'quote', '|', 'align', 'ul', 'ol'];

    private User $user;
    private int $siteId;
    private bool $isAdmin;
    private MailComposeService $compose;

    public function __construct(User $user, ?int $siteId = null)
    {
        $this->user = $user;
        $this->siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
        $this->isAdmin = \Yii::$app->authManager->checkAccess($user->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS);
        $this->compose = new MailComposeService($user, $this->siteId);
    }

    /** @return CmsCommAccount[] mailboxes the employee may send from (they may have a signature) */
    public function sendableAccounts(): array
    {
        return $this->compose->sendableAccounts();
    }

    /** @return array mailbox id => signature HTML, for every mailbox with a signature */
    public function signatures(): array
    {
        $ids = array_map(static fn($a) => (int)$a->id, $this->sendableAccounts());
        $result = [];
        foreach (CmsCommSignature::find()->andWhere(['cms_user_id' => (int)$this->user->id, 'cms_comm_account_id' => $ids ?: [0]])->all() as $signature) {
            if (trim((string)$signature->body_html) !== '') {
                $result[(int)$signature->cms_comm_account_id] = (string)$signature->body_html;
            }
        }

        return $result;
    }

    /** Saves (empty text: removes) the employee's signature for a mailbox. */
    public function saveSignature(int $accountId, string $html): bool
    {
        if (!$this->compose->canSendFrom($accountId)) {
            return false;
        }
        $html = RichTextHtml::clean($html, self::EDITOR_PRESET);
        $signature = CmsCommSignature::findOne(['cms_user_id' => (int)$this->user->id, 'cms_comm_account_id' => $accountId]);
        if (RichTextHtml::isEmpty($html)) {
            return !$signature || $signature->delete() !== false;
        }
        $signature = $signature ?? new CmsCommSignature(['cms_user_id' => (int)$this->user->id, 'cms_comm_account_id' => $accountId]);
        $signature->body_html = mb_substr($html, 0, 20000);

        return $signature->save(false);
    }

    /** Mailboxes whose shared templates the employee may change. */
    public function manageableAccounts(): array
    {
        $query = CmsCommAccount::find()->andWhere(['cms_site_id' => $this->siteId])->orderBy(['address' => SORT_ASC]);
        if (!$this->isAdmin) {
            $query->andWhere(['id' => CmsCommAccountAccess::find()->select('cms_comm_account_id')
                ->andWhere(['cms_user_id' => (int)$this->user->id, 'can_manage' => 1])]);
        }

        return $query->all();
    }

    /**
     * Templates the employee may insert: personal ones and the shared ones of
     * mailboxes they send from.
     *
     * @return CmsCommTemplate[]
     */
    public function templates(): array
    {
        $ids = array_map(static fn($a) => (int)$a->id, $this->sendableAccounts());

        return CmsCommTemplate::find()
            ->andWhere(['cms_site_id' => $this->siteId])
            ->andWhere(['or',
                ['cms_user_id' => (int)$this->user->id],
                ['and', ['cms_user_id' => null], ['cms_comm_account_id' => $ids ?: [0]]],
            ])
            ->orderBy(['priority' => SORT_ASC, 'name' => SORT_ASC])
            ->all();
    }

    /** @return array[] each: id, name, html, account (null — any mailbox) — for the letter form */
    public function templatesForForm(): array
    {
        return array_map(static fn(CmsCommTemplate $t) => [
            'id' => (int)$t->id,
            'name' => (string)$t->name,
            'html' => (string)$t->body_html,
            'account' => $t->cms_user_id ? null : (int)$t->cms_comm_account_id,
        ], $this->templates());
    }

    public function canEdit(CmsCommTemplate $template): bool
    {
        if ((int)$template->cms_site_id !== $this->siteId) {
            return false;
        }
        if ($template->cms_user_id) {
            return (int)$template->cms_user_id === (int)$this->user->id;
        }
        foreach ($this->manageableAccounts() as $account) {
            if ((int)$account->id === (int)$template->cms_comm_account_id) {
                return true;
            }
        }

        return false;
    }

    /** A template the employee may change, or null. */
    public function editable(int $id): ?CmsCommTemplate
    {
        $template = CmsCommTemplate::findOne($id);

        return $template && $this->canEdit($template) ? $template : null;
    }
}
