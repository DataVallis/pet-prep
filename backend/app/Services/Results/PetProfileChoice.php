<?php

namespace App\Services\Results;

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;

/**
 * The parent's choice for a NEW pet (M5-R01, David 2026-10-05): breed,
 * origin (bought | adopted) and age stage at arrival (puppy | young |
 * adult | senior). Omitted fields keep the pre-M5 behaviour: a bought mutt
 * puppy. Stored on the child PIN (`child_login_pins.pet_options`) until the
 * PIN creates the pet.
 */
final readonly class PetProfileChoice
{
    public function __construct(
        public BreedType $breed = BreedType::Mutt,
        public PetOrigin $origin = PetOrigin::Bought,
        public LifeStage $ageStage = LifeStage::Puppy,
    ) {}

    public static function default(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        return new self(
            BreedType::tryFrom((string) ($data['breed'] ?? '')) ?? BreedType::Mutt,
            PetOrigin::tryFrom((string) ($data['origin'] ?? '')) ?? PetOrigin::Bought,
            LifeStage::tryFrom((string) ($data['age_stage'] ?? '')) ?? LifeStage::Puppy,
        );
    }

    /**
     * @return array{breed: string, origin: string, age_stage: string}
     */
    public function toArray(): array
    {
        return [
            'breed' => $this->breed->value,
            'origin' => $this->origin->value,
            'age_stage' => $this->ageStage->value,
        ];
    }
}
