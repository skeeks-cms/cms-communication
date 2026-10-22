<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\base\ActiveRecord;
use yii\helpers\ArrayHelper;

/**
 * A label of letters, shared by the site ("Счета", "Ждём ответа"). Labels
 * are CRM data; where the mail server keeps own keywords, they are written
 * to the letters on the server as IMAP keywords (see keyword()).
 *
 * @property int    $id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int    $cms_site_id
 * @property string $name
 * @property string $color   #rrggbb
 * @property int    $priority
 */
class CmsCommLabel extends ActiveRecord
{
    /** Colors offered for a new label. */
    public const COLORS = ['#e5484d', '#f76b15', '#e2a336', '#30a46c', '#12a594', '#0090ff', '#6e56cf', '#d6409f', '#64748b'];

    public static function tableName()
    {
        return '{{%cms_comm_label}}';
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['name'], 'filter', 'filter' => static fn($v) => trim(preg_replace('/\s+/u', ' ', (string)$v))],
            [['color'], 'filter', 'filter' => static fn($v) => strtolower(trim((string)$v))],
            [['color'], 'default', 'value' => self::COLORS[count(self::COLORS) - 1]],
            [['priority'], 'default', 'value' => 100],
            [['cms_site_id', 'name', 'color'], 'required'],
            [['cms_site_id', 'priority'], 'integer'],
            [['name'], 'string', 'max' => 64],
            [['color'], 'match', 'pattern' => '/^#[0-9a-f]{6}$/', 'message' => 'Цвет — в виде #rrggbb.'],
            [['name'], 'unique', 'targetAttribute' => ['cms_site_id', 'name'], 'message' => 'Такая метка уже есть.'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'name' => 'Название',
            'color' => 'Цвет',
        ]);
    }

    /** The IMAP keyword of the label: an ASCII atom, the same on every server. */
    public function keyword(): string
    {
        return self::keywordFor((int)$this->id);
    }

    public static function keywordFor(int $id): string
    {
        return 'SkeekS_label_'.$id;
    }

    public function asText()
    {
        return (string)$this->name;
    }
}
