<?php

namespace App\Services;

use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `scratching` object of a pet (M5-R06-05, CAT_SPEC Q10) in the child
 * state and PetUpdated: "opraskala je kavč" and its resolve "Odnesi na
 * praskalnik in pohvali". Null for a dog (and a cat without a profile, or
 * unborn).
 *
 * - `active`: the open scratching mess (it also shows in
 *   `behaviour.active_events` with kind `scratching` and as
 *   `behaviour.scene`) with its 2 h deadline (outside quiet hours, like the
 *   dog's chewing); null when there is none.
 * - `blocked_reason` (scratching_not_needed | scratching_session_active;
 *   null = a carry may start), `can_start` (viewing child incl. lock),
 *   the viewing child's running `session`, `session_running`.
 *
 * Instants are ISO 8601 in the family timezone.
 */
final class ScratchingPayload
{
    /**
     * @param  array{id: int, started_at: string, due_at: string}|null  $active
     * @param  array<string, mixed>|null  $session
     */
    public function __construct(
        public readonly ?array $active,
        public readonly ?string $blockedReason,
        public readonly bool $canStart,
        public readonly ?array $session,
        public readonly bool $sessionRunning,
    ) {}

    public static function for(Pet $pet, ?User $viewer = null, ?CarbonInterface $now = null): ?self
    {
        if (! $pet->isCat() || $pet->isLegacyProfile() || $pet->isUnborn()) {
            return null;
        }

        $service = app(ScratchingService::class);
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $tz = $pet->familyTimezone();
        $iso = fn (CarbonInterface $at): string => CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String();

        $event = $service->openEvent($pet);
        $active = null;
        if ($event !== null) {
            $started = CarbonImmutable::instance($event->scheduled_at)->utc();
            $active = [
                'id' => $event->id,
                'started_at' => $iso($started),
                'due_at' => $iso(app(RoutineLedgerService::class)->addSecondsOutsideQuiet($pet->quietHours(), $started, RoutineLedgerService::CLEAN_WITHIN_SECONDS)),
            ];
        }
        $refusal = $service->startRefusal($pet, $viewer, $now);
        $live = $service->liveSession($pet, $now);
        $mine = $viewer !== null && $live !== null && (int) $live->user_id === $viewer->id ? $live : null;

        return new self(
            active: $active,
            blockedReason: $refusal['refusal']->value ?? null,
            canStart: $refusal === null && $pet->actionLockReasonFor($viewer) === null,
            session: $mine !== null ? CareSessionPayload::scratching($mine, $tz) : null,
            sessionRunning: $live !== null,
        );
    }

    /**
     * @return array{active: array{id: int, started_at: string, due_at: string}|null, blocked_reason: 'scratching_not_needed'|'scratching_session_active'|'care_session_active'|null, can_start: bool, session: array{id: string, started_at: string, ends_at: string, expires_at: string, land_at_ms: int, praise_window_ms: int, min_reaction_ms: int}|null, session_running: bool}
     */
    public function toArray(): array
    {
        return [
            // The open "scratched the sofa" mess with its 2 h deadline; null when none.
            'active' => $this->active,
            // Why a carry would be refused now (not the lock); null = it may start.
            'blocked_reason' => $this->blockedReason,
            // POST /api/child/pet/scratching/start would be accepted now.
            'can_start' => $this->canStart,
            // The viewing child's running carry (as returned by start), else null.
            'session' => $this->session,
            'session_running' => $this->sessionRunning,
        ];
    }
}
