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
| `MAIL_*` | your SMTP details — or leave them and use the screen described below |

Then:

```sh
docker compose -f docker-compose.selfhost.yml build

docker compose -f docker-compose.selfhost.yml run --rm \
    --entrypoint php app artisan key:generate --show
# paste the base64:… value into APP_KEY in .env

docker compose -f docker-compose.selfhost.yml up -d
```

Migrations run automatically on start. Then open your domain and register: on an
install with no accounts, the first registration is allowed whatever else is set, and
that account runs the install. See [First run, and who can join](#first-run-and-who-can-join).

`APP_KEY` encrypts sessions and stored credentials. Keep a copy, and do not change it
once there is data.

## First run, and who can join

**The first account.** On an install with no accounts at all, exactly one person may
register. That account is the install's **operator**: it configures mail, decides who
can join, and creates workspaces. Create your first workspace straight afterwards. If
two people race to register on an empty install, one wins and the other is refused.

The exception is used once and never offered again, even if every account is later
deleted. To get back in, or to set up an install without a browser, use the command:

```sh
docker compose -f docker-compose.selfhost.yml exec app php artisan buggie:operator you@example.com
```

It promotes an existing account, or creates one (it asks for a password, or generates
and prints one if there is no terminal). `--revoke` removes an operator, and `--list`
shows who operates the install and why.

**Everybody else arrives by invitation.** Invite people from a workspace's
**Settings → Members**. Somebody without an account follows the link in the email,
registers, and lands in the workspace — that works in every mode below.

**Registration mode.** Set under **Settings → Instance → Who can join**:

| Mode | |
|---|---|
| `invite` | **The default.** `/register` explains that sign-ups are by invitation. Only operators create workspaces. |
| `request` | As `invite`, and a workspace's sign-in page offers **Request access**. The workspace's owners and admins are emailed and decide under **Settings → Access requests**; approving sends an ordinary invitation. A request made on the bare domain asks for a new workspace and goes to operators, who see every request on the Instance screen. |
| `open` | Anyone may register and create a workspace. Right for a public service; almost never right for an agency's own server, because a stranger gets your storage, your outgoing mail, and a subdomain of your domain to put content on. |

To fix the mode from the command line instead, set it in `.env`, where it overrides the
screen:

```
BUGGIE_REGISTRATION=invite
```

A value that is not one of the three is treated as `invite`.

**Operators** are the accounts marked by the first run or by `buggie:operator`, plus
anybody named in `BUGGIE_OPERATORS`. A workspace owner is not an operator.

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

## Email out (do this before you invite anybody)

Nothing about Buggie fails loudly without it, which is the problem. Invitations,
password resets, notification digests and reporter portal links are all queued,
accepted and then written to a log file. A broken install looks exactly like a working
one until somebody says they never got their invitation — and they cannot say so,
because the way in was the invitation.

**There are two places to configure it, and the screen is the better one.**

`MAIL_*` in `.env` works and is the traditional route. But **Settings → Instance**, in
the application, is easier to live with: the password is encrypted at rest with your
`APP_KEY` rather than sitting in a file, changing it needs no redeploy, and there is a
**Send me a test** button that reports the provider's own error — `invalid_token` and
`no_service` are different problems with different fixes, and without that the only
symptom is a channel that is quiet.

The screen is reachable by an **operator**: the account made on first run, anybody
promoted with `buggie:operator`, and whoever is named in `BUGGIE_OPERATORS`. The first
account being an operator exists so you do not have to edit `.env` before you can
configure mail on your own server.

Settings saved there override `.env`, and `.env` stays as the fallback.

**Until it is set up**, every operator sees a banner saying so, and inviting somebody
tells you plainly that the invitation was created but not sent — the members screen
shows the invitation link, which you can send by hand.

### Getting it to arrive rather than to spam

Send from a domain you control — `hello@buggie.example.com`, never a Gmail address —
and add an **SPF** and a **DKIM** record for it. Without them a correctly configured
SMTP account still lands in spam, which looks identical to not sending at all.

## Email in (optional)

Lets people file issues by writing to a project address, and reply to a notification to
comment on the issue.

1. Point a Mailgun inbound route at `https://buggie.example.com/api/mail/inbound`.
2. Set `MAIL_INBOUND_DOMAIN` and `MAILGUN_SIGNING_KEY` in `.env`.

Both are needed. Without the domain, a project's address is a placeholder; without the
signing key the webhook refuses everything, which is correct and still means a
correctly addressed email vanishes. Until both are set the project screen says so
rather than offering an address that goes nowhere.

The signature check is Mailgun's specifically, so Mailgun is the path of least
resistance here even if you use somebody else for outgoing mail.

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

### Open sign-up is closed by default

Earlier versions let anybody register on a self-hosted install and create workspaces of
their own. An install that has never chosen a registration mode is now `invite` after
upgrading, which is the fix: nothing to do for that part.

Nobody loses anything. Existing members keep their workspaces, and invitations already
sent still work, including for people who have no account yet. If nobody was named in
`BUGGIE_OPERATORS`, the account that was your operator before the upgrade (the first
one created) is still the operator.

Then look at what strangers may have set up while the door was open:

```sh
docker compose -f docker-compose.selfhost.yml exec app php artisan buggie:audit-signups
```

It lists accounts that belong to no workspace and workspaces not owned by an operator,
with dates. It only reports. Removing anything is your decision.

**To reopen sign-up deliberately**, choose **Open** under Settings → Instance → Who can
join, or set `BUGGIE_REGISTRATION=open`.

## Operating

The queue dashboard at `/horizon` is closed to everybody until you list yourself:

```
BUGGIE_OPERATORS=you@example.com
```

It shows jobs across every workspace, which is why it is not tied to a workspace role.
Being an operator through the first run or `buggie:operator` does not open it — only
this list does.

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
