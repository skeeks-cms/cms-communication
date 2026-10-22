<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\base\ActiveRecord;
use skeeks\cms\query\CmsActiveQuery;
use yii\helpers\ArrayHelper;

/**
 * A mailbox or a channel as employees see it in the CRM: an address with its
 * own employee access. Messages arrive and leave through its connection.
 *
 * There is no single owner: every employee gets their own rights in
 * CmsCommAccountAccess.
 *
 * @property int                    $id
 * @property int|null               $created_by
 * @property int|null               $updated_by
 * @property int|null               $created_at
 * @property int|null               $updated_at
 * @property int                    $cms_site_id
 * @property int|null               $cms_comm_connection_id
 * @property string                 $channel
 * @property string                 $address
 * @property string|null            $name
 * @property string|null            $reply_to
 * @property int                    $is_expose_linked
 * @property int                    $is_active
 * @property string|null            $file_settings JSON, see getFileSettings()
 *
 * @property CmsCommConnection|null $connection
 * @property CmsCommAccountAccess[] $accesses
 */
class CmsCommAccount extends ActiveRecord
{
    public const CHANNEL_EMAIL = 'email';

    public static function tableName()
    {
        return '{{%cms_comm_account}}';
    }

    public static function channels(): array
    {
        return [
            self::CHANNEL_EMAIL => 'Электронная почта',
        ];
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['cms_site_id'], 'default', 'value' => static function () {
                return \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : null;
            }],
            [['channel'], 'default', 'value' => self::CHANNEL_EMAIL],
            [['is_active', 'is_expose_linked'], 'default', 'value' => 1],
            [['address', 'name', 'reply_to'], 'filter', 'filter' => static fn($value) => $value === null ? null : trim((string)$value)],
            [['address', 'reply_to'], 'filter', 'filter' => static fn($value) => $value === null || $value === '' ? $value : mb_strtolower($value)],
            [['name', 'reply_to', 'cms_comm_connection_id'], 'default', 'value' => null],
            [['address', 'channel', 'cms_site_id'], 'required'],
            [['cms_site_id', 'cms_comm_connection_id', 'is_active', 'is_expose_linked'], 'integer'],
            [['channel'], 'in', 'range' => array_keys(self::channels())],
            [['address', 'name', 'reply_to'], 'string', 'max' => 255],
            [['address', 'reply_to'], 'email', 'when' => static fn(self $model) => in_array($model->channel, [null, '', self::CHANNEL_EMAIL], true), 'whenClient' => 'function () { return true; }'],
            [['address'], 'unique', 'targetAttribute' => ['cms_site_id', 'channel', 'address'], 'message' => 'Такой ящик уже подключён.'],
            [['cms_comm_connection_id'], 'exist', 'targetClass' => CmsCommConnection::class, 'targetAttribute' => 'id',
                'filter' => function ($query) {
                    $query->andWhere(['cms_site_id' => $this->cms_site_id]);
                }, 'message' => 'Подключение не найдено.'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'cms_comm_connection_id' => 'Подключение',
            'channel' => 'Канал',
            'address' => 'Адрес',
            'name' => 'Имя отправителя',
            'reply_to' => 'Ответы направлять на',
            'is_expose_linked' => 'Показывать письма через связанные лиды, компании и клиентов',
            'is_active' => 'Активен',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'address' => 'Например info@skeeks.com.',
            'cms_comm_connection_id' => 'Логин на почтовом сервере, через который ящик принимает и отправляет письма.',
            'name' => 'Как получатель увидит отправителя, например «SkeekS». Если пусто — только адрес.',
            'reply_to' => 'Необязательно. Если ответы должны приходить в другой ящик.',
            'is_expose_linked' => 'Сотрудник без доступа к ящику увидит письмо, если видит связанный лид, компанию или клиента. Для личных ящиков можно выключить.',
        ]);
    }

    public function getConnection()
    {
        return $this->hasOne(CmsCommConnection::class, ['id' => 'cms_comm_connection_id']);
    }

    public function getAccesses()
    {
        return $this->hasMany(CmsCommAccountAccess::class, ['cms_comm_account_id' => 'id']);
    }

    public function asText()
    {
        return $this->name ? $this->name.' <'.$this->address.'>' : (string)$this->address;
    }

    /** @return array|null own file storage settings (MailFileSettings values), null — as the site settings of "Почта" */
    public function getFileSettings(): ?array
    {
        if (!$this->hasAttribute('file_settings') || !$this->file_settings) {
            return null;
        }
        $values = \yii\helpers\Json::decode((string)$this->file_settings);

        return is_array($values) && !empty($values['mode']) ? $values : null;
    }

    public function setFileSettings(?array $values): void
    {
        if ($this->hasAttribute('file_settings')) {
            $this->file_settings = $values ? \yii\helpers\Json::encode($values) : null;
        }
    }

    /** @return CmsActiveQuery */
    public static function find()
    {
        return new CmsActiveQuery(static::class);
    }
}
