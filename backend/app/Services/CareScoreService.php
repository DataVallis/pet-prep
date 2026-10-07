<?php

namespace App\Services;

use App\Enums\PetStatusPeriodKind;
use App\Enums\RoutineType;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetContract;
use App\Models\PetDailyStep;
use App\Models\PetStatusPeriod;
use App\Services\Results\Routine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Care Score, traffic light and progress (M2-06, David 2026-10-04,
 * PRODUCT_SPEC §9/§11), on top of the routine ledger.
 *
 * Pet:   score = done / expected × 100 − 10 per illness, clamped 0–100.
 * Child: "fair share" — every routine of the pet counts 1 / n for each of
 *        the n caretakers who were caring when it opened (from their own
 *        contract, or the birth for grandfathered caretakers);
 *        score = min(100, done_by_child / fair_expected × 100)
 *                − 10 per illness of the pet since the child started,
 *        clamped 0–100. done_by_child = routines that child performed
 *        (activities_log.actor_user_id); a walk counts for a child when the
 *        pet reached its goal AND that child walked ≥ goal / n steps
 *        (Claude's interpretation of "fair share", pending David). One
 *        caretaker = the pet formula. Pending routines (deadline ahead) are
 *        not scored yet.
 * Light: RED = game over, phase-3 alarm active (a metric shown 0 % > 1 h)
 *        or the pet fell ill today; else YELLOW = more than 2 routines
 *        missed today — of today's date or with a deadline today (per
 *        child: every missed routine they shared — a sibling can't hide
 *        behind the other); else GREEN. Today = the family-local day.
 *        Illnesses count from the first ledger day (and the child's start).
 */
class CareScoreService
{
    public const ILLNESS_PENALTY = 10;

    public const CHALLENGE_WEEKS = 12;

    /** Yellow when MORE than this many routines of today were missed. */
    public const YELLOW_AFTER_MISSED = 2;

    public function __construct(private readonly RoutineLedgerService $ledger) {}

    /**
     * Everything the dashboard / report needs for the given pets of one
     * family, in a constant number of queries.
     *
     * @param  Collection<int, Pet>  $pets
     * @param  Collection<int, PetCaretaker>|null  $caretakers
     * @param  Collection<int, PetContract>|null  $contracts
     * @return array<string, mixed>
     */
    public function board(Collection $pets, string $timezone, ?Collection $caretakers = null, ?Collection $contracts = null, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $ids = $pets->pluck('id');
        $caretakers ??= PetCaretaker::whereIn('pet_id', $ids)->orderBy('id')->get();
        $contracts ??= PetContract::whereIn('pet_id', $ids)->get(['pet_id', 'user_id', 'signed_at']);
        $today = $now->setTimezone($timezone)->toDateString();

        $born = $pets->filter(fn (Pet $p) => ! $p->isUnborn());
        $from = $born->isEmpty()
            ? $today
            : $born->map(fn (Pet $p) => $this->ledger->ledgerStartDate($p))->min();

        $routines = $this->ledger->routinesFor($born, $from, $today, $now);

        // When each caretaker started: their own contract, or the birth for
        // grandfathered caretakers (no contract required). A deleted child's
        // tombstone (M2-08) keeps its stored started_at and has an end; it is
        // keyed "ended:{row id}" (no user) and only ever counts as a sharer of
        // routines that opened while it was caring.
        $starts = [];
        $ends = [];
        foreach ($pets as $pet) {
            $starts[$pet->id] = [];
            $ends[$pet->id] = [];
            if ($pet->isUnborn()) {
                continue;
            }
            $bornAt = CarbonImmutable::instance($pet->born_at)->utc();
            foreach ($caretakers->where('pet_id', $pet->id) as $c) {
                $key = $c->user_id !== null ? (int) $c->user_id : 'ended:'.$c->id;
                if ($c->started_at !== null) {
                    $start = CarbonImmutable::instance($c->started_at)->utc();
                } elseif ($c->user_id === null) {
                    $start = null; // tombstone without a known start: not a sharer
                } else {
                    $signed = $contracts->first(fn ($k) => (int) $k->pet_id === $pet->id && (int) $k->user_id === (int) $c->user_id);
                    $start = $c->requires_contract
                        ? ($signed?->signed_at !== null ? CarbonImmutable::instance($signed->signed_at)->utc() : null)
                        : $bornAt;
                }
                $starts[$pet->id][$key] = $start !== null && $start->lessThan($bornAt) ? $bornAt : $start;
                $ends[$pet->id][$key] = $c->ended_at !== null ? CarbonImmutable::instance($c->ended_at)->utc() : null;
            }
        }

        // Illnesses count only from the first ledger day on (routines before
        // it aren't scored either); per child additionally from their start.
        $ledgerStart = [];
        foreach ($pets as $pet) {
            $ledgerStart[$pet->id] = CarbonImmutable::parse($this->ledger->ledgerStartDate($pet), $timezone)->startOfDay()->utc();
        }
        $illnesses = PetStatusPeriod::whereIn('pet_id', $ids)
            ->where('kind', PetStatusPeriodKind::Illness->value)
            ->orderBy('started_at')
            ->get(['pet_id', 'started_at', 'ended_at'])
            ->filter(fn ($r) => CarbonImmutable::instance($r->started_at)->greaterThanOrEqualTo($ledgerStart[$r->pet_id]))
            ->groupBy('pet_id');

        $steps = [];
        foreach (PetDailyStep::whereIn('pet_id', $ids)->where('local_date', '>=', $from)->get(['pet_id', 'user_id', 'local_date', 'steps']) as $row) {
            $steps[$row->pet_id][$row->user_id][substr((string) $row->local_date, 0, 10)] = (int) $row->steps;
        }

        return [
            'now' => $now,
            'timezone' => $timezone,
            'today' => $today,
            // Today's family-local bounds (UTC): a routine whose deadline
            // falls in (start, end] counts for today's light.
            'today_start' => CarbonImmutable::parse($today, $timezone)->startOfDay()->utc(),
            'today_end' => CarbonImmutable::parse($today, $timezone)->addDay()->startOfDay()->utc(),
            'pets' => $pets->keyBy('id'),
            'routines' => $routines,
            'starts' => $starts,
            'ends' => $ends,
            'illnesses' => $illnesses->map(fn ($rows) => $rows->map(fn ($r) => CarbonImmutable::instance($r->started_at)->utc())->values()->all())->all(),
            'steps' => $steps,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Pet
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    public function petSummary(array $board, Pet $pet): array
    {
        $routines = array_values(array_filter($board['routines'][$pet->id] ?? [], fn (Routine $r) => ! $r->isPending()));
        $done = count(array_filter($routines, fn (Routine $r) => $r->isDone()));
        $expected = count($routines);
        $illnesses = count($board['illnesses'][$pet->id] ?? []);

        return [
            'traffic_light' => $this->petLight($board, $pet),
            'care_score' => [
                'score' => $expected > 0 ? $this->clamp($done / $expected * 100 - self::ILLNESS_PENALTY * $illnesses) : null,
                'done' => $done,
                'expected' => $expected,
                'illnesses' => $illnesses,
                'since' => $pet->born_at?->toIso8601String(),
            ],
            'today' => $this->dayBlock($board, $pet, null, $board['today']),
        ];
    }

    /**
     * @param  array<string, mixed>  $board
     * @return array{color: string, reasons: list<string>}
     */
    public function petLight(array $board, Pet $pet): array
    {
        if ($pet->isUnborn()) {
            return ['color' => 'green', 'reasons' => []];
        }

        $reasons = $this->redReasons($board, $pet);
        if ($reasons !== []) {
            return ['color' => 'red', 'reasons' => $reasons];
        }

        $missed = count(array_filter($this->todayRoutines($board, $pet), fn (Routine $r) => $r->isMissed()));

        return $missed > self::YELLOW_AFTER_MISSED
            ? ['color' => 'yellow', 'reasons' => ['missed_routines']]
            : ['color' => 'green', 'reasons' => []];
    }

    // ──────────────────────────────────────────────────────────────
    //  Child
    // ──────────────────────────────────────────────────────────────

    /**
     * Score, light, today, last 7 days and 12-week progress of one child
     * on their pet (null: not caring for a pet yet).
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    public function childSummary(array $board, int $childId, ?Pet $pet): array
    {
        $start = $pet !== null ? ($board['starts'][$pet->id][$childId] ?? null) : null;

        $days = [];
        $todayDate = CarbonImmutable::parse($board['today'], 'UTC');
        for ($i = 6; $i >= 0; $i--) {
            $days[] = $todayDate->subDays($i)->toDateString();
        }

        return [
            'traffic_light' => $this->childLight($board, $childId, $pet),
            'care_score' => $this->childScore($board, $childId, $pet),
            'today' => $pet !== null && $start !== null
                ? $this->dayBlock($board, $pet, $childId, $board['today'])
                : $this->emptyDay($board['today']),
            'last_7_days' => array_map(fn (string $date) => $this->dailyRow($board, $childId, $pet, $date), $days),
            // M3-11: the free plan has no 12-week program → no progress.
            'progress' => $pet !== null && $pet->isFreePlan() ? null : $this->progress($board, $start, $pet),
        ];
    }

    /**
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    public function childScore(array $board, int $childId, ?Pet $pet, ?string $fromDate = null): array
    {
        $start = $pet !== null ? ($board['starts'][$pet->id][$childId] ?? null) : null;
        if ($pet === null || $start === null) {
            return ['score' => null, 'done' => 0, 'expected' => 0, 'routines' => 0, 'illnesses' => 0, 'since' => null];
        }

        $fair = 0.0;
        $done = 0;
        $count = 0;
        foreach ($board['routines'][$pet->id] ?? [] as $r) {
            if ($r->isPending() || ($fromDate !== null && $r->localDate < $fromDate)) {
                continue;
            }
            $n = $this->sharers($board, $pet, $r, $childId);
            if ($n === 0) {
                continue;
            }
            $fair += 1 / $n;
            $count++;
            if ($this->credited($board, $pet, $r, $childId, $n)) {
                $done++;
            }
        }

        $illnesses = count(array_filter(
            $board['illnesses'][$pet->id] ?? [],
            fn (CarbonImmutable $at) => $at->greaterThanOrEqualTo($start)
                && ($fromDate === null || $at->setTimezone($board['timezone'])->toDateString() >= $fromDate),
        ));

        return [
            'score' => $fair > 0
                ? $this->clamp(min(100.0, $done / $fair * 100) - self::ILLNESS_PENALTY * $illnesses)
                : null,
            'done' => $done,
            // Fair-share expected routines (1 / n per shared routine).
            'expected' => round($fair, 2),
            // Routines of the pet this child shared (not divided).
            'routines' => $count,
            'illnesses' => $illnesses,
            'since' => $start->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $board
     * @return array{color: string, reasons: list<string>}
     */
    public function childLight(array $board, int $childId, ?Pet $pet): array
    {
        $start = $pet !== null ? ($board['starts'][$pet->id][$childId] ?? null) : null;
        if ($pet === null || $start === null || $pet->isUnborn()) {
            return ['color' => 'green', 'reasons' => []];
        }

        $reasons = $this->redReasons($board, $pet);
        if ($reasons !== []) {
            return ['color' => 'red', 'reasons' => $reasons];
        }

        // Every missed routine the child shared counts against them.
        $missed = 0;
        foreach ($this->todayRoutines($board, $pet) as $r) {
            if ($r->isMissed() && $this->sharers($board, $pet, $r, $childId) > 0) {
                $missed++;
            }
        }

        return $missed > self::YELLOW_AFTER_MISSED
            ? ['color' => 'yellow', 'reasons' => ['missed_routines']]
            : ['color' => 'green', 'reasons' => []];
    }

    /**
     * Routines of one family-local day for a pet; with $childId only those
     * the child shared, plus what that child did.
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    public function dayBlock(array $board, Pet $pet, ?int $childId, string $date): array
    {
        $expected = 0;
        $done = 0;
        $byChild = 0;
        $pending = 0;
        $missed = [];
        $routines = $date === $board['today'] ? $this->todayRoutines($board, $pet) : $this->routinesOn($board, $pet, $date);
        foreach ($routines as $r) {
            $n = $childId !== null ? $this->sharers($board, $pet, $r, $childId) : 1;
            if ($n === 0) {
                continue;
            }
            $expected++;
            if ($r->isDone()) {
                $done++;
                if ($childId !== null && $this->credited($board, $pet, $r, $childId, $n)) {
                    $byChild++;
                }
            } elseif ($r->isPending()) {
                $pending++;
            } else {
                $missed[] = $this->missedItem($board, $r);
            }
        }

        return [
            'date' => $date,
            'expected' => $expected,
            'done' => $done,
            // Of `done`, what this child earned (null on the pet block).
            'done_by_child' => $childId !== null ? (int) $byChild : null,
            'pending' => $pending,
            'missed_count' => count($missed),
            'missed' => $missed,
        ];
    }

    /**
     * One day of a child's history (chart, report).
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    public function dailyRow(array $board, int $childId, ?Pet $pet, string $date): array
    {
        $row = [
            'date' => $date,
            'expected' => 0,
            'fair_expected' => 0.0,
            'done' => 0,
            'done_by_child' => 0,
            'missed' => 0,
            'pending' => 0,
            'walk_steps' => $pet !== null ? (int) ($board['steps'][$pet->id][$childId][$date] ?? 0) : 0,
            'walk_goal' => null,
            'walk_done' => null,
        ];
        if ($pet === null || ($board['starts'][$pet->id][$childId] ?? null) === null) {
            return $row;
        }

        $fair = 0.0;
        foreach ($this->routinesOn($board, $pet, $date) as $r) {
            if ($r->type === RoutineType::Walk) {
                $row['walk_goal'] = $r->goal;
                $row['walk_done'] = $r->isDone();
            }
            $n = $this->sharers($board, $pet, $r, $childId);
            if ($n === 0) {
                continue;
            }
            $row['expected']++;
            $fair += 1 / $n;
            if ($r->isDone()) {
                $row['done']++;
                if ($this->credited($board, $pet, $r, $childId, $n)) {
                    $row['done_by_child']++;
                }
            } elseif ($r->isPending()) {
                $row['pending']++;
            } else {
                $row['missed']++;
            }
        }
        $row['fair_expected'] = round($fair, 2);

        // One caretaker: steps without a per-child row (before M2-01) are theirs.
        $current = array_filter($board['ends'][$pet->id] ?? [], fn ($end) => $end === null);
        if ($row['walk_steps'] === 0 && count($current) === 1) {
            foreach ($this->routinesOn($board, $pet, $date) as $r) {
                if ($r->type === RoutineType::Walk) {
                    $row['walk_steps'] = (int) $r->steps;
                }
            }
        }

        return $row;
    }

    /**
     * 12-week challenge progress since the child started (contract).
     * Payment-lock time does not count (M3-11b, David 2026-10-07): the
     * days stand still while the pet waits for payment.
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>|null
     */
    public function progress(array $board, ?CarbonImmutable $start, ?Pet $pet = null): ?array
    {
        if ($start === null) {
            return null;
        }

        $now = $board['now'];
        $paused = $pet !== null ? $pet->programSecondsPausedBetween($start, $now) : 0;
        $days = max(0, intdiv($now->getTimestamp() - $start->getTimestamp() - $paused, 86400));

        return [
            'started_at' => $start->toIso8601String(),
            'days_elapsed' => $days,
            'week' => min(self::CHALLENGE_WEEKS, intdiv($days, 7) + 1),
            'weeks_total' => self::CHALLENGE_WEEKS,
            'completed' => $days >= self::CHALLENGE_WEEKS * 7,
        ];
    }

    /**
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    public function missedItem(array $board, Routine $r): array
    {
        return [
            'type' => $r->type->value,
            // M5-R02: which mess a missed `clean` was (poop | accident | chewing); null for other types.
            'kind' => $r->eventKind?->value,
            'date' => $r->localDate,
            'opens_at' => $r->opensAt->setTimezone($board['timezone'])->toIso8601String(),
            'due_at' => $r->dueAt->setTimezone($board['timezone'])->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $board
     * @return list<Routine>
     */
    public function routinesOn(array $board, Pet $pet, string $date): array
    {
        return array_values(array_filter($board['routines'][$pet->id] ?? [], fn (Routine $r) => $r->localDate === $date));
    }

    /**
     * Routines that make up "today" for the light and the today block: those
     * of today's family-local date, plus routines missed with a deadline
     * inside today (e.g. a 21:50 mess due 07:50 this morning). A deadline at
     * exactly midnight (yesterday's water / walk) belongs to yesterday.
     *
     * @param  array<string, mixed>  $board
     * @return list<Routine>
     */
    public function todayRoutines(array $board, Pet $pet): array
    {
        return array_values(array_filter(
            $board['routines'][$pet->id] ?? [],
            fn (Routine $r) => $r->localDate === $board['today']
                || ($r->isMissed() && $r->dueAt->greaterThan($board['today_start']) && $r->dueAt->lessThanOrEqualTo($board['today_end'])),
        ));
    }

    /**
     * Number of caretakers sharing $r, or 0 when $childId is not one of them
     * (they started caring after the routine opened). A caretaker shares a
     * routine that opened while they were caring — including a deleted
     * child's tombstone (M2-08), so a sibling's deletion never rewrites the
     * remaining children's past fair share; routines opened after the end
     * are shared by the remaining caretakers only.
     *
     * @param  array<string, mixed>  $board
     */
    public function sharers(array $board, Pet $pet, Routine $r, int $childId): int
    {
        $n = 0;
        $member = false;
        foreach ($board['starts'][$pet->id] ?? [] as $id => $start) {
            $end = $board['ends'][$pet->id][$id] ?? null;
            if ($start !== null && $start->lessThanOrEqualTo($r->opensAt)
                && ($end === null || $end->greaterThan($r->opensAt))) {
                $n++;
                $member = $member || $id === $childId;
            }
        }

        return $member ? $n : 0;
    }

    /**
     * Did $childId earn routine $r (shared by $n caretakers)?
     *
     * @param  array<string, mixed>  $board
     */
    public function credited(array $board, Pet $pet, Routine $r, int $childId, int $n): bool
    {
        if (! $r->isDone()) {
            return false;
        }

        if ($r->type !== RoutineType::Walk) {
            return $r->actorUserId === $childId;
        }

        if ($n <= 1) {
            return true;
        }

        $mine = (int) ($board['steps'][$pet->id][$childId][$r->localDate] ?? 0);

        return $mine * $n >= (int) $r->goal;
    }

    /**
     * @param  array<string, mixed>  $board
     * @return list<string>
     */
    private function redReasons(array $board, Pet $pet): array
    {
        $reasons = [];
        if ($pet->is_game_over) {
            $reasons[] = 'game_over';
        } elseif ($pet->escalation_level >= 3) {
            $reasons[] = 'phase3_alarm';
        }

        foreach ($board['illnesses'][$pet->id] ?? [] as $at) {
            if ($at->setTimezone($board['timezone'])->toDateString() === $board['today'] && $at->lessThanOrEqualTo($board['now'])) {
                $reasons[] = 'fell_ill_today';
                break;
            }
        }

        return $reasons;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDay(string $date): array
    {
        return ['date' => $date, 'expected' => 0, 'done' => 0, 'done_by_child' => 0, 'pending' => 0, 'missed_count' => 0, 'missed' => []];
    }

    private function clamp(float $value): int
    {
        return (int) max(0, min(100, round($value, 0, PHP_ROUND_HALF_UP)));
    }
}
