# PetPrep — Production Environment Variables Reference

This document describes all environment variables used by the PetPrep production stack.

> ⚠️ **SECURITY NOTICE:** Real production secrets must NEVER be committed to Git. The `.env` file resides securely on the server at `/opt/petprep/.env`.

---

## 1. Application Core

| Variable | Required | Secret | Purpose | Example / Format |
| :--- | :--- | :--- | :--- | :--- |
| `APP_NAME` | Required | No | Name of the application | `PetPrep` |
| `APP_ENV` | Required | No | Environment mode (must be `production`) | `production` |
| `APP_KEY` | Required | **Yes** | 32-character AES encryption key (generated once) | `base64:...` |
| `APP_DEBUG` | Required | No | Debug mode (must be `false` in production) | `false` |
| `APP_URL` | Required | No | Full public URL of the application | `https://api.yourdomain.com` |
| `APP_TIMEZONE` | Optional | No | Timezone for timestamps | `UTC` |
| `APP_LOCALE` | Optional | No | Default application locale | `en` |
| `DOMAIN` | Required | No | Domain name used by Caddy for TLS routing | `api.yourdomain.com` |

---

## 2. Database (PostgreSQL 18)

| Variable | Required | Secret | Purpose | Example / Format |
| :--- | :--- | :--- | :--- | :--- |
| `DB_CONNECTION` | Required | No | Database driver | `pgsql` |
| `DB_HOST` | Required | No | PostgreSQL host container name | `postgres` |
| `DB_PORT` | Required | No | PostgreSQL port | `5432` |
| `DB_DATABASE` | Required | No | Production database name | `petprep_production` |
| `DB_USERNAME` | Required | **Yes** | PostgreSQL production user | `petprep_user` |
| `DB_PASSWORD` | Required | **Yes** | PostgreSQL strong password | `secure_generated_password` |

---

## 3. Redis (Cache, Queues, Broadcasting)

| Variable | Required | Secret | Purpose | Example / Format |
| :--- | :--- | :--- | :--- | :--- |
| `REDIS_CLIENT` | Required | No | PHP Redis client extension | `phpredis` |
| `REDIS_HOST` | Required | No | Redis host container name | `redis` |
| `REDIS_PORT` | Required | No | Redis port | `6379` |
| `REDIS_PASSWORD` | Optional | **Yes** | Redis authentication password | `null` or `password` |
| `CACHE_STORE` | Required | No | Cache driver (`redis` or `database`) | `redis` |
| `QUEUE_CONNECTION` | Required | No | Queue connection (`redis`) | `redis` |
| `SESSION_DRIVER` | Required | No | Session storage driver | `database` or `redis` |

---

## 4. Laravel Reverb (WebSockets)

| Variable | Required | Secret | Purpose | Example / Format |
| :--- | :--- | :--- | :--- | :--- |
| `BROADCAST_CONNECTION` | Required | No | Broadcast driver | `reverb` |
| `REVERB_APP_ID` | Required | No | Reverb Application ID | `763538` |
| `REVERB_APP_KEY` | Required | No | Reverb public app key (shared with mobile) | `clxd3fw5l7ggxgagu281` |
| `REVERB_APP_SECRET` | Required | **Yes** | Reverb server secret key | `secure_secret_key` |
| `REVERB_HOST` | Required | No | Public domain for WebSockets | `api.yourdomain.com` |
| `REVERB_PORT` | Required | No | Public WebSocket port (443 through Caddy) | `443` |
| `REVERB_SCHEME` | Required | No | Public scheme (`https` / `wss`) | `https` |
| `REVERB_SERVER_HOST` | Required | No | Internal binding host | `0.0.0.0` |
| `REVERB_SERVER_PORT` | Required | No | Internal listening port | `8080` |

---

## 5. Third-Party Integrations & Services

| Variable | Required | Secret | Purpose | Example / Format |
| :--- | :--- | :--- | :--- | :--- |
| `FAL_AI_API_KEY` | Optional | **Yes** | fal.ai API key for Kling 3.0 / Flux | `fal_key_...` |
| `FAL_AI_JWKS_URL` | Optional | No | fal.ai public keys for ED25519 webhook verification (no shared secret needed) | `https://rest.fal.ai/.well-known/jwks.json` |
| `FAL_AI_MEDIA_HOSTS` | Optional | No | Allowed hosts for generated media | `fal.media` |
| `AI_DAILY_BUDGET_USD` | Optional | No | Cap on **estimated** fal.ai spend per day (M4-07); calls over it are refused (fail closed), pets work without media. `0` = no AI calls | `5` |
| `AI_MONTHLY_BUDGET_USD` | Optional | No | Cap on estimated fal.ai spend per month | `50` |
| `AI_BUDGET_TIMEZONE` | Optional | No | Day / month boundary for the caps (operations clock) | `UTC` |
| `AI_REFERENCE_IMAGE_PROFILE` | Optional | No | Image profile from `config/media.php` for new pets' reference image (David 2026-10-05: Nano Banana Pro). **Remove the line** from an old `/opt/petprep/.env` that still says `flux_schnell` — an unknown key falls back to the default with an error log; the deploy preflight warns about non-default values | `nano_banana_pro` |
| `AI_STATE_VIDEO_PROFILE` | Optional | No | Video profile for the pet state videos (M4-03; David 2026-10-05: Kling 3.0 Pro, 5 s, no audio). `kling_v16_legacy` no longer exists (falls back to the default + error log) | `kling_v3_pro` |
| `PET_MEDIA_DISK` | Optional | No | Filesystem disk for stored pet images / videos (M4-05); `pet_media` = `storage/app/pet-media` on the `app_storage` volume | `pet_media` |
| `PET_MEDIA_MAX_IMAGE_MB` | Optional | No | Largest image downloaded from fal | `25` |
| `PET_MEDIA_MAX_VIDEO_MB` | Optional | No | Largest video downloaded from fal | `60` |
| `PET_MEDIA_DOWNLOAD_TIMEOUT` | Optional | No | Seconds per download (capped at 80 — below the queue's `retry_after` 90) | `60` |
| `PET_MEDIA_URL_TTL_MINUTES` | Optional | No | Lifetime of the signed media URLs in API responses (min 10); URLs stay identical for half of it and are valid between TTL and 1.5 × TTL | `60` |
| `PET_MEDIA_SERVE_VIA` | Optional | No | Who sends the bytes of `GET /api/media/{id}` (M4-05b): `caddy` = PHP checks signature + authz, Caddy serves the file (`X-Accel-Redirect`, read-only `app_storage`); `php` = stream from PHP (the app default for local dev / tests). **Production default `caddy` comes from `compose.production.yaml`** — leave the line out of `/opt/petprep/.env`; set `php` only as an emergency fallback (PRODUCTION_DEPLOYMENT.md §7), then `docker compose … up -d app` | `caddy` |
| `AI_PET_DNA_VERSION` | Optional | No | DNA for new pets: `2` unique traits (M4-08), `1` pre-M4 anchors | `2` |
| `AI_LAB_ENABLED` | Optional | No | Show the Filament AI Lab (superadmin only) | `true` |
| `AI_LAB_MAX_RUN_USD` | Optional | No | Max estimated cost of one AI Lab run | `3` |
| `AI_LAB_DAILY_USD` | Optional | No | Separate AI Lab budget per day (lab spend never counts against the pets' caps) | `3` |
| `AI_LAB_MONTHLY_USD` | Optional | No | Separate AI Lab budget per month | `30` |
| `FAL_MODEL_*` | Optional | No | Override a profile's fal endpoint id (`FAL_MODEL_FLUX_SCHNELL`, `FAL_MODEL_FLUX2_PRO`, `FAL_MODEL_NANO_BANANA_PRO`, `FAL_MODEL_SEEDREAM`, `FAL_MODEL_KLING_V3_PRO`, `FAL_MODEL_KLING_V26_PRO`, `FAL_MODEL_VEO31_FAST`, `FAL_MODEL_VEO31_LITE`) — only `owner/model/...` ids are accepted. **The price stays the profile's** (`config/media.php`): overriding the endpoint does not change the cost estimate | `fal-ai/flux-2-pro` |
| `PUSH_ENABLED` | Optional | No | Escalation pushes via Expo (M3-02). **Production default `true` comes from `compose.production.yaml`** — leave the line out of `/opt/petprep/.env`; set `false` there only to silence all pushes (then `docker compose … up -d`). Tests / local default `false` | `true` |
| `EXPO_ACCESS_TOKEN` | **Required before the closed beta** (PR #35 review) | **Yes** | expo.dev access token (robot user, scope: push) sent as `Authorization: Bearer` to the Expo Push API. **Turn on "Enhanced security for push notifications"** for the `petprep` project on expo.dev at the same time — without it anyone who learns a device's Expo token can push to it. Empty = unauthenticated sends (works today, not acceptable with real families) | `expo_...` |
| `PUSH_DEDUPE_MINUTES` | Optional | No | Duplicate guard: the same pet + push type at most once per window | `30` |
| `REVENUECAT_WEBHOOK_SECRET` | **Required before RevenueCat is enabled** (M3-08) | **Yes** | Shared secret of `POST /api/webhooks/revenuecat`; set the webhook's Authorization header in RevenueCat to `Bearer <this value>`. **Fails closed:** empty → every webhook call is answered 503 and nothing is processed. Replaces `REVENUECAT_SECRET_KEY` (no longer read) | long random string |
| `PAYMENTS_ENFORCED` | Optional | No | Kill switch for the payment lock (M3-11, `config/payments.php`). `false` (default) = an unpaid challenge keeps playing, no payment pushes. `true` (M3-13, no free trial) = an unpaid challenge dog is locked from its birth (contract) until a purchase. Set `true` only after the payments go-live checklist (DEPLOYMENT.md D7a) | `false` |
| `REVENUECAT_CHALLENGE_PRODUCTS` | Optional | No | Comma-separated store product id(s) of the consumable 12-week challenge (M3-11, PAYMENTS_SPEC P1); each purchase = one challenge credit. Replaces `REVENUECAT_ENTITLEMENTS` (never deployed, no longer read) | `petprep_challenge_12w` (default) |
| `REVENUECAT_ACCEPT_SANDBOX` | Optional | No | Whether SANDBOX purchases create / revoke challenge credits (they are always stored). Default `false` when `APP_ENV=production`, `true` elsewhere — set `true` in production only for a store review / TestFlight phase | `false` |
| `REVENUECAT_PUBLIC_KEY` | Optional | No | RevenueCat public SDK key | `test_...` or `appl_...` |
| `MAIL_MAILER` | Optional | No | Mail driver (`log`, `smtp`, `resend`, `ses`) | `log` |

---

## 6. Runtime / deploy knobs (M4-05b — not secrets, normally NOT in `/opt/petprep/.env`)

Set by the image, `compose.production.yaml` or `deploy-production.sh`; listed so nobody adds them to the `.env` by accident.

| Variable | Where | Purpose | Default |
| :--- | :--- | :--- | :--- |
| `PETPREP_IMAGE_TAG` | compose interpolation | Image tag of `petprep-app` / `petprep-web`. The deploy script sets `next` only for the build + smoke test of the new release. **Never put it in `/opt/petprep/.env`** (compose would read it for every command) | `production` |
| `PETPREP_OPTIMIZE` | container env | What `docker/production/entrypoint.sh` caches on start: `full` (`php artisan optimize`), `config` (config + events), `none`. Auto: `full` for php-fpm, `config` for everything else | auto |
| `VIEW_COMPILED_PATH` | image (`Dockerfile`) | Compiled Blade views inside the container (`bootstrap/cache/views`), not on the shared volume | `/var/www/html/bootstrap/cache/views` |
| `APP_ENV` / `APP_DEBUG` | compose `environment` | Forced to `production` / `false` for every PHP container, whatever the `.env` says | `production` / `false` |
| `DEPLOY_HEALTH_HOST` | deploy script env | Name checked with `curl --resolve <host>:443:127.0.0.1 https://<host>/up` | `api.petprep.si` |
| `DEPLOY_HEALTH_IP_HOST` | deploy script env | Fallback health check: `Host:` header for `http://127.0.0.1/up` (the IP site, D8) | `138.199.172.97` |
| `DEPLOY_APP_WAIT_TRIES` | deploy script env | `php-fpm-ping` attempts (3 s apart) after `up -d` | `40` |
| `DEPLOY_RETRY_SLEEP` | deploy script env | Seconds between `artisan up` / health check attempts | `3` |
| `DEPLOY_ALLOW_NO_BACKUP` | deploy script env | `1` = continue when the pre-deploy backup fails (emergency only) | `0` |
| `DEPLOY_COMPOSE_PROJECT` | deploy script env | Compose project (network + volume prefix, e.g. `backend_app_storage`). Only if production ever runs under another project name — the deploy aborts when the volume is missing while the app runs | `backend` |
| `DEPLOY_APP_UID` | deploy script env | uid the FPM image runs as; storage is chowned to it before every deploy | `1000` |
