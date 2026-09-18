# Deploying the hosted service

For running Buggie *for other people* — the commercial service. If you are running it
for yourself, see [`SELF_HOSTING.md`](SELF_HOSTING.md) instead; it is simpler and has
no billing.

The difference is one flag:

```
BUGGIE_HOSTED=true
```

Which turns on plans, limits, the billing screens and the usage banner. Everything else
is identical.

## What is undecided

This file describes the shape, not a specific provider — that choice has not been made
yet. The requirements are ordinary:

- Container runtime (Fly, Render, ECS, Hetzner + Docker, anything).
- Managed Postgres 16 with backups.
- Managed Redis.
- S3-compatible object storage for screenshots.
- A CDN in front of `/widget/*` and `/build/*`.
- Mailgun, or another provider with inbound routes.

## Processes

The self-host image runs web, queue and scheduler under supervisor in one container,
which is the wrong shape here. Run the same image three ways instead:

| Process | Command | Notes |
|---|---|---|
| web | `supervisord` (or just php-fpm + nginx) | Scale horizontally |
| queue | `php artisan horizon` | Horizon supervises the workers |
| scheduler | `php artisan schedule:work` | **Exactly one.** Two send digests twice |

The scheduler is the one that must not be scaled. `notifications:flush` uses
`withoutOverlapping`, which protects against a slow run overlapping itself, not against
two hosts running it at once.

## Configuration

Beyond the self-hosting variables:

```
BUGGIE_HOSTED=true
BUGGIE_OPERATORS=you@example.com

STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_PRICE_TEAM=
STRIPE_PRICE_BUSINESS=

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_BUCKET=
AWS_DEFAULT_REGION=

SENTRY_LARAVEL_DSN=
```

Prices in `config/plans.php` are placeholders. The limits there are enforced; the
displayed amounts are cosmetic and the real number is whatever the Stripe price says.

## Stripe

1. Create the products and prices, and put the price ids in the environment.
2. Point a webhook at `/stripe/webhook` for the subscription events — Cashier registers
   the route and handles them.
3. Set `STRIPE_WEBHOOK_SECRET`.

Subscription state is read from the local `subscriptions` table, never from Stripe at
request time, so webhook delivery is what keeps entitlements correct. If webhooks stop,
plans silently go stale — worth an alert.

## DNS and TLS

Workspaces are subdomains, so `*.buggie.eu` needs a wildcard record and a wildcard
certificate.

`in.buggie.eu` is separate and points at Mailgun.

The widget is served from the app at `/w/{key}.js`; put a CDN in front of it. It is
embedded in customers' pages, so its availability is their availability.

## Before launch

- [ ] Wildcard DNS and TLS, including a workspace subdomain end to end
- [ ] Stripe webhooks delivering, verified with a real checkout
- [ ] Inbound mail delivering, verified with a real email
- [ ] Database backups, and a restore actually tested
- [ ] Object storage lifecycle rules — screenshots accumulate
- [ ] `APP_DEBUG=false`, and a check that a 500 shows nothing useful to a visitor
- [ ] Exactly one scheduler
- [ ] `/horizon` reachable only by `BUGGIE_OPERATORS`
- [ ] A retention policy for screenshots and reporter emails, which `docs/DESIGN.md`
      §11 lists as still undecided
