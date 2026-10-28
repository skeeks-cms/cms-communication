<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\models;

use skeeks\cms\base\ActiveRecord;
use skeeks\cms\models\CmsUser;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;

/**
 * An import of mailing recipients into leads: the uploaded table (its rows
 * are CmsCommImportRow), the columns chosen for the fields, the responsible
 * employee, the topic and the consent basis for the whole file, the report.
 * The steps and the run are services\LeadImport; large files go to the job
 * comm.import.leads.
 *
 * @property int         $id
 * @property int|null    $created_by
 * @property int|null    $updated_by
 * @property int|null    $created_at
 * @property int|null    $updated_at
 * @property int         $cms_site_id
 * @property string|null $file_name
 * @property string      $mode MODE_AUDIENCE — a base into a list, without deals (services\AudienceImport); MODE_LEADS — requests into deals
 * @property int         $excluded_count rows of an audience import left out by the rules
 * @property string      $status
 * @property string|null $columns JSON: header titles of the table
 * @property string|null $mapping JSON: column number => field
 * @property int|null    $executor_id
 * @property int|null    $cms_comm_topic_id
 * @property string|null $consent_basis
 * @property int|null    $cms_comm_list_id the list of recipients the import fills (stage 4D)
 * @property int         $row_count
 * @property int         $created_count
 * @property int         $duplicate_count
 * @property int         $skipped_count
 * @property int|null    $cms_job_run_id
 * @property string|null $error
 * @property int|null    $finished_at
 *
 * @property CmsCommImportRow[] $rows
 * @property CmsCommTopic|null  $topic
 * @property CmsUser|null       $executor
 * @property CmsUser|null       $createdBy
 * @property CmsCommList|null   $list
 */
class CmsCommImport extends ActiveRecord
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    /** A base for mailings: companies and contacts of a list, no deals (services\AudienceImport). */
    public const MODE_AUDIENCE = 'audience';
    /** Real requests: a deal in «Неразобранное» or an addition to an open one (services\LeadImport). */
    public const MODE_LEADS = 'leads';

    /** The choice of the form before the import starts: a list id, "new" (made at the start) or "" — no list. */
    public $listChoice;

    public static function tableName()
    {
        return '{{%cms_comm_import}}';
    }

    public function getList()
    {
        return $this->hasOne(CmsCommList::class, ['id' => 'cms_comm_list_id']);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Не запущен',
            self::STATUS_QUEUED => 'В очереди',
            self::STATUS_RUNNING => 'Идёт',
            self::STATUS_DONE => 'Готово',
            self::STATUS_FAILED => 'Ошибка',
        ];
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'id' => '№',
            'file_name' => 'Файл',
            'status' => 'Состояние',
            'executor_id' => 'Ответственный',
            'cms_comm_topic_id' => 'Тема рассылки',
            'consent_basis' => 'Основание согласия',
            'row_count' => 'Строк',
            'created_count' => 'Создано сделок',
            'duplicate_count' => 'Повторов',
            'skipped_count' => 'Пропущено',
            'excluded_count' => 'Исключено',
            'mode' => 'Что загружаем',
            'created_at' => 'Загружен',
            'created_by' => 'Загрузил',
        ]);
    }

    /** @return string[] header titles of the table */
    public function getColumnTitles(): array
    {
        return $this->columns ? (array)Json::decode($this->columns) : [];
    }

    /** @return array column number => field (services\LeadImport::fields()) */
    public function getMappingArray(): array
    {
        $mapping = $this->mapping ? (array)Json::decode($this->mapping) : [];
        $result = [];
        foreach ($mapping as $column => $field) {
            if (is_string($field) && $field !== '') {
                $result[(int)$column] = $field;
            }
        }

        return $result;
    }

    public static function modes(): array
    {
        return [
            self::MODE_AUDIENCE => 'База в список рассылки',
            self::MODE_LEADS => 'Заявки в сделки',
        ];
    }

    public function isAudience(): bool
    {
        return self::audienceReady() && $this->mode === self::MODE_AUDIENCE;
    }

    /**
     * The audience schema is migrated (cms_comm_import.mode, cms_comm_audience):
     * the code may be deployed before the migrations, then every import stays a
     * leads one and the audience actions are off.
     */
    public static function audienceReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $db = static::getDb();
            $ready = static::getTableSchema()->getColumn('mode') !== null
                && $db->getTableSchema(CmsCommAudience::tableName(), true) !== null;
        }

        return $ready;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }

    public function getStatusName(): string
    {
        return self::statuses()[$this->status] ?? (string)$this->status;
    }

    public function getRows()
    {
        return $this->hasMany(CmsCommImportRow::class, ['cms_comm_import_id' => 'id'])->orderBy(['row_num' => SORT_ASC]);
    }

    public function getTopic()
    {
        return $this->hasOne(CmsCommTopic::class, ['id' => 'cms_comm_topic_id']);
    }

    public function getExecutor()
    {
        return $this->hasOne(CmsUser::class, ['id' => 'executor_id']);
    }

    public function asText()
    {
        return ($this->isAudience() ? 'Импорт в список №' : 'Импорт №').$this->id.($this->file_name ? ' — '.$this->file_name : '');
    }
}
