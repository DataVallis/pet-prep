# PetPrep — Architecture (as built, updated 2026-10-03 — M1-04/05/06)

This describes what the code **actually does today**, not the target. Target behaviour lives in
`docs/product/PRODUCT_SPEC.md`; the gap is tracked in `docs/engineering/ROADMAP.md`.
Update this file whenever you change a route, table, event, or service contract.

## 1. Repository layout

```
PetPrep/                         git: DataVallis/pet-prep
├── backend/                     Laravel 11 API + Filament admin + Reverb (PHP 8.5 via Sail)
│   ├── app/Console/Commands/    pets:process-decay, openapi:export
│   ├── app/Enums/               UserRole, BreedType, ActivityType, PetStateEnum, HygieneEventStatus
│   ├── app/Events/PetUpdated    the only broadcast event
│   ├── app/Filament/Resources/  User, Pet, BreedConfig, ActivityLog
│   ├── app/Http/Controllers/    Auth, Pairing, ParentDashboard, QuietHours, FalAiWebhook, RevenueCatWebhook
│   ├── app/Http/Requests/       one FormRequest per write endpoint
│   ├── app/Models/              User, Pet, ActivityLog, BreedConfig, QuietHours, PetHygieneEvent, PetMediaJob
│   ├── app/Observers/           PetObserver, ActivityLogObserver (both broadcast PetUpdated)
│   ├── app/Services/            PairingService, PetDecayService, EscalationService, HygieneEventService,
│   │                            PetActivityService (steps, clean — no HTTP yet, M1-07), FalAiService
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

**pets** — `id, user_id → users (child), breed_type (mutt|border_collie, CHECK), pet_dna JSONB (GIN), current_video_url, hunger_level, thirst_level, energy_level, hygiene_level (double precision 0–100, CHECK; Eloquent cast `float`; every API/broadcast/Filament output rounds half up to int via `Pet::displayMetric()` / `Pet::attributesToArray()`), daily_step_count, last_step_reset_at (start of the current family-local step day; set at creation), last_step_sync_at (device time of the last accepted step sync — anti-cheat reference, cleared at midnight), last_decay_at (decay clock, nullable; set on create, backfilled to migration time), frozen_at (start of the current hard stop / illness freeze, null otherwise), hygiene_scheduled_through (date: last family-local day whose hygiene events exist), born_at, is_active, pet_state (CHECK: idle|sleeping|low_energy|hungry|sick|playing), illness_until (null again after recovery), walk_illness_due_at (planned start of a missed-walk illness — daily walk rule), escalation_level (0–3), {hunger,thirst,hygiene}_zero_since (neglect clocks), energy_zero_since (unused since the daily walk rule, always null), is_game_over, is_hard_stopped, certificate_eligible, timestamps`. Index `(user_id, is_active)`.

**activities_log** — `id, pet_id → pets, activity_type (fed_pet|watered_pet|walked_pet|cleaned_poop|ignored_warning, CHECK), value int nullable, created_at`. Index `(pet_id, created_at)`. `value` conventions for `ignored_warning`: 30 = phase 1, 10 = phase 2, 0 = phase 3, −1 = illness, −2 = game over; `walked_pet`: one row per day when a step sync first reaches the daily goal, value = the day's steps (not one row per sync — the dashboard counts a walk once); `cleaned_poop`: null.

**breed_configs** — every tunable game number (M1-06), mutt | border collie: `breed_slug (mutt | border-collie), daily_steps_required (4000 | 10000), hunger_decay_rate (8 | 12 %/h), thirst_decay_rate (10 | 15 %/h, CHECK ≥ 0), poops_per_day (1 | 2, CHECK 0–10), feed_windows (jsonb list of family-local [start, end) "HH:MM" pairs, default [["06:00","10:00"],["17:00","21:00"]], CHECK array), water_times_per_day (3), water_min_gap_minutes (180), premium_unlock`. Column defaults = mutt values (backfill for existing rows). `BreedConfigsSeeder` upserts by slug and runs on every production deploy (overwrites Filament edits — open question). Feed windows and water limits are enforced by the child API (M1-07). Note the slug uses a hyphen and the enum uses an underscore — `BreedType::slug()` maps them.

**pet_hygiene_events** (M1-05) — `id, pet_id → pets (cascade), local_date (family-local day), scheduled_at (UTC), status (pending|applied|skipped, CHECK), resolved_at, cleaned_at, timestamps`. Unique `(pet_id, scheduled_at)`, index `(pet_id, status, scheduled_at)`. `applied` = hygiene dropped to 0 at `scheduled_at`; `skipped` = fell into a freeze / before birth / into quiet hours changed later; `cleaned_at` = the clean that took care of it.

**pet_daily_walks** (daily walk rule, David 2026-10-03) — `id, pet_id → pets (cascade), local_date (family-local day), steps, goal (breed daily_steps_required that day), achieved (steps ≥ goal), birth_day, illness_due_at (planned illness start when the day had no walk at all), illness_started_at (when it actually started), illness_skipped_at (planned illness dropped: pet frozen when due, or evaluated only after its 12 h were over), timestamps`. Unique `(pet_id, local_date)`. One row per closed day; raw material for the parent dashboard (M2-05).

**pet_media_jobs** — `id, pet_id → pets, request_id (unique, fal.ai), kind (video), pet_state, status (pending|completed|failed), result_url, error, completed_at`. Webhooks are accepted only for rows here.

`pets.media_status` — `disabled | pending | ready | failed` (reference image lifecycle).

**quiet_hours** — one row per parent: `school_start/end, bedtime_start/end (time), is_active`.

Laravel default tables: `password_reset_tokens, sessions, cache, jobs, failed_jobs, personal_access_tokens`.

## 3. HTTP API (`routes/api.php`)

| Method | Path | Auth | Controller | Notes |
|---|---|---|---|---|
| POST | `/api/login` | – | AuthController@login | email + password → `{token, user, pet}` (pet = own or child's active pet, raw model) |
| GET | `/api/user` | sanctum | AuthController@user | flat `{id,name,email,role,pet}` — used by the app for session restore on launch (M1-12). ⚠️ The generated OpenAPI type still describes the bare `User` model without `pet`; mobile declares `UserResponse` in `client.ts` |
| POST | `/api/logout` | sanctum | AuthController@logout | |
| POST | `/api/parent/generate-pin` | sanctum, throttle:pairing (5/min) | PairingController@generatePin | `{pin, expires_at, expires_in_minutes: 15}`; each call replaces the previous PIN; 429 carries `Retry-After`. Used by the parent "Dodaj otroka" screen |
| GET | `/api/parent/dashboard` | sanctum | ParentDashboardController@dashboard | `timezone` (family IANA tz) + pet + traffic light + quiet hours + 20 activities + 7-day chart (`weekly_performance[].date` = family-local day; `completed` counts a walk at most once per day — one `walked_pet` row when the goal is reached) |
| GET | `/api/parent/activities` | sanctum | ParentDashboardController@activities | paginated |
| POST | `/api/parent/hard-stop` | sanctum | ParentDashboardController@toggleHardStop | **toggle**, returns `is_hard_stopped` |
| GET/PUT | `/api/parent/quiet-hours` | sanctum | QuietHoursController | windows are family-local `HH:MM`; PUT also accepts optional `timezone` (IANA, saved on the parent in the same transaction); responses include `timezone` |
| PUT | `/api/parent/settings` | sanctum, parent only (`UserPolicy@updateFamilySettings`, 403 otherwise) | ParentSettingsController@update | `{timezone}` required, IANA name (case-sensitive, 422 otherwise) → `{message, settings: {timezone}}` (M1-03) |
| POST | `/api/child/pair` | sanctum, throttle:pairing | PairingController@pairChild | creates Mutt pet (offline Pet DNA, `media_status`); queues `GeneratePetReferenceImage` after commit when fal.ai is enabled |
| POST | `/api/webhooks/fal-ai` | ED25519 signature (fail closed) | FalAiWebhookController | matches `request_id` → `pet_media_jobs`; idempotent; only `*.fal.media` URLs |
| POST | `/api/webhooks/revenuecat` | Bearer secret (optional!) | RevenueCatWebhookController | |

**Missing (see ROADMAP M1-07):** every child action endpoint (pet state, feed, water, clean, steps, contract), registration, social login, reset. The service layer for steps and cleaning exists (`PetActivityService`, §4); the endpoints only need FormRequests + policy + mapping `ActionResult::LOCKED` → 423.

Docs: Scramble at `/docs/api` (local env only), export via `php artisan openapi:export`.

## 4. Game loop

`routes/console.php` schedules `pets:process-decay` every minute (`withoutOverlapping`, `runInBackground`) → `PetDecayService::processAllActivePets()` then `EscalationService::processAllActivePets()`.

**PetDecayService** (M1-01/M1-02, scheduler selects IDs of active, non-game-over pets): decay is a pure function of (precise stored metrics, elapsed time `last_decay_at → now`, breed config, quiet hours). `updated_at` is never used, so other writes don't eat decay; missed ticks are caught up in full (no cap).
- **Concurrency:** each pet is processed in its own `DB::transaction` from a fresh `Pet::lockForUpdate()` read (the passed model is only an ID and is synced afterwards), so a concurrent write is never overwritten by a stale copy. Other metric writers must take the same lock (`backend/CLAUDE.md`; `EditPet` does).
- First tick with `last_decay_at = null` only starts the clock.
- **Frozen** while `is_hard_stopped`, ill (`illness_until` in the future), inactive or game over: metrics untouched, `last_decay_at` advanced to now (quiet write). Hard stop / illness also stamp `frozen_at`. The local midnight still closes the day while hard-stopped / ill (walk row, steps → 0) but never schedules a walk illness, and a walk illness that comes due during a freeze is dropped.
- **Illness recovery = fresh start** (David 2026-10-03, `Pet::recoverFromIllnessIfDue()`; first thing in every decay tick, escalation tick, child action and model update once `illness_until` has passed): at `illness_until` → hygiene 100 % and `hygiene_zero_since` null (the vet cleaned the dog), every other running `*_zero_since` restarts at `illness_until`, `escalation_level` 0, `illness_until` null, decay clock resumes at `illness_until`. Hunger / thirst keep their values. If a hard stop is still on, `frozen_at` = `illness_until` (the freeze continues). One `PetUpdated('metric_changed')` after commit. Fixes the illness loop (before, the clock still showed ≥ 6 h after the 12 h).
- **Thaw of a hard stop** (`Pet::applyThaw($at)`): shifts every non-null `*_zero_since` forward by `at − frozen_at` (neglect time spent frozen doesn't count), restarts the decay clock at `at`, clears `frozen_at`. `at` = now when a hard stop is lifted (`updating` hook — works without any tick during the freeze). Re-activation / undoing game over restarts the decay clock.
- The elapsed interval is split into normal vs quiet time by jumping between quiet-window boundaries (`QuietHours::segmentsBetween()` / `splitSecondsBetween()` on top of `nextBoundaryAfter()`, ≤ 4 segments per day + DST transitions). Windows are read on the **family-local clock** (M1-03): 22:00–06:00 lasts 9 h on the fall-back night and 7 h on the spring-forward night; a boundary inside the spring gap takes effect at the jump, one inside the repeated hour occurs twice. Hunger/thirst = breed rate × (normal h + 0.10 × quiet h), rates from `breed_configs.hunger_decay_rate` / `thirst_decay_rate` (M1-06).
- **Energy (M1-04) = today's walk** — not time-decayed and **not an hourly neglect metric** (no `energy_zero_since`, no phase 3 / illness / game over from energy; daily walk rule, David 2026-10-03). `DailyWalkService::closeDayIfNeeded()` runs in every tick (and in every step sync): once the family-local date differs from `last_step_reset_at` it writes one `pet_daily_walks` row for the finished day (steps, goal, achieved; `firstOrCreate` → idempotent), then `Pet::resetDailyStepsIfNewDay()` sets `daily_step_count` and `energy_level` to 0 and clears `last_step_sync_at` (local midnight, e.g. 22:00 UTC in Slovenian summer). `last_step_reset_at` is set at creation, so a newborn keeps its initial energy until its first local midnight. Energy rises only through `PetActivityService::recordSteps()`.
- **Daily walk rule:** if the finished day ended with energy **showing 0 %** (no walk at all; < 0.5 % of the goal), is not the birth day, is exactly yesterday (one midnight passed — not after a multi-day scheduler outage) and the pet is not frozen, `pets.walk_illness_due_at` = the end of the quiet stretch that contains the midnight (`DailyWalkService::endOfQuietStretch`; bedtime 22–06 → 06:00 local; bedtime directly followed by school → end of school; no quiet hours → midnight). Some steps below the goal → only `achieved = false`. The illness time is computed from the quiet hours **in force at that midnight**; a later change of quiet hours does not move an already planned walk illness.
- **Energy backfill** (migration `2026_10_03_160100`, one-off): existing pets get `energy_level = min(100, daily_step_count / goal × 100)`; pets born on the current family-local day keep their energy (birth-day grace).
- **Hygiene (M1-05)** has no gradual decay. `HygieneEventService::ensureScheduled()` creates `poops_per_day` pending events for every family-local day from the day of `last_decay_at` to today (at most 7 past days): whole minutes drawn from the day's non-quiet time, one per equal share; RNG `Xoshiro256**` seeded with sha256(app key, pet id, date) — deterministic, not predictable by clients. `applyDue()` flips every pending event with `scheduled_at ≤ now`: `applied` if it lies in (`from`, now] and isn't quiet, otherwise `skipped` (freeze ticks and thaw advance `last_decay_at`, so events during a hard stop / illness or before birth are skipped). Any applied event → hygiene 0 and `hygiene_zero_since` = the earliest applied `scheduled_at` (exact also after a scheduler gap).
- Values are clamped to 0–100, stored unrounded, and snapped to an integer when within 1e-6 (float noise). `*_zero_since` and `pet_state` use the displayed value (zero = shows 0 %, i.e. precise < 0.5). Sets `certificate_eligible` at 12 weeks.
- **Broadcast:** all tick writes are quiet; after commit the tick broadcasts one `PetUpdated('metric_changed')` only if a rounded metric, `pet_state` or `certificate_eligible` changed. A mutt broadcasts ~213× per 24 h instead of every minute.
- Verified by 24 h minute-by-minute simulations (`tests/Feature/PetDecayTest.php`): mutt hunger 0 % at exactly 750 min, thirst 600 min; BC hunger 500 min, thirst 400 min; hygiene 91 after 6 h. Identical with 5-min ticks or a 3 h scheduler gap.
- Verified 24 h simulations with events enabled (mutt + BC): hunger/thirst zero minutes unchanged (750/600, 500/400), hygiene 100 % until an applied event and 0 % after, energy 0 % at minute 900 (local midnight).
- `pet_state`: `sick` only for hygiene 0 % (and during illness); energy 0 % shows `low_energy` outside quiet hours (`sleeping` inside).
⚠️ Remaining: hunger/thirst `*_zero_since` set at tick time (not the exact crossing time) after a catch-up gap.

**PetActivityService** (M1-04/M1-05; called by the future child API M1-07). Each action: transaction + `lockForUpdate()`, illness recovery first if due, refused with `ActionResult::LOCKED` while hard-stopped / ill / inactive / game over, quiet write, one `activities_log` row (created without model events), one `PetUpdated(<activity type>)` after commit.
- `recordSteps(Pet, int $stepsToday, Carbon $recordedAt)`: cumulative count for the family-local day, the max wins (`UNCHANGED` for repeats / lower counts). Anti-cheat: increment capped at 200 steps per minute between max(`last_step_sync_at`, local midnight) and `recordedAt` (future → now) — `CAPPED` keeps the plausible part, `REJECTED` if none; a sync dated on an earlier local day is `STALE`. Closes the previous day first (`DailyWalkService`, same as the tick). Energy = max(current, min(100, steps / `daily_steps_required` × 100)). Logs one `walked_pet` row per day, when the count first reaches the goal (value = steps). If the action itself changed nothing but closed the previous day or applied a recovery, it still broadcasts one `PetUpdated('metric_changed')` after commit.
- `clean(Pet)`: settles hygiene events already due but not yet ticked (`HygieneEventService::settleForCleaning`), stamps `cleaned_at`, hygiene → 100, `hygiene_zero_since` → null, logs `cleaned_poop`. `UNCHANGED` when already clean.

**EscalationService**: skips hard-stopped and ill pets entirely (M1-02 — no new escalation, illness or game over while frozen; `thawIfDue()` runs first: recovery of an expired illness, stale `frozen_at`). Neglect clocks = hunger, thirst, hygiene (**not energy**). Then: game over (hunger / thirst / hygiene 0 % ≥ 24 h → `is_active=false`, `is_game_over=true`) › illness — (a) `walk_illness_due_at` ≤ now → illness from `walk_illness_due_at` (`illness_until` = due + 12 h, `frozen_at` = due, walk row `illness_started_at`); if due + 12 h ≤ now (scheduler down) it is skipped instead (walk row `illness_skipped_at`, no recovery side effects); game over and reactivation clear a pending `walk_illness_due_at`, or (b) hygiene 0 % for ≥ 6 h **counted outside quiet hours only** (`QuietHours::splitSecondsBetween(zero_since, now)['normal']`) → `illness_until = now+12h`; state `sick`, `frozen_at` = start › matrix (lowest displayed metric ≤ 30 → level 1, ≤ 10 → level 2 — energy counts only **outside quiet hours**; hunger / thirst / hygiene 0 % ≥ 1 h → level 3 + broadcast `parent_intervention_alarm`). Each escalation writes an `ignored_warning` activity. Push jobs are commented out (none exist).

## 5. Real-time

- Reverb (`BROADCAST_CONNECTION=reverb`), event `PetUpdated` (`broadcastAs: pet.updated`) on **public** `Channel("pet.updated.{id}")`; `ShouldBroadcast` with `$connection = 'sync'`.
- Fired by: `PetObserver::updated` (every non-quiet pet save while active; runs after the DB transaction commits), the decay tick (once per tick, only on displayed changes, after commit), `PetActivityService` actions (once per applied action, after commit, event type = activity type; its activity rows bypass the observer), `ActivityLogObserver::created`, and explicitly in escalation, webhooks and hard stop → duplicates remain outside the decay tick (M1-08).
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

`index.ts → App (QueryClientProvider with the shared `src/api/queryClient.ts`, SafeArea) → AppNavigator`
→ **launch (M1-12):** `useSessionBootstrap` → `SplashScreen` ("Nalagam …") while `restoreSession()` reads the SecureStore token and calls `GET /api/user`. 200 → `appStore.signIn({token,user,pet})` (the same action a login uses: user, pet, `pairingStatus`, `lockState` from the pet's `is_game_over` / `illness_until`); 401 → token deleted → login; network error / 5xx → "Ni povezave" splash, token kept, "Poskusi znova" / "Odjava". Diagram: DIAGRAMS §2b.
→ not logged in → `PairingScreen` (email/password login, dev quick-login buttons, PIN → contract "Tap to Sign" → `POST /api/child/pair`)
→ `user.role === 'parent'` → `ParentDashboardScreen` (tabs: dashboard [timeline/chart still mock], controls, breeds paywall [simulated]). The dashboard now queries `GET /api/parent/dashboard` (TanStack, `['parent','dashboard']`) for loading / error / "no child" states; metrics still come from the session pet + Reverb. When the response is `"No child profile paired yet."` the dashboard shows the **"Dodaj otroka"** card (also in Controls) → `AddChildScreen`: `POST /api/parent/generate-pin`, PIN shown as `734 912` with a live 15-min countdown, "Nova koda", 429 cooldown from `Retry-After`, dashboard polled every 5 s while the PIN is valid → "Otrok je povezan!" + `GET /api/user` to load the pet.
→ child → `ChildHudScreen` (video/image/fallback background, 4 MetricBars, Feed/Water = local +20 % only, Walk → local overlay, Clean → local overlay) + `LockedScreen` overlay when `lockState !== 'none'`.
→ **logout** (both roles, the offline splash and the child PIN step's "Nazaj"): `modules/session/logout.ts` → `POST /api/logout` (best effort) → delete token → `queryClient.clear()` → `appStore.reset()`. Any later 401 on an authenticated request triggers the same local logout (`setUnauthorizedHandler` in `client.ts`).
Default API is production (`https://api.petprep.si`, Reverb wss :443) unless `EXPO_PUBLIC_*` env vars are set.

## 8. Configuration

Backend `.env` keys in use: `DB_*` (pgsql), `BROADCAST_CONNECTION`, `REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME`, `QUEUE_CONNECTION`, `FAL_AI_API_KEY`, `FAL_AI_JWKS_URL`, `FAL_AI_MEDIA_HOSTS`, `REVENUECAT_SECRET_KEY`, `REVENUECAT_PUBLIC_KEY`, `REVENUECAT_BORDER_COLLIE_PRODUCT_ID`.
Mobile: `EXPO_PUBLIC_API_URL`, `EXPO_PUBLIC_REVERB_APP_KEY`, `EXPO_PUBLIC_REVERB_HOST`, `EXPO_PUBLIC_REVERB_PORT`, `EXPO_PUBLIC_REVERB_SCHEME`.

## 9. Production

See `docs/PRODUCTION_DEPLOYMENT.md`. Single Hetzner host, Docker Compose: Caddy → app (PHP 8.3) / reverb; queue; scheduler (`schedule:work`); postgres 18; redis. Live at `https://api.petprep.si`.

## 10. Architecture decisions

ADR-001…011 are in the root `README.md` (Reverb, NativeWind, service layer, enums, Pet DNA, elapsed-time decay, dual theme, Zustand + TanStack Query, Filament access, OpenAPI → TS, Sail). New ADRs go in `docs/decisions/NNN-title.md`.
