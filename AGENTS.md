# CMS Communication development

Read README.md and ARCHITECTURE.md before changing this package. Follow the
active shared-vendor skeeks-cms-package-development skill.

This package owns communication accounts (mailboxes, bots, SMS channels),
employee access to them, external identities, conversations, messages,
delivery events, links to CRM entities, client notification routing and
mailings. Channel transports live in driver packages
(`cms-communication-imap`, future messenger drivers). CMS core owns leads,
companies, clients, tasks, the activity log and the extension points this
package plugs into; never add a dependency from CMS to this package.

Every action (send, reply, link, create a task from a message) is one service
operation used by the admin UI, MCP/REST tools, bot scenarios and the AI
assistant alike. Do not implement the same operation twice in controllers.

All external I/O runs through cms-job: own lanes, resource keys per account,
no own queue or worker loop. Protocol cursors (IMAP UID, update offsets) are
committed in the same transaction as the received rows. Unknown send outcomes
are never resent automatically. Provider acceptance does not prove receipt.

Credentials are never exposed in forms, job payloads, results, logs or
exceptions. Visibility of a message is account access OR visibility of a
linked entity through its `forManager()` scope; sending is limited by the
employee's send right on the account. Notifications use CmsWebNotify only,
with server-side recipients and no self-notification.

Exception to the queue rule: the interactive "Проверить подключение" action
logs in to IMAP/SMTP synchronously with the driver timeout; it reads and sends
nothing. Mail transports implement `mail\MailDriverInterface`; the receive
algorithm (cursor per folder, Message-ID dedup, threading, links, feed) lives
in `jobs\MailPollJobHandler` and `services\MailIngestService`, not in drivers.
Manual sorting is `services\MessageSorting` (link/unlink/assign) and
`services\MessageRecordFactory` (task/lead/deal from a letter, also behind
the core record source `message`, `source\MessageRecordSource`); the CRM
record types mail knows and their visibility are `services\CrmRecords`. All
links go through `MessageLinker` (link type strength, exclusions).
Actions with letters in the mailbox are `services\MailboxActions` (records
the asked-for place `target_role` at once; right "Управление письмами") and
`services\MailServerSync` (the job `comm.mail.apply` and the receive job do
it on the server and find letters moved or deleted in the mail service);
drivers that can change a mailbox implement `mail\MailManageSessionInterface`.
Conversation search is `services\MailSearch` (`cms_comm_message_search`,
written by `CmsCommMessage::afterSave()`).
"Отправить клиенту" is `services\SendService` with channels in `send\`
(`EmailSendChannel` reuses `MailComposeService::queue()` and its
`ComposeForm::$sendables`, `SmsSendChannel` + job `comm.sms.send`); documents of
other packages come through `communication.sendables`
(`send\SendableSourceInterface`, `send\SendableInterface`). This package never
ships a production sendable: the sample lives in `tests/support`.
`CmsCommMessageQuery::forManager()` relies on core forManager() scopes, which
read `Yii::$app->user` for subordinates: call it in web requests, not in jobs.
Mailings (stage 4): consent and opt-out belong to a contact
(`CmsCommContact`: site, kind, normalized value), not to a lead; only
`services\MailingConsents` writes topic states and the stop list (an import
never undoes an opt-out or weakens a consent). Every advertising send asks
`services\MailingPolicy` (manager mailbox: cold allowed; mailing service:
consent only); service messages and personal letters never ask it, a
personal letter to an opted-out contact only warns. An advertising letter is
`ComposeForm::$cms_comm_topic_id` (one recipient, personal opt-out link,
List-Unsubscribe headers); the opt-out page and RFC 8058 one click are
`controllers\UnsubscribeController` (`/~unsub/<code>`). Mailings reach CRM
cards only through core places (`CrmCardBlocksWidget`, `CrmContactBlockWidget`).
Imports into leads are `services\LeadImport` + `services\CrmDuplicates`, the
job `comm.import.leads` for large files; leads get no notification each. Its
entry is the tab "Импорт" of the core leads section, added by the bootstrap
through the container definition (`actionsMap`), not a menu item.

Run isolated tests and PHP syntax checks. No external sends, release,
commit, push or production deployment are implied by a local code change.
