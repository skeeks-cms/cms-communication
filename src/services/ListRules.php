<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommListMember;
use skeeks\cms\communication\models\CmsCommListRule;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\User;
use skeeks\cms\queryfilters\filters as FilterFields;
use skeeks\cms\queryfilters\filters\modes as Modes;
use skeeks\cms\queryfilters\QueryFiltersEvent;
use yii\base\Event;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;
use yii\helpers\Json;

/**
 * Rules of lists of recipients (stage 4D step 2): the filter of a CRM
 * section ("Компании", "Лиды", "Клиенты") saved from that section, counted
 * with the section's own code — the grid scope of its index action
 * (`forManager()`) and the "on apply" handlers of its filter fields — on
 * behalf of the employee who saved it, in a web request and in a job alike.
 * What the employee saw in the section is what the list takes; we keep no
 * filter builder of our own.
 *
 * - run() adds the records the filter finds now; a member removed by hand is
 *   never added back; records that stop matching stay in the list (an
 *   excluding list is the way to leave them out).
 * - Only the explicit fields of a section (with "on apply") are rules;
 *   automatic column filters are refused with a reason.
 */
class ListRules
{
    /** Pause between counts of one rule by the scheduled job, seconds. */
    public const JOB_INTERVAL = 3600;

    /** @return array[] section => controller (class), id (controller id in module cms), title, type (CrmRecords) */
    public static function sections(): array
    {
        return [
            'companies' => ['controller' => \skeeks\cms\controllers\AdminCmsCompanyController::class, 'id' => 'admin-cms-company',
                'title' => 'Компании', 'type' => CrmRecords::COMPANY, 'route' => '/cms/admin-cms-company'],
            'leads' => ['controller' => \skeeks\cms\controllers\AdminCmsLeadController::class, 'id' => 'admin-cms-lead',
                'title' => 'Сделки', 'type' => CrmRecords::LEAD, 'route' => '/cms/admin-cms-lead/index'],
            'clients' => ['controller' => \skeeks\cms\controllers\AdminUserController::class, 'id' => 'admin-user',
                'title' => 'Клиенты', 'type' => CrmRecords::CLIENT, 'route' => '/cms/admin-user'],
        ];
    }

    /**
     * Runs $fn with $user as the web user: a fresh user component (no
     * access cache of another employee), also in a console job that has no
     * user component at all; the previous component comes back afterwards.
     */
    public static function asUser(User $user, callable $fn)
    {
        $app = \Yii::$app;
        $previous = $app->has('user', true) ? $app->get('user') : ($app->has('user') ? $app->getComponents()['user'] : null);
        $app->set('user', [
            'class' => \yii\web\User::class,
            'identityClass' => CmsUser::class,
            'enableSession' => false,
            'enableAutoLogin' => false,
        ]);
        try {
            $app->user->setIdentity($user);

            return $fn();
        } finally {
            if ($previous === null) {
                $app->clear('user');
            } else {
                $app->set('user', $previous);
            }
        }
    }

    /** The index action config of the section's controller (its grid and filters). */
    private static function indexConfig(string $section): array
    {
        $config = self::sections()[$section] ?? null;
        if (!$config || !class_exists($config['controller'])) {
            throw new \DomainException('Раздел «'.$section.'» не установлен.');
        }
        $module = \Yii::$app->getModule('cms');
        $controller = \Yii::createObject($config['controller'], [$config['id'], $module]);
        $index = $controller->actions()['index'] ?? [];

        return ['controller' => $controller, 'index' => $index, 'modelClass' => $controller->modelClassName];
    }

    /**
     * Filter fields of the section a rule may keep: fields with their own
     * "on apply", and the fields with a mode ("равно", "содержит") the section
     * configures over a column — their class applies them (FilterField), as
     * the automatic filters of the page do.
     *
     * @return array field => config with its class
     */
    public static function fields(string $section): array
    {
        $config = self::indexConfig($section);
        /** @var \yii\db\ActiveRecord $modelClass */
        $modelClass = $config['modelClass'];
        $columns = $modelClass::getTableSchema()->columns;
        $model = new $modelClass();
        $result = [];
        foreach ((array)($config['index']['filters']['filtersModel']['fields'] ?? []) as $key => $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string)$key;
            $class = $field['class'] ?? null;
            if (!isset($field['on apply']) && $class === null && isset($columns[$key])) {
                // The class the automatic filter of the page gives this column.
                $field['class'] = in_array($columns[$key]->type, ['integer', 'bigint', 'smallint', 'tinyint', 'decimal'], true)
                    ? FilterFields\NumberFilterField::class : FilterFields\StringFilterField::class;
            }
            if (isset($field['on apply']) || (isset($field['class']) && is_a($field['class'], FilterFields\FilterField::class, true))) {
                $field['label'] = $field['label'] ?? $model->getAttributeLabel($key);
                $result[$key] = $field;
            }
        }

        return $result;
    }

    /** @return string[] the chosen values of a filter, whatever its shape */
    private static function chosen($value): array
    {
        if (!is_array($value)) {
            return trim((string)$value) !== '' ? [trim((string)$value)] : [];
        }
        unset($value['mode']);
        $flat = [];
        array_walk_recursive($value, static function ($v) use (&$flat) {
            if (trim((string)$v) !== '') {
                $flat[] = trim((string)$v);
            }
        });

        return $flat;
    }

    /** Values that mean "no filter" are dropped: empty strings, empty arrays, null. */
    public static function clean(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_array($value) && array_key_exists('mode', $value)) {
                // A field with a mode: no mode, or a mode that needs a value without one, is no filter.
                // Its value keeps the page shape: value[0] (a list for choices), value[1] (the end of a range).
                $mode = trim((string)$value['mode']);
                $parts = [];
                foreach ((array)($value['value'] ?? []) as $i => $part) {
                    $parts[$i] = is_array($part)
                        ? array_values(array_filter(array_map(static fn($v) => trim((string)$v), $part), static fn($v) => $v !== ''))
                        : trim((string)$part);
                }
                $chosen = self::chosen(['mode' => $mode, 'value' => $parts]);
                $value = $mode !== '' && ($chosen || in_array($mode, [Modes\FilterModeEmpty::ID, Modes\FilterModeNotEmpty::ID], true))
                    ? ['mode' => $mode, 'value' => $parts] : null;
            } elseif (is_array($value)) {
                $value = array_values(array_filter(array_map('strval', $value), static fn($v) => $v !== ''));
            } elseif ($value !== null) {
                $value = trim((string)$value);
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $result[(string)$key] = $value;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * The filter of the section page the employee looked at: its saved view
     * (`sx-bs`, a BackendShowing) under the values of the address, the way
     * the page combines them.
     *
     * @param string $queryString the query string of the section page
     * @return array field => value, cleaned
     */
    public static function valuesFromPage(string $queryString): array
    {
        parse_str(ltrim($queryString, '?'), $get);
        $values = [];
        if (!empty($get['sx-bs']) && class_exists(\skeeks\cms\backend\models\BackendShowing::class)) {
            $showing = \skeeks\cms\backend\models\BackendShowing::findOne((int)$get['sx-bs']);
            // The model decodes the column itself; a raw string is decoded here.
            $config = $showing ? $showing->config_jsoned : [];
            $config = is_string($config) ? (Json::decode($config) ?: []) : (array)$config;
            foreach ((array)$config as $widget => $byKey) {
                if (is_array($byKey) && strpos((string)$widget, 'FiltersWidget') !== false) {
                    foreach ($byKey as $settings) {
                        $values = array_merge($values, (array)($settings['filterValues'] ?? []));
                    }
                }
            }
        }
        // The form of the section filters is "f<widget id>" (findex): the values in the address win.
        foreach ($get as $key => $value) {
            if (is_array($value) && preg_match('/^f[a-z0-9_-]+$/i', (string)$key)) {
                $values = array_merge($values, $value);
            }
        }

        return self::clean($values);
    }

    /**
     * @return string[] field => why it cannot be a rule; empty when every value is a field of the section
     */
    public static function errors(string $section, array $values): array
    {
        if (!isset(self::sections()[$section])) {
            return ['section' => 'Правило можно сохранить только из разделов «Компании», «Сделки», «Клиенты».'];
        }
        $values = self::clean($values);
        if (!$values) {
            return ['values' => 'В разделе не выбран ни один фильтр: такое правило взяло бы все записи.'];
        }
        $fields = self::fields($section);
        $errors = [];
        foreach (array_keys($values) as $key) {
            if (!isset($fields[$key])) {
                $errors[$key] = 'Фильтр по полю «'.$key.'» нельзя сохранить как правило: используйте поиск, «Готовый фильтр» и списки выбора раздела.';
                continue;
            }
            // A value of a choice list must still be one of its options: an unknown one is ignored by the section.
            $items = $fields[$key]['items'] ?? ($fields[$key]['field']['items'] ?? null);
            if (is_array($items) && ($missing = array_diff(self::chosen($values[$key]), array_map('strval', array_keys($items))))) {
                $errors[$key] = 'В фильтре «'.(string)($fields[$key]['label'] ?? $key).'» больше нет варианта «'.implode('», «', $missing).'».';
            }
        }

        return $errors;
    }

    /**
     * The query of the section with these filters, as the employee sees the
     * section: call inside asUser().
     */
    public static function query(string $section, array $values): ActiveQuery
    {
        $config = self::indexConfig($section);
        $index = $config['index'];
        /** @var \yii\db\ActiveRecord $modelClass */
        $modelClass = $config['modelClass'];
        $dataProvider = new ActiveDataProvider(['query' => $modelClass::find()]);
        // The grid scope of the section: its "on init" sees our data provider as the grid's.
        if (isset($index['grid']['on init']) && is_callable($index['grid']['on init'])) {
            $grid = new \yii\base\Component();
            $grid->attachBehavior('dataProvider', new class extends \yii\base\Behavior {
                public $dataProvider;
            });
            $grid->dataProvider = $dataProvider;
            call_user_func($index['grid']['on init'], new Event(['sender' => $grid]));
        }

        $values = self::clean($values);
        $filtersModel = (array)($index['filters']['filtersModel'] ?? []);
        $filtersModel['class'] = \skeeks\yii2\config\DynamicConfigModel::class;
        $filtersModel['formName'] = 'findex';
        // Fields over columns are attributes of the page through its automatic filters: define them here.
        $filtersModel['attributeDefines'] = array_values(array_unique(array_merge((array)($filtersModel['attributeDefines'] ?? []), array_keys($values))));
        /** @var \skeeks\yii2\config\DynamicConfigModel $model */
        $model = \Yii::createObject($filtersModel);
        $model->setAttributes($values, false);
        $model->setFields(array_intersect_key(self::fields($section), $values));
        // The fields apply themselves exactly as on the page (QueryFiltersWidget::run()).
        $builder = new \skeeks\yii2\form\Builder(['models' => $model->builderModels(), 'model' => $model, 'fields' => $model->builderFields()]);
        foreach ($builder->fields as $field) {
            $field->trigger('apply', new QueryFiltersEvent(['field' => $field, 'dataProvider' => $dataProvider, 'query' => $dataProvider->query]));
        }

        return $dataProvider->query;
    }

    /** @return int[] ids the filter finds for the employee */
    public static function ids(string $section, array $values, User $user): array
    {
        return self::asUser($user, static function () use ($section, $values) {
            $query = self::query($section, $values);
            /** @var \yii\db\ActiveRecord $class */
            $class = $query->modelClass;
            $id = $class::tableName().'.id';
            // HAVING filters of the section ("Есть неоплаченные счета") need the columns they selected.
            if ($query->having) {
                $ids = [];
                foreach ($query->addSelect(['sx_rule_record_id' => $id])->orderBy([$id => SORT_ASC])->asArray()->each(500) as $row) {
                    $ids[(int)$row['sx_rule_record_id']] = true;
                }

                return array_keys($ids);
            }

            return array_map('intval', $query->select([$id])->distinct()->orderBy([$id => SORT_ASC])->column());
        });
    }

    /** "Компании: Категория — Магазин керамической плитки; Готовый фильтр — Нет оплат". */
    public static function describe(string $section, array $values): string
    {
        $fields = self::fields($section);
        $parts = [];
        foreach (self::clean($values) as $key => $value) {
            $field = $fields[$key] ?? [];
            $label = (string)($field['label'] ?? $key);
            $items = $field['items'] ?? ($field['field']['items'] ?? null);
            $names = [];
            if (is_array($value) && array_key_exists('mode', $value) && !$value['value']) {
                $names[] = $value['mode'] === Modes\FilterModeEmpty::ID ? 'не заполнено' : 'заполнено';
            }
            foreach (self::chosen($value) as $item) {
                $name = null;
                if (is_array($items)) {
                    $name = $items[$item] ?? null;
                } elseif (!empty($field['widgetConfig']['modelClass']) && class_exists($field['widgetConfig']['modelClass'])) {
                    $record = $field['widgetConfig']['modelClass']::findOne($item);
                    $name = $record ? (method_exists($record, 'asText') ? $record->asText() : ($record->name ?? null)) : null;
                }
                $names[] = (string)($name ?? $item);
            }
            $parts[] = $label.' — '.implode(', ', $names);
        }

        return (self::sections()[$section]['title'] ?? $section).': '.implode('; ', $parts);
    }

    /**
     * Saves the filter of a section as a rule of the list (the same filter
     * twice is one rule) and counts it at once.
     *
     * @throws \DomainException when the filter cannot be a rule
     */
    public function create(CmsCommList $list, string $section, array $values, User $user): CmsCommListRule
    {
        $values = self::clean($values);
        if ($errors = self::errors($section, $values)) {
            throw new \DomainException(implode(' ', $errors));
        }
        $json = Json::encode($values);
        $rule = CmsCommListRule::find()->andWhere(['cms_comm_list_id' => (int)$list->id, 'section' => $section, 'filter_values' => $json])->one()
            ?: new CmsCommListRule(['cms_comm_list_id' => (int)$list->id, 'section' => $section, 'filter_values' => $json]);
        $rule->title = mb_substr(self::describe($section, $values), 0, 1000);
        $rule->user_id = (int)$user->id;
        $rule->is_active = 1;
        $rule->save(false);
        $this->run($rule);

        return $rule;
    }

    /**
     * Counts the rule and adds what is new. Errors stay on the rule.
     *
     * @param callable|null $ids fn(CmsCommListRule): int[] instead of the section query (tests)
     * @return int records added
     */
    public function run(CmsCommListRule $rule, ?callable $ids = null): int
    {
        $list = $rule->list;
        $user = $rule->user_id ? CmsUser::findOne((int)$rule->user_id) : null;
        $now = time();
        try {
            if (!$list) {
                throw new \DomainException('Список удалён.');
            }
            if (!$user || !$user->is_active) {
                throw new \DomainException('Сотрудник, сохранивший правило, отключён: сохраните правило заново.');
            }
            // A filter of the section renamed or an option removed must not widen the rule silently: it stops instead.
            if (!$ids && ($errors = self::errors((string)$rule->section, $rule->getValues()))) {
                throw new \DomainException('Фильтры раздела изменились, правило остановлено: '.implode(' ', $errors)
                    .' Поставьте фильтр в разделе заново и сохраните правило.');
            }
            $found = $ids ? array_map('intval', $ids($rule)) : self::ids((string)$rule->section, $rule->getValues(), $user);
            $added = (new RecipientLists())->addFound($list, (string)self::sections()[$rule->section]['type'], $found, $user, 'rule:'.(int)$rule->id);
            CmsCommListRule::updateAll(['last_run_at' => $now, 'last_found' => count($found), 'last_added' => $added,
                'added_total' => (int)$rule->added_total + $added, 'last_error' => null, 'updated_at' => $now], ['id' => (int)$rule->id]);
            $rule->refresh();

            return $added;
        } catch (\Throwable $e) {
            if (!$e instanceof \DomainException) {
                \Yii::error('Правило списка #'.(int)$rule->id.': '.$e->getMessage()."\n".$e->getTraceAsString(), self::class);
            }
            CmsCommListRule::updateAll(['last_run_at' => $now, 'last_added' => 0, 'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'updated_at' => $now], ['id' => (int)$rule->id]);
            $rule->refresh();

            return 0;
        }
    }

    /**
     * Counts the active rules of lists not counted for $maxAge seconds:
     * before a campaign is shown or launched, in the scheduled job.
     *
     * @param int[]|null $listIds null — every list
     * @return int records added
     */
    public function runDue(?array $listIds, int $maxAge): int
    {
        $query = CmsCommListRule::find()->andWhere(['is_active' => 1])
            ->andWhere(['or', ['last_run_at' => null], ['<', 'last_run_at', time() - $maxAge]])->orderBy(['id' => SORT_ASC]);
        if ($listIds !== null) {
            $query->andWhere(['cms_comm_list_id' => $listIds ?: [0]]);
        }
        $added = 0;
        foreach ($query->all() as $rule) {
            $added += $this->run($rule);
        }

        return $added;
    }
}
