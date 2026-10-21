<?php

namespace App\Services;

use App\Models\Pet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The `sleep` object of a pet (M5-T02) in the child state and PetUpdated:
 * `asleep` = the pet sleeps now ({@see PetSleepService}: the family's quiet
 * hours), `until` = when it wakes (ISO 8601 in the family timezone; null while
 * awake). The apps disable play and school with "💤 … spi do 07:00". Pet
 * facts only — no personal data.
 */
final class SleepPayload
{
    public function __construct(
        public readonly bool $asleep,
        public readonly ?string $until,
    ) {}

    public static function for(Pet $pet, ?CarbonInterface $now = null): self
    {
        $now = CarbonImmutable::instance($now ?? now());
        $sleep = app(PetSleepService::class);
        $quiet = $pet->quietHours();
        $wakes = $sleep->asleepAt($pet, $now, $quiet) ? $sleep->wakesAt($pet, $now, $quiet) : null;

        return new self(
            $wakes !== null,
            $wakes?->setTimezone($pet->familyTimezone())->toIso8601String(),
        );
    }

    /**
     * @return array{asleep: bool, until: string|null}
     */
    public function toArray(): array
    {
        return [
            // The pet sleeps now (family quiet hours): play and school are off.
            'asleep' => $this->asleep,
            // When it wakes (end of the quiet stretch, family offset); null while awake.
            'until' => $this->until,
        ];
    }
}
