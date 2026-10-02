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
| `REVENUECAT_SECRET_KEY` | Optional | **Yes** | RevenueCat secret API key for IAP | `sk_...` |
| `REVENUECAT_PUBLIC_KEY` | Optional | No | RevenueCat public SDK key | `test_...` or `appl_...` |
| `MAIL_MAILER` | Optional | No | Mail driver (`log`, `smtp`, `resend`, `ses`) | `log` |
