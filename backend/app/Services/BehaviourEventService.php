<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\LifeStage;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use App\Models\QuietHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Behaviour events (M5-R02, David 2026-10-06, REALISM_SPEC §3,
 * PRODUCT_SPEC §5 / §11) — two new kinds of mess in `pet_hygiene_events`
 * next to the random "kakec" (HygieneEventService):
 *
 * 1. Puppy accident ("Pelji ven"). A puppy holds about one hour per month of
 *    age (S30 / S31, `accident_hold_hours_per_age_month` × the age that the
 *    rules of the day use). The bladder clock starts at the last take-out,
 *    the last accident, birth or the family-local midnight (whichever is
 *    latest — the night belongs to the parents) and only counts time
 *    OUTSIDE quiet hours: due = start + hold hours of non-quiet time, so an
 *    accident never falls into quiet hours. A freeze (hard stop, vet, game
 *    over) holds the clock at its end (the tick moves it while frozen).
 *    When the due instant passes without a take-out, the tick writes an
 *    `accident` event at that instant (hygiene → 0) and the clock restarts
 *    there. Non-legacy pets in the `puppy` stage only.
 *
 * 2. Chewing ("uničil copat"), at most one per family-local day, decided
 *    once per day (`pets.behaviour_scheduled_through`):
 *    a) teething puppy (age in `teething_months`, S32 / S33): happens with
 *       probability `chewing_chance_per_day` (UNSOURCED proposal 0.5,
 *       verified = false — Claude, waiting for David), drawn from a seeded
 *       RNG per (salt, pet, date);
 *    b) any non-legacy dog whose previous local day's walk routine was
 *       missed (RoutineLedgerService — an excused or unexpected walk does
 *       not count): always (boredom, S33 / S7).
 *    Its time is a random whole minute outside quiet hours (same draw as
 *    the hygiene schedule); the tick applies it like a "kakec" — not inside
 *    a freeze, not before birth, not in quiet hours changed later.
 *
 * Every event is one `clean` routine with its kind (resolved within 2 h
 * outside quiet hours) and drops hygiene to 0, so the existing escalation
 * and push rules apply unchanged. Accidents are cleaned with the cleaning
 * game; chewing with POST /api/child/pet/resolve-chewing.
 *
 * Legacy-profile and unborn pets never get behaviour events. All callers
 * hold the pet's row lock (backend/CLAUDE.md); attributes are written on
 * $pet, the caller saves.
 */
class BehaviourEventService
{
    /** Accidents written in one tick at most (a long scheduler outage). */
    public const MAX_ACCIDENTS_PER_TICK = 24;

    /** Same catch-up bound as the hygiene schedule. */
    public const MAX_CATCH_UP_DAYS = HygieneEventService::MAX_CATCH_UP_DAYS;

    /** Midnight resets / quiet hops when looking for the due instant. */
    private const MAX_HOPS = 16;

    public function __construct(
        private readonly HygieneEventService $hygiene,
        private readonly LifeStageService $lifeStages,
        private readonly RoutineLedgerService $ledger,
        private readonly DailyWalkService $walks,
        private ?string $seedSalt = null,
    ) {
        $this->seedSalt ??= (string) config('app.key');
    }

    // ──────────────────────────────────────────────────────────────
    //  Accidents
    // ──────────────────────────────────────────────────────────────

    /**
     * Hours the puppy holds on a family-local date (age at the start of
     * that day × hours per month of age); null when the rule does not apply
     * (legacy profile, past the puppy stage, no data).
     */
    public function holdHoursOn(Pet $pet, string $localDate): ?int
    {
        if ($pet->isLegacyProfile()) {
            return null;
        }

        $row = $this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::AccidentHoldHoursPerAgeMonth);
        if ($row === null || $row['stage'] !== LifeStage::Puppy || ! is_int($row['value']) || $row['value'] <= 0) {
            return null;
        }

        return max(1, $row['value'] * max(1, $row['age']));
    }

    /**
     * The puppy's bladder clock as it stands: hold hours, when the clock
     * (re)started and when the accident is due. Null when the pet has no
     * clock (legacy, unborn, not a puppy).
     *
     * @return array{hold_hours: int, started_at: CarbonImmutable, due_at: CarbonImmutable}|null
     */
    public function pottyClock(Pet $pet, ?QuietHours $quiet): ?array
    {
        if ($pet->isLegacyProfile() || $pet->isUnborn()) {
            return null;
        }

        $born = CarbonImmutable::instance($pet->born_at)->utc();
        $start = $pet->potty_clock_started_at !== null
            ? CarbonImmutable::instance($pet->potty_clock_started_at)->utc()
            : $born;

        return $this->dueFrom($pet, $start->greaterThan($born) ? $start : $born, $quiet);
    }

    /**
     * Write the accidents whose due instant lies in ($from, $now] (decay
     * tick, after the hygiene events). The clock restarts at each accident.
     * A due instant at or before $from was never live (the clock was not
     * moved while frozen / inactive, or quiet hours were changed): the
     * clock restarts at $from instead — no accident is made up.
     *
     * @return Carbon|null The earliest accident written (hygiene is 0 from then on), or null.
     */
    public function applyDueAccidents(Pet $pet, CarbonInterface $from, CarbonInterface $now, ?QuietHours $quiet): ?Carbon
    {
        if ($pet->isLegacyProfile() || $pet->isUnborn()) {
            return null;
        }

        $from = CarbonImmutable::instance($from)->utc();
        $now = CarbonImmutable::instance($now)->utc();
        $first = null;

        for ($i = 0; $i < self::MAX_ACCIDENTS_PER_TICK; $i++) {
            $clock = $this->pottyClock($pet, $quiet);
            if ($clock === null || $clock['due_at']->greaterThan($now)) {
                break;
            }

            $due = $clock['due_at'];
            if ($due->lessThanOrEqualTo($from)) {
                $pet->potty_clock_started_at = $from;

                continue;
            }

            PetHygieneEvent::insertOrIgnore([[
                'pet_id' => $pet->id,
                'kind' => HygieneEventKind::Accident->value,
                'local_date' => $pet->localDate($due),
                'scheduled_at' => $due,
                'status' => HygieneEventStatus::Applied->value,
                'resolved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]]);
            HygieneEventService::logSystemEvent($pet, ActivityType::PetAccident, $due);
            Log::info('BehaviourEventService: puppy accident', ['pet_id' => $pet->id, 'at' => $due->toIso8601String(), 'hold_hours' => $clock['hold_hours']]);

            $first ??= Carbon::instance($due);
            $pet->potty_clock_started_at = $due;
        }

        return $first;
    }

    /**
     * "Pelji ven": the bladder is empty now — the clock restarts.
     */
    public function takeOut(Pet $pet, CarbonInterface $now): void
    {
        $pet->potty_clock_started_at = $now;
    }

    /**
     * While the pet is frozen (hard stop, vet) the clock does not run: the
     * tick keeps moving its start, so it resumes from the end of the freeze.
     */
    public function holdClockWhileFrozen(Pet $pet, CarbonInterface $now): void
    {
        if ($pet->isLegacyProfile() || $pet->isUnborn()) {
            return;
        }

        if ($pet->potty_clock_started_at === null || $pet->potty_clock_started_at->lessThan($now)) {
            $pet->potty_clock_started_at = $now;
        }
    }

    /**
     * The due instant from a clock start: hold hours of non-quiet time, moved
     * out of quiet hours; the clock restarts at every family-local midnight
     * it would run through (the night is the parents').
     *
     * @return array{hold_hours: int, started_at: CarbonImmutable, due_at: CarbonImmutable}|null
     */
    private function dueFrom(Pet $pet, CarbonImmutable $start, ?QuietHours $quiet): ?array
    {
        $tz = $pet->familyTimezone();

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $hold = $this->holdHoursOn($pet, $pet->localDate($start));
            if ($hold === null) {
                return null;
            }

            $due = $this->ledger->addSecondsOutsideQuiet($quiet, $start, $hold * 3600);
            if ($quiet?->isQuietNow($due) ?? false) {
                $due = CarbonImmutable::instance($this->walks->endOfQuietStretch($quiet, $due))->utc();
            }

            $midnight = CarbonImmutable::parse($pet->localDate($due), $tz)->startOfDay()->utc();
            if ($midnight->greaterThan($start)) {
                $start = $midnight;

                continue;
            }

            return ['hold_hours' => $hold, 'started_at' => $start, 'due_at' => $due];
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Chewing
    // ──────────────────────────────────────────────────────────────

    /**
     * Decide the chewing event of every family-local day from the day of
     * $from up to the day of $now that is not decided yet (attributes on
     * $pet; the caller saves). Called by the tick after the midnight close,
     * so yesterday's walk is known.
     */
    public function ensureChewingScheduled(Pet $pet, CarbonInterface $from, CarbonInterface $now, ?QuietHours $quiet): void
    {
        if ($pet->isUnborn() || $pet->isLegacyProfile()) {
            return;
        }

        $tz = $pet->familyTimezone();
        $today = Carbon::parse($pet->localDate($now), $tz);
        $day = Carbon::parse($pet->localDate($from), $tz);

        $through = $pet->behaviour_scheduled_through;
        if ($through !== null) {
            $next = Carbon::parse($through, $tz)->addDay();
            if ($next->greaterThan($day)) {
                $day = $next;
            }
        }

        $earliest = $today->copy()->subDays(self::MAX_CATCH_UP_DAYS);
        if ($day->lessThan($earliest)) {
            $day = $earliest;
        }
        if ($day->greaterThan($today)) {
            return;
        }

        $rows = [];
        $stamp = now();
        for (; $day->lessThanOrEqualTo($today); $day->addDay()) {
            $date = $day->toDateString();
            $rng = $this->randomizerFor($pet, $date);
            if ($this->chewingReasonOn($pet, $date, $now, $rng) === null) {
                continue;
            }

            foreach ($this->hygiene->scheduleDay($pet, $date, $quiet, 1, $rng) as $at) {
                $rows[] = [
                    'pet_id' => $pet->id,
                    'kind' => HygieneEventKind::Chewing->value,
                    'local_date' => $date,
                    'scheduled_at' => $at,
                    'status' => HygieneEventStatus::Pending->value,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
        }

        if ($rows !== []) {
            PetHygieneEvent::insertOrIgnore($rows);
        }

        $pet->behaviour_scheduled_through = $today->toDateString();
    }

    /**
     * Why the dog chews on a family-local date, or null:
     * `walk_missed` (yesterday's walk routine was missed — always) ›
     * `teething` (age inside teething_months and the day's roll is below
     * chewing_chance_per_day). The roll is always drawn first, so the
     * RNG stream (and the event time) does not depend on the walk.
     *
     * @return 'walk_missed'|'teething'|null
     */
    public function chewingReasonOn(Pet $pet, string $localDate, CarbonInterface $now, ?Randomizer $rng = null): ?string
    {
        if ($pet->isLegacyProfile() || $pet->isUnborn()) {
            return null;
        }

        $rng ??= $this->randomizerFor($pet, $localDate);
        $roll = $rng->nextFloat();

        $yesterday = CarbonImmutable::parse($localDate, 'UTC')->subDay()->toDateString();
        if ($this->walkMissedOn($pet, $yesterday, $now)) {
            return 'walk_missed';
        }

        return $this->isTeethingOn($pet, $localDate) && $roll < $this->teethingChanceOn($pet, $localDate)
            ? 'teething'
            : null;
    }

    /**
     * Age at the start of the day inside the breed's teething months
     * [from, to) — S32: baby teeth shed from 12–16 weeks; S33: intense
     * chewing over by ~6 months.
     */
    public function isTeethingOn(Pet $pet, string $localDate): bool
    {
        $row = $this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::TeethingMonths);
        $range = $row['value'] ?? null;

        return is_array($range) && count($range) === 2
            && $row['age'] >= $range[0] && $row['age'] < $range[1];
    }

    /**
     * Probability of a teething chewing event per day (0 without data).
     */
    public function teethingChanceOn(Pet $pet, string $localDate): float
    {
        $value = $this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::ChewingChancePerDay)['value'] ?? null;

        return is_int($value) || is_float($value) ? max(0.0, min(1.0, (float) $value)) : 0.0;
    }

    /**
     * The walk routine of that family-local day was missed (not excused, not
     * unexpected — e.g. the birth day or a day mostly frozen).
     */
    public function walkMissedOn(Pet $pet, string $localDate, CarbonInterface $now): bool
    {
        $routines = $this->ledger->routinesFor(collect([$pet]), $localDate, $localDate, $now)[$pet->id] ?? [];

        foreach ($routines as $routine) {
            if ($routine->type === RoutineType::Walk && $routine->status === RoutineStatus::Missed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deterministic RNG for one pet and local day (not predictable by
     * clients: salted with the app key unless a test pins the salt).
     */
    public function randomizerFor(Pet $pet, string $localDate): Randomizer
    {
        $seed = hash('sha256', $this->seedSalt.'|chewing|'.$pet->id.'|'.$localDate, true);

        return new Randomizer(new Xoshiro256StarStar($seed));
    }
}
