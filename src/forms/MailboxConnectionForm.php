<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\forms;

use skeeks\cms\communication\models\CmsCommConnection;
use yii\base\Model;

/**
 * How a mailbox reaches its server: its own login (the usual case) or the
 * login of another mailbox (several addresses delivered into one account).
 */
class MailboxConnectionForm extends Model
{
    public const MODE_OWN = 'own';
    public const MODE_SHARED = 'shared';

    public $mode = self::MODE_OWN;
    public $shared_connection_id;

    /** @var int[] connections that may be shared */
    public array $sharedIds = [];

    public static function modes(): array
    {
        return [
            self::MODE_OWN => 'Свой логин и пароль',
            self::MODE_SHARED => 'Письма приходят в другой ящик (алиас, пересылка)',
        ];
    }

    public function rules()
    {
        return [
            [['mode'], 'in', 'range' => array_keys(self::modes())],
            [['shared_connection_id'], 'integer'],
            [['shared_connection_id'], 'required', 'when' => fn() => $this->mode === self::MODE_SHARED,
                'whenClient' => 'function () { return false; }', 'message' => 'Выберите ящик, в который приходят письма.'],
            [['shared_connection_id'], 'in', 'range' => fn() => $this->sharedIds, 'when' => fn() => $this->mode === self::MODE_SHARED,
                'message' => 'Подключение не найдено.'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'mode' => 'Подключение к серверу',
            'shared_connection_id' => 'Через логин ящика',
        ];
    }

    public function attributeHints()
    {
        return [
            'shared_connection_id' => 'Письма на этот адрес забираются через логин выбранного ящика и раскладываются по адресу получателя.',
        ];
    }

    /** Connections other mailboxes may use, title => id. */
    public static function sharedOptions(int $siteId, ?int $exceptConnectionId): array
    {
        $query = CmsCommConnection::find()->andWhere(['cms_site_id' => $siteId])->orderBy(['name' => SORT_ASC]);
        if ($exceptConnectionId) {
            $query->andWhere(['!=', 'id', $exceptConnectionId]);
        }
        $options = [];
        foreach ($query->all() as $connection) {
            $options[(int)$connection->id] = $connection->name;
        }

        return $options;
    }
}
