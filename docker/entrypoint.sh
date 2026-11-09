#!/bin/sh
# One image for every PHP process. First argument selects the role:
#   fpm | worker | scheduler | reverb ; anything else is executed as-is.
set -eu

role="${1:-fpm}"
echo "$role" > /tmp/container-role
cd /var/www/html

log() { printf '[entrypoint:%s] %s\n' "$role" "$*"; }

# Config comes from the environment; an empty .env avoids dotenv read warnings.
[ -f .env ] || touch .env

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache

if [ "${APP_ENV:-production}" = "local" ] && [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
    log "vendor/ missing, running composer install"
    composer install --no-interaction --prefer-dist
fi

wait_for_database() {
    php -r '
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "postgres",
            getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE") ?: "postgres");
        for ($i = 1; $i <= 60; $i++) {
            try { new PDO($dsn, getenv("DB_USERNAME") ?: "", getenv("DB_PASSWORD") ?: ""); exit(0); }
            catch (Throwable $e) { fwrite(STDERR, "  attempt $i: {$e->getMessage()}\n"); sleep(2); }
        }
        exit(1);
    '
}

case "$role" in
    fpm)
        wait_for_database
        [ "${RUN_MIGRATIONS:-false}" = "true" ] && php artisan migrate --force --isolated
        exec php-fpm --nodaemonize
        ;;
    worker)    wait_for_database; exec php artisan queue:work --tries=3 --backoff=30 --max-time=3600 ;;
    scheduler) wait_for_database; exec php artisan schedule:work ;;
    reverb)    exec php artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8080}" ;;
    *)         exec "$@" ;;
esac
