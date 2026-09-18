# Deploying buggie.eu

A single Hetzner box running the same images a self-hoster runs, with Caddy in front
for TLS. Follow this top to bottom the first time; after that only §6 matters.

Server: `188.245.198.86`. Deploys the **private** `buggie-platform` repository.

---

## 1. Key access, before anything else

Do this from your own machine, not the server.

```sh
ssh-copy-id -i ~/.ssh/id_ed25519_hetzner.pub root@188.245.198.86
```

It asks for the root password once. Confirm it worked before going further:

```sh
ssh -i ~/.ssh/id_ed25519_hetzner root@188.245.198.86 'echo in'
```

§3 disables password authentication. If the key is not on the box when that happens,
you are locked out and the only way back is the Hetzner web console. The setup script
refuses to run rather than let that happen, but check anyway.

---

## 2. DNS

Two records at Blacknight, both pointing at the server:

| Type | Name | Value |
|---|---|---|
| A | `buggie.eu` | `188.245.198.86` |
| A | `*.buggie.eu` | `188.245.198.86` |

The wildcard is not optional: every workspace is a subdomain.

Check before continuing — DNS changes are the single most common reason a first
deploy fails, and it fails late, after the certificate request:

```sh
dig +short buggie.eu
dig +short anything.buggie.eu
```

Both must print `188.245.198.86`.

### TLS: pick one

**Wildcard certificate (recommended).** Move the nameservers to Cloudflare — free,
registration stays with Blacknight — and create an API token with `Zone:DNS:Edit` on
buggie.eu and nothing else. One certificate covers every workspace. This is what
`deploy/caddy/` is built for.

**On-demand TLS.** Keeps Blacknight. Caddy issues a certificate per subdomain as
requests arrive, over HTTP-01, so no DNS API is needed. It needs an `ask` endpoint on
the application first, or anyone pointing a DNS record at this server can make it
request certificates until Let's Encrypt rate-limits the domain. Not built yet — ask
before taking this route.

---

## 3. Server setup

```sh
ssh -i ~/.ssh/id_ed25519_hetzner root@188.245.198.86

curl -fsSL https://raw.githubusercontent.com/bpowerie25/buggie/main/deploy/setup-server.sh -o setup-server.sh
less setup-server.sh        # read it before running it as root
bash setup-server.sh
```

It creates a `deploy` user, adds swap, installs Docker, disables password
authentication, and opens 22, 80 and 443.

SSH stays on port 22. Moving it stops nothing that key-only authentication and
fail2ban do not already stop, and automating it on a socket-activated sshd locked the
author out of this very box — `ListenStream=2222` binds IPv6 only.

**Open a second terminal and confirm key access still works before closing this one**,
since password authentication is now off:

```sh
ssh -i ~/.ssh/id_ed25519_hetzner deploy@188.245.198.86 'echo in'
```

---

## 4. Let the server read the private repository

As the `deploy` user:

```sh
ssh -i ~/.ssh/id_ed25519_hetzner deploy@188.245.198.86

ssh-keygen -t ed25519 -C "buggie.eu deploy" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Copy that line into GitHub → `bpowerie25/buggie-platform` → Settings → Deploy keys →
Add deploy key. **Leave "Allow write access" unticked.** The server only ever reads.

A deploy key rather than your own account's key: it is scoped to this one repository,
read-only, and revoking it if the box is ever compromised costs one click and affects
nothing else.

```sh
ssh -T git@github.com     # expect "successfully authenticated ... does not provide shell access"
```

---

## 5. Clone and configure

Still as `deploy`:

```sh
git clone git@github.com:bpowerie25/buggie-platform.git /srv/buggie
cd /srv/buggie

cp --update=none deploy/env.production.example .env
nano .env
```

Every line marked `CHANGE ME`. Generate the secrets on the spot:

```sh
openssl rand -base64 32   # DB_PASSWORD
openssl rand -hex 32      # MAIL_INBOUND_SECRET
```

`CLOUDFLARE_API_TOKEN` and `ACME_EMAIL` come from §2. `BUGGIE_OPERATORS` is your own
email address. Leave the Stripe values empty until there is something to sell — the
billing screens handle a plan with no price by not offering it.

Then the application key:

```sh
docker compose --project-directory . --env-file .env -f deploy/docker-compose.prod.yml run --rm \
    --entrypoint php app artisan key:generate --show
```

Paste the `base64:...` output into `APP_KEY=` in `.env`. The first run of this builds
the image, so give it a few minutes.

An unset `APP_KEY` produces a bare "Server Error" with nothing in the log to explain
it. `deploy.sh` refuses to run without one rather than let you find that out live.

---

## 6. Deploy

```sh
cd /srv/buggie
bash deploy/deploy.sh --first-run
```

`--first-run` skips putting the site into maintenance mode, since there is nothing
running yet. Every later deploy is just:

```sh
cd /srv/buggie && git pull && bash deploy/deploy.sh
```

If it fails, the site is **left in maintenance mode on purpose** — visitors see a
maintenance page rather than a 500. Fix the cause and re-run, or if you are satisfied
it is serviceable:

```sh
docker compose --project-directory . --env-file .env -f deploy/docker-compose.prod.yml exec app php artisan up
```

---

## 7. Check it actually works

```sh
curl -I https://buggie.eu                    # 200, and a valid certificate
curl -I https://anything.buggie.eu           # the wildcard certificate covers this too
docker compose --project-directory . --env-file .env -f deploy/docker-compose.prod.yml ps
```

All five services up: `app`, `caddy`, `postgres`, `redis`. Then in a browser: register
an account, create a workspace, create a project, and **send yourself a test email** —
password reset is the quickest. Mail configuration failing is silent, and every
invitation and notification depends on it.

Watch the first certificate being issued if it is slow:

```sh
docker compose --project-directory . --env-file .env -f deploy/docker-compose.prod.yml logs -f caddy
```

---

## 8. Backups

```sh
crontab -e
```

```
0 3 * * * /srv/buggie/deploy/backup.sh >> /srv/buggie/storage/logs/backup.log 2>&1
```

Set `BUGGIE_BACKUP_REMOTE` in `.env` to an `rclone` remote, or the backups sit on the
same disk as the thing they are backing up and protect against exactly one failure:
you deleting something by hand.

**Restore one before relying on it.** A backup nobody has restored is a hope.

```sh
gunzip -c /srv/backups/db-YYYYMMDD-HHMMSS.sql.gz | \
    docker compose --project-directory . --env-file .env -f deploy/docker-compose.prod.yml exec -T postgres \
    psql -U buggie -d buggie_restore_test
```

---

## Keeping the hosted service current

`buggie-platform` carries the public repository as an upstream remote. On your own
machine, not the server:

```sh
cd ~/Sites/buggie-platform
git fetch upstream && git merge upstream/main && git push
```

Then deploy as in §6. See [`../docs/PLATFORM_REPO.md`](../docs/PLATFORM_REPO.md).
