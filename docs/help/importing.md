# Importing and updating issues

Buggie reads a CSV exported from Jira, MantisBT or Buggie itself, or a spreadsheet you
have been keeping yourself. It can create new issues, and update the ones already here.

**Import issues** is on each project's page, and **Import** is next to Export on the
issue list. Anyone on the staff can use it, so a developer bringing in a client's
spreadsheet doesn't need an admin. Clients can't.

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
named Description, Status, Priority, Type, Assignee, Start, Due, Estimate, Phase,
Parent and Created are used if present.

**Starting from nothing** — choose **Download a template** on the Import page. It is a
CSV (Excel, Numbers and Google Sheets all open it) with exactly the columns Buggie
reads, and three example rows written in *this* project's words — its own status
names, not generic ones. Fill in a row per issue, keep the header row, and save as
CSV. The example rows are keyed `EXAMPLE-1` to `EXAMPLE-3`, and any row keyed
`EXAMPLE-…` is skipped, so leaving them in does no harm. Only Title is required; the
card lists the values each column accepts.

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
lost the thing that made it a history. Write them as `2026-10-14`, or `14/10/2026`,
which is read **day first**.

**Estimate** is in hours: `4`, `2.5`, or with units, `3h 30m`.

**Phase** is matched by name against the project's phases. A name that isn't there yet
creates that phase.

**Parent** makes the row a subtask. Give an existing issue's key (`WEB-12`), or another
row's Key in the same file, even one further down. Subtasks are one level deep, and a
parent that would break that is left out and listed afterwards.

## Updating issues that are already here

A row matches an existing issue when its Key is that issue's key (`WEB-12`), or the
key an earlier import brought it in under. The preview counts the matching rows and
asks what to do with them:

- **Skip them.** This is the default, and makes running the same file twice safe.
- **Update them from the file.** Only **filled-in cells** change anything. A blank
  cell leaves that field as it is, so a sheet with an empty Assignee column doesn't
  unassign anyone. Each change goes into the issue's activity under the name of
  whoever ran the import, and notifies people as if it had been made by hand.

The preview shows, for the first few matching rows, which fields would change.

**The quickest way to change many issues at once:** filter the issue list, **Export**
it, edit the CSV, and import it back with *Update them from the file*. The export's
columns are read back under the same names, and only what you edited changes.

## Running it twice

Safe, unless you choose to update. Each issue remembers the key it came from, so a
second run of the same file creates nothing and reports the rows it skipped.

## Limits

20,000 rows and 20MB per file. Larger exports can be split. The uploaded file is
deleted once the import finishes — it is your bug history and it has done its job.

Comments and attachments are **not** imported. The title, description, status,
priority, type, assignee, dates, estimate, phase and parent are.
