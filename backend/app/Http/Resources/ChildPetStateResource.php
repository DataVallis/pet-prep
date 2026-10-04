<?php

namespace App\Http\Resources;

use App\Enums\PetLockReason;
use App\Models\Pet;
use App\Services\CareScheduleService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full pet state for the child app (GET /api/child/pet, and `state` in every
 * child action response — M1-07). Metrics are the displayed integers 0–100
 * (Pet::displayMetric). Instants are ISO 8601 in the family timezone
 * (e.g. 2026-10-04T17:00:00+02:00); feed windows also as local "HH:MM".
 *
 * `can_feed` / `can_water` combine every server rule (lock, mess, window /
 * limit), so the app can disable the button and show `next_*` times.
 *
 * @property Pet $resource
 */
class ChildPetStateResource extends JsonResource
{
    /** No `data` wrapper. */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pet = $this->resource;
        $now = now()->startOfSecond();
        $tz = $pet->familyTimezone();
        $config = $pet->breedConfig();
        $schedule = app(CareScheduleService::class);

        $lockReason = $pet->actionLockReason();
        $locked = $lockReason !== null;
        $needsCleaning = $pet->displayMetric('hygiene_level') <= 0;
        $iso = fn (?CarbonInterface $at): ?string => $at?->copy()->setTimezone($tz)->toIso8601String();

        $feeding = $config ? $schedule->feeding($pet, $config, $now) : null;
        $water = $config ? $schedule->water($pet, $config, $now) : null;
        $contract = $pet->contract;

        return [
            'pet' => [
                'id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
                'born_at' => $iso($pet->born_at),
                'virtual_age_months' => $pet->virtualAgeInMonths(),
                'hunger_level' => $pet->displayMetric('hunger_level'),
                'thirst_level' => $pet->displayMetric('thirst_level'),
                'energy_level' => $pet->displayMetric('energy_level'),
                'hygiene_level' => $pet->displayMetric('hygiene_level'),
                'pet_state' => $pet->pet_state->value,
                'escalation_level' => (int) $pet->escalation_level,
                'needs_cleaning' => $needsCleaning,
                'is_active' => (bool) $pet->is_active,
                'is_hard_stopped' => (bool) $pet->is_hard_stopped,
                'is_ill' => $pet->isIll(),
                'illness_until' => $pet->isIll() ? $iso($pet->illness_until) : null,
                'is_game_over' => (bool) $pet->is_game_over,
                'certificate_eligible' => (bool) $pet->certificate_eligible,
                'current_video_url' => $pet->current_video_url,
                'media_status' => $pet->media_status,
                'reference_image_url' => $pet->pet_dna['reference_image_url'] ?? null,
            ],
            'lock' => [
                'is_locked' => $locked,
                // game_over | inactive | hard_stopped | ill | null
                'reason' => $lockReason?->value,
                // End of the vet visit; null for locks without an end time.
                'until' => $lockReason === PetLockReason::Ill ? $iso($pet->illness_until) : null,
            ],
            'timezone' => $tz,
            'server_time' => $iso($now),
            'feeding' => [
                'windows' => array_map(
                    fn (array $window): array => ['start' => $window[0], 'end' => $window[1]],
                    $feeding->windows ?? [],
                ),
                'current_window' => $feeding?->currentStart
                    ? ['start' => $iso($feeding->currentStart), 'end' => $iso($feeding->currentEnd)]
                    : null,
                'fed_in_current_window' => (bool) $feeding?->fedInCurrent,
                'can_feed' => ! $locked && ! $needsCleaning && (bool) $feeding?->windowOpen(),
                // The current window while unused, otherwise the next one.
                'next_feed_window' => $feeding?->nextStart
                    ? ['start' => $iso($feeding->nextStart), 'end' => $iso($feeding->nextEnd)]
                    : null,
                'last_fed_at' => $iso($feeding?->lastFedAt),
            ],
            'water' => [
                'times_per_day' => $water->timesPerDay ?? 0,
                'min_gap_minutes' => $water->minGapMinutes ?? 0,
                'used_today' => $water->usedToday ?? 0,
                'remaining_today' => $water?->remainingToday() ?? 0,
                'last_watered_at' => $iso($water?->lastAt),
                'can_water' => ! $locked && ! $needsCleaning && (bool) $water?->allowedNow(),
                // Earliest next refill by the water rules; null when allowed now.
                'next_allowed_at' => $iso($water?->nextAllowedAt),
            ],
            'steps' => [
                'steps_today' => (int) $pet->daily_step_count,
                'goal' => (int) ($config->daily_steps_required ?? 0),
                'energy_level' => $pet->displayMetric('energy_level'),
            ],
            'contract' => [
                'signed' => $contract !== null,
                'signed_at' => $iso($contract?->signed_at),
            ],
        ];
    }
}
