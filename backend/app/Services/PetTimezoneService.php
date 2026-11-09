<?php

namespace App\Services;

use App\Events\PetUpdated;
use App\Exceptions\TimezoneChangeTooSoonException;
use App\Models\Family;
use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Time zone per pet (David 2026-11-09): family members in different places
 * (a parent with his dog in Lisbon, the children with theirs in Ljubljana).
 * A pet's effective zone = `pets.timezone` ?? `families.timezone`
 * (Pet::effectiveTimezone()); every wall-clock rule of the pet reads it.
 *
 * The only writer of `pets.timezone` / `pets.timezone_changed_at`. A change
 * reuses the family change logic of #838 (FamilySettingsService), per pet:
 *  - one transaction, family row lock → pet row lock (the family change's
 *    order), so a family change and a pet change never interleave;
 *  - a day that already ended in the OLD zone is closed first (walk row,
 *    step reset — exactly as the tick would);
 *  - same calendar date in both zones → the running step day continues until
 *    the NEW zone's midnight (no double midnight, no wiped steps, no walk
 *    illness); a repeated date (move west across midnight) never schedules a
 *    second walk illness (DailyWalkService);
 *  - the routine close pointer is re-evaluated on the next tick;
 *  - one `pet_timezone_changed` PetUpdated after commit.
 * At most once per MIN_HOURS_BETWEEN_CHANGES per pet — counted only when the
 * pet's EFFECTIVE zone changes (pinning the zone it already follows, or
 * resetting to an equal family zone, is free and stamps nothing). A family
 * change never stamps the pets (its own 24 h limit applies), so »family back
 * to Ljubljana, then my dog to Lisbon« works right away.
 */
class PetTimezoneService
{
    public const MIN_HOURS_BETWEEN_CHANGES = 24;

    public function __construct(private readonly DailyWalkService $dailyWalks) {}

    /**
     * Set the pet's own zone, or — `$timezone` null — make it follow the
     * family zone again.
     *
     * @throws TimezoneChangeTooSoonException
     */
    public function change(User $parent, Pet $pet, ?string $timezone): Pet
    {
        $changed = DB::transaction(function () use ($pet, $timezone, $parent): Pet {
            $family = Family::whereKey($pet->family_id)->lockForUpdate()->firstOrFail();
            /** @var Pet $locked */
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('family', $family);

            $oldOwn = $locked->hasOwnTimezone() ? (string) $locked->timezone : null;
            if ($oldOwn === $timezone) {
                return $locked; // nothing to do
            }

            $oldZone = $locked->effectiveTimezone();
            $newZone = $timezone ?? $family->timezone;
            $now = CarbonImmutable::now()->utc()->startOfSecond();

            if ($oldZone === $newZone) {
                // Pin / unpin without moving the clock: no game effect, never counted.
                $locked->forceFill(['timezone' => $timezone])->save();
                PetUpdated::afterCommit($locked, 'pet_timezone_changed');

                return $locked;
            }

            if ($locked->timezone_changed_at !== null) {
                $next = CarbonImmutable::instance($locked->timezone_changed_at)->addHours(self::MIN_HOURS_BETWEEN_CHANGES);
                if ($now->lessThan($next)) {
                    throw new TimezoneChangeTooSoonException($next, $oldZone, 'The pet time zone can be changed once every 24 hours.');
                }
            }

            $this->closeOldDay($locked, $now);
            $locked->timezone = $timezone;
            $this->settleNewZone($locked, $oldZone, $now);
            $locked->timezone_changed_at = $now;
            $locked->save();
            PetUpdated::afterCommit($locked, 'pet_timezone_changed');

            Log::info('PetTimezoneService: pet timezone changed', [
                'family_id' => $family->id,
                'pet_id' => $locked->id,
                'by_user_id' => $parent->id,
                'from' => $oldZone,
                'to' => $newZone,
                'follows_family' => $timezone === null,
            ]);

            return $locked;
        });

        return $changed->fresh(['family']) ?? $changed;
    }

    /**
     * Step 1 of a zone move (pet change or family change): close a day that
     * is already over on the pet's CURRENT (old) clock. The caller holds the
     * pet's row lock and saves.
     */
    public function closeOldDay(Pet $locked, CarbonImmutable $now): void
    {
        $this->dailyWalks->closeDayIfNeeded($locked, $now, allowIllness: ! $locked->isFrozen());
    }

    /**
     * Step 2, after the pet's effective zone moved from $oldZone: same date
     * in both zones → the running step day continues until the new midnight
     * (re-anchored reset stamp); the routine close pointer is pulled to now.
     * Attributes only — the caller holds the lock and saves.
     */
    public function settleNewZone(Pet $locked, string $oldZone, CarbonImmutable $now): void
    {
        $newZone = $locked->effectiveTimezone();
        $oldToday = $now->setTimezone($oldZone)->toDateString();
        $newToday = $now->setTimezone($newZone)->toDateString();

        if ($oldToday === $newToday && $locked->last_step_reset_at !== null
            && $locked->localDate($locked->last_step_reset_at) < $newToday) {
            $locked->last_step_reset_at = CarbonImmutable::parse($newToday, $newZone)->startOfDay()->utc();
        }

        $nextClose = $locked->routines_next_close_at; // uncast column (UTC)
        if ($nextClose !== null && CarbonImmutable::parse((string) $nextClose, 'UTC')->greaterThan($now)) {
            $locked->routines_next_close_at = $now->toDateTimeString();
        }
    }
}
