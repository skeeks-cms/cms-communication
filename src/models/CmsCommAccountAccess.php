<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\base\ActiveRecord;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveQuery;
use yii\helpers\ArrayHelper;

/**
 * Rights of one employee on one mailbox or channel.
 *
 * Reading: none, linked (only messages linked to leads, companies and clients
 * the employee can see), all. Sending: none, own (only to visible CRM contacts
 * or in visible conversations), any. "Default" selects the sender in the
 * compose dialog; one default mailbox per employee and site.
 *
 * @property int            $id
 * @property int|null       $created_by
 * @property int|null       $updated_by
 * @property int|null       $created_at
 * @property int|null       $updated_at
 * @property int            $cms_comm_account_id
 * @property int            $cms_user_id
 * @property string         $read_level
 * @property string         $send_level
 * @property int            $can_manage
 * @property int            $can_manage_messages
 * @property int            $is_default
 * @property int            $is_responsible
 * @property int            $is_notify new incoming letters notify the employee (their own choice)
 *
 * @property CmsCommAccount $account
 * @property CmsUser        $user
 */
class CmsCommAccountAccess extends ActiveRecord
{
    public const READ_NONE = 'none';
    public const READ_LINKED = 'linked';
    public const READ_ALL = 'all';

    public const SEND_NONE = 'none';
    public const SEND_OWN = 'own';
    public const SEND_ANY = 'any';

    public static function tableName()
    {
        return '{{%cms_comm_account_access}}';
    }

    public static function readLevels(): array
    {
        return [
            self::READ_ALL => 'Все письма ящика',
            self::READ_LINKED => 'Только связанные с его сделками, компаниями и клиентами',
            self::READ_NONE => 'Нет',
        ];
    }

    public static function sendLevels(): array
    {
        return [
            self::SEND_ANY => 'На любой адрес',
            self::SEND_OWN => 'Только по своим сделкам, компаниям и клиентам',
            self::SEND_NONE => 'Нет',
        ];
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['read_level'], 'default', 'value' => self::READ_LINKED],
            [['send_level'], 'default', 'value' => self::SEND_NONE],
            [['can_manage', 'can_manage_messages', 'is_default', 'is_responsible'], 'default', 'value' => 0],
            [['is_notify'], 'default', 'value' => 1],
            [['cms_comm_account_id', 'cms_user_id', 'read_level', 'send_level'], 'required'],
            [['cms_comm_account_id', 'cms_user_id'], 'integer'],
            [['can_manage', 'can_manage_messages', 'is_default', 'is_responsible', 'is_notify'], 'boolean'],
            [['read_level'], 'in', 'range' => array_keys(self::readLevels())],
            [['send_level'], 'in', 'range' => array_keys(self::sendLevels())],
            [['cms_comm_account_id'], 'exist', 'targetClass' => CmsCommAccount::class, 'targetAttribute' => 'id',
                'filter' => static function ($query) {
                    if (\Yii::$app->skeeks->site) {
                        $query->andWhere(['cms_site_id' => (int)\Yii::$app->skeeks->site->id]);
                    }
                }, 'message' => 'Ящик не найден.'],
            [['cms_user_id'], 'exist', 'targetClass' => CmsUser::class, 'targetAttribute' => 'id'],
            [['cms_user_id'], 'unique', 'targetAttribute' => ['cms_comm_account_id', 'cms_user_id'], 'message' => 'У этого сотрудника уже есть доступ к ящику.'],
            [['is_default'], 'validateDefaultCanSend'],
        ]);
    }

    public function validateDefaultCanSend($attribute)
    {
        if ($this->is_default && $this->send_level === self::SEND_NONE) {
            $this->addError($attribute, 'Ящиком по умолчанию может быть только ящик, с которого сотрудник может отправлять письма.');
        }
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'cms_comm_account_id' => 'Ящик',
            'cms_user_id' => 'Сотрудник',
            'read_level' => 'Чтение',
            'send_level' => 'Отправка',
            'can_manage' => 'Управление ящиком',
            'can_manage_messages' => 'Управление письмами',
            'is_default' => 'Ящик по умолчанию',
            'is_responsible' => 'Ответственный за ящик',
            'is_notify' => 'Уведомлять о новых письмах',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'can_manage' => 'Настройки ящика и права других сотрудников.',
            'can_manage_messages' => 'Удалять письма, переносить в архив, в папки и в спам, ставить метки. Письма переносятся в самом почтовом ящике — у всех сотрудников.',
            'is_default' => 'Подставляется отправителем в окне «Написать». У сотрудника один ящик по умолчанию.',
            'is_responsible' => 'Получает служебные уведомления: подключение не работает, накопились неразобранные письма.',
        ]);
    }

    public function getAccount()
    {
        return $this->hasOne(CmsCommAccount::class, ['id' => 'cms_comm_account_id']);
    }

    public function getUser()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'cms_user_id']);
    }

    /**
     * Only one default account per employee within the site and channel (a mailbox, a bot).
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($this->is_default && ($insert || array_key_exists('is_default', $changedAttributes))) {
            $siteAccountIds = CmsCommAccount::find()
                ->select('id')
                ->andWhere(['cms_site_id' => $this->account->cms_site_id, 'channel' => (string)$this->account->channel]);
            foreach (static::find()
                ->andWhere(['cms_user_id' => $this->cms_user_id, 'is_default' => 1])
                ->andWhere(['cms_comm_account_id' => $siteAccountIds])
                ->andWhere(['!=', 'id', $this->id])
                ->all() as $other) {
                $other->is_default = 0;
                $other->save(false, ['is_default', 'updated_at', 'updated_by']);
            }
        }
    }

    public function asText()
    {
        return $this->user ? $this->user->shortDisplayName : (string)$this->cms_user_id;
    }

    /** @return ActiveQuery */
    public static function find()
    {
        return new ActiveQuery(static::class);
    }
}
