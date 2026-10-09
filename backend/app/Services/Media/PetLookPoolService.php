<?php

namespace App\Services\Media;

use App\Enums\BreedType;
use App\Models\Pet;
use App\Models\PetLook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Shared appearance pool for free pets (M4-10, David 2026-10-09).
 *
 * Every free breed (breed_configs.premium_unlock = false — today the mutt
 * and the domestic cat) has a pool of up to `media.look_pool.size` (20)
 * looks. A look = a DNA v2 trait combination ({@see PetDnaService::sample()})
 * plus its media, stored once on the look and reused by every pet of the
 * look (PetMediaService, "Shared looks"). The pool fills lazily: while it
 * has fewer looks than its size, a new free pet creates the next look;
 * afterwards new pets pick an existing one.
 *
 * Family rule: a family never gets a look it already shows while the pool
 * has one it does not use (random among the unused ones; once every look is
 * used in the family, any look). "Used" = any pet of the family of that
 * breed with the same trait fingerprint (pool pets and older unique pets).
 *
 * Paid breeds, legacy-profile pets (no profile → no life stage, pre-M5
 * rules) and DNA v1 keep their unique DNA (PetDnaService::forNewPet).
 * Called inside the pairing transaction that holds the family row lock
 * (PairingService::createPet); the pool itself is guarded by the unique
 * (breed_type, pool_index) index, so two families racing for the last free
 * index cannot overfill it.
 */
class PetLookPoolService
{
    /** Attempts to create a look before falling back to an existing one (index / fingerprint race). */
    private const CREATE_ATTEMPTS = 3;

    public function __construct(private readonly PetDnaService $dna) {}

    public function enabled(): bool
    {
        return (bool) config('media.look_pool.enabled', true);
    }

    public function size(): int
    {
        return max(1, (int) config('media.look_pool.size', 20));
    }

    /**
     * A new pet of this breed takes its look from the pool: a free breed with
     * appearance data and a profile (arrival age → life stages).
     */
    public function appliesTo(BreedType $breed, ?int $arrivalAgeMonths): bool
    {
        return $this->enabled()
            && $arrivalAgeMonths !== null
            && ! $breed->isPremium()
            && PetDnaService::hasAppearance($breed->value);
    }

    /**
     * Pick (or create) the look of a new pet and give the pet its DNA. The
     * pet must already have an id and a family. Never rewrites the DNA of a
     * pet that has one.
     */
    public function assignTo(Pet $pet): PetLook
    {
        $look = $this->pickFor($pet);

        $pet->forceFill(['pet_dna' => $this->dnaFor($look), 'pet_look_id' => $look->id])->saveQuietly();

        return $look;
    }

    public function pickFor(Pet $pet): PetLook
    {
        $breed = $pet->breed_type instanceof BreedType ? $pet->breed_type : BreedType::from((string) $pet->breed_type);
        $family = $this->familyFingerprints($pet, $breed);

        for ($attempt = 0; $attempt < self::CREATE_ATTEMPTS; $attempt++) {
            $looks = $this->looksOf($breed);

            if ($looks->count() >= $this->size()) {
                break;
            }

            $created = $this->createLook($breed, $looks, $family);

            if ($created !== null) {
                return $created;
            }
        }

        $looks = $this->looksOf($breed);

        if ($looks->isEmpty()) {
            // Only possible when the trait space is exhausted before the first look — never with real data.
            throw new \RuntimeException("The look pool of {$breed->value} could not be filled.");
        }

        $unused = $looks->reject(fn (PetLook $look) => isset($family[$look->trait_fingerprint]));

        return ($unused->isNotEmpty() ? $unused : $looks)->random();
    }

    /**
     * The pet's DNA for a look: the look's DNA v2 payload + the look id.
     *
     * @return array<string, mixed>
     */
    public function dnaFor(PetLook $look): array
    {
        return array_merge($look->dna, ['look_id' => $look->id, 'reference_image_url' => null]);
    }

    /**
     * Number of looks per breed (Filament, AI Lab cost line).
     *
     * @return array<string, int> breed value => looks
     */
    public function counts(): array
    {
        return PetLook::query()
            ->selectRaw('breed_type, count(*) as n')
            ->groupBy('breed_type')
            ->pluck('n', 'breed_type')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @return Collection<int, PetLook>
     */
    private function looksOf(BreedType $breed): Collection
    {
        return PetLook::query()->where('breed_type', $breed->value)->orderBy('pool_index')->get();
    }

    /**
     * @param  Collection<int, PetLook>  $looks
     * @param  array<string, true>  $family
     */
    private function createLook(BreedType $breed, Collection $looks, array $family): ?PetLook
    {
        $index = (int) ($looks->max('pool_index') ?? 0) + 1;

        if ($index > $this->size()) {
            return null;
        }

        $pool = $looks->pluck('trait_fingerprint')->all();
        $salt = random_int(1, PHP_INT_MAX);
        $seed = PetDnaService::seedFor($index, $salt);
        $sample = $this->dna->sample($breed->value, $seed, [], array_merge($pool, array_keys($family)));

        if (in_array($sample['fingerprint'], $pool, true)) {
            // PetDnaService::sample() found no unused combination: never two equal looks.
            Log::warning('PetLookPoolService: no new trait combination for the pool', ['breed' => $breed->value, 'looks' => count($pool)]);

            return null;
        }

        $dna = $this->dna->dnaFrom($breed->value, $seed, $sample) + ['salt' => $salt];
        unset($dna['reference_image_url']);

        $inserted = PetLook::query()->insertOrIgnore([
            'breed_type' => $breed->value,
            'pool_index' => $index,
            'trait_fingerprint' => $sample['fingerprint'],
            'dna' => json_encode($dna),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted !== 1) {
            return null; // another pairing took this index / combination first
        }

        return PetLook::query()->where('breed_type', $breed->value)->where('pool_index', $index)->firstOrFail();
    }

    /**
     * Trait fingerprints the family already shows for this breed.
     *
     * @return array<string, true>
     */
    private function familyFingerprints(Pet $pet, BreedType $breed): array
    {
        return Pet::query()
            ->where('family_id', $pet->family_id)
            ->where('breed_type', $breed->value)
            ->whereKeyNot($pet->id)
            ->whereRaw("pet_dna->>'version' = ?", [(string) PetDnaService::VERSION])
            ->pluck('pet_dna')
            ->map(fn ($dna) => is_array($dna) ? ($dna['trait_fingerprint'] ?? null) : null)
            ->filter()
            ->mapWithKeys(fn (string $fp) => [$fp => true])
            ->all();
    }
}
