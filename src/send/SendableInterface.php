<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use skeeks\cms\communication\models\CmsCommMessage;
use yii\db\ActiveRecord;

/**
 * Something a package sends to a client through any channel: a commercial
 * proposal, a bill, an act. The package describes it; communications choose
 * the channel, the recipient and the look ("Отправить клиенту", "Прикрепить
 * из…" in the letter form). Found through a SendableSourceInterface
 * registered in `communication.sendables`.
 *
 * One send is one call of createSendLink() and one call of afterSend() with
 * the same SendContext, inside one database transaction together with the
 * created message: an exception in either rolls the whole send back, and a
 * send that fails before the message is created never reaches afterSend().
 */
interface SendableInterface
{
    /** Name of what is sent: the letter subject, the chip in the form ("КП №12 «Сайт под ключ»"). Plain text. */
    public function getSendTitle(): string;

    /** Short introduction the employee may edit before sending: plain text, a few lines. */
    public function getSendText(): string;

    /** Text of the button or link that opens the personal page ("Открыть КП"). */
    public function getSendButton(): string;

    /**
     * Files sent with it (the PDF): attached to letters, later sent as files
     * to messengers. Called for the preview too, so keep it cheap or cached.
     *
     * @return SendFile[]
     */
    public function getSendFiles(): array;

    /**
     * CRM records the send belongs to: a lead, company, client (CmsUser) or
     * deal. Their contacts are offered as recipients and the message lands
     * in their feeds. Records the employee cannot see are dropped.
     *
     * @return ActiveRecord[]
     */
    public function getSendRecords(): array;

    /**
     * A personal link of this one send: the channel and the recipient are in
     * the context, the package stores its own code (a pending send row) and
     * returns an absolute URL. It may keep its state in $context->ref.
     */
    public function createSendLink(SendContext $context): string;

    /**
     * The message is created and queued (not yet delivered: its status tells
     * that later). The package stores the message with its send record and
     * writes its own feed entry or status.
     */
    public function afterSend(SendContext $context, CmsCommMessage $message): void;
}
