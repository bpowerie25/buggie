#!/usr/bin/env bash
set -euo pipefail

# ──────────────────────────────────────────────────────────
# Buggie — Backup
# Nightly from cron:  0 3 * * * /srv/buggie/deploy/backup.sh
# ──────────────────────────────────────────────────────────
#
# A backup you have never restored is a hope, not a backup. Restore one into a
# scratch database before you rely on this — the procedure is in deploy/RUNBOOK.md,
# under "Backups", and has been run against a real nightly dump.
#
# This script reports what it did into the application, not only into a log file,
# because a log file nobody opens is the same as no report at all. Whatever happens,
# the last line written is a status file the operator sees in the app.

# --project-directory and --env-file are both load-bearing. Without them Compose
# treats deploy/ as the project directory: it looks for deploy/.env, and resolves the
# build context and env_file from there too, so every variable comes back empty.
COMPOSE="docker compose --project-directory . --env-file .env -f deploy/docker-compose.prod.yml"
APP_DIR="${BUGGIE_APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BACKUP_DIR="${BUGGIE_BACKUP_DIR:-/srv/backups}"
KEEP_DAYS=14

cd "$APP_DIR"
mkdir -p "$BACKUP_DIR"

stamp="$(date +%Y%m%d-%H%M%S)"
db="$BACKUP_DIR/db-$stamp.sql.gz"
uploads="$BACKUP_DIR/storage-$stamp.tar.gz"

# ── Reporting ──
# Written through the container: storage/app is a named volume, so what the host can
# see here and what the application can read are two different directories.
report() {
    local ok="$1" stage="$2" detail="$3"

    $COMPOSE exec -T app sh -c "cat > /var/www/html/storage/app/backup-status.json" <<JSON || true
{"at":"$(date -u +%Y-%m-%dT%H:%M:%SZ)","ok":$ok,"stage":"$stage","detail":"$detail","offsite":$([[ -n "${BUGGIE_BACKUP_REMOTE:-}" ]] && echo true || echo false)}
JSON
}

# Any unexpected exit is a failed backup, and silence is the failure mode worth
# guarding against: the disk fills, pg_dump dies, and nothing says so for a month.
trap 'report false "${stage:-unknown}" "the backup did not finish"' ERR

# ── Database ──
# --clean --if-exists so the dump can be restored over an existing database without
# dropping it by hand first.
stage="database"
$COMPOSE exec -T postgres pg_dump \
    --username "${DB_USERNAME:-buggie}" \
    --clean --if-exists \
    "${DB_DATABASE:-buggie}" | gzip > "$db"

# A truncated dump is worse than no dump, because it looks like one. pipefail catches
# pg_dump dying, but a disk that filled mid-write leaves a file that only fails when
# somebody tries to restore it, which is the worst possible moment to find out.
stage="verifying the database dump"
gzip -t "$db"

if [[ "$(stat -c %s "$db")" -lt 1000 ]]; then
    report false "$stage" "the dump is implausibly small"
    echo "✗ $db is only $(stat -c %s "$db") bytes — refusing to call that a backup." >&2
    exit 1
fi

# ── Uploads ──
# Screenshots and attachments. The database references these by path, so a database
# backup without them restores to a tracker full of broken images.
stage="uploads"
$COMPOSE exec -T app tar -cz -C /var/www/html/storage/app . > "$uploads"
gzip -t "$uploads"

# ── Retention ──
stage="retention"
find "$BACKUP_DIR" -name 'db-*.sql.gz' -mtime +$KEEP_DAYS -delete
find "$BACKUP_DIR" -name 'storage-*.tar.gz' -mtime +$KEEP_DAYS -delete

# ── Off the box ──
# A backup on the same disk as the thing it backs up protects against exactly one
# failure: you deleting something. Not against losing the server.
stage="offsite copy"

if [[ -n "${BUGGIE_BACKUP_REMOTE:-}" ]]; then
    if ! command -v rclone >/dev/null; then
        report false "$stage" "rclone is not installed"
        echo "✗ BUGGIE_BACKUP_REMOTE is set but rclone is not installed." >&2
        exit 1
    fi

    # --immutable: a remote copy is a record of what the database was that night, and
    # nothing here should ever be rewriting one. If a name collides, that is a
    # problem to look at rather than to overwrite.
    rclone copy "$BACKUP_DIR" "$BUGGIE_BACKUP_REMOTE" --max-age 25h --immutable

    # Retention on the far end too. `rclone copy` never deletes, so without this the
    # bucket grows for ever — cheap, but "for ever" is also a growing pile of
    # customer data nobody decided to keep.
    rclone delete "$BUGGIE_BACKUP_REMOTE" --min-age "${BUGGIE_BACKUP_KEEP_DAYS:-90}d"

    report true "done" "copied off the server"
    echo "Backed up to $BACKUP_DIR and to $BUGGIE_BACKUP_REMOTE"
else
    report true "done" "on this server only"
    echo "⚠ BUGGIE_BACKUP_REMOTE is not set: backups are staying on this server only."
    echo "Backed up to $BACKUP_DIR (db-$stamp.sql.gz, storage-$stamp.tar.gz)"
fi
