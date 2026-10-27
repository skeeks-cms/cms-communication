<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\forms;

use skeeks\cms\communication\models\CmsCommList;
use skeeks\cms\communication\models\CmsCommListMember;
use skeeks\cms\communication\services\ListRules;
use skeeks\cms\communication\services\RecipientLists;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\HtmlBlock;
use skeeks\yii2\form\fields\SelectField;
use skeeks\yii2\form\fields\TextField;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\helpers\Html;

/**
 * "Добавить в список рассылки": the chosen list or a new one. The form of the
 * bulk action of the CRM sections "Компании", "Лиды", "Клиенты" (the
 * standard BackendModelMultiWindowAction over the section's own filters,
 * marked rows or "для всех") and of the window of a card.
 */
class ListAddForm extends Model
{
    public const NEW = 'new';

    /** A list id or NEW. */
    public $list = '';
    public $newName = '';
    /** "Пополнять по этому фильтру": the filter of the section page becomes a rule of the list (ListRules). */
    public $autoRule = 0;
    /** The query string of the section page the window was opened from (its filter), set by the window script. */
    public $source = '';

    /** The section of the bulk action: companies, leads, clients; null — a card. */
    public ?string $section = null;

    public int $added = 0;
    public int $skipped = 0;

    private ?CmsCommList $_list = null;
    private bool $_ruled = false;

    public function rules()
    {
        return [
            [['newName'], 'filter', 'filter' => static fn($v) => trim((string)$v)],
            [['list'], 'required', 'message' => 'Выберите список.'],
            [['newName'], 'string', 'max' => 255],
            [['newName'], 'required', 'when' => static fn(self $m) => (string)$m->list === self::NEW,
                'whenClient' => "function () { return $('#listaddform-list').val() === '".self::NEW."'; }",
                'message' => 'Напишите название нового списка.'],
            [['autoRule'], 'boolean'],
            [['source'], 'string', 'max' => 8000],
            [['autoRule'], function ($attribute) {
                if (!$this->autoRule) {
                    return;
                }
                if (!$this->section) {
                    $this->addError($attribute, 'Правило сохраняется только из разделов «Компании», «Сделки», «Клиенты».');
                } elseif ($errors = ListRules::errors($this->section, ListRules::valuesFromPage((string)$this->source))) {
                    $this->addError($attribute, implode(' ', $errors));
                }
            }],
            [['list'], function ($attribute) {
                if ((string)$this->list !== self::NEW && !isset(CmsCommList::forSite(self::siteId())[(int)$this->list])) {
                    $this->addError($attribute, 'Список не найден.');
                }
            }],
        ];
    }

    public function attributeLabels()
    {
        return ['list' => 'Список рассылки', 'newName' => 'Название нового списка', 'autoRule' => 'Пополнять по этому фильтру'];
    }

    public function formName()
    {
        return 'ListAddForm';
    }

    private static function siteId(): int
    {
        return \Yii::$app->skeeks->site ? (int)\Yii::$app->skeeks->site->id : 0;
    }

    /** @return string[] value => title: the lists of the site and "+ Новый список" */
    public static function choices(): array
    {
        $result = [];
        foreach (CmsCommList::forSite(self::siteId()) as $id => $name) {
            $result[(string)$id] = $name;
        }

        return $result + [self::NEW => '+ Новый список'];
    }

    /** Fields of the bulk action window (BackendModelMultiWindowAction::$fields). */
    public static function formFields($action = null): array
    {
        \Yii::$app->view->registerJs(<<<JS
(function () {
    function toggle() { $('.field-listaddform-newname').toggle($('#listaddform-list').val() === 'new'); }
    $(document).on('change', '#listaddform-list', toggle);
    $(toggle);
    setTimeout(toggle, 50);
    // The filter of the section page under the window: the rule keeps it.
    try { $('#listaddform-source').val(window.parent.location.search || ''); } catch (e) {}
})();
JS);

        return [
            'hint' => ['class' => HtmlBlock::class, 'content' => Html::tag('p',
                'Участник списка — сама запись CRM: адрес выбирается при отправке, отписанные остаются в списке и исключаются при рассылке. '
                .'Записи, которые уже в списке, не повторятся.', ['class' => 'form-group'])],
            'list' => ['class' => SelectField::class, 'items' => self::choices(), 'allowNull' => false],
            'newName' => ['class' => TextField::class],
            'autoRule' => ['class' => BoolField::class, 'allowNull' => false, 'hint' => 'Фильтр раздела, который сейчас стоит на странице, сохранится у списка: '
                .'сразу добавятся все найденные записи, а новые будут добавляться сами — раз в час и перед каждой кампанией по этому списку. '
                .'Кого вы убрали из списка вручную, правило не вернёт.'],
            // A bare hidden input: a field would show its label.
            'source' => ['class' => HtmlBlock::class, 'content' => Html::hiddenInput('ListAddForm[source]', '', ['id' => 'listaddform-source'])],
        ];
    }

    /** The chosen list; a new one is created once, on the first record. */
    public function resolve(\skeeks\cms\models\User $user): CmsCommList
    {
        if ($this->_list) {
            return $this->_list;
        }
        if ((string)$this->list === self::NEW) {
            return $this->_list = (new RecipientLists())->create(self::siteId(), (string)$this->newName, $user);
        }
        $list = CmsCommList::find()->andWhere(['id' => (int)$this->list, 'cms_site_id' => self::siteId()])->one();
        if (!$list) {
            throw new \DomainException('Список не найден.');
        }

        return $this->_list = $list;
    }

    /**
     * applyCallback of the bulk action: the record joins the list; an
     * employee in "Клиенты" or a record the list does not take is skipped.
     * The records come from the section's own scoped query.
     */
    public function apply(ActiveRecord $record): bool
    {
        $user = \Yii::$app->user->identity;
        if (!RecipientLists::recordType($record)) {
            $this->skipped++;

            return true;
        }
        $list = $this->resolve($user);
        if ($this->autoRule && !$this->_ruled) {
            // Once per window: the rule counts the whole filter now, the marked rows are added below as well.
            $this->_ruled = true;
            (new ListRules())->create($list, (string)$this->section, ListRules::valuesFromPage((string)$this->source), $user);
        }
        (new RecipientLists())->add($list, $record, $user, CmsCommListMember::VIA_SECTION, $this->section, false);
        $this->added++;

        return true;
    }
}
