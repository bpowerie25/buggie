# Changelog

Notable changes, in the words of somebody explaining them to you rather than a list of
commit subjects. The commit log has the detail; this has the reasons.

## Unreleased

**There are no tagged releases yet.** The hosted service at buggie.eu went up on
19 September 2026 and `main` has been moving quickly since. If you are self-hosting
today you are running `main`, which is honest rather than ideal — see
[Versioning](#versioning) for what changes that.

### Added

- **Mark as duplicate.** Closes an issue as not done, says why in its thread, and moves
  its reporter and watchers onto the original, so whoever raised it keeps hearing about
  the fix. A "duplicates" link existed before, but left the duplicate open and its
  reporter waiting on an issue nobody would work.
- **Buggie works on a phone.** Below desktop width the sidebar is a slide-out menu,
  and the board shows one near-full-width column at a time with a strip of column
  names to jump between. On a touch screen, swiping scrolls and press-and-hold picks a
  card up, instead of every swipe dragging whichever card it started on.
- **A board you can work on.** Every status is a column whether or not it has cards,
  so there is always somewhere to drop the first one; *Add card* at the foot of a
  column creates an issue straight into that status; cards finished in the last two
  weeks stay in their column instead of vanishing under `is:open`; and a pinned card
  stays on the board whatever the filter says (within its project, and never past what
  the viewer may see). The board has its own entry in the sidebar, and a card's key
  now opens the issue — it was meant to, and was a hidden link.
- **A spreadsheet template for importing.** Project settings → Import has a *Download a
  template* link: a CSV with exactly the columns the importer reads, example rows in the
  project's own status names, and the accepted values listed beside it. The example rows
  are skipped on import, so an untouched template creates nothing.
- **Reporter identity on widget reports**: anonymous, unverified email, identified (via
  `identify()`), or verified, where your server signs `id:email` with the key's secret.
  A verified report from a client on the project is linked to them on acceptance, so
  they can see it; unverified addresses grant nothing unless the workspace trusts them,
  and staff can link by hand. Keys can require a verified identity. The key's `mode`,
  which existed and did nothing, is now this setting.
- **Reply & await client.** Whose turn it is lives in the status, not the assignee.
  The reply goes out in public, the clients who can see the issue are told, and the
  issue moves to the project's awaiting-client status. The client's answer — in the
  app, by email or through the portal — moves it back, tells the assignee, and puts a
  *Client replied* badge on lists and Triage until somebody on the team looks. Projects
  can remind the client once, and close after a longer wait, both off by default.
- **Who wrote what, and who can read it, on every comment**: a Staff or Client badge, an
  Internal or Visible-to-client badge with the real audience on hover, and coloured
  borders. The composer is now an explicit Comment / Internal note switch with a line
  saying exactly who will see the message.
- **Choosing which clients see an issue.** Visibility is now *Internal only*,
  *Default* (client managers, the reporter and watchers), *All clients on this
  project*, or *Specific clients* (the default plus people you name). Named sharing is
  stored separately from watching, so un-watching never takes access away. The badge
  says who can actually see the issue — "Visible to: Jane, Mia", "All Kennco clients" —
  and only staff are shown it; a client sees "Shared with you". The rule lives in one
  scope that the list, the issue page, search, export, the API and notifications all
  use, and the issue policy now asks that scope rather than repeating it.
- **A client's tier is set per project on the members screen**, saving as it changes,
  and chosen when the client is invited (defaulting to their own issues only). Every
  change is listed under *Recent changes* on the same screen.
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

### Changed

- **Only staff can be assigned an issue.** A client could be made the assignee as a way
  of asking them a question, which made the agency's work say it belonged to the
  client — "Matrix assigned this to Brian Power". Every way in now refuses a client:
  the sidebar, the list, bulk edit, the API, triage, import and a project's default
  assignee. `buggie:client-assignees` lists any issue already assigned to a client and
  changes nothing. Reply & await client replaces the old way of asking.
- **Clients now see status changes** in their thread, alongside the issue being created
  and the conversation. Assignment, priority, visibility, audience, time and estimates
  stay out of their payload entirely, and they see the team as the workspace's name
  unless the workspace chooses to show its staff.

### Fixed

- **The screenshot is of what the reporter is looking at.** On a scrolled page it could
  be of a different part of the page altogether — further off the further down — and
  text in it looked wrong. The capture copied the widget's own stylesheet into its copy
  of the page, which resized every padded or bordered element on it. The widget is now
  left out of the copy entirely. *Mark up* also floats over the screenshot and stays in
  view however the report form is scrolled.
- A client on "own issues only" did not see issues they had reported through the widget
  with their own address, because the link to their account was only made when a
  report was accepted, and only for a trusted identity. Turning on *Trust unverified
  emails* now links the widget issues already accepted as well, and
  `buggie:link-widget-reporters` does the same from the command line, with a dry run.
- Marking up a screenshot was hard to aim. The capture carried its own inline size,
  which beat the widget's styles: the preview in the panel showed a zoomed-in crop of
  one corner, and the full-screen editor squashed the image to fit, so a box never
  seemed to land where it was drawn. The image now keeps its shape in both, the editor's
  tools sit at the top right of the screen where they are always visible, and on a
  touch screen a drag draws instead of scrolling the page.
- The widget key settings card called its routes with the numeric id while the routes
  bind by public key, so every toggle, the allowed origins and the revoke button failed
  with a 404 while looking saved. A failed save now says so on the card.
- A client's copy of an issue included the full watcher list, which could name other
  clients, and the titles of related, parent and child issues they could not open.
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
