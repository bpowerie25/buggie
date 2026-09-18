# Buggy

Bug tracking that starts with a good report.

The issue list is a commodity. The valuable part is **intake**: a script tag in your
app that captures the screenshot, console, failing request, route and signed-in user
at the moment the bug happens, so nobody has to ask "what browser were you on?".

Full rationale and data model: [`docs/DESIGN.md`](docs/DESIGN.md).

## Status

**M1 + M2 complete.**

- **M1** — Laravel 13 + Inertia 3 + React 19 skeleton, subdomain multi-tenancy with
  enforced isolation, projects CRUD, seeded per-project workflows.
- **M2** — Issues with gapless per-project keys, grouped inline-editable list with
  filters and full-text search, issue detail with a merged comment/activity feed,
  tiptap comments with an internal/public split, labels, relations and watchers.

- **M3** — Command palette (⌘K), full keyboard map, a query language that doubles as
  saved views, kanban board with drag-and-drop, virtualised list, optimistic inline
  edits and bulk actions.

- **M4** — The reporter widget (~6KB gzipped): screenshot with annotation and
  redaction, console and network ring buffers, error capture, a hostile-input ingest
  endpoint, fingerprint-based grouping, and a keyboard-driven triage inbox.

- **M5** — Invitations with per-project client scoping, a portal thread so reporters can
  follow a bug without an account, email-in via Mailgun (new issues and replies, with
  quoted history stripped), and batched notification digests.

Next: M6 — billing, onboarding and deploy.

Realtime is deferred: Reverb v1 pins an old `guzzlehttp/psr7` and would downgrade
guzzle 8 → 7 repo-wide. See `docs/DESIGN.md` §14.

## Running it

Requires Docker and Node. There is no local PHP — see [`AGENTS.md`](AGENTS.md).

```sh
cp .env.example .env
docker compose up -d
./bin/composer install
./bin/art key:generate
./bin/art migrate --seed
npm install && npm run dev
```

Then http://buggy.localhost:8080 — sign in as `brian@example.com` / `password`, which
lands you in http://acme.buggy.localhost:8080.

```sh
./bin/test        # phpunit, against Postgres
npm run types     # tsc --noEmit
npm run build     # production assets
```
