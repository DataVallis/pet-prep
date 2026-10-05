# PetPrep — Access & Credentials

## Local Development URLs

| Service | URL | Notes |
|---------|-----|-------|
| **Laravel API** | `http://localhost:8000` | `php artisan serve` |
| **Reverb WebSocket** | `ws://localhost:8080` | `php artisan reverb:start` |
| **Filament Admin Panel** | `http://localhost:8000/admin` | Requires superadmin login |
| **Swagger / OpenAPI Docs** | `http://localhost:8000/docs/api` | Interactive API documentation (Scramble) |
| **OpenAPI JSON Spec** | `http://localhost:8000/docs/api.json` | Raw OpenAPI 3.1 JSON |
| **Expo Dev Server (Mobile)** | `http://localhost:8081` | `cd mobile && npx expo start` |

## Default Credentials

### Superadmin (Filament Admin Panel)
- **Email:** `admin@petprep.io`
- **Password:** `Password123!`
- Created by `SuperadminSeeder`. Has access to the Filament admin panel at `/admin`.

### Test Parent Account (auto-seeded)
- **Email:** `parent@test.com`
- **Password:** `password`
- Created by `TestUsersSeeder`. Has a child + pet pre-configured.

### Test Child Account (auto-seeded)
- **Email:** `child@test.com`
- **Password:** `password`
- Linked to the test parent. Has a pet (Mutt breed).

### Register a new parent account (M2-10a)
In the app: **Sem starš → "Nimate računa? Registracija"**. Or via the API (5 per minute / 20 per hour per IP):
```bash
curl -s -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Test Parent","email":"me@example.com","password":"Varno1Geslo","password_confirmation":"Varno1Geslo","timezone":"Europe/Ljubljana","accept_terms":true,"device_name":"curl"}'
# → 201 {"token":"…","abilities":["parent"],"user":{…},"pet":null,"awaiting_contract":null}
```
Password: ≥ 10 characters with upper + lower case and a digit. No e-mail verification yet (M2-10b) — use any address you control; don't register real families on production for testing.

### How to Get a Pairing PIN
```bash
# 1. Login as parent to get an API token
curl -s -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"parent@test.com","password":"password","device_name":"mobile"}'
# → returns {"token":"1|abc123...", "user":{...}}

# 2. Generate a 6-digit PIN (valid for 15 minutes)
curl -s -X POST http://localhost:8000/api/parent/generate-pin \
  -H "Authorization: Bearer <YOUR_TOKEN>"
# → returns {"pin":"858249","expires_at":"...","expires_in_minutes":15}
```

## Database Access

```bash
# Connect to the PetPrep PostgreSQL database
psql -d petprep

# View breed configurations
psql -d petprep -c "SELECT * FROM breed_configs;"

# View all users
psql -d petprep -c "SELECT id, name, email, role, is_superadmin, parent_id FROM users;"

# View all pets with their states
psql -d petprep -c "SELECT id, user_id, breed_type, pet_state, hunger_level, thirst_level, energy_level, hygiene_level, is_active, is_game_over FROM pets;"

# View quiet hours configurations
psql -d petprep -c "SELECT * FROM quiet_hours;"

# View recent activity logs
psql -d petprep -c "SELECT * FROM activities_log ORDER BY created_at DESC LIMIT 20;"
```

## Environment Variables

### Core
| Variable | Value | Description |
|----------|-------|-------------|
| `DB_CONNECTION` | `pgsql` | PostgreSQL driver |
| `DB_DATABASE` | `petprep` | Database name |
| `BROADCAST_CONNECTION` | `reverb` | WebSocket broadcast driver |

### Reverb (WebSockets)
| Variable | Description |
|----------|-------------|
| `REVERB_APP_ID` | Reverb application ID |
| `REVERB_APP_KEY` | Reverb app key (used by frontend) |
| `REVERB_APP_SECRET` | Reverb app secret |
| `REVERB_HOST` | WebSocket server host (`localhost`) |
| `REVERB_PORT` | WebSocket server port (`8080`) |

### fal.ai (AI Media Generation)
| Variable | Description |
|----------|-------------|
| `FAL_AI_API_KEY` | fal.ai API key (Flux reference image + Kling video). Empty = media generation disabled |
| `FAL_AI_JWKS_URL` | Optional. fal.ai public keys for webhook signature checks (default `https://rest.fal.ai/.well-known/jwks.json`) |
| `FAL_AI_MEDIA_HOSTS` | Optional. Comma-separated hosts allowed for generated media (default `fal.media`, incl. subdomains) |

There is **no webhook secret**: fal.ai signs webhooks with ED25519 and we verify the signature (`App\Services\FalWebhookVerifier`).

### RevenueCat (IAP — Phase 4)
| Variable | Description |
|----------|-------------|
| `REVENUECAT_SECRET_KEY` | RevenueCat secret API key for receipt verification |
| `REVENUECAT_PUBLIC_KEY` | RevenueCat public SDK key |

## Webhook Testing (fal.ai)

`POST /api/webhooks/fal-ai` only accepts webhooks **signed by fal.ai** for a `request_id` we created
(`pet_media_jobs` table). Unsigned curl calls are rejected with 401 by design.
To test, use the Pest suite (`tests/Feature/FalAiTest.php` signs requests with a test key pair), or trigger
a real generation with a valid `FAL_AI_API_KEY` and a public `APP_URL` (e.g. via a tunnel such as `cloudflared`):

```bash
./vendor/bin/sail artisan tinker
>>> app(App\Services\FalAiService::class)->generatePetVideoState(App\Models\Pet::first(), App\Enums\PetStateEnum::Idle);
```

## Webhook Testing (RevenueCat)

### Testing the RevenueCat Webhook Endpoint Locally

The RevenueCat webhook endpoint is at `POST /api/webhooks/revenuecat`.
It requires an `Authorization: Bearer {REVENUECAT_SECRET_KEY}` header.

```bash
# Simulate a successful Border Collie purchase webhook
curl -X POST http://localhost:8000/api/webhooks/revenuecat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your_revenuecat_secret_key" \
  -d '{
    "event": {
      "type": "NON_RENEWING_PURCHASE",
      "app_user_id": "1",
      "subscriber_id": "rc_customer_12345",
      "product_id": "border_collie_unlock",
      "store": "APP_STORE"
    }
  }'

# Simulate an ignored event type (subscription expired)
curl -X POST http://localhost:8000/api/webhooks/revenuecat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your_revenuecat_secret_key" \
  -d '{
    "event": {
      "type": "SUBSCRIPTION_EXPIRED",
      "app_user_id": "1"
    }
  }'
```

## Test Accounts (auto-seeded by `TestUsersSeeder`)

Run `php artisan migrate:fresh --seed` (or `npm run sail:migrate`) to create:

| Account | Email | Password | Role |
|---------|-------|----------|------|
| Superadmin | `admin@petprep.io` | `Password123!` | Admin panel access |
| Test Parent | `parent@test.com` | `password` | Parent profile |
| Test Child | `child@test.com` | `password` | Child profile (linked to parent) |

The test child has a pre-configured pet (Mutt breed). Use the parent login to generate pairing PINs.

## API Endpoints

### Authentication (Sanctum)
All authenticated endpoints require `Authorization: Bearer {token}` header.

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `POST` | `/api/register` | None (throttled) | Parent sign-up (M2-10a): parent + own family + parent token (201) |
| `POST` | `/api/login` | None | Login with email (case-insensitive) + password, returns Sanctum token |
| `GET` | `/api/user` | Bearer token | Get authenticated user's profile |
| `POST` | `/api/logout` | Bearer token | Revoke current token (logout) |

### Pairing
| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `POST` | `/api/parent/generate-pin` | Parent | Generate 6-digit pairing PIN (15-min expiry) |
| `POST` | `/api/child/pair` | Child | Pair child to parent via PIN |

### Parent Dashboard
| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/api/parent/dashboard` | Parent | Combined state: pet metrics, traffic light, quiet hours, activities, weekly chart |
| `GET` | `/api/parent/activities` | Parent | Paginated activity logs for timeline |
| `POST` | `/api/parent/hard-stop` | Parent | Toggle emergency hard stop (locks/unlocks child app) |

### Quiet Hours
| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/api/parent/quiet-hours` | Parent | Get quiet hours configuration |
| `PUT` | `/api/parent/quiet-hours` | Parent | Create/update quiet hours |

### Webhooks
| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `POST` | `/api/webhooks/fal-ai` | Webhook secret | Handle fal.ai video rendering callbacks |
| `POST` | `/api/webhooks/revenuecat` | Bearer secret | Handle RevenueCat IAP events (breed unlock) |

### Game Loop (Internal)
| Command | Description |
|---------|-------------|
| `php artisan pets:process-decay` | Process minutely metric decay + escalation for all active pets |
| `php artisan openapi:export` | Export OpenAPI JSON spec to `storage/api-docs/openapi.json` |

## TypeScript SDK Generation

```bash
# From the workspace root — generates mobile/src/api/schema.ts
npm run generate-api-types
```

This fetches `openapi.json` from the running Laravel backend and generates TypeScript interfaces.

## Mobile App Environment Variables

The mobile app reads environment variables prefixed with `EXPO_PUBLIC_`:

| Variable | Default | Description |
|----------|---------|-------------|
| `EXPO_PUBLIC_API_URL` | `http://localhost:8000` | Laravel API base URL |
| `EXPO_PUBLIC_REVERB_APP_KEY` | `clxd3fw5l7ggxgagu281` | Reverb app key (from backend `.env`) |
| `EXPO_PUBLIC_REVERB_HOST` | `localhost` | Reverb WebSocket server host |
| `EXPO_PUBLIC_REVERB_PORT` | `8080` | Reverb WebSocket server port |
| `EXPO_PUBLIC_REVERB_SCHEME` | `http` | WebSocket scheme (`http` or `https`) |

Set these in `mobile/.env` or via EAS Build environment variables for production.

## Mobile App Commands

```bash
# Start the Expo dev server
cd mobile && npx expo start

# Run mobile tests (Jest + React Native Testing Library)
cd mobile && npm test

# Run tests in watch mode
cd mobile && npm run test:watch

# Start iOS simulator
cd mobile && npx expo start --ios

# Start Android emulator
cd mobile && npx expo start --android
```
