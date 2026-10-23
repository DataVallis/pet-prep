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
| `REDIS_TIMEOUT` / `REDIS_READ_TIMEOUT` | Optional | No | Redis connect / read timeout in seconds (fail fast when Redis is down) | `2.0` |
| `SYSTEM_HEALTH_CAPTURE` | Optional | No | Store reported exceptions + error log lines in `system_errors` for `/admin/system-health` (`false` = kill switch) | `true` |
| `SYSTEM_HEALTH_ISOLATED_CONNECTION` | Optional | No | Write captured errors over a second PostgreSQL connection (`system_health`, opened only when an error is recorded; `lock_timeout` 2 s, `statement_timeout` 3 s) so a rolled-back transaction keeps the row. Each PHP process may hold 1 extra PG connection. | `true` |
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
| `REVERB_MAX_REQUEST_SIZE` | Optional | No | Max HTTP request Reverb accepts (the server's event publish). Default 65 536 since M5-T09 / T10 — a `PetUpdated` with every school / game clip URL is ≈ 9 kB (measured largest: 8 768 B payload / 9 677 B publish body for a puppy with every clip; the old default 10 000 was too tight). Not to be confused with `REVERB_APP_MAX_MESSAGE_SIZE` (default 10 000), which only limits messages a **client** sends over the WebSocket — never the server's broadcasts. A change of `config/**` or `/opt/petprep/.env` recreates Reverb on the next deploy (fingerprint D19) | `65536` |

---

## 5. Third-Party Integrations & Services

| Variable | Required | Secret | Purpose | Example / Format |
| :--- | :--- | :--- | :--- | :--- |
| `FAL_AI_API_KEY` | Optional | **Yes** | fal.ai API key for Kling 3.0 / Flux | `fal_key_...` |
| `FAL_AI_BILLING_KEY` | Optional (M5-T15) | **Yes** | fal.ai key with the **`billing:usage:read`** scope (fal key presets *BILLING*, *READONLY* or *FULL*), used only to read the account balance (`GET api.fal.ai/v1/account/billing?expand=credits`) shown on `/admin/system-health` / dashboard and for the low-balance warning. Empty → `FAL_AI_API_KEY` is tried; if that key lacks the scope (fal answers 401 / 403) the balance shows as *unknown* and only the existing `fal_balance` refusals warn. The warning threshold is set in `/admin → AI budget` (default 20 $), not here | `fal_key_...` |
| `FAL_AI_JWKS_URL` | Optional | No | fal.ai public keys for ED25519 webhook verification (no shared secret needed) | `https://rest.fal.ai/.well-known/jwks.json` |
| `FAL_AI_MEDIA_HOSTS` | Optional | No | Allowed hosts for generated media | `fal.media` |
| `AI_DAILY_BUDGET_USD` | Optional | No | Cap on **estimated** fal.ai spend per day (M4-07); calls over it are refused (fail closed), pets work without media. `0` = no AI calls. **Default only** — since M4-07b the superadmin sets the budget in `/admin → AI budget` (no restart); an empty admin field falls back to this value | `5` |
| `AI_MONTHLY_BUDGET_USD` | Optional | No | Cap on estimated fal.ai spend per month — default only, overridden in `/admin → AI budget` (M4-07b) | `50` |
| `AI_BUDGET_TIMEZONE` | Optional | No | Day / month boundary for the caps (operations clock) | `UTC` |
| `AI_REFERENCE_IMAGE_PROFILE` | Optional | No | Image profile from `config/media.php` for new pets' reference image (David 2026-10-05: Nano Banana Pro). **Remove the line** from an old `/opt/petprep/.env` that still says `flux_schnell` — an unknown key falls back to the default with an error log; the deploy preflight warns about non-default values | `nano_banana_pro` |
| `AI_STATE_VIDEO_PROFILE` | Optional | No | Video profile for the pet state videos (M4-03; David 2026-10-05: Kling 3.0 Pro, 5 s, no audio). `kling_v16_legacy` no longer exists (falls back to the default + error log) | `kling_v3_pro` |
| `AI_KEYFRAME_PROFILE` | Optional | No | Image-edit profile for the state KEY IMAGES (M5-T08; David 2026-10-21: Nano Banana Pro Edit, ~$0.15 each). An unknown / disabled key → no key images (pets fall back to the pre-M5-T08 clips) | `nano_banana_pro_edit` |
| `AI_STATE_MEDIA_ENABLED` | Optional | No | **Do not use as the switch.** State media (key images, loops, transitions) is switched by the superadmin in `/admin → Feature switches` (`app_settings.state_media`, audited — M5-T08b, David 2026-10-21). This env value is only the default while no row exists; off in the panel also stops what is already queued, retried and swept (read uncached by the workers); back on resumes the waiting items at once (`ResumeStateMedia` job, queue worker needed) | `true` |
| `AI_PHASE_CLIPS_ENABLED` | Optional | No | **Do not use as the switch.** School & game phase clips + their pose images (M5-T09 / T10) are switched by the superadmin in `/admin → Feature switches` → »School & game clips« (`app_settings.phase_clips`, audited — QA PR #332 M2, David 2026-10-21). This env value is only the default while no row exists; off in the panel also stops what is already queued, retried and swept (read uncached by the workers); back on resumes the waiting items (`ResumeStateMedia`). Needs state media on too | `true` |
| `AI_QUALITY_CHECK_ENABLED` | Optional | No | **Do not use as the switch.** The automatic clip quality check (David 2026-10-23) is switched by the superadmin in `/admin → Feature switches` → »Quality check« (`app_settings.quality_check`, audited). This env value is only the default while no row exists; off = new clips are shown at once without a check | `true` |
| `AI_QUALITY_VISION_ENABLED` | Optional | No | Turns on the AI vision part of the quality check (`media.profiles.vision.openrouter_vision_gemini_flash`, fal `openrouter/router/vision`, production budget). **Off by default: the per-token price is not verified** — David enables it after checking the cost on the fal dashboard; off → the free technical check alone decides (clips »not AI-checked«) | `false` |
| `AI_QUALITY_VISION_PROFILE` | Optional | No | Vision profile key for the quality check | `openrouter_vision_gemini_flash` |
| `FFMPEG_BINARY` / `FFPROBE_BINARY` | Optional | No | ffmpeg / ffprobe binaries of the quality check (the production image installs both on `PATH`) | `ffmpeg` / `ffprobe` |
| `PET_MEDIA_DISK` | Optional | No | Filesystem disk for stored pet images / videos (M4-05); `pet_media` = `storage/app/pet-media` on the `app_storage` volume | `pet_media` |
| `PET_MEDIA_MAX_IMAGE_MB` | Optional | No | Largest image downloaded from fal | `25` |
| `PET_MEDIA_MAX_VIDEO_MB` | Optional | No | Largest video downloaded from fal | `60` |
| `PET_MEDIA_DOWNLOAD_TIMEOUT` | Optional | No | Seconds per download (capped at 80 — below the queue's `retry_after` 90) | `60` |
| `PET_MEDIA_URL_TTL_MINUTES` | Optional | No | Lifetime of the signed media URLs in API responses (min 10); URLs stay identical for half of it and are valid between TTL and 1.5 × TTL | `60` |
| `PET_MEDIA_SERVE_VIA` | Optional | No | Who sends the bytes of `GET /api/media/{id}` (M4-05b): `caddy` = PHP checks signature + authz, Caddy serves the file (`X-Accel-Redirect`, read-only `app_storage`); `php` = stream from PHP (the app default for local dev / tests). **Production default `caddy` comes from `compose.production.yaml`** — leave the line out of `/opt/petprep/.env`; set `php` only as an emergency fallback (PRODUCTION_DEPLOYMENT.md §7), then `docker compose … up -d app` | `caddy` |
| `AI_PET_DNA_VERSION` | Optional | No | DNA for new pets: `2` unique traits (M4-08), `1` pre-M4 anchors | `2` |
| `AI_LAB_ENABLED` | Optional | No | Show the Filament AI Lab (superadmin only) | `true` |
| `AI_LAB_MAX_RUN_USD` | Optional | No | Max estimated cost of one AI Lab run — default only, overridden in `/admin → AI budget` (M4-07c) | `3` |
| `AI_LAB_DAILY_USD` | Optional | No | Separate AI Lab budget per day (lab spend never counts against the pets' caps) — default only, overridden in `/admin → AI budget` (M4-07c) | `3` |
| `AI_LAB_MONTHLY_USD` | Optional | No | Separate AI Lab budget per month — default only, overridden in `/admin → AI budget` (M4-07c) | `30` |
| `FAL_MODEL_*` | Optional | No | Override a profile's fal endpoint id (`FAL_MODEL_FLUX_SCHNELL`, `FAL_MODEL_FLUX2_PRO`, `FAL_MODEL_NANO_BANANA_PRO`, `FAL_MODEL_SEEDREAM`, `FAL_MODEL_KLING_V3_PRO`, `FAL_MODEL_KLING_V26_PRO`, `FAL_MODEL_VEO31_FAST`, `FAL_MODEL_VEO31_LITE`) — only `owner/model/...` ids are accepted. **The price stays the profile's** (`config/media.php`): overriding the endpoint does not change the cost estimate | `fal-ai/flux-2-pro` |
| `PUSH_ENABLED` | Optional | No | Escalation pushes via Expo (M3-02). **Production default `true` comes from `compose.production.yaml`** — leave the line out of `/opt/petprep/.env`; set `false` there only to silence all pushes (then `docker compose … up -d`). Tests / local default `false` | `true` |
| `EXPO_ACCESS_TOKEN` | **Required before the closed beta** (PR #35 review) | **Yes** | expo.dev access token (robot user, scope: push) sent as `Authorization: Bearer` to the Expo Push API. **Turn on "Enhanced security for push notifications"** for the `petprep` project on expo.dev at the same time — without it anyone who learns a device's Expo token can push to it. Empty = unauthenticated sends (works today, not acceptable with real families) | `expo_...` |
| `PUSH_DEDUPE_MINUTES` | Optional | No | Duplicate guard: the same pet + push type at most once per window | `30` |
| `REVENUECAT_WEBHOOK_SECRET` | **Required before RevenueCat is enabled** (M3-08) | **Yes** | Shared secret of `POST /api/webhooks/revenuecat`; set the webhook's Authorization header in RevenueCat to `Bearer <this value>`. **Fails closed:** empty → every webhook call is answered 503 and nothing is processed. Replaces `REVENUECAT_SECRET_KEY` (no longer read) | long random string |
| `PAYMENTS_ENFORCED` | Optional | No | Kill switch for the payment lock (M3-11, `config/payments.php`). `false` (default) = an unpaid challenge keeps playing, no payment pushes. `true` (M3-13, no free trial) = an unpaid challenge dog is locked from its birth (contract) until a purchase. Set `true` only after the payments go-live checklist (DEPLOYMENT.md D7a) | `false` |
| `PETPREP_CATS_ENABLED` | Optional | No | **Since M5-R06-09 (2026-10-10) only a backwards-compatible override:** cats are switched in `/admin → Funkcije` (off / test families / everyone, stored in `app_settings`, default off). `true` forces *everyone* whatever the admin setting says (the admin page shows a warning); `false` / unset lets the admin switch decide — **keep it `false`/unset**. In every mode only an app build that declares `species_cat` gets a cat; switching off stops new cats only, existing cats keep working | `false` |
| `REVENUECAT_CHALLENGE_PRODUCTS` | Optional | No | Comma-separated store product id(s) of the consumable 12-week challenge (M3-11, PAYMENTS_SPEC P1); each purchase = one challenge credit. Replaces `REVENUECAT_ENTITLEMENTS` (never deployed, no longer read) | `petprep_challenge_12w` (default) |
| `REVENUECAT_ACCEPT_SANDBOX` | Optional | No | Whether SANDBOX purchases create / revoke challenge credits (they are always stored). Default `false` when `APP_ENV=production`, `true` elsewhere — set `true` in production only for a store review / TestFlight phase | `false` |
| `REVENUECAT_PUBLIC_KEY` | Optional | No | RevenueCat public SDK key | `test_...` or `appl_...` |
| `GOOGLE_CLIENT_IDS` | Optional (M2-10c) | No (public ids) | Comma-separated Google OAuth client IDs (web, iOS, Android) accepted as the `aud` of a Google ID token at `POST /api/auth/social`. **Default = PetPrep's own web / iOS / Android ids (in `config/services.php`) — no server change needed**; set only to override. An explicitly empty value (`GOOGLE_CLIENT_IDS=`) disables Google sign-in (503 `provider_disabled`) | `<web-id>.apps.googleusercontent.com,<ios-id>.apps.googleusercontent.com` |
| `APPLE_AUDIENCES` | Optional | No (public ids) | Comma-separated `aud` values accepted for Sign in with Apple (M2-10c): the iOS bundle id, later a Services ID for Android / web. Unset → `com.datavallis.petprep` (Apple works without any env); an empty value disables Apple sign-in | `com.datavallis.petprep` |
| `APPLE_SIGN_IN_PRIVATE_KEY` | **Required for Apple token revocation** (M2-10c, App Store guideline 5.1.1(v)) | **Yes** | The Sign in with Apple private key (`AuthKey_Z8WR3WL68G.p8`) as **base64 of the whole file on one line**: `base64 -i AuthKey_Z8WR3WL68G.p8 \| tr -d '\n'` (raw PEM with `\n` escapes is accepted too). Used only by the queued `RevokeAppleTokenJob` after a parent with a linked Apple identity deletes the account. **Empty → revocation is skipped** (one neutral warning in the log; the deletion still succeeds). Read by every PHP container through `env_file: /opt/petprep/.env`; the job runs on the `queue` container (`--queue=auth,default`) | base64 string |
| `APPLE_TEAM_ID` | Optional | No (public id) | Apple Developer Team ID for the revocation `client_secret` (`iss`). Default `4345XU4MU8` — no server change needed | `4345XU4MU8` |
| `APPLE_KEY_ID` | Optional | No (public id) | Key ID of the Sign in with Apple key (`kid`). Default `Z8WR3WL68G` | `Z8WR3WL68G` |
| `APPLE_CLIENT_ID` | Optional | No (public id) | **Override only:** `client_id` of Apple's `/auth/token` and `/auth/revoke`. Unset → the `aud` of the Apple token the parent re-authenticated with for the deletion (the id the code was issued to), else `com.datavallis.petprep` | (unset) |
| `MAIL_MAILER` | Optional | No | Mail driver (`log`, `smtp`, `resend`, `ses`). **No production mailer is documented or set up by the deploy (default `log`)** — with `log` / `array` the M5-T15 AI budget warnings go only to the Filament bell (database notifications); set a real mailer (+ its `MAIL_*` keys) to also e-mail them to the superadmins | `log` |

---

## 6. Runtime / deploy knobs (M4-05b — not secrets, normally NOT in `/opt/petprep/.env`)


Set by the image, `compose.production.yaml` or `deploy-production.sh`; listed so nobody adds them to the `.env` by accident.

| Variable | Where | Purpose | Default |
| :--- | :--- | :--- | :--- |
| `PETPREP_IMAGE_TAG` | compose interpolation | Image tag of `petprep-app` / `petprep-web`. The deploy script sets `next` only for the build + smoke test of the new release. **Never put it in `/opt/petprep/.env`** (compose would read it for every command) | `production` |
| `PETPREP_OPTIMIZE` | container env | What `docker/production/entrypoint.sh` caches on start: `full` (`php artisan optimize`), `config` (config + events), `none`. Auto: `full` for php-fpm, `config` for everything else | auto |
| `VIEW_COMPILED_PATH` | image (`Dockerfile`) | Compiled Blade views inside the container (`bootstrap/cache/views`), not on the shared volume | `/var/www/html/bootstrap/cache/views` |
| `APP_ENV` / `APP_DEBUG` | compose `environment` | Forced to `production` / `false` for every PHP container, whatever the `.env` says | `production` / `false` |
| `PETPREP_API_DOMAIN` | `/opt/petprep/.env` (optional) → compose → caddy | API site of the Caddyfile and the deploy health check (D18). Unset = the host of `APP_URL` (deploy script) | `api.petprep.si` |
| `PETPREP_CADDY_SITES` | `/opt/petprep/.env` (optional) → compose → caddy | `production` = API + `petprep.si` / `www` + raw-IP site; `api-only` = the API site only (a second server, e.g. the postponed dev server — no certificates for petprep.si). Unset = `production` when the API domain is `api.petprep.si`, else `api-only`. Other values fail the deploy preflight | derived |
| `DEPLOY_HEALTH_HOST` | deploy script env | Name checked with `curl --resolve <host>:443:127.0.0.1 https://<host>/up` | `PETPREP_API_DOMAIN` |
| `DEPLOY_HEALTH_IP_HOST` | deploy script env | Fallback health check: `Host:` header for `http://127.0.0.1/up` (the IP site, D8). Only with `PETPREP_CADDY_SITES=production`; empty = no fallback | `138.199.172.97` (production) / none |
| `DEPLOY_APP_WAIT_TRIES` | deploy script env | `php-fpm-ping` attempts (3 s apart) after `up -d` | `40` |
| `DEPLOY_RETRY_SLEEP` | deploy script env | Seconds between `artisan up` / health check attempts | `3` |
| `DEPLOY_ALLOW_NO_BACKUP` | deploy script env | `1` = continue when the pre-deploy backup fails (emergency only) | `0` |
| `DEPLOY_FORCE_MAINTENANCE` | deploy script env | `1` = maintenance mode even when no migration is pending (D19) | `0` |
| `DEPLOY_RECREATE_REVERB` | deploy script env | `1` = recreate reverb even when its inputs are unchanged (D19; drops open WebSockets) | `0` |
| `DEPLOY_RECREATE_CADDY` | deploy script env | `1` = recreate caddy even when its inputs are unchanged (D19) | `0` |
| `DEPLOY_COMPOSE_PROJECT` | deploy script env | Compose project (network + volume prefix, e.g. `backend_app_storage`). Only if production ever runs under another project name — the deploy aborts when the volume is missing while the app runs | `backend` |
| `DEPLOY_APP_UID` | deploy script env | uid the FPM image runs as; storage is chowned to it before every deploy | `1000` |
