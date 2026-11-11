<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\FamilyRole;
use App\Enums\PetPlan;
use App\Models\Pet;
use App\Services\GameLoop\PetLoopGuard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The day-7 parent reminder of a FREE pet (M6-U02, FREE_PET_UPGRADE_SPEC U5 / D1 / §5.7,
 * David 2026-11-10): »[Vzdevek] že 7 dni skrbi za [ime]. Je čas za pravi preizkus?«
 *
 * Pattern of PetBirthdayService (once per pet, a cheap minutely check, dates on the pet's clock):
 *
 *  - Which pets: an upgradeable free pet (Pet::isUpgradeableFreePet — free plan, free breed,
 *    born, active, not game over, not a pre-M5 legacy profile). A challenge pet (also a
 *    »mešanček + izziv« one) never gets it.
 *  - The 7 days: the pet-local date of the contract (`born_at`) and the 6 following pet-local
 *    dates (Pet::effectiveTimezone()). D1: ONE child of the family did at least one care action
 *    (`activities_log` row with that child as the actor; signing the contract does not count)
 *    on at least {@see self::ACTIVE_DAYS} of those 7 dates. Per child, not summed over siblings
 *    (the card names that child — conservative default, DECISIONS 2026-11-11).
 *  - When: from the contract's wall-clock time 7 days later (DST-safe — the 7 dates are then
 *    complete) for {@see self::WINDOW_HOURS} hours. A pet locked (hard stop / illness) is looked
 *    at again every few minutes inside that window; a window that passes entirely locked, or
 *    a pet whose window ended before this feature shipped, gets no reminder (never later).
 *  - Once: `pets.upgrade_reminder_sent_at` (atomic, under the row lock) — one push to the
 *    family's parents (NotificationService::upgradeReminder: held over quiet hours, suppressed at
 *    delivery when the pet is no longer upgradeable) and from then on the dashboard card
 *    ({@see self::cardFor()}). The child is never told anything (no prices on the child side),
 *    so there is no PetUpdated broadcast.
 *  - `pets.upgrade_reminder_next_check_at` keeps the tick cheap: the due instant, or "never"
 *    once decided (sent, not qualified, window passed, not applicable).
 *
 * Logs carry ids only (no child names).
 */
class UpgradeReminderService
{
    /** Push type value (PushType::UpgradeReminder). */
    public const TYPE = 'upgrade_reminder';

    /** D1: the 7 pet-local days after the contract … */
    public const DAYS = 7;

    /** … of which the child acted on at least this many. */
    public const ACTIVE_DAYS = 5;

    /** The reminder may go out this long after it became due (then never). */
    public const WINDOW_HOURS = 24;

    /** The dashboard card is shown this many days after the reminder (then it goes away by itself). */
    public const CARD_DAYS = 7;

    /** Pets looked at per tick (the rest wait for the next minute). */
    public const MAX_PETS_PER_TICK = 200;

    /** A locked pet (hard stop, illness) is looked at again this much later. */
    public const LOCKED_RECHECK_MINUTES = 5;

    /** `upgrade_reminder_next_check_at` of a pet with nothing left to decide. */
    public const NEVER = '2999-12-31 00:00:00';

    /** True when the reminder may still concern this pet (an upgradeable free pet). */
    public function applies(Pet $pet): bool
    {
        return $pet->plan === PetPlan::Free && $pet->isUpgradeableFreePet();
    }

    /** The instant the reminder becomes due: the contract's wall-clock time 7 pet-local days later. */
    public function dueAt(Pet $pet): ?CarbonImmutable
    {
        if ($pet->born_at === null) {
            return null;
        }

        return CarbonImmutable::instance($pet->born_at)->setTimezone($pet->effectiveTimezone())->addDays(self::DAYS)->utc();
    }

    /**
     * Active days per child over the 7 pet-local dates from the contract's date: child id →
     * number of dates with at least one care action. Children of the pet's family only.
     *
     * @return array<int, int>
     */
    public function activeDaysByChild(Pet $pet): array
    {
        if ($pet->born_at === null) {
            return [];
        }
        $tz = $pet->effectiveTimezone();
        $from = CarbonImmutable::instance($pet->born_at)->setTimezone($tz)->startOfDay();
        $until = $from->addDays(self::DAYS); // start of the 8th date — exclusive

        $children = DB::table('family_user')
            ->where('family_id', $pet->family_id)
            ->where('role', FamilyRole::Child->value)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($children === []) {
            return [];
        }

        $dates = [];
        DB::table('activities_log')
            ->where('pet_id', $pet->id)
            ->whereIn('actor_user_id', $children)
            ->where('activity_type', '!=', ActivityType::SignedContract->value)
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $until->utc())
            ->orderBy('id')
            ->get(['actor_user_id', 'created_at'])
            ->each(function ($row) use (&$dates, $tz): void {
                $date = CarbonImmutable::parse((string) $row->created_at, 'UTC')->setTimezone($tz)->toDateString();
                $dates[(int) $row->actor_user_id][$date] = true;
            });

        return array_map('count', $dates);
    }

    /** D1: the child who qualifies (most active days, ties → lowest id), or null when nobody reached 5 of 7. */
    public function qualifyingChild(Pet $pet): ?int
    {
        $days = array_filter($this->activeDaysByChild($pet), fn (int $n) => $n >= self::ACTIVE_DAYS);
        if ($days === []) {
            return null;
        }
        ksort($days);

        return (int) array_search(max($days), $days, true);
    }

    /**
     * The dashboard card (`pets[].upgrade_reminder`): the reminder went out, the pet can still
     * be upgraded, and it is at most {@see self::CARD_DAYS} days old. `child_id` = the child
     * whose days qualified (the app shows their name; null when that account is gone).
     *
     * @return array{sent_at: string, child_id: int|null}|null
     */
    public static function cardFor(Pet $pet, ?CarbonInterface $now = null): ?array
    {
        $sent = $pet->upgrade_reminder_sent_at;
        if ($sent === null || ! $pet->isUpgradeableFreePet()) {
            return null;
        }
        if (CarbonImmutable::instance($sent)->addDays(self::CARD_DAYS)->lessThanOrEqualTo($now ?? now())) {
            return null;
        }

        return [
            'sent_at' => CarbonImmutable::instance($sent)->setTimezone($pet->effectiveTimezone())->toIso8601String(),
            'child_id' => $pet->upgrade_reminder_child_id !== null ? (int) $pet->upgrade_reminder_child_id : null,
        ];
    }

    /**
     * The tick: decide every free pet whose reminder is due (once), schedule the next look.
     *
     * @return array{pets: int, sent: int}
     */
    public function processDue(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();

        $pets = Pet::born()
            ->where('plan', PetPlan::Free->value)
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->whereNotNull('arrival_age_months')
            ->whereNull('upgrade_reminder_sent_at')
            ->where(fn ($q) => $q->whereNull('upgrade_reminder_next_check_at')->orWhere('upgrade_reminder_next_check_at', '<=', $now))
            ->orderByRaw('upgrade_reminder_next_check_at ASC NULLS FIRST')
            ->orderBy('id')
            ->limit(self::MAX_PETS_PER_TICK)
            ->with('family')
            ->get();

        $sent = 0;
        foreach ($pets as $pet) {
            try {
                $sent += $this->processPet($pet, $now) ? 1 : 0;
            } catch (Throwable $e) {
                // One pet never stops the tick; it is retried a few minutes later.
                Log::error('UpgradeReminderService: check failed', ['pet_id' => $pet->id, 'error' => $e->getMessage()]);
                app(PetLoopGuard::class)->failed('upgrade_reminder', $pet->id, $e);
                $this->setNextCheck($pet->id, $now->addMinutes(self::LOCKED_RECHECK_MINUTES));
            }
        }

        return ['pets' => $pets->count(), 'sent' => $sent];
    }

    private function processPet(Pet $pet, CarbonImmutable $now): bool
    {
        $due = $this->dueAt($pet);
        if ($due === null || ! $this->applies($pet)) {
            $this->setNextCheck($pet->id, null);

            return false;
        }
        if ($now->lessThan($due)) {
            $this->setNextCheck($pet->id, $due);

            return false;
        }
        if ($now->greaterThanOrEqualTo($due->addHours(self::WINDOW_HOURS))) {
            // Passed (born before this feature, or the whole window was locked): never later.
            $this->setNextCheck($pet->id, null);

            return false;
        }
        if ($this->locked($pet)) {
            $this->setNextCheck($pet->id, $now->addMinutes(self::LOCKED_RECHECK_MINUTES));

            return false;
        }

        $child = $this->qualifyingChild($pet);
        if ($child === null) {
            // D1 not met (fewer than 5 of 7 days): decided — no reminder for this pet.
            $this->setNextCheck($pet->id, null);

            return false;
        }

        return $this->remind($pet->id, $child, $now);
    }

    /**
     * Marks the reminder ONCE (atomic under the row lock) and queues the one parent push (after
     * commit). Re-checks that the pet is still an upgradeable free pet.
     */
    public function remind(int $petId, ?int $childId, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();

        return DB::transaction(function () use ($petId, $childId, $now): bool {
            $locked = Pet::whereKey($petId)->lockForUpdate()->first();
            if ($locked === null || $locked->upgrade_reminder_sent_at !== null || ! $this->applies($locked)) {
                return false;
            }

            $locked->forceFill([
                'upgrade_reminder_sent_at' => $now,
                'upgrade_reminder_child_id' => $childId,
                'upgrade_reminder_next_check_at' => self::NEVER,
            ])->saveQuietly();

            Log::info('Upgrade reminder decided', ['pet_id' => $locked->id]);

            app(NotificationService::class)->upgradeReminder($locked);

            return true;
        });
    }

    private function locked(Pet $pet): bool
    {
        return (bool) $pet->is_hard_stopped || ! $pet->is_active || (bool) $pet->is_game_over || $pet->isIll();
    }

    private function setNextCheck(int $petId, ?CarbonImmutable $at): void
    {
        DB::table('pets')->where('id', $petId)->update([
            'upgrade_reminder_next_check_at' => $at?->format('Y-m-d H:i:s') ?? self::NEVER,
        ]);
    }
}
