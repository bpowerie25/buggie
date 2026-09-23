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

By default that puts a floating "Report a bug" button in front of **every** visitor,
which is right for a public beta and wrong for most client work. Two attributes change
who sees it:

```html
<!-- No button. Trigger it yourself from an admin menu with buggie.open(). -->
<script src="https://your-buggie/w/pk_live_9f3a2b.js" data-launcher="false" async></script>

<!-- No button until someone visits ?buggie=on in that browser. -->
<script src="https://your-buggie/w/pk_live_9f3a2b.js" data-launcher="opt-in" async></script>
```

`opt-in` is the one to reach for when you are putting Buggie on a client's live site:
their staff visit `https://theirsite.com/?buggie=on` once per browser and keep the
button; their customers never see it, and their developer does not have to wire
anything up. The switch is removed from the address bar so it cannot travel into a
shared link.

It hides the interface rather than restricting it — the key is in the page source
either way. If customers must not be able to report at all, do not render the script
tag for them.

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

iOS apps have a Swift package that posts to the same endpoint:

```swift
Buggie.shared.start(key: "pk_live_9f3a2b")

try await Buggie.shared.report(title: notes, screenshot: Buggie.shared.captureScreen())
```

Android apps have the same thing in Kotlin — see [`packages/kotlin`](packages/kotlin).
Both are built from source for now; neither is published yet.

---

## What it does

Buggie is for people who build things for clients. Three reasons to use it:

**Reports arrive complete.** Your client clicks a button; you get the screenshot, the
console, the failing request, the route, the browser and whoever was signed in —
captured at the moment it happened rather than remembered afterwards.

**Clients, without the mess.** Invite a client to their own projects only. Share the
issues you choose, keep internal notes internal, and let whoever reported a bug follow
it through a private link without an account. No per-seat bill for letting a client
tell you something is broken.

**It stays yours.** AGPL, self-hostable, with an API and CSV export. Your clients'
history is not hostage to anybody's pricing page, including mine.

---

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
- **A board that answers "what next"** — kanban grouped by status, assignee, priority
  or project, with cards you drag into the order you actually mean and work-in-progress
  limits that go red rather than refusing the drop.
- **Custom fields** — per project, because an agency's clients do not share a
  vocabulary. Filterable, exportable, and internal unless you say otherwise.
- **Time tracking** — hours against an issue, an estimate beside them, and a total per
  client and per person for any month. Stored in minutes, exported in both.
- **Insights** — opened against closed, whether the backlog is growing, and how long a
  typical bug takes to close. Median, not an average one long-running bug has wrecked.
- **Due dates that chase** — a ladder of reminders before and after the date, settling
  to weekly, so three weeks late is six messages rather than twenty-one.
- **Releases and changelogs** — group issues into a release, mark it shipped, and hand
  the client a list of what changed rather than writing one.
- **White-labelling** — put your client's name and colours on the portal and the
  reporter panel, per project.
- **Import your backlog** — Jira, MantisBT or a spreadsheet, with a preview of what it
  will create before it creates anything.
- **Two-factor authentication** — TOTP with recovery codes. Not a paid add-on; nothing
  about security is.
- **Slack and Teams** — a readable message in the channel, with the same event
  vocabulary as the webhooks and a switch that keeps internal work out of a channel a
  client can read.
- **API and webhooks** — a token-authenticated API scoped to one workspace, and signed
  webhooks for whatever you have already built.
- **Export** — CSV of whatever the filter is showing, because "you can leave whenever
  you like" is not true unless the data comes out.
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
worker, a scheduler, object storage, inbound mail and a CDN. It runs the same code, and
the difference is that all of that is somebody else's problem. Plans and limits apply
there and nowhere else.

**Nothing is held back from the open build, and nothing already in it will be removed.**
Whether some future enterprise-shaped feature — single sign-on, audit logging, retention
policies — would ever arrive only on the hosted service is an open question rather than
a plan; see [`ROADMAP.md`](ROADMAP.md). What is settled is that the published project is
AGPL-3.0 and stays that way.

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

In use and worth your time: multi-tenancy, issues, the query language and saved views,
the board with ordering and WIP limits, the reporter widget, triage, clients, the
portal, custom fields, time tracking, insights, releases, imports, white-labelling,
due-date chasing, two-factor authentication, email in and out, webhooks, Slack and
Teams, the API and CSV export.

Not done, and worth knowing before you commit to it: no realtime updates, no
`@mention` autocomplete, no undo for bulk edits, no email verification on sign-up, no
issue templates, and the interface is English only.

Each help page ends with its own **Known rough edges**, which are kept honest rather
than aspirational. Start at [`docs/help/index.md`](docs/help/index.md).

## Licence

[GNU AGPL v3](LICENSE).

You can run Buggie for yourself or your company, modify it, and keep those
modifications private as long as you do not offer it to others over a network. If you
do run it as a service for other people, the AGPL asks you to publish your changes.

Contributions are welcome — see [`CONTRIBUTING.md`](CONTRIBUTING.md).

Found a security problem? Please do not open a public issue — see
[`SECURITY.md`](SECURITY.md).

What changed and what is coming: [`CHANGELOG.md`](CHANGELOG.md) and
[`ROADMAP.md`](ROADMAP.md). There are no tagged releases yet, so self-hosting today
means running `main` — the changelog says what would change that.
