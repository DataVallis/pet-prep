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
 * Family-wide settings, editable by any parent of the family (M1-03, M2-01).
 *
 * Since David 2026-11-09 the family timezone is the DEFAULT zone of the
 * family's pets: a pet with its own `pets.timezone` (PetTimezoneService)
 * keeps it — a family change moves only the pets that follow the family
 * (the steps below), never stamps their `timezone_changed_at`, and still
 * broadcasts to every pet (the apps show the new default).
 *
 * The family timezone is stored on `families.timezone`; every parent's
 * `users.timezone` is kept in sync (deprecated mirror). Changing it only
 * changes how wall clock rules are read from now on — stored timestamps
 * stay UTC.
 *
 * A change from the app (David 2026-11-08, »kar B«) runs in one transaction
 * under the family row lock and every pet's row lock:
 *  - at most once per MIN_HOURS_BETWEEN_CHANGES (Claude default, DECISIONS
 *    2026-11-08) so the zone can't be flipped to move meal windows — the same
 *    zone again is a no-op and never counts;
 *  - a day that already ended in the OLD zone is closed first (walk row, step
 *    reset — exactly as the tick would);
 *  - when the calendar date is the same in both zones, the running step day
 *    simply continues until the NEW zone's midnight. Without this the
 *    reset stamp (old midnight) reads as »yesterday« in a zone further west
 *    (Ljubljana → Lisbon / New York) and the next tick ran a second midnight:
 *    today's steps and energy wiped mid-day and possibly a walk illness;
 *  - the routine close pointer is re-evaluated on the next tick;
 *  - one `family_timezone_changed` PetUpdated per pet after commit, so both
 *    apps refetch (quiet hours, windows, »today«).
 * When the dates differ, the day is shorter (east) or a date repeats (west);
 * DailyWalkService never schedules a second walk illness for a date it
 * already closed.
 */
class FamilySettingsService
{
    public const MIN_HOURS_BETWEEN_CHANGES = 24;

    public function __construct(
        private readonly FamilyService $families,
        private readonly PetTimezoneService $petZones,
    ) {}

    /**
     * @throws TimezoneChangeTooSoonException
     */
    public function updateTimezone(User $parent, string $timezone): User
    {
        $family = $this->families->ensureFamilyFor($parent);

        DB::transaction(function () use ($family, $timezone, $parent): void {
            $locked = Family::whereKey($family->id)->lockForUpdate()->firstOrFail();
            $old = $locked->timezone;
            if ($old === $timezone) {
                return;
            }

            $now = CarbonImmutable::now()->utc()->startOfSecond();
            if ($locked->timezone_changed_at !== null) {
                $next = CarbonImmutable::instance($locked->timezone_changed_at)->addHours(self::MIN_HOURS_BETWEEN_CHANGES);
                if ($now->lessThan($next)) {
                    throw new TimezoneChangeTooSoonException($next, $old);
                }
            }

            $pets = Pet::where('family_id', $locked->id)->orderBy('id')->lockForUpdate()->get();

            // Only pets that follow the family zone move (David 2026-11-09).
            $following = $pets->filter(fn (Pet $p) => ! $p->hasOwnTimezone());

            // 1. Close a day that is already over in the old zone.
            foreach ($following as $pet) {
                $pet->setRelation('family', $locked);
                $this->petZones->closeOldDay($pet, $now);
            }

            $this->families->updateTimezone($locked, $timezone);
            $locked->forceFill(['timezone_changed_at' => $now])->save();

            foreach ($pets as $pet) {
                $pet->setRelation('family', $locked);
                if (! $pet->hasOwnTimezone()) {
                    // 2. Same date in both zones: the running step day continues;
                    // 3. re-evaluate the routine close pointer on the next tick.
                    $this->petZones->settleNewZone($pet, $old, $now);
                    $pet->save();
                }
                PetUpdated::afterCommit($pet, 'family_timezone_changed');
            }

            Log::info('FamilySettingsService: family timezone changed', [
                'family_id' => $locked->id,
                'by_user_id' => $parent->id,
                'from' => $old,
                'to' => $timezone,
                'pets' => $pets->count(),
            ]);
        });

        $parent->forceFill(['timezone' => $timezone])->syncOriginalAttribute('timezone');
        $parent->unsetRelation('family');

        return $parent;
    }
}
