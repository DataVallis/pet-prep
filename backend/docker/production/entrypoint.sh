#!/bin/sh
# PetPrep production entrypoint (M4-05b).
#
# Builds the Laravel caches inside THIS container (bootstrap/cache and the
# compiled views live in the container, not on a shared volume), so every
# container — and every one-off `docker compose run --rm app php artisan …` —
# runs with caches that match its own code and the current production env:
#   php-fpm (app)       → `php artisan optimize` (config, events, routes, views,
#                          Filament components + Blade icons)
#   any other command   → config + events only (workers, scheduler, artisan)
# Override with PETPREP_OPTIMIZE=full|config|none. A failing cache build stops
# the container (fail fast: a broken config never serves traffic).
set -eu
cd /var/www/html

mode="${PETPREP_OPTIMIZE:-auto}"
if [ "$mode" = "auto" ]; then
    case "${1:-}" in
        php-fpm) mode=full ;;
        *) mode=config ;;
    esac
fi

case "$mode" in
    full)
        php artisan optimize --no-interaction
        ;;
    config)
        php artisan config:cache --no-interaction >/dev/null
        php artisan event:cache --no-interaction >/dev/null
        ;;
    none) ;;
    *)
        echo "petprep-entrypoint: unknown PETPREP_OPTIMIZE='${mode}' (full|config|none)" >&2
        exit 64
        ;;
esac

exec "$@"
