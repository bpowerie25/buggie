# Buggy — Design Spec

Status: draft v1 · 2026-09-18

---

## 1. Thesis

The issue list is a commodity. Mantis, Bugzilla, Jira, Plane and Linear all store a
title, a status and some comments — that part is not worth building.

What none of them do well is **intake**. A bug report is useful in proportion to how
much context arrives with it, and today that context is reconstructed by hand:
"what browser?", "can you send a screenshot?", "what were you doing?", "does it still
happen?". Most of a maintainer's time goes into turning a bad report into a good one.

Buggy's job is to make the report arrive good. A `<script>` tag in a client app captures
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

- Resolution: subdomain — `acme.buggy.app`. A `ResolveWorkspace` middleware looks up
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
<script src="https://cdn.buggy.app/w/pk_live_9f3a2b.js" async></script>
```

Optionally identify the current user so reports arrive attributed:

```js
window.buggy?.identify({ id: 4821, email: 'a@b.com', name: 'Ann', plan: 'pro' });
window.buggy?.setRelease('2026.09.18-a1c3');
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

- Never capture `input[type=password]`, `[data-buggy-redact]`, or anything inside
  `.buggy-redact` — blanked on the canvas before encoding.
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
`bugs+{project_token}@in.buggy.app` creates an issue; replies to notification mail
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

**M3 — The UX that justifies building it (3 days).** Command palette, full keyboard map,
saved views, filter chips + text syntax, board with dnd-kit, optimistic updates, Reverb
wiring. *This is the milestone that decides whether the thing is worth having; don't
compress it.*

**M4 — The widget (4 days).** Widget build, capture, redaction, annotation UI, presigned
uploads, ingest endpoint with origin/rate/size guards, fingerprinting worker, triage inbox.

**M5 — Clients (3 days).** Client role, visibility scopes, internal-vs-public comments,
portal thread by magic link, invitations, email-in via Mailgun, notification preferences
and digests.

**M6 — SaaS (3 days).** Cashier plans and limits, signup and onboarding, workspace
settings, usage metering on reports/month, Sentry, Horizon, deploy.

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
`Request::getHost()`, which never includes one, so `Route::domain('buggy.localhost:8080')`
matches nothing. Hence two config values: `buggy.host` (port-free, for routing) and
`buggy.domain` (with port, for building URLs). `RouteUrlGenerator::addPortToDomain()`
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

Tests run against a real Postgres database (`buggy_testing`), not sqlite — the schema
uses `jsonb`. The `<env>` entries in `phpunit.xml` need `force="true"`, because PHPUnit
will not override a variable that already exists in the real environment, and
docker-compose sets `DB_DATABASE` for the container. Without it the suite runs
`RefreshDatabase` against the **development** database, which is exactly as destructive
as it sounds.

`TestCase::setUp()` calls `withoutVite()` so rendering a page never depends on
`npm run build` having been run.

### Still open from M1

- No password reset or email verification yet. Registration, login and logout only.
- No workspace member invitations — a workspace has exactly its owner until M5.
- Statuses are seeded and displayed but not yet editable; the reorder/rename UI is M2.
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
