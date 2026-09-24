# Workload

**Workload** (staff only, in the sidebar) shows who has how much work on, week by week,
across every project. A person's week covers all their projects: 60% on one client and
70% on another is overbooked, and neither project on its own would show it. Pick a
project at the top to see only its share.

## Setting it up

On **Members**, give each member of staff:

- **Hours a week:** what they have available, e.g. 37.5, or 15 for someone part-time.
  Blank means "not set". Their weeks are shown without colour rather than judged
  against a number nobody chose.
- **A discipline,** chosen from the workspace's list. The grid groups people by it,
  in the list's order.

Owners and admins set these. Clients have neither and never appear on the screen.

**The list of disciplines** is under **Disciplines** on the same Members screen. It
starts as Developer, Designer, Project manager, QA and Content. Add, rename, reorder
or remove entries there. Renaming one renames it for everyone who has it. Removing one
leaves those people with no discipline rather than a name that's no longer on the list.

## Holidays and leave

The **Time off** panel on the Workload screen lists upcoming leave and public holidays.

- **Leave:** anyone on the staff can book their own. Owners and admins can book it for
  anyone.
- **Public holidays:** owners and admins choose *Everyone: public holiday*. A holiday
  takes that day from everybody.

Days off are whole days, inclusive at both ends, and they do two things:

- **They reduce that week's hours.** Three days' leave in a 20-hour week leaves 8 hours.
  The cell shows "3d off", or "Off" for a whole week away.
- **No work is planned onto them.** An issue's remaining hours are spread only over the
  days its assignee is in. Work due on a holiday, or entirely inside somebody's leave,
  moves to their next working day.

Hours available under *Estimates against actuals* also leave out days off.

## What a week's number is

Each open issue that is assigned, estimated and dated contributes **what is left of its
estimate**: the estimate minus the time already logged on it, never below zero. That
amount is spread evenly over the working days from its start date to its due date.

- Weekends, public holidays and the person's own leave take nothing. Work dated only on
  days off goes on their next working day.
- An issue with one date sits on that day.
- Open work whose dates have passed counts from **today**, because that is when it is
  actually being done.

A cell is **amber** at 85% of the person's hours and **red** over 100%. Click a cell to
see the issues behind it.

## What it cannot place

Open work with **no estimate** or **no dates** has no honest place in a week. It is
counted beside each person instead, with links to the filters `no:estimate` and
`no:dates`, so a plan with gaps looks like one rather than looking light.

Estimated, dated work with **nobody assigned** has its own row at the bottom.

## Estimates against actuals

Below the grid, for the last 30, 90, 180 or 365 days:

- **Finished / Estimated / Took:** for the issues each person finished in that period
  that had an estimate, the estimates added up against every hour logged on those issues.
  "20% over" means the work took a fifth longer than estimated.
- **Logged / available:** their own time entries in the period against their weekly
  hours times the number of weeks.

See [Time tracking](time-tracking.md) for logging hours and setting estimates.
