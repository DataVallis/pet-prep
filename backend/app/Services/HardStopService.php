<?php

namespace App\Services;

use App\Events\PetUpdated;
use App\Models\Pet;
use Illuminate\Support\Facades\DB;

/**
 * The parent's hard stop (PRODUCT_SPEC §7 / §9; M1-16 backend, M2-05 review).
 *
 * The change happens under the pet's row lock (same rule as every other
 * freeze-state writer, backend/CLAUDE.md): the `updating` hook thaws the
 * neglect clocks, the `updated` hook writes the `pet_status_periods` row —
 * both inside this transaction — and exactly one PetUpdated is broadcast
 * after commit, only when the state really changed.
 */
class HardStopService
{
    /**
     * @param  bool|null  $active  the wanted state (idempotent); null = legacy toggle
     * @return array{pet: Pet, changed: bool}|null null when the pet is gone or no longer active
     */
    public function set(int $petId, ?bool $active): ?array
    {
        return DB::transaction(function () use ($petId, $active): ?array {
            $pet = Pet::whereKey($petId)->lockForUpdate()->first();

            if ($pet === null || ! $pet->is_active) {
                return null;
            }

            $target = $active ?? ! $pet->is_hard_stopped;

            if ((bool) $pet->is_hard_stopped === $target) {
                return ['pet' => $pet, 'changed' => false];
            }

            // Non-quiet update: hooks thaw / freeze and record the status period.
            $pet->update(['is_hard_stopped' => $target]);

            PetUpdated::afterCommit($pet, $target ? 'hard_stop_activated' : 'hard_stop_deactivated');

            return ['pet' => $pet, 'changed' => true];
        });
    }
}
