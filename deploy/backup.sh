#!/usr/bin/env bash
set -euo pipefail

# ──────────────────────────────────────────────────────────
# Buggie — Backup
# Nightly from cron:  0 3 * * * /srv/buggie/deploy/backup.sh
# ──────────────────────────────────────────────────────────
#
# A backup you have never restored is a hope, not a backup. Restore one into a
# scratch database before you rely on this — the procedure is in README.md.

COMPOSE="docker compose -f deploy/docker-compose.prod.yml"
APP_DIR="${BUGGIE_APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BACKUP_DIR="${BUGGIE_BACKUP_DIR:-/srv/backups}"
KEEP_DAYS=14

cd "$APP_DIR"
mkdir -p "$BACKUP_DIR"

stamp="$(date +%Y%m%d-%H%M%S)"

# ── Database ──
# --clean --if-exists so the dump can be restored over an existing database without
# dropping it by hand first.
$COMPOSE exec -T postgres pg_dump \
    --username "${DB_USERNAME:-buggie}" \
    --clean --if-exists \
    "${DB_DATABASE:-buggie}" | gzip > "$BACKUP_DIR/db-$stamp.sql.gz"

# ── Uploads ──
# Screenshots and attachments. The database references these by path, so a database
# backup without them restores to a tracker full of broken images.
$COMPOSE exec -T app tar -cz -C /var/www/html/storage/app . > "$BACKUP_DIR/storage-$stamp.tar.gz"

# ── Retention ──
find "$BACKUP_DIR" -name 'db-*.sql.gz' -mtime +$KEEP_DAYS -delete
find "$BACKUP_DIR" -name 'storage-*.tar.gz' -mtime +$KEEP_DAYS -delete

# ── Off the box ──
# A backup on the same disk as the thing it backs up protects against exactly one
# failure: you deleting something. Not against losing the server.
if [[ -n "${BUGGIE_BACKUP_REMOTE:-}" ]]; then
    rclone copy "$BACKUP_DIR" "$BUGGIE_BACKUP_REMOTE" --max-age 25h
else
    echo "⚠ BUGGIE_BACKUP_REMOTE is not set: backups are staying on this server only."
fi

echo "Backed up to $BACKUP_DIR (db-$stamp.sql.gz, storage-$stamp.tar.gz)"
