<?php
/**
 * Sorting letters by hand on an isolated SQLite memory database: link
 * strength (automatic linking never weakens a manual or "created" link),
 * unlinking with its feed entry, records unlinked from a conversation that
 * automatic linking does not bring back, list filters by the responsible
 * person and CRM links, CRM record types, the letter's own text without
 * quotes. No site schema, no network.
 *
 * Usage: php tests/message-sorting.php [vendor/autoload.php]
 */

$autoloadCandidates = array_filter([$argv[1] ?? null, dirname(__DIR__, 3).'/autoload.php', '/app/vendor/autoload.php']);
foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        require_once dirname($autoload).'/yiisoft/yii2/Yii.php';
        break;
    }
}
Yii::setAlias('@skeeks/cms/communication', dirname(__DIR__).'/src');

use skeeks\cms\communication\helpers\MailText;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\communication\services\MessageLinker;
use skeeks\cms\communication\services\MessageRecordFactory;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;

function sortExpect($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** A CRM record without the CRM schema: the linker reads only its code, id and name. */
class SortTestCompany extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'test_company';
    }
    public function getSkeeksModelCode()
    {
        return CmsCompany::class;
    }
    public function asText()
    {
        return 'ООО «Ромашка»';
    }
}

new \yii\console\Application([
    'id' => 'cms-communication-sorting',
    'basePath' => sys_get_temp_dir(),
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'skeeks' => ['class' => \skeeks\cms\Skeeks::class],
    ],
]);

$db = Yii::$app->db;
$db->createCommand()->createTable('cms_comm_conversation', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'channel' => 'string', 'thread_key' => 'string', 'subject' => 'string', 'last_message_at' => 'integer', 'assigned_user_id' => 'integer', 'assigned_at' => 'integer', 'assigned_by' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_message', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer', 'cms_comm_account_id' => 'integer', 'channel' => 'string', 'direction' => 'string', 'status' => 'string', 'external_id' => 'string', 'from_address' => 'string', 'from_name' => 'string', 'to_list' => 'text', 'subject' => 'string', 'body_text' => 'text', 'body_html' => 'text', 'is_internal' => 'integer default 0', 'is_history' => 'integer default 0', 'message_at' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_link', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_message_id' => 'integer', 'model_code' => 'string', 'model_id' => 'integer', 'link_type' => 'string', 'cms_log_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_log', ['id' => 'pk', 'created_by' => 'integer', 'created_at' => 'integer', 'updated_by' => 'integer', 'updated_at' => 'integer', 'cms_company_id' => 'integer', 'cms_user_id' => 'integer', 'log_type' => 'string', 'comment' => 'text', 'model_code' => 'string', 'model_id' => 'integer', 'model_as_text' => 'string', 'data' => 'text', 'sub_model_code' => 'string', 'sub_model_id' => 'integer', 'sub_model_log_type' => 'string', 'sub_model_as_text' => 'string', 'is_pinned' => 'integer default 0'])->execute();
$db->createCommand()->createTable('cms_comm_link_exclusion', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_conversation_id' => 'integer', 'model_code' => 'string', 'model_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_log_file', ['id' => 'pk', 'cms_log_id' => 'integer', 'storage_file_id' => 'integer', 'priority' => 'integer'])->execute();
$db->createCommand()->createTable('cms_storage_file', ['id' => 'pk', 'priority' => 'integer'])->execute();

$db->createCommand()->batchInsert('cms_comm_conversation', ['id', 'cms_site_id', 'channel', 'thread_key', 'assigned_user_id'], [[1, 1, 'email', 'a', 5], [2, 1, 'email', 'b', null]])->execute();
$db->createCommand()->batchInsert('cms_comm_message', ['id', 'cms_site_id', 'cms_comm_conversation_id', 'channel', 'direction', 'status', 'external_id', 'from_address', 'subject', 'message_at'], [
    [1, 1, 1, 'email', 'in', 'received', 'm1', 'client@example.com', 'Заказ', 1000],
    [2, 1, 1, 'email', 'out', 'sent', 'm2', 'info@skeeks.com', 'Re: Заказ', 2000],
    [3, 1, 2, 'email', 'in', 'received', 'm3', 'other@example.com', 'Вопрос', 3000],
])->execute();

$db->createCommand()->createTable('test_company', ['id' => 'pk'])->execute();
$db->createCommand()->insert('test_company', ['id' => 15])->execute();
$linker = new MessageLinker();
$company = SortTestCompany::findOne(15);
$codeCompany = CmsCompany::class;
$m1 = CmsCommMessage::findOne(1);
$m2 = CmsCommMessage::findOne(2);
$m3 = CmsCommMessage::findOne(3);
$link = static fn(int $messageId) => CmsCommLink::find()->andWhere(['cms_comm_message_id' => $messageId, 'model_code' => $codeCompany, 'model_id' => 15])->one();

// A manual link is not weakened by automatic linking, and is written into the feed like an automatic one.
$linker->link($m1, [$company], CmsCommLink::TYPE_MANUAL, 1);
$linker->link($m1, [$company], CmsCommLink::TYPE_AUTO);
$row = $link(1);
sortExpect($row && $row->link_type === CmsCommLink::TYPE_MANUAL && (int)$row->created_by === 1, 'Automatic linking must not replace a manual link.');
$log = CmsLog::findOne((int)$row->cms_log_id);
sortExpect($log && $log->log_type === CmsCommMessage::LOG_TYPE && (int)$log->cms_company_id === 15 && (int)($log->data['id'] ?? 0) === 1
    && (int)$log->created_at === 1000, 'A manual link must write the same feed entry as an automatic one (letter date, company).');
sortExpect((int)CmsCommLink::find()->count() === 1 && (int)CmsLog::find()->count() === 1, 'Linking twice must not duplicate the link or the feed entry.');

// An automatic link becomes "created" when a record is created from the letter.
$linker->link($m2, [$company], CmsCommLink::TYPE_AUTO);
$linker->link($m2, [$company], CmsCommLink::TYPE_CREATED, 1);
sortExpect($link(2)->link_type === CmsCommLink::TYPE_CREATED, 'A stronger link type must replace an automatic one.');

// Unlinking removes the link and its feed entry.
$logId = (int)$link(2)->cms_log_id;
sortExpect($linker->unlink($m2, $codeCompany, 15) && !$link(2) && !CmsLog::findOne($logId), 'Unlinking must remove the link and its feed entry.');
sortExpect(!$linker->unlink($m2, $codeCompany, 15), 'Unlinking a letter that is not linked must report nothing done.');

// A record unlinked from the whole conversation is not linked automatically again, only by hand.
$linker->exclude(1, $codeCompany, 15, 1);
$linker->exclude(1, $codeCompany, 15, 1);
sortExpect((int)(new \yii\db\Query())->from('cms_comm_link_exclusion')->count() === 1, 'Excluding twice must keep one row.');
foreach ([CmsCommLink::TYPE_AUTO, CmsCommLink::TYPE_THREAD, CmsCommLink::TYPE_CONTEXT] as $type) {
    $linker->link($m2, [$company], $type);
    sortExpect(!$link(2), 'An excluded record must not come back by '.$type.' linking.');
}
$linker->inheritFromConversation($m2);
sortExpect(!$link(2), 'An excluded record must not come back from earlier letters of the conversation.');
$linker->link($m3, [$company], CmsCommLink::TYPE_AUTO);
sortExpect((bool)$link(3), 'An exclusion must stay inside its conversation.');
$linker->link($m2, [$company], CmsCommLink::TYPE_MANUAL, 1);
sortExpect($link(2) && $link(2)->link_type === CmsCommLink::TYPE_MANUAL, 'A manual link must still be possible after an exclusion.');
$linker->include(1, $codeCompany, 15);
sortExpect(!$linker->isExcluded(1, $codeCompany, 15), 'Linking by hand again must remove the exclusion.');

// List filters: the responsible person and CRM links.
$ids = static fn($query) => array_map('intval', $query->select(CmsCommMessage::tableName().'.id')->orderBy(['id' => SORT_ASC])->column());
sortExpect($ids(CmsCommMessage::find()->assignedTo(5)) === [1, 2], '"Мне назначено" must give the letters of conversations assigned to the employee.');
sortExpect($ids(CmsCommMessage::find()->assignedTo(null)) === [3], '"Без ответственного" must give letters of unassigned conversations.');
$db->createCommand()->delete('cms_comm_link', ['cms_comm_message_id' => 3])->execute();
sortExpect($ids(CmsCommMessage::find()->linked(true)) === [1, 2] && $ids(CmsCommMessage::find()->linked(false)) === [3],
    '"Привязано к CRM" and "Не разобрано" must split letters by their links.');

// CRM record types of the mail.
sortExpect(CrmRecords::typeOfCode(CmsDeal::class) === CrmRecords::DEAL && CrmRecords::typeOfCode(CmsUser::class) === CrmRecords::CLIENT
    && CrmRecords::typeOfCode('skeeks\cms\models\CmsProject') === null, 'Link codes must map to the record types the mail knows.');
sortExpect(!in_array(CrmRecords::TASK, CrmRecords::LINKABLE, true) && CrmRecords::CREATABLE === [CrmRecords::TASK, CrmRecords::LEAD, CrmRecords::DEAL],
    'Tasks are created from letters, not linked by hand.');
$factory = new MessageRecordFactory();
sortExpect($factory->supports(CmsTask::class) && $factory->supports(CmsLead::class) && $factory->supports(CmsDeal::class)
    && !$factory->supports(CmsCompany::class) && !$factory->supports(\yii\base\Model::class), 'Only tasks, leads and deals are created from letters.');

// The letter's own text: quotes and their introductions go.
$text = "Добрый день!\nНужен счёт.\n\n22.10.2026, 12:00, Иван <ivan@example.com>:\n> старое письмо\n> ещё";
sortExpect(MailText::stripQuotedText($text) === "Добрый день!\nНужен счёт.", 'A reply must lose the quote under it.');
$bottom = "Срд 12 Авг 2026 17:15:03, sem@example.com писал:\n> вопрос\n> ещё\n\nОтвет под цитатой.";
sortExpect(MailText::stripQuotedText($bottom) === 'Ответ под цитатой.', 'A reply under the quote must keep its own text.');
sortExpect(MailText::stripQuotedText("Текст\n\nOn Tue, 1 Oct 2026 John wrote:\n> x") === 'Текст', 'English quote introductions must cut the text.');
$html = '<div>Спасибо!</div><blockquote><div>старое <blockquote>совсем старое</blockquote></div></blockquote><div class="gmail_quote">gmail</div>';
sortExpect(trim(\skeeks\cms\helpers\RichTextHtml::toText(MailText::stripQuotedHtml($html))) === 'Спасибо!', 'Quoted HTML blocks must be removed.');
$outlook = '<p>Ок</p><div id="divRplyFwdMsg">From: a</div><div>old letter</div>';
sortExpect(trim(\skeeks\cms\helpers\RichTextHtml::toText(MailText::stripQuotedHtml($outlook))) === 'Ок', 'Outlook reply headers and the letter after them must be removed.');
sortExpect(MailText::name('=?UTF-8?B?0JDQu9C10LrRgdCw0L3QtNGAINCf0LDQvdGO0YjQtdCy?=') === 'Александр Панюшев' && MailText::name(' Иван ') === 'Иван',
    'Encoded display names must be decoded, plain ones kept.');

echo "cms-communication message sorting: OK\n";
