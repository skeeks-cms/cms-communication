<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\base\ActiveRecord;
use skeeks\cms\models\CmsUser;
use skeeks\cms\query\CmsActiveQuery;
use yii\helpers\ArrayHelper;

/**
 * A list of mailing recipients (stage 4D): CRM records — leads, companies,
 * clients — gathered by hand, from CRM sections, by an import or by AI.
 * Members are CmsCommListMember rows; a campaign takes one or more lists
 * and may exclude others. The work is done by services\RecipientLists.
 *
 * @property int         $id
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_site_id
 * @property string      $name
 * @property string|null $description
 *
 * @property CmsCommListMember[] $members
 * @property CmsUser|null        $createdBy
 */
class CmsCommList extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_list}}';
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['cms_site_id'], 'default', 'value' => static fn() => \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : null],
            [['name', 'description'], 'filter', 'filter' => static fn($v) => trim((string)$v)],
            [['name', 'cms_site_id'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['description'], 'string', 'max' => 5000],
            [['description'], 'default', 'value' => null],
            [['cms_site_id'], 'integer'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'id' => '№',
            'name' => 'Название',
            'description' => 'Описание',
            'created_at' => 'Создан',
            'created_by' => 'Создал',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'name' => 'Например: «Компании сферы Плитка без оплат», «Действующие клиенты», «Тестовый — свои адреса».',
            'description' => 'Для кого список и как его собирали — видно коллегам.',
        ]);
    }

    public function getMembers()
    {
        return $this->hasMany(CmsCommListMember::class, ['cms_comm_list_id' => 'id']);
    }

    public function getCreatedBy()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'created_by']);
    }

    /** Members not removed from the list. */
    public function getActiveCount(): int
    {
        return (int)CmsCommListMember::find()->andWhere(['cms_comm_list_id' => (int)$this->id, 'removed_at' => null])->count();
    }

    public function asText()
    {
        return 'Список №'.(int)$this->id.($this->name ? ' «'.$this->name.'»' : '');
    }

    /** @return string[] id => name: lists of the site, the newest first */
    public static function forSite(int $siteId): array
    {
        $result = [];
        foreach (self::find()->andWhere(['cms_site_id' => $siteId])->orderBy(['id' => SORT_DESC])->limit(500)->all() as $list) {
            $result[(int)$list->id] = (string)$list->name;
        }

        return $result;
    }

    /** @return CmsActiveQuery */
    public static function find()
    {
        return new CmsActiveQuery(static::class);
    }
}
