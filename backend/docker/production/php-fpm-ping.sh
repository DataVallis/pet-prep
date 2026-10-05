#!/bin/sh
# Exit 0 when PHP-FPM in this container answers its ping (compose healthcheck of
# `app`; deploy-production.sh waits for it before leaving maintenance mode).
#
# `env -i`: cgi-fcgi sends its whole environment as FastCGI params — in the
# container that is every variable of the production .env (secrets), and an
# oversized request is dropped by FPM. Send only what the ping needs.
out=$(env -i PATH=/usr/local/bin:/usr/bin:/bin \
    SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null) || exit 1
case "$out" in
    *pong*) exit 0 ;;
    *) exit 1 ;;
esac
