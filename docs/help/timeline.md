# Timeline

When the work is meant to happen. Issues as bars on a date axis, subtasks grouped
under their parent, and the late ones in red.

The issue list answers "what is outstanding" and the board answers "what is moving".
Neither has ever answered "what is meant to land in October", because a list sorted by
priority has no room for a date in it.

**Staff only.** A timeline is a plan across the whole workspace. Drawn for a client
who holds two projects out of twenty it would either tell them about the other
eighteen or stop being a plan, so it is not in their navigation and the page refuses
them outright.

## Two dates, and why the start is a real field

An issue has a **start date** and a **due date**. Both are optional, and each is set
on the issue itself, beside one another in the sidebar.

The start date is stored, not worked out. The two obvious ways to avoid asking for it
both produce a bar that says something untrue:

- **Due date minus the estimate.** An issue estimated at two hours and due in three
  weeks draws as a two-hour sliver somewhere near the end of the month. The estimate
  is how long the work takes; it is not a statement about when anybody intends to
  start it.
- **The date the issue was created.** An issue raised in January and due in September
  becomes an eight-month bar, and nothing on the screen distinguishes it from work
  somebody actually planned to take eight months.

So Buggie asks, and where it has not been told, it says so:

| What is set | What is drawn |
|---|---|
| Both dates | A **bar** from one to the other |
| Due date only | A **diamond** on the due date |
| Start date only | A **diamond** on the start date |
| Neither | Nothing — it is listed under **No dates** below the chart |

A diamond claims a moment and nothing either side of it, which is exactly as much as
is known. Issues with no dates at all are listed rather than drawn, because there is
no honest position for them on an axis made of dates.

## Grouping

A subtask is drawn indented under its parent. Buggie has exactly one level of
hierarchy, so the indent is never deeper than one.

**A parent with no dates of its own spans its children**, drawn as a bracket rather
than a solid bar — it is standing in for the work underneath it rather than making a
commitment of its own.

**A parent with its own dates keeps them**, even when a child runs past the end. A
date somebody typed is a commitment, and quietly widening it to fit whatever the work
turned out to be is how a deadline stops meaning anything. The child's bar sticking
out past its parent's is the point: that is the slip, visible.

A subtask whose parent did not match the filter is drawn at the top level. It is still
real work.

## Dependencies

Blocking relations come from the issue page — see [Issues](issues.md). On the timeline
they show up two ways.

Every blocked issue is **named as blocked**: a ⛔ and a count beside the title, with
the blockers listed on hover.

Only a blocker **that does not finish in time** gets a connector — a dashed red line
from the blocker's end to the blocked issue's start. That is deliberate. A line for
every blocking relation turns a busy quarter into a ball of string, and most of those
lines repeat something the ordering already shows. A blocker that is still running
after the thing it blocks was supposed to start is the one worth interrupting somebody
about.

A blocker that is not on screen — filtered out, or outside the date range — is still
named, but has nowhere for a line to go.

## Late

An issue is **overdue** when it is past its due date and still open. It is drawn in
red, and its title goes red too.

The rule is exactly the one behind `is:overdue` in the
[query language](query-language.md), because two screens disagreeing about what "late"
means is worse than neither having it:

- Strictly past. Something due today has until the end of the day.
- Open only. An issue nobody is going to work on again is finished, not late.

The dashed vertical line is today.

## The range, and the filter

**The range** is two date pickers and three presets, and it defaults to a fortnight
back and about two and a half months forward. Insights defaults to the last ninety
days because it reports what happened; a plan mostly points the other way, and the
fortnight behind is there so a slip is visible next to what it has pushed.

Putting the dates the wrong way round swaps them rather than complaining.

**The axis scale follows the range.** Up to about two months is labelled by day, up to
a year by week, longer by month. A year at day resolution is 365 gridlines with bars
drawn between them.

Work is drawn when it **overlaps** the range, not when it fits inside it. A six-month
piece of work is not absent from October because it started in September; its bar runs
off the edge, which is the thing worth knowing about it.

**The filter is the [issue query language](query-language.md)** — the same string as
the issue list, so `is:open assignee:@me -label:wontfix` means here what it means
there. It defaults to `is:open`: a timeline of finished work is a record, not a plan.
The project dropdown edits that string rather than being a filter of its own, so a
query you build here is one you can paste into the issue list.

The dates are not part of the query, because they decide what is *drawn* rather than
what matches. Two issues both due in June are both June issues however you are
looking at them.

## Dragging

- **Move a bar** by dragging its middle: both dates move together, in whole days.
- **Change one date** by dragging either end of a bar. An end stops at the other one,
  so a bar is always at least a day.
- **A diamond** (one date only) moves that one date and leaves the other empty.
- **A bracket** (a parent spanning its subtasks) cannot be dragged: its dates are its
  subtasks'. Drag those.
- **An undated issue** can be dragged from the *No dates* list onto the chart: it gets
  a one-day bar on the day it was dropped, ready to be stretched.
- **From the keyboard**, focus a bar with Tab: ← and → move it a day, and with Shift
  they move its due date.

The dates being chosen are shown above the bar while it moves. Every change is recorded
in the issue's activity, for staff.

**Two people planning at once.** Each bar carries the version of the issue it was drawn
from. If somebody else has changed that issue since you loaded the timeline, your drag is
refused rather than quietly overwriting theirs; the timeline says who changed it and
reloads to show it as it is now. Try again from there.

## Known rough edges

- **Five hundred rows, then it stops**, and says so. A chart with a thousand bars is a
  texture rather than a plan. The cut happens before subtasks are grouped, so a very
  large workspace may see a parent whose children were trimmed away.
- **A start date after its due date is not rejected.** The bar is drawn between the
  two dates whichever way round they are. Refusing the edit would leave somebody
  unable to fix the other date first, so the typo is left visible instead of being
  made unfixable.
- **A parent is not marked late because a child is.** Overdue is about an issue's own
  due date. The child's own bar is red, one row down.
- **There is no export.** The [issue export](issues.md) carries `due_on`; it does not
  yet carry the start date.
- **Progress is not shown.** A bar says when, not how far along. See
  [Time tracking](time-tracking.md) for hours against an estimate, which is the
  nearest thing.

## Related pages

- [Issues](issues.md) — setting the dates, and the blocking relations.
- [The issue query language](query-language.md) — everything the filter box accepts.
- [Insights](insights.md) — what happened, rather than what is planned.
