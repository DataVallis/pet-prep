# School days in quiet hours — contract (David 2026-11-22)

Bug (David, 22. 11.): on Sunday the daughter could not play with the cat during the school window. The school window is applied every day, and there is no weekday rule anywhere.
Decision (David 2026-11-22, »Starš izbere dneve«): the parent picks the days the school window applies (Mon–Sun). The default is Mon–Fri. The night bedtime window stays every day.

## Data
- `school_days`: an array of ISO weekday integers 1..7 (1 = Monday … 7 = Sunday), unique and sorted.
  - `[]` means no school window on any day, the same as having no school times.
  - The default is `[1,2,3,4,5]`.
- It is stored on the family `quiet_hours` row as a new column, `jsonb` or `smallint[]`; the implementer picks one and documents it. A migration backfills existing rows to `[1,2,3,4,5]`.
- It is also stored inside `pets.own_quiet_hours`, the per-pet JSON from 2026-11-16. A missing key there means `[1,2,3,4,5]`; no rewrite of existing JSON is needed beyond reading the default.
- `QuietHours::DEFAULTS` gains `school_days => [1,2,3,4,5]`, and `SCHEDULE_KEYS` gains `school_days`.

## Rule
- The school window applies only when the pet-local date (`Pet::effectiveTimezone()`) of the instant falls on a weekday in `school_days`.
- School windows never cross midnight, so the weekday is the date of the instant.
- Bedtime is unchanged and applies every day.
- Every consumer goes through `Pet::quietHours()` / `QuietHours::readIn` and must honour the weekday:
  - `isWithinQuietHours`;
  - quiet segments used by decay ×0.1;
  - the boundary iteration (a new school day is a boundary);
  - sleep (`PetSleepService`), pushes, messes / hygiene scheduling, the puppy bladder clock;
  - parent-covered meals (a feed window entirely inside school is the parent's only on school days);
  - routines / ledger, care tasks, games / school / wand quiet checks, the walk reminder (»2 h after quiet end«);
  - `RoutineLedgerService::loadInputs` (batch).
- No recompute: closed days stay as they are, exactly like a quiet-hours change today. The new rule applies from the next tick.

## API
- `GET /api/parent/quiet-hours` and the family quiet hours in the dashboard / settings payloads return `school_days`.
- `PUT /api/parent/quiet-hours` and `PUT /api/parent/pets/{pet}/quiet-hours` accept optional `school_days`.
  - Missing means keep the stored value; a new row gets the default.
  - Validation: an array of distinct ints 1..7. Invalid values → 422 `school_days_invalid`.
  - Old apps never send it and keep working.
- Pet payloads that expose `quiet_hours` (`quiet_hours_follow_family` etc.) include `school_days`.
- The child state's sleep / quiet info (for example »Kuža spi do 7:00«) follows the rule automatically through the server.

## App
- In the quiet-hours editors (family: Nadzor → Tihe ure; per pet: Nadzor → pet card → Tihe ure → Lastne ure), add a row of 7 day chips under the school window: »Po To Sr Če Pe So Ne« (EN »Mon … Sun«). The default is Mon–Fri.
  - Helper text: »Šola velja samo izbrane dni. Nočno spanje velja vsak dan.«
- Saving sends `school_days`. A server that does not return the field means the app treats it as Mon–Fri and does not send it.
- i18n covers every app locale.

## As built (backend, `fix/quiet-hours-school-days`, 2026-11-22)
- **Storage:** `quiet_hours.school_days` = `jsonb NOT NULL DEFAULT '[1,2,3,4,5]'`, CHECK `jsonb_typeof(school_days) = 'array'`; migration `2027_05_01_120000_add_school_days_to_quiet_hours` added it, backfilled every existing row to `[1,2,3,4,5]` and has a `down()` that drops the column. Pet schedules: the key `school_days` inside `pets.own_quiet_hours`; a missing key reads `[1,2,3,4,5]` (no JSON rewrite).
- **Model:** `QuietHours::DEFAULT_SCHOOL_DAYS`, `DEFAULTS['school_days']`, `SCHEDULE_KEYS` += `school_days`, `normaliseSchoolDays()` (distinct, sorted, 1..7; non-list → default; `[]` stays `[]`), `schoolDays()` (memoised, never null). `normaliseValues()` always returns `school_days`.
- **Rule (one place):** `isQuietNow()` counts the school window only when the pet-local ISO weekday of the instant is in `schoolDays()`. `nextBoundaryAfter()` leaves the school times out when no day is selected and adds every local midnight when the window applies on some but not all days. Every consumer (decay segments, sleep, pushes / walk reminder, messes, bladder clock, parent-covered meals, routines + `RoutineLedgerService::loadInputs`, care tasks, games / school / wand, care guide) reads quiet time through these methods — none checks a weekday itself.
- **API:** `school_days` in `GET` / `PUT /api/parent/quiet-hours` (`quiet_hours.school_days`), the dashboard's top-level `quiet_hours` and `family.pets[].quiet_hours`, `PUT /api/parent/pets/{pet}/quiet-hours` (`quiet_hours.school_days`), the child state's `quiet_hours`, the care guide's `sleep.school.days` (`school` null when no day is selected) and the GDPR export (family `quiet_hours.school_days`, pets' `own_quiet_hours.school_days`). PUT bodies: optional; missing = keep the stored days (family: the row's; pet: its own, or `[1,2,3,4,5]` for a pet that followed the family); invalid → `422 {message, reason: "school_days_invalid", errors}`, nothing saved. Validation: `Requests\Concerns\ValidatesSchoolDays` — a JSON list (`list`), ≤ 7 entries, each a JSON integer 1..7, distinct.
- **Tests:** `tests/Feature/QuietHoursSchoolDaysTest.php` (pet in Pacific/Auckland, family in Ljubljana — the pet-local weekday differs from the family's).
