# Self-hosting Buggie

Buggie is AGPL-3.0 and self-hosted installs have **no limits**: no plans, no seat
counts, no metering, no billing screens, no telemetry. The hosted service runs the same
code with one flag flipped.

## What you need

- Docker and Docker Compose.
- A domain, and a **wildcard DNS record** — workspaces live on subdomains
  (`acme.buggie.example.com`), so `*.buggie.example.com` must point at the server.
- A **wildcard TLS certificate**, for the same reason. Let's Encrypt issues these via
  the DNS-01 challenge.
- An SMTP account for outgoing mail. Invitations, notification digests and the
  reporters' portal links all arrive by email; without it people cannot be invited.

Roughly 1GB of RAM is enough for a small team.

## Install

```sh
git clone https://github.com/you/buggie && cd buggie
cp .env.selfhost.example .env
```

Edit `.env` and set at least:

| Variable | |
|---|---|
| `APP_URL` | `https://buggie.example.com` |
| `APP_DOMAIN` | `buggie.example.com` — no scheme, include a port only in development |
| `SESSION_DOMAIN` | `.buggie.example.com` — the leading dot shares the session across workspace subdomains |
| `DB_PASSWORD` | anything long |
| `MAIL_*` | your SMTP details |

Then:

```sh
docker compose -f docker-compose.selfhost.yml build

docker compose -f docker-compose.selfhost.yml run --rm \
    --entrypoint php app artisan key:generate --show
# paste the base64:… value into APP_KEY in .env

docker compose -f docker-compose.selfhost.yml up -d
```

Migrations run automatically on start. Open your domain, create an account, and the
first workspace you make is yours.

`APP_KEY` encrypts sessions and stored credentials. Keep a copy, and do not change it
once there is data.

## TLS

The container serves plain HTTP on `APP_PORT` (8080 by default). Put it behind
something that terminates TLS — Caddy, nginx, Traefik or a load balancer — and make
sure it forwards the original host header, because the host is how Buggie decides which
workspace you are looking at.

A minimal Caddyfile:

```
*.buggie.example.com, buggie.example.com {
    reverse_proxy localhost:8080
    tls you@example.com {
        dns cloudflare {env.CF_API_TOKEN}
    }
}
```

## Email in (optional)

Lets people file issues by writing to a project address, and reply to a notification to
comment on the issue.

1. Point a Mailgun inbound route at `https://buggie.example.com/api/mail/inbound`.
2. Set `MAIL_INBOUND_DOMAIN` and `MAILGUN_SIGNING_KEY` in `.env`.

Without a signing key the endpoint rejects everything, which is the safe default —
otherwise anyone could file issues in your workspaces.

Each project shows its own address in project settings.

## The widget

Serve it from your own domain; the snippet in project settings already points at it.
The bundle reads its own script URL to find the key, so nothing needs configuring.

If the sites you are embedding in have a strict `Content-Security-Policy`, they need
`connect-src` to allow your Buggie domain. Screenshot capture additionally loads
html2canvas from cdnjs; if that is blocked, reports still arrive, just without an
image.

## Backups

Two things hold state:

```sh
# Database
docker compose -f docker-compose.selfhost.yml exec -T postgres \
    pg_dump -U buggie buggie | gzip > buggie-$(date +%F).sql.gz

# Screenshots and attachments (the `storage` volume)
docker run --rm -v buggie_storage:/data -v "$PWD":/backup alpine \
    tar czf /backup/buggie-storage-$(date +%F).tar.gz -C /data .
```

Redis holds only cache, sessions and the queue. Losing it logs everyone out and drops
pending jobs; it is not worth backing up.

## Upgrading

```sh
git pull
docker compose -f docker-compose.selfhost.yml build
docker compose -f docker-compose.selfhost.yml up -d
```

Migrations run on start. Take a database backup first.

## Operating

The queue dashboard at `/horizon` is closed to everybody until you list yourself:

```
BUGGIE_OPERATORS=you@example.com
```

It shows jobs across every workspace, which is why it is not tied to a workspace role.

Error reporting is off. Set `SENTRY_LARAVEL_DSN` if you want it, pointed wherever you
like — nothing is ever sent to us.

## Troubleshooting

**Every page is a bare "Server Error"** — `APP_KEY` is unset. The container refuses to
start now and tells you, but an install from before that change may be in this state.

**Subdomains 404** — wildcard DNS is not resolving, or `APP_DOMAIN` does not match the
host you are visiting. It must be the bare domain, with no scheme.

**Signed out when switching workspaces** — `SESSION_DOMAIN` needs the leading dot.

**Digests never arrive** — the scheduler is not running. `docker compose ... logs app`
should show a `scheduler` process; the digests wait for it.
