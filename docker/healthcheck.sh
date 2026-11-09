#!/bin/sh
set -eu
role="$(cat /tmp/container-role 2>/dev/null || echo fpm)"
case "$role" in
    fpm)    php -r "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);" ;;
    reverb) php -r "exit(@fsockopen('127.0.0.1', (int)(getenv('REVERB_SERVER_PORT') ?: 8080)) ? 0 : 1);" ;;
    *)      pgrep -f artisan >/dev/null ;;
esac
