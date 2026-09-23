# Statuses and workflow

Statuses belong to a project, and each project's set is yours to rename, recolour,
reorder, extend and prune. Behind every status name sits one of five fixed
**categories**, and that is the part you cannot change.

## The five categories

| Category | Open? | |
|---|---|---|
| `backlog` | yes | Noted, not scheduled |
| `unstarted` | yes | Scheduled, not begun |
| `started` | yes | In hand |
| `done` | no | Finished |
| `canceled` | no | Deliberately not doing it |

An issue is **open** when its status sits in `backlog`, `unstarted` or `started`, and
**closed** otherwise. Nothing in Buggie ever asks what a status is *called* in order
to decide that.

This is what lets one client have a column named "Awaiting client" and another "In
QA" while `is:open` keeps meaning the same thing, the board keeps sorting sensibly,
the reporter portal keeps saying a truthful "open" or "closed", and a reopened issue
keeps being a reopened issue. Bugzilla's `RESOLVED` / `VERIFIED` / `CLOSED` confusion
is what this avoids.

The category also drives the timestamps: moving an issue from an open category to a
closed one records `closed_at` and writes a *closed* event, and `resolved_at` is set
only when the destination is `done` — a cancelled issue is closed but was never
resolved. Moving back the other way clears both and writes a *reopened* event.

## The default six

Unless you [start it from a template](projects.md#starting-from-a-template), every
new project starts with:

| Name | Category | |
|---|---|---|
| Backlog | backlog | |
| Todo | unstarted | the default for new issues |
| In Progress | started | |
| In Review | started | |
| Done | done | |
| Won't Fix | canceled | |

Two statuses sharing a category is normal and expected — "In Progress" and "In
Review" both mean started.

## Editing the workflow

**Project settings → Workflow** (`/projects/{slug}/edit`). Owners and admins only.

You can:

- **Rename** a status. Names must be unique within the project, up to 40 characters.
- **Recolour** it. A six-digit hex colour.
- **Reorder** by dragging. Order drives the column order on the board and the group
  order in the list.
- **Add** a status, choosing its category at that moment.
- **Remove** one.
- **Set the default** — the status new issues start in. Exactly one per project.

## What the editor refuses, and why

**The category cannot be changed after a status is created.** Add a status with the
category you want and move the issues across; the delete flow does that anyway.
Changing a category in place would rewrite the meaning of history — issues that closed
under that status would silently become open again, or the reverse, and the `closed_at`
timestamps already recorded would disagree with the status they were recorded under.
The name is the customer's; the category is the invariant.

**You cannot delete a status that holds issues without saying where they go.** The
delete asks for a replacement status in the same project and moves them. Cascading
them away or leaving them pointing at nothing is how a tracker loses work.

**You cannot delete the last open status.** New issues have to start somewhere.

**You cannot delete the last status at all.**

**A closed status cannot be made the default.** New issues cannot start closed.

Deleting the status that *is* the default promotes another open status — the first by
position — because a project without a default cannot create an issue.

## Deliberately absent

There is no workflow-transition designer, no per-status permissions, and no custom
fields. Any status can move to any other. These are the features that make the older
trackers feel the way they do, and adding them is a decision, not an oversight.

## Related pages

- [Projects](projects.md)
- [Issues](issues.md)
- [The issue query language](query-language.md) — `is:open`, `is:closed`, `is:any`
