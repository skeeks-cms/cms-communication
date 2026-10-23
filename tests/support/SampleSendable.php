<?php
/**
 * A minimal sendable for tests and for trying "Отправить клиенту" on a
 * stand: not part of the package, never registered in production. It
 * shows what a package (cms-proposal, bills) implements.
 *
 * On a stand register it in local configuration only:
 *
 * ```php
 * require_once '/app/vendor/skeeks/cms-communication/tests/support/SampleSendable.php';
 * 'communication' => ['sendables' => ['sample' => \skeeks\cms\communication\tests\SampleSendableSource::class]],
 * ```
 *
 * The id of a sample is the id of a lead; its sends are written to
 * @runtime/sample-sends.log.
 */

namespace skeeks\cms\communication\tests;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SendableInterface;
use skeeks\cms\communication\send\SendableSourceInterface;
use skeeks\cms\communication\send\SendContext;
use skeeks\cms\communication\send\SendFile;
use skeeks\cms\communication\services\CrmRecords;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\User;
use yii\db\ActiveRecord;

class SampleSendable implements SendableInterface
{
    /** @var array[] every send: type, id, channel, recipient, url, code, messageId */
    public static array $sends = [];

    /** Link base; on a web request — the site itself. */
    public static string $baseUrl = 'https://example.test/offer/';

    /** Tests make createSendLink() fail to check the rollback. */
    public static bool $failLink = false;

    public int $id;

    /** @var ActiveRecord[] */
    public array $records;

    public function __construct(int $id, array $records = [])
    {
        $this->id = $id;
        $this->records = $records;
    }

    public function getSendTitle(): string
    {
        return 'Тестовое предложение №'.$this->id;
    }

    public function getSendText(): string
    {
        return "Здравствуйте!\nПодготовили для вас предложение. Посмотреть его можно по ссылке.";
    }

    public function getSendButton(): string
    {
        return 'Открыть предложение';
    }

    public function getSendFiles(): array
    {
        return [SendFile::path(__DIR__.'/sample.pdf', 'Предложение-'.$this->id.'.pdf', 'application/pdf')];
    }

    public function getSendRecords(): array
    {
        return $this->records;
    }

    public function createSendLink(SendContext $context): string
    {
        if (self::$failLink) {
            throw new \RuntimeException('Ссылка не создана.');
        }
        // A package stores a pending send row here; the sample keeps its code in the context.
        $context->ref = bin2hex(random_bytes(6));
        $base = self::$baseUrl;
        if (\Yii::$app->request instanceof \yii\web\Request && \Yii::$app->has('urlManager')) {
            $base = \Yii::$app->request->hostInfo.'/sample-offer/';
        }

        return $base.$context->ref;
    }

    public function afterSend(SendContext $context, CmsCommMessage $message): void
    {
        $send = [
            'type' => $context->type,
            'id' => $context->id,
            'channel' => $context->channel,
            'recipient' => $context->recipient,
            'recipientName' => $context->recipientName,
            'url' => $context->url,
            'code' => $context->ref,
            'messageId' => (int)$message->id,
            'record' => $context->record ? get_class($context->record).':'.$context->record->primaryKey : null,
        ];
        self::$sends[] = $send;
        if (\Yii::$app->has('request') && \Yii::$app->request instanceof \yii\web\Request) {
            @file_put_contents(\Yii::getAlias('@runtime/sample-sends.log'), date('c').' '.json_encode($send, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        }
    }
}

class SampleSendableSource implements SendableSourceInterface
{
    /** @var callable|null fn(int $id, User $user): ?SendableInterface — tests without the CRM schema */
    public $finder;

    public function title(): string
    {
        return 'Тестовый документ';
    }

    public function find(int $id, User $user): ?SendableInterface
    {
        if ($this->finder) {
            return ($this->finder)($id, $user);
        }
        $lead = CrmRecords::find(CrmRecords::LEAD, $id, $user);

        return $lead ? new SampleSendable($id, [$lead]) : null;
    }

    public function suggest(User $user, string $q, ?ActiveRecord $record = null, int $limit = 20): array
    {
        $t = CmsLead::tableName();
        $query = CrmRecords::visibleQuery(CrmRecords::LEAD, $user)->orderBy([$t.'.id' => SORT_DESC])->limit($limit);
        if ($q !== '') {
            $query->search($q);
        }
        $leads = $query->all();
        if ($record instanceof CmsLead) {
            $leads = array_merge([$record], array_filter($leads, static fn($l) => (int)$l->id !== (int)$record->id));
        }

        return array_map(static fn(CmsLead $lead) => [
            'id' => (int)$lead->id,
            'title' => 'Тестовое предложение №'.$lead->id,
            'hint' => 'Лид «'.CrmRecords::name($lead).'»',
        ], array_slice($leads, 0, $limit));
    }
}
