<?php

namespace App\Services\Media;

use App\Enums\BreedType;
use App\Models\Pet;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Pet DNA v2 (M4-08, David 2026-10-04: "every dog unique like in real life").
 *
 * A seed (pet id + random salt) drives a deterministic sample of one option per
 * trait from config/breed_appearance.php. The same seed, fixed traits and
 * excluded combinations always give the same traits. Within a family no two
 * v2 pets of the same breed share the same trait combination (the caller holds
 * the family row lock — PairingService::pairChild — so concurrent pairings
 * cannot pick the same combination).
 *
 * Existing pets keep their v1 DNA; nothing is rewritten.
 */
class PetDnaService
{
    public const VERSION = 2;

    /** Attempts to find a combination that is not used in the family yet. */
    public const MAX_ATTEMPTS = 25;

    public function __construct(private readonly PetAppearancePrompt $prompts) {}

    /**
     * DNA v2 for a pet that already has an id and a family.
     *
     * @return array<string, mixed>
     */
    public function forNewPet(Pet $pet, ?int $salt = null): array
    {
        $breed = $pet->breed_type instanceof BreedType ? $pet->breed_type : BreedType::from((string) $pet->breed_type);
        $salt ??= random_int(1, PHP_INT_MAX);
        $seed = self::seedFor($pet->id, $salt);

        $taken = Pet::query()
            ->where('family_id', $pet->family_id)
            ->where('breed_type', $breed->value)
            ->whereKeyNot($pet->id)
            ->whereRaw("pet_dna->>'version' = ?", [(string) self::VERSION])
            ->pluck('pet_dna')
            ->map(fn ($dna) => is_array($dna) ? ($dna['trait_fingerprint'] ?? null) : null)
            ->filter()
            ->values()
            ->all();

        $sample = $this->sample($breed->value, $seed, [], $taken);

        return $this->dnaFrom($breed->value, $seed, $sample) + ['salt' => $salt];
    }

    /**
     * @param  array{traits: array<string, string>, fingerprint: string, attempt: int}  $sample
     * @return array<string, mixed>
     */
    public function dnaFrom(string $breedKey, int $seed, array $sample): array
    {
        $prompt = $this->prompts->imagePrompt($breedKey, $sample['traits']);

        return [
            'version' => self::VERSION,
            'seed' => $seed,
            'attempt' => $sample['attempt'],
            'breed' => $breedKey,
            'traits' => $sample['traits'],
            'trait_fingerprint' => $sample['fingerprint'],
            'appearance_verified' => (bool) config("breed_appearance.{$breedKey}.verified", false),
            'prompt' => $prompt,
            'negative_prompt' => $this->prompts->negativePrompt(),
            // v1-compatible keys read by GeneratePetReferenceImage, FalAiService and the API resources.
            'prompt_anchor' => $prompt,
            'visual_traits' => $sample['traits'],
            'reference_image_url' => null,
        ];
    }

    /**
     * Deterministic trait sample.
     *
     * @param  array<string, string>  $fixed  trait => value the result must have (AI Lab)
     * @param  list<string>  $excludeFingerprints  combinations already used (family / run)
     * @return array{traits: array<string, string>, fingerprint: string, attempt: int}
     */
    public function sample(string $breedKey, int $seed, array $fixed = [], array $excludeFingerprints = []): array
    {
        $traitConfig = $this->traitConfig($breedKey);
        $this->assertValidFixed($breedKey, $traitConfig, $fixed);

        $exclude = array_flip($excludeFingerprints);
        $result = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $random = new Randomizer(new Xoshiro256StarStar(hash('sha256', "petprep-dna:{$breedKey}:{$seed}:{$attempt}", true)));
            $traits = [];

            foreach ($traitConfig as $trait => $options) {
                if (isset($fixed[$trait])) {
                    $traits[$trait] = $fixed[$trait];

                    continue;
                }

                $traits[$trait] = $this->pick($random, $this->allowedOptions($options, $traits));
            }

            $result = ['traits' => $traits, 'fingerprint' => self::fingerprint($breedKey, $traits), 'attempt' => $attempt];

            if (! isset($exclude[$result['fingerprint']])) {
                return $result;
            }
        }

        Log::warning('PetDnaService: no unused trait combination found, reusing one', ['breed' => $breedKey]);

        return $result;
    }

    /**
     * @return array<string, list<string>> trait => option values
     */
    public function optionsFor(string $breedKey): array
    {
        $options = [];

        foreach ($this->traitConfig($breedKey) as $trait => $list) {
            $options[$trait] = array_map(fn (array $o) => $o['value'], $list);
        }

        return $options;
    }

    /**
     * M5-R06-01: the breed has appearance data (config/breed_appearance.php).
     * Cats have none until M5-R06-07 — they get no DNA and no AI media.
     */
    public static function hasAppearance(string $breedKey): bool
    {
        $traits = config("breed_appearance.{$breedKey}.traits");

        return is_array($traits) && $traits !== [];
    }

    /**
     * @return list<string> breed keys with appearance data
     */
    public function breeds(): array
    {
        return array_values(array_filter(
            array_keys((array) config('breed_appearance', [])),
            fn ($key) => BreedType::tryFrom((string) $key) !== null,
        ));
    }

    public static function seedFor(int $petId, int $salt): int
    {
        // 32-bit unsigned: accepted as `seed` by every fal image model.
        return (int) sprintf('%u', crc32("petprep-pet:{$petId}:{$salt}"));
    }

    /**
     * @param  array<string, string>  $traits
     */
    public static function fingerprint(string $breedKey, array $traits): string
    {
        ksort($traits);

        return hash('sha256', $breedKey.'|'.json_encode($traits));
    }

    /**
     * @return array<string, list<array{value: string, weight: int, only_with: array<string, list<string>>}>>
     */
    private function traitConfig(string $breedKey): array
    {
        $traits = config("breed_appearance.{$breedKey}.traits");

        if (! is_array($traits) || $traits === []) {
            throw new InvalidArgumentException("No appearance data for breed {$breedKey}.");
        }

        $normalised = [];

        foreach ($traits as $trait => $options) {
            $normalised[(string) $trait] = array_map(fn ($o) => is_array($o)
                ? ['value' => (string) $o['value'], 'weight' => max(1, (int) ($o['weight'] ?? 1)), 'only_with' => (array) ($o['only_with'] ?? [])]
                : ['value' => (string) $o, 'weight' => 1, 'only_with' => []], array_values((array) $options));
        }

        return $normalised;
    }

    /**
     * @param  list<array{value: string, weight: int, only_with: array<string, list<string>>}>  $options
     * @param  array<string, string>  $chosen
     * @return list<array{value: string, weight: int, only_with: array<string, list<string>>}>
     */
    private function allowedOptions(array $options, array $chosen): array
    {
        $allowed = array_values(array_filter($options, function (array $o) use ($chosen) {
            foreach ($o['only_with'] as $trait => $values) {
                if (! in_array($chosen[$trait] ?? null, (array) $values, true)) {
                    return false;
                }
            }

            return true;
        }));

        // A fixed (lab) value may rule everything out: fall back to unconditional options.
        if ($allowed !== []) {
            return $allowed;
        }

        $unconditional = array_values(array_filter($options, fn ($o) => $o['only_with'] === []));

        return $unconditional !== [] ? $unconditional : $options;
    }

    /**
     * @param  list<array{value: string, weight: int, only_with: array<string, list<string>>}>  $options
     */
    private function pick(Randomizer $random, array $options): string
    {
        $total = array_sum(array_column($options, 'weight'));
        $roll = $random->getInt(1, $total);

        foreach ($options as $option) {
            $roll -= $option['weight'];

            if ($roll <= 0) {
                return $option['value'];
            }
        }

        return $options[array_key_last($options)]['value'];
    }

    /**
     * @param  array<string, list<array{value: string, weight: int, only_with: array<string, list<string>>}>>  $traitConfig
     * @param  array<string, string>  $fixed
     */
    private function assertValidFixed(string $breedKey, array $traitConfig, array $fixed): void
    {
        foreach ($fixed as $trait => $value) {
            $values = array_column($traitConfig[$trait] ?? [], 'value');

            if (! in_array($value, $values, true)) {
                throw new InvalidArgumentException("Trait {$trait} = '{$value}' is not an option for {$breedKey}.");
            }
        }
    }
}
