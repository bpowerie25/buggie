# Buggie — Design Spec

Status: draft v1 · 2026-09-18

---

## 1. Thesis

The issue list is a commodity. Mantis, Bugzilla, Jira, Plane and Linear all store a
title, a status and some comments — that part is not worth building.

What none of them do well is **intake**. A bug report is useful in proportion to how
much context arrives with it, and today that context is reconstructed by hand:
"what browser?", "can you send a screenshot?", "what were you doing?", "does it still
happen?". Most of a maintainer's time goes into turning a bad report into a good one.

Buggie's job is to make the report arrive good. A `<script>` tag in a client app captures
the screenshot, the console, the failing request, the route, the release and the logged-in
user at the moment the reporter clicks "report a bug". Everything else in the product
exists to keep that firehose organised.

Two consequences shape the whole design:

1. **Reports are not issues.** Raw intake lands in a per-project **Triage inbox**, never
   directly in the backlog. A tracker dies when the backlog fills with junk; the inbox is
   the airlock.
2. **Identical reports collapse.** Incoming reports are fingerprinted and grouped. Forty
   users hitting the same broken checkout produce one issue with `occurrence_count = 40`,
   not forty tickets.

### What we are deliberately not doing in v1

Sprints, story points, burndown charts, time tracking, Gantt, a workflow designer, custom
field builders, a plugin API. Every one of these is why Mantis and Bugzilla feel the way
they do. Ship without them; add only the ones that get asked for twice.

---

## 2. Actors

| Actor | Scope | Can |
|---|---|---|
| **Owner** | Workspace | Everything, incl. billing and deletion |
| **Admin** | Workspace | Manage projects, members, widget keys, statuses, labels |
| **Member** (staff) | Workspace | All projects; triage, comment internally, change status |
| **Client** | Specific projects only | See `visibility = client` issues in their projects, comment publicly, file new reports. Never sees internal notes, other clients' projects, or assignees' private discussion |
| **Reporter** (end user) | None — unauthenticated | Submit via the widget. Optionally gets a magic link to follow their own report's status |

`Client` is the role that most trackers get wrong. It is not "member with fewer buttons" —
it is a separate visibility plane. Every query that can reach a client passes through a
scope that filters on `visibility` and `is_internal`. Get this right once, in a global
scope and a policy, not in 40 controllers.

---

## 3. Tenancy

**Single database, `workspace_id` on every tenant-owned table.** No database-per-tenant;
at your scale it buys nothing and costs migrations, connection pooling and backup pain.

- Resolution: subdomain — `acme.buggie.eu`. A `ResolveWorkspace` middleware looks up
  `workspaces.slug`, aborts 404 on miss, and binds the model into the container.
- Isolation: a `BelongsToWorkspace` trait adds a global scope reading the bound workspace,
  and an `creating` hook that stamps `workspace_id`. Models opt in; nothing opts out
  silently.
- Guard rail: a test that boots each tenant model, runs `Model::query()->toSql()`, and
  asserts `workspace_id` appears in the where clause. Cheap, catches the one mistake that
  leaks customer data between accounts.
- Billing: `laravel/cashier` on the `Workspace` model (not `User` — users belong to many
  workspaces).

---

## 4. Data model

Postgres, not MySQL. Three reasons that matter here: `jsonb` with GIN indexes for widget
payloads and saved-view filters, `tsvector` for search without standing up Meilisearch,
and partial indexes for the "open issues only" queries that dominate every screen.

```
workspaces
  id, name, slug (unique, subdomain), settings jsonb,
  trial_ends_at, stripe_id, pm_type, pm_last_four, timestamps

users
  id, name, email (unique), password, avatar_path, timezone, timestamps
  -- global identity; a user may belong to several workspaces

workspace_user
  id, workspace_id, user_id, role enum(owner|admin|member|client),
  invited_by_id, invited_at, joined_at
  unique(workspace_id, user_id)

projects
  id, workspace_id, name, key varchar(6) (e.g. "WEB"), slug,
  description, issue_sequence int default 0, default_assignee_id,
  is_archived bool, settings jsonb, timestamps
  unique(workspace_id, key)

project_user                       -- only needed to scope clients to projects
  project_id, user_id, role enum(maintainer|contributor|client)

statuses
  id, project_id, name, category enum(backlog|unstarted|started|done|canceled),
  color, position smallint, is_default bool
  -- Seeded per project: Backlog, Todo, In Progress, In Review, Done, Won't Fix

labels
  id, workspace_id, name, color, description
  unique(workspace_id, name)                -- workspace-wide, not per project

issues
  id, workspace_id, project_id,
  number int, key varchar (stored, "WEB-142"),
  title, description jsonb (tiptap doc), description_text text (for search),
  type enum(bug|feature|task|question) default bug,
  status_id, priority smallint (0 none … 4 urgent), severity smallint null,
  reporter_id null, assignee_id null,
  visibility enum(internal|client) default internal,
  fingerprint varchar null, occurrence_count int default 1,
  duplicate_of_id null,
  environment jsonb,                        -- browser/os/url/viewport/release/user
  first_seen_at, last_seen_at, resolved_at, closed_at, due_on,
  search_vector tsvector generated,
  timestamps, deleted_at
  unique(project_id, number)
  index(workspace_id, status_id), index(assignee_id), index(fingerprint)
  GIN(search_vector), GIN(environment)
  partial index on open issues: WHERE deleted_at IS NULL AND closed_at IS NULL

issue_label            issue_id, label_id

comments
  id, issue_id, user_id null, body jsonb, body_text text,
  is_internal bool default false,
  source enum(web|email|widget) default web,
  edited_at, timestamps, deleted_at

attachments
  id, workspace_id, attachable_type, attachable_id,
  disk, path, thumb_path, filename, mime, size, width, height,
  uploaded_by_id, timestamps

issue_events                                -- typed activity feed
  id, issue_id, user_id null,
  type enum(created|status_changed|assigned|priority_changed|label_added|
            label_removed|commented|merged|reopened|occurrence|attachment_added|
            visibility_changed|related),
  data jsonb, created_at
  index(issue_id, created_at)

issue_relations
  id, issue_id, related_issue_id,
  type enum(blocks|blocked_by|relates_to|duplicates)

issue_watchers
  issue_id, user_id, reason enum(assigned|mentioned|commented|manual)

saved_views
  id, workspace_id, project_id null, user_id null,   -- null user = shared
  name, filters jsonb, group_by, order_by, layout enum(list|board), position

widget_keys
  id, project_id, public_key (unique), allowed_origins jsonb,
  mode enum(anonymous|identified), require_email bool,
  is_active, last_used_at, timestamps

reports                                     -- raw intake, pre-triage
  id, workspace_id, project_id, widget_key_id,
  title, body text, reporter_name, reporter_email, reporter_ref,
  environment jsonb, console jsonb, network jsonb,
  screenshot_path, fingerprint,
  state enum(new|promoted|merged|spam|discarded),
  issue_id null, ip_hash, timestamps
  index(project_id, state, created_at)
```

### Three schema decisions worth defending

**`statuses.category`.** Statuses are per-project and renameable, but every one maps to a
fixed category. This is the trick that lets "is this issue open?" stay a reliable query
while customers still call their column "Ready for QA". Bugzilla's `RESOLVED` vs `VERIFIED`
vs `CLOSED` confusion is the cost of not having this.

**`priority` separate from `severity`.** Severity is how bad it is when it happens.
Priority is when we will fix it. A cosmetic typo on the pricing page is low severity,
urgent priority. Conflating them is the single most common source of arguments in a
tracker. `severity` is nullable — most issues never need it.

**A dedicated `issue_events` table rather than `spatie/laravel-activitylog`.** The activity
feed is a primary UI surface here, not an audit trail. Typed events with a known `data`
shape render cleanly and query fast; generic activity logs end up needing a switch
statement over diffed attributes anyway.

### Issue key generation

`WEB-142` must be gapless and unique per project under concurrency. Do it in a transaction
with a row lock on the project — not `MAX(number)+1`, not a UUID:

```php
$issue = DB::transaction(function () use ($project, $attrs) {
    $number = DB::table('projects')->where('id', $project->id)
        ->lockForUpdate()->value('issue_sequence') + 1;

    DB::table('projects')->where('id', $project->id)
        ->update(['issue_sequence' => $number]);

    return Issue::create($attrs + [
        'number' => $number,
        'key'    => "{$project->key}-{$number}",
    ]);
});
```

---

## 5. The reporter widget

This is the product. Everything else is table stakes.

### Install

```html
<script src="https://cdn.buggie.eu/w/pk_live_9f3a2b.js" async></script>
```

Optionally identify the current user so reports arrive attributed:

```js
window.buggie?.identify({ id: 4821, email: 'a@b.com', name: 'Ann', plan: 'pro' });
window.buggie?.setRelease('2026.09.18-a1c3');
```

### What it captures

| | |
|---|---|
| **Screenshot** | `html2canvas` of the viewport, downscaled to 1600px, JPEG q0.8. Annotatable before send — draw a red box, blur a region. |
| **Console** | Ring buffer of the last 50 entries. `console.*` patched at load; original always called through. |
| **Network** | Last 30 `fetch`/XHR calls: method, URL, status, duration. **Headers and bodies are not captured.** |
| **Errors** | `window.onerror` + `unhandledrejection`, with stack. |
| **Environment** | UA, OS, viewport, DPR, locale, timezone, URL, referrer, release, session duration. |
| **Identity** | Whatever `identify()` was given. Nothing more. |

### Redaction — non-negotiable

The widget runs inside *customers'* apps, on *their users'* screens. It must be boring
and safe:

- Never capture `input[type=password]`, `[data-buggie-redact]`, or anything inside
  `.buggie-redact` — blanked on the canvas before encoding.
- Never read `document.cookie`, `localStorage`, or request/response bodies.
- Strip query strings matching `/token|key|secret|password|auth|session/i` from every
  captured URL before it leaves the page.
- Screenshot capture is opt-out per key, and the reporter always sees the image they are
  about to send, with the annotate/blur tools, before it uploads.

Document all of this publicly. It is the first thing a client's security reviewer will ask.

### Ingest

```
POST /api/ingest/{public_key}
```

Unauthenticated by necessity — the key is in page source and is public. So it is treated
as hostile input:

- **Origin allowlist** per `widget_keys.allowed_origins`; reject on mismatch.
- **Rate limits:** 5/min per IP per key, 200/hour per key. 429 with `Retry-After`.
- **Size caps:** 32KB JSON body, 2MB screenshot.
- Screenshot goes to S3 via a **presigned PUT** issued by the ingest response, so large
  binaries never pass through PHP.
- `hCaptcha` required when `mode = anonymous` and the key trips its hourly rate.
- Store `ip_hash` (HMAC, not raw IP), never the IP itself.

Ingest writes a `reports` row and dispatches `ProcessIncomingReport` to the queue. The HTTP
response is a 202 with a report reference. Nothing synchronous, ever — the client app's
user is waiting.

### Fingerprinting and grouping

```
fingerprint = sha1(
    normalize(error.message)      // numbers, UUIDs, hex → placeholders
  + '|' + topAppStackFrame        // first frame not in node_modules/vendor
  + '|' + routePattern            // /orders/1234 → /orders/:id
)
```

If a non-closed issue in the same project already carries that fingerprint, the worker
increments `occurrence_count`, bumps `last_seen_at`, appends an `occurrence` event, and
attaches the new screenshot — instead of creating anything. Human-written reports with no
error attached get no fingerprint and always land in the inbox individually.

Reopen rule: an occurrence arriving against an issue closed **less than 14 days ago**
reopens it (`reopened` event, notify last assignee). Older than that, file fresh and link
`relates_to` — a regression a month later is a different bug.

---

## 6. Triage inbox

Per project, keyboard-driven, built to be emptied daily. Each row: screenshot thumbnail,
title, reporter, occurrence count, route, time.

| Key | Action |
|---|---|
| `j` / `k` | Move |
| `Enter` | Expand — full screenshot, console, network, environment |
| `a` | Accept → creates an issue, opens the assign/priority popover inline |
| `m` | Merge into existing issue (search-as-you-type, pre-filtered by fingerprint proximity) |
| `s` | Spam — trains nothing in v1, just hides and rate-limits that `ip_hash` |
| `x` | Discard |
| `r` | Reply to reporter (magic-link thread, no account required) |

Target: a 30-report inbox cleared in under two minutes without touching the mouse.

---

## 7. Screens

```
/                                   → redirect to last-used project view
/projects                           → project grid, per-project open counts
/p/{key}                            → default saved view (list)
/p/{key}/board                      → kanban by status, dnd-kit
/p/{key}/inbox                      → triage
/p/{key}/{ISSUE-KEY}                → issue detail
/p/{key}/settings                   → statuses, widget keys, members, email-in
/views/{id}                         → saved view (may span projects)
/settings/{workspace,members,billing,labels,profile,notifications}
/portal/{token}                     → client-facing single-issue thread, no account
```

### Issue list

Virtualised (`@tanstack/react-virtual`) — must stay smooth at 10k rows. Grouped by status
by default, collapsible. Inline editing of status, assignee and priority from the row via
popover; no navigation, no page reload. Multi-select with `shift+click`, then bulk
operations from the same keyboard map.

The filter bar is **not** a query builder. It is a row of chips (`status: open`,
`assignee: me`, `label: regression`) plus free text that runs against `search_vector`.
Bugzilla's advanced search page is the canonical example of what to avoid: it is complete,
and nobody can use it. Power users get a text syntax (`is:open assignee:@me -label:wontfix`)
parsed into the same filter JSON that the chips produce.

### Issue detail

Two columns. Left: title, description, attachments, then a merged chronological stream of
comments and events. Right: status, assignee, priority, labels, relations, watchers, and —
when the issue came from the widget — an **Environment** card with the screenshot,
collapsible console and network tables, and the occurrence sparkline.

Internal comments are visually distinct (amber left border, lock icon) and the composer
has an explicit internal/public toggle that **remembers its last state per issue** and
shows a persistent banner when set to public on a client-visible issue. Accidentally
telling a client "this is because Dave's migration was garbage" is the failure mode to
design against.

### Keyboard map (global)

| Key | |
|---|---|
| `⌘K` | Command palette (`cmdk`) — go to issue, project, view; run any action |
| `c` | New issue |
| `/` | Focus search |
| `g` then `i` / `b` / `t` | Go to issues / board / triage |
| `j` `k` | Navigate list |
| `e` | Status · `a` Assignee · `p` Priority · `l` Labels (all popovers) |
| `⌘Enter` | Submit comment |
| `?` | Shortcut sheet |

Every one of these must work without a modifier fight against the browser, and every action
reachable by keyboard must also be reachable by mouse. Keyboard-first, not keyboard-only.

---

## 8. Realtime, notifications, email

**Realtime** — `laravel/reverb`, private channel `workspace.{id}.project.{id}`. Broadcast
`IssueUpdated`, `CommentCreated`, `ReportReceived`. A presence channel per issue drives
"Ann is viewing" and a live "someone is typing" hint in the comment box. Optimistic local
update first, reconcile on the broadcast.

**Notifications** — database + email, per-user preferences. Default: notify on assigned,
mentioned, commented-on-watched, and state change of an issue you reported. Batch email
into a 5-minute digest per issue so a burst of activity is one mail, not nine. Slack
webhook per project is a fast follow.

**Email-in** — Mailgun **inbound routes**, not IMAP polling. You already run Mailgun in
tixline, and a webhook beats a cron poll on latency and failure modes.
`bugs+{project_token}@in.buggie.eu` creates an issue; replies to notification mail
(`reply+{comment_token}@`) become comments. Strip quoted history above the `On … wrote:`
line and any `-- ` signature before storing.

---

## 9. Stack

**Backend** — PHP 8.4, Laravel 12, Postgres 16, Redis (cache, queues, Horizon), S3.

```
inertiajs/inertia-laravel ^2      tightenco/ziggy ^2
laravel/sanctum ^4               laravel/reverb ^1
laravel/cashier ^16              laravel/horizon ^5
spatie/laravel-permission ^6     (teams mode = workspaces)
spatie/laravel-medialibrary ^11  sentry/sentry-laravel ^4
league/flysystem-aws-s3-v3 ^3
```

**Frontend** — React 19 + TypeScript, Inertia 2, Tailwind 4, Vite.

```
@inertiajs/react            @tanstack/react-virtual     cmdk
@dnd-kit/{core,sortable}    @tiptap/react + starter-kit
lucide-react                laravel-echo + pusher-js
@headlessui/react           date-fns
```

**Widget** — separate Vite build, vanilla TS, zero runtime deps, `<20KB` gzipped, served
from CDN with a versioned path and a `pk_*` shim that never breaks old embeds.

**Search** — Postgres `tsvector` with a generated column and a GIN index. Scout +
Meilisearch only if and when full-text across comments and attachments gets slow.

---

## 10. Build order

**M1 — Skeleton. ✅ Done.** Laravel + Inertia + React + TS scaffold, Postgres, auth,
workspaces with subdomain resolution, the `BelongsToWorkspace` trait and its leak test,
projects CRUD, seeded statuses. See *Implementation notes* below.

**M2 — The tracker. ✅ Done.** Issue CRUD with the locking key generator, grouped
inline-editable list, issue detail, comments with tiptap, `issue_events` feed, labels,
relations, watchers. Attachments have a table and model but no upload UI yet — they
arrive with the widget in M4. Virtualised paging deferred to M3; the list is capped at
500 rows until then.

**M3 — The UX that justifies building it. ✅ Done, except realtime.** Command palette,
full keyboard map, saved views, filter chips + query language, board with dnd-kit,
virtualised list, optimistic updates, bulk edit. Reverb is **deferred** — see below.

**M4 — The widget. ✅ Done.** Widget build, capture, redaction, annotation UI, signed
uploads, ingest endpoint with origin/rate/size guards, fingerprinting worker, triage inbox.

**M5 — Clients. ✅ Done.** Invitations with per-project client scoping, the reporter's
portal thread, email-in via Mailgun, notification preferences and digests. (The client
role, visibility scopes and internal-vs-public comments arrived early, in M2.)

**M6 — Open source, and a hosted service. ✅ Done.** AGPL-3.0, one codebase that runs
both ways, Cashier plans and limits behind a single flag, workspace settings, usage
metering, Horizon, Sentry, and a self-host image that was actually installed from
scratch to check.

Roughly three weeks of focused work. M1–M3 alone already beat Mantis.

---

## 11. Risks and open questions

- **`html2canvas` fidelity.** It re-renders the DOM; cross-origin iframes, canvas/WebGL
  content and some CSS come out wrong. Mitigation: offer `getDisplayMedia` as an opt-in
  "true screenshot" path (costs a browser permission prompt) and always show the reporter
  the image before it sends, so a bad capture is visibly bad.
- **Widget in a CSP-strict client app.** Some clients will need a self-hosted widget bundle
  and an allowlisted connect-src. Plan for a downloadable build from day one.
- **Fingerprint over-grouping.** Too aggressive and two real bugs merge. Ship a one-click
  "split from group" that moves the last N occurrences into a new issue.
- **Client visibility is the leak surface.** Anything a client can reach goes through one
  scope, one policy, and a test suite that asserts a client account 404s on every internal
  route. Write those tests in M5, before the feature is "done".
- **Undecided:** GDPR retention policy for screenshots and `reporter_email` (proposal:
  configurable per workspace, default 180 days, hard delete via scheduled job).
- **Undecided:** whether `severity` earns its place in v1 or waits for a request.

---

## 12. Implementation notes (M1)

Written after building it. Where reality differed from the plan above, reality wins.

### Versions

The spec guessed at versions; these are what actually installed.

| Planned | Installed |
|---|---|
| Laravel 12 | **13.17** (PHP 8.3 floor, 8.4 in the container) |
| Inertia ^2 | **3.3** — `ComponentResolver` now wants the component, not the module, so the resolver ends `.then((m) => m.default)` |
| React 18 | **19.3** |
| — | TypeScript 7, Vite 8, Tailwind 4 |
| Postgres 16 | 16.15 |

### Schema change: `statuses` carries `workspace_id`

The plan had statuses owned only by a project. They also carry `workspace_id`, so the
same global scope covers them and the leak test can assert on them directly. Reaching
statuses through a scoped parent would work, but it makes the invariant
"every tenant table filters on `workspace_id`" conditional, and a conditional invariant
is one nobody checks.

### Three things that cost time, all worth knowing

**`Route::domain()` must not carry a port.** Domain matching compares against
`Request::getHost()`, which never includes one, so `Route::domain('buggie.localhost:8080')`
matches nothing. Hence two config values: `buggie.host` (port-free, for routing) and
`buggie.domain` (with port, for building URLs). `RouteUrlGenerator::addPortToDomain()`
re-adds the current request's port when generating, so `route()` still produces working
dev URLs.

**The `{workspace}` domain parameter has to be dropped before binding.** Laravel's
controller dispatcher splices resolved route bindings into the argument list
*positionally*. Leave the domain parameter in place and `show(Project $project)` is
handed the workspace slug as a string. `ResolveWorkspace` calls
`forgetParameter('workspace')` once it has done its job; `URL::defaults()` keeps
`route()` working without passing the subdomain everywhere.

**The middleware priority list names a contract, not a class.** It contains
`Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`, not
`Illuminate\Auth\Middleware\Authenticate`. Prepending before the class silently does
nothing, and the symptom is remote from the cause: an unknown subdomain redirects to
login instead of 404ing. `ResolveWorkspace` must sit before that contract, which also
puts it ahead of `SubstituteBindings`.

### Testing

Tests run against a real Postgres database (`buggie_testing`), not sqlite — the schema
uses `jsonb`. The `<env>` entries in `phpunit.xml` need `force="true"`, because PHPUnit
will not override a variable that already exists in the real environment, and
docker-compose sets `DB_DATABASE` for the container. Without it the suite runs
`RefreshDatabase` against the **development** database, which is exactly as destructive
as it sounds.

`TestCase::setUp()` calls `withoutVite()` so rendering a page never depends on
`npm run build` having been run.

### Still open from M1

- ~~No password reset~~ — added later; see §22. No email verification yet.
- No workspace member invitations — a workspace has exactly its owner until M5.
- ~~Statuses not editable~~ — done later; see §23.
- `laravel/sanctum` is installed but unused until the widget ingest endpoint in M4.

---

## 13. Implementation notes (M2)

### Timestamp precision is load-bearing for the feed

Comments and events interleave into one chronological stream. Laravel's
`$table->timestamps()` defaults to **precision 0** on Postgres, so creating an issue and
its first comment landed in the same second and the merge order became whatever the
arrays happened to be in — the feed showed comments before the "created" event.

Two changes were needed, and either alone is insufficient:

1. `$table->timestamps(6)` / `$table->timestamp('created_at', 6)` on `comments` and
   `issue_events`.
2. `protected $dateFormat = 'Y-m-d H:i:s.u';` on both models — the column accepting
   microseconds does not help while Eloquent formats dates as whole seconds on write.

The API then serialises with `format('Y-m-d\TH:i:s.uP')`, because `toIso8601String()`
truncates to seconds and would throw the precision away again at the last step.

### `workspace_id` is never mass-assignable

It is stamped by `BelongsToWorkspace` on create and appears in no model's `#[Fillable]`.
Mass-assigning a tenant id from request input is precisely the bug the trait exists to
prevent. Internal call sites therefore **do not pass it** — they let the trait do it, and
throw `MissingWorkspaceContext` if no workspace is bound. Factories that may run outside
a bound workspace use `forceCreate` instead.

`issue_events.created_at` is the one deliberate exception to "timestamps aren't fillable":
the table has `$timestamps = false` because the feed is append-only and rows are written
with an explicit time and never touched again.

### Insert order for issue keys

`number` and `key` are NOT NULL and are not fillable — they are the project's to hand out.
The model must therefore be built with `new Issue(...)`, have them applied with
`forceFill`, and be saved **once**. Creating first and back-filling after fails on the
NOT NULL constraint.

### Factory states see unexpanded attributes

A factory state closure receives the raw definition, where `project_id` may still be a
`Factory` instance rather than a key. `IssueFactory::inStatus()` originally resolved the
status inside a state closure and passed a `ProjectFactory` object into a query binding.
Anything derived from a related model belongs in `afterMaking`, once the relation is a
concrete id.

### Statuses are per project, but the list spans projects

The cross-project issue list groups by status **name**, so two projects' "In Progress"
columns merge into one group — which is what you want when looking across projects. The
inline status editor needs each issue's own project's workflow, so the controller ships
a `statuses_by_project` map rather than one flat list.

### Client visibility, enforced in three places

A client sees an issue only if `visibility = client` **and** they were granted that
project — either condition alone is not enough. Beyond `IssuePolicy`, two things matter:

- Comments and events default to `is_internal = true`. Something a client can see is a
  decision someone makes, never an accident.
- `IssueController@store` overrides `visibility` to `client` when a client files an
  issue, whatever the payload said. Otherwise a client could file a report and
  immediately lose sight of it.

`ClientVisibilityTest` covers all of this over HTTP, including asserting the internal
comment text appears nowhere in the client's response payload.

### Still open from M2

- Attachments: table, model and polymorphic relation exist; no upload UI or controller.
- No `@mention` autocomplete in the editor. `TiptapDocument::mentionedUserIds()` reads
  mention nodes already, so the backend is ready for it.
- The list is capped at 500 rows with no paging — virtualisation is M3.
- No keyboard navigation yet (`j`/`k`, `c`, `⌘K`) — that is the substance of M3.
- Relations can be created and removed via the API but have no UI beyond display.

---

## 14. Implementation notes (M3)

### The query language is the filter state

The plan had chips writing query parameters and a text syntax as a separate power-user
feature. That would have been three representations of the same thing — URL parameters,
chip state and saved-view JSON — which drift.

Instead there is one string:

```
is:open project:web assignee:@me -label:wontfix checkout
```

`IssueQuery` parses it, `IssueQueryFilter` applies it, the chips are an editor for it,
and **a saved view is nothing more than a stored one**. The URL is shareable and
readable, and "save this as a view" needed no extra modelling at all.

Rules worth knowing:

- **Unknown operators become search text.** `sevrity:high` searches for that literal
  rather than being dropped, so a typo can never silently change what you are looking at.
- **Exclusions accumulate; inclusions replace.** `-assignee:a -assignee:b` excludes
  both, because excluding is a set operation. `assignee:a assignee:b` keeps the last,
  because an issue has one assignee and the chip is a single choice.
- **An unresolvable name matches nothing, not everything.** `assignee:nobodyhere`
  returns an empty list. A filter that silently does nothing is the dangerous failure.
- Both parsers produce the same canonical ordering, so a query built by clicking and the
  same query typed by hand compare equal — which is what makes deduplicating views work.

`lib/issue-query.ts` mirrors `IssueQuery` so chips can edit the string without a
round trip. The two must stay in step; the key order constant is the thing to watch.

### Partial reloads make prop laziness free

Every prop on the issue index is a closure. Inertia evaluates only the props a partial
reload asks for, so an inline edit sending `only: ['issues', 'flash']` re-runs the issue
query and nothing else — facets, views and the rest are not recomputed. `Inertia::merge`
was wrong here (it appends, duplicating rows) and `Inertia::optional` was wrong too (the
chips need facets on first paint).

### Optimistic updates, and rolling them back

Inline edits write into an `overrides` map keyed by issue key, so the row repaints
before the request leaves. The entry is cleared when fresh `issues` arrive, and on
`onError` the override is dropped so the row snaps back rather than leaving a lie on
screen.

### The board merges columns by status name

Statuses belong to projects, so a cross-project board has no single workflow. Columns
are grouped by status *name*, and a drop resolves to the status with that name **in the
dropped issue's own project**. A project with no status of that name simply cannot
accept the drop. `PointerSensor` uses a 4px activation distance so a click still clicks.

### Keyboard map

`useHotkeys` handles single keys, `mod+` combos and two-key sequences (`g i`) with a
one-second timeout. Modifier combos fire everywhere, including inside the comment
editor; bare keys are ignored while the caret is in a field. Keyboard-first, not
keyboard-only: everything reachable by key is also reachable by mouse.

### Bulk edits are authorised per issue

`IssueController@bulk` loops `authorize('update', $issue)` inside the transaction. A
bulk action is a convenience, never a way around a policy, and because the lookup is
workspace-scoped, keys from another tenant resolve to nothing at all.

### Realtime is deferred, deliberately

`laravel/reverb` exists only at v1.x, which pins `guzzlehttp/psr7 ^2.6`. Laravel 13
ships guzzle 8 / psr7 3.1, so installing Reverb downgrades **guzzle 8 → 7** across the
whole application. That is a major-version downgrade of a core HTTP dependency in
exchange for websockets, on a product where optimistic updates already cover the
responsiveness. Options, in preference order:

1. Wait for a Reverb release that supports psr7 ^3.
2. Poll with `router.reload({ only: ['issues'] })` on an interval — a few lines, no
   dependency change, good enough for a tracker.
3. Take the guzzle downgrade.

The client side (`laravel-echo`, `pusher-js`) is already installed, so whichever path is
taken, only the server half is outstanding.

### Still open from M3

- No realtime (above), and no presence/"who is viewing".
- Bulk edit has no undo. Given it can touch 200 issues, it should.
- Saved views cannot be reordered or renamed from the sidebar — only created and
  deleted.
- `shift+click` range selection is not implemented; `x` toggles one row at a time.
- The palette searches loaded issues only. Typing a key offers it directly, but there
  is no server-side search-as-you-type yet.

---

## 15. Implementation notes (M4)

The widget is ~6KB gzipped because html2canvas (~50KB) is fetched from a CDN only when
someone actually opens the reporter. Every visitor to a customer's site pays for the
small part; only people filing a report pay for the big one.

### Redaction must happen before rasterising

The plan said to paint over redacted regions on the captured canvas. Built that way, it
was **wrong on the first real test**: the masks landed on the field labels while the
password and the `data-buggie-redact` field stayed perfectly readable, because painting
afterwards means reproducing html2canvas's coordinate system exactly, and any
disagreement about scroll offset or scale shifts the masks without any visible sign of
failure.

The fix is to restyle the elements in the DOM — solid background, transparent text,
children hidden — let the browser lay out the masks, rasterise, then undo. Alignment
becomes the browser's problem, which it cannot get wrong. **A redaction mechanism that
can be silently misaligned is not a redaction mechanism.**

Also: `backgroundColor: null` produced a canvas that was 48% transparent, which becomes
a **black** JPEG on export. The capture now falls back to the page's own background.

### No requestAnimationFrame in the capture path

The first version waited a frame before capturing, so the panel was laid out and the
widget's own UI hidden. rAF does not fire in a hidden tab, so a report filed from a
background window sat on "Capturing screenshot…" indefinitely. It is a `setTimeout`
now, and the CDN load has a six-second ceiling — a blocked CDN gives a report with no
image, never a stuck panel.

### Fingerprinting

```
sha1( normalised message | first application stack frame | route pattern )
```

Build hashes are stripped from stack frames so a deploy does not split a group; ids,
UUIDs, hex and quoted strings are removed from messages; `/orders/8412/checkout`
becomes `/orders/:id/checkout`. **A report with no error gets no fingerprint** — human
prose is not reliably comparable, and merging two people's different problems is worse
than showing two inbox rows.

Reopen window: an occurrence against an issue closed **less than 14 days ago** reopens
it. Older than that, the report stays in the inbox so a person decides whether it is a
regression worth linking, rather than quietly reviving month-old history.

### Reports are not issues

Intake lands in `reports` and is promoted, merged or discarded from the triage inbox —
`a`, `m`, `s`, `x`, one keystroke each. Reports sharing a fingerprint collapse into one
inbox row with a count, and acting on the row acts on the whole group. Sibling ids are
re-fetched and filtered by fingerprint server-side, never trusted from the request.

### Two Laravel/nginx gotchas

- `Route::get('w/{key}.js', …)` never matches without `->where('key', '[A-Za-z0-9_]+')`:
  the default `[^/]+` is greedy, swallows the `.js`, and leaves nothing for the literal.
- nginx's static-asset rule matches anything ending `.js`, so `/w/{key}.js` 404ed before
  reaching PHP. It needs an explicit `location ^~ /w/`.

### The test database, corrected

**The M1 note in §12 was wrong.** `force="true"` in `phpunit.xml` does *not* beat a
container environment variable: those land in `$_SERVER`, and Laravel's env repository
reads `$_SERVER` before `$_ENV`, while PHPUnit's force only writes `$_ENV` and
`putenv()`. The suite had been running `RefreshDatabase` against the **development
database** ever since — which is why the dev data kept needing reseeding after test
runs.

The real fix is that no application configuration lives in `docker-compose.yml` any
more; it all comes from the bind-mounted `.env`, and the `app`/`queue` services have no
`environment:` block at all. `TestCase::setUp()` now asserts the database name ends in
`_testing` and fails loudly otherwise, because this class of mistake is destructive
rather than merely wrong.

### Still open from M4

- No hCaptcha on anonymous keys; the design calls for it once a key trips its hourly
  rate. Rate limits and the origin allowlist are in place.
- Screenshots are stored on the local disk. The `Attachment` model already prefers a
  temporary URL when the disk supports one, so moving to S3 is configuration.
- No reply-to-reporter flow from the inbox — the magic-link thread is M5.
- The reopen window is a constant, not a per-workspace setting.
- No "split from group" for over-grouped fingerprints; the design calls for it.
- Screenshot uploads are not virus-scanned or re-encoded server-side.

---

## 16. Implementation notes (M5)

### The portal is not a cut-down version of the app

Someone who reported a bug is not a user of a bug tracker. They followed a link from an
email to find out what happened. So the portal has no navigation, no jargon, and shows
**the status category rather than the team's status name** — "closed" is honest, where
"Won't Fix" needs explaining and reads as rude. Public comments only; the reply box can
only ever produce a public comment, with no code path from there to an internal note.

The token is the entire credential, so it is long, bound to one issue, expires after 90
days, and rate-limits replies. `PortalTest` is mostly about what it must *not* open.

An anonymous report gets no email and no token: emailing someone who never left an
address is not a feature.

### Invitations

Re-inviting an address refreshes the existing invitation rather than failing on the
unique index or leaving two live tokens. Accepting deliberately does **not** re-check
the invitee's own email against the one written to — the token is the credential, and
people routinely sign in with a different address than the one they were mailed at.

A client invitation with no projects is refused: it produces someone who can see
nothing, which is a confusing way to arrive. Only the workspace owner can invite another
owner, and the owner cannot be removed.

Accepting always lands in the workspace that issued the token, whatever domain the link
was presented on.

### Email-in

Mailgun inbound routes rather than IMAP polling — a webhook beats a cron poll on latency
and on failure modes. Anyone can POST to the endpoint, so the signature (timestamp +
token, HMAC-SHA256) is verified before anything is read, and signatures older than five
minutes are rejected as replays. **With no signing key configured the endpoint accepts
nothing**, because failing open here would let anyone file issues in any workspace.

Malformed or unknown addresses get **200, not an error**: Mailgun retries failures, and
these will never succeed however many times they are tried.

`EmailBody` strips the quoted thread and signature. An email reply is mostly not the
reply, and storing all of it makes a thread unreadable within about three messages.

Who wrote it decides where it lands: a staff member replying by email writes an internal
note, matching what the in-app composer defaults to for them; everybody else writes in
public. A project's inbound token is random rather than derived from the slug, so
guessing one project's address does not reveal another's, and a reply token pairs the
issue key *with* the project token so a valid key cannot be aimed at a different project.

### Notification digests

Activity is recorded into `pending_notifications` and nothing is sent inline. A
scheduled `notifications:flush` groups by person-plus-issue and sends one message per
group — **once the group has been quiet for the digest delay**, measured from the *last*
entry rather than the first. Measuring from the first would mean a long argument in the
comments posts its own digest mid-conversation.

Three rules worth keeping:

- Nobody is told about their own actions.
- A client watching an issue is never told about internal activity. Email is a way for
  an internal note to escape, so the visibility check is repeated here rather than
  trusted from the caller.
- Preferences are opt-out. Silence should be chosen, not the default.

Digest mail sets `Reply-To` to `reply+{KEY}.{project token}@`, so replying to a
notification lands back on the issue as a comment. The loop closes.

`docker-compose.yml` now runs a `scheduler` service. Without it the digests accumulate
and nothing is ever delivered.

### Still open from M5

- Notification preferences have no settings screen; the column and the check exist but
  a person cannot change them yet.
- No unsubscribe link in digest mail, which a production system needs.
- Inbound attachments are discarded — Mailgun sends them, and they should become issue
  attachments.
- No handling of bounces or of mail sent to a closed issue.
- The portal cannot show attachments or let a reporter add one.
- Removing a member does not reassign their issues.

---

## 17. Implementation notes (M6)

The plan for this milestone was "SaaS". It changed mid-build: Buggie is **AGPL-3.0**,
and the hosted service is the same code with one flag set.

### One codebase, one flag

`BUGGIE_HOSTED` decides whether plans, limits and billing apply. Self-hosted installs
get a `self_hosted` plan whose every limit is `null`, so the same checks run in both
modes without the code being littered with conditionals.

This was chosen over the alternatives on purpose:

- **Open core** (MIT core, proprietary `ee/`) means every new feature becomes a "which
  side?" decision, forever, for a small team.
- **BSL/FSL** protects the hosted service better but is not OSI open source, and the
  argument costs more attention than it is worth here.
- **AGPL** means anyone can run it, and anyone offering it as a service has to publish
  their changes. The self-host burden — Postgres, Redis, a queue, a scheduler, object
  storage, inbound mail, wildcard DNS and TLS — is real enough that hosting sells
  itself without crippling anything.

The hosted version has **no extra features**. It sells not having to run all that.

### What self-hosting must never be

A self-hosted install has no limits, no upgrade prompts, no billing routes, and **no
telemetry of any kind** — no licence check, no usage ping, no error reporting unless
the operator sets their own Sentry DSN. `SelfHostedTest` asserts all of it, including
that `billing` is `null` in the shared Inertia props so the UI has nothing to nag with.

### The hosted check is middleware, not a route condition

Wrapping the billing routes in `if (config('buggie.hosted'))` looked tidier and was
wrong: routes are registered during bootstrap, which makes the condition invisible to
anything that changes configuration afterwards — and route caching would bake in
whichever mode was active when the cache was built. A `hosted` middleware answers 404
at request time instead.

### Limits

Metered on **reports per calendar month**, because that is what actually scales with
usage: every report costs storage, a screenshot and a worker. Projects and members are
counted too, because they are what customers compare on.

Two details worth keeping:

- Seats are counted **including outstanding invitations**. Otherwise a workspace invites
  its way past the limit and only finds out when people try to accept, which blames the
  wrong person.
- Over the report limit, ingest returns **402 with a message the widget displays**. The
  person who hit the bug did nothing wrong and should be told something true rather
  than "could not send".

`config/plans.php` holds the numbers. Prices there are placeholders; the limits are
enforced, the displayed amounts are cosmetic and Stripe is the real source.

### Self-hosting was tested by doing it

The production image was built and a clean install run from an empty directory:
`build` → `key:generate` → `up` → register → create a workspace. Four things were
broken and would have been found by the first person who tried it:

1. The `composer` image has no `pcntl` or `bcmath`, which Horizon and Cashier's money
   library require. Adding the extensions, rather than `--ignore-platform-req`, which
   moves the failure to runtime and hides a real missing extension.
2. `composer dump-autoload` ran `package:discover`, which boots the application and
   needs configuration that does not exist at build time. Deferred to the entrypoint.
3. **There was no `.dockerignore`**, so `.env` was being copied into the image. Anyone
   building and pushing it would have shipped their own secrets.
4. `mbstring` needs oniguruma, which the dev image had and the production one did not.

And one that was only visible by running it: with `APP_KEY` unset, migrations run
happily and then **every page answers a bare "Server Error"**. The documented order
made that the default experience, because it said to generate the key after starting.
The entrypoint now refuses to start and prints the exact command.

### Still open from M6

- No provider chosen for the hosted deployment; `docs/DEPLOYMENT.md` describes the
  shape and its checklist.
- Prices are placeholders and no Stripe products exist.
- No dunning: a failed payment downgrades entitlements at the next webhook with no
  warning email.
- No usage export or invoice history beyond Stripe's own portal.
- The self-host image runs web, queue and scheduler in one container. Right for a small
  install, wrong at scale — the deployment guide says to split them.
- No `docker compose pull` path: self-hosters build the image rather than fetching a
  published one. Publishing to a registry would make upgrades a great deal easier.

---

## 18. Post-milestone work

Three things that were on the "still open" lists and are now done.

### Retention

Bug reports collect personal data as a side effect of being useful: a screenshot of
whatever was on somebody's screen, the address they wrote from, the account they were
signed in as. `buggie:prune` runs daily and ages it out.

What goes, and what does not:

| | |
|---|---|
| Screenshots | Deleted after 180 days. The report keeps its title and error. |
| Reporter name, email, ref, IP hash | Scrubbed after 180 days, including the `identity` block inside the captured environment — the same data by another route. |
| Spam and discarded reports | Deleted outright 30 days after triage; nobody wanted them. |
| Expired portal links | Deleted 30 days after expiry. |
| **Issues and comments** | **Never.** They are the work product; a tracker that deletes its own history is not a tracker. |

Every rule is a day count in `config/buggie.retention`, and zero disables it. Pruning
runs across every workspace, so it deliberately bypasses the tenancy scope — it is
housekeeping, not a tenant operation.

### Attachments

Drag-and-drop, paste and a file picker on the issue page. The security decisions:

- An **allowlist** of types, deliberately **without SVG** — it is an XML document that
  can carry script, and we serve attachments from our own origin.
- The uploaded filename is kept as a *label only*; the stored path is generated, so a
  crafted name cannot escape the directory.
- Every read goes through the issue's own visibility check. These files routinely hold
  a customer's production data.
- `X-Content-Type-Options: nosniff` on every response. Images render inline; everything
  else is force-downloaded and carries a strict CSP.

One thing corrected during the work: the response originally carried
`Content-Security-Policy: ... sandbox` for all types. `sandbox` is document-scoped and
puts the response in an opaque origin, which is meaningless at best for an image
subresource. It now applies only to the types that download and are never interpreted.

### CI

`.github/workflows/ci.yml` runs three jobs: the test suite against real Postgres and
Redis, typecheck plus build, and — because self-hosting is a first-class path — an
actual build and boot of the production image, including a check that a missing
`APP_KEY` still stops the container with a readable message.

It also fails the build if the widget exceeds **20KB gzipped**. It is 6KB today, and
every visitor to every site running Buggie downloads it; a dependency sneaking in should
break the build rather than be noticed a year later.

### A bug worth recording

Any authenticated visitor to `/login` or `/register` got a **500**, not a redirect.
Laravel's `guest` middleware looks for a route literally named `dashboard`; ours lives
on the `{workspace}` subdomain, so generating it from the central domain throws for a
missing parameter. Fixed with `redirectUsersTo('/')` — the central home already knows
which workspace to send someone to.

No test caught it because none visited an auth page while signed in. It was found by
reloading a page out of habit, which is exactly how a real user would have found it.

---

## 19. Two bugs found by using it

### Signing out did nothing

Reported as "can't login as a client", which is exactly how it presents: you cannot
sign in as somebody else because you cannot get out of the session you are in.

Workspaces are subdomains, so `POST /logout` on `acme.buggie.eu` redirected to
`buggie.eu` — a different origin. Inertia issues that as XHR, the browser follows the
302 across the boundary, the cross-origin request is refused, and the page simply sits
there. The console said `HttpNetworkError`; the interface said nothing.

Every cross-origin redirect now goes through `redirect_across_domains()`, which
returns `Inertia::location()` for Inertia requests — a 409 carrying
`X-Inertia-Location`, telling the client to do a hard visit — and an ordinary redirect
otherwise. That covers sign-in, sign-out, workspace creation, workspace switching,
invitation acceptance and workspace deletion; `CrossDomainRedirectTest` covers all of
them.

Worth noting what made this hard to see: the tests passed, because they follow
redirects without caring about origins. Only a browser can tell you that nothing
happened.

### Annotating a screenshot in a 380px panel

The reporter widget captured a 1600px-wide screenshot and offered it for markup inside
a 380px panel, which meant aiming at a thumbnail and scrolling in both directions to
find anything.

The preview is now a button into a full-screen editor: the same canvas element moves
into an overlay scaled to the viewport (about 1400px wide), and moves back when done,
so there is only ever one image and nothing is copied between them. Drawing listeners
attach once rather than per open, or reopening would draw one rectangle per time the
editor had been opened.

The widget went from 6.1KB to 6.8KB gzipped, against a 20KB budget enforced in CI.

---

## 20. Clients, scoped properly

Asked whether the product supports several projects, several clients, and a client
confined to one project. It did — and checking properly turned up three leaks.

### The model

Three gates, all of which must be open for a client to see an issue:

| Gate | |
|---|---|
| Workspace membership | role `client` |
| Project grant | a row in `project_user` |
| Issue visibility | `visibility = client`, set per issue |

The third is the one people do not expect: **holding the project is not enough**.
Issues are shared with a client one at a time, because a project contains plenty that
is not the customer's business — internal rewrites, estimates, opinions about their
legacy code.

A client may hold several projects and sees the union. Nothing restricts them to one.

### What was leaking

An agency runs several customers in one workspace, which makes one client learning
another's project *name* a leak even when they can read none of the work. Three places
gave it away:

- The **dashboard** and **project list** returned every project in the workspace.
- The **labels page** was reachable by clients, exposing the team's own vocabulary and
  issue counts across projects they cannot see.
- The **filter bar** offered every project, every label, and the full staff list.

Also fixed: shared **saved views** were shown to clients, and a view named "Globex
escalations" names a customer in the sidebar of a different one.

Everything a client can reach now goes through `Project::visibleTo()` or an equivalent
scope, `LabelPolicy::viewAny` is staff-only, facets are filtered per role, and
`SavedView::visibleTo` gives clients only their own.

`MultiClientAccessTest` builds the agency shape — two client projects, one internal,
a different client on each — and asserts that the serialised page props for one client
contain no trace of the other customer.

### Why the earlier tests missed it

`ClientVisibilityTest` asked "can this client read that issue?", which was always
answered correctly. It never asked "what does this client learn about the existence of
things they cannot read?" — a different question, and the one that matters when one
workspace holds several customers.

---

## 21. Name and domain

The product is **Buggie**, at **buggie.eu**.

`buggie.app`, `buggie.dev` and `buggie.com` were all registered already, so the choice
was `.io` or `.eu`. `.io` is the conventional signal for a developer tool, but at
roughly €65/year against €10 it is a soft preference charged at 6.5×, and it carries a
real question mark since the British Indian Ocean Territory was ceded to Mauritius in
2024. `.eu` is stable, cheap, and unrestricted for an EU-based operator.

Worth knowing, because it is specific to this product: **the widget URL lives in
customers' HTML** (`<script src="https://buggie.eu/w/pk_….js">`). Changing domain later
means keeping a redirect on `/w/{key}.js` alive indefinitely rather than asking every
customer to edit their pages. Cheap to do, but a reason the decision was worth making
once.

The rename touched 61 files. Two things nearly slipped through:

- The rename script excluded directories named `widget` to skip the build output in
  `public/`, which also skipped `resources/widget/` — the widget's source. The public
  API (`window.buggie`), the redaction attribute (`data-buggie-redact`) and the shadow
  host marker would have kept the old name while everything else moved.
- `config/buggy.php` had to be renamed as well as rewritten, since every call site now
  asks for `config('buggie.*')`.

`data-buggie-redact` is a breaking change for anyone who had the old attribute in their
markup. Nothing is released, so it is a clean break rather than an alias to carry.

---

## 22. Password reset

Needed before inviting anyone real: without it, the first client who forgets their
password is locked out permanently and has to email you to be rescued by hand.

Laravel provides most of it — the `password_reset_tokens` table ships in the users
migration and the framework's base `User` already carries `CanResetPassword`. What
needed deciding:

**The link is built explicitly, not by `route()`.** Workspaces live on subdomains, and
the notification may be sent from a queued job with no request behind it, so
`ResetPassword::createUrlUsing()` pins it to `central_url()`. Left to `route()` this is
the same class of problem as the sign-out bug: correct in a test, wrong in the world.

**The same answer either way.** A request for an unknown address produces exactly the
response a known one does. Saying "no account with that email" turns the form into a
way of discovering who has an account here.

**No automatic sign-in after reset.** They have just chosen the password, so typing it
once proves it is the one they meant — and a briefly compromised mailbox does not hand
over a live session.

Rate limited to five attempts per address-and-address-pair per fifteen minutes, on top
of the framework's own one-minute throttle between links.

Two assertions worth keeping, both of which were missing on the first pass and were
only noticed because a manual check proved nothing: **the old password must stop
working**, and **a token must not work twice**. A link in an inbox is a link for ever.

### Still open

- No email verification on registration.
- No "your password was changed" notification, which is how someone discovers an
  account takeover.

---

## 23. Editable workflows, and a widget that stays out of the way

### Statuses

The six seeded statuses were never editable, which §1 had waved at as an M2 job and
M2 never did. For an agency where one client wants "Awaiting client" and another wants
"In QA", that is a real limitation rather than a missing nicety.

Now: rename, recolour, reorder by dragging, add and remove — per project, as before.
Deliberately **not** extended to custom fields or a workflow-transition designer, which
§1 rejects and which remain rejected.

**The category stays fixed at creation.** A name is the customer's; the category behind
it is the invariant that lets "is this issue open?" keep working. Changing it later
rewrites the meaning of history — issues that closed under a status would silently
reopen, or the reverse. If you need a different category, add a status and move issues
across, which the delete flow does anyway.

Four things the API refuses, each with a test:

- Deleting a status that holds issues, without naming where they should go. Cascading
  them away or leaving them pointing at nothing is how a tracker loses work.
- Deleting the last open status. New issues have to start somewhere.
- Deleting the last status at all.
- Making a closed status the default.

Deleting the current default promotes another open status, because a project without
one cannot create an issue.

### The widget's launcher is now optional

`data-launcher="false"` suppresses the floating button, for applications that already
have somewhere sensible to put "Report a bug" — a help menu, a keyboard shortcut, an
error boundary — and would rather call `buggie.open()` from there. `buggie.close()` and
`buggie.isSupported()` round out the API.

Installation is still one script tag and nothing else. Two integration gaps remain and
are worth being honest about:

- **No npm package.** Fine for a `<script>` in a template; awkward in a React or Next
  application where `npm i @buggie/widget` is the expectation.
- **Native apps have no SDK.** The ingest endpoint is ordinary HTTP authenticated by
  the public key, so a native client can post to it directly — but nobody has wrapped
  that up.
