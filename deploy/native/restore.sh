#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────
# Buggie — Restore, for an install without Docker
# Restores what deploy/native/backup.sh took. See deploy/native/README.md.
# ──────────────────────────────────────────────────────────
#
#   buggie-restore list                     every snapshot, oldest first, with its time
#   buggie-restore inspect <ID|latest>      load that backup's database into a scratch DB
#                                           (buggie_inspect) and compare it with the live one.
#                                           Touches nothing live.
#   buggie-restore restore <ID|latest>      replace the live database and uploads with that backup.
#                                           Saves the current state first, asks for confirmation,
#                                           and keeps the site in maintenance mode meanwhile.
#
# Uses the same /etc/buggie-backup.env as the backup script.

# -E: the failure message must fire from inside the functions too, or a step that
# fails half way through a restore leaves the site in maintenance mode without a word.
set -Eeuo pipefail

ENV_FILE="${BUGGIE_BACKUP_ENV:-/etc/buggie-backup.env}"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && set -a && . "$ENV_FILE" && set +a

BUGGIE_DIR="${BUGGIE_DIR:-/var/www/buggie}"
DB_NAME="${DB_NAME:-buggie}"
DB_OWNER="${DB_OWNER:-buggie}"
SCRATCH_DB=buggie_inspect
WORK=/var/tmp/buggie-restore
SAFE_ROOT=/var/backups/buggie-pre-restore
LOCK=/run/buggie-backup.lock          # same lock as the cron job, so no backup runs mid-restore
WORKERS="${WORKERS:-all}"             # supervisor programs to stop during a restore, e.g. "buggie-worker buggie-horizon"

die()  { echo "ERROR: $*" >&2; exit 1; }
pg()   { (cd / && runuser -u postgres -- env PGOPTIONS="-c client_min_messages=warning" "$@"); }
db_exists() { pg psql -tAc "select 1 from pg_database where datname='$1'" | grep -q 1; }

APP_USER="$(stat -c %U "$BUGGIE_DIR/storage" 2>/dev/null || echo www-data)"
APP_GROUP="$(stat -c %G "$BUGGIE_DIR/storage" 2>/dev/null || echo www-data)"
artisan() { (cd "$BUGGIE_DIR" && runuser -u "$APP_USER" -- php artisan "$@"); }

# Restore the newest db/storage/env set inside a snapshot into $WORK.
# Sets SNAP_TIME, STAMP, DB_FILE, STORAGE_FILE, ENV_BACKUP.
fetch() {
  local snap="$1" newest dir base
  SNAP_TIME="$(restic snapshots --tag buggie --json "$snap" | python3 -c 'import json,sys;print(json.load(sys.stdin)[-1]["time"][:19].replace("T"," "))')" \
    || die "no snapshot '$snap' - run: buggie-restore list"
  newest="$(restic ls --tag buggie "$snap" | grep -E '/buggie-db-[0-9_-]+\.sql\.gz$' | sort | tail -1)"
  [ -n "$newest" ] || die "snapshot $snap has no database dump in it"
  dir="$(dirname "$newest")"
  STAMP="$(basename "$newest" | sed -E 's/^buggie-db-(.*)\.sql\.gz$/\1/')"

  rm -rf "$WORK"; install -d -m 700 "$WORK"
  echo "Fetching backup set $STAMP from snapshot $snap ..."
  restic restore --tag buggie "$snap" --target "$WORK" \
    --include "$dir/buggie-db-$STAMP.sql.gz" \
    --include "$dir/buggie-storage-$STAMP.tar.gz" \
    --include "$dir/buggie-env-$STAMP" >/dev/null

  base="$WORK$dir"
  DB_FILE="$base/buggie-db-$STAMP.sql.gz"
  STORAGE_FILE="$base/buggie-storage-$STAMP.tar.gz"
  ENV_BACKUP="$base/buggie-env-$STAMP"
  for f in "$DB_FILE" "$STORAGE_FILE" "$ENV_BACKUP"; do [ -s "$f" ] || die "missing from backup: $(basename "$f")"; done
  gzip -t "$DB_FILE" || die "the database dump in the backup is corrupt"
  gzip -t "$STORAGE_FILE" || die "the uploads archive in the backup is corrupt"
}

# Row count and newest created_at for every table that has one.
activity() {
  local db="$1"
  pg psql -d "$db" -qc "ANALYZE" >/dev/null
  pg psql -d "$db" -At -c "select table_name from information_schema.columns
                           where table_schema='public' and column_name='created_at' order by 1" |
  while read -r t; do
    pg psql -d "$db" -At -F '|' -c "select '$t', count(*), coalesce(to_char(max(created_at),'YYYY-MM-DD HH24:MI'),'-') from \"$t\""
  done
}

cmd_list() {
  restic snapshots --tag buggie --compact
  echo
  echo "Times are server time ($(date +%Z)). Each snapshot holds the data as it was at that moment."
  echo "Next: buggie-restore inspect <ID>"
}

cmd_inspect() {
  [ -n "${1:-}" ] || die "usage: buggie-restore inspect <ID|latest>"
  fetch "$1"
  echo "Loading into scratch database $SCRATCH_DB ..."
  pg dropdb --if-exists --force "$SCRATCH_DB"
  pg createdb "$SCRATCH_DB"
  gunzip -c "$DB_FILE" | pg psql -q -v ON_ERROR_STOP=0 -d "$SCRATCH_DB" >/dev/null 2>&1 || true

  echo
  echo "Backup taken: $SNAP_TIME (set $STAMP)"
  echo
  join -t '|' -a1 -a2 -e '-' -o 0,1.2,1.3,2.2,2.3 \
    <(activity "$SCRATCH_DB" | sort -t '|' -k1,1) <(db_exists "$DB_NAME" && activity "$DB_NAME" | sort -t '|' -k1,1 || true) |
    (echo "TABLE|BACKUP ROWS|BACKUP NEWEST|LIVE ROWS|LIVE NEWEST"; cat) |
    awk -F '|' '{ printf "%-28s %12s  %-17s %12s  %-17s\n", $1, $2, $3, $4, $5 }'
  echo
  echo "Look around:  runuser -u postgres -- psql $SCRATCH_DB"
  echo "Clean up:     runuser -u postgres -- dropdb $SCRATCH_DB"
  echo "Use it:       buggie-restore restore $1"
}

cmd_restore() {
  [ -n "${1:-}" ] || die "usage: buggie-restore restore <ID|latest>"
  exec 9>"$LOCK"; flock -n 9 || die "a backup is running right now - try again in a minute"
  fetch "$1"

  local restore_env=no live_key backup_key
  backup_key="$(grep -E '^APP_KEY=' "$ENV_BACKUP" | cut -d= -f2-)"
  live_key="$(grep -E '^APP_KEY=' "$BUGGIE_DIR/.env" 2>/dev/null | cut -d= -f2- || true)"
  if [ -z "$live_key" ]; then
    restore_env=yes
  elif [ "$live_key" != "$backup_key" ]; then
    echo "WARNING: the live APP_KEY differs from the one in the backup."
    echo "Encrypted settings in the restored database (e.g. the SMTP password) need the backup's key."
    read -r -p "Replace .env with the backed-up one? [y/N] " a
    [[ "$a" =~ ^[Yy] ]] && restore_env=yes
  fi

  local SAFE
  SAFE="$SAFE_ROOT/$(date +%F_%H%M%S)"
  cat <<EOF

About to REPLACE the live Buggie data on $(hostname):
  Backup taken:     $SNAP_TIME (set $STAMP)
  Database:         $DB_NAME  (dropped and reloaded)
  Uploads:          $BUGGIE_DIR/storage/app  (replaced)
  .env:             $( [ "$restore_env" = yes ] && echo "replaced from backup" || echo "kept as is (APP_KEY matches)" )
  Current state saved to: $SAFE
Anything created after $SNAP_TIME will be gone from the live site (it stays in the safety copy).

EOF
  read -r -p "Type RESTORE to continue: " a
  [ "$a" = "RESTORE" ] || die "cancelled"

  # Safety copy of what's live now
  install -d -m 700 "$SAFE_ROOT" "$SAFE"
  db_exists "$DB_NAME" && pg pg_dump "$DB_NAME" | gzip > "$SAFE/db.sql.gz"
  [ -d "$BUGGIE_DIR/storage/app" ] && tar czf "$SAFE/storage.tar.gz" -C "$BUGGIE_DIR/storage" app
  [ -f "$BUGGIE_DIR/.env" ] && cp -p "$BUGGIE_DIR/.env" "$SAFE/env"
  echo "Safety copy saved."

  # Only the main shell speaks: with -E the trap also runs in each helper's subshell.
  trap '[ "$BASH_SUBSHELL" -eq 0 ] && { trap - ERR; printf "\nRESTORE FAILED. The site is in maintenance mode with workers stopped.\nThe pre-restore state is in %s.\n" "$SAFE"; }' ERR

  artisan down || true
  local workers; read -r -a workers <<< "$WORKERS"
  supervisorctl stop "${workers[@]}" >/dev/null

  if [ "$restore_env" = yes ]; then
    if [ -f "$BUGGIE_DIR/.env" ]; then cat "$ENV_BACKUP" > "$BUGGIE_DIR/.env"
    else install -m 640 -o root -g "$APP_GROUP" "$ENV_BACKUP" "$BUGGIE_DIR/.env"; fi
  fi

  # The database role must exist before the dump can hand tables back to it (fresh server case)
  if ! pg psql -tAc "select 1 from pg_roles where rolname='$DB_OWNER'" | grep -q 1; then
    local pw; pw="$(grep -E '^DB_PASSWORD=' "$BUGGIE_DIR/.env" | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/')"
    echo "create role \"$DB_OWNER\" login password :'pw';" | pg psql -q -v ON_ERROR_STOP=1 -v pw="$pw"
  fi

  echo "Reloading database ..."
  pg dropdb --if-exists --force "$DB_NAME"
  pg createdb -O "$DB_OWNER" "$DB_NAME"
  gunzip -c "$DB_FILE" | pg psql -q -v ON_ERROR_STOP=1 -d "$DB_NAME" >/dev/null

  echo "Restoring uploads ..."
  local tmp; tmp="$(mktemp -d "$BUGGIE_DIR/storage/.restore.XXXX")"
  tar xzf "$STORAGE_FILE" -C "$tmp"
  rm -rf "$BUGGIE_DIR/storage/app"
  mv "$tmp/app" "$BUGGIE_DIR/storage/app"
  rmdir "$tmp"
  chown -R "$APP_USER:$APP_GROUP" "$BUGGIE_DIR/storage/app"

  artisan migrate --force          # in case the backup predates the deployed code
  artisan optimize:clear
  supervisorctl start "${workers[@]}" >/dev/null
  artisan up
  trap - ERR
  rm -rf "$WORK"

  echo
  echo "Restored to $SNAP_TIME. Log in and check recent issues and an attachment."
  echo "To undo, the previous live state is in $SAFE."
}

case "${1:-}" in
  list)    cmd_list ;;
  inspect) cmd_inspect "${2:-}" ;;
  restore) cmd_restore "${2:-}" ;;
  *) sed -n '7,14p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
