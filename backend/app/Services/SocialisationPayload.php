<?php

namespace App\Services;

use App\Enums\SocialisationExperience;
use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `socialisation` object of a pet (M5-R17, David 2026-10-12) in the
 * child state and PetUpdated: the puppy's weekly "new experience" card.
 * Null when this program week has no task (not a puppy week any more, a
 * breed without a socialisation row, a cat, a legacy pet, a dog whose apps
 * did not declare `socialisation`, unborn).
 *
 * - `goal_per_week` / `done_this_week` / `done`: the week's experiences
 *   (1 per week; `done` = goal reached).
 * - `week` (1 = the week from birth), `week_started_at`, `week_ends_at`:
 *   the program week (from one weekly birthday to the next).
 * - `experience`: the last experience recorded this week (person | dog |
 *   sound | place), else null; `experiences`: the kinds a child can pick.
 * - `blocked_reason`: why recording would be refused now (not the lock):
 *   socialisation_week_done | socialisation_quiet_hours; null = it may be
 *   recorded; `next_allowed_at` its end (null after the last puppy week);
 *   `can_record` (viewing child incl. lock).
 * - Never scored: no routine, Care Score, traffic light or metric.
 *
 * Instants are ISO 8601 in the family timezone.
 */
final class SocialisationPayload
{
    /**
     * @param  list<string>  $experiences
     */
    public function __construct(
        public readonly int $goalPerWeek,
        public readonly int $doneThisWeek,
        public readonly int $week,
        public readonly string $weekStartedAt,
        public readonly string $weekEndsAt,
        public readonly ?string $experience,
        public readonly array $experiences,
        public readonly ?string $nextAllowedAt,
        public readonly ?string $blockedReason,
        public readonly bool $canRecord,
    ) {}

    public static function for(Pet $pet, ?User $viewer = null, ?CarbonInterface $now = null): ?self
    {
        $service = app(SocialisationService::class);
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $week = $service->weekAt($pet, $now);
        if ($week === null) {
            return null;
        }

        $tz = $pet->familyTimezone();
        $iso = fn (?CarbonInterface $at): ?string => $at === null ? null : CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String();
        $rows = $service->rowsBetween($pet, $week['start'], $now->addSecond());
        $last = $rows->last();
        $refusal = $service->refusal($pet, $now);

        return new self(
            goalPerWeek: $week['goal'],
            doneThisWeek: min($rows->count(), $week['goal']),
            week: $week['index'] + 1,
            weekStartedAt: (string) $iso($week['start']),
            weekEndsAt: (string) $iso($week['end']),
            experience: $last !== null ? SocialisationExperience::fromCode($last->value !== null ? (int) $last->value : null)?->value : null,
            experiences: SocialisationExperience::values(),
            nextAllowedAt: $iso($refusal['next_allowed_at'] ?? null),
            blockedReason: $refusal['refusal']->value ?? null,
            canRecord: $refusal === null && $pet->actionLockReasonFor($viewer) === null,
        );
    }

    /**
     * @return array{goal_per_week: int, done_this_week: int, done: bool, week: int, week_started_at: string, week_ends_at: string, experience: 'person'|'dog'|'sound'|'place'|null, experiences: list<'person'|'dog'|'sound'|'place'>, next_allowed_at: string|null, blocked_reason: 'socialisation_not_available'|'socialisation_week_done'|'socialisation_quiet_hours'|null, can_record: bool}
     */
    public function toArray(): array
    {
        return [
            // New experiences per program week while a puppy (1, the breed's puppy-stage row).
            'goal_per_week' => $this->goalPerWeek,
            'done_this_week' => $this->doneThisWeek,
            // This week's task is done.
            'done' => $this->doneThisWeek >= $this->goalPerWeek,
            // Program week number (1 = the week from birth).
            'week' => $this->week,
            'week_started_at' => $this->weekStartedAt,
            'week_ends_at' => $this->weekEndsAt,
            // The last experience recorded this week, else null.
            'experience' => $this->experience,
            // What the child can pick: a new person, another dog, a new sound, a new place.
            'experiences' => $this->experiences,
            // When the refusal in blocked_reason ends (next puppy week / end of quiet hours); null = never this stage.
            'next_allowed_at' => $this->nextAllowedAt,
            // Why recording would be refused now (not the lock); null = it may be recorded.
            'blocked_reason' => $this->blockedReason,
            // POST /api/child/pet/socialisation would be accepted now.
            'can_record' => $this->canRecord,
        ];
    }
}
