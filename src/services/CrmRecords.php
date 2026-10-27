<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * CRM records a letter can belong to: their names, icons and visibility for
 * an employee (the same forManager() scopes as the CRM lists). The model
 * code of a link (cms_comm_link.model_code) is the record class.
 */
final class CrmRecords
{
    public const LEAD = 'lead';
    public const COMPANY = 'company';
    public const CLIENT = 'client';
    public const DEAL = 'deal';
    public const TASK = 'task';

    /** A letter is linked to these by hand ("Привязать"). */
    public const LINKABLE = [self::LEAD, self::COMPANY, self::CLIENT, self::DEAL];
    /** These are created from a letter ("Создать задачу / лид / сделку"). */
    public const CREATABLE = [self::TASK, self::LEAD, self::DEAL];

    /** @var array user id => model code => record id => visible */
    private static array $visible = [];

    /** @return array[] type => class, title, icon, create (button text), number ("Задача №") */
    public static function types(): array
    {
        return [
            self::LEAD => ['class' => CmsLead::class, 'title' => 'Сделка', 'icon' => 'id-card', 'create' => 'Создать сделку', 'number' => 'Сделка №'],
            self::COMPANY => ['class' => CmsCompany::class, 'title' => 'Компания', 'icon' => 'building'],
            self::CLIENT => ['class' => CmsUser::class, 'title' => 'Клиент', 'icon' => 'user'],
            self::DEAL => ['class' => CmsDeal::class, 'title' => 'Услуга', 'icon' => 'handshake', 'create' => 'Создать услугу', 'number' => 'Услуга №'],
            self::TASK => ['class' => CmsTask::class, 'title' => 'Задача', 'icon' => 'tasks', 'create' => 'Создать задачу', 'number' => 'Задача №'],
        ];
    }

    public static function type(string $type): ?array
    {
        return self::types()[$type] ?? null;
    }

    /** Type of a link's model code, null for records mail does not know. */
    public static function typeOfCode(string $modelCode): ?string
    {
        foreach (self::types() as $type => $config) {
            if ($config['class'] === $modelCode) {
                return $type;
            }
        }

        return null;
    }

    public static function codeOf(string $type): string
    {
        return self::types()[$type]['class'];
    }

    /** Records of the type the employee sees in the CRM. */
    public static function visibleQuery(string $type, User $user): ActiveQuery
    {
        /** @var ActiveRecord $class */
        $class = self::codeOf($type);
        $query = $class::find()->forManager($user);
        if ($type === self::CLIENT) {
            // Clients only, and once: the client scope joins companies and managers.
            $query->andWhere([CmsUser::tableName().'.is_worker' => 0])->distinct();
        }

        return $query;
    }

    /** A record the employee sees, or null. */
    public static function find(string $type, int $id, User $user): ?ActiveRecord
    {
        if (!self::type($type) || $id <= 0) {
            return null;
        }
        $class = self::codeOf($type);

        return self::visibleQuery($type, $user)->andWhere([$class::tableName().'.id' => $id])->one();
    }

    public static function isVisible(string $modelCode, int $id, User $user): bool
    {
        $type = self::typeOfCode($modelCode);
        if (!$type) {
            return false;
        }
        $uid = (int)$user->id;
        if (!isset(self::$visible[$uid][$modelCode][$id])) {
            $class = self::codeOf($type);
            self::$visible[$uid][$modelCode][$id] = self::visibleQuery($type, $user)
                ->andWhere([$class::tableName().'.id' => $id])->exists();
        }

        return self::$visible[$uid][$modelCode][$id];
    }

    /** Record name for marks and lists. */
    public static function name(ActiveRecord $record): string
    {
        return method_exists($record, 'asText') ? (string)$record->asText() : '#'.$record->primaryKey;
    }

    /** "Задача №12", "Лид №5": the mark of a record created from a letter. */
    public static function number(string $type, ActiveRecord $record): string
    {
        return (self::types()[$type]['number'] ?? self::types()[$type]['title'].' №').$record->primaryKey;
    }
}
