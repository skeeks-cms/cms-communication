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
 * A topic of advertising mailings of the site ("Акции и скидки"). Every
 * campaign has one; clients opt out of topics on the opt-out page, consent
 * is kept per contact and topic (CmsCommContactTopic). Service messages
 * have no topic and are never blocked by an opt-out.
 *
 * @property int         $id
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_site_id
 * @property string      $name
 * @property string|null $description shown to the client on the opt-out page
 * @property int         $is_active
 * @property int         $priority
 */
class CmsCommTopic extends ActiveRecord
{
    /** Topics offered by "Добавить начальные темы" (decision of the owner, 23.10.2026). */
    public const DEFAULTS = [
        ['name' => 'Новые решения и услуги', 'description' => 'Рассказываем о новых готовых решениях и услугах, которые могут пригодиться вашему бизнесу.'],
        ['name' => 'Акции и скидки', 'description' => 'Специальные предложения и скидки на наши услуги.'],
        ['name' => 'Новости студии', 'description' => 'Что нового у нас: выполненные проекты, команда, события.'],
        ['name' => 'Новости платформы', 'description' => 'Обновления платформы, на которой работает ваш сайт: новые возможности и улучшения.'],
    ];

    public static function tableName()
    {
        return '{{%cms_comm_topic}}';
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['name'], 'filter', 'filter' => static fn($v) => trim(preg_replace('/\s+/u', ' ', (string)$v))],
            [['description'], 'filter', 'filter' => static fn($v) => trim((string)$v) === '' ? null : trim((string)$v)],
            [['cms_site_id'], 'default', 'value' => static fn() => \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : null],
            [['is_active'], 'default', 'value' => 1],
            [['priority'], 'default', 'value' => 100],
            [['cms_site_id', 'name'], 'required'],
            [['cms_site_id', 'priority'], 'integer'],
            [['is_active'], 'boolean'],
            [['name'], 'string', 'max' => 255],
            [['description'], 'string', 'max' => 2000],
            [['name'], 'unique', 'targetAttribute' => ['cms_site_id', 'name'], 'message' => 'Такая тема уже есть.'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'name' => 'Название',
            'description' => 'Описание для клиента',
            'is_active' => 'Активна',
            'priority' => 'Сортировка',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'description' => 'Клиент видит его на странице отписки рядом с названием темы.',
            'is_active' => 'Неактивная тема не предлагается для новых рассылок и не показывается на странице отписки; согласия и отписки по ней сохраняются.',
        ]);
    }

    /** @return self[] topics of the site in their order */
    public static function forSite(int $siteId, bool $activeOnly = false): array
    {
        $query = self::find()->andWhere(['cms_site_id' => $siteId])->orderBy(['priority' => SORT_ASC, 'name' => SORT_ASC]);
        if ($activeOnly) {
            $query->andWhere(['is_active' => 1]);
        }

        return $query->all();
    }

    /**
     * "Добавить начальные темы": the topics of DEFAULTS the site does not
     * have yet. Safe to repeat.
     *
     * @return int how many were added
     */
    public static function addDefaults(int $siteId): int
    {
        $added = 0;
        $priority = 0;
        foreach (self::DEFAULTS as $default) {
            $priority += 10;
            if (self::find()->andWhere(['cms_site_id' => $siteId, 'name' => $default['name']])->exists()) {
                continue;
            }
            $topic = new self($default + ['cms_site_id' => $siteId, 'is_active' => 1, 'priority' => $priority]);
            if ($topic->save()) {
                $added++;
            }
        }

        return $added;
    }

    public function asText()
    {
        return (string)$this->name;
    }
}
