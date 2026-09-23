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

### Start from

What the project is set up with: its statuses, the labels it expects to exist, and
its custom fields. See **Starting from a template**, below.

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

## Starting from a template

An agency running twenty client sites sets the same things up twenty times: the same
statuses, the same two or three labels, the same "Client reference" field. **Start
from** on the new-project form does it once.

It covers three things, and only these three: **statuses**, **labels** and **custom
field definitions**. Everything else about the project — its name, key, site URL,
branding, widget keys — is its own.

### The built-in templates

| Template | What it is for |
|---|---|
| **Standard** | The six statuses every project has always started with. No labels, no fields. This is what you get if you choose nothing. |
| **Client website build** | A fixed-scope build with a sign-off step. Work passes internal QA, then sits in *Awaiting client* until somebody says yes. |
| **Ongoing support** | A live site on a retainer. Everything arrives in *Triage* and is scheduled, resolved or declined. Carries a **Billable** checkbox. |
| **Internal product** | Your own work. An icebox, a sized queue, a review step, and no waiting-on-client status because there is no client. |

They live in `config/templates.php`, which means a self-hosted install has them
without anything being seeded, and editing or adding one is a change to a file rather
than something somebody did once in a database. The file explains its own rules.

Every template's statuses map to the same five [categories](workflow.md) as any other
status — a template may call a column anything, but `is:open` keeps meaning what it
means.

**A template's custom fields are always internal.** A field reaches a client because
you decided it should, on the project, and never because of a list picked in a hurry
while creating one. Share it afterwards from project settings.

**Labels are workspace-wide**, so a template creates the ones that do not exist yet
and leaves the ones that do exactly as they are, colour and all. If you already have
a "Regression" label, it stays yours.

### Copying an existing project

The more useful of the two, and the reason this exists: *make it like Acme's*.

Pick **Copy an existing project** and choose one. The new project gets that project's
statuses exactly — names, colours, order, which one is the default, any WIP limit —
and its custom field definitions, keeping their keys so a saved view filtering on
`field:client_ref` works on the new project too.

What is **not** copied, none of it an oversight:

- **Issues, comments and attachments.** This is a new project, not a fork of the work
  in an old one.
- **Custom field values.** The definitions come across; what was filled in on
  somebody else's issues does not.
- **Client access.** A client who holds the project you copied does **not** get the
  new one. Grant it deliberately, the way you granted the first.
- **Widget keys.** A key is a credential with its own origin allowlist. Add one from
  project settings when the new project is ready to receive reports.
- **Versions, webhooks, branding and the inbound email address.**

You can only copy from a project in the workspace you are in. Archived projects are
left out of the list.

### Known rough edges

- **A template applies at creation and never again.** Editing `config/templates.php`,
  or changing the project you copied from, does nothing to projects that already
  exist — they own their statuses and fields from the moment they are created. This
  is deliberate: a template that reached back into live projects would rename columns
  and delete fields on work in progress. If you want an existing project changed,
  change it in [project settings](workflow.md).
- **There is no "save this project as a template".** Copying an existing project is
  the same thing without the extra concept, and a template saved from a project would
  be a third copy of a workflow, free to drift from both.
- **A template cannot pre-fill anything a client can see.** By design, but it does
  mean a field that is genuinely meant to be shared is two steps rather than one.

## What is created with a project

With no template chosen, every new project is seeded with six statuses:

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
