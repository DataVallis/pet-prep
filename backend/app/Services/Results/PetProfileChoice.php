<?php

namespace App\Services\Results;

use App\Enums\BreedType;
use App\Enums\ClientFeature;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\Species;

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
 *
 * `species` (M5-R06-01): dog | cat; the breed always belongs to it (the
 * request refuses a mismatch with 422 `breed_species_mismatch`). A PIN
 * stored before M5-R06-01 has no `species` → the breed's species (a dog). No
 * breed → the free breed of the species (mutt / domestic cat).
 *
 * `colour` (M5-R21, David 2026-10-14): the coat colour key the parent picked
 * for a paid breed (config/breed_colours.php); null = no choice (old app
 * builds) → the colour is sampled by the breed's weights. A PIN stored before
 * M5-R21 has no key → null.
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
        public Species $species = Species::Dog,
        public ?string $colour = null,
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

        $breed = BreedType::tryFrom((string) ($data['breed'] ?? ''));
        $species = Species::tryFrom((string) ($data['species'] ?? '')) ?? $breed?->species() ?? Species::Dog;
        $breed ??= $species->freeBreed();
        $origin = PetOrigin::tryFrom((string) ($data['origin'] ?? '')) ?? PetOrigin::Bought;
        // M5-F09 (David 2026-10-12): a non-pedigree free breed (mutt / domestic cat) is never
        // bought from a breeder — it is adopted. Here, not in the request, so a PIN stored
        // before this rule (read back at pin-login) and old builds get the same answer.
        if ($origin === PetOrigin::Bought && ! $breed->isPremium()) {
            $origin = PetOrigin::Adopted;
        }

        return new self(
            $breed,
            $origin,
            LifeStage::tryFrom((string) ($data['age_stage'] ?? '')) ?? LifeStage::Puppy,
            $features,
            $species,
            is_string($data['colour'] ?? null) && $data['colour'] !== '' ? $data['colour'] : null,
        );
    }

    /**
     * The same choice keeping only features also in $features (the child
     * device's, at pin-login — M5-R02, PR #42).
     *
     * @param  list<string>  $features
     */
    public function withOnlyFeatures(array $features): self
    {
        return new self($this->breed, $this->origin, $this->ageStage, array_values(array_intersect($this->features, $features)), $this->species, $this->colour);
    }

    public function supports(ClientFeature $feature): bool
    {
        return in_array($feature->value, $this->features, true);
    }

    /**
     * @return array{breed: string, origin: string, age_stage: string, features: list<string>, species: string, colour: string|null}
     */
    public function toArray(): array
    {
        return [
            'breed' => $this->breed->value,
            'origin' => $this->origin->value,
            'age_stage' => $this->ageStage->value,
            'features' => $this->features,
            // M5-R06-01 (additive, last key).
            'species' => $this->species->value,
            // M5-R21 (additive, last key).
            'colour' => $this->colour,
        ];
    }
}
