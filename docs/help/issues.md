# Issues

An issue is the unit of work. Every issue belongs to one project and carries a key
built from the project key and a per-project sequence number: `WEB-1`, `WEB-2`,
`SHOP-118`. Keys are unique across the workspace, so issues live at the top level:

```
/issues                 the list
/issues/create          new issue
/issues/WEB-142         one issue
```

## Creating an issue

**New issue**, the `c` key anywhere, or **New issue** in the command palette.

The form has: project, type, priority, visibility, title, description and starting
status. Only the title is required; type defaults to *Bug*, priority to *No
priority*, visibility to *Internal only* and the status to the project's default.

Changing the project reloads the page, because statuses belong to a project and the
starting-status list has to come from the right one.

An issue created by a **client** is forced to *visible to client* and left
unassigned, whatever the form posted. Otherwise a client could file a report and
immediately lose sight of it.

Issues can also arrive by [email](email.md) and out of the
[triage inbox](triage.md).

## The list

`/issues` shows open issues by default, grouped by status, with a filter bar at the
top. The URL carries the whole state:

```
/issues?q=is%3Aopen+assignee%3A%40me&layout=list&group=status
```

| Parameter | |
|---|---|
| `q` | The [query](query-language.md). Empty means open issues. |
| `layout` | `list` or `board`. Anything else is read as `list`. |
| `group` | `status`, `assignee`, `priority` or `project`. Anything else is read as `status`. |

The list is virtualised, so it stays smooth when a group is large, and is capped at
1000 rows per request. There is no paging; narrow the query instead.

Group headings are collapsible. When the query spans projects, grouping by status
merges columns by status *name*, so two projects' "In Progress" columns appear as one
group — which is what you want when looking across projects.

### Editing from the list

Status, assignee and priority can be changed from a row without navigating: press
`e`, `a` or `p` on the highlighted row, or click the corresponding cell. The row
repaints immediately and reverts if the request fails.

`x` selects the highlighted row (one at a time; there is no shift-click range
selection). With a selection, a bulk bar appears offering **status**, **assignee** and
**priority**, applied to up to 200 issues at once. Every issue in a bulk edit is
authorised individually, and **there is no undo** — a bulk edit that touches 200 rows
is 200 separate changes in the activity feeds.

Full list of keys: [Keyboard shortcuts](keyboard.md).

## The board

`layout=board` draws a kanban board, dragging cards between columns. As on the list,
columns are grouped by status *name*, and a drop resolves to the status with that name
**in the dropped issue's own project**. A project with no status of that name cannot
accept the drop. Dragging needs about 4 pixels of movement, so a click still opens the
issue.

Clients cannot drag: changing state is staff work.

## The issue page

Two columns.

**Left:** the title (click to edit), the description (rich text, click to edit), the
attachments, then a single chronological stream that interleaves comments with typed
activity events — created, status changed, assigned, priority changed, title changed,
label added or removed, visibility changed, related, reopened, closed, another
occurrence recorded, attachment added.

**Right:** status, assignee, priority, type, labels, visibility, any linked issues,
and the reporter.

### Comments

The composer is rich text. `⌘Enter` (or `Ctrl+Enter`) posts.

For staff the composer **defaults to an internal note**, marked with an amber border
and a lock. Switching it to *Visible to client* shows a warning line underneath
before you post. This is the mistake worth designing against: telling a client
something that was meant for the team. The toggle resets to internal each time the
page loads.

Clients see no toggle; their comments are always public. A client cannot post an
internal note even by crafting the request.

You can edit and delete your own comments. Owners and admins can delete anyone's.

### Attachments

Drag files onto the issue, paste them, or use the file picker. Allowed types are PNG,
JPEG, GIF, WebP, PDF, plain text, CSV, JSON and ZIP, up to 10 MB each.

**SVG is deliberately refused.** It is an XML document that can carry script, and
attachments are served from Buggie's own origin.

Every download is checked against the issue's own visibility, because these files
routinely hold a customer's production data. Images render inline; everything else is
forced to download.

### Linking issues

Issues can be linked as *blocks*, *blocked by*, *relates to* or *duplicates*, and the
inverse link is written on the other issue automatically. Existing links appear under
**Linked** in the right-hand column.

There is currently no control in the interface for adding or removing a link; the
endpoints exist (`POST` and `DELETE` on `/issues/{key}/relations`, taking a `key` and
a `type`) but nothing on the issue page calls them. Links created another way display
correctly.

### Visibility

**Visibility** in the right-hand column flips an issue between *Internal only* and
*Client can see this*. It is per issue, not per project — holding the project is not
enough for a client to see the work in it. See [Clients](clients.md).

Deleting an issue is a soft delete and requires owner or admin.

## Watchers

You are added as a watcher of an issue when you report it, are assigned it, comment
on it, or are mentioned in it. Watchers are what [notifications](notifications.md)
are sent to. There is no button to watch or unwatch by hand, and the watcher list is
not shown on the issue page.

## Related pages

- [The issue query language](query-language.md)
- [Statuses and workflow](workflow.md)
- [Labels, priorities, types and assignees](labels-and-fields.md)
- [Keyboard shortcuts](keyboard.md)
