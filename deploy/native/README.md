# Backups for an install without Docker

`docs/SELF_HOSTING.md` and `deploy/` assume Docker. Some installs run Buggie
directly on the host instead: PostgreSQL, PHP-FPM, nginx or Caddy, and the queue
workers under Supervisor. `deploy/backup.sh` cannot back those up, because every
command in it goes through `docker compose`. These two scripts do the same job for
that layout.

| | |
|---|---|
| `backup.sh` | Database, `storage/app` and `.env`, sent off the server with restic every 15 minutes |
| `restore.sh` | Lists the backups, compares one with the live data, and restores it |

They were written for a real install of this shape and have been run end to end
against PostgreSQL 16: a backup, an accidental delete, `inspect` showing the
missing rows, a restore bringing them back, and a restore failing half way to check
it says so. Stand-ins were used for `artisan` and `supervisorctl`, so do one
restore on a scratch server of your own before you rely on it.

## What it does

Every 15 minutes `backup.sh`:

1. dumps the database as the `postgres` user, so no password is kept anywhere,
2. archives `storage/app` (screenshots and attachments),
3. copies `.env`, because `APP_KEY` decrypts secrets stored in the database, such as
   the SMTP password set on the Instance screen,
4. sends the three to a restic repository, which encrypts them before upload and
   only stores what changed,
5. writes `storage/app/private/backup-status.json`, the same status file
   `deploy/backup.sh` writes. Operators see a banner in the app if a backup fails or
   none has run for 36 hours.

restic keeps every backup for the last four hours, one an hour for two days, then
one a day for a week, one a week for a month and one a month for six months.

Fifteen minutes is cheap at the size a tracker usually is: a run against a few
megabytes of data adds tens of kilobytes to the repository. If losing fifteen
minutes of issues is still too much, that is a job for continuous WAL archiving
(WAL-G or pgBackRest), not for running this more often.

## Setting it up

### 1. Somewhere to send it

Any storage restic supports. S3 on AWS is below; Hetzner, Backblaze B2, Cloudflare R2
and an SFTP box all work the same way with a different `RESTIC_REPOSITORY`. Put it
with a different provider from the server, so one account problem cannot take both.

On AWS, in the region you want the data kept in:

**Bucket.** S3 → Create bucket. Block all public access. **Versioning: on.** Object
Lock: off, because it stops restic pruning.

**Lifecycle rule** (bucket → Management): permanently delete noncurrent versions
after 30 days, and delete incomplete multipart uploads after 7.

Versioning is what protects the backups from the server. The server has to be able
to delete objects, because restic deletes its own locks and prunes old data. With
versioning on, a delete only hides an object, and the key below cannot remove old
versions. Somebody with root on the server can empty the bucket and you still have
30 days to get it back.

**Policy** (IAM → Policies → JSON), with your bucket name:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:ListBucket", "s3:GetBucketLocation"],
      "Resource": "arn:aws:s3:::your-bucket"
    },
    {
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::your-bucket/*"
    }
  ]
}
```

No `s3:DeleteObjectVersion`, and nothing that changes the bucket's own settings.

**User** (IAM → Users): no console access, that policy attached directly. Under
Security credentials, create an access key for "Application running outside AWS".

### 2. On the server

```sh
apt install restic

install -m 750 deploy/native/backup.sh  /usr/local/sbin/buggie-backup
install -m 750 deploy/native/restore.sh /usr/local/sbin/buggie-restore

install -m 600 /dev/null /etc/buggie-backup.env
openssl rand -base64 32          # the restic password
nano /etc/buggie-backup.env
```

```sh
BUGGIE_DIR=/var/www/buggie
RESTIC_REPOSITORY=s3:https://s3.eu-west-1.amazonaws.com/your-bucket
RESTIC_PASSWORD=...
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-west-1
# HC_URL=https://hc-ping.com/...        optional: healthchecks.io, 15 min period, 15 min grace
# WORKERS="buggie-worker buggie-horizon" if Supervisor runs anything that is not Buggie
```

**Put the restic password in a password manager now.** It cannot be recovered, and
the backups cannot be opened without it. The copy of `.env` inside the backup is no
help either, because it is encrypted with the same password.

```sh
set -a; . /etc/buggie-backup.env; set +a
restic init
buggie-backup
restic snapshots
```

Then schedule it:

```sh
echo '2,17,32,47 * * * * root flock -n /run/buggie-backup.lock /usr/local/sbin/buggie-backup >> /var/log/buggie-backup.log 2>&1' \
  > /etc/cron.d/buggie-backup
```

`flock` stops a run starting while the last is still going, and `buggie-restore`
takes the same lock so no backup runs in the middle of a restore.

**The staging folder must belong to this script alone.** It defaults to
`/var/backups/buggie-restic` and old files in it are deleted. Pointed at a folder
another job also writes to, it will delete that job's files.

## Restoring

```sh
buggie-restore list                 # every backup, with its time
buggie-restore inspect <ID>         # load it beside the live data and compare
buggie-restore restore <ID>         # put it back
```

`inspect` loads the backup into a separate database, `buggie_inspect`, and prints
each table's row count and newest record next to the live ones. Nothing live is
touched:

```
TABLE        BACKUP ROWS  BACKUP NEWEST       LIVE ROWS  LIVE NEWEST
issues              1204  2026-10-21 09:44         1180  2026-10-21 10:02
```

`restore` says what it is about to replace and waits for you to type `RESTORE`.
It saves the current live state to `/var/backups/buggie-pre-restore/` first, so a
restore can itself be undone. Then it puts the site in maintenance mode, stops the
workers, reloads the database and uploads, runs migrations in case the backup is
older than the code, and brings everything back. If a step fails it stops and says
so, and leaves the site in maintenance mode rather than half restored.

`.env` is only replaced when it is missing, as on a new server, or when its
`APP_KEY` differs from the backup's, and then only if you agree.

### Which backup

- **The server is gone.** `latest`, on the new server, after installing Buggie and
  `restic` and writing `/etc/buggie-backup.env`.
- **Something was deleted or broken at a known time.** The last backup before it.
  `inspect` it to see the missing data is there.
- **You do not know when.** `inspect` backups going back until the data reappears.
- **One project or a handful of issues.** Do not restore the whole install, which
  throws away everything everybody else did since. `inspect` the backup and copy the
  rows you need across from `buggie_inspect`.

Times are the server's, which is normally UTC.
