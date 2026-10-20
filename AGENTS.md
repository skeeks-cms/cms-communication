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

Run isolated tests and PHP syntax checks. No external sends, release,
commit, push or production deployment are implied by a local code change.
