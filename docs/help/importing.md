# Importing from another tracker

Nobody moves tracker without their history, so Buggie reads a CSV export from Jira or
MantisBT — or a spreadsheet you have been keeping yourself.

**Project settings → Import from another tracker.**

## What happens

Upload the file and you get a **preview**: which tracker it looks like, how many rows,
which columns were understood, which were ignored, and the first few issues exactly as
they would be created. Nothing is created until you say so.

Importing then runs in the background. When it finishes you get a count and a list of
anything worth a look.

## Getting the file out

**Jira** — Issues → export → *Export Excel CSV (all fields)*, or the current fields.
Buggie looks for `Issue key`, `Summary`, `Description`, `Status`, `Priority`,
`Issue Type`, `Assignee`, `Reporter` and `Created`.

**MantisBT** — View Issues → *CSV Export*. Buggie looks for `Id`, `Summary`,
`Description`, `Status`, `Priority` or `Severity`, `Category`, `Assigned To`,
`Reporter` and `Date Submitted`.

**Anything else** — any CSV with a column called Title, Summary or Subject. Columns
named Description, Status, Priority, Type, Assignee and Created are used if present.

## What it does with the values

**Statuses** are matched by name against the project's own. A status that does not
match but sounds finished — closed, resolved, done, fixed, complete, verified — goes to
a done status rather than the default one. Importing a decade of fixed bugs as open
work is the single most annoying thing an import can do.

**Priorities** are mapped by meaning: blocker, immediate, critical and highest all
become Urgent; major becomes High; minor and trivial become Low.

**Assignees** are matched on email address, then on exact name, against people already
in the workspace. Never fuzzily — a wrong assignee is worse than none — and anyone who
could not be matched is listed afterwards.

**Dates** are preserved where the export has them. A backlog that all arrived today has
lost the thing that made it a history.

## Running it twice

Safe. Each issue remembers the key it came from, so a second run of the same file
creates nothing and reports the rows it skipped. You will want this, because the first
attempt always looks like it half worked.

## Limits

20,000 rows and 20MB per file. Larger exports can be split. The uploaded file is
deleted once the import finishes — it is your bug history and it has done its job.

Comments and attachments are **not** imported. The description, status, priority, type,
assignee and dates are.
