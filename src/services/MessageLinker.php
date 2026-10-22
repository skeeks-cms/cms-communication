<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\services;

use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsUser;
use yii\db\ActiveRecord;

/**
 * Links a message to CRM records and puts it into their activity feeds.
 * The message is stored once; every linked record gets a cms_log entry of
 * type `message` that points to it, like calls do. The caller owns the
 * transaction.
 */
class MessageLinker
{
    /**
     * @param ActiveRecord[] $records
     */
    public function link(CmsCommMessage $message, array $records, string $type, ?int $createdBy = null): void
    {
        foreach ($records as $record) {
            $code = method_exists($record, 'getSkeeksModelCode') ? $record->getSkeeksModelCode() : get_class($record);
            $this->linkModel($message, (string)$code, (int)$record->primaryKey, $type, $createdBy, $record);
        }
    }

    /**
     * Earlier letters of the conversation pass their links on: a reply from
     * another address still lands in the same lead or company.
     */
    public function inheritFromConversation(CmsCommMessage $message): void
    {
        $rows = CmsCommLink::find()
            ->select(['model_code', 'model_id'])
            ->distinct()
            ->andWhere(['cms_comm_message_id' => CmsCommMessage::find()->select('id')
                ->andWhere(['cms_comm_conversation_id' => $message->cms_comm_conversation_id])
                ->andWhere(['!=', 'id', $message->id])])
            ->asArray()
            ->all();
        foreach ($rows as $row) {
            $this->linkModel($message, $row['model_code'], (int)$row['model_id'], CmsCommLink::TYPE_THREAD, null);
        }
    }

    private function linkModel(CmsCommMessage $message, string $code, int $id, string $type, ?int $createdBy, ?ActiveRecord $record = null): void
    {
        if (CmsCommLink::find()->andWhere(['cms_comm_message_id' => $message->id, 'model_code' => $code, 'model_id' => $id])->exists()) {
            return;
        }

        $log = new CmsLog();
        $log->model_code = $code;
        $log->model_id = $id;
        $log->log_type = CmsCommMessage::LOG_TYPE;
        $log->comment = (string)$message->subject;
        $log->data = ['id' => (int)$message->id];
        if ($record) {
            $log->model_as_text = method_exists($record, 'asText') ? (string)$record->asText() : null;
        }
        if ($code === (new CmsCompany())->skeeksModelCode) {
            $log->cms_company_id = $id;
        } elseif ($code === (new CmsUser())->skeeksModelCode) {
            $log->cms_user_id = $id;
        }
        if (!$log->save(false)) {
            throw new \RuntimeException('Не удалось записать письмо в ленту.');
        }
        // The feed is ordered by date: an imported letter takes its own date.
        if ($message->message_at) {
            $log->updateAttributes(['created_at' => (int)$message->message_at]);
        }

        $link = new CmsCommLink([
            'cms_comm_message_id' => $message->id,
            'model_code' => $code,
            'model_id' => $id,
            'link_type' => $type,
            'created_by' => $createdBy,
            'cms_log_id' => $log->id,
        ]);
        if (!$link->save(false)) {
            throw new \RuntimeException('Не удалось связать письмо.');
        }
    }
}
