<?php

namespace App\Services;

use App\Enums\TrainingCommand;
use App\Models\Pet;
use App\Models\PetTrainingSkill;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The `training` object of a pet (M5-R03, David 2026-10-06) in the child
 * state (full), the parent dashboard and PetUpdated (summary). Typed so
 * Scramble documents it (mobile/src/api/schema.ts). Pet facts only — no
 * personal data (the running session says only whether it is the viewing
 * child's own).
 *
 * - `enabled`: training exists for this pet (profile + app feature
 *   `training`); false for legacy pets and pets created by older apps —
 *   then `commands` is empty.
 * - `commands`: every command (sit, come, place, potty) with the displayed
 *   progress 0–100, `learned` (100 %) and the last completed session.
 * - `today_done`: a session was completed today (the training routine).
 * - `session` (child state only): the running session, else null.
 * - budget: the dog's mini-game seconds per family-local day and what is left.
 *
 * Instants are ISO 8601 in the family timezone.
 */
final class TrainingPayload
{
    /**
     * @param  list<array{command: 'sit'|'come'|'place'|'potty', progress: int, learned: bool, last_practised_at: string|null}>  $commands
     * @param  array{id: string, command: 'sit'|'come'|'place'|'potty', started_at: string, ends_at: string, expires_at: string, mine: bool}|null  $session
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $commands,
        public readonly bool $todayDone,
        public readonly ?array $session,
        public readonly int $dailyBudgetSeconds,
        public readonly int $dailyBudgetLeftSeconds,
    ) {}

    public static function for(Pet $pet, ?User $viewer = null, ?CarbonInterface $now = null): self
    {
        if (! $pet->trainingEnabled() || $pet->isUnborn()) {
            return new self($pet->trainingEnabled(), $pet->trainingEnabled() ? self::commandsOf($pet, collect()) : [], false, null, 0, 0);
        }

        $now = CarbonImmutable::instance($now ?? now());
        $service = app(TrainingService::class);
        $tz = $pet->familyTimezone();
        $iso = fn (CarbonInterface $at): string => CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String();
        $today = $pet->localDate($now);

        $live = $service->liveSession($pet, $now);
        $budget = $service->dailyBudgetSeconds($pet, $today);

        return new self(
            true,
            self::commandsOf($pet, $service->skills($pet), $tz),
            $service->doneOn($pet, $now),
            $live === null ? null : [
                'id' => $live->public_id,
                'command' => $live->command->value,
                'started_at' => $iso($live->started_at),
                'ends_at' => $iso($live->ends_at),
                'expires_at' => $iso($live->expires_at),
                'mine' => $viewer !== null && (int) $live->user_id === $viewer->id,
            ],
            $budget,
            max(0, $budget - $service->usedSecondsOn($pet, $today)),
        );
    }

    /**
     * @param  Collection<string, PetTrainingSkill>  $skills
     * @return list<array{command: 'sit'|'come'|'place'|'potty', progress: int, learned: bool, last_practised_at: string|null}>
     */
    private static function commandsOf(Pet $pet, $skills, ?string $tz = null): array
    {
        $tz ??= $pet->familyTimezone();
        $out = [];
        foreach (TrainingCommand::cases() as $command) {
            $skill = $skills->get($command->value);
            $progress = Pet::displayValue((float) ($skill->progress ?? 0.0));
            $out[] = [
                'command' => $command->value,
                'progress' => $progress,
                'learned' => $progress >= 100,
                'last_practised_at' => $skill?->last_practised_at?->copy()->setTimezone($tz)->toIso8601String(),
            ];
        }

        return $out;
    }

    /** Seconds of one session (the app can show "a session takes 50 s"). */
    public static function sessionSeconds(): int
    {
        return intdiv(TrainingService::sessionDurationMs() + 999, 1000);
    }

    /**
     * Full object for the child state.
     *
     * @return array{enabled: bool, commands: list<array{command: 'sit'|'come'|'place'|'potty', progress: int, learned: bool, last_practised_at: string|null}>, today_done: bool, session: array{id: string, command: 'sit'|'come'|'place'|'potty', started_at: string, ends_at: string, expires_at: string, mine: bool}|null, session_seconds: int, daily_budget_seconds: int, daily_budget_left_seconds: int}
     */
    public function toArray(): array
    {
        return [
            // Training exists for this pet (new pet + app feature `training`); false for legacy pets.
            'enabled' => $this->enabled,
            /**
             * Every command with displayed progress 0–100 (empty when not enabled).
             *
             * @var list<array{command: 'sit'|'come'|'place'|'potty', progress: int, learned: bool, last_practised_at: string|null}>
             */
            'commands' => $this->commands,
            // A session was completed today (the daily training routine).
            'today_done' => $this->todayDone,
            /**
             * The running session (any caretaker), `mine` = started by this child; else null.
             *
             * @var array{id: string, command: 'sit'|'come'|'place'|'potty', started_at: string, ends_at: string, expires_at: string, mine: bool}|null
             */
            'session' => $this->session,
            // Length of one session in seconds.
            'session_seconds' => self::sessionSeconds(),
            // The dog's mini-game seconds per family-local day, and what is left today.
            'daily_budget_seconds' => $this->dailyBudgetSeconds,
            'daily_budget_left_seconds' => $this->dailyBudgetLeftSeconds,
        ];
    }

    /**
     * Summary for the parent dashboard and PetUpdated ("Kuža zna: sedi ✓, pridi 60 %").
     *
     * @return array{enabled: bool, commands: list<array{command: 'sit'|'come'|'place'|'potty', progress: int, learned: bool, last_practised_at: string|null}>, today_done: bool, session_active: bool}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            /**
             * @var list<array{command: 'sit'|'come'|'place'|'potty', progress: int, learned: bool, last_practised_at: string|null}>
             */
            'commands' => $this->commands,
            'today_done' => $this->todayDone,
            // A child is training the dog right now.
            'session_active' => $this->session !== null,
        ];
    }
}
