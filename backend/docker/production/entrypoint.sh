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

# Scheduler start (DEPLOYMENT.md D34, incident 2026-11-04): a reboot / container stop kills
# running scheduled commands before they release their withoutOverlapping mutex (Redis cache,
# AOF — it survives the reboot), and the scheduler then silently skipped the game loop for
# ~9 h. At the start of the ONE scheduler container (compose service `scheduler`) no scheduled
# run of this host is alive — they ran inside the previous container's process tree — so every
# schedule mutex left over is stale: clear them before `schedule:work`. Not fatal: a failure
# (Redis briefly unreachable) is logged and the explicit expiries in routes/console.php still
# bound the block.
if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ] && [ "${3:-}" = "schedule:work" ]; then
    php artisan schedule:clear-cache --no-interaction \
        || echo "petprep-entrypoint: schedule:clear-cache failed — stale overlap locks expire by themselves (D34)" >&2
fi

exec "$@"
