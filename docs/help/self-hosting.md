# Self-hosting

Buggie is AGPL-3.0 and runs on your own server with **no limits of any kind**: no
plans, no seat counts, no metering, no billing screens, no upgrade prompts. It also
**never phones home** — no licence check, no usage ping, and no error reporting unless
you configure your own Sentry DSN.

This page is an orientation. The operator's reference is
[`../SELF_HOSTING.md`](../SELF_HOSTING.md), which covers TLS, backups and upgrades in
detail.

## What you need

- Docker and Docker Compose.
- A domain, and a **wildcard DNS record**. Workspaces live on subdomains
  (`acme.buggie.example.com`), so `*.buggie.example.com` must point at the server.
  This is not optional — it is how Buggie decides which workspace you are looking at.
- A **wildcard TLS certificate**, for the same reason.
- An SMTP account. Invitations, notification digests, reporter portal links and
  password resets all arrive by email; without it nobody can be invited.

Postgres and Redis are the only other dependencies, and the compose file provides
both. Around 1 GB of RAM is enough for a small team.

## Install

```sh
git clone https://github.com/you/buggie && cd buggie
cp .env.selfhost.example .env
```

Set at least `APP_URL`, `APP_DOMAIN`, `SESSION_DOMAIN`, `DB_PASSWORD` and the `MAIL_*`
values, then:

```sh
docker compose -f docker-compose.selfhost.yml build

docker compose -f docker-compose.selfhost.yml run --rm \
    --entrypoint php app artisan key:generate --show
# paste the base64:… value into APP_KEY in .env

docker compose -f docker-compose.selfhost.yml up -d
```

Migrations run automatically on start. Open your domain, register, and the first
workspace you create is yours.

Generate `APP_KEY` **before** the first proper start. With it unset, migrations run
happily and then every page answers a bare "Server Error"; the container now refuses
to start and prints the exact command, but it is worth knowing why.

## Configuration worth understanding

| Variable | |
|---|---|
| `APP_DOMAIN` | The bare central domain, no scheme. Include a port only in development. |
| `SESSION_DOMAIN` | The same value with a **leading dot**, so the session cookie is shared across workspace subdomains. Without the dot, switching workspace signs you out. |
| `BUGGIE_HOSTED` | Leave `false`. `true` is the commercial service and turns on plan limits. |
| `DIGEST_DELAY_MINUTES` | How long activity on one issue is gathered before emailing. Default 5. |
| `MAIL_INBOUND_DOMAIN`, `MAILGUN_SIGNING_KEY` | [Email in](email.md), optional. With no signing key the inbound endpoint accepts nothing. |
| `BUGGIE_OPERATORS` | Comma-separated addresses allowed into the queue dashboard at `/horizon`. Empty means nobody, which is the default. The dashboard shows jobs from every workspace, which is why it is not tied to a workspace role. |
| `SENTRY_LARAVEL_DSN` | Optional error reporting, pointed wherever you like. Empty means nothing is sent anywhere. |
| `RETAIN_*` | [Retention](privacy-and-security.md#retention) windows in days. |

All application configuration comes from the bind-mounted `.env`. Do not move it into
a compose `environment:` block — container environment variables take precedence over
everything else in a way that has already caused one destructive mistake here.

## The queue worker and the scheduler

Two background processes are load-bearing.

**The queue worker** fingerprints incoming reports, groups duplicates and reopens
issues. Without it, reports still arrive and ingest still answers `202` — they simply
never fingerprint, never group and never notify, and nothing on screen says so.

**The scheduler** runs two jobs:

- `notifications:flush`, every minute, which sends [digests](notifications.md).
- `buggie:prune`, daily at 03:20, which ages out old screenshots and reporter
  identities.

Without the scheduler, digests accumulate and are never delivered, and nothing is ever
pruned.

In the self-hosted image, one container runs the web server, the queue worker and the
scheduler together under supervisord with automatic restart. That is right for a small
install and wrong at scale; [`../DEPLOYMENT.md`](../DEPLOYMENT.md) covers splitting
them.

In the development compose file they are separate `queue` and `scheduler` services,
both with `restart: unless-stopped` — because a worker that dies on a timed-out job
and stays dead looks exactly like everything working.

## Storage

Screenshots and attachments are written to the application's own disk (the `storage`
volume), never to a public directory, and are served only through Buggie with an
authorisation check on every read. Back up the database and that volume; Redis holds
only cache, sessions and the queue.

## The widget on your own domain

The snippet in project settings already points at your install, and the bundle reads
its own script URL to find its key, so nothing needs configuring. See
[The reporter widget](widget.md).

## Development

There is no local PHP — everything runs in Docker.

```sh
docker compose up -d
npm install && npm run dev
./bin/art migrate --seed
```

The application is then at `http://buggie.localhost:8080`, a seeded workspace at
`http://acme.buggie.localhost:8080`, and Mailpit at `http://localhost:8027`.
`*.localhost` resolves to 127.0.0.1 in every current browser, so no `/etc/hosts`
editing is needed.

`./bin/art`, `./bin/composer` and `./bin/test` wrap the containerised toolchain. See
[`../../CONTRIBUTING.md`](../../CONTRIBUTING.md) and
[`../../AGENTS.md`](../../AGENTS.md).

## Related pages

- [`../SELF_HOSTING.md`](../SELF_HOSTING.md) — TLS, email, backups, upgrades, troubleshooting
- [Privacy and security](privacy-and-security.md)
- [Billing and plans](billing.md) — which is to say, none of it

## Sending mail

Mail is configured in the application, not in `.env`: **Settings → Instance**. Changing
an SMTP password should be a form, not an edit to a file on the server followed by a
redeploy.

The screen is reachable only by an operator. On a self-hosted install with nobody named
in `BUGGIE_OPERATORS`, that is the first account created — it is your server. On the
hosted service it is whoever is named there, and an empty list means nobody rather than
everybody.

Anything set here overrides the environment, which stays as the fallback, so an install
that would rather configure mail the traditional way can carry on doing so.

The password is encrypted at rest and never sent back to the browser. Leaving the field
blank keeps the stored one, so changing the from-name does not wipe your credentials.

**Send yourself a test before trusting it.** Mail failure is completely silent —
invitations, password resets and every notification simply never arrive, with nothing on
screen and nothing a user could report. The button reports the provider's own error,
which is the difference between "could not send" and knowing the port is wrong.
