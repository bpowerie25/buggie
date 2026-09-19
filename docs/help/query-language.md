# The issue query language

The filter bar is not a query builder. It is one string, and that string is the whole
filter state: the URL you can share, what the filter chips edit, and what a saved view
stores. There is no second set of filter parameters to keep in step with it.

```
is:open project:web assignee:@me -label:wontfix checkout
```

Read as: open issues in the `web` project, assigned to me, not labelled `wontfix`,
matching the text "checkout".

## Shape

A query is a list of whitespace-separated terms. A term is either

- `key:value` — an operator,
- `-key:value` — a negated operator, or
- anything else — free text.

Values containing spaces are quoted: `label:"needs repro"`.

## Operators

| Key | Values | |
|---|---|---|
| `is` | `open`, `closed`, `any` | Defaults to `open`. An unrecognised value falls back to `open` rather than showing nothing. |
| `project` | a project slug | `project:marketing-site` |
| `assignee` | `@me`, `me`, a name, a user id, or `none` | |
| `reporter` | the same values | Who filed it. |
| `version` | a release name | `version:2.4.1`. Names are unique per project, so two projects may each have a 2.4.1. |
| `label` | a label name | Repeatable. |
| `type` | `bug`, `feature`, `task`, `question` | |
| `priority` | `none`, `low`, `medium`, `high`, `urgent`, or `0`–`4` | |
| `no` | `assignee`, `label`, `description`, `priority` | The "needs attention" filters. |

Free text runs against a Postgres full-text index over the title and the flattened
description. Typing an issue key on its own (`WEB-142`) matches that issue directly.

`assignee` and `reporter` resolve a name by case-insensitive prefix, so
`assignee:sam` finds Sam Okonkwo without knowing any ids.

## Rules worth knowing

**Unknown operators become search text.** `sevrity:high broken` searches for the
literal string `sevrity:high broken`. A typo must never silently change what you are
looking at, which is what dropping the term would do.

**An unresolvable name matches nothing, not everything.** `assignee:nobodyhere`
returns an empty list. A filter that quietly does nothing is the dangerous failure.

**Exclusions accumulate; inclusions replace.**

```
-assignee:sam -assignee:jo      neither Sam nor Jo
assignee:sam assignee:jo        Jo — an issue has one assignee, and the chip is a single choice
label:a label:b                 both labels — labels and fields are the repeatable keys
```

**Custom fields** are filtered with a `field:` prefix:

```
field:environment=Production            that value
field:environment=Production field:browser=Safari   both, at once
-field:environment=Staging              anything but
field:client_ref                        the field has some value
```

Values match case-insensitively. The prefix is not decoration: a project may name a
field "type" or "label", and a bare key would shadow the built-in filter. A client can
only filter on fields marked visible to clients — filtering on an internal one matches
nothing, because otherwise its value could be recovered by trying values and watching
the count. See [Custom fields](custom-fields.md).

**Every query has a canonical form.** Operators are written first, in the order
`is`, `project`, `assignee`, `reporter`, `label`, `type`, `priority`, `no`, then the
free text. So

```
checkout -label:wontfix is:open label:a assignee:@me
```

is stored and displayed as

```
is:open assignee:@me label:a -label:wontfix checkout
```

Clicking chips and typing by hand therefore produce identical strings, which is what
makes saved views compare equal rather than quietly duplicating.

## Worked examples

```
                                     open issues (the default)
is:any                               everything, open or closed
is:closed project:shop               closed issues in the shop project
no:assignee                          nobody has picked it up
is:open no:assignee priority:urgent  urgent and unowned
assignee:@me is:open                 my open work
reporter:@me                         things I filed
label:regression -label:wontfix      regressions we have not given up on
type:question                        questions rather than bugs
project:web checkout                 "checkout" in the web project
label:"needs repro"                  a label with a space in its name
priority:0                           the same as priority:none
no:description                       filed with a title and nothing else
```

## Saved views

**Save view** beside the filter bar stores the current query along with the layout
(list or board) and the grouping. Saved views appear in the sidebar and in the command
palette, and open the issue list with all three restored.

A view is private to you unless you tick **Shared**, which makes it a workspace view
that every staff member sees. Shared views can be edited or deleted by any staff
member; a private view only by its owner.

Clients never see shared views — a view named "Globex escalations" names a customer in
the sidebar of a different one. A client sees only views they made themselves.

Views can be created and deleted, but not renamed or reordered from the sidebar.

## Limits

The list returns at most 1000 issues per request and there is no paging. Grouping is
changed through the `group` URL parameter (`&group=assignee`); the current build has
no control for it in the interface.
