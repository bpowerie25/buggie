# Buggie

**Bug tracking that starts with a good report.**

The issue list is a commodity. What makes a tracker useful is *intake* — and today
that context is reconstructed by hand: "what browser?", "can you send a screenshot?",
"what were you doing?". Most of a maintainer's time goes into turning a bad report
into a good one.

Buggie makes the report arrive good. A `<script>` tag in your app captures the
screenshot, the console, the failing request, the route, the release and the
signed-in user at the moment someone clicks *report a bug*.

```html
<script src="https://your-buggie/w/pk_live_9f3a2b.js" async></script>
```

That's the whole install. ~7KB gzipped.

Add `data-launcher="false"` if you would rather trigger it from your own menu with
`buggie.open()` than have a floating button.

For bundled front ends there is a typed package — a loader, not a copy, so the widget
your visitors run always matches the server it reports to:

```sh
npm i @buggie/widget
```

```ts
import { init } from '@buggie/widget';

init({ key: 'pk_live_9f3a2b', endpoint: 'https://your-buggie' });
```

See [`packages/widget`](packages/widget).

---

## What it does

- **Reports arrive complete** — screenshot with annotation and redaction, console ring
  buffer, failing requests, route, browser, release, and whoever was signed in.
- **Reports are not issues.** Intake lands in a triage inbox, not your backlog, and is
  cleared with one keystroke each: accept, merge, spam, discard.
- **Duplicates collapse.** Forty people hitting one broken checkout is one issue with a
  count of forty, not forty tickets.
- **Keyboard-first tracker** — command palette, `j`/`k` navigation, inline editing,
  a kanban board, and a query language that doubles as saved views:
  `is:open project:web -label:wontfix checkout`
- **Clients without the mess** — invite a client to specific projects, mark individual
  issues visible to them, and keep internal notes internal. Reporters can follow their
  own bug through a private link without being made to create an account.
- **Email in and out** — file issues by email, reply to a notification to comment, and
  get one digest per issue rather than nine.
- **Attachments** — drag, drop or paste screenshots and logs onto an issue.
- **Data ages out** — screenshots and reporter identities are pruned on a schedule you
  control. Issues and comments are never touched.

## Privacy

The widget runs inside your application, on your users' screens, so it is deliberately
boring:

- Password fields and anything marked `data-buggie-redact` are masked **before** the
  screenshot is taken, so alignment cannot silently fail.
- Cookies, `localStorage` and request/response bodies are never read.
- Credential-shaped query parameters are stripped from every captured URL.
- The reporter sees the image, and can hide anything else on it, before sending.
- Reporter IP addresses are stored as an HMAC, never in the clear.

**Nothing phones home.** No telemetry, no licence check, no usage reporting. Error
reporting is off unless you set a Sentry DSN yourself.

## Self-hosting

Requires Docker and a wildcard DNS record, because workspaces live on subdomains.

```sh
git clone https://github.com/you/buggie && cd buggie
cp .env.selfhost.example .env          # set APP_URL, APP_DOMAIN and DB_PASSWORD

docker compose -f docker-compose.selfhost.yml build
docker compose -f docker-compose.selfhost.yml run --rm \
    --entrypoint php app artisan key:generate --show    # paste into .env

docker compose -f docker-compose.selfhost.yml up -d
```

Then open your domain and create a workspace. One container runs the web server,
queue worker and scheduler; Postgres and Redis are the only dependencies.

**Self-hosted installs have no limits.** No plans, no seat counts, no metering, no
billing screens. It is your server.

See [`docs/SELF_HOSTING.md`](docs/SELF_HOSTING.md) for TLS, email, backups and upgrades.

## Hosted

There is a hosted version for people who would rather not run Postgres, Redis, a queue
worker, a scheduler, object storage, inbound mail and a CDN. It is the same code — the
only difference is that it is somebody else's problem. Plans and limits apply there and
nowhere else.

## Development

No local PHP needed; everything runs in Docker.

```sh
docker compose up -d
npm install && npm run dev
./bin/art migrate --seed
```

http://buggie.localhost:8080 — sign in as `brian@example.com` / `password`.

```sh
./bin/test        # phpunit, against Postgres
npm run types     # tsc --noEmit
npm run build     # application bundle + widget
```

See [`AGENTS.md`](AGENTS.md) for how the codebase is arranged and the decisions worth
knowing before changing things, and [`docs/DESIGN.md`](docs/DESIGN.md) for why it is
built the way it is.

## Status

Working: multi-tenancy, issues, the query language and saved views, the board, the
reporter widget, triage, clients, the portal, email-in and notification digests.

Not done: realtime updates, `@mention` autocomplete, undo for bulk edits, email
verification on sign-up.

## Licence

[GNU AGPL v3](LICENSE).

You can run Buggie for yourself or your company, modify it, and keep those
modifications private as long as you do not offer it to others over a network. If you
do run it as a service for other people, the AGPL asks you to publish your changes.

Contributions are welcome — see [`CONTRIBUTING.md`](CONTRIBUTING.md).
