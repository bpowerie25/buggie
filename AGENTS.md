# Working notes

How this codebase is arranged, and the decisions worth knowing before changing
things. Written for anyone — or anything — about to touch it.

Bug tracker with an embeddable reporter widget. Multi-tenant SaaS.
Design spec: `docs/DESIGN.md`. Read it before adding features — it explains what is
deliberately *not* being built.

## Tests get their own database per checkout

`./bin/test` names the test database after the directory it runs from, so the main
checkout uses `buggie_testing` and a worktree gets `buggie_testing_<dir>`.

This is not tidiness. The suite truncates and re-migrates as it goes, so two checkouts
sharing one database deadlock, and the failures land in whichever run loses. That
produced 25, 27 and 28 failures on three consecutive runs of an unchanged tree —
which reads exactly like a regression and is not one. If a test count moves between
identical runs, suspect this before suspecting the code.

`BUGGIE_TEST_DB` overrides it. Note that `-e DB_DATABASE=` is the only way to redirect
it, because `phpunit.xml` sets the database with `force="true"`.

## There is no local PHP

Everything runs in Docker. Do not install PHP or Composer on the host, and do not
follow generic Laravel setup instructions that tell you to.

```sh
docker compose up -d        # app, nginx, postgres, redis, mailpit
npm run dev                 # Vite runs on the HOST (node is not containerised)
```

Wrappers run the containerised toolchain:

```sh
./bin/art migrate           # php artisan …
./bin/composer require …    # composer …
./bin/test                  # php artisan test
npm run types               # tsc --noEmit
```

| | |
|---|---|
| App | http://buggie.localhost:8080 |
| A workspace | http://acme.buggie.localhost:8080 |
| Mailpit | http://localhost:8027 |
| Postgres | localhost:5433 (`buggie` / `buggie` / `secret`) |

`*.localhost` resolves to 127.0.0.1 in every current browser, so no `/etc/hosts`
editing is needed. Ports are shifted off the defaults because ddev already holds
8025/8026 and another project holds 5432.

Seeded logins (`./bin/art migrate:fresh --seed`):
`brian@example.com` / `password` (acme), `someone@globex.test` / `password` (globex).
Globex exists so a tenancy leak is visible by eye.

## Tenancy — the part to be careful with

Every tenant-owned model uses `BelongsToWorkspace`, which adds a global scope
filtering on `workspace_id` and stamps it on insert. The current workspace lives in
the `Tenancy` singleton, resolved from the subdomain by `ResolveWorkspace`.

- Crossing a tenant boundary (jobs, commands, tests): `Tenancy::run($workspace, fn () => …)`.
- Deliberately querying every tenant: `->acrossAllWorkspaces()`, which is named so it
  shows up in review. Never reach for `withoutGlobalScopes()`.
- In strict mode (on for all web requests) querying a tenant model with no workspace
  bound throws `MissingWorkspaceContext` rather than returning every customer's rows.
- `tests/Feature/TenancyIsolationTest.php` asserts that every table with a
  `workspace_id` has a model using the trait. If you add such a table, it will fail
  until you do.

Non-members get **404, not 403**, on a workspace they don't belong to. A 403 confirms
the workspace exists, which is a free way to enumerate customer names.

## Routing gotchas, both already hit once

**`Route::domain()` must not include a port.** Domain matching runs against
`Request::getHost()`, which never has one. `config('buggie.host')` is the port-free
value for routing; `config('buggie.domain')` keeps the port for URL building. Laravel's
URL generator re-adds the request's port when generating, so dev URLs still work.

**`ResolveWorkspace` drops the `{workspace}` domain parameter.** The controller
dispatcher splices resolved route bindings in *positionally*, so leaving the domain
parameter in place shifts every controller argument along by one and
`show(Project $project)` receives the workspace slug as a string. It also runs before
`SubstituteBindings` and before auth — the priority list names the
`AuthenticatesRequests` **contract**, not the `Authenticate` class, which is easy to
get wrong.

## The activity feed

`comments` and `issue_events` interleave into one chronological stream, so both tables
use `timestamp(6)` **and** `protected $dateFormat = 'Y-m-d H:i:s.u'` on the model.
Laravel's defaults (precision 0, whole-second date format) put every entry in the same
second and the order becomes arbitrary. Serialise with `format('Y-m-d\TH:i:s.uP')` —
`toIso8601String()` truncates back to seconds.

Events are typed, not diffed. Add a case to `IssueEventType`, write it from
`UpdateIssue`, and render it in `eventSentence()` in `pages/issues/show.tsx`.

## Client visibility

A client sees an issue only when `visibility = client` **and** they hold the project.
Comments and events both default to `is_internal = true`. When adding anything a client
can reach, extend `ClientVisibilityTest` — it asserts over HTTP, including that internal
text appears nowhere in the response payload.

## The query language

One string is the entire filter state: `is:open project:web -label:wontfix checkout`.
`IssueQuery` parses, `IssueQueryFilter` applies, chips edit it, and a saved view stores
it. Do not add filter query parameters alongside it — add an operator.

`resources/js/lib/issue-query.ts` mirrors the PHP. If you change key order, quoting or
the accumulate/replace rules, change both, or queries stop comparing equal and saved
views silently duplicate.

Invariants worth preserving: unknown operators fall through to search text (a typo must
not silently change results); exclusions accumulate while single-valued inclusions
replace; an unresolvable name matches nothing rather than everything.

## The widget

`resources/widget/` builds separately (`npm run build:widget`) to a stable, unhashed
path, because customers embed the URL. It is vanilla TypeScript with no runtime
dependencies — html2canvas is fetched from a CDN only when someone opens the reporter,
which keeps the bundle every visitor downloads at ~8KB gzipped. Keep it that way.

Two things that are load-bearing:

- **Redaction happens in the DOM before rasterising**, by restyling password fields and
  `[data-buggie-redact]` elements into solid blocks. The earlier approach — painting
  rectangles onto the finished canvas — has to reproduce html2canvas's coordinate
  system, and when it disagreed the masks landed on the labels while the password
  stayed readable. Letting the browser lay out the mask cannot be misaligned.
- **No `requestAnimationFrame` in the capture path.** rAF does not fire in a hidden
  tab, and a report filed from a background window hung for ever on "Capturing
  screenshot…".

The ingest endpoint is unauthenticated by necessity and treated as hostile: origin
allowlist, rate limits, hard size caps, URL secret-stripping, and the IP stored only as
an HMAC. Reporter identity is decided there too (`ReporterIdentityCheck`), after the
origin check: a `user_hash` is HMAC-SHA256 of `id:email` with the key's secret, which is
encrypted, `#[Hidden]`, and leaves the server only in the one flash after creating or
rotating it. Identity grants access only through `ReporterLink` setting `reporter_id`. `/widget-demo` (local only) is a deliberately broken checkout page for
developing against.

Reports are **not** issues. They land in `reports` and are promoted, merged or
discarded from the triage inbox. Anything the fingerprinter cannot group with
confidence goes to a human.

## Whose turn it is

The assignee is always staff (`Assignable`), checked in the actions as well as the
requests, and never changes on its own. "Waiting on the client" is a status flagged
`is_awaiting_client`, and `ClientConversation` moves issues in and out of it. Every
comment goes through it by author: `AddComment` routes a client's comment to
`clientReplied`, and the portal and inbound email call it directly, so a new comment
path must too. `UpdateIssue` keeps the wait state following the status however it
moved.

A client's issue payload is built for them in `IssueController::show`: events pass
`IssueEventType::isClientSafe()` as well as `is_internal`, staff are named through
`AuthorLabel`, and related issues go through the visibility scope. Add a field there
and ask whether a client should have it; the test asserts on the raw props.

## What a client may know

Three separate gates, and all of them must be open:

1. **Workspace membership** with the `client` role.
2. **A grant on the project** (`project_user`). Without it the project does not exist
   as far as they are concerned — not a filtered list, no knowledge of the name.
3. **`visibility = client` on the individual issue.** Holding the project is not
   enough; issues are shared one at a time, deliberately.

A client may hold several projects; they see the union. An agency runs several
customers in one workspace, so **one client learning another's project name is a leak**
even when they can read none of the work. Everything a client can reach is scoped:
`Project::visibleTo()`, the filter-bar facets (projects, labels, members), and saved
views — shared views belong to the team and can be named after a customer.

`MultiClientAccessTest` asserts the whole shape, including that the serialised page
props contain no other customer's name.

## Client-facing surfaces

Three things reach people who are not staff, and all three default to silence:

- **The portal** (`/portal/{token}`, central domain, outside every guard). The token is
  the whole credential. Public comments only, and a reporter's reply can only ever be
  public. It shows the status *category*, not the team's status name.
- **Email-in** (`/api/mail/inbound`). Signature-verified; with no signing key
  configured it accepts nothing. Unknown addresses return 200 so Mailgun stops retrying.
- **Digest mail**. A client watching an issue is never sent internal activity — the
  check lives in `Notifier`, not in the caller.

Notifications are recorded, never sent inline. `notifications:flush` (scheduled every
minute, run by the `scheduler` compose service) groups by person-plus-issue and sends
once the group has been quiet for `buggie.digest_delay_minutes`, measured from the last
entry.

`Notifier::record` writes **two** rows. `pending_notifications` is the send queue and
is deleted the moment its digest goes out; `in_app_notifications` is the record behind
the notification list and survives until `buggie:prune` ages it out. Both are written
in one place because that place is the only one that already knows not to tell somebody
about their own actions and not to tell a client about internal work.

**Nobody is notified about an issue they cannot open.** `Notifier::record` asks
`IssuePolicy::view` for every non-staff recipient, and refuses anything flagged
internal, so a new caller cannot forget; `notifications:flush` asks again at send time.
A mention passes `internal` like a comment does.

**Email replies are signed per issue and per recipient** (`ReplyAddress`). The comment
is posted as the person the address was issued to; the `From:` header decides nothing,
here or on emailed new issues, because anybody can write it.

**Sessions end when the password changes** (`App\Http\Middleware\AuthenticateSession`,
a wrapper that skips token requests). Every sign-in forgets the remembered hash
(`AppServiceProvider`), or a second person signing in on the same browser session would
be measured against the first person's password and signed straight out.

**Verified email is required only where an address grants something:** creating a
workspace when `buggie.require_verified_email` is on (the hosted default), and being
named in `BUGGIE_OPERATORS`. Accepting an invitation verifies. It needs working mail.

**Project grants live in `project_user`, which has no `workspace_id`.** `detach()` with
no ids and `sync()` therefore act on every workspace a person belongs to. Always pass
this workspace's project ids.

**What a notification list shows is decided when it is read.** Membership, project
grants and an issue's client visibility all change after a row is written, so
`NotificationController` puts every row through `IssuePolicy` — the way
`ChaseDueIssues` does — rather than trusting the check that let it be recorded.
`InAppNotification::visibleTo` is the same rules in SQL and exists **only** for the
unread badge, which runs on every page load. Do not reach for it to prefilter the
list: a prefilter that agrees with the policy hides a broken policy call, and one that
disagrees is the leak.

## Who can join

`Registration` (in `app/Support/Registration`) is the one place that decides who may
register and who may create a workspace. The mode is `BUGGIE_REGISTRATION` if set,
else the stored setting, else `open` hosted and `invite` self-hosted. Do not test
`config('buggie.hosted')` to decide either question; ask `Registration`.

- Creating a workspace is checked by `WorkspacePolicy::create` on the route, in
  `StoreWorkspaceRequest`, **and** in `CreateWorkspace`, so a new entry point cannot
  skip it. `RegistrationModeTest` asserts no other route reaches either store action.
- In invite mode a newcomer still registers, carrying their invitation token in the
  session. Anything that changes `PendingInvitation` must keep that working.
- The first-run exception is claimed by a primary-key insert inside the registration
  transaction. Keep the claim inside the transaction, or a failed registration uses it
  up.
- Operators are `users.is_operator` **or** `BUGGIE_OPERATORS`, via the `operate` gate.
  `Operators::all()` returns them as accounts. There is no "first user" fallback any
  more; do not reintroduce one.
- `access_requests.workspace_id` is nullable: a request from the bare domain is for
  operators only. Such rows are written through `AccessRequest::forOperators()` and read
  with `acrossAllWorkspaces()`. Workspace admins reach requests only through the tenant
  scope, and the policy compares ids as well.
- The request form answers every accepted submission identically, including ones it
  drops (existing member, already invited, over the cap). Keep new refusals silent the
  same way, and keep the notification queued so timing does not tell them apart either.

## Open source, and the hosted service

Buggie is AGPL-3.0. The same code runs somebody's own server and the commercial hosted
service; `BUGGIE_HOSTED` is the only difference.

- **Self-hosted installs have no limits and no telemetry.** Not reduced features —
  none. `SelfHostedTest` asserts this, including that `billing` is null in the shared
  props so the UI has nothing to nag with. Anything added that phones home, meters, or
  nags will fail it, which is the point.
- Limits live in `config/plans.php` and are read through `Workspace::plan()`, which
  returns the unlimited `self_hosted` plan when not hosted. Do not scatter
  `config('buggie.hosted')` checks; add a limit to the config instead.
- The hosted-only routes are guarded by the `hosted` **middleware**, not by a condition
  around the route definitions. Route registration happens during bootstrap and gets
  cached, so a condition there is invisible to later configuration changes.

Two files exist because self-hosting is a first-class path: `docker-compose.selfhost.yml`
and `docker/php/Dockerfile.production`. If you change the app's runtime requirements,
change those too, and actually run a clean install — four separate breakages were found
that way and none of them showed up in development.

## Retention and attachments

`buggie:prune` (daily) ages out screenshots, reporter identities, dismissed reports and
expired portal links. **Issues and comments are never pruned** — they are the work
product. Rules are day counts in `config/buggie.retention`; zero disables one.

Attachments use a type allowlist with **no SVG** (XML that can carry script, served
from our own origin), keep the uploaded filename as a label only while generating the
stored path, and check the issue's own visibility on every read. Images render inline;
everything else downloads with a strict CSP. Do not add `sandbox` to an image response
— it creates an opaque origin.

## Crossing the origin boundary

Workspaces are subdomains, so signing in, signing out, switching workspace and
accepting an invitation all leave the current origin. Use
`redirect_across_domains($url)`, never a bare `redirect()`: Inertia issues these as
XHR, the browser follows the 302 to the other origin, the cross-origin request is
refused, and **nothing appears to happen at all**. Sign-out failed silently this way.

`Inertia::location()` returns a plain response, so flash any message to the session
first rather than chaining `->with()`.

## Conventions

- Inertia page components are lowercase paths: `Inertia::render('projects/index')`
  resolves `resources/js/pages/projects/index.tsx`.
- Colours come from semantic tokens (`bg-canvas`, `text-ink-muted`) defined in
  `resources/css/app.css` and redefined under `.dark`. Components should not need a
  `dark:` variant for ordinary surfaces and text.
- Statuses are per-project and renameable, but every one maps to a fixed
  `StatusCategory`. Never test a status by name; ask its category.
- Issue-index props are all closures. Inertia evaluates only what a partial reload
  requests, so `only: ['issues']` re-runs the issue query alone. Don't reach for
  `Inertia::merge` (it appends and duplicates) or `optional` (the chips need facets on
  first paint).
- `workspace_id` is never in a `#[Fillable]`. `BelongsToWorkspace` stamps it on create;
  internal call sites must not pass it. Factories that may run with no workspace bound
  use `forceCreate`.
- Tests run against Postgres (`buggie_testing`), not sqlite — the schema uses `jsonb`.

## Never put app config in docker-compose `environment:`

Container environment variables land in `$_SERVER`, and Laravel's env repository reads
`$_SERVER` **before** `$_ENV`. PHPUnit's `<env force="true">` only sets `$_ENV` and
`putenv()`, so a variable defined in `docker-compose.yml` silently wins over
`phpunit.xml` — and the suite runs `RefreshDatabase` against the development database.

This is not theoretical: it happened here, and `force="true"` did **not** fix it. All
configuration now comes from `.env`, which is bind-mounted into the container, and the
`app`/`queue` services deliberately have no `environment:` block. Don't add one.

`TestCase::setUp()` asserts the database name ends in `_testing` and fails loudly
otherwise, because a misconfiguration here is destructive rather than merely wrong.
