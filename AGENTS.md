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
Campaigns (4B) are `services\Campaigns` (audience by `forManager()` of the
launching employee in the web request, recipients fixed at launch, one per
CRM record; every letter is `MailComposeService::queue()` with the topic,
the campaign mark, the trusted `ComposeForm::$record` and prepared
`$sendableInstances`, made inside the transaction that marks the recipient).
How letters leave is a channel of `communication.campaignChannels`
(`campaign\CampaignChannelInterface`); managers' mailboxes are
`campaign\MailboxCampaignChannel` with the job `comm.campaign.mailbox` per
mailbox and the pace of `services\MailboxPace` (CRM time zone, own letters of
the day count). Offers come from sendable sources with
`send\CampaignSourceInterface`; the "opened" task stays the document
package's rule and asks `Campaigns::taskOnOpen()`. Links made in jobs use
`CommunicationComponent::siteUrl()` (`communication.baseUrl` on a stand).
Recipients added one by one (an employee, AI through MCP) are
`Campaigns::addRecipient()` rows in status `draft` with an optional personal
letter and who prepared it (`text_by`, `text_client`, `text_user_id`); they
join any audience at launch and pass the same exclusions.
AI actions through MCP/REST (AI-2) are `services\AiActions` behind
`mcp\CommunicationActionToolProvider`: drafts saved by `MailDrafts` for the
responsible employee and checked by their letter form, draft campaigns and
their recipients (scope `cms.communication.write`). Nothing launches a
campaign; saving needs `confirm=true`. "Подготовлено ИИ" is `services\AiMark`
(prepared_* columns, recipient text_*), set only by the server from the
OAuth client of the running tool, never from arguments or forms; a letter
takes it only from the sender's own draft (`MailDrafts::applyAiDraft()`), an
employee's change marks it edited instead of removing it. Limits are
`communication.aiLimits`.
Sending through the API is `services\AiSend` only (scope
`cms.communication.mail_send`, separate from preparing): an own draft, a
check with no side effects (the letter form plus the API rules) whose
`check_token` the send recomputes, an idempotency key stored in
`cms_comm_api_send` inside the transaction of `MailComposeService::queue()`.
Never send around it (no direct SMTP, no own tracking) and never report a
queued or sent letter as delivered. Every API letter has an explicit
purpose (`services\LetterPurpose`: advertising with a topic and opt-out,
personal — the client wrote, service — cooperation, individual_offer — one
offer to one person: every advertising check by its topic, no opt-out line),
checked by the server and by `ComposeForm::$purpose` alike; never infer it
from the text or how the letter is sent. Whether a letter carries the
opt-out is `ComposeForm::withOptOut()` only (campaign letters always do).
The sender's mailbox signature is `services\MailSignatures` (once, in the
draft text). Files are `services\AiDraftFiles` (own storage files,
type by content, copies in mail storage, `cid:` pictures). Test mode is the
site's `communication.aiTestRecipients` (exact record–address pairs,
administrators) and skips only the repetition checks. Mailing topics by API are
`services\MailingTopics` (administrator only); a send never creates a topic.

Visits by links of letters (step F): links are marked only in
`MailComposeService::queue()` by `services\LinkMarks` (the text written now,
before quotes, sendable links and the opt-out link; never a link that has
`sx`, a `/~` path or another host; the code `cms_comm_track` in the letter's
transaction); no pixel, no redirect. Visits are `services\LinkVisits` only:
counted by the page signal of the shared `link-signal.js`
(`assets\LinkSignalAsset`, also used by cms-proposal), robots
`LinkChecks::ROBOT_PATTERNS`, checks `LinkChecks::reason()`, employees never.
The site script is registered by the bootstrap only for `?sx=` or the cookie
`sx_visit`; endpoints `controllers\VisitController` answer CORS only for
domains of `components\LinkTrackingSettings`. One feed entry per visit
(`log\VisitLogRenderer`), the call task once per code and never beside an
open "Позвонить…" task of the executor on the record.

Run isolated tests and PHP syntax checks. No external sends, release,
commit, push or production deployment are implied by a local code change.
