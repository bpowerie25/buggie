# Projects

A project is one application, site or product. Issues, statuses, widget keys and
incoming reports all belong to a project; labels and saved views belong to the
workspace as a whole.

Projects live at `/projects`. Only owners and admins can create, edit or delete them.

## Creating a project

**Projects → New project** asks for four things, of which only the name is required.

### Name

Anything up to 120 characters. It can be changed later.

### Issue key

Two to six characters, uppercase letters and digits, starting with a letter: `WEB`,
`SHOP`, `API2`. Issue keys are built from it — `WEB-1`, `WEB-2` — and it must be
unique within the workspace.

Leave it blank and Buggie suggests one from the name: the initials of up to the first
four words for a multi-word name (`Marketing Site` → `MS`, `GAA Website Redesign` →
`GWR`), or the first three characters of a single-word name (`Invoicer` → `INV`). A
collision with an existing project adds a digit.

**The key is fixed once the project exists.** The settings screen shows it disabled,
because every issue key already handed out refers to it.

Issue numbers are gapless and sequential per project, allocated under a row lock, so
two people creating issues at the same moment cannot receive the same number.

### Description

Free text, up to 2000 characters. It appears on the projects list and the dashboard.

### Site URL

Where the application actually runs, for example `https://acme.com`. It must be a
valid URL.

This does one specific job: **it seeds the origin allowlist of widget keys created
afterwards**. From `https://acme.com` Buggie derives `https://acme.com` *and*
`https://www.acme.com`, because a site served at both sends whichever origin the
visitor happened to be on, and allowing only one produces a widget that works for
some of a client's users and not others with nothing on screen to explain it.

Two things worth knowing:

- Changing the site URL later does **not** rewrite the allowlist of keys that already
  exist. They keep their own list, which you can edit directly.
- With no site URL set, a new widget key starts with an empty allowlist, which means
  it accepts reports from **any** origin. That is the only default that can work when
  Buggie does not know where the application runs, and it is stated on the settings
  screen.

See [The reporter widget](widget.md#the-origin-allowlist).

## The project address

Each project also has a slug derived from its name (`Marketing Site` →
`marketing-site`), which is what appears in URLs: `/projects/marketing-site`, and in
the query language as `project:marketing-site`. It is generated once at creation and
is not editable.

## Project settings

`/projects/{slug}/edit`, reachable from the project page. It holds:

- Name, site URL, description.
- **Archive this project** — archived projects drop out of the dashboard, the project
  picker on the new-issue screen and the filter bar, but their issues remain.
- The [workflow editor](workflow.md).
- The [bug reporter widget](widget.md) keys and their settings.
- **Delete project** — a soft delete. The project and its issues disappear from the
  interface and are recoverable only by someone with database access. Only owners and
  admins can delete.

## What is created with a project

Every new project is seeded with six statuses:

| Name | Category |
|---|---|
| Backlog | backlog |
| Todo | unstarted (the default for new issues) |
| In Progress | started |
| In Review | started |
| Done | done |
| Won't Fix | canceled |

Rename, recolour, reorder, add and remove them freely — see
[Statuses and workflow](workflow.md).

A project also gets a random inbound email token at creation, used for
[filing issues by email](email.md). No widget key is created automatically; you add
one from project settings.

## Related pages

- [The reporter widget](widget.md)
- [Issues](issues.md)
- [Clients](clients.md) — granting a client access to a project
