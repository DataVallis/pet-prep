<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use App\Models\QuietHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The cat's litter tray (M5-R06-05, CAT_SPEC Q3 / §4 / §7, David
 * 2026-10-08 13:47 + ~22:20; M5-R06_PLAN T7).
 *
 * 1. Uses: HygieneEventService schedules `litter_uses_per_day` (kitten 3,
 *    grown cat 2) `litter_use` rows per family-local day outside quiet
 *    hours, like the dog's poops. A use is NOT a mess (hygiene stays).
 * 2. Scoop deadline: when a use happens the tick fixes its `due_at` —
 *    `litter_scoop_deadline_hours` (4 h) counted outside quiet hours, or
 *    OVERDUE_SCOOP_DEADLINE_HOURS (2 h) while the weekly full change is
 *    overdue (David 2026-10-08: "until changed"). Scooped before `due_at`
 *    (POST /api/child/pet/litter/scoop, the weekly change, or the cleaning
 *    of a litter accident) = the `litter_scoop` routine is done.
 * 3. Not scooped by `due_at` → the tick writes a `litter_accident` at
 *    `due_at` ("a mess next to the tray"): hygiene 0 and from there exactly
 *    the dog ladder (phases, alarm after 1 h, illness after 6 h outside
 *    quiet hours, game over after 24 h). Cleaning it (POST /pet/clean) also
 *    scoops the tray. A deadline that passed while the cat was frozen
 *    (hard stop, vet, payment lock) or during a scheduler outage writes no
 *    accident — like the puppy accidents (BehaviourEventService).
 * 4. Weekly full change: one `litter_change` routine per program period of
 *    `litter_full_change_days` (7) days from the birth, done by a completed
 *    `litter_change` care session (CatChoreService, `changed_litter` row).
 *    The week's change is overdue from the end of a period whose routine
 *    was MISSED until the next completed change.
 *
 * Only cats with life-stage data; every caller that writes holds the pet's
 * row lock (backend/CLAUDE.md).
 */
class LitterService
{
    /**
     * Scoop deadline while the weekly change is overdue: 2 h outside quiet
     * hours (data.json general.litter.game_overdue_change_deadline, David
     * 2026-10-08 — a game rule for every cat breed, so a constant like the
     * 2 h cleaning deadline, not a breed_stage_params key).
     */
    public const OVERDUE_SCOOP_DEADLINE_HOURS = 2;

    public function __construct(
        private readonly LifeStageService $lifeStages,
        private readonly RoutineLedgerService $ledger,
    ) {}

    /** The litter rules apply: a cat with life-stage data. */
    public function appliesTo(Pet $pet): bool
    {
        return $pet->isCat() && ! $pet->isLegacyProfile();
    }

    /** `litter_scoop_deadline_hours` of that day's stage (4), 0 without data. */
    public function scoopDeadlineHours(Pet $pet, string $localDate): float
    {
        $value = $this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::LitterScoopDeadlineHours)['value'] ?? null;

        return (is_int($value) || is_float($value)) && $value > 0 ? (float) $value : 0.0;
    }

    /** `litter_full_change_days` (7), 0 = no weekly change routine. */
    public function changeDays(Pet $pet, string $localDate): int
    {
        if (! $this->appliesTo($pet)) {
            return 0;
        }
        $value = $this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::LitterFullChangeDays)['value'] ?? null;

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * The change period (program week) containing $at, or null (no rule).
     *
     * @return array{index: int, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function changePeriodAt(Pet $pet, CarbonInterface $at): ?array
    {
        $days = $this->changeDays($pet, $pet->localDate($at));

        return $days > 0 ? $this->lifeStages->programPeriodAt($pet, $at, $days) : null;
    }

    /** The last completed full change at or before $at (the `changed_litter` row), if any. */
    public function lastChangeAt(Pet $pet, CarbonInterface $at): ?CarbonImmutable
    {
        $last = ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', ActivityType::ChangedLitter->value)
            ->where('created_at', '<=', CarbonImmutable::instance($at)->utc())
            ->max('created_at');

        return $last !== null ? CarbonImmutable::parse((string) $last, 'UTC') : null;
    }

    /** A full change was completed in [$from, $to]. */
    public function changedBetween(Pet $pet, CarbonInterface $from, CarbonInterface $to): bool
    {
        return ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', ActivityType::ChangedLitter->value)
            ->where('created_at', '>=', CarbonImmutable::instance($from)->utc())
            ->where('created_at', '<=', CarbonImmutable::instance($to)->utc())
            ->exists();
    }

    /**
     * Is the weekly full change overdue at $at? Yes when the previous
     * period's `litter_change` routine was MISSED (ledger — not when it was
     * excused by a long hard stop / vet, or the pet was not born yet) and no
     * change was completed since that period began (David 2026-10-08: the
     * 2 h deadline lasts until the litter is changed).
     */
    public function changeOverdueAt(Pet $pet, CarbonInterface $at): bool
    {
        $period = $this->changePeriodAt($pet, $at);
        if ($period === null || $period['index'] < 1) {
            return false;
        }
        $previous = $this->lifeStages->programPeriodAt($pet, $period['start']->subSecond(), $this->changeDays($pet, $pet->localDate($at)));
        if ($previous === null || $this->changedBetween($pet, $previous['start'], $at)) {
            return false;
        }

        // Confirm with the ledger (the routine sits on the day its period ended).
        $date = $pet->localDate($period['start']);
        foreach ($this->ledger->routinesFor(collect([$pet]), $date, $date, $at)[$pet->id] ?? [] as $routine) {
            if ($routine->type === RoutineType::LitterChange && $routine->status === RoutineStatus::Missed) {
                return true;
            }
        }

        return false;
    }

    /** The scoop deadline in hours for a use at $usedAt: 2 while the change is overdue, else 4 (0 without data). */
    public function deadlineHoursAt(Pet $pet, CarbonInterface $usedAt): float
    {
        $hours = $this->scoopDeadlineHours($pet, $pet->localDate($usedAt));
        if ($hours <= 0) {
            return 0.0;
        }

        return $this->changeOverdueAt($pet, $usedAt) ? min($hours, (float) self::OVERDUE_SCOOP_DEADLINE_HOURS) : $hours;
    }

    /**
     * The scoop deadline of a use at $usedAt: the deadline hours counted
     * outside quiet hours, moved out of quiet hours (never due at night).
     */
    public function deadlineFor(Pet $pet, CarbonInterface $usedAt, ?QuietHours $quiet): ?CarbonImmutable
    {
        $hours = $this->deadlineHoursAt($pet, $usedAt);
        if ($hours <= 0) {
            return null;
        }
        $due = $this->ledger->addSecondsOutsideQuiet($quiet, CarbonImmutable::instance($usedAt)->utc(), (int) round($hours * 3600));
        if ($quiet?->isQuietNow($due) ?? false) {
            $due = CarbonImmutable::instance(app(DailyWalkService::class)->endOfQuietStretch($quiet, $due))->utc();
        }

        return $due;
    }

    // ──────────────────────────────────────────────────────────────
    //  Tick (caller holds the lock)
    // ──────────────────────────────────────────────────────────────

    /** Fix the deadline of every use that happened and has none yet (fixed once, at the use). */
    public function syncDeadlines(Pet $pet, ?QuietHours $quiet): void
    {
        if (! $this->appliesTo($pet)) {
            return;
        }
        $uses = $pet->hygieneEvents()
            ->where('kind', HygieneEventKind::LitterUse->value)
            ->where('status', HygieneEventStatus::Applied->value)
            ->whereNull('due_at')
            ->orderBy('scheduled_at')
            ->get();
        foreach ($uses as $use) {
            $due = $this->deadlineFor($pet, $use->scheduled_at, $quiet);
            if ($due !== null) {
                $use->forceFill(['due_at' => $due])->save();
            }
        }
    }

    /**
     * Unscooped uses whose deadline passed: a `litter_accident` at the
     * deadline when it lay inside ($from, $now] and the tick ran normally;
     * otherwise (frozen, outage) nothing is made up. Each use is handled
     * once (`escalated_at`).
     *
     * @return Carbon|null The earliest accident written (hygiene is 0 from then on), or null.
     */
    public function escalateDue(Pet $pet, CarbonInterface $from, CarbonInterface $now, ?QuietHours $quiet): ?Carbon
    {
        if (! $this->appliesTo($pet) || $pet->isUnborn()) {
            return null;
        }
        $this->syncDeadlines($pet, $quiet);

        $from = CarbonImmutable::instance($from)->utc();
        $now = CarbonImmutable::instance($now)->utc();
        $outage = BehaviourEventService::isOutage($from, $now);
        $first = null;

        $expired = $pet->hygieneEvents()
            ->where('kind', HygieneEventKind::LitterUse->value)
            ->where('status', HygieneEventStatus::Applied->value)
            ->whereNull('cleaned_at')
            ->whereNull('escalated_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->orderBy('due_at')
            ->get();

        foreach ($expired as $use) {
            $due = CarbonImmutable::instance($use->due_at)->utc();
            $live = $due->greaterThan($from) && ! $outage;
            if ($outage) {
                BehaviourEventService::warnOutage($pet, $from, $now);
            }
            if ($live) {
                // Once per use: `escalated_at` under the pet's row lock. insertOrIgnore
                // only folds two uses whose deadlines fall on the same instant (unique
                // pet_id + kind + scheduled_at, M5-R02) into one mess next to the tray.
                PetHygieneEvent::insertOrIgnore([[
                    'pet_id' => $pet->id,
                    'kind' => HygieneEventKind::LitterAccident->value,
                    'local_date' => $pet->localDate($due),
                    'scheduled_at' => $due,
                    'status' => HygieneEventStatus::Applied->value,
                    'resolved_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]]);
                HygieneEventService::logSystemEvent($pet, ActivityType::PetLitterAccident, $due);
                Log::info('LitterService: litter not scooped in time', ['pet_id' => $pet->id, 'due_at' => $due->toIso8601String()]);
                $first ??= Carbon::instance($due);
            }
            $use->forceFill(['escalated_at' => $now])->save();
        }

        return $first;
    }

    // ──────────────────────────────────────────────────────────────
    //  Actions (caller holds the lock)
    // ──────────────────────────────────────────────────────────────

    /**
     * Unscooped uses (applied, not scooped), oldest first — also those whose
     * deadline passed (their accident is a separate mess).
     *
     * @return Collection<int, PetHygieneEvent>
     */
    public function openUses(Pet $pet)
    {
        return $pet->hygieneEvents()
            ->where('kind', HygieneEventKind::LitterUse->value)
            ->where('status', HygieneEventStatus::Applied->value)
            ->whereNull('cleaned_at')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();
    }

    /** Scoop the tray: every open use is scooped now. Returns the number scooped. */
    public function scoop(Pet $pet, CarbonInterface $now): int
    {
        return $pet->hygieneEvents()
            ->where('kind', HygieneEventKind::LitterUse->value)
            ->where('status', HygieneEventStatus::Applied->value)
            ->whereNull('cleaned_at')
            ->update(['cleaned_at' => $now, 'updated_at' => $now]);
    }
}
