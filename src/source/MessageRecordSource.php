<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\source;

use skeeks\cms\communication\helpers\MailText;
use skeeks\cms\communication\helpers\MailUi;
use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\MessageRecordFactory;
use skeeks\cms\source\RecordOriginInterface;
use skeeks\cms\source\RecordSourceInterface;
use yii\db\ActiveRecord;
use yii\helpers\Html;

/**
 * A letter as the source of a new task, lead or deal: the standard create
 * window of the CRM opens with ?source=message:ID (skeeks/cms
 * RecordSources) and MessageRecordFactory does the work. The letter must be
 * visible to the employee (CmsCommMessageQuery::forManager()).
 */
class MessageRecordSource implements RecordSourceInterface, RecordOriginInterface
{
    public const TYPE = 'message';

    public function find(string $id)
    {
        if (!ctype_digit($id) || !\Yii::$app->has('user') || \Yii::$app->user->isGuest) {
            return null;
        }

        return CmsCommMessage::find()->cmsSite()->forManager()
            ->andWhere([CmsCommMessage::tableName().'.id' => (int)$id])
            ->one();
    }

    public function supports(string $modelClass): bool
    {
        return (new MessageRecordFactory())->supports($modelClass);
    }

    public function prefill($source, ActiveRecord $model): void
    {
        $this->factory()->prefill($source, $model);
    }

    public function enforce($source, ActiveRecord $model): void
    {
        $this->factory()->enforce($source, $model);
    }

    public function created($source, ActiveRecord $model): void
    {
        $this->factory()->created($source, $model);
    }

    public function describe($source): string
    {
        return $this->factory()->describe($source);
    }

    /**
     * "Создано из письма «Тема» от …, дата" at the top of the card of a
     * task, lead or deal created from a letter; the subject opens the letter
     * in the panel when the employee may read it.
     */
    public function origins(ActiveRecord $record): array
    {
        $code = method_exists($record, 'getSkeeksModelCode') ? (string)$record->getSkeeksModelCode() : get_class($record);
        if (!(new MessageRecordFactory())->supports($code) || !\Yii::$app->has('user') || \Yii::$app->user->isGuest) {
            return [];
        }
        $links = CmsCommLink::find()
            ->andWhere(['model_code' => $code, 'model_id' => (int)$record->primaryKey, 'link_type' => CmsCommLink::TYPE_CREATED])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $result = [];
        foreach ($links as $link) {
            $message = CmsCommMessage::find()->cmsSite()->forManager()
                ->andWhere([CmsCommMessage::tableName().'.id' => (int)$link->cms_comm_message_id])
                ->one();
            if (!$message) {
                $result[] = ['label' => 'Создано из письма', 'html' => Html::tag('span', 'из почтового ящика, к которому у вас нет доступа', ['class' => 'sx-gray'])];
                continue;
            }
            $incoming = $message->direction === CmsCommMessage::DIRECTION_IN;
            $who = $incoming ? MailText::name($message->from_name) : '';
            $who = $incoming ? ($who !== '' ? $who.' <'.$message->from_address.'>' : (string)$message->from_address) : $message->getToAsText();
            $result[] = [
                'label' => 'Создано из письма',
                'html' => Html::a('«'.Html::encode($message->getAsText()).'»', '#', [
                        'data-pjax' => '0',
                        'onclick' => MailUi::viewOnClick((int)$message->id),
                        'title' => 'Открыть письмо',
                    ])
                    .' '.Html::encode(($incoming ? 'от ' : 'для ').$who.', '
                        .\Yii::$app->formatter->asDatetime((int)$message->message_at, 'php:d.m.Y H:i')),
            ];
        }

        return $result;
    }

    private function factory(): MessageRecordFactory
    {
        return new MessageRecordFactory(\Yii::$app->has('user') ? (\Yii::$app->user->identity ?: null) : null);
    }
}
