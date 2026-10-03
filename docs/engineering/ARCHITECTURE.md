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

**users** — `id, name, email, password, role (parent|child, CHECK), timezone (IANA string(64), NOT NULL, default `Europe/Ljubljana`; validated with the `timezone:all` rule — M1-03), is_superadmin, parent_id → users (cascade), pairing_pin(6), pin_expires_at, revenuecat_id (unique), timestamps`. Indexes: `parent_id`, `pairing_pin`. **Family timezone** = the parent's `timezone`; a child uses its parent's (`User::familyTimezone()`, `Pet::familyTimezone()`). All timestamps stay UTC (`APP_TIMEZONE=UTC`).

**pets** — `id, user_id → users (child), breed_type (mutt|border_collie, CHECK), pet_dna JSONB (GIN), current_video_url, hunger_level, thirst_level, energy_level, hygiene_level (double precision 0–100, CHECK; Eloquent cast `float`; every API/broadcast/Filament output rounds half up to int via `Pet::displayMetric()` / `Pet::attributesToArray()`), daily_step_count, last_step_reset_at, last_decay_at (decay clock, nullable; set on create, backfilled to migration time), frozen_at (start of the current hard stop / illness freeze, null otherwise), born_at, is_active, pet_state (CHECK: idle|sleeping|low_energy|hungry|sick|playing), illness_until, escalation_level (0–3), {hunger,thirst,energy,hygiene}_zero_since, is_game_over, is_hard_stopped, certificate_eligible, timestamps`. Index `(user_id, is_active)`.

**activities_log** — `id, pet_id → pets, activity_type (fed_pet|watered_pet|walked_pet|cleaned_poop|ignored_warning, CHECK), value int nullable, created_at`. Index `(pet_id, created_at)`. `value` conventions for `ignored_warning`: 30 = phase 1, 10 = phase 2, 0 = phase 3, −1 = illness, −2 = game over.

**breed_configs** — `breed_slug (mutt | border-collie), daily_steps_required (4000 | 10000), hunger_decay_rate (8 | 12), premium_unlock`. Thirst rate is hard-coded in `PetDecayService` (10 | 15). Note the slug uses a hyphen and the enum uses an underscore — `BreedType::slug()` maps them.

**pet_media_jobs** — `id, pet_id → pets, request_id (unique, fal.ai), kind (video), pet_state, status (pending|completed|failed), result_url, error, completed_at`. Webhooks are accepted only for rows here.

`pets.media_status` — `disabled | pending | ready | failed` (reference image lifecycle).

**quiet_hours** — one row per parent: `school_start/end, bedtime_start/end (time), is_active`.

Laravel default tables: `password_reset_tokens, sessions, cache, jobs, failed_jobs, personal_access_tokens`.

## 3. HTTP API (`routes/api.php`)

| Method | Path | Auth | Controller | Notes |
|---|---|---|---|---|
| POST | `/api/login` | – | AuthController@login | email + password → `{token, user, pet}` (pet = own or child's active pet, raw model) |
| GET | `/api/user` | sanctum | AuthController@user | flat `{id,name,email,role,pet}` |
| POST | `/api/logout` | sanctum | AuthController@logout | |
| POST | `/api/parent/generate-pin` | sanctum, throttle:pairing (5/min) | PairingController@generatePin | |
| GET | `/api/parent/dashboard` | sanctum | ParentDashboardController@dashboard | `timezone` (family IANA tz) + pet + traffic light + quiet hours + 20 activities + 7-day chart (`weekly_performance[].date` = family-local day) |
| GET | `/api/parent/activities` | sanctum | ParentDashboardController@activities | paginated |
| POST | `/api/parent/hard-stop` | sanctum | ParentDashboardController@toggleHardStop | **toggle**, returns `is_hard_stopped` |
| GET/PUT | `/api/parent/quiet-hours` | sanctum | QuietHoursController | windows are family-local `HH:MM`; PUT also accepts optional `timezone` (IANA, saved on the parent in the same transaction); responses include `timezone` |
| PUT | `/api/parent/settings` | sanctum, parent only (`UserPolicy@updateFamilySettings`, 403 otherwise) | ParentSettingsController@update | `{timezone}` required, IANA name (case-sensitive, 422 otherwise) → `{message, settings: {timezone}}` (M1-03) |
| POST | `/api/child/pair` | sanctum, throttle:pairing | PairingController@pairChild | creates Mutt pet (offline Pet DNA, `media_status`); queues `GeneratePetReferenceImage` after commit when fal.ai is enabled |
| POST | `/api/webhooks/fal-ai` | ED25519 signature (fail closed) | FalAiWebhookController | matches `request_id` → `pet_media_jobs`; idempotent; only `*.fal.media` URLs |
| POST | `/api/webhooks/revenuecat` | Bearer secret (optional!) | RevenueCatWebhookController | |

**Missing (see ROADMAP M1-07):** every child action endpoint (pet state, feed, water, clean, steps, contract), registration, social login, reset.

Docs: Scramble at `/docs/api` (local env only), export via `php artisan openapi:export`.

## 4. Game loop

`routes/console.php` schedules `pets:process-decay` every minute (`withoutOverlapping`, `runInBackground`) → `PetDecayService::processAllActivePets()` then `EscalationService::processAllActivePets()`.

**PetDecayService** (M1-01/M1-02, scheduler selects IDs of active, non-game-over pets): decay is a pure function of (precise stored metrics, elapsed time `last_decay_at → now`, breed config, quiet hours). `updated_at` is never used, so other writes don't eat decay; missed ticks are caught up in full (no cap).
- **Concurrency:** each pet is processed in its own `DB::transaction` from a fresh `Pet::lockForUpdate()` read (the passed model is only an ID and is synced afterwards), so a concurrent write is never overwritten by a stale copy. Other metric writers must take the same lock (`backend/CLAUDE.md`; `EditPet` does).
- First tick with `last_decay_at = null` only starts the clock.
- **Frozen** while `is_hard_stopped`, ill (`illness_until` in the future), inactive or game over: metrics untouched, `last_decay_at` advanced to now (quiet write). Hard stop / illness also stamp `frozen_at`.
- **Thaw** (`Pet::applyThaw($at)`): shifts every non-null `*_zero_since` forward by `at − frozen_at` (neglect time spent frozen doesn't count), restarts the decay clock at `at`, clears `frozen_at`. `at` = now when a hard stop is lifted (`updating` hook — works without any tick during the freeze), `illness_until` when an illness expired between ticks (`thawIfDue()` at the start of the next decay/escalation tick). Re-activation / undoing game over restarts the decay clock.
- The elapsed interval is split into normal vs quiet time by jumping between quiet-window boundaries (`QuietHours::nextBoundaryAfter()`, ≤ 4 segments per day + DST transitions). Windows are read on the **family-local clock** (M1-03): 22:00–06:00 lasts 9 h on the fall-back night and 7 h on the spring-forward night; a boundary inside the spring gap takes effect at the jump, one inside the repeated hour occurs twice: hunger/thirst = breed rate × (normal h + 0.10 × quiet h) — hunger from `breed_configs.hunger_decay_rate` (8 | 12), thirst hard-coded 10 | 15 (M1-06); hygiene −1.5 %/h in normal hours only (interim until M1-05); energy untouched (M1-04).
- Values are clamped to 0–100, stored unrounded, and snapped to an integer when within 1e-6 (float noise). `*_zero_since` and `pet_state` use the precise value (zero = `<= 0`; e.g. 0.4 displays as 0 but isn't zero yet). Resets `daily_step_count` once per family-local day (local midnight, e.g. 22:00 UTC in Slovenian summer); sets `certificate_eligible` at 12 weeks.
- **Broadcast:** all tick writes are quiet; after commit the tick broadcasts one `PetUpdated('metric_changed')` only if a rounded metric, `pet_state` or `certificate_eligible` changed. A mutt broadcasts ~213× per 24 h instead of every minute.
- Verified by 24 h minute-by-minute simulations (`tests/Feature/PetDecayTest.php`): mutt hunger 0 % at exactly 750 min, thirst 600 min; BC hunger 500 min, thirst 400 min; hygiene 91 after 6 h. Identical with 5-min ticks or a 3 h scheduler gap.
⚠️ Remaining: no energy (M1-04), no random hygiene events (M1-05), `*_zero_since` set at tick time (not the exact crossing time) after a catch-up gap.

**EscalationService**: skips hard-stopped and ill pets entirely (M1-02 — no new escalation, illness or game over while frozen; `thawIfDue()` runs first so expired illness shifts the neglect clocks before evaluation). Then: game over (any metric 0 % ≥ 24 h → `is_active=false`, `is_game_over=true`) › illness (hygiene or energy 0 % ≥ 6 h outside quiet hours → `illness_until = now+12h`, state `sick`) › matrix (lowest metric ≤ 30 → level 1, ≤ 10 → level 2, any metric 0 % ≥ 1 h → level 3 + broadcast `parent_intervention_alarm`). Each escalation writes an `ignored_warning` activity. Push jobs are commented out (none exist).

## 5. Real-time

- Reverb (`BROADCAST_CONNECTION=reverb`), event `PetUpdated` (`broadcastAs: pet.updated`) on **public** `Channel("pet.updated.{id}")`; `ShouldBroadcast` with `$connection = 'sync'`.
- Fired by: `PetObserver::updated` (every non-quiet pet save while active; runs after the DB transaction commits), the decay tick (once per tick, only on displayed changes, after commit), `ActivityLogObserver::created`, and explicitly in escalation, webhooks and hard stop → duplicates remain outside the decay tick (M1-08).
- `channels.php` defines auth for `pet.updated.{petId}` but it is never used because the channel is public. Fix in M1-08.
- Mobile: `usePetWebSocket` (laravel-echo + pusher-js, `ws` only), maps payload into Zustand; fallback "polling" only pings the socket.

## 6. External services

| Service | Where | State |
|---|---|---|
| fal.ai (Flux schnell image via `fal.run`, Kling v1.6 pro i2v via `queue.fal.run`) | `FalAiService`, `FalWebhookVerifier`, `GeneratePetReferenceImage` job | Disabled without `FAL_AI_API_KEY`. Reference image generated in a queued job after pairing; video jobs recorded in `pet_media_jobs`; webhooks signature-verified. Video generation is not yet triggered by state changes (M4-03) |
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

Backend `.env` keys in use: `DB_*` (pgsql), `BROADCAST_CONNECTION`, `REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME`, `QUEUE_CONNECTION`, `FAL_AI_API_KEY`, `FAL_AI_JWKS_URL`, `FAL_AI_MEDIA_HOSTS`, `REVENUECAT_SECRET_KEY`, `REVENUECAT_PUBLIC_KEY`, `REVENUECAT_BORDER_COLLIE_PRODUCT_ID`.
Mobile: `EXPO_PUBLIC_API_URL`, `EXPO_PUBLIC_REVERB_APP_KEY`, `EXPO_PUBLIC_REVERB_HOST`, `EXPO_PUBLIC_REVERB_PORT`, `EXPO_PUBLIC_REVERB_SCHEME`.

## 9. Production

See `docs/PRODUCTION_DEPLOYMENT.md`. Single Hetzner host, Docker Compose: Caddy → app (PHP 8.3) / reverb; queue; scheduler (`schedule:work`); postgres 18; redis. Live at `https://api.petprep.si`.

## 10. Architecture decisions

ADR-001…011 are in the root `README.md` (Reverb, NativeWind, service layer, enums, Pet DNA, elapsed-time decay, dual theme, Zustand + TanStack Query, Filament access, OpenAPI → TS, Sail). New ADRs go in `docs/decisions/NNN-title.md`.
