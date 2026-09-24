# Changelog

Notable changes, in the words of somebody explaining them to you rather than a list of
commit subjects. The commit log has the detail; this has the reasons.

## Unreleased

**There are no tagged releases yet.** The hosted service at buggie.eu went up on
19 September 2026 and `main` has been moving quickly since. If you are self-hosting
today you are running `main`, which is honest rather than ideal — see
[Versioning](#versioning) for what changes that.

### Added

- **An issue's type can be changed from its sidebar**, like status and priority, and
  the activity feed says what it changed from and to. Staff only; a client sees it.
- **Client issues start in "New" and appear in Triage.** An issue a client filed used
  to start in the project's default status — the team's ready queue, "Scoped" on the
  client website template — as though somebody had already looked at it. Every
  project now has a **New** status, added at the top of existing workflows on upgrade,
  and the Triage screen lists client issues waiting in it. Staff issues are unchanged.
- **A registration mode**: open, by invitation, or by invitation and on request, set
  under Settings → Instance or with `BUGGIE_REGISTRATION`. In `request` mode a
  workspace's sign-in page offers *Request access*; its owners and admins are emailed
  and decide, approving sends the ordinary invitation, and operators see every request
  as the fallback. See the security entry below for why this exists.
- **`buggie:operator`**, to promote, create, revoke or list operators from the command
  line, and **`buggie:audit-signups`**, which lists accounts in no workspace and
  workspaces no operator owns. The audit reports and never deletes.
- **An in-app notification list.** Notifications existed only as email, and mail is
  unconfigured on a great many installs, so in practice they existed nowhere: the
  activity was recorded, the digest was assembled, and it went to a mail server that
  was not there. There is now a list of what has happened to you, an unread count in
  the sidebar, and a mark-all-read. What it shows is decided against the issue policy
  when you read it, not when the row was written, so losing a workspace, a project
  grant or a client-visible flag takes the notifications with it.
- **A board that can be ordered.** Cards drag into the order you mean rather than
  sitting in priority order, and a status can carry a work-in-progress limit. The
  limit is shown and counted but never enforced: a limit that refuses the drop turns a
  prompt to finish something into an obstacle to be routed around.
- **Custom fields**, per project, with their own filter syntax (`field:key=value`).
  Internal unless you say otherwise, and an internal field cannot be filtered on by a
  client — filtering leaks a value as surely as printing it.
- **Time tracking.** Hours against an issue, estimates beside them, totals per client
  and per person. Stored as whole minutes; exported as both minutes and hours, because
  twenty minutes is 0.333… hours and a column of those does not add up.
- **Insights.** Opened against closed, backlog over time, median time to close.
- **Due-date chasing.** Reminders on a ladder before and after the date, settling to
  weekly, so three weeks overdue is six messages rather than twenty-one.
- **Two-factor authentication**, TOTP with recovery codes, on every plan and in the
  self-hosted build.
- **Slack and Teams** notifications, with issue descriptions and comment text
  deliberately never sent to either.
- **White-labelling** the reporter portal and panel, per project.
- **Importing** from Jira, MantisBT or a CSV, with a preview before anything is
  created.
- **Subtasks.** An issue can be part of another one, exactly one level deep, filtered
  with `parent:` and `no:parent`. Deleting a parent never takes its children with it.
- **Deleted issues can be recovered.** Issues have been soft-deleted from the
  beginning and nothing ever read them again — no screen, no route, no `withTrashed`
  anywhere — so a delete looked careful and behaved permanently.
- **A security policy** ([`SECURITY.md`](SECURITY.md)) and this file.

### Fixed

- A client filing an issue could set its starting status by adding `status_id` to the
  request — straight into "Done" — although the form never offered one. And any
  issue could be created in a status belonging to another project of the workspace.
- The board rendered empty columns when grouped by anything other than status, and
  drops were silently inert. It resolved every drop to a status by name regardless of
  the grouping.
- Client invitations were created but never consumed, so an invited client landed on
  "create a workspace" instead of the workspace they were invited to.
- The API skipped the client-visibility rule the UI applied. The rule now lives in the
  action, so every entry point gets it.
- Non-euro subscribers were not recognised on webhook, because the plan lookup searched
  only the default currency. They would have paid and received nothing.
- **Deleting a project broke the issue list for the whole workspace.** Projects
  soft-delete, and the `cascadeOnDelete` on `issues.project_id` only fires on a real
  DELETE — so the issues stayed in every list belonging to a project that no longer
  resolved, and the list threw on the first one it rendered. One click, no way back,
  because projects have no restore path either. Issues of a deleted project are now
  hidden rather than destroyed, so restoring the project would bring them back.
- Settings → Billing returned a 500 for every owner. Nothing covered the successful
  load; the two tests touching the route asserted a 403 and a 404.
- Prices showed without a period or a VAT note whenever Stripe was unconfigured,
  because "what this costs" and "can you buy it right now" were the same flag.

### Removed

- `issues.severity`. The idea was sound — severity is how bad a thing is, priority is
  when it gets fixed — but it was never built beyond the column. Nothing wrote it,
  nothing read it, and the CSV importer maps a Mantis "severity" column onto
  *priority* instead, so not one row in any install had a value in it. A column that
  exists only in the schema misleads whoever reads the model next.

### Security

- **Self-hosted installs no longer let strangers sign up.** Until now anybody could
  register on a self-hosted install and create workspaces of their own — storage,
  outbound mail and a subdomain of somebody else's domain to put content on. It was
  not a leak between workspaces, which are membership-gated, but it was an open door
  on a server whose owner never meant to run a public service. A self-hosted install
  is now invitation-only by default, and one upgraded without having chosen a mode
  becomes invitation-only too. Only operators create workspaces, enforced on the
  route, the form and the action rather than by hiding a button. Nobody already in a
  workspace loses anything, invitations already sent still work, and the hosted
  service stays open. After upgrading, run `buggie:audit-signups` to see what was set
  up while the door was open; to reopen it deliberately, choose *Open* on
  Settings → Instance or set `BUGGIE_REGISTRATION=open`.
- **Operators are stored, not inferred.** With nobody named in `BUGGIE_OPERATORS`, a
  self-hosted install treated the lowest user id as its operator on every check, so
  deleting that account silently handed the install to whoever came next. The upgrade
  marks the same person the old rule chose, and from then on it is a recorded fact.
- **The first registration on an empty install is race-safe.** Exactly one wins, and
  the exception is never offered again, even after every account is deleted.
- Webhook and chat URLs are checked against private address ranges when saved **and
  again at send time**, because a name that resolved somewhere public yesterday can
  resolve somewhere else today.
- Chat integrations no longer announce internal issues to a channel that has not been
  marked as the team's own.
- The production server's address was removed from the deployment runbook, where it sat
  beside the SSH user, the paths and the deploy procedure.

## Versioning

Releases will be tagged `MAJOR.MINOR.PATCH` once self-hosting is something this project
promises rather than something it permits. The first tag is `0.1.0` and the trigger for
it is an upgrade path worth relying on: migrations that have been run forward from the
previous tag on a database with real data in it.

Until then, **`main` is the only thing to run**, and it can move under you.

A change is **breaking** — and so a major version — if it changes any of:

- the HTTP API under `/api/v1`,
- the webhook payload shape documented in `docs/help/webhooks.md`,
- the widget snippet's contract with the page it is embedded in,
- or the meaning of an existing database column.

Renaming a thing in the interface is not breaking. Adding a field to a payload is not
breaking. Removing one is.
