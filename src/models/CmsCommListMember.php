<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\communication\services\AiMark;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\models\CmsUser;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A member of a list of recipients: a CRM record (lead, company, client),
 * never an address — the campaign channel picks the contact. Keeps who,
 * when and from where added it; a member removed from the list stays as a
 * row with `removed_at` (its history), adding it again brings it back.
 *
 * @property int         $id
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_comm_list_id
 * @property string      $record_type CrmRecords type: lead, company, client
 * @property int         $record_id
 * @property int|null    $added_by
 * @property string      $added_via VIA_*
 * @property string|null $added_ref the section, "import:7", "mcp:<client>"
 * @property int|null    $removed_at
 * @property int|null    $removed_by
 *
 * @property CmsCommList  $list
 * @property CmsUser|null $addedBy
 * @property CmsUser|null $removedBy
 */
class CmsCommListMember extends ActiveRecord
{
    public const VIA_MANUAL = 'manual';
    /** The bulk action of a CRM section: companies, leads, clients. */
    public const VIA_SECTION = 'section';
    public const VIA_IMPORT = 'import';
    public const VIA_MCP = 'mcp';
    /** A rule of the list (ListRules): the saved filter of a CRM section, ref "rule:N". */
    public const VIA_RULE = 'rule';

    public static function tableName()
    {
        return '{{%cms_comm_list_member}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public static function vias(): array
    {
        return [
            self::VIA_MANUAL => 'вручную',
            self::VIA_SECTION => 'из раздела CRM',
            self::VIA_IMPORT => 'импорт',
            self::VIA_MCP => 'ИИ',
            self::VIA_RULE => 'по правилу',
        ];
    }

    /** "из раздела «Компании»", "импорт №7", "ИИ (claude)". */
    public function getViaText(): string
    {
        $ref = (string)$this->added_ref;
        if ($this->added_via === self::VIA_SECTION) {
            $sections = ['companies' => 'Компании', 'leads' => 'Лиды', 'clients' => 'Клиенты'];

            return 'из раздела «'.($sections[$ref] ?? $ref).'»';
        }
        if ($this->added_via === self::VIA_IMPORT) {
            return 'импорт №'.(int)preg_replace('/\D/', '', $ref);
        }
        if ($this->added_via === self::VIA_RULE) {
            return 'по правилу №'.(int)preg_replace('/\D/', '', $ref);
        }
        if ($this->added_via === self::VIA_MCP) {
            return 'ИИ'.($ref !== '' ? ' ('.AiMark::clientName($ref).')' : '');
        }

        return self::vias()[$this->added_via] ?? (string)$this->added_via;
    }

    public function getIsRemoved(): bool
    {
        return (bool)$this->removed_at;
    }

    public function getList()
    {
        return $this->hasOne(CmsCommList::class, ['id' => 'cms_comm_list_id']);
    }

    public function getAddedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'added_by']);
    }

    public function getRemovedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'removed_by']);
    }

    /** The CRM record of the member, or null when it was removed from the CRM. */
    public function getRecord(): ?ActiveRecord
    {
        if ($this->isRelationPopulated('record')) {
            return $this->getRelatedRecords()['record'];
        }
        $type = CrmRecords::type((string)$this->record_type);
        if (!$type) {
            return null;
        }
        /** @var ActiveRecord $class */
        $class = $type['class'];

        return $class::findOne((int)$this->record_id);
    }
}
