#!/bin/sh
set -e

# Without an application key Laravel cannot encrypt sessions, and every page answers
# a bare "Server Error" — which tells an operator nothing. Say what is wrong and what
# to type instead.
if [ -z "${APP_KEY}" ]; then
    cat >&2 <<'MESSAGE'

  APP_KEY is not set.

  Generate one and put it in your .env:

      docker compose -f docker-compose.selfhost.yml run --rm \
          --entrypoint php app artisan key:generate --show

  Then start again. The key encrypts sessions and stored credentials, so keep it
  safe and do not change it once there is data.

MESSAGE
    exit 1
fi

# Caches are built at boot rather than at build time: they bake in configuration,
# and the image must not carry one deployment's secrets into another's.
# Deferred from the build, where the application could not boot for want of
# configuration.
php artisan package:discover --ansi

php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --isolated
fi

php artisan storage:link 2>/dev/null || true

exec "$@"
