<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models\queries;

use skeeks\cms\communication\models\CmsCommAccount;
use skeeks\cms\communication\models\CmsCommAccountAccess;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\models\CmsCommMessageMailbox;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;
use skeeks\cms\rbac\CmsManager;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * @method CmsCommMessage|null one($db = null)
 * @method CmsCommMessage[] all($db = null)
 */
class CmsCommMessageQuery extends ActiveQuery
{
    public function cmsSite(?int $siteId = null)
    {
        if ($siteId === null) {
            $siteId = \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : null;
        }

        return $this->andWhere([CmsCommMessage::tableName().'.cms_site_id' => $siteId]);
    }

    /**
     * Messages of one mailbox: received into it or sent from it.
     */
    public function inAccount(int $accountId)
    {
        $t = CmsCommMessage::tableName();

        return $this->andWhere(['or',
            [$t.'.cms_comm_account_id' => $accountId],
            ['exists', CmsCommMessageMailbox::find()
                ->andWhere(['cms_comm_account_id' => $accountId])
                ->andWhere([CmsCommMessageMailbox::tableName().'.cms_comm_message_id' => new Expression($t.'.id')])],
        ]);
    }

    /**
     * Messages an employee may read:
     * - administrators: all messages of the site;
     * - mailboxes where the employee reads everything;
     * - letters the employee sent;
     * - letters linked to a lead, company or client the employee can see,
     *   unless every mailbox of the letter hides linked access
     *   (CmsCommAccount::$is_expose_linked).
     *
     * Visibility follows the same forManager() scopes as the CRM lists and is
     * built from IN/EXISTS subqueries, never from joins.
     */
    public function forManager(User $user = null)
    {
        if ($user === null) {
            $user = \Yii::$app->user->identity;
            $isAdmin = \Yii::$app->user->can(CmsManager::PERMISSION_ROLE_ADMIN_ACCESS);
        } else {
            $isAdmin = \Yii::$app->authManager->checkAccess($user->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS);
        }
        if (!$user) {
            return $this->andWhere('1=0');
        }
        if ($isAdmin) {
            return $this;
        }

        $t = CmsCommMessage::tableName();
        $userId = (int)$user->id;
        $messageId = new Expression($t.'.id');

        $readAllAccounts = CmsCommAccountAccess::find()->select('cms_comm_account_id')
            ->andWhere(['cms_user_id' => $userId, 'read_level' => CmsCommAccountAccess::READ_ALL]);
        $exposedAccounts = CmsCommAccount::find()->select('id')->andWhere(['is_expose_linked' => 1]);

        $exposed = ['or',
            ['and', ['not', ['exists', CmsCommMessageMailbox::find()->andWhere([CmsCommMessageMailbox::tableName().'.cms_comm_message_id' => $messageId])]],
                ['or', [$t.'.cms_comm_account_id' => null], [$t.'.cms_comm_account_id' => $exposedAccounts]]],
            ['exists', CmsCommMessageMailbox::find()
                ->andWhere([CmsCommMessageMailbox::tableName().'.cms_comm_message_id' => $messageId])
                ->andWhere(['cms_comm_account_id' => $exposedAccounts])],
        ];

        return $this->andWhere(['or',
            [$t.'.sent_by_user_id' => $userId],
            [$t.'.cms_comm_account_id' => $readAllAccounts],
            ['exists', CmsCommMessageMailbox::find()
                ->andWhere([CmsCommMessageMailbox::tableName().'.cms_comm_message_id' => $messageId])
                ->andWhere(['cms_comm_account_id' => $readAllAccounts])],
            ['and', $exposed, self::linkedToVisibleCondition($user)],
        ]);
    }

    /**
     * Letters linked to a lead, company or client the employee can see in
     * the CRM ("Мои клиенты"). Reading a whole mailbox does not make its
     * other clients the employee's.
     */
    public function linkedToVisible(User $user)
    {
        return $this->andWhere(self::linkedToVisibleCondition($user));
    }

    private static function linkedToVisibleCondition(User $user): array
    {
        $messageId = new Expression(CmsCommMessage::tableName().'.id');
        $linkedTo = static function (string $modelCode, $visibleIds) use ($messageId) {
            return ['exists', CmsCommLink::find()
                ->andWhere([CmsCommLink::tableName().'.cms_comm_message_id' => $messageId])
                ->andWhere(['model_code' => $modelCode, 'model_id' => $visibleIds])];
        };

        return ['or',
            $linkedTo((new CmsLead())->skeeksModelCode, CmsLead::find()->forManager($user)->select(CmsLead::tableName().'.id')),
            $linkedTo((new CmsCompany())->skeeksModelCode, CmsCompany::find()->forManager($user)->select(CmsCompany::tableName().'.id')),
            $linkedTo((new CmsUser())->skeeksModelCode, CmsUser::find()->forManager($user)->select(CmsUser::tableName().'.id')),
        ];
    }
}
