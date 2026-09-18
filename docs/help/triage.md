# The triage inbox

Everything the [widget](widget.md) and the [native SDKs](native-sdks.md) send lands in
`reports`, and reports are shown at `/inbox`. **Reports are not issues.** A tracker
dies when its backlog fills with junk, and the inbox is the airlock.

The inbox is staff-only. A client never sees it, and the badge that carries its count
is not rendered for them, because even the existence of an inbox is not their
business. Reports hold raw, unreviewed context — a screenshot of whatever was on
somebody's screen at the time.

## The screen

Left: one row per report, oldest first, up to 200. Each row shows the screenshot
thumbnail, the title, the project key, the reporter's name or address (or
"Anonymous"), and how long ago it arrived.

Right: the selected report in full — the reporter's own words, the screenshot, the
error message, the page address, the browser, and a collapsible panel with the
console output and the network table, with failing statuses picked out.

A filter in the top right restricts the list to one project (`/inbox?project={slug}`).

Clearing the inbox is meant to be a keyboard job:

| Key | |
|---|---|
| `j` / `k` (or arrows) | Move between reports |
| `Enter` | Expand the console and network panel |
| `a` | Accept — turns it into an issue |
| `m` | Merge into an existing issue |
| `s` | Spam |
| `x` | Discard |
| `Escape` | Close the merge field or the expanded panel |

Every one of them is also a button.

## Fingerprinting and grouping

When a report arrives, a background worker computes a fingerprint from three things
that stay stable across many reports of one bug and differ between genuinely different
bugs:

```
sha1( normalised error message | first application stack frame | route pattern )
```

- The **message** has its varying parts removed: numbers, UUIDs, hexadecimal, quoted
  strings and URLs become placeholders.
- The **stack frame** is the first one that is not in `node_modules`, `vendor`, a
  browser extension, or a CDN — where the bug is, rather than where it surfaced. The
  build hash is stripped from the filename, so a deploy does not split a group.
- The **route pattern** collapses identifiers: `/orders/8412/checkout` becomes
  `/orders/:id/checkout`.

Reports sharing a fingerprint collapse into **one inbox row with a count**, and acting
on that row acts on the whole group. The list of siblings is re-fetched and re-checked
server-side, never trusted from the request.

**A report with no error attached gets no fingerprint.** Human prose is not reliably
comparable, and merging two people's different problems is worse than showing two
inbox rows, so hand-written reports always reach a person individually.

### Automatic grouping onto an existing issue

If an issue in the same project already carries that fingerprint and is open, the
report never reaches the inbox at all: it is marked merged, the issue's occurrence
count goes up, `last_seen_at` moves, and an *occurrence* event is recorded on the
issue. Forty people hitting one broken checkout give you one issue with a count of
forty rather than forty rows to dismiss.

If the matching issue is **closed**, it is reopened — but only when it was closed less
than **14 days** ago, in which case the issue moves to the project's first open status
and gets a *reopened* event. Closed longer ago than that, the report stays in the
inbox so a person can decide whether it is a regression worth linking. Quietly
reviving month-old history hides a real regression.

The window is a fixed 14 days and is not configurable.

## The four decisions

Each one ends with the report out of the inbox, because an inbox that cannot be
emptied is a second backlog.

### Accept (`a`)

Creates an issue in the report's project. The issue gets:

- The report's title, unless you supply another.
- A description built from the reporter's words, the error message, and a line
  recording where it was reported from and by whom.
- The report's fingerprint and captured environment, so later reports of the same bug
  group onto it automatically.
- The screenshot, as an attachment.
- Priority *none*, type *bug* and no assignee, unless you choose otherwise.

**Accept as…** beside the button opens a menu to set a priority, a type or an
assignee in the same action.

Visibility follows the reporter: a report that carried an email address becomes an
issue **visible to clients**, because the person asked; an anonymous report becomes an
internal issue.

If the reporter left an email address, they are emailed a private
[portal link](clients.md#the-reporter-portal) to follow their own bug. An anonymous
report gets no email — writing to somebody who never left an address is not a feature.

The rest of the fingerprint group is merged into the new issue.

### Merge (`m`)

Asks for an issue key (`WEB-142`) and folds the report into it: the occurrence count
goes up, `last_seen_at` moves, an *occurrence* event is recorded noting that a person
did it by hand, and the screenshot is attached. If the target issue had no
fingerprint, it adopts the report's, so later reports of this bug group themselves.

An unknown key is refused with an error rather than silently doing nothing, and a key
belonging to another workspace is an unknown key.

### Spam (`s`)

Marks the report as spam and removes it from the inbox. In this build that is all it
does: nothing is trained, and the reporter's address or IP hash is not blocked or
throttled as a result.

### Discard (`x`)

Marks the report as discarded and removes it from the inbox.

Spam and discarded reports are deleted outright 30 days after triage — see
[Privacy and security](privacy-and-security.md#retention). Accepted and merged reports
are kept.

## Things it does not do yet

- There is no reply-to-reporter action from the inbox. The reporter gets a portal link
  when a named report is accepted, and the conversation happens there.
- There is no "split from group" for a fingerprint that has over-grouped two genuinely
  different bugs.
- Screenshots are stored on the application's own disk and are served only through
  Buggie, with an authorisation check on every read.

## If the inbox stays empty

Reports arrive but never appear grouped, never notify and never fingerprint if the
**queue worker is not running**. Ingest still answers `202` and the rows are still
written, so nothing surfaces the problem. On a self-hosted install the worker runs
inside the container under a process supervisor; in development it is a separate
compose service. See [Self-hosting](self-hosting.md#the-queue-worker-and-the-scheduler).

## Related pages

- [The reporter widget](widget.md)
- [Issues](issues.md)
- [Clients](clients.md)
