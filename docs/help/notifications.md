# Notifications

Activity is recorded when it happens, and you find out in two places.

**The list**, under **Notifications** in the sidebar, gets everything immediately.
**Email** does not: a scheduled job turns each person-plus-issue group into **one
message** once the group has gone quiet, so ten edits in a minute is one email, not
ten.

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

One switch covers both surfaces. Turning a reason off stops the email *and* keeps it
out of the list — somebody who says "never tell me about comments" is not asking to
be told about them quietly. See [Turning them off](#turning-them-off).

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

## The list

**Notifications** in the sidebar, with the number you have not read yet beside it.

Everything in the table above lands there the moment it happens: no batching, no
delay, nothing scheduled. Each entry says which issue, what happened, who did it and
when, and opening one takes you to the issue.

It exists because email used to be the only way a notification reached anybody, and
mail is not configured on a great many installs. On those the activity was recorded,
the digest was assembled, and it was handed to a mail server that was not there. The
tracker knew perfectly well that six issues had been put in your name and had no way
of saying so.

### Read and unread

Opening an entry marks it read. **Mark all read** clears the rest. Read entries stay
where they are — the list is a record of what you were told, not a queue to be
emptied.

Read state is yours. Two people watching the same issue have their own counts, and
yours going quiet says nothing about theirs.

### What it will not show you

**The list is filtered when you read it, not when it was written.** Anything about an
issue you cannot see *now* is not in it, whatever was true at the time:

- **Removed from a workspace**, you see none of its notifications — you cannot reach
  the workspace at all.
- **A client who loses a project grant** loses the notifications about its issues
  with it.
- **An issue that stops being shared with clients** stops appearing in a client's
  list, including activity from while it was shared.
- **Somebody moved from staff to client** stops seeing the internal comments they
  were told about while they were staff.

Every one of those happens *after* the notification was recorded, which is the whole
reason the question is asked at the point of reading. The rule is the same one the
rest of the product follows — see [Clients](clients.md).

### How long they are kept

Ninety days, read or unread, and then `buggie:prune` deletes them
(`RETAIN_NOTIFICATION_DAYS`, and zero disables it). What actually happened is on the
issue, and issues are never pruned. A notification nobody opened in three months is
not waiting to be opened.

### Known rough edges

- **The list is per workspace.** It shows what happened in the workspace you are
  looking at, and the count beside it counts only that. Four client workspaces means
  four lists and four counts.
- **Nothing updates while you sit still.** The count is worked out when a page loads,
  so something arriving while you stare at one screen appears when you move.
- **The most recent hundred, and no paging.** Older than that is on the issue, which
  is where the history lives anyway. The hundred are picked before the visibility
  rules run, so somebody who has just lost access to a project can see fewer.
- **Opening an unread entry is a button, not a link**, so you cannot middle-click it
  into a new tab. Marking something read has to be a POST: a link that changes
  something is a link that a prefetcher will follow on your behalf, and a list that
  quietly marked itself read because something crawled it would be worse than one
  that never marked anything.
- There is no mark-as-unread, and no way to delete a single entry.
- Nothing in the list says whether the email went out as well, or whether it could
  have.

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

Digests are also written to Laravel's own notifications table as they are sent. That
is a record of what was emailed, and is separate from the list above, which is
written when the activity happens rather than when a message goes out.

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

There is no bell in the top bar — the list is a page, reached from the sidebar. There
is no unsubscribe link in digest mail, no realtime "Ann is viewing" presence, and no
`@mention` autocomplete in the editor: mentions are recognised and notified when
present, but nothing helps you type one.

## Related pages

- [Email](email.md)
- [Issues](issues.md)
- [Clients](clients.md#internal-notes)

## Turning them off

**Preferences**, at the top of the notification list. Every reason has its own
switch, and everything is on until you turn it off — a tracker nobody hears from is a
tracker nobody uses.

A switch covers both the email and the list. There is deliberately no way to keep one
and drop the other: two switches per reason is twelve switches, and nobody has ever
wanted to be told about comments only in the place they were not looking.

The preferences belong to you, not to a workspace. Somebody invited to four client
workspaces should not have to switch the same thing off four times, so changing them
anywhere changes them everywhere.

Turning everything off is allowed and the page says plainly what it means: nothing
will reach you about anything, including issues assigned to you. That should be a
choice, not something discovered a fortnight later when a client says nobody replied.
