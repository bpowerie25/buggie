# Time tracking

Log how long something took, against the issue it was spent on, and get a total per
person and per project for any period.

**Time is staff-only.** There is deliberately no "clients can see time" switch. How
long something took is an input to an invoice, not a status update — an agency that
wants a client to see hours sends them an invoice. A client never sees an issue's
time, cannot reach the Time report, and their API token does not return the field at
all.

## Logging it

On any issue, under **Time**. Four things: how long, which day, an optional note, and
whether it is billable.

**Billable is ticked by default.** It is the common case for an agency, and the entry
that should not be billed is the one somebody will remember to untick.

**The day is the day the work happened**, not the day you logged it — Friday
afternoon's work is often entered on Monday. A future date is refused: work that has
not happened is a plan, and a future date falls outside every month-end report.

### How to write a duration

| You type | It means |
|---|---|
| `1h 30m`, `1h30m`, `1h30` | 90 minutes |
| `1.5h` (or `1,5h`) | 90 minutes |
| `90m` | 90 minutes |
| `2:30` | 150 minutes |
| `90` | **90 minutes** |

A bare number is minutes. That is genuinely ambiguous — somebody typing `2` probably
means two hours — which is why the box shows what it understood, right underneath,
before anything is saved. If it says *"= 2m"* and you meant two hours, you will see it.

One entry cannot be longer than 24 hours. An entry that long is a typo, not a day.

## Estimates

Set an estimate on the issue and it appears beside the total: *1h 30m of 2h*. Once
the logged time passes it, the overrun is shown and nothing else changes — Buggie
does not block work for being over an estimate.

Clearing the estimate is not the same as setting it to zero. **No estimate** and
**estimated at nothing** are different claims, and the field keeps them apart.

## The Time report

**Time** in the sidebar. Filter by date range, person, project, and billable or not.
The default period is the current calendar month, which is what an agency reports on.

It gives you the total, the billable total, a breakdown by person and by project, and
the individual entries.

**The totals are counted in the database, not from the rows on screen.** The list
shows the most recent 500 entries; the totals cover everything that matched. A total
quietly smaller than the truth would be the worst possible bug on a page somebody
invoices from.

## Minutes, not decimal hours

Time is stored as whole minutes everywhere. Twenty minutes is 0.333… hours, so a
column of decimals does not add up to what a calculator gives, and an invoice built on
it is wrong by pence a row.

Hours appear only on the way out: the CSV export carries **both** `minutes` and
`hours` columns, so whoever does the invoicing does not have to do the conversion — or
argue about the rounding.

## What happens when somebody leaves

Their entries stay. The hours were still worked and may already be on an invoice;
cascading the delete would silently change last quarter's numbers. The entries read as
*"Someone who has left"* and keep counting towards totals.

## Who can delete what

Your own entry is yours to remove. Removing somebody else's needs the authority that
manages the workspace, because deleting logged hours changes what a person is paid
for.

## In the export and the API

The issue CSV gains `estimate` and `time_spent` columns, in hours, for staff. The Time
report has its own export with one row per entry.

The API returns `estimate_minutes` and `time_spent_minutes` on an issue **for staff
tokens only**. A client's token gets the keys absent rather than zeroed: "no time
logged" and "you may not see the time" are different answers.

## Known rough edges

- There is no timer — you enter a duration, you do not start and stop a clock.
- Entries cannot be edited, only removed and re-entered.
- There is no per-person or per-project rate, so the report gives hours and not money.
- Estimates are per issue; there is no roll-up to a release or a project.

## Related pages

- [Issues](issues.md) — everything else on an issue.
- [Custom fields](custom-fields.md) — if you need to record something else per issue.
