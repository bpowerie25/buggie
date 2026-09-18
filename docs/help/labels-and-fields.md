# Labels, priorities, types and assignees

## Labels

Labels are **workspace-wide**, not per project, so the same `regression` means the
same thing everywhere and a cross-project query can use it. Names are unique within
the workspace.

Manage them at `/labels` — staff only. A label has a name, a colour and an optional
description, and the screen shows how many issues carry each one. Owners and admins
can delete a label; any staff member can create or edit one.

Apply labels from the **Labels** row in the right-hand column of an issue. They are
the one repeatable key in the [query language](query-language.md):
`label:regression label:checkout` means both.

Clients never see the labels screen — the workspace's own vocabulary and its issue
counts say plenty about customers they do not know exist. A client's filter bar offers
only the labels that actually appear on work they can see.

## Priority

Priority is **when we will fix it**.

| Value | Name |
|---|---|
| 0 | No priority |
| 1 | Low |
| 2 | Medium |
| 3 | High |
| 4 | Urgent |

New issues start at *No priority*. The list is ordered by priority descending, then by
most recently updated, so urgent work is at the top of its group.

In a query, either name or number works: `priority:urgent`, `priority:4`,
`no:priority` for issues nobody has ranked.

### Priority is not severity

Severity is *how bad it is when it happens*; priority is *when we will fix it*. A
cosmetic typo on the pricing page is low severity and urgent priority. Conflating them
is the most common source of argument in a tracker.

The data model keeps a separate, nullable `severity` field for this reason, but
nothing in the interface sets or shows it and the query language has no operator for
it. In practice, priority is the only ranking available today.

## Type

Four values: **bug** (the default), **feature**, **task**, **question**. Set on
creation, changed from the **Type** row on the issue page, and filtered with
`type:feature`.

There is no way to add a type. Custom fields and custom types are deliberately absent.

## Assignees

An issue has at most one assignee, who must be a member of the workspace. Set it from
the issue page, from the issue list with `a`, or in bulk.

Being assigned makes you a watcher of that issue and sends you a
[notification](notifications.md).

A project can carry a default assignee, which new issues inherit. The API accepts it;
there is no control for it in project settings, so in practice new issues start
unassigned unless you set one.

Clients cannot assign anything and are not shown the staff list.

In a query: `assignee:@me`, `assignee:sam` (a case-insensitive prefix of the name),
`no:assignee`, `-assignee:sam`. The same values work for `reporter:`, which matches
whoever filed the issue.

## Related pages

- [Issues](issues.md)
- [The issue query language](query-language.md)
- [Statuses and workflow](workflow.md) — the other per-issue field, and the only one
  a project can rename
