# Working notes

How this codebase is arranged, and the decisions worth knowing before changing
things. Written for anyone — or anything — about to touch it.

Bug tracker with an embeddable reporter widget. Multi-tenant SaaS.
Design spec: `docs/DESIGN.md`. Read it before adding features — it explains what is
deliberately *not* being built.

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
| App | http://buggy.localhost:8080 |
| A workspace | http://acme.buggy.localhost:8080 |
| Mailpit | http://localhost:8027 |
| Postgres | localhost:5433 (`buggy` / `buggy` / `secret`) |

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
`Request::getHost()`, which never has one. `config('buggy.host')` is the port-free
value for routing; `config('buggy.domain')` keeps the port for URL building. Laravel's
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

## Conventions

- Inertia page components are lowercase paths: `Inertia::render('projects/index')`
  resolves `resources/js/pages/projects/index.tsx`.
- Colours come from semantic tokens (`bg-canvas`, `text-ink-muted`) defined in
  `resources/css/app.css` and redefined under `.dark`. Components should not need a
  `dark:` variant for ordinary surfaces and text.
- Statuses are per-project and renameable, but every one maps to a fixed
  `StatusCategory`. Never test a status by name; ask its category.
- `workspace_id` is never in a `#[Fillable]`. `BelongsToWorkspace` stamps it on create;
  internal call sites must not pass it. Factories that may run with no workspace bound
  use `forceCreate`.
- Tests run against Postgres (`buggy_testing`), not sqlite — the schema uses `jsonb`.
  The `<env>` entries in `phpunit.xml` need `force="true"` to beat the container's
  real environment variables.
