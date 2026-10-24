<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommTopic;
use skeeks\cms\models\User;
use skeeks\cms\rbac\CmsManager;

/**
 * Mailing topics outside the "Темы рассылок" section (API): the same model
 * and rules (a unique name per site), the same right — the administrator's,
 * as the section. A topic is never made silently by a send: a site without
 * topics answers what to set up first.
 */
class MailingTopics
{
    private User $user;
    private int $siteId;

    public function __construct(User $user, ?int $siteId = null)
    {
        $this->user = $user;
        $this->siteId = $siteId ?? (int)\Yii::$app->skeeks->site->id;
    }

    /** The section's right: only administrators manage topics. */
    public function canManage(): bool
    {
        return \Yii::$app->authManager && \Yii::$app->authManager->checkAccess((int)$this->user->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS);
    }

    /**
     * A new topic or changes of one (only the given fields: name, description, is_active, priority).
     *
     * @return array model, errors (field => text), changed (attribute names)
     */
    public function save(?CmsCommTopic $topic, array $data, bool $apply): array
    {
        if (!$this->canManage()) {
            throw new \DomainException('Темы рассылок настраивает администратор (раздел «Настройки → Темы рассылок»).');
        }
        $topic = $topic ?? new CmsCommTopic(['cms_site_id' => $this->siteId]);
        foreach (['name', 'description', 'is_active', 'priority'] as $attribute) {
            if (array_key_exists($attribute, $data)) {
                $topic->$attribute = is_bool($data[$attribute]) ? (int)$data[$attribute] : $data[$attribute];
            }
        }
        $changed = array_keys($topic->getDirtyAttributes());
        if (!$topic->validate()) {
            return ['model' => $topic, 'errors' => $topic->getFirstErrors(), 'changed' => $changed];
        }
        if ($apply && $changed) {
            $topic->save(false);
        }

        return ['model' => $topic, 'errors' => [], 'changed' => $changed];
    }

    public function find(int $id): ?CmsCommTopic
    {
        return CmsCommTopic::findOne(['id' => $id, 'cms_site_id' => $this->siteId]);
    }

    public static function data(CmsCommTopic $topic): array
    {
        return [
            'id' => $topic->id ? (int)$topic->id : null,
            'name' => (string)$topic->name,
            'description' => $topic->description !== null ? (string)$topic->description : null,
            'is_active' => (bool)$topic->is_active,
            'priority' => (int)$topic->priority,
        ];
    }
}
