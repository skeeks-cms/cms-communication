<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\base\ActiveRecord;
use skeeks\cms\communication\services\MailTemplates;
use skeeks\cms\helpers\RichTextHtml;
use yii\helpers\ArrayHelper;

/**
 * A quick reply template inserted from the letter form: personal
 * (cms_user_id, available from every mailbox) or shared by a mailbox
 * (cms_comm_account_id, no user: everyone who sends from it). Edited in the
 * standard backend windows (AdminCmsCommTemplateController); who may see and
 * change which template is decided by services\MailTemplates.
 *
 * @property int                 $id
 * @property int|null            $created_by
 * @property int|null            $updated_by
 * @property int|null            $created_at
 * @property int|null            $updated_at
 * @property int                 $cms_site_id
 * @property int|null            $cms_comm_account_id shared template of this mailbox
 * @property int|null            $cms_user_id personal template of this employee
 * @property string              $name
 * @property string              $body_html cleaned with MailTemplates::EDITOR_PRESET
 * @property int                 $priority
 *
 * @property CmsCommAccount|null $account
 */
class CmsCommTemplate extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_template}}';
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['name'], 'filter', 'filter' => static fn($v) => trim((string)$v)],
            // The letter formatting without pictures, as the editor of the template offers.
            [['body_html'], 'filter', 'filter' => static function ($v) {
                $html = RichTextHtml::clean((string)$v, MailTemplates::EDITOR_PRESET);
                return RichTextHtml::isEmpty($html) ? '' : $html;
            }],
            [['scope'], 'safe'],
            [['cms_comm_account_id'], 'filter', 'filter' => static fn($v) => $v ? (int)$v : null],
            [['name', 'body_html'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['body_html'], 'string', 'max' => 60000],
            [['priority'], 'integer'],
            [['priority'], 'default', 'value' => 100],
            [['cms_comm_account_id'], 'validateScope', 'skipOnEmpty' => false],
        ]);
    }

    /**
     * A shared template only of a mailbox the employee manages; without a
     * mailbox the template is the employee's own. The current employee
     * decides (administration form); MailTemplates::canEdit() guards changes.
     */
    public function validateScope($attribute)
    {
        $user = \Yii::$app->has('user') ? \Yii::$app->user->identity : null;
        if (!$user) {
            $this->addError($attribute, 'Шаблон сохраняет сотрудник.');
            return;
        }
        $service = new MailTemplates($user, $this->cms_site_id ? (int)$this->cms_site_id : null);
        $this->cms_site_id = $this->cms_site_id ?: (int)\Yii::$app->skeeks->site->id;
        if ($this->cms_comm_account_id) {
            $allowed = array_map(static fn($a) => (int)$a->id, $service->manageableAccounts());
            if (!in_array((int)$this->cms_comm_account_id, $allowed, true)) {
                $this->addError('scope', 'Общие шаблоны этого ящика вам менять нельзя.');
                return;
            }
            $this->cms_user_id = null;
        } else {
            $this->cms_user_id = (int)$user->id;
        }
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'name' => 'Название',
            'body_html' => 'Текст',
            'cms_comm_account_id' => 'Кому доступен',
            'scope' => 'Кому доступен',
        ]);
    }

    /** SCOPE_OWN or a mailbox id: the choice of the form (a select loses an empty value's label). */
    public const SCOPE_OWN = 'own';

    public function getScope(): string
    {
        return $this->cms_comm_account_id ? (string)$this->cms_comm_account_id : self::SCOPE_OWN;
    }

    public function setScope($value): void
    {
        $this->cms_comm_account_id = $value && $value !== self::SCOPE_OWN ? (int)$value : null;
    }

    public function asText()
    {
        return (string)$this->name;
    }

    public function getAccount()
    {
        return $this->hasOne(CmsCommAccount::class, ['id' => 'cms_comm_account_id']);
    }

    public function isPersonal(): bool
    {
        return (bool)$this->cms_user_id;
    }
}
