<?php

namespace App\Services\Media;

use App\Enums\BreedType;
use App\Models\Pet;
use App\Models\PetLook;
use App\Services\AppSettingsService;

/**
 * Shared AI media for paid breeds (M5-R21, David 2026-10-14).
 *
 * While the admin switch "unique AI media per pet" (AppSettingsService,
 * default OFF) is off, a new profiled pet of a paid breed does not get its own
 * DNA: it gets the ONE shared look of its breed + coat colour (`pet_looks`
 * row with `colour_key`). The look's media (image per life stage, state
 * videos) are generated once — by the first pet that needs them — and every
 * later pet of that breed + colour links the stored files (PetMediaService,
 * "Shared looks"; same machinery as the free pool, M4-10). No fal call for
 * the second family.
 *
 * Concurrency: the look is created with insert-or-ignore on the unique
 * (breed_type, pool_index) index (pool_index = the colour's position in the
 * breed's list) and the partial unique (breed_type, colour_key) — two
 * families buying the same breed + colour at once end up with one look; the
 * media generation is claimed per look row, the other pet waits for it.
 *
 * A paid breed whose config/breed_colours.php entry is missing still shares:
 * the colour comes from its breed_appearance options (derived keys, no
 * picker) — fail safe toward shared, never an accidental unique generation.
 *
 * Switch ON → unique DNA per pet (with the chosen colour fixed) as before.
 * Free breeds keep the pool (PetLookPoolService); legacy-profile pets (no
 * arrival age) keep a unique DNA. Existing pets never change.
 */
class SharedBreedLookService
{
    public function __construct(
        private readonly PetDnaService $dna,
        private readonly PetLookPoolService $pool,
        private readonly BreedColourCatalogue $colours,
        private readonly AppSettingsService $settings,
    ) {}

    public function appliesTo(BreedType $breed, ?int $arrivalAgeMonths): bool
    {
        return ! $this->settings->uniquePetMedia()
            && $arrivalAgeMonths !== null
            && $breed->isPremium()
            && PetDnaService::hasAppearance($breed->value);
        // Deliberately NOT gated on a breed_colours.php entry: a paid breed
        // without a picker list still shares (colour sampled from its
        // appearance, BreedColourCatalogue::lookColours) — never an accidental
        // unique, paid generation per pet.
    }

    /**
     * Give the pet the shared look of its breed + colour ($colourKey; null →
     * a colour sampled by the breed's weights, like a unique pet). The pet must
     * already have an id and a family. Never rewrites an existing DNA.
     */
    public function assignTo(Pet $pet, ?string $colourKey): PetLook
    {
        $breed = $pet->breed_type instanceof BreedType ? $pet->breed_type : BreedType::from((string) $pet->breed_type);
        $colour = $this->colourIn($breed, $colourKey) ?? $this->sampledColour($breed);

        $look = $this->lookFor($breed, $colour['key']);

        $pet->forceFill([
            'pet_dna' => $this->pool->dnaFor($look),
            'pet_look_id' => $look->id,
            // A derived key (breed without a picker list) has no label → no pet colour.
            'coat_colour' => BreedColourCatalogue::isDerivedKey($colour['key']) ? null : $colour['key'],
        ])->saveQuietly();

        return $look;
    }

    /**
     * The shared look of (breed, colour) — created on first use.
     */
    public function lookFor(BreedType $breed, string $colourKey): PetLook
    {
        $existing = $this->find($breed, $colourKey);
        if ($existing !== null) {
            return $existing;
        }

        $colour = $this->colourIn($breed, $colourKey);
        $index = $this->positionIn($breed, $colourKey);
        if ($colour === null || $index === null) {
            throw new \InvalidArgumentException("Unknown colour {$colourKey} for {$breed->value}.");
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $salt = random_int(1, PHP_INT_MAX);
            $seed = PetDnaService::seedFor($index, $salt);
            // Never a trait combination another look of the breed already has
            // (unique breed + fingerprint index).
            $taken = PetLook::query()->where('breed_type', $breed->value)->pluck('trait_fingerprint')->all();
            $sample = $this->dna->sample($breed->value, $seed, ['coat_color' => $colour['value']], $taken);
            $dna = $this->dna->dnaFrom($breed->value, $seed, $sample) + ['salt' => $salt];
            unset($dna['reference_image_url']);

            PetLook::query()->insertOrIgnore([
                'breed_type' => $breed->value,
                'pool_index' => $index,
                'colour_key' => $colourKey,
                'trait_fingerprint' => $sample['fingerprint'],
                'dna' => json_encode($dna),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Ours, or the concurrent winner's (unique breed + colour): one look either way.
            $look = $this->find($breed, $colourKey);
            if ($look !== null) {
                return $look;
            }

            // The index was taken by another look of the breed (e.g. the colour list
            // was reordered since): take the next free one.
            $index = (int) PetLook::query()->where('breed_type', $breed->value)->max('pool_index') + 1;
        }

        throw new \RuntimeException("The shared look of {$breed->value} / {$colourKey} could not be created.");
    }

    public function find(BreedType $breed, string $colourKey): ?PetLook
    {
        return PetLook::query()->where('breed_type', $breed->value)->where('colour_key', $colourKey)->first();
    }

    /**
     * A colour by the breed's appearance weights (what an old app build without
     * a colour gets — the same distribution as a unique pet).
     *
     * @return array{key: string, value: string, en: string, sl: string}
     */
    private function sampledColour(BreedType $breed): array
    {
        $sample = $this->dna->sample($breed->value, random_int(1, PHP_INT_MAX));
        $value = $sample['traits']['coat_color'] ?? null;
        $colours = $this->colours->lookColours($breed);
        foreach ($colours as $colour) {
            if ($colour['value'] === $value) {
                return $colour;
            }
        }
        if ($colours === []) {
            throw new \InvalidArgumentException("{$breed->value} has no coat colours.");
        }

        return $colours[0];
    }

    /**
     * @return array{key: string, value: string, en: string, sl: string}|null
     */
    private function colourIn(BreedType $breed, ?string $key): ?array
    {
        foreach ($this->colours->lookColours($breed) as $colour) {
            if ($key !== null && $colour['key'] === $key) {
                return $colour;
            }
        }

        return null;
    }

    /** 1-based position of the colour in the breed's look colours (the look's pool index). */
    private function positionIn(BreedType $breed, string $key): ?int
    {
        foreach ($this->colours->lookColours($breed) as $i => $colour) {
            if ($colour['key'] === $key) {
                return $i + 1;
            }
        }

        return null;
    }
}
