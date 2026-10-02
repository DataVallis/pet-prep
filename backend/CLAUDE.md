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
- Decay is a pure function of (previous state, elapsed time since `last_decay_at`, breed config, quiet-hours flag). It must not depend on `updated_at` (planned fix M1-01).
- Metrics are 0–100. Don't lose fractional decay to rounding.
- Frozen while `is_hard_stopped`, ill, or game over.
- Quiet hours: ×0.10 decay, no hygiene events, no pushes, illness clock paused.
- Every state change the parent should see → one `PetUpdated` broadcast (avoid duplicates from observers + manual calls).
- Every child action and every escalation writes one `activities_log` row.

## Testing
- Pest feature tests in `tests/Feature`, unit tests in `tests/Unit` (create the folder when needed).
- `RefreshDatabase`; call `seedBreedConfigs()` (helper in `tests/Pest.php`) when a test needs breed data.
- Time: `$this->travel(...)` / `Carbon::setTestNow()`. For decay, simulate a full day minute-by-minute and assert against spec numbers.
- External HTTP: `Http::fake()`; broadcasts: `Event::fake([PetUpdated::class])`.
- `phpunit.xml` uses `BROADCAST_CONNECTION=log`, DB `testing` on the Sail pgsql service.

## Known traps
- `BreedType` enum uses `border_collie`; `breed_configs.breed_slug` uses `border-collie`. Use `BreedType::slug()`.
- `User::activePet()` is a method returning a model, not a relation (can't eager-load).
- `PetUpdated` currently uses `$connection = 'sync'` and a public channel — both scheduled for change (M1-08/09).
- `APP_TIMEZONE=UTC`; family timezone doesn't exist yet (M1-03).
- Webhook controllers skip verification when the secret env var is empty — never deploy that way.
