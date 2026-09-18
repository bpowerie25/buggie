# Notifications

Buggie never sends a notification the moment something happens. Activity is recorded,
and a scheduled job turns each person-plus-issue group into **one message** once the
group has gone quiet. Ten edits in a minute is one email, not ten.

## What you are told about

| Reason | |
|---|---|
| Assigned to you | Somebody put an issue in your name |
| You were mentioned | You were mentioned in a description or comment |
| New comment | Somebody commented on an issue you watch |
| Status changed | An issue you watch moved |
| Activity on an issue you reported | |

All five are on by default. Preferences are opt-out: silence should be chosen, not the
default.

> **Worth knowing:** there is no settings screen for these yet. The per-person
> preference is stored and honoured, but nothing in the interface changes it, so in
> practice everybody receives all five.

## Who is told

You become a **watcher** of an issue when you report it, are assigned it, comment on
it, or are mentioned in it. Notifications go to watchers.

Three rules are enforced regardless of preferences:

- **Nobody is told about their own actions.**
- **A client watching an issue is never told about internal activity.** Email is a way
  for an internal note to escape a workspace, so that check is repeated at the point
  of sending rather than trusted from whatever recorded the activity.
- Nothing crosses a workspace boundary: a digest only ever covers one issue in one
  workspace.

There is no control for watching or unwatching an issue by hand, and the watcher list
is not shown on the issue page.

## How the batching works

A scheduled command, `notifications:flush`, runs every minute. It groups pending
activity by person and issue and sends a group once **no new entry has arrived for the
digest delay**, which defaults to five minutes and is set with `DIGEST_DELAY_MINUTES`.

The window is measured from the **last** entry rather than the first. Measuring from
the first would mean a long argument in the comments posting its own digest
mid-conversation.

The email subject is `[WEB-142] The checkout button does nothing`, the body is one
line per thing that happened, and there is a button to open the issue. `Reply-To` is
set so that replying comments on the issue — see [Email](email.md#replying-to-a-notification).

Digests are also written to an in-application notifications table, but there is no
screen that reads it; email is how you find out.

**If digests never arrive, the scheduler is not running.** Nothing else surfaces that:
the entries simply accumulate. See
[Self-hosting](self-hosting.md#the-queue-worker-and-the-scheduler).

## What is not here

There is no in-app notification bell, no Slack integration, no unsubscribe link in
digest mail, no realtime "Ann is viewing" presence, and no `@mention` autocomplete in
the editor — mentions are recognised and notified when present, but nothing helps you
type one.

## Related pages

- [Email](email.md)
- [Issues](issues.md)
- [Clients](clients.md#internal-notes)
