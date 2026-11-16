<?php

namespace App\Services;

use App\Events\PetUpdated;
use App\Models\Family;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quiet hours per pet (David 2026-11-16: »Mi imamo 3 pse in mačko. Hčerka
 * mačko, sin psa. Jaz psa in žena psa. Zdaj pa ko nastavim school hours mi
 * avtomatsko za vse živali velja. To ni ok.«). Same pattern as the time zone
 * per pet (#891): every pet follows the family's quiet hours by default; a
 * parent can give one pet its own schedule (»lastne ure«); a family change
 * never moves a pet with its own hours; `null` = follow the family again.
 *
 * The only writer of `pets.own_quiet_hours`. A change takes effect exactly
 * like a change of the family's quiet hours (QuietHoursController::update,
 * unchanged): the value is written and every rule reads it from the next
 * read on — the next decay tick splits the time since `last_decay_at` with
 * the new schedule, closed days (`pet_daily_routines`) are never recomputed,
 * today's open day is evaluated with the schedule in force when it closes.
 * No limit per day (the family hours have none either).
 *
 * One transaction, family row lock → pet row lock (the order of the family
 * and pet zone changes), one `pet_quiet_hours_changed` PetUpdated after
 * commit when the stored value changed, one audit log line (no PII).
 */
class PetQuietHoursService
{
    /**
     * Give the pet its own schedule, or — `$values` null — make it follow
     * the family's quiet hours again.
     *
     * @param  array<string, mixed>|null  $values  school_start, school_end, bedtime_start, bedtime_end ("HH:MM" | null), is_active
     */
    public function change(User $parent, Pet $pet, ?array $values): Pet
    {
        $new = $values === null ? null : QuietHours::normaliseValues($values);

        $changed = DB::transaction(function () use ($parent, $pet, $new): Pet {
            $family = Family::whereKey($pet->family_id)->lockForUpdate()->firstOrFail();
            /** @var Pet $locked */
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('family', $family);

            $old = $locked->ownQuietHoursValues();
            if ($old === $new) {
                return $locked; // nothing to do
            }

            $locked->forceFill(['own_quiet_hours' => $new])->save();
            PetUpdated::afterCommit($locked, 'pet_quiet_hours_changed');

            Log::info('PetQuietHoursService: pet quiet hours changed', [
                'family_id' => $family->id,
                'pet_id' => $locked->id,
                'by_user_id' => $parent->id,
                'follows_family' => $new === null,
                'was_following_family' => $old === null,
            ]);

            return $locked;
        });

        return $changed->fresh(['family']) ?? $changed;
    }

    /**
     * The `quiet_hours` object the apps get for a pet: the effective
     * schedule ("HH:MM" | null) + whether it is the family's.
     *
     * @return array{school_start: string|null, school_end: string|null, bedtime_start: string|null, bedtime_end: string|null, is_active: bool, follows_family: bool}
     */
    public static function payloadFor(Pet $pet): array
    {
        $own = $pet->ownQuietHoursValues();
        $values = $own ?? QuietHours::normaliseValues($pet->familyQuietHours()->only(QuietHours::SCHEDULE_KEYS));

        return $values + ['follows_family' => $own === null];
    }
}
