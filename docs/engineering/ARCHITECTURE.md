# PetPrep — Architecture (as built, 2026-10-02, updated after monorepo merge)

This describes what the code **actually does today**, not the target. Target behaviour lives in
`docs/product/PRODUCT_SPEC.md`; the gap is tracked in `docs/engineering/ROADMAP.md`.
Update this file whenever you change a route, table, event, or service contract.

## 1. Repository layout

```
PetPrep/                         git: DataVallis/pet-prep
├── backend/                     Laravel 11 API + Filament admin + Reverb (PHP 8.5 via Sail)
│   ├── app/Console/Commands/    pets:process-decay, openapi:export
│   ├── app/Enums/               UserRole, BreedType, ActivityType, PetStateEnum
│   ├── app/Events/PetUpdated    the only broadcast event
│   ├── app/Filament/Resources/  User, Pet, BreedConfig, ActivityLog
│   ├── app/Http/Controllers/    Auth, Pairing, ParentDashboard, QuietHours, FalAiWebhook, RevenueCatWebhook
│   ├── app/Http/Requests/       one FormRequest per write endpoint
│   ├── app/Models/              User, Pet, ActivityLog, BreedConfig, QuietHours
│   ├── app/Observers/           PetObserver, ActivityLogObserver (both broadcast PetUpdated)
│   ├── app/Services/            PairingService, PetDecayService, EscalationService, FalAiService
│   ├── database/                migrations, factories (Pet, User), seeders (BreedConfigs, Superadmin, TestUsers)
│   ├── routes/                  api.php, channels.php, console.php (scheduler)
│   └── tests/Feature/           Pest: Pairing, FalAi, PetDecay, Escalation, ParentDashboard, RevenueCatWebhook
├── mobile/                      Expo SDK 57, RN 0.86, React 19, EAS (merged from pet-prep-mobile 2026-10-02)
│   └── src/
│       ├── api/                 client.ts (fetch wrapper), schema.ts (generated OpenAPI types — unused)
│       ├── components/          ActionButton, MetricBar, CleaningOverlay, WalkTrackerOverlay (stale duplicate)
│       ├── hooks/               usePetWebSocket (Echo + pusher-js)
│       ├── modules/walk/        WalkTrackerOverlay (expo-sensors Pedometer)
│       ├── navigation/          AppNavigator (child, mounted), ParentAppNavigator (NOT mounted)
│       ├── screens/             PairingScreen, ChildHudScreen, LockedScreen, parent/{Dashboard,Controls,BreedPaywall}
│       ├── store/appStore.ts    Zustand: token, pet, ws status, lock state, overlays
│       └── utils/metrics.ts     colours, step formatting, virtual age, action disabling
├── scripts/                     generate-api-types.mjs, deploy/backup/restore-production*.sh
├── deployment/Caddyfile         TLS + reverse proxy (app, Reverb /app/*)
├── .github/workflows/           deploy-production.yml (test → rsync → deploy on push to main)
├── docs/                        product, business, engineering docs (this folder)
├── HANDOFF.md                   current state log — update after every task
└── CLAUDE.md                    agent operating manual
```

## 2. Data model (PostgreSQL)

**users** — `id, name, email, password, role (parent|child, CHECK), is_superadmin, parent_id → users (cascade), pairing_pin(6), pin_expires_at, revenuecat_id (unique), timestamps`. Indexes: `parent_id`, `pairing_pin`.

**pets** — `id, user_id → users (child), breed_type (mutt|border_collie, CHECK), pet_dna JSONB (GIN), current_video_url, hunger_level, thirst_level, energy_level, hygiene_level (int 0–100, CHECK), daily_step_count, last_step_reset_at, born_at, is_active, pet_state (CHECK: idle|sleeping|low_energy|hungry|sick|playing), illness_until, escalation_level (0–3), {hunger,thirst,energy,hygiene}_zero_since, is_game_over, is_hard_stopped, certificate_eligible, timestamps`. Index `(user_id, is_active)`.

**activities_log** — `id, pet_id → pets, activity_type (fed_pet|watered_pet|walked_pet|cleaned_poop|ignored_warning, CHECK), value int nullable, created_at`. Index `(pet_id, created_at)`. `value` conventions for `ignored_warning`: 30 = phase 1, 10 = phase 2, 0 = phase 3, −1 = illness, −2 = game over.

**breed_configs** — `breed_slug (mutt | border-collie), daily_steps_required (4000 | 10000), hunger_decay_rate (8 | 12), premium_unlock`. Thirst rate is hard-coded in `PetDecayService` (10 | 15). Note the slug uses a hyphen and the enum uses an underscore — `BreedType::slug()` maps them.

**quiet_hours** — one row per parent: `school_start/end, bedtime_start/end (time), is_active`.

Laravel default tables: `password_reset_tokens, sessions, cache, jobs, failed_jobs, personal_access_tokens`.

## 3. HTTP API (`routes/api.php`)

| Method | Path | Auth | Controller | Notes |
|---|---|---|---|---|
| POST | `/api/login` | – | AuthController@login | email + password → `{token, user, pet}` (pet = own or child's active pet, raw model) |
| GET | `/api/user` | sanctum | AuthController@user | flat `{id,name,email,role,pet}` |
| POST | `/api/logout` | sanctum | AuthController@logout | |
| POST | `/api/parent/generate-pin` | sanctum, throttle:pairing (5/min) | PairingController@generatePin | |
| GET | `/api/parent/dashboard` | sanctum | ParentDashboardController@dashboard | pet + traffic light + quiet hours + 20 activities + 7-day chart |
| GET | `/api/parent/activities` | sanctum | ParentDashboardController@activities | paginated |
| POST | `/api/parent/hard-stop` | sanctum | ParentDashboardController@toggleHardStop | **toggle**, returns `is_hard_stopped` |
| GET/PUT | `/api/parent/quiet-hours` | sanctum | QuietHoursController | |
| POST | `/api/child/pair` | sanctum, throttle:pairing | PairingController@pairChild | creates Mutt pet + calls fal.ai synchronously inside the DB transaction |
| POST | `/api/webhooks/fal-ai` | secret (optional!) | FalAiWebhookController | |
| POST | `/api/webhooks/revenuecat` | Bearer secret (optional!) | RevenueCatWebhookController | |

**Missing (see ROADMAP M1-07):** every child action endpoint (pet state, feed, water, clean, steps, contract), registration, social login, reset.

Docs: Scramble at `/docs/api` (local env only), export via `php artisan openapi:export`.

## 4. Game loop

`routes/console.php` schedules `pets:process-decay` every minute (`withoutOverlapping`, `runInBackground`) → `PetDecayService::processAllActivePets()` then `EscalationService::processAllActivePets()`.

**PetDecayService** (per active, non-game-over, non-ill pet): elapsed minutes = `now − updated_at`; hunger/thirst decay by breed rate (×0.10 during quiet hours); hygiene −1.5 %/h outside quiet hours; energy untouched; resets `daily_step_count` once per (UTC) day; tracks `*_zero_since`; derives `pet_state`; sets `certificate_eligible` at 12 weeks.
⚠️ Known defects: integer rounding + `updated_at` coupling (≈1.8× hunger/thirst speed, hygiene frozen), no energy decay, runs during hard stop, UTC everywhere. See AUDIT §2.1–2.2.

**EscalationService**: game over (any metric 0 % ≥ 24 h → `is_active=false`, `is_game_over=true`) › illness (hygiene or energy 0 % ≥ 6 h outside quiet hours → `illness_until = now+12h`, state `sick`) › matrix (lowest metric ≤ 30 → level 1, ≤ 10 → level 2, any metric 0 % ≥ 1 h → level 3 + broadcast `parent_intervention_alarm`). Each escalation writes an `ignored_warning` activity. Push jobs are commented out (none exist).

## 5. Real-time

- Reverb (`BROADCAST_CONNECTION=reverb`), event `PetUpdated` (`broadcastAs: pet.updated`) on **public** `Channel("pet.updated.{id}")`; `ShouldBroadcast` with `$connection = 'sync'`.
- Fired by: `PetObserver::updated` (every pet save while active), `ActivityLogObserver::created`, and explicitly in escalation, webhooks and hard stop → duplicates are common.
- `channels.php` defines auth for `pet.updated.{petId}` but it is never used because the channel is public. Fix in M1-08.
- Mobile: `usePetWebSocket` (laravel-echo + pusher-js, `ws` only), maps payload into Zustand; fallback "polling" only pings the socket.

## 6. External services

| Service | Where | State |
|---|---|---|
| fal.ai (Flux schnell image, Kling v1.6 pro i2v) | `FalAiService` | Disabled without `FAL_AI_API_KEY`; video generation is never triggered; webhook format/signature wrong |
| RevenueCat | webhook only | SDK not installed in the app; paywall is simulated with `setTimeout` |
| Push (Expo/APNs/FCM) | – | Not implemented |
| HealthKit / Health Connect | – | Not implemented; uses `expo-sensors` Pedometer (iOS only for history) |

## 7. Mobile app runtime flow (today)

`index.ts → App (QueryClientProvider, SafeArea) → AppNavigator`
→ not logged in → `PairingScreen` (email/password login, dev quick-login buttons, PIN → contract "Tap to Sign" → `POST /api/child/pair`)
→ `user.role === 'parent'` → `ParentDashboardScreen` (tabs: dashboard [timeline/chart still mock], controls, breeds paywall [simulated])
→ child → `ChildHudScreen` (video/image/fallback background, 4 MetricBars, Feed/Water = local +20 % only, Walk → local overlay, Clean → local overlay) + `LockedScreen` overlay when `lockState !== 'none'`.
Default API is production (`https://api.petprep.si`, Reverb wss :443) unless `EXPO_PUBLIC_*` env vars are set.

## 8. Configuration

Backend `.env` keys in use: `DB_*` (pgsql), `BROADCAST_CONNECTION`, `REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME`, `QUEUE_CONNECTION`, `FAL_AI_API_KEY`, `FAL_AI_WEBHOOK_SECRET`, `REVENUECAT_SECRET_KEY`, `REVENUECAT_PUBLIC_KEY`, `REVENUECAT_BORDER_COLLIE_PRODUCT_ID`.
Mobile: `EXPO_PUBLIC_API_URL`, `EXPO_PUBLIC_REVERB_APP_KEY`, `EXPO_PUBLIC_REVERB_HOST`, `EXPO_PUBLIC_REVERB_PORT`, `EXPO_PUBLIC_REVERB_SCHEME`.

## 9. Production

See `docs/PRODUCTION_DEPLOYMENT.md`. Single Hetzner host, Docker Compose: Caddy → app (PHP 8.3) / reverb; queue; scheduler (`schedule:work`); postgres 18; redis. Live at `https://api.petprep.si`.

## 10. Architecture decisions

ADR-001…011 are in the root `README.md` (Reverb, NativeWind, service layer, enums, Pet DNA, elapsed-time decay, dual theme, Zustand + TanStack Query, Filament access, OpenAPI → TS, Sail). New ADRs go in `docs/decisions/NNN-title.md`.
