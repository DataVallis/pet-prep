<?php

namespace App\Services\Results;

use App\Enums\BreedType;
use App\Enums\ClientFeature;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;

/**
 * The parent's choice for a NEW pet (M5-R01, David 2026-10-05): breed,
 * origin (bought | adopted) and age stage at arrival (puppy | young |
 * adult | senior). Omitted fields keep the pre-M5 behaviour: a bought mutt
 * puppy. Stored on the child PIN (`child_login_pins.pet_options`) until the
 * PIN creates the pet.
 *
 * `features` (M5-R02, PR #42 B1): what the parent's app build can show
 * (ClientFeature values); unknown values are dropped. An old PIN without
 * the key → [] (behaviour events off).
 */
final readonly class PetProfileChoice
{
    /**
     * @param  list<string>  $features
     */
    public function __construct(
        public BreedType $breed = BreedType::Mutt,
        public PetOrigin $origin = PetOrigin::Bought,
        public LifeStage $ageStage = LifeStage::Puppy,
        public array $features = [],
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
        $features = array_values(array_unique(array_filter(
            is_array($data['features'] ?? null) ? $data['features'] : [],
            fn (mixed $f): bool => is_string($f) && ClientFeature::tryFrom($f) !== null,
        )));

        return new self(
            BreedType::tryFrom((string) ($data['breed'] ?? '')) ?? BreedType::Mutt,
            PetOrigin::tryFrom((string) ($data['origin'] ?? '')) ?? PetOrigin::Bought,
            LifeStage::tryFrom((string) ($data['age_stage'] ?? '')) ?? LifeStage::Puppy,
            $features,
        );
    }

    public function supports(ClientFeature $feature): bool
    {
        return in_array($feature->value, $this->features, true);
    }

    /**
     * @return array{breed: string, origin: string, age_stage: string, features: list<string>}
     */
    public function toArray(): array
    {
        return [
            'breed' => $this->breed->value,
            'origin' => $this->origin->value,
            'age_stage' => $this->ageStage->value,
            'features' => $this->features,
        ];
    }
}
