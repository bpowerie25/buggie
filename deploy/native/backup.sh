#!/usr/bin/env bash
set -euo pipefail

# ──────────────────────────────────────────────────────────
# Buggie — Backup, for an install without Docker
# Every 15 minutes from cron, see deploy/native/README.md:
#   2,17,32,47 * * * * root flock -n /run/buggie-backup.lock /usr/local/sbin/buggie-backup >> /var/log/buggie-backup.log 2>&1
# ──────────────────────────────────────────────────────────
#
# For an install where PostgreSQL, PHP-FPM and the queue workers run on the host
# rather than in containers. The Docker setup has its own script, deploy/backup.sh.
#
# Each run writes the database, storage/app and .env into a staging folder and sends
# that folder to a restic repository. restic encrypts before upload and stores only
# what changed, so running every fifteen minutes costs little more than nightly.
#
# Settings come from /etc/buggie-backup.env (root-only, 0600):
#   BUGGIE_DIR=/var/www/buggie
#   RESTIC_REPOSITORY=s3:https://s3.eu-west-1.amazonaws.com/your-bucket
#   RESTIC_PASSWORD=...                 # also in a password manager, never only here
#   AWS_ACCESS_KEY_ID=...
#   AWS_SECRET_ACCESS_KEY=...
#   AWS_DEFAULT_REGION=eu-west-1
#   HC_URL=https://hc-ping.com/<uuid>   # optional dead-man's switch

ENV_FILE="${BUGGIE_BACKUP_ENV:-/etc/buggie-backup.env}"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && set -a && . "$ENV_FILE" && set +a

BUGGIE_DIR="${BUGGIE_DIR:-/var/www/buggie}"
DB_NAME="${DB_NAME:-buggie}"
# A folder this script owns outright, because it deletes old files from it. Pointed
# at a folder another job also wrote to, it once removed that job's nightly dumps.
STAGING="${STAGING:-/var/backups/buggie-restic}"
# restic holds the history. The local copies are only what the next run uploads.
LOCAL_KEEP_MINUTES="${LOCAL_KEEP_MINUTES:-60}"
STAMP="$(date +%F_%H%M)"
STATUS_FILE="$BUGGIE_DIR/storage/app/private/backup-status.json"

# ── Reporting ──
# The same status file deploy/backup.sh writes, read by App\Support\Backups\BackupStatus,
# so an operator sees a banner in the app when backups fail or stop. Without it the
# banner says no backup has ever been recorded, which is true of nothing and is
# therefore ignored, which is worse than no banner.
report() {
  local ok="$1" stage="$2" detail="$3" tmp
  [ -d "$(dirname "$STATUS_FILE")" ] || return 0
  tmp="$(mktemp "$STATUS_FILE.XXXX")" || return 0
  printf '{"at":"%s","ok":%s,"stage":"%s","detail":"%s","offsite":true}\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$ok" "$stage" "$detail" > "$tmp"
  chown --reference="$(dirname "$STATUS_FILE")" "$tmp" 2>/dev/null || true
  chmod 640 "$tmp"
  mv "$tmp" "$STATUS_FILE"
}

ping_hc() { [ -n "${HC_URL:-}" ] && curl -fsS -m 10 --retry 3 "$HC_URL$1" >/dev/null || true; }

fail() {
  echo "BACKUP FAILED at ${stage:-unknown}: $*" >&2
  report false "${stage:-unknown}" "$*" || true
  ping_hc /fail
  exit 1
}
trap 'fail "the backup did not finish (line $LINENO)"' ERR

ping_hc /start

stage="setup"
[ -f "$BUGGIE_DIR/.env" ] || fail "no .env in $BUGGIE_DIR"
umask 077
mkdir -p "$STAGING"
chmod 700 "$STAGING"

# ── Database ──
# As the postgres superuser through local peer authentication, so no password lives
# in this script. Ownership stays in the dump, so restoring it as postgres hands the
# tables back to the application's role.
stage="database"
db="$STAGING/buggie-db-$STAMP.sql.gz"
(cd / && runuser -u postgres -- pg_dump "$DB_NAME") | gzip > "$db"

# A truncated dump is worse than none because it looks like one.
stage="verifying the database dump"
gzip -t "$db"
[ "$(gzip -cd "$db" | head -c 4096 | wc -c)" -gt 1000 ] || fail "the dump is implausibly small"

# ── Uploads ──
# The database refers to screenshots and attachments by path; without them a restore
# is a tracker full of broken images.
stage="uploads"
tar czf "$STAGING/buggie-storage-$STAMP.tar.gz" -C "$BUGGIE_DIR/storage" app

# ── .env ──
# APP_KEY decrypts stored secrets such as the SMTP password set on the Instance
# screen. A database restored without the key it was written with cannot read them.
stage="env"
install -m 600 "$BUGGIE_DIR/.env" "$STAGING/buggie-env-$STAMP"

# ── Off the box ──
stage="offsite copy"
restic backup --tag buggie --host "$(hostname)" "$STAGING"

# Every run for the last four hours, one an hour for two days, then thinning out.
# Pruning rewrites the repository and is the slow part, so only the first run after
# 02:00 does it.
stage="retention"
prune=""
[ "$(date +%H)" = "02" ] && [ "$(date +%-M)" -lt 15 ] && prune="--prune"
# shellcheck disable=SC2086
restic forget --tag buggie --group-by host,tags \
  --keep-last 16 --keep-hourly 48 --keep-daily 7 --keep-weekly 4 --keep-monthly 6 $prune

find "$STAGING" -type f -mmin +"$LOCAL_KEEP_MINUTES" -delete

report true "done" "copied off the server"
ping_hc ""
echo "Backup OK: $STAMP"
