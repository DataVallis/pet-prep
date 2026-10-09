<?php

namespace App\Services;

use App\Enums\CareSessionKind;
use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `litter` object of a pet (M5-R06-05, CAT_SPEC Q3 / §4) in the child
 * state and PetUpdated: the cat's tray. Null for a dog (and a cat without a
 * profile, or unborn). Pet facts only — no personal data.
 *
 * - `uses_per_day`: today's litter uses (kitten 3, grown cat 2).
 * - `open_uses`: unscooped uses, oldest first, each with its scoop deadline
 *   (`due_at`, 4 h / 2 h outside quiet hours) and `expired` (the deadline
 *   passed — the mess next to the tray is in `behaviour.active_events` as
 *   `litter_accident`).
 * - `next_due_at`: the earliest deadline still ahead; null when none.
 * - `scoop_deadline_hours`: the deadline a use happening now would get.
 * - `can_scoop`: POST /api/child/pet/litter/scoop has something to scoop
 *   (viewing child incl. their lock; pet level in the broadcast).
 * - `change`: the weekly full change of the current program week — `due_at`
 *   (the week's end), `done`, `overdue` (last week's change was missed and
 *   the litter has not been changed since → 2 h deadlines), `blocked_reason`
 *   (litter_not_available | needs_cleaning | litter_change_done |
 *   litter_change_session_active; null = it may start), `can_start`, the
 *   viewing child's running `session`, `session_running`.
 *
 * Instants are ISO 8601 in the family timezone.
 */
final class LitterPayload
{
    /**
     * @param  list<array{id: int, used_at: string, due_at: string|null, expired: bool}>  $openUses
     * @param  array{week_started_at: string, due_at: string, done: bool, overdue: bool, blocked_reason: string|null, can_start: bool, session: array<string, mixed>|null, session_running: bool}|null  $change
     */
    public function __construct(
        public readonly int $usesPerDay,
        public readonly array $openUses,
        public readonly ?string $nextDueAt,
        public readonly float $scoopDeadlineHours,
        public readonly bool $canScoop,
        public readonly ?array $change,
    ) {}

    public static function for(Pet $pet, ?User $viewer = null, ?CarbonInterface $now = null): ?self
    {
        $litter = app(LitterService::class);
        if (! $litter->appliesTo($pet) || $pet->isUnborn()) {
            return null;
        }

        $now = CarbonImmutable::instance($now ?? now())->utc();
        $tz = $pet->familyTimezone();
        $iso = fn (?CarbonInterface $at): ?string => $at === null ? null : CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String();
        $locked = $pet->actionLockReasonFor($viewer) !== null;

        $open = [];
        $next = null;
        foreach ($litter->openUses($pet) as $use) {
            $due = $use->due_at !== null ? CarbonImmutable::instance($use->due_at)->utc() : null;
            $expired = $due !== null && ! $due->greaterThan($now);
            $open[] = ['id' => $use->id, 'used_at' => $iso($use->scheduled_at), 'due_at' => $iso($due), 'expired' => $expired];
            if ($due !== null && ! $expired && ($next === null || $due->lessThan($next))) {
                $next = $due;
            }
        }

        $change = null;
        $period = $litter->changePeriodAt($pet, $now);
        $overdue = $litter->changeOverdueAt($pet, $now);
        if ($period !== null) {
            $chores = app(CatChoreService::class);
            $refusal = $chores->startRefusal($pet, $viewer, CareSessionKind::LitterChange, $now);
            $live = $chores->liveSession($pet, CareSessionKind::LitterChange, $now);
            $mine = $viewer !== null && $live !== null && (int) $live->user_id === $viewer->id ? $live : null;
            $change = [
                'week_started_at' => (string) $iso($period['start']),
                'due_at' => (string) $iso($period['end']),
                'done' => $litter->changedBetween($pet, $period['start'], $now),
                'overdue' => $overdue,
                'blocked_reason' => $refusal['refusal']->value ?? null,
                'can_start' => $refusal === null && ! $locked,
                'session' => $mine !== null ? CareSessionPayload::chore($mine, $tz) : null,
                'session_running' => $live !== null,
            ];
        }

        $hours = $litter->scoopDeadlineHours($pet, $pet->localDate($now));

        return new self(
            usesPerDay: app(HygieneEventService::class)->litterUsesOn($pet, $pet->localDate($now)),
            openUses: $open,
            nextDueAt: $iso($next),
            scoopDeadlineHours: $overdue ? min($hours, (float) LitterService::OVERDUE_SCOOP_DEADLINE_HOURS) : $hours,
            canScoop: ! $locked && $open !== [],
            change: $change,
        );
    }

    /**
     * @return array{uses_per_day: int, open_uses: list<array{id: int, used_at: string, due_at: string|null, expired: bool}>, next_due_at: string|null, scoop_deadline_hours: float, can_scoop: bool, change: array{week_started_at: string, due_at: string, done: bool, overdue: bool, blocked_reason: 'litter_not_available'|'needs_cleaning'|'litter_change_done'|'litter_change_session_active'|'care_session_active'|null, can_start: bool, session: array{id: string, kind: 'grooming'|'litter_change', started_at: string, ends_at: string, expires_at: string, duration_ms: int, min_strokes: int, segments: int, min_stroke_interval_ms: int, matted: bool}|null, session_running: bool}|null}
     */
    public function toArray(): array
    {
        return [
            // Litter uses the cat has today (kitten 3, grown cat 2).
            'uses_per_day' => $this->usesPerDay,
            // Unscooped uses, oldest first; `expired` = deadline passed (the mess is a litter_accident).
            'open_uses' => $this->openUses,
            // The earliest scoop deadline still ahead.
            'next_due_at' => $this->nextDueAt,
            // Hours a use happening now gets to be scooped (4; 2 while the weekly change is overdue).
            'scoop_deadline_hours' => $this->scoopDeadlineHours,
            // POST /api/child/pet/litter/scoop has something to scoop now.
            'can_scoop' => $this->canScoop,
            // The weekly full change (POST /api/child/pet/litter-change/start|finish).
            'change' => $this->change,
        ];
    }
}
