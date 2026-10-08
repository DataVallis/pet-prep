<?php

namespace App\Services;

use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `wand` object of a pet (M5-R06-04, CAT_SPEC §5.2) in the child state
 * and PetUpdated: the cat's daily wand play. Null for a dog (and a cat
 * without a profile). Typed so Scramble documents it. Pet facts only — no
 * personal data.
 *
 * - `goal` / `sessions_today`: today's successful sessions and the goal
 *   (`play_sessions_per_day` of today's life stage); the play meter is
 *   `pet.energy_level` (= sessions / goal × 100, plan T5) — the app labels
 *   it "Igra" for a cat (`pet.species`).
 * - `my_sessions_today`: the viewing child's own successful sessions (fair
 *   share: ⌈goal / n⌉; null in the broadcast).
 * - `min_gap_minutes` / `next_allowed_at`: the 2 h gap after the last
 *   SUCCESSFUL session (null when no gap runs).
 * - `blocked_reason`: why a start would be refused now (null = it may start;
 *   lock reasons are in `lock`) — wand_not_available | needs_cleaning |
 *   wand_too_soon | wand_session_active | wand_day_ending.
 * - `can_start`: POST /api/child/pet/wand/start would be accepted now (for
 *   the viewing child incl. their lock; pet level in the broadcast).
 * - `session`: the viewing child's running session (the start payload),
 *   else null; `session_running`: any child's game is running.
 * - `missed_yesterday`: yesterday's play routine was missed (the fact
 *   M5-R06-05 needs for "scratched the sofa").
 *
 * Instants are ISO 8601 in the family timezone.
 */
final class WandPayload
{
    /**
     * @param  array{id: string, started_at: string, ends_at: string, expires_at: string, duration_ms: int, catch_at_ms: int, pounces_ms: list<int>, min_away_moves: int, segments: int, min_move_interval_ms: int, pounce_window_ms: int}|null  $session
     */
    public function __construct(
        public readonly int $goal,
        public readonly int $sessionsToday,
        public readonly ?int $mySessionsToday,
        public readonly int $minGapMinutes,
        public readonly ?string $nextAllowedAt,
        public readonly ?string $blockedReason,
        public readonly bool $canStart,
        public readonly ?array $session,
        public readonly bool $sessionRunning,
        public readonly bool $missedYesterday,
    ) {}

    public static function for(Pet $pet, ?User $viewer = null, ?CarbonInterface $now = null): ?self
    {
        $service = app(WandPlayService::class);
        if (! $service->appliesTo($pet) || $pet->isUnborn()) {
            return null;
        }

        $now = CarbonImmutable::instance($now ?? now())->utc();
        $tz = $pet->familyTimezone();
        $iso = fn (?CarbonInterface $at): ?string => $at === null ? null : CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String();
        $today = $pet->localDate($now);

        $refusal = $service->startRefusal($pet, $viewer, $now);
        $live = $service->liveSession($pet, $now);
        $mine = $viewer !== null && $live !== null && (int) $live->user_id === $viewer->id ? $live : null;
        $yesterday = CarbonImmutable::parse($today, 'UTC')->subDay()->toDateString();
        $missed = $pet->play_missed_on !== null && substr((string) $pet->getRawOriginal('play_missed_on'), 0, 10) === $yesterday;

        return new self(
            goal: $service->goalOn($pet, $today),
            sessionsToday: $service->successfulOn($pet, $today),
            mySessionsToday: $viewer !== null ? $service->successfulOn($pet, $today, $viewer) : null,
            minGapMinutes: $service->minGapMinutes($pet, $today),
            nextAllowedAt: $iso($service->gapEndsAt($pet, $now)),
            blockedReason: $refusal['refusal']->value ?? null,
            canStart: $refusal === null && $pet->actionLockReasonFor($viewer) === null,
            session: $mine !== null ? PetActivityService::wandSessionPayload($mine, $tz) : null,
            sessionRunning: $live !== null,
            missedYesterday: $missed,
        );
    }

    /**
     * @return array{goal: int, sessions_today: int, my_sessions_today: int|null, min_gap_minutes: int, next_allowed_at: string|null, blocked_reason: 'wand_not_available'|'needs_cleaning'|'wand_too_soon'|'wand_session_active'|'wand_day_ending'|null, can_start: bool, session: array{id: string, started_at: string, ends_at: string, expires_at: string, duration_ms: int, catch_at_ms: int, pounces_ms: list<int>, min_away_moves: int, segments: int, min_move_interval_ms: int, pounce_window_ms: int}|null, session_running: bool, missed_yesterday: bool}
     */
    public function toArray(): array
    {
        return [
            // Successful sessions the cat needs today (kitten 3, grown cat 2).
            'goal' => $this->goal,
            // Today's successful sessions (all children); the meter is pet.energy_level.
            'sessions_today' => $this->sessionsToday,
            // The viewing child's own successful sessions today (null in the broadcast).
            'my_sessions_today' => $this->mySessionsToday,
            'min_gap_minutes' => $this->minGapMinutes,
            // End of the gap after the last successful session; null when none runs.
            'next_allowed_at' => $this->nextAllowedAt,
            // Why a start would be refused now (not the lock); null = it may start.
            'blocked_reason' => $this->blockedReason,
            // POST /api/child/pet/wand/start would be accepted now.
            'can_start' => $this->canStart,
            // The viewing child's running game (as returned by start), else null.
            'session' => $this->session,
            'session_running' => $this->sessionRunning,
            // Yesterday's play routine was missed (M5-R06-05: scratching).
            'missed_yesterday' => $this->missedYesterday,
        ];
    }
}
