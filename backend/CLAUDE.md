# Backend (Laravel) — agent notes

Root rules in `../CLAUDE.md` apply. This file covers backend specifics.

## Stack
Laravel 11 (upgrade planned, M0-07) · PHP 8.5 in Sail · PostgreSQL 18 · Redis · Reverb · Sanctum tokens · Filament 3 · Scramble · Pest 3.
Run everything through Sail: `./vendor/bin/sail artisan …`, `./vendor/bin/sail test`, `./vendor/bin/sail pint`.

## Where things go
| Concern | Location |
|---|---|
| HTTP input validation | `app/Http/Requests/*Request.php` |
| Business rules | `app/Services/*Service.php` (inject via constructor) |
| Async / external calls (fal.ai, push, RevenueCat sync) | `app/Jobs/*` implementing `ShouldQueue` |
| Authorization | `app/Policies/*` + Sanctum abilities (`parent:*`, `child:*`) |
| Response shape | `app/Http/Resources/*Resource.php` (introduce; controllers currently build arrays inline) |
| Enums | `app/Enums/*` — mirror any new value in a DB CHECK constraint migration |
| Tunable game numbers | `breed_configs` table (+ seeder), never constants in services |
| Scheduled work | `routes/console.php` |

## Game-loop invariants (see PRODUCT_SPEC §4–7)
- Decay is a pure function of (previous state, elapsed time since `last_decay_at`, breed config, quiet-hours schedule). It never depends on `updated_at` (M1-01).
- Metrics are 0–100, stored as `double precision` (float cast). Don't lose fractional decay to rounding; round only for output via `Pet::displayMetric()` / `Pet::displayValue()` (half up). Never write a rounded value back. **Thresholds (escalation, pet state, zero tracking) compare the displayed value** — David, 2026-10-03.
- **Row lock for metric writes:** the decay tick re-reads each pet with `Pet::whereKey($id)->lockForUpdate()` inside `DB::transaction` and computes from that fresh row. Any other code that changes metrics or freeze state (child actions M1-07, admin edits — see `EditPet::handleRecordUpdate`, webhooks) must do the same: open a transaction, `lockForUpdate()` the pet, compute from the locked row, write, and broadcast after commit (`PetUpdated::afterCommit($pet, $eventType)`; there are no model observers any more). Never write metrics from a model loaded earlier.
- Frozen while `is_hard_stopped`, ill, inactive or game over. Hard stop and illness also freeze the neglect clocks (`*_zero_since`) and block escalation: `frozen_at` marks the freeze start. Lifting a hard stop (`Pet::applyThaw()` via the `updating` hook / `thawIfDue()`) shifts every `*_zero_since` forward by the frozen duration. **The end of an illness is a fresh start** (`Pet::recoverFromIllnessIfDue()`, David 2026-10-03): hygiene 100 %, running `*_zero_since` restart at `illness_until`, escalation 0, `illness_until` → null. Every writer that holds the pet lock calls it first (tick, escalation, `PetActivityService`, `updating` hook).
- Quiet hours: ×0.10 decay, no hygiene events, no pushes, hygiene illness clock paused (`EscalationService` counts only non-quiet seconds since `hygiene_zero_since`), no energy escalation.
- Energy is not time-decayed: it is `min(100, daily_step_count / daily_steps_required × 100)`, written only by `PetActivityService::recordSteps()` and zeroed at the family-local midnight (M1-04). **Energy is the daily walk, not a neglect metric** (David 2026-10-03): never use `energy_zero_since` (always null) — no phase 3 / illness / game over from energy. The midnight goes through `DailyWalkService::closeDayIfNeeded()` (walk row in `pet_daily_walks`, then `Pet::resetDailyStepsIfNewDay()`); a day whose energy showed 0 % sets `pets.walk_illness_due_at` (end of the night's quiet hours), which `EscalationService` turns into a normal illness.
- Hygiene has no gradual decay: `HygieneEventService` schedules `poops_per_day` events per local day in `pet_hygiene_events` and the tick applies those in (`last_decay_at`, now] (M1-05). Tests that are not about hygiene call `disableHygieneEvents($pet)`; tests that need fixed times plant rows (see `HygieneEventTest::hyPlant`) — the RNG seed includes the pet ID, which isn't stable across runs.
- Child actions go through `PetActivityService` (lock, `ActionResult`, one activity row, one broadcast after commit). HTTP: `ChildPetController` / `ChildContractController` + `Concerns/HandlesChildPet` map `ActionResult` → 200 / 422 (`CareRefusal` + `next_allowed_at`) / 409 / 423 (`PetLockReason`); every body carries `state` (`ChildPetStateResource`). Authz = `PetPolicy` (child only, own pet), never ad-hoc role checks.
- Feed / water first call `PetDecayService::catchUpLocked()` under the same lock (owed decay lands on the old value), then set 100 % and re-derive `pet_state`. Window / water rules live in `CareScheduleService` (family tz; `fed_pet` / `watered_pet` rows in `activities_log` are the source of truth). Feed/water are refused while hygiene shows 0 %.
- `EscalationService` follows the row-lock rule too (IDs → `lockForUpdate()` per pet, one `PetUpdated::afterCommit` per escalation step).
- Every state change the parent should see → exactly one `PetUpdated::afterCommit($pet, $eventType)` (single emission point; a plain `save()` broadcasts nothing). Queued on the `broadcasts` queue, `PrivateChannel('pet.{id}')` (owner child + parent, `PetPolicy::listen`), auth `POST /api/broadcasting/auth` (Sanctum). Payload = pet-state snapshot, no child PII. Broadcasting errors are logged, never thrown. The decay tick writes quietly and broadcasts itself only when a displayed value/state changed.
- Every child action and every escalation writes one `activities_log` row. Exception: step syncs log one `walked_pet` row per day, when the goal is first reached (the dashboard counts rows).

## Testing
- Pest feature tests in `tests/Feature`, unit tests in `tests/Unit` (create the folder when needed).
- `RefreshDatabase`; call `seedBreedConfigs()` (helper in `tests/Pest.php`) when a test needs breed data.
- Time: `$this->travel(...)` / `Carbon::setTestNow()`. For decay, simulate a full day minute-by-minute and assert against spec numbers.
- External HTTP: `Http::fake()`; broadcasts: `Event::fake([PetUpdated::class])`, assert on `$e->petId` / `$e->eventType`.
- `phpunit.xml` uses `BROADCAST_CONNECTION=log`, DB `testing` on the Sail pgsql service.

## Known traps
- `BreedType` enum uses `border_collie`; `breed_configs.breed_slug` uses `border-collie`. Use `BreedType::slug()`.
- `User::activePet()` is a method returning a model, not a relation (can't eager-load).
- Storage is UTC; wall-clock rules (quiet hours, midnight, dashboard days, feed windows, water day) use `User::familyTimezone()` / `Pet::familyTimezone()` (parent's `users.timezone`, default Europe/Ljubljana) — M1-03.
- Webhook controllers skip verification when the secret env var is empty — never deploy that way.
