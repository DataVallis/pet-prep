<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Models\ActivityLog;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\PetDailyRoutine;
use App\Models\PetDailyStep;
use App\Models\PetDailyWalk;
use App\Models\PetHygieneEvent;
use App\Models\PetStatusPeriod;
use App\Models\QuietHours;
use App\Services\Results\Routine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The routine ledger (M2-06, David 2026-10-04, PRODUCT_SPEC §11): which care
 * routines a pet was expected to get on each family-local day, and whether
 * each was done or missed. Derived from existing data — nothing new is asked
 * of the child:
 *
 *  - feed: one routine per feed window starting that day (the windows of the
 *    pet's life stage that day, M5-R01); done = a `fed_pet` row inside
 *    [start, end); missed when the window ended unfed. A window that lies
 *    entirely in quiet hours is done by the parent: not expected.
 *  - water: `water_times_per_day` refills per day, pro rata to the part of
 *    the day's non-quiet time the pet was in play (birth day, hard stop,
 *    vet, game over), rounded half up; done = `watered_pet` rows that day
 *    (in order, up to the expected number); the rest is missed at day end.
 *  - clean: one routine per hygiene event that happened (`applied`) — a
 *    poop, a puppy accident or a chewing event (M5-R02, `eventKind`); done =
 *    resolved within 2 hours counted outside quiet hours (cleaned, or for
 *    chewing tidied up with a toy).
 *  - walk: the daily step goal (of that day's life stage); done = the day's steps reached the goal
 *    (`pet_daily_walks.achieved`, live for today); missed at day end. Not
 *    expected on the birth day (energy grace) or for a past day without any
 *    step data (scheduler outage).
 *  - training (M5-R03, David 2026-10-06): one completed training session
 *    per day (`trained_pet` row, actor = the child) for a pet with training
 *    enabled (never a legacy-profile pet); missed at day end. Not expected on
 *    the birth day; excused like the walk (whole-day routine).
 *  - play (M5-R06-04, cats only — CAT_SPEC §5.2 / §7): the cat's wand play;
 *    done when the day's successful sessions (`played_wand` rows) reach
 *    `play_sessions_per_day` of that day's life stage (done at the row that
 *    reached it, actor = its child); missed at day end. `steps` = sessions,
 *    `goal` = the goal. Not expected on the birth day, nor on a day whose
 *    quiet hours leave room for fewer games than the goal (no play in quiet
 *    hours, David 2026-10-08 — WandPlayService::feasibleSessions); excused
 *    like the walk.
 *  - litter_scoop (M5-R06-05, cats — CAT_SPEC Q3 / §7): one per litter use
 *    (`litter_use` event, not a mess); done = scooped (`cleaned_at`) by its
 *    `due_at` (4 h outside quiet hours, 2 h while the weekly change is
 *    overdue — fixed by LitterService when the use happens); missed after.
 *    The cat's `litter_accident` and `scratching` are `clean` routines like
 *    the dog's messes (2 h; scratching = chewing, David 2026-10-08 ~22:20).
 *  - litter_change / grooming (M5-R06-05, cats): WEEKLY routines of the
 *    program period (LifeStageService::programPeriodAt — 7 days from the
 *    birth's wall-clock time; `litter_full_change_days`) that ENDS on the
 *    family-local date: one litter change (`changed_litter` inside the
 *    period) and, for a Maine Coon, `grooming_sessions_per_week` grooming
 *    slots (slot i = the period's i-th `groomed_pet`). Not expected when
 *    hard stop / vet / game over cover ≥ 50 % of the period's non-quiet
 *    time (like the walk). A weekly routine is never pending past its day.
 *
 * Not expected at all: before birth (an unborn pet has none), before
 * FIRST_LEDGER_DATE, and — unless it was done anyway — any routine whose
 * window overlaps a hard stop, an illness or an inactive / game-over period
 * (pet_status_periods) — for the whole-day walk only when those periods
 * cover ≥ 50 % of the day's non-quiet time (WHOLE_DAY_EXCUSE_SHARE).
 * Everything is evaluated on the family-local clock
 * (DST: a day has 23 / 25 h, windows follow the wall clock) and stored UTC.
 *
 * Storage: once every routine of a day is resolved (no deadline ahead, no
 * hygiene event still pending), the tick materialises the day into
 * `pet_daily_routines` (closeDueDays — idempotent: insert-or-ignore on
 * (pet, date, type, slot) and a close pointer per pet). Closed days are read
 * from the table and never recomputed, so changing quiet hours or feed
 * windows later does not rewrite history; open days (today, a day whose
 * cleaning deadline is still ahead) are computed live with the same code.
 */
class RoutineLedgerService
{
    /**
     * The first family-local day the ledger covers: from 2026-10-04 every
     * routine has a server-side record (fed / watered rows of the child API
     * M1-07, hygiene events M1-05, daily walks M1-04). Earlier days of old
     * pets would only show "missed" for actions the old app did locally.
     */
    public const FIRST_LEDGER_DATE = '2026-10-04';

    /** Cleaning deadline: 2 hours counted outside quiet hours (David 2026-10-04). */
    public const CLEAN_WITHIN_SECONDS = 2 * 3600;

    /** At most this many days are closed per pet and tick (backfill in slices). */
    public const MAX_DAYS_PER_CLOSE = 120;

    /** At most this many pets are visited per tick; the rest come next tick. */
    public const MAX_PETS_PER_TICK = 500;

    /** After a failure the pet is retried this much later (no log flood). */
    public const RETRY_AFTER_FAILURE_MINUTES = 30;

    /**
     * A whole-day routine (the walk) is excused only when hard stop / vet /
     * game over cover at least this share of the day's non-quiet time
     * (Claude, pending David): a nightly or short pause must not excuse it.
     */
    public const WHOLE_DAY_EXCUSE_SHARE = 0.5;

    private const ACTIVITY_TYPES = [
        ActivityType::FedPet,
        ActivityType::WateredPet,
        ActivityType::CleanedPoop,
        ActivityType::WalkedPet,
        ActivityType::ResolvedChewing,
        ActivityType::TrainedPet,
        // M5-R06-04: a successful cat wand session (the play routine).
        ActivityType::PlayedWand,
        // M5-R06-05 (cats): scoop / weekly change / grooming / scratching resolved.
        ActivityType::ScoopedLitter,
        ActivityType::ChangedLitter,
        ActivityType::GroomedPet,
        ActivityType::ResolvedScratching,
    ];

    /** M5-R06-05: who scooped a litter use — the scoop itself, a cleaning of the accident, the weekly change. */
    private const SCOOP_ACTIVITIES = [ActivityType::ScoopedLitter, ActivityType::CleanedPoop, ActivityType::ChangedLitter];

    /** M5-R06-05: weekly cat routines look back one period (+ margin) for their activities / freezes. */
    private const WEEKLY_LOOKBACK_DAYS = 9;

    public function __construct(
        private readonly CareScheduleService $schedule,
        private readonly LifeStageService $lifeStages,
    ) {}

    // ──────────────────────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────────────────────

    /**
     * Routines of the given pets on family-local dates [$fromDate, $toDate]:
     * stored rows for closed days, computed live for the rest. A constant
     * number of queries for any number of pets and days.
     *
     * @param  Collection<int, Pet>  $pets
     * @return array<int, list<Routine>> pet id → routines sorted by opensAt
     */
    public function routinesFor(Collection $pets, string $fromDate, string $toDate, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $pets = $pets->filter(fn (Pet $p) => ! $p->isUnborn())->values();
        $result = [];
        foreach ($pets as $pet) {
            $result[$pet->id] = [];
        }
        if ($pets->isEmpty() || $fromDate > $toDate) {
            return $result;
        }

        $stored = PetDailyRoutine::whereIn('pet_id', $pets->pluck('id'))
            ->whereBetween('local_date', [$fromDate, $toDate])
            ->orderBy('opens_at')
            ->orderBy('id')
            ->get();
        // Only up to the pointer this pet was loaded with: a day the tick
        // closes meanwhile is still computed live below, never twice.
        $closedThrough = $pets->mapWithKeys(fn (Pet $p) => [$p->id => $p->routines_closed_through])->all();
        foreach ($stored as $row) {
            $date = substr((string) $row->getRawOriginal('local_date'), 0, 10);
            if ($closedThrough[$row->pet_id] !== null && $date <= $closedThrough[$row->pet_id]) {
                $result[$row->pet_id][] = $this->fromRow($row);
            }
        }

        // Live part: every day after the pet's close pointer.
        $live = [];
        foreach ($pets as $pet) {
            $start = max($fromDate, $this->firstOpenDate($pet));
            $today = $pet->localDate($now);
            $end = min($toDate, $today);
            if ($start <= $end) {
                $live[$pet->id] = [$start, $end];
            }
        }

        if ($live !== []) {
            $livePets = $pets->filter(fn (Pet $p) => isset($live[$p->id]))->values();
            $inputs = $this->loadInputs($livePets, min(array_column($live, 0)), max(array_column($live, 1)));
            foreach ($livePets as $pet) {
                [$start, $end] = $live[$pet->id];
                foreach ($this->dates($start, $end) as $date) {
                    $day = $this->computeDay($pet, $inputs[$pet->id], $date, $now);
                    array_push($result[$pet->id], ...$day['routines']);
                }
            }
        }

        foreach ($result as $petId => $routines) {
            usort($routines, fn (Routine $a, Routine $b): int => [$a->opensAt, $a->type->value, $a->slot] <=> [$b->opensAt, $b->type->value, $b->slot]);
            $result[$petId] = $routines;
        }

        return $result;
    }

    /**
     * The first family-local date that is not closed yet for a pet.
     */
    public function firstOpenDate(Pet $pet): string
    {
        if ($pet->routines_closed_through !== null) {
            return CarbonImmutable::parse($pet->routines_closed_through, 'UTC')->addDay()->toDateString();
        }

        return $this->ledgerStartDate($pet);
    }

    /**
     * The first family-local date the ledger covers for a pet.
     */
    public function ledgerStartDate(Pet $pet): string
    {
        $born = $pet->born_at !== null ? $pet->localDate($pet->born_at) : self::FIRST_LEDGER_DATE;

        return max($born, self::FIRST_LEDGER_DATE);
    }

    // ──────────────────────────────────────────────────────────────
    //  Closing days (tick)
    // ──────────────────────────────────────────────────────────────

    /**
     * Materialise every finished, fully resolved day of every pet that is
     * due (pets.routines_next_close_at). Called by `pets:process-decay`
     * after decay and escalation (so the night's walk row and due hygiene
     * events already exist). A failure for one pet is logged and never
     * stops the tick.
     *
     * @return array{pets: int, days: int}
     */
    public function closeDueDays(?CarbonInterface $now = null, int $limit = self::MAX_PETS_PER_TICK): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();

        // Never-closed pets first, then the longest overdue; at most $limit
        // per tick so a mass backfill can't stall the game loop.
        $ids = Pet::born()
            ->where(function ($q) use ($now) {
                $q->where(fn ($q) => $q->whereNull('routines_next_close_at')->whereNull('routines_closed_through'))
                    ->orWhere('routines_next_close_at', '<=', $now);
            })
            ->orderByRaw('routines_next_close_at ASC NULLS FIRST')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        $days = 0;
        foreach ($ids as $id) {
            try {
                $days += $this->closePet((int) $id, $now);
            } catch (Throwable $e) {
                // Logged once per attempt; the pet is retried 30 min later
                // instead of failing (and logging) every minute.
                Log::error('RoutineLedgerService: closing days failed', ['pet_id' => $id, 'error' => $e->getMessage()]);
                report($e);
                DB::table('pets')->where('id', $id)->update([
                    'routines_next_close_at' => $now->addMinutes(self::RETRY_AFTER_FAILURE_MINUTES),
                ]);
            }
        }

        return ['pets' => $ids->count(), 'days' => $days];
    }

    /**
     * Close the finished days of one pet. Returns the number of days closed.
     */
    public function closePet(int $petId, ?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $pet = Pet::with('family')->find($petId);
        if ($pet === null || $pet->isUnborn()) {
            return 0;
        }

        $tz = $pet->familyTimezone();
        $today = $pet->localDate($now);
        $todayEnd = CarbonImmutable::parse($today, $tz)->addDay()->startOfDay()->utc();
        $yesterday = CarbonImmutable::parse($today, 'UTC')->subDay()->toDateString();
        $previous = $pet->routines_closed_through;
        $start = $this->firstOpenDate($pet);

        $closedThrough = $previous;
        $next = $todayEnd;
        $rows = [];
        $lastDayEmpty = false;

        if ($start <= $yesterday) {
            $end = min($yesterday, CarbonImmutable::parse($start, 'UTC')->addDays(self::MAX_DAYS_PER_CLOSE - 1)->toDateString());
            $inputs = $this->loadInputs(collect([$pet]), $start, $end)[$pet->id];

            foreach ($this->dates($start, $end) as $date) {
                $day = $this->computeDay($pet, $inputs, $date, $now);
                if (! $day['final']) {
                    $next = max($day['settles_at'], $now->addMinute());
                    break;
                }
                foreach ($day['routines'] as $routine) {
                    $rows[] = $this->toRow($routine, $now);
                }
                $closedThrough = $date;
                $lastDayEmpty = $day['routines'] === [];
            }

            // More backfill left: continue on the next tick.
            if ($closedThrough === $end && $end < $yesterday) {
                $next = $now;
            }
        }

        // A pet out of play (game over / inactive) with everything closed and
        // nothing expected any more stops being visited until it comes back
        // (PetStatusPeriodService resets the pointer on re-activation).
        if (! $pet->is_active && $closedThrough !== null && $closedThrough === $yesterday && ($lastDayEmpty || $start > $yesterday)) {
            $next = null;
        }

        $written = DB::transaction(function () use ($pet, $rows, $previous, $closedThrough, $next): bool {
            // Row lock: a concurrent close or a re-activation (which resets
            // routines_next_close_at) is serialised with this write.
            $current = DB::table('pets')->where('id', $pet->id)->lockForUpdate()
                ->first(['routines_closed_through', 'routines_next_close_at']);
            $currentPointer = $current?->routines_closed_through !== null ? substr((string) $current->routines_closed_through, 0, 10) : null;
            if ($current === null || $currentPointer !== $previous
                || (string) $current->routines_next_close_at !== (string) $pet->getRawOriginal('routines_next_close_at')) {
                return false; // someone else moved it: retry next tick
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('pet_daily_routines')->insertOrIgnore($chunk);
            }

            DB::table('pets')->where('id', $pet->id)->update([
                'routines_closed_through' => $closedThrough,
                'routines_next_close_at' => $next,
            ]);

            return true;
        });

        return ! $written || $closedThrough === $previous ? 0 : count($this->dates(
            $previous === null ? $start : CarbonImmutable::parse($previous, 'UTC')->addDay()->toDateString(),
            $closedThrough,
        ));
    }

    // ──────────────────────────────────────────────────────────────
    //  One day
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $in  Inputs of this pet (loadInputs()).
     * @return array{routines: list<Routine>, final: bool, settles_at: CarbonImmutable|null}
     */
    private function computeDay(Pet $pet, array $in, string $date, CarbonImmutable $now): array
    {
        $tz = $pet->familyTimezone();
        $dayStart = CarbonImmutable::parse($date, $tz)->startOfDay();
        $dayEnd = $dayStart->addDay()->startOfDay();
        $dayStartUtc = $dayStart->utc();
        $dayEndUtc = $dayEnd->utc();
        $born = $pet->born_at !== null ? CarbonImmutable::instance($pet->born_at)->utc() : null;

        $none = ['routines' => [], 'final' => $now->greaterThanOrEqualTo($dayEndUtc), 'settles_at' => $dayEndUtc];
        if ($born === null || $date < self::FIRST_LEDGER_DATE || $dayEndUtc->lessThanOrEqualTo($born)) {
            return $none;
        }

        /** @var QuietHours|null $quiet */
        $quiet = $in['quiet'];
        /** @var BreedConfig|null $config */
        $config = $in['config'];
        /** @var list<array{0: CarbonImmutable, 1: CarbonImmutable|null}> $blocks */
        $blocks = $in['blocks'];
        if ($config === null) {
            return $none;
        }

        $routines = [];

        // Feed: one routine per window that starts today (the windows of the
        // pet's life stage that day, M5-R01).
        foreach ($this->schedule->feedWindowsStartingOn($pet, $config, $date) as $slot => [$start, $end]) {
            $start = $start->utc();
            $end = $end->utc();
            if ($start->lessThan($born)) {
                continue;
            }
            if (QuietHours::splitSecondsBetween($quiet, $start, $end)['normal'] <= 0) {
                // Window entirely in quiet hours: done by the parent (the
                // tick feeds the dog, parent_fed_pet) — never expected from
                // the child, never counts for or against them (M5-R01).
                continue;
            }
            $fed = $this->firstActivity($in['activities'], ActivityType::FedPet, $start, $end);
            $routines[] = $this->resolve($pet, $date, RoutineType::Feed, $slot, $start, $end, $fed, $blocks, $now);
        }

        // Water: pro-rata number of refills for the part of the day in play.
        $timesPerDay = max(0, (int) $config->water_times_per_day);
        $from = $born->greaterThan($dayStartUtc) ? $born : $dayStartUtc;
        $total = QuietHours::splitSecondsBetween($quiet, $dayStartUtc, $dayEndUtc);
        $useNormal = $total['normal'] > 0;
        $playable = 0.0;
        foreach ($this->subtract($from, $dayEndUtc, $blocks) as [$a, $b]) {
            $split = QuietHours::splitSecondsBetween($quiet, $a, $b);
            $playable += $useNormal ? $split['normal'] : $split['normal'] + $split['quiet'];
        }
        $denominator = $useNormal ? $total['normal'] : $total['normal'] + $total['quiet'];
        $expectedWater = $denominator > 0
            ? (int) min($timesPerDay, max(0, round($timesPerDay * $playable / $denominator, 0, PHP_ROUND_HALF_UP)))
            : 0;
        $waterRows = array_values(array_filter(
            $in['activities'],
            fn ($r) => $r['type'] === ActivityType::WateredPet->value && $r['at']->greaterThanOrEqualTo($dayStartUtc) && $r['at']->lessThan($dayEndUtc),
        ));
        for ($slot = 0; $slot < $expectedWater; $slot++) {
            $row = $waterRows[$slot] ?? null;
            $routines[] = new Routine(
                petId: $pet->id,
                localDate: $date,
                type: RoutineType::Water,
                slot: $slot,
                opensAt: $from,
                dueAt: $dayEndUtc,
                status: $row !== null ? RoutineStatus::Done : ($now->lessThan($dayEndUtc) ? RoutineStatus::Pending : RoutineStatus::Missed),
                doneAt: $row['at'] ?? null,
                actorUserId: $row['actor'] ?? null,
            );
        }

        // Clean: one routine per hygiene event that happened today.
        $hygienePending = false;
        $slot = 0;
        $litterUses = [];
        foreach ($in['hygiene'] as $event) {
            if ($event['at']->lessThan($dayStartUtc) || $event['at']->greaterThanOrEqualTo($dayEndUtc)) {
                continue;
            }
            if ($event['status'] === HygieneEventStatus::Pending->value) {
                // A pending event inside a hard stop / vet / inactive period
                // can only ever be skipped (a game-over pet is never ticked
                // again, so it would stay pending forever): not a routine and
                // no reason to keep the day open.
                if (! $this->overlaps($event['at'], $event['at']->addSecond(), $blocks)) {
                    $hygienePending = true;
                }

                continue;
            }
            if ($event['status'] !== HygieneEventStatus::Applied->value || $event['at']->lessThan($born)) {
                continue;
            }
            // M5-R06-05: a litter use is not a mess — its own `litter_scoop` routine below.
            if ($event['kind'] === HygieneEventKind::LitterUse) {
                $litterUses[] = $event;

                continue;
            }
            $due = $this->addSecondsOutsideQuiet($quiet, $event['at'], self::CLEAN_WITHIN_SECONDS);
            $cleaned = $event['cleaned_at'];
            // M5-R02: a poop / accident is cleaned (cleaned_poop), chewing is tidied up (resolved_chewing).
            $done = $cleaned !== null && $cleaned->lessThanOrEqualTo($due)
                ? ['at' => $cleaned, 'actor' => $this->cleanActor($in['activities'], $cleaned, $event['kind']->resolvingActivity())]
                : null;
            // Cleaned too late: missed once the deadline is behind us.
            $routine = $this->resolve($pet, $date, RoutineType::Clean, $slot, $event['at'], $due, $done, $blocks, $now, eventKind: $event['kind']);
            if ($routine !== null) {
                $routines[] = $routine;
                $slot++;
            }
        }

        // Litter scoop (M5-R06-05, cats): one per litter use, done when scooped by its deadline.
        foreach ($litterUses as $i => $event) {
            $due = $event['due_at'] ?? $this->fallbackScoopDue($pet, $quiet, $event['at']);
            $cleaned = $event['cleaned_at'];
            $done = $cleaned !== null && $cleaned->lessThanOrEqualTo($due)
                ? ['at' => $cleaned, 'actor' => $this->cleanActorOf($in['activities'], $cleaned, self::SCOOP_ACTIVITIES)]
                : null;
            $routines[] = $this->resolve($pet, $date, RoutineType::LitterScoop, $i, $event['at'], $due, $done, $blocks, $now);
        }

        // Whole-day routines (walk, training) are excused only if the freeze
        // covered at least half of the day's non-quiet time.
        $dayPlayable = 0.0;
        foreach ($this->subtract($dayStartUtc, $dayEndUtc, $blocks) as [$a, $b]) {
            $split = QuietHours::splitSecondsBetween($quiet, $a, $b);
            $dayPlayable += $useNormal ? $split['normal'] : $split['normal'] + $split['quiet'];
        }
        $blockedShare = $denominator > 0 ? 1 - $dayPlayable / $denominator : 1.0;
        $wholeDayExcused = $blockedShare >= self::WHOLE_DAY_EXCUSE_SHARE - 1e-9;

        // Walk: the daily step goal (not on the birth day).
        if ($date !== $pet->localDate($born)) {
            $walk = $this->walkFor($pet, $in, $date, $date >= $pet->localDate($now));
            if ($walk !== null && $walk['goal'] > 0) {
                $done = $walk['steps'] >= $walk['goal']
                    ? ($this->walkActivity($in['activities'], $dayStartUtc, $dayEndUtc) ?? ['at' => $dayEndUtc, 'actor' => null])
                    : null;
                $routine = $this->resolve(
                    $pet, $date, RoutineType::Walk, 0, $dayStartUtc, $dayEndUtc, $done, $blocks, $now,
                    $walk['steps'], $walk['goal'], excused: $wholeDayExcused,
                );
                if ($routine !== null) {
                    $routines[] = $routine;
                }
            }
        }

        // Training (M5-R03): one completed session a day (not on the birth day).
        if ($pet->trainingEnabled() && $date !== $pet->localDate($born)) {
            $trained = $this->firstActivity($in['activities'], ActivityType::TrainedPet, $dayStartUtc, $dayEndUtc);
            $routine = $this->resolve(
                $pet, $date, RoutineType::Training, 0, $dayStartUtc, $dayEndUtc, $trained, $blocks, $now,
                excused: $wholeDayExcused,
            );
            if ($routine !== null) {
                $routines[] = $routine;
            }
        }

        // Play (M5-R06-04, cats): the day's successful wand sessions reach the goal (not on the birth day).
        if ($pet->isCat() && ! $pet->isLegacyProfile() && $date !== $pet->localDate($born)) {
            $goal = $this->playGoal($pet, $date);
            // Derived rule (David 2026-10-08): no play in quiet hours — a day whose
            // quiet hours leave no room for the goal (with the 2 h gap) is not expected.
            $gap = $this->lifeStages->stageValueOn($pet, $date, StageParamKey::PlayMinGapMinutes)['value'] ?? 0;
            if ($goal > 0 && WandPlayService::feasibleSessions($quiet, $dayStartUtc, $dayEndUtc, is_int($gap) ? $gap : 0, $goal) < $goal) {
                $goal = 0;
            }
            if ($goal > 0) {
                $sessions = array_values(array_filter(
                    $in['activities'],
                    fn ($a) => $a['type'] === ActivityType::PlayedWand->value && $a['at']->greaterThanOrEqualTo($dayStartUtc) && $a['at']->lessThan($dayEndUtc),
                ));
                $reached = $sessions[$goal - 1] ?? null;
                $routine = $this->resolve(
                    $pet, $date, RoutineType::Play, 0, $dayStartUtc, $dayEndUtc,
                    $reached !== null ? ['at' => $reached['at'], 'actor' => $reached['actor']] : null,
                    $blocks, $now, count($sessions), $goal, excused: $wholeDayExcused,
                );
                if ($routine !== null) {
                    $routines[] = $routine;
                }
            }
        }

        // Weekly cat routines (M5-R06-05): the program period that ends today.
        if ($pet->isCat() && ! $pet->isLegacyProfile()) {
            array_push($routines, ...$this->weeklyCatRoutines($pet, $in, $date, $dayStartUtc, $dayEndUtc, $born, $useNormal, $now));
        }

        $routines = array_values(array_filter($routines));
        $pending = array_filter($routines, fn (Routine $r) => $r->isPending());
        $settles = $dayEndUtc;
        foreach ($pending as $r) {
            $settles = $r->dueAt->greaterThan($settles) ? $r->dueAt : $settles;
        }
        if ($hygienePending) {
            $settles = max($settles, $now->addMinutes(5));
        }

        return [
            'routines' => $routines,
            'final' => $pending === [] && ! $hygienePending && $now->greaterThanOrEqualTo($dayEndUtc),
            'settles_at' => $settles,
        ];
    }

    /**
     * Done › excused (overlaps a freeze, not done) › pending › missed.
     *
     * @param  array{at: CarbonImmutable, actor: int|null}|null  $done
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $blocks
     */
    private function resolve(
        Pet $pet, string $date, RoutineType $type, int $slot,
        CarbonImmutable $opens, CarbonImmutable $due, ?array $done, array $blocks, CarbonImmutable $now,
        ?int $steps = null, ?int $goal = null, ?bool $excused = null, ?HygieneEventKind $eventKind = null,
    ): ?Routine {
        if ($done !== null) {
            $status = RoutineStatus::Done;
        } elseif ($excused ?? $this->overlaps($opens, $due, $blocks)) {
            return null; // hard stop / vet / game over: not expected
        } elseif ($now->lessThan($due)) {
            $status = RoutineStatus::Pending;
        } else {
            $status = RoutineStatus::Missed;
        }

        return new Routine(
            petId: $pet->id,
            localDate: $date,
            type: $type,
            slot: $slot,
            opensAt: $opens,
            dueAt: $due,
            status: $status,
            doneAt: $done['at'] ?? null,
            actorUserId: $done['actor'] ?? null,
            steps: $steps,
            goal: $goal,
            eventKind: $eventKind,
        );
    }

    /**
     * M5-R06-05: the cat's weekly routines whose program period ends on
     * $date — the full litter change (1 slot) and the Maine Coon's grooming
     * (`grooming_sessions_per_week` slots). Opens at the period start, due
     * at its end (the next weekly birthday); excused when freezes cover ≥
     * 50 % of the period's non-quiet time.
     *
     * @param  array<string, mixed>  $in
     * @return list<Routine>
     */
    private function weeklyCatRoutines(Pet $pet, array $in, string $date, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, CarbonImmutable $born, bool $useNormal, CarbonImmutable $now): array
    {
        $quiet = $in['quiet'];
        $blocks = $in['blocks'];
        $changeDays = $this->lifeStages->stageValueOn($pet, $date, StageParamKey::LitterFullChangeDays)['value'] ?? 0;
        $routines = [];

        foreach ([
            [RoutineType::LitterChange, is_int($changeDays) ? $changeDays : 0, ActivityType::ChangedLitter],
            [RoutineType::Grooming, 7, ActivityType::GroomedPet],
        ] as [$type, $days, $activity]) {
            if ($days <= 0) {
                continue;
            }
            $ending = $this->lifeStages->programPeriodAt($pet, $dayEnd->subSecond(), $days);
            if ($ending === null || $ending['index'] < 1 || $ending['start']->lessThan($dayStart) || $ending['start']->greaterThanOrEqualTo($dayEnd)) {
                continue;
            }
            $period = $this->lifeStages->programPeriodAt($pet, $ending['start']->subSecond(), $days);
            if ($period === null) {
                continue;
            }
            $opens = $period['start']->lessThan($born) ? $born : $period['start'];
            $due = $ending['start'];
            $goal = 1;
            if ($type === RoutineType::Grooming) {
                $value = $this->lifeStages->stageValueOn($pet, $pet->localDate($opens), StageParamKey::GroomingSessionsPerWeek)['value'] ?? 0;
                $goal = is_int($value) ? $value : 0;
            }
            if ($goal <= 0 || ! $opens->lessThan($due)) {
                continue;
            }

            // Excused like the walk: freezes cover ≥ 50 % of the period's (non-quiet) time.
            $playable = 0.0;
            $split = QuietHours::splitSecondsBetween($quiet, $opens, $due);
            $total = $useNormal ? $split['normal'] : $split['normal'] + $split['quiet'];
            foreach ($this->subtract($opens, $due, $blocks) as [$a, $b]) {
                $part = QuietHours::splitSecondsBetween($quiet, $a, $b);
                $playable += $useNormal ? $part['normal'] : $part['normal'] + $part['quiet'];
            }
            $excused = ($total > 0 ? 1 - $playable / $total : 1.0) >= self::WHOLE_DAY_EXCUSE_SHARE - 1e-9;

            $rows = array_values(array_filter(
                $in['activities'],
                fn ($a) => $a['type'] === $activity->value && $a['at']->greaterThanOrEqualTo($opens) && $a['at']->lessThan($due),
            ));
            for ($slot = 0; $slot < $goal; $slot++) {
                $row = $rows[$slot] ?? null;
                $routines[] = $this->resolve(
                    $pet, $date, $type, $slot, $opens, $due,
                    $row !== null ? ['at' => $row['at'], 'actor' => $row['actor']] : null,
                    $blocks, $now, excused: $excused,
                );
            }
        }

        return array_values(array_filter($routines));
    }

    /**
     * A litter use without a stored deadline yet (the tick fixes it right
     * after applying): `litter_scoop_deadline_hours` (4 h) outside quiet hours.
     */
    private function fallbackScoopDue(Pet $pet, ?QuietHours $quiet, CarbonImmutable $at): CarbonImmutable
    {
        $value = $this->lifeStages->stageValueOn($pet, $pet->localDate($at), StageParamKey::LitterScoopDeadlineHours)['value'] ?? 4;
        $hours = is_int($value) || is_float($value) ? (float) $value : 4.0;

        return $this->addSecondsOutsideQuiet($quiet, $at, (int) round(max(0.0, $hours) * 3600));
    }

    /**
     * The cat's play goal of a family-local date: `play_sessions_per_day` of
     * that day's life stage (0 = no play routine).
     */
    private function playGoal(Pet $pet, string $date): int
    {
        $value = $this->lifeStages->stageValueOn($pet, $date, StageParamKey::PlaySessionsPerDay)['value'] ?? null;

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * Steps and goal of a day: the closed walk row, today's live count, or
     * the children's step rows; null for a past day without any step data
     * (benefit of the doubt after a scheduler outage). Today always counts.
     *
     * @param  array<string, mixed>  $in
     * @return array{steps: int, goal: int}|null
     */
    private function walkFor(Pet $pet, array $in, string $date, bool $isToday): ?array
    {
        // The step goal of that day's life stage (M5-R01); a closed walk row
        // keeps the goal it was closed with.
        $goal = $in['config'] !== null ? $this->lifeStages->rulesOn($pet, $date, $in['config'])->stepGoal : 0;

        if (isset($in['walks'][$date])) {
            return ['steps' => $in['walks'][$date]['steps'], 'goal' => $in['walks'][$date]['goal']];
        }

        if ($pet->last_step_reset_at !== null && $pet->localDate($pet->last_step_reset_at) === $date) {
            return ['steps' => (int) $pet->daily_step_count, 'goal' => $goal];
        }

        if (isset($in['steps'][$date])) {
            return ['steps' => (int) $in['steps'][$date], 'goal' => $goal];
        }

        return $isToday ? ['steps' => 0, 'goal' => $goal] : null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Inputs (batched)
    // ──────────────────────────────────────────────────────────────

    /**
     * Everything computeDay() needs for the pets on [$fromDate, $toDate],
     * in a constant number of queries.
     *
     * @param  Collection<int, Pet>  $pets
     * @return array<int, array<string, mixed>>
     */
    private function loadInputs(Collection $pets, string $fromDate, string $toDate): array
    {
        (new EloquentCollection($pets->all()))->loadMissing('family');
        $ids = $pets->pluck('id');
        // Generous UTC bounds: any family timezone, windows over midnight,
        // cleaning deadlines after quiet nights.
        $fromUtc = CarbonImmutable::parse($fromDate, 'UTC')->subDays(2);
        $toUtc = CarbonImmutable::parse($toDate, 'UTC')->addDays(3);
        $dateFrom = CarbonImmutable::parse($fromDate, 'UTC')->subDay()->toDateString();
        $dateTo = CarbonImmutable::parse($toDate, 'UTC')->addDay()->toDateString();

        // M5-R06-05: weekly cat routines need the whole period before the day.
        $hasCats = $pets->contains(fn (Pet $p) => $p->isCat());
        $lookbackUtc = $hasCats ? CarbonImmutable::parse($fromDate, 'UTC')->subDays(self::WEEKLY_LOOKBACK_DAYS) : $fromUtc;

        $activities = ActivityLog::whereIn('pet_id', $ids)
            ->whereIn('activity_type', array_map(fn (ActivityType $t) => $t->value, self::ACTIVITY_TYPES))
            ->where('created_at', '>=', $lookbackUtc)
            ->where('created_at', '<', $toUtc)
            ->orderBy('created_at')
            ->orderBy('id')
            ->toBase()
            ->get(['pet_id', 'actor_user_id', 'activity_type', 'created_at'])
            ->groupBy('pet_id');

        $hygiene = PetHygieneEvent::whereIn('pet_id', $ids)
            ->whereIn('status', [HygieneEventStatus::Applied->value, HygieneEventStatus::Pending->value])
            ->where('scheduled_at', '>=', $fromUtc)
            ->where('scheduled_at', '<', $toUtc)
            ->orderBy('scheduled_at')
            ->toBase()
            ->get(['pet_id', 'kind', 'scheduled_at', 'status', 'cleaned_at', 'due_at'])
            ->groupBy('pet_id');

        $walks = PetDailyWalk::whereIn('pet_id', $ids)
            ->whereBetween('local_date', [$dateFrom, $dateTo])
            ->toBase()
            ->get(['pet_id', 'local_date', 'steps', 'goal'])
            ->groupBy('pet_id');

        $steps = PetDailyStep::whereIn('pet_id', $ids)
            ->whereBetween('local_date', [$dateFrom, $dateTo])
            ->toBase()
            ->selectRaw('pet_id, local_date, sum(steps) as steps')
            ->groupBy('pet_id', 'local_date')
            ->get()
            ->groupBy('pet_id');

        $periods = PetStatusPeriod::whereIn('pet_id', $ids)
            ->where('started_at', '<', $toUtc)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $lookbackUtc))
            ->toBase()
            ->get(['pet_id', 'started_at', 'ended_at'])
            ->groupBy('pet_id');

        $quiet = QuietHours::whereIn('family_id', $pets->pluck('family_id')->unique())->get()->keyBy('family_id');
        $configs = BreedConfig::all()->keyBy('breed_slug');

        $inputs = [];
        foreach ($pets as $pet) {
            // Same rule as Pet::quietHours(): no row → QuietHours::DEFAULTS.
            $q = $quiet->get($pet->family_id);
            if ($q === null) {
                $q = QuietHours::defaultFor($pet->family);
            } elseif ($pet->family !== null) {
                $q->setRelation('family', $pet->family);
            }

            $inputs[$pet->id] = [
                'quiet' => $q,
                'config' => $configs->get($pet->breed_type->slug()),
                'activities' => $activities->get($pet->id, collect())->map(fn ($r) => [
                    'type' => $r->activity_type,
                    'actor' => $r->actor_user_id !== null ? (int) $r->actor_user_id : null,
                    'at' => $this->utc($r->created_at),
                ])->all(),
                'hygiene' => $hygiene->get($pet->id, collect())->map(fn ($r) => [
                    'kind' => HygieneEventKind::tryFrom((string) $r->kind) ?? HygieneEventKind::Poop,
                    'at' => $this->utc($r->scheduled_at),
                    'status' => $r->status,
                    'cleaned_at' => $r->cleaned_at !== null ? $this->utc($r->cleaned_at) : null,
                    // M5-R06-05: the scoop deadline of a litter use (null for messes).
                    'due_at' => $r->due_at !== null ? $this->utc($r->due_at) : null,
                ])->all(),
                'walks' => $walks->get($pet->id, collect())->mapWithKeys(fn ($r) => [
                    substr((string) $r->local_date, 0, 10) => ['steps' => (int) $r->steps, 'goal' => (int) $r->goal],
                ])->all(),
                'steps' => $steps->get($pet->id, collect())->mapWithKeys(fn ($r) => [
                    substr((string) $r->local_date, 0, 10) => (int) $r->steps,
                ])->all(),
                'blocks' => $periods->get($pet->id, collect())->map(fn ($r) => [
                    $this->utc($r->started_at),
                    $r->ended_at !== null ? $this->utc($r->ended_at) : null,
                ])->values()->all(),
            ];
        }

        return $inputs;
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * The instant at which $seconds of non-quiet time have passed since
     * $from (cleaning deadline). Without quiet hours: $from + $seconds.
     */
    public function addSecondsOutsideQuiet(?QuietHours $quiet, CarbonImmutable $from, int $seconds): CarbonImmutable
    {
        $plain = $from->addSeconds($seconds);
        if ($quiet === null || ! $quiet->is_active) {
            return $plain;
        }

        $cursor = Carbon::instance($from)->utc();
        $remaining = $seconds;
        // ≤ 4 boundaries per day; 64 hops cover any sane schedule.
        for ($hop = 0; $hop < 64; $hop++) {
            $next = $quiet->nextBoundaryAfter($cursor);
            if ($quiet->isQuietNow($cursor)) {
                if ($next === null) {
                    break;
                }
                $cursor = Carbon::instance($next)->utc();

                continue;
            }

            $available = $next === null ? PHP_INT_MAX : (int) $cursor->diffInSeconds($next, true);
            if ($available >= $remaining) {
                return CarbonImmutable::instance($cursor)->addSeconds($remaining)->utc();
            }
            $remaining -= $available;
            $cursor = Carbon::instance($next)->utc();
        }

        // Quiet (almost) all the time: fall back to real time.
        return $plain;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $blocks
     */
    private function overlaps(CarbonImmutable $from, CarbonImmutable $to, array $blocks): bool
    {
        foreach ($blocks as [$start, $end]) {
            if ($start->lessThan($to) && ($end === null || $end->greaterThan($from))) {
                return true;
            }
        }

        return false;
    }

    /**
     * [$from, $to) minus the blocking periods.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $blocks
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function subtract(CarbonImmutable $from, CarbonImmutable $to, array $blocks): array
    {
        $pieces = $from->lessThan($to) ? [[$from, $to]] : [];
        foreach ($blocks as [$start, $end]) {
            $next = [];
            foreach ($pieces as [$a, $b]) {
                $blockEnd = $end ?? $b->addYear();
                if ($start->greaterThanOrEqualTo($b) || $blockEnd->lessThanOrEqualTo($a)) {
                    $next[] = [$a, $b];

                    continue;
                }
                if ($start->greaterThan($a)) {
                    $next[] = [$a, $start];
                }
                if ($blockEnd->lessThan($b)) {
                    $next[] = [$blockEnd, $b];
                }
            }
            $pieces = $next;
        }

        return $pieces;
    }

    /**
     * @param  list<array{type: string, actor: int|null, at: CarbonImmutable}>  $activities
     * @return array{at: CarbonImmutable, actor: int|null}|null
     */
    private function firstActivity(array $activities, ActivityType $type, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        foreach ($activities as $a) {
            if ($a['type'] === $type->value && $a['at']->greaterThanOrEqualTo($from) && $a['at']->lessThan($to)) {
                return ['at' => $a['at'], 'actor' => $a['actor']];
            }
        }

        return null;
    }

    /**
     * The `walked_pet` row of the day (one per day, the sync that reached the goal).
     *
     * @param  list<array{type: string, actor: int|null, at: CarbonImmutable}>  $activities
     * @return array{at: CarbonImmutable, actor: int|null}|null
     */
    private function walkActivity(array $activities, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        return $this->firstActivity($activities, ActivityType::WalkedPet, $from, $to);
    }

    /**
     * Who cleaned: the resolving row (`cleaned_poop`; `resolved_chewing` for
     * chewing, M5-R02) written by the same action as the event's
     * `cleaned_at` (same request, within a few seconds).
     *
     * @param  list<array{type: string, actor: int|null, at: CarbonImmutable}>  $activities
     */
    private function cleanActor(array $activities, CarbonImmutable $cleanedAt, ActivityType $type = ActivityType::CleanedPoop): ?int
    {
        $best = null;
        $bestDiff = 6;
        foreach ($activities as $a) {
            if ($a['type'] !== $type->value) {
                continue;
            }
            $diff = abs($a['at']->getTimestamp() - $cleanedAt->getTimestamp());
            if ($diff < $bestDiff) {
                $best = $a['actor'];
                $bestDiff = $diff;
            }
        }

        return $best;
    }

    /**
     * Who did it among several resolving activity types (closest row within 5 s).
     *
     * @param  list<array{type: string, actor: int|null, at: CarbonImmutable}>  $activities
     * @param  list<ActivityType>  $types
     */
    private function cleanActorOf(array $activities, CarbonImmutable $at, array $types): ?int
    {
        $values = array_map(fn (ActivityType $t) => $t->value, $types);
        $best = null;
        $bestDiff = 6;
        foreach ($activities as $a) {
            if (! in_array($a['type'], $values, true)) {
                continue;
            }
            $diff = abs($a['at']->getTimestamp() - $at->getTimestamp());
            if ($diff < $bestDiff) {
                $best = $a['actor'];
                $bestDiff = $diff;
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private function dates(string $from, string $to): array
    {
        $dates = [];
        for ($d = CarbonImmutable::parse($from, 'UTC'); $d->toDateString() <= $to; $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    private function utc(mixed $value): CarbonImmutable
    {
        return $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)->utc()
            : CarbonImmutable::parse((string) $value, 'UTC');
    }

    private function fromRow(PetDailyRoutine $row): Routine
    {
        return new Routine(
            petId: $row->pet_id,
            localDate: substr((string) $row->getRawOriginal('local_date'), 0, 10),
            type: $row->routine_type,
            slot: $row->slot,
            opensAt: CarbonImmutable::instance($row->opens_at)->utc(),
            dueAt: CarbonImmutable::instance($row->due_at)->utc(),
            status: $row->status,
            doneAt: $row->done_at !== null ? CarbonImmutable::instance($row->done_at)->utc() : null,
            actorUserId: $row->actor_user_id,
            steps: $row->steps,
            goal: $row->goal,
            // Clean rows closed before M5-R02 have no kind: they were all poop.
            eventKind: $row->routine_type === RoutineType::Clean ? ($row->event_kind ?? HygieneEventKind::Poop) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Routine $r, CarbonImmutable $now): array
    {
        return [
            'pet_id' => $r->petId,
            'local_date' => $r->localDate,
            'routine_type' => $r->type->value,
            'slot' => $r->slot,
            'opens_at' => $r->opensAt->format('Y-m-d H:i:s'),
            'due_at' => $r->dueAt->format('Y-m-d H:i:s'),
            'status' => $r->status->value,
            'done_at' => $r->doneAt?->format('Y-m-d H:i:s'),
            'actor_user_id' => $r->actorUserId,
            'steps' => $r->steps,
            'goal' => $r->goal,
            'event_kind' => $r->eventKind?->value,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ];
    }
}
