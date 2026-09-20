# Changelog

Notable changes, in the words of somebody explaining them to you rather than a list of
commit subjects. The commit log has the detail; this has the reasons.

## Unreleased

**There are no tagged releases yet.** The hosted service at buggie.eu went up on
19 September 2026 and `main` has been moving quickly since. If you are self-hosting
today you are running `main`, which is honest rather than ideal — see
[Versioning](#versioning) for what changes that.

### Added

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
- **A security policy** ([`SECURITY.md`](SECURITY.md)) and this file.

### Fixed

- The board rendered empty columns when grouped by anything other than status, and
  drops were silently inert. It resolved every drop to a status by name regardless of
  the grouping.
- Client invitations were created but never consumed, so an invited client landed on
  "create a workspace" instead of the workspace they were invited to.
- The API skipped the client-visibility rule the UI applied. The rule now lives in the
  action, so every entry point gets it.
- Non-euro subscribers were not recognised on webhook, because the plan lookup searched
  only the default currency. They would have paid and received nothing.
- Settings → Billing returned a 500 for every owner. Nothing covered the successful
  load; the two tests touching the route asserted a 403 and a 404.
- Prices showed without a period or a VAT note whenever Stripe was unconfigured,
  because "what this costs" and "can you buy it right now" were the same flag.

### Security

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
