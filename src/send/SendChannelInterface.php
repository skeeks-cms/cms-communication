<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\models\User;

/**
 * A channel of "Отправить клиенту": it decides how a sendable looks in it
 * (a letter with a button and the PDF, an SMS with a link, later a messenger
 * message with a button and a file) and creates the message. Registered in
 * `communication.channels`; mail and SMS come with this package. A sendable
 * never knows the channels.
 */
interface SendChannelInterface
{
    /** "Письмо", "СМС". */
    public function title(): string;

    /** Icon name for MailUi::icon(): "mail", "phone". */
    public function icon(): string;

    /** Kind of client contacts this channel sends to: SendContacts::EMAIL or SendContacts::PHONE. */
    public function contactKind(): string;

    /** Null when the employee may send through the channel now, otherwise why not (shown in the window). */
    public function unavailableReason(User $user, int $siteId): ?string;

    /**
     * The panel of the channel in the window "Отправить клиенту": recipient,
     * editable text, preview. It posts to the channel's own endpoint or to
     * `send` of the message controller (SendRequest fields).
     *
     * @param array[] $contacts contacts of this channel's kind (SendContacts::of())
     */
    public function renderForm(SendRequest $request, array $contacts): string;

    /**
     * Sends: checks the request, asks the sendable for the personal link,
     * creates the message linked to the CRM records, calls afterSend() and
     * queues the delivery job — all in one transaction.
     *
     * @throws SendException with errors by field when the request is wrong
     */
    public function send(SendRequest $request): CmsCommMessage;
}
