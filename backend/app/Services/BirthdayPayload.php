<?php

namespace App\Services;

use App\Models\Pet;
use Carbon\CarbonInterface;

/**
 * The `birthday` object of a pet in the child state, the parent dashboard and
 * PetUpdated (M5-T13, APP_REFINEMENT_SPEC §9). Typed for Scramble. Null when the
 * pet has no first birthday inside the simulation (unborn, legacy profile, or
 * it arrived 12 months or older).
 */
final class BirthdayPayload
{
    public function __construct(
        public readonly string $date,
        public readonly bool $isToday,
        public readonly bool $celebrated,
    ) {}

    public static function for(Pet $pet, ?CarbonInterface $now = null): ?self
    {
        return app(PetBirthdayService::class)->summary($pet, $now);
    }

    /**
     * @return array{date: string, is_today: bool, celebrated: bool}|null
     */
    public static function arrayFor(Pet $pet, ?CarbonInterface $now = null): ?array
    {
        return self::for($pet, $now)?->toArray();
    }

    /**
     * @return array{date: string, is_today: bool, celebrated: bool}
     */
    public function toArray(): array
    {
        return [
            // The family-local date (Y-m-d) the pet turns 1 by the simulation's age formula
            // (arrival age + one month per program week; payment-lock time does not count, so
            // the date moves later while the pet waits for payment).
            'date' => $this->date,
            // Today (family-local) is the birthday: show the party hat, the safe treat and the
            // tip the whole day. False while the pet waits for payment.
            'is_today' => $this->isToday,
            // The server has celebrated it (PetUpdated `pet_birthday` + one push) — once per pet.
            'celebrated' => $this->celebrated,
        ];
    }
}
