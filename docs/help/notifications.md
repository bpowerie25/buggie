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
| Due soon, or overdue | An issue you hold or watch is nearly due, or is late |

All six are on by default. Preferences are opt-out: silence should be chosen, not the
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

## Due dates

An issue can be given a due date in the sidebar. Something chases it: a scheduled
command, `issues:chase-due`, runs once a day and tells **the assignee and everyone
watching** when an issue is nearly due, and keeps telling them while it is late.

Before this existed the field was decoration — you could set a date and nothing on
earth would look at it again, which is worse than not having the field, because people
set it and assume something is watching.

### When you hear about it

Reminders get further apart rather than arriving every day:

| | |
|---|---|
| Three days before | Still time to do it, or to move the date |
| The day before | The last useful warning |
| The day itself | |
| One day late | The one that actually gets acted on |
| Three days late | |
| A week late | |
| Every week after that | 14 days, 21 days, and so on |

An issue that has been overdue for three weeks has produced six reminders, not
twenty-one. That is the whole design. Chasing has to get quieter as it goes on or the
sender ends up in a filter, and then the tracker cannot reach you about anything.

It never stops while the issue is open, though. Still open and still overdue a year
later is still overdue, and going silent would turn a missed deadline into a forgotten
one.

### What is never chased

- **A closed or cancelled issue.** By status *category*, not by name — your "Done"
  column can be called "Shipped" and your cancelled one "Not doing", and this still
  works. See [Statuses and workflow](workflow.md).
- **Anything in an archived project.** Archiving a project is deciding to stop working
  on it; its deadlines stopped meaning anything at the same moment.
- **An issue somebody cannot see.** A client is told about an issue only when they
  could have opened it themselves: marked client-visible, in a project they hold. A
  client watching anything else hears nothing, which is the same rule the rest of the
  product follows — see [Clients](clients.md).

Dates are dates, not moments. "Due today" means the date in the issue is today's date
in UTC, wherever you happen to be reading, and the reminder for a given day goes out
once however many times the command runs.

### Finding them yourself

`is:overdue` in the issue list: open issues whose due date has passed. Something due
today has until the end of the day and is not overdue yet. It replaces `is:open`
rather than combining with it, because a closed issue is finished rather than late.
See [The issue query language](query-language.md).

### Known rough edges

- There is one switch for due dates, covering both the warnings and the chasing. You
  cannot keep the reminder that something is late and turn off the one that says it is
  nearly due.
- The schedule is not configurable. Three days, one day, the day, then 1, 3, 7 and
  weekly, for everybody.
- Being assigned an issue means being chased about its date even if you have stopped
  watching the issue. Unwatching is about the running commentary; a deadline on work
  in your name is not commentary. Turn the reason off if you disagree.
- **A day the scheduler was down is a reminder nobody gets.** The command works out
  what today owes rather than what it has missed, so nothing catches up afterwards —
  which is deliberate, because an install that was offline for a month would otherwise
  deliver a month of chasing the moment it came back.
- Nothing chases a due date that was never set, and nothing suggests setting one.
- There is no "due this week" view, no calendar, and no way to see one person's
  deadlines other than `assignee:@me is:overdue`.

## What is not here

There is no in-app notification bell, no Slack integration, no unsubscribe link in
digest mail, no realtime "Ann is viewing" presence, and no `@mention` autocomplete in
the editor — mentions are recognised and notified when present, but nothing helps you
type one.

## Related pages

- [Email](email.md)
- [Issues](issues.md)
- [Clients](clients.md#internal-notes)

## Turning them off

**Notifications** in the sidebar. Every reason has its own switch, and everything is
on until you turn it off — a tracker nobody hears from is a tracker nobody uses.

The preferences belong to you, not to a workspace. Somebody invited to four client
workspaces should not have to switch the same thing off four times, so changing them
anywhere changes them everywhere.

Turning everything off is allowed and the page says plainly what it means: you will
not be emailed about anything, including issues assigned to you. That should be a
choice, not something discovered a fortnight later when a client says nobody replied.
