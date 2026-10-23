<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\communication\send;

use skeeks\cms\communication\forms\ComposeForm;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\services\MailComposeService;
use skeeks\cms\communication\services\MailTemplates;
use skeeks\cms\communication\services\SendContacts;
use skeeks\cms\communication\services\SendService;
use skeeks\cms\helpers\RichTextHtml;
use skeeks\cms\models\User;

/**
 * Mail: an ordinary letter from the employee's mailbox (conversation, feed,
 * "Отправленные") with the text, the button "Открыть …" and the files as
 * attachments. The window shows the usual letter form with the sendable
 * put in, as "Прикрепить из…" does; both go through MailComposeService.
 */
class EmailSendChannel implements SendChannelInterface
{
    public function title(): string
    {
        return 'Письмо';
    }

    public function icon(): string
    {
        return 'mail';
    }

    public function contactKind(): string
    {
        return SendContacts::EMAIL;
    }

    public function unavailableReason(User $user, int $siteId): ?string
    {
        return (new MailComposeService($user, $siteId))->sendableAccounts()
            ? null
            : 'У вас нет почтового ящика, с которого можно отправлять письма.';
    }

    public function renderForm(SendRequest $request, array $contacts): string
    {
        $service = new MailComposeService($request->user, $request->siteId);
        $form = $this->form($request, $service);
        $form->to = $form->to ?: (string)($contacts[0]['value'] ?? '');
        $templates = new MailTemplates($request->user);

        return \Yii::$app->view->render('@skeeks/cms/communication/views/message/_compose', [
            'uploads' => [],
            'signatures' => $templates->signatures(),
            'templates' => $templates->templatesForForm(),
            'form' => $form,
            'accounts' => $service->sendableAccounts(),
            'contextRecord' => null,
            'original' => null,
            'inline' => false,
            'files' => [],
            'sendables' => $service->formSendables($form),
            // The window keeps no draft: the sendable is its subject.
            'noDrafts' => true,
        ]);
    }

    public function send(SendRequest $request): CmsCommMessage
    {
        $service = new MailComposeService($request->user, $request->siteId);
        $form = $this->form($request, $service);
        if (!$form->validate()) {
            throw new SendException($form->getFirstErrors());
        }

        return $service->queue($form);
    }

    /** The letter of the request: the sendable's text and its link, which the employee may edit. */
    private function form(SendRequest $request, MailComposeService $service): ComposeForm
    {
        $form = new ComposeForm(['service' => $service]);
        $form->cms_comm_account_id = $request->accountId ?: $service->defaultAccountId();
        $form->to = $request->recipient;
        $form->subject = $request->subject !== '' ? $request->subject : $request->sendable->getSendTitle();
        // A formatted introduction (SendableHtmlInterface) keeps bold, links and lists in the letter.
        $intro = $request->sendable instanceof SendableHtmlInterface ? trim($request->sendable->getSendHtml()) : '';
        $form->body_html = $request->text !== ''
            ? RichTextHtml::clean($request->text, ComposeForm::EDITOR_PRESET)
            : ($intro !== '' && !RichTextHtml::isEmpty($intro)
                ? RichTextHtml::clean($intro, ComposeForm::EDITOR_PRESET)
                : RichTextHtml::fromText($request->sendable->getSendText())).'<div><br></div>'
                .SendService::editorLink($request->type, $request->id, $request->sendable->getSendButton());
        $form->sendables = [$request->ref()];
        $code = $request->record && method_exists($request->record, 'getSkeeksModelCode') ? (string)$request->record->getSkeeksModelCode() : '';
        if ($code && $service->contextRecord($code, (int)$request->record->primaryKey)) {
            $form->context_model_code = $code;
            $form->context_model_id = (int)$request->record->primaryKey;
        }

        return $form;
    }
}
