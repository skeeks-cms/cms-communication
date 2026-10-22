<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\base\ActiveRecord;
use skeeks\cms\communication\CommunicationComponent;
use skeeks\cms\communication\drivers\ConnectionDriver;
use skeeks\cms\query\CmsActiveQuery;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;

/**
 * A login on an external service: a mail server account, a bot, an API key.
 * One connection may serve several mailboxes (for example folders of one login).
 *
 * The secret column holds encrypted driver secrets and is never exposed:
 * it is excluded from fields(), forms and logs.
 *
 * @property int              $id
 * @property int|null         $created_by
 * @property int|null         $updated_by
 * @property int|null         $created_at
 * @property int|null         $updated_at
 * @property int              $cms_site_id
 * @property string           $name
 * @property string           $driver
 * @property string|null      $driver_config
 * @property string|null      $secret
 * @property int              $is_active
 *
 * @property CmsCommAccount[] $accounts
 * @property string           $driverTitle
 */
class CmsCommConnection extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%cms_comm_connection}}';
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['cms_site_id'], 'default', 'value' => static function () {
                return \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : null;
            }],
            [['is_active'], 'default', 'value' => 1],
            [['name'], 'filter', 'filter' => 'trim'],
            [['name', 'driver', 'cms_site_id'], 'required'],
            [['cms_site_id', 'is_active'], 'integer'],
            [['name'], 'string', 'max' => 255],
            [['driver'], 'in', 'range' => static function () {
                return array_keys(static::component()->drivers);
            }, 'message' => 'Такой драйвер не установлен.'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'name' => 'Название',
            'driver' => 'Тип подключения',
            'is_active' => 'Активно',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'name' => 'Для себя, например «Яндекс 360 — info@skeeks.com».',
            'is_active' => 'Неактивное подключение не принимает и не отправляет письма.',
        ]);
    }

    /** Never expose the encrypted secrets through toArray() or the API. */
    public function fields()
    {
        $fields = parent::fields();
        unset($fields['secret']);

        return $fields;
    }

    public static function component(): CommunicationComponent
    {
        return \Yii::$app->get('communication');
    }

    public function getDriverTitle(): string
    {
        return static::component()->getDriversForSelect()[$this->driver] ?? (string)$this->driver;
    }

    public function getPublicConfig(): array
    {
        $config = $this->driver_config ? Json::decode($this->driver_config) : [];

        return is_array($config) ? $config : [];
    }

    public function hasSecret(): bool
    {
        return (string)$this->secret !== '';
    }

    /**
     * Driver with the stored settings. Secrets are decrypted only on request:
     * forms get a driver without them.
     */
    public function createDriver(bool $withSecrets = false): ConnectionDriver
    {
        $driver = static::component()->createDriver((string)$this->driver);
        $driver->setAttributes($this->getPublicConfig(), false);
        if ($withSecrets && $this->hasSecret()) {
            $driver->setAttributes($this->decryptSecrets(), false);
        }

        return $driver;
    }

    /**
     * Stores the driver's settings. Empty secret fields keep the stored values.
     */
    public function applyDriver(ConnectionDriver $driver): void
    {
        $this->driver_config = Json::encode($driver->getPublicConfig());

        $newSecrets = $driver->getSecretConfig();
        if (!$newSecrets) {
            return;
        }

        $secrets = [];
        if ($this->hasSecret()) {
            try {
                $secrets = $this->decryptSecrets();
            } catch (\RuntimeException $e) {
                // The old value cannot be read (key changed): the new input replaces it.
                $secrets = [];
            }
        }
        $secrets = array_intersect_key(array_merge($secrets, $newSecrets), array_flip($driver->secretAttributes()));
        $this->secret = static::component()->encryptSecret(Json::encode($secrets));
    }

    private function decryptSecrets(): array
    {
        $secrets = Json::decode(static::component()->decryptSecret((string)$this->secret));

        return is_array($secrets) ? $secrets : [];
    }

    public function getAccounts()
    {
        return $this->hasMany(CmsCommAccount::class, ['cms_comm_connection_id' => 'id']);
    }

    public function beforeDelete()
    {
        if (!parent::beforeDelete()) {
            return false;
        }
        if ($this->getAccounts()->exists()) {
            $this->addError('name', 'Подключение используют почтовые ящики. Сначала переключите или удалите их.');
            return false;
        }

        return true;
    }

    public function asText()
    {
        return (string)$this->name;
    }

    /** @return CmsActiveQuery */
    public static function find()
    {
        return new CmsActiveQuery(static::class);
    }
}
