#!/usr/bin/env bash
set -euo pipefail

# ──────────────────────────────────────────────────────────
# Buggie — Production Deployment
# Run as the deploy user from the repository root:  bash deploy/deploy.sh
# ──────────────────────────────────────────────────────────

# --project-directory and --env-file are both load-bearing. Without them Compose
# treats deploy/ as the project directory: it looks for deploy/.env, and resolves the
# build context and env_file from there too, so every variable comes back empty.
COMPOSE="docker compose --project-directory . --env-file .env -f deploy/docker-compose.prod.yml"
FIRST_RUN=0

# What to deploy. buggie.eu runs the private platform repo, which carries the public
# one as an upstream remote; a self-hoster runs the public repo directly. Same script
# either way, so there is only ever one deploy path to keep working.
REMOTE="${BUGGIE_REMOTE:-origin}"
BRANCH="${BUGGIE_BRANCH:-main}"

for arg in "$@"; do
    [[ "$arg" == "--first-run" ]] && FIRST_RUN=1
done

APP_DIR="${BUGGIE_APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

# ── 0. Run from a copy ──
# Step 2 pulls, and the pull can rewrite this very file. Bash reads a script
# incrementally as it executes, so replacing it mid-run makes the shell resume at a
# byte offset that now lands in the middle of a different command. Copy ourselves to
# /tmp and re-exec, so the running script is one git cannot touch.
if [[ "${BUGGIE_DEPLOY_REEXEC:-}" != "1" ]]; then
    RUNNER="$(mktemp /tmp/buggie-deploy.XXXXXX.sh)"
    cp "${BASH_SOURCE[0]}" "$RUNNER"
    export BUGGIE_DEPLOY_REEXEC=1
    export BUGGIE_APP_DIR="$APP_DIR"
    exec bash "$RUNNER" "$@"
fi

cd "$APP_DIR"

# ── Failure handling ──
# Maintenance mode is lifted only on success. A half-deployed application — assets
# wiped by a failed build, a routes cache that never got written — serves 500s on
# every page, and a maintenance page is always the better failure.
DEPLOY_OK=0

finish() {
    if [[ "$DEPLOY_OK" == "1" ]]; then
        $COMPOSE exec -T app php artisan up || true
        echo ""
        echo "✅ Deployed."
    else
        echo ""
        echo "❌ Deploy FAILED — left in maintenance mode on purpose."
        echo "   Visitors see the maintenance page, not a 500."
        echo ""
        echo "   Fix the failure above and re-run, or once you are sure the app is"
        echo "   serviceable:  $COMPOSE exec app php artisan up"
        echo ""
    fi
    rm -f /tmp/buggie-deploy.*.sh
}
trap finish EXIT

# ── 1. Preflight ──
[[ -f .env ]] || { echo "No .env. Copy deploy/env.production.example first."; exit 1; }

grep -q '^APP_KEY=base64:' .env || { echo "APP_KEY is not set in .env."; exit 1; }

# An unset APP_KEY gives a bare "Server Error" with nothing in the log explaining it,
# which is an hour of your life you do not get back. Checked here rather than found
# later.

if [[ "$FIRST_RUN" == "0" ]]; then
    $COMPOSE exec -T app php artisan down --render="errors::503" --retry=60 || true
fi

# ── 2. Code ──
git fetch "$REMOTE" "$BRANCH"

# Hard reset rather than pull: the server's tree is a deployment artefact, not
# somewhere to work. Anything uncommitted on it is an accident and should not
# survive, and a merge conflict mid-deploy is the worst possible time to find out.
git reset --hard "$REMOTE/$BRANCH"

# ── 3. Build ──
# Assets are built inside the image, so there is no node_modules on the server.
$COMPOSE build app caddy

# ── 4. Database ──
# Started before the app so migrations have something to talk to.
$COMPOSE up -d postgres redis
$COMPOSE run --rm --entrypoint php app artisan migrate --force

# ── 5. Up ──
$COMPOSE up -d --remove-orphans

# Give the container a moment to accept commands before caching config.
sleep 3

# ── 6. Caches ──
$COMPOSE exec -T app php artisan config:cache
$COMPOSE exec -T app php artisan route:cache
$COMPOSE exec -T app php artisan view:cache
$COMPOSE exec -T app php artisan storage:link || true

# ── 7. Prune ──
# Untagged images from previous builds fill a 40 GB disk faster than you would think.
docker image prune -f

DEPLOY_OK=1
