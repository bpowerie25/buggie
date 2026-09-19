# Buggie help

Buggie is a bug tracker built around *intake*. A `<script>` tag in your application
captures the screenshot, the console, the failing request, the page address, the
release and whoever was signed in at the moment somebody clicks "Report a bug", so
nobody has to reconstruct the context by hand afterwards.

Two ideas shape everything else:

- **Reports are not issues.** Everything the widget sends lands in a per-workspace
  triage inbox, never directly in the backlog. See [Triage](triage.md).
- **Identical reports collapse.** Reports carrying the same error are fingerprinted
  and grouped, so forty people hitting one broken checkout produce one issue with a
  count of forty. See [Triage](triage.md#fingerprinting-and-grouping).

Buggie is AGPL-3.0. The same code runs the hosted service and your own server; the
only difference is that plans and limits apply on the hosted service and nowhere
else. See [Billing and plans](billing.md) and [Self-hosting](self-hosting.md).

## If you run Buggie for your clients

1. [Getting started](getting-started.md) — accounts, workspaces and the subdomain model.
2. [Projects](projects.md) — creating them, the issue key, the site URL.
3. [The reporter widget](widget.md) — installing it in a client's application.
4. [Triage](triage.md) — turning incoming reports into issues.
5. [Issues](issues.md) — the list, the board, editing, attachments, comments.
6. [The issue query language](query-language.md) — filtering, and saved views.
7. [Statuses and workflow](workflow.md) — renameable names over fixed categories.
8. [Labels, priorities, types and assignees](labels-and-fields.md).
9. [Clients](clients.md) — inviting them and controlling what they see.
10. [Custom fields](custom-fields.md) — extra fields on an issue, per project.
11. [White-labelling](branding.md) — putting your client's name on what they see.
12. [Releases and versions](releases.md) — what a bug was found in, and fixed in.
13. [Email](email.md) and [Notifications](notifications.md).
14. [Keyboard shortcuts](keyboard.md).
15. [Billing and plans](billing.md), [Self-hosting](self-hosting.md),
    [Privacy and security](privacy-and-security.md).

## If you were invited to a workspace as a client

- [Getting started](getting-started.md) — accepting the invitation and signing in.
- [Clients](clients.md) — what you can and cannot see, and why.
- [Issues](issues.md) — reading an issue, commenting, filing a new one.
- [The issue query language](query-language.md) — finding things in the list.

## If somebody sent you a link to a bug you reported

You do not need an account. See
[the reporter portal](clients.md#the-reporter-portal).

## Native applications

There is a Swift package for iOS and a Kotlin one for Android, both posting to the
same endpoint as the web widget. See [Native SDKs](native-sdks.md).

## Connecting Buggie to other things

- [The API](api.md) — token-authenticated HTTP access to a workspace.
- [Webhooks](webhooks.md) — calling a URL when something happens.
- [Importing](importing.md) — bringing issues in from Mantis, Jira or a CSV.

---

These pages describe the behaviour in this repository. Two further documents are
worth reading alongside them: [`../DESIGN.md`](../DESIGN.md) records why Buggie is
built the way it is, including the mistakes, and [`../SELF_HOSTING.md`](../SELF_HOSTING.md)
is the operator's reference.
- [Releases](releases.md) — grouping issues into a release, and the changelog
- [Importing](importing.md) — bringing a backlog over from Jira, MantisBT or a spreadsheet
