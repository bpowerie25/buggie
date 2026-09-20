# Insights

Where the work went: how much came in, how much went out, whether the backlog is
growing, and how long a typical bug takes to close.

It is called Insights rather than Reports because a **report** already means something
in Buggie — the thing the widget sends. Two meanings for one word in one product is
how a support conversation goes wrong.

**Staff only.** Every figure is an aggregate across the whole workspace, and a client
scoped to two projects out of twenty cannot be shown a workspace total without it
telling them about the other eighteen.

## The period

Defaults to the **last 90 days**, not the current calendar month: a trend needs enough
points to be a trend, and on the second of the month a month-to-date chart has two.
There are presets for 30 days, 90 days and a year, or pick any two dates.

Narrow to one project with the dropdown. Putting the dates the wrong way round swaps
them rather than complaining.

**The bucket width follows the range.** Up to about two months is plotted by day,
up to a year by week, longer by month — so a chart is always somewhere between a
dozen and sixty points rather than 365 unreadable columns.

## What the numbers mean

| | |
|---|---|
| **Opened** | Issues created in the period |
| **Closed** | Issues that reached a done or cancelled status in the period |
| **Backlog change** | Opened minus closed. Positive means the backlog grew, and is the only one coloured — shrinking or level is not worth shouting about |
| **Typical time to close** | The **median**, not the average |
| **Reports received** | What the widget and the SDKs sent |
| **Duplicate reports absorbed** | Occurrences past the first, on issues seen in the period |

**Median, not mean.** One bug that sat open for eight months drags an average
somewhere nobody recognises, and the question being asked is "how long does a typical
thing take". Three bugs closed in 1h, 2h and 100h report 2h here, not 34h.

**Duplicate reports absorbed** is the number the product is sold on. Forty people
hitting one broken checkout is one issue, and this says how many reports that
collapsing actually took off your hands — and, on the hosted service, did not bill
you for.

## The charts

**Opened and closed** — two bars per bucket. Closed taller than opened means you are
catching up.

**Open issues over time** — the backlog, as a line. It starts from where the backlog
actually stood the instant before the period opened, not from zero, so an issue raised
two years ago and still open is counted on day one of the chart.

**Opened by project**, **open by assignee**, **open by status** — ranked lists, top
ten. Unassigned issues are named as "Unassigned" rather than dropped, because they are
usually the interesting row.

**Open longest** — the oldest open issues. Every tracker accumulates these and no list
view surfaces them, because they are never near the top of anything sorted by
recency.

## Known rough edges

- There is no export from this page. The [issue export](issues.md) and the
  [time export](time-tracking.md) both carry the underlying rows.
- Days are bucketed in the application's timezone, which is UTC unless the instance
  is configured otherwise. For an operator well east or west of it, work late in the
  local evening can land on the following day's bar.
- Closed counts depend on `closed_at`, which is stamped when an issue first reaches a
  done status. Issues closed before this was recorded do not appear in the closed
  figures, though they are correctly absent from the open ones.
- There is nothing here about money. See [Time tracking](time-tracking.md) for hours,
  which is as far as Buggie goes.

## Related pages

- [Time tracking](time-tracking.md) — hours, and what they add up to.
- [Triage](triage.md) — where reports become issues.
