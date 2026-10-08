<?php

namespace App\Services;

use App\Enums\CareSessionKind;
use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `grooming` object of a pet (M5-R06-05, CAT_SPEC Q8; David 2026-10-08
 * ~22:20) in the child state and PetUpdated: the Maine Coon's combing.
 * Null for every pet without a grooming routine (dogs, the domestic cat).
 *
 * - `goal_per_week` / `done_this_week`: groomings of the current program
 *   week (from one weekly birthday to the next: `week_started_at`,
 *   `week_ends_at`).
 * - `matted` / `matted_since`: the matted coat (≥ 2 of the previous week's
 *   3 groomings missed) — visible only, no penalty; the next grooming lasts
 *   ~2× (`session_seconds`) and resolves it.
 * - `blocked_reason`: why a start would be refused now (grooming_not_available
 *   | needs_cleaning | grooming_week_done | grooming_done_today |
 *   grooming_session_active | grooming_quiet_hours; null = it may start),
 *   `next_allowed_at` its end; `can_start` (viewing child incl. lock).
 * - `session`: the viewing child's running session; `session_running`: anyone's.
 *
 * Instants are ISO 8601 in the family timezone.
 */
final class GroomingPayload
{
    /**
     * @param  array<string, mixed>|null  $session
     */
    public function __construct(
        public readonly int $goalPerWeek,
        public readonly int $doneThisWeek,
        public readonly string $weekStartedAt,
        public readonly string $weekEndsAt,
        public readonly bool $matted,
        public readonly ?string $mattedSince,
        public readonly int $sessionSeconds,
        public readonly ?string $nextAllowedAt,
        public readonly ?string $blockedReason,
        public readonly bool $canStart,
        public readonly ?array $session,
        public readonly bool $sessionRunning,
    ) {}

    public static function for(Pet $pet, ?User $viewer = null, ?CarbonInterface $now = null): ?self
    {
        $chores = app(CatChoreService::class);
        $now = CarbonImmutable::instance($now ?? now())->utc();
        if ($pet->isUnborn() || ! $pet->isCat()) {
            return null;
        }
        $period = $chores->groomingPeriodAt($pet, $now);
        if ($period === null) {
            return null;
        }

        $tz = $pet->familyTimezone();
        $iso = fn (?CarbonInterface $at): ?string => $at === null ? null : CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String();
        $refusal = $chores->startRefusal($pet, $viewer, CareSessionKind::Grooming, $now);
        $live = $chores->liveSession($pet, CareSessionKind::Grooming, $now);
        $mine = $viewer !== null && $live !== null && (int) $live->user_id === $viewer->id ? $live : null;

        return new self(
            goalPerWeek: $chores->groomingGoalOn($pet, $pet->localDate($now)),
            doneThisWeek: $chores->groomingsBetween($pet, $period['start'], $now->addSecond()),
            weekStartedAt: (string) $iso($period['start']),
            weekEndsAt: (string) $iso($period['end']),
            matted: $chores->isMatted($pet),
            mattedSince: $iso($pet->coat_matted_at),
            sessionSeconds: $chores->sessionSeconds($pet, CareSessionKind::Grooming),
            nextAllowedAt: $iso($refusal['next_allowed_at'] ?? null),
            blockedReason: $refusal['refusal']->value ?? null,
            canStart: $refusal === null && $pet->actionLockReasonFor($viewer) === null,
            session: $mine !== null ? CareSessionPayload::chore($mine, $tz) : null,
            sessionRunning: $live !== null,
        );
    }

    /**
     * @return array{goal_per_week: int, done_this_week: int, week_started_at: string, week_ends_at: string, matted: bool, matted_since: string|null, session_seconds: int, next_allowed_at: string|null, blocked_reason: 'grooming_not_available'|'needs_cleaning'|'grooming_week_done'|'grooming_done_today'|'grooming_session_active'|'grooming_quiet_hours'|null, can_start: bool, session: array{id: string, kind: 'grooming'|'litter_change', started_at: string, ends_at: string, expires_at: string, duration_ms: int, min_strokes: int, segments: int, min_stroke_interval_ms: int, matted: bool}|null, session_running: bool}
     */
    public function toArray(): array
    {
        return [
            // Groomings per program week (Maine Coon 3, ≥ 1 day apart).
            'goal_per_week' => $this->goalPerWeek,
            'done_this_week' => $this->doneThisWeek,
            'week_started_at' => $this->weekStartedAt,
            'week_ends_at' => $this->weekEndsAt,
            // Matted coat (≥ 2 of last week's groomings missed): visible only, no penalty.
            'matted' => $this->matted,
            'matted_since' => $this->mattedSince,
            // Length of the next grooming (~2× while matted).
            'session_seconds' => $this->sessionSeconds,
            // When the refusal in blocked_reason ends (next day / next week / end of quiet hours).
            'next_allowed_at' => $this->nextAllowedAt,
            // Why a start would be refused now (not the lock); null = it may start.
            'blocked_reason' => $this->blockedReason,
            // POST /api/child/pet/grooming/start would be accepted now.
            'can_start' => $this->canStart,
            // The viewing child's running grooming (as returned by start), else null.
            'session' => $this->session,
            'session_running' => $this->sessionRunning,
        ];
    }
}
