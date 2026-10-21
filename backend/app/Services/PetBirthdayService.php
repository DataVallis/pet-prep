<?php

namespace App\Services;

use App\Events\PetUpdated;
use App\Models\Pet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The pet's first birthday (M5-T13, APP_REFINEMENT_SPEC §9, David 2026-10-20).
 *
 * The birthday is the moment the pet REACHES 1 year (12 months) by the
 * simulation's age formula (LifeStageService::ageMonthsAt — arrival age + one
 * month per completed program week; payment-lock time is not program time,
 * M3-11b). Once per pet — never monthly or weekly.
 *
 *  - Which pets: born, with a profile (`arrival_age_months` set) that arrived
 *    younger than 12 months. A pet that arrives at 12 months or older is
 *    already 1 and never has a first birthday; a legacy-profile pet has no age.
 *  - The date: the family-local date of the instant the pet completes
 *    (12 − arrival) program weeks. While a payment lock is open the date is
 *    computed as if the clock resumed now (like LifeStageService::nextTransition),
 *    so it only ever moves later.
 *  - Due from the family-local start (00:00) of that date and shown that whole
 *    day (`is_today`). Not while the pet waits for payment (the date is not fixed then).
 *  - The tick (`pets:process-decay`) marks it ONCE (`pets.birthday_celebrated_at`,
 *    atomic update): one PetUpdated `pet_birthday` + one push to the caretaker
 *    children and the parents (held over quiet hours — midnight is usually
 *    inside the night window). A pet locked by a hard stop / illness that day is
 *    looked at again every few minutes; a day that passes entirely locked has no
 *    celebration. Inactive / game-over pets never celebrate.
 *  - `pets.birthday_next_check_at` keeps the minutely tick cheap: start of the
 *    birthday date, or "never" once celebrated / passed / not applicable.
 *
 * No new AI media here: the birthday clip is future work (M5-T08+); the apps
 * draw the party hat over the existing media.
 */
class PetBirthdayService
{
    /** PetUpdated event type / push type of the birthday. */
    public const EVENT = 'pet_birthday';

    /** The birthday is the age of 12 months (1 year). */
    public const AGE_MONTHS = 12;

    /** Pets looked at per tick (the rest wait for the next minute). */
    public const MAX_PETS_PER_TICK = 200;

    /** A pet that is locked (hard stop, illness) on its birthday is looked at again this much later. */
    public const LOCKED_RECHECK_MINUTES = 5;

    /** `birthday_next_check_at` of a pet with nothing left to celebrate. */
    public const NEVER = '2999-12-31 00:00:00';

    public function __construct(
        private readonly LifeStageService $lifeStages,
    ) {}

    /** True when this pet will (or did) reach 1 year inside the simulation. */
    public function applies(Pet $pet): bool
    {
        return $pet->born_at !== null
            && ! $pet->isLegacyProfile()
            && (int) $pet->arrival_age_months < self::AGE_MONTHS;
    }

    /**
     * The real instant the pet completes (12 − arrival) program weeks — the
     * same instant LifeStageService::weeksSinceBirth() counts that week at:
     * the anniversary of the effective birth (`born_at` shifted by the payment
     * locks that started before it). An open lock counts until $now (the clock
     * is assumed to resume now). Null when no first birthday applies.
     */
    public function birthdayAt(Pet $pet, CarbonInterface $now): ?CarbonImmutable
    {
        if (! $this->applies($pet)) {
            return null;
        }

        $weeks = self::AGE_MONTHS - (int) $pet->arrival_age_months;
        $tz = $pet->familyTimezone();
        $born = CarbonImmutable::instance($pet->born_at)->utc();
        $nowTs = $now->getTimestamp();
        $spans = $pet->paymentLockSpans();

        // Fixed point: the paused time before the anniversary moves the anniversary,
        // which may bring another lock before it. Grows monotonically, ≤ one round per lock.
        $paused = 0;
        for ($round = 0; $round <= count($spans); $round++) {
            $at = $this->lifeStages->anniversary($born->addSeconds($paused)->setTimezone($tz), $weeks, $tz);
            $next = 0;
            foreach ($spans as [$start, $end]) {
                if ($start < $at->getTimestamp()) {
                    $next += ($end ?? max($nowTs, $start)) - $start;
                }
            }
            if ($next === $paused) {
                return $at->utc();
            }
            $paused = $next;
        }

        return $this->lifeStages->anniversary($born->addSeconds($paused)->setTimezone($tz), $weeks, $tz)->utc();
    }

    /** The family-local birthday date (Y-m-d), or null when no first birthday applies. */
    public function birthdayDate(Pet $pet, CarbonInterface $now): ?string
    {
        $at = $this->birthdayAt($pet, $now);

        return $at === null ? null : $pet->localDate($at);
    }

    /** The `birthday` object of the child state, the parent dashboard and PetUpdated. */
    public function summary(Pet $pet, ?CarbonInterface $now = null): ?BirthdayPayload
    {
        $now ??= now();
        $date = $this->birthdayDate($pet, $now);
        if ($date === null) {
            return null;
        }

        return new BirthdayPayload(
            date: $date,
            isToday: $date === $pet->localDate($now) && ! $pet->isPaymentLocked(),
            celebrated: $pet->birthday_celebrated_at !== null,
        );
    }

    /**
     * The tick: celebrate every pet whose birthday is today (once), schedule the
     * next look at the others.
     *
     * @return array{pets: int, celebrated: int}
     */
    public function processDue(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();

        $pets = Pet::born()
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->whereNotNull('arrival_age_months')
            ->where('arrival_age_months', '<', self::AGE_MONTHS)
            ->whereNull('birthday_celebrated_at')
            ->where(fn ($q) => $q->whereNull('birthday_next_check_at')->orWhere('birthday_next_check_at', '<=', $now))
            ->orderByRaw('birthday_next_check_at ASC NULLS FIRST')
            ->orderBy('id')
            ->limit(self::MAX_PETS_PER_TICK)
            ->with(['family', 'paymentLockPeriods'])
            ->get();

        $celebrated = 0;
        foreach ($pets as $pet) {
            try {
                $celebrated += $this->processPet($pet, $now) ? 1 : 0;
            } catch (Throwable $e) {
                // One pet never stops the tick; it is retried a few minutes later.
                Log::error('PetBirthdayService: birthday check failed', ['pet_id' => $pet->id, 'error' => $e->getMessage()]);
                report($e);
                $this->setNextCheck($pet->id, $now->addMinutes(self::LOCKED_RECHECK_MINUTES));
            }
        }

        return ['pets' => $pets->count(), 'celebrated' => $celebrated];
    }

    private function processPet(Pet $pet, CarbonImmutable $now): bool
    {
        $date = $this->birthdayDate($pet, $now);
        if ($date === null) {
            $this->setNextCheck($pet->id, null);

            return false;
        }

        $today = $pet->localDate($now);
        if ($date < $today) {
            // Passed (born before this feature, or the whole day was locked).
            $this->setNextCheck($pet->id, null);

            return false;
        }
        if ($date > $today) {
            $this->setNextCheck($pet->id, CarbonImmutable::parse($date, $pet->familyTimezone())->startOfDay()->utc());

            return false;
        }

        if ($this->locked($pet)) {
            $this->setNextCheck($pet->id, $now->addMinutes(self::LOCKED_RECHECK_MINUTES));

            return false;
        }

        return $this->celebrate($pet->id, $now);
    }

    /**
     * Marks the birthday ONCE (atomic update under the row lock): one PetUpdated
     * `pet_birthday` and one push (children + parents), both after commit.
     */
    public function celebrate(int $petId, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();

        return DB::transaction(function () use ($petId, $now): bool {
            $locked = Pet::whereKey($petId)->lockForUpdate()->first();
            if ($locked === null || $locked->birthday_celebrated_at !== null) {
                return false;
            }

            $locked->forceFill([
                'birthday_celebrated_at' => $now,
                'birthday_next_check_at' => self::NEVER,
            ])->saveQuietly();

            Log::info('Pet birthday celebrated', ['pet_id' => $locked->id]);

            PetUpdated::afterCommit($locked, self::EVENT);
            app(NotificationService::class)->petBirthday($locked);

            return true;
        });
    }

    private function locked(Pet $pet): bool
    {
        return (bool) $pet->is_hard_stopped || ! $pet->is_active || (bool) $pet->is_game_over
            || $pet->isIll() || $pet->isPaymentLocked();
    }

    private function setNextCheck(int $petId, ?CarbonImmutable $at): void
    {
        DB::table('pets')->where('id', $petId)->update([
            'birthday_next_check_at' => $at?->format('Y-m-d H:i:s') ?? self::NEVER,
        ]);
    }
}
