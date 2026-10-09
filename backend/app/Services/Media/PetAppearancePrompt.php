<?php

namespace App\Services\Media;

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Models\Pet;
use InvalidArgumentException;

/**
 * Turns a breed + sampled traits into the image / video prompts (M4-08).
 *
 * Prompt = breed name + natural description of the traits + fixed photography
 * style. Built only from breed config and traits — never from a pet name,
 * child nickname or any other personal data.
 *
 * Species templates (M5-R06-07, CAT_SPEC §8): the species comes from the
 * breed (BreedType::species()). Dogs use the original constants below —
 * byte-identical (DogMediaPromptSnapshotTest); cats use the CAT_* templates,
 * which never contain the word "dog". Cats have DNA v2 only: the legacy v1
 * prompt path refuses a cat.
 */
class PetAppearancePrompt
{
    public const STYLE = 'Full body in frame, standing on all four paws, three-quarter side view at the dog\'s eye level. '
        .'Natural soft daylight, plain neutral home interior background, sharp focus, true-to-life fur texture and colours, '
        .'realistic 35mm photograph. Only the dog: no people, no children, no hands, no text, no watermark, no logo.';

    public const NEGATIVE = 'cartoon, illustration, drawing, 3d render, anime, painting, deformed anatomy, extra legs, '
        .'extra tail, missing legs, blurry, low quality, text, letters, watermark, logo, people, person, child, hands';

    /** Adopted dogs (M5-R01): neutral — no "sad shelter dog" clichés (David 2026-10-05). */
    public const ADOPTED_AVOID = 'Not sad, not scared, not thin, not injured, no cage, no kennel bars, no shelter background.';

    /** Stage growth edit (M5-R01): the same individual dog, only its age changes. */
    public const EDIT_KEEP = 'Keep it clearly the same individual dog: identical coat colours, coat pattern and markings, '
        .'identical eye colour and ear carriage, same breed type. Change only what ageing changes.';

    /** State videos (M4-03): the same dog, subtle realistic motion, nothing else changes. */
    public const VIDEO_STYLE = 'Keep the dog\'s exact appearance, size, coat colours and markings from the image; the same single dog throughout. '
        .'Static locked-off camera, no zoom, no pan, no cuts, same room and natural light. '
        .'Subtle, slow, realistic dog motion that could loop seamlessly. No people, no hands, no other animals, no text.';

    public const VIDEO_NEGATIVE = self::NEGATIVE.', camera movement, camera shake, zoom, pan, scene cut, morphing, '
        .'second dog, other animals, changing fur colour, changing markings, fast motion';

    // ── Cat templates (M5-R06-07, CAT_SPEC §8 — draft (D), verified in the AI Lab before cats switch on) ──

    /** Cats sit or stand naturally (a cat rarely poses "standing on all four paws" like a show dog). */
    public const CAT_STYLE = 'Full body in frame, sitting or standing naturally, three-quarter side view at the cat\'s eye level. '
        .'Natural soft daylight, plain neutral home interior background, sharp focus, true-to-life fur texture and colours, '
        .'realistic 35mm photograph. Only the cat: no people, no children, no hands, no text, no watermark, no logo.';

    /** Same as NEGATIVE (it has no species word) + the classic cat-anatomy failures. */
    public const CAT_NEGATIVE = self::NEGATIVE.', extra ears, missing whiskers';

    /** The cat is indoor-only in the game (CAT_SPEC Q7): "adopted" also covers a cat given by acquaintances (§1). */
    public const CAT_ADOPTED_AVOID = 'Not sad, not scared, not thin, not injured, no cage, no carrier, no shelter background.';

    public const CAT_EDIT_KEEP = 'Keep it clearly the same individual cat: identical coat colours, coat pattern and markings, '
        .'identical eye colour and ear shape, same breed type. Change only what ageing changes.';

    public const CAT_VIDEO_STYLE = 'Keep the cat\'s exact appearance, size, coat colours and markings from the image; the same single cat throughout. '
        .'Static locked-off camera, no zoom, no pan, no cuts, same room and natural light. '
        .'Subtle, slow, realistic cat motion that could loop seamlessly. No people, no hands, no other animals, no text.';

    public const CAT_VIDEO_NEGATIVE = self::CAT_NEGATIVE.', camera movement, camera shake, zoom, pan, scene cut, morphing, '
        .'second cat, other animals, changing fur colour, changing markings, fast motion';

    /**
     * @param  array<string, string>  $traits
     */
    public function imagePrompt(string $breedKey, array $traits): string
    {
        return 'A photorealistic photograph of a single '.$this->describe($breedKey, $traits).'. '.$this->style(self::speciesOf($breedKey));
    }

    /**
     * The species of a breed key (DNA `breed` / config key); an unknown key
     * keeps the original dog templates.
     */
    public static function speciesOf(string $breedKey): Species
    {
        return BreedType::tryFrom($breedKey)?->species() ?? Species::Dog;
    }

    /**
     * Reference image prompt for a pet at its current life stage and origin
     * (M5-R01): the DNA description (breed + traits — never a name or other
     * personal data) + stage cue + origin cue + photo style. DNA v1 pets
     * (no traits) use the breed name + the cues. The stored DNA prompt is
     * never rewritten.
     */
    public function imagePromptForPet(Pet $pet, ?LifeStage $stage = null): string
    {
        [$breedKey, $traits] = $this->dnaOf($pet);

        return $this->stagedImagePrompt($breedKey, $traits, $stage ?? $pet->life_stage, $this->originOf($pet));
    }

    /**
     * Reference image prompt from breed + traits + optional stage / origin
     * cues (imagePromptForPet; the AI Lab uses it to try a stage, M5-R06-07).
     * Empty traits (DNA v1 dogs) → the breed name only; a cat always needs traits.
     *
     * @param  array<string, string>  $traits
     */
    public function stagedImagePrompt(string $breedKey, array $traits, ?LifeStage $stage = null, ?PetOrigin $origin = null): string
    {
        $species = self::speciesOf($breedKey);
        $this->refuseCatWithoutTraits($species, $traits);
        $subject = $traits !== [] ? $this->describe($breedKey, $traits) : $this->displayName($breedKey);

        return trim(implode(' ', array_filter([
            'A photorealistic photograph of a single '.$subject.'.',
            $stage !== null ? $this->stageSentence($breedKey, $species, $stage) : null,
            $this->originSentence($origin, $species),
            $this->style($species),
        ])));
    }

    /**
     * DNA v1 pets (fixed prompt anchor, M4): the stored prompt + the stage /
     * origin cues (M5-R01). Without a stage and for a bought dog the stored
     * prompt is returned unchanged.
     */
    public function legacyPromptForPet(Pet $pet): string
    {
        // DNA v1 has dog prompts only (M5-R06-07): a cat never takes this path.
        if ($pet->speciesValue() !== Species::Dog) {
            throw new InvalidArgumentException('Legacy pet DNA v1 prompts are for dogs only.');
        }

        $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];
        $base = (string) ($dna['prompt'] ?? $dna['prompt_anchor'] ?? '');
        $stage = $pet->life_stage;
        $cues = array_filter([
            $stage !== null ? 'The dog is '.$stage->promptCue().'.' : null,
            $this->originSentence($this->originOf($pet)),
        ]);

        return $cues === [] ? $base : rtrim($base, '. ').'. '.implode(' ', $cues);
    }

    /**
     * Image-to-image prompt for the stage growth (M5-R01): the dog of the
     * reference image, now at the new stage, identity kept.
     */
    public function stageEditPrompt(Pet $pet, LifeStage $stage): string
    {
        [$breedKey, $traits] = $this->dnaOf($pet);
        $species = self::speciesOf($breedKey);
        $this->refuseCatWithoutTraits($species, $traits);
        $subject = $traits !== [] ? $this->describe($breedKey, $traits) : $this->displayName($breedKey);

        if ($species === Species::Cat) {
            return trim(implode(' ', array_filter([
                'The same cat as in the reference image ('.$subject.'), shown at a later age: now '.$stage->promptCue($species).'.',
                $this->stageNote($breedKey, $stage),
                self::CAT_EDIT_KEEP,
                $this->originOf($pet) === PetOrigin::Adopted ? self::CAT_ADOPTED_AVOID : null,
                self::CAT_STYLE,
            ])));
        }

        return trim(implode(' ', array_filter([
            'The same dog as in the reference image ('.$subject.'), shown at a later age: now '.$stage->promptCue().'.',
            self::EDIT_KEEP,
            $this->originOf($pet) === PetOrigin::Adopted ? self::ADOPTED_AVOID : null,
            self::STYLE,
        ])));
    }

    /**
     * @return array{0: string, 1: array<string, string>} breed key, DNA v2 traits (empty for v1)
     */
    private function dnaOf(Pet $pet): array
    {
        $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];
        $breedKey = (string) ($dna['breed'] ?? $pet->breed_type->value);
        $traits = (int) ($dna['version'] ?? 1) >= 2 && is_array($dna['traits'] ?? null) ? $dna['traits'] : [];

        return [$breedKey, $traits];
    }

    private function originOf(Pet $pet): ?PetOrigin
    {
        return $pet->origin instanceof PetOrigin ? $pet->origin : PetOrigin::tryFrom((string) $pet->origin);
    }

    private function originSentence(?PetOrigin $origin, Species $species = Species::Dog): ?string
    {
        $cue = $origin?->promptCue($species);

        if ($cue === null) {
            return null;
        }

        return $cue.'. '.($species === Species::Cat ? self::CAT_ADOPTED_AVOID : self::ADOPTED_AVOID);
    }

    /**
     * "The dog is <cue>." — for a cat "The cat is <cue>." plus the breed's
     * optional stage note (`stage_notes` in config/breed_appearance.php, e.g.
     * the Maine Coon kitten is already larger; the young Maine Coon is not
     * fully grown yet — CAT_SPEC §8).
     */
    private function stageSentence(string $breedKey, Species $species, LifeStage $stage): string
    {
        if ($species !== Species::Cat) {
            return 'The dog is '.$stage->promptCue().'.';
        }

        return trim('The cat is '.$stage->promptCue($species).'. '.($this->stageNote($breedKey, $stage) ?? ''));
    }

    private function stageNote(string $breedKey, LifeStage $stage): ?string
    {
        $note = config("breed_appearance.{$breedKey}.stage_notes.{$stage->value}");

        return is_string($note) && $note !== '' ? rtrim($note, '. ').'.' : null;
    }

    /**
     * A cat always has DNA v2 traits (PairingService never builds v1 DNA for
     * a cat): without them the prompt would be a bare breed name.
     *
     * @param  array<string, string>  $traits
     */
    private function refuseCatWithoutTraits(Species $species, array $traits): void
    {
        if ($species === Species::Cat && $traits === []) {
            throw new InvalidArgumentException('A cat needs DNA v2 traits; legacy DNA v1 is for dogs only.');
        }
    }

    private function style(Species $species): string
    {
        return $species === Species::Cat ? self::CAT_STYLE : self::STYLE;
    }

    private function displayName(string $breedKey): string
    {
        $noun = self::speciesOf($breedKey) === Species::Cat ? ' cat' : ' dog';

        return (string) config("breed_appearance.{$breedKey}.display_name", str_replace('_', ' ', $breedKey).$noun);
    }

    /**
     * "medium sturdy mixed-breed dog with a short coat in … and …" — breed + traits only.
     *
     * @param  array<string, string>  $traits
     */
    public function describe(string $breedKey, array $traits): string
    {
        $breed = (array) config("breed_appearance.{$breedKey}", []);
        $displayName = (string) ($breed['display_name'] ?? $this->displayName($breedKey));
        $order = (array) ($breed['prompt_order'] ?? array_keys($traits));

        $t = [];
        foreach ($order as $key) {
            if (isset($traits[$key]) && $traits[$key] !== '') {
                $t[$key] = $traits[$key];
            }
        }
        // Traits not mentioned in prompt_order still go into the prompt.
        $t += $traits;

        $subject = trim(implode(' ', array_filter([
            $t['size'] ?? null,
            $t['build'] ?? null,
            $displayName,
        ])));

        $parts = [];

        if (isset($t['coat_color'])) {
            $coat = 'a '.trim(($t['coat_length'] ?? '').' coat').' in '.$t['coat_color'];
            $pattern = $t['coat_pattern'] ?? null;
            $parts[] = $pattern === null || $pattern === 'solid' ? $coat.($pattern === 'solid' ? ' (solid colour)' : '') : $coat.' '.$pattern;
        }

        if (isset($t['markings']) && $t['markings'] !== 'no special markings') {
            $parts[] = $t['markings'];
        }

        if (isset($t['ear_carriage'])) {
            $parts[] = $t['ear_carriage'].' ears';
        }

        if (isset($t['eye_color'])) {
            $parts[] = str_starts_with($t['eye_color'], 'one ') ? $t['eye_color'].' eye' : $t['eye_color'].' eyes';
        }

        if (isset($t['tail'])) {
            $parts[] = 'a '.$t['tail'].' tail';
        }

        // Fixed breed features every pet of the breed has (M5-R06-07, e.g. the
        // Maine Coon's frill and lynx tufts — FIFe C16). Dog breeds have none.
        foreach ((array) ($breed['features'] ?? []) as $feature) {
            if (is_string($feature) && $feature !== '') {
                $parts[] = $feature;
            }
        }

        $known = ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'markings', 'ear_carriage', 'eye_color', 'tail'];
        foreach ($t as $key => $value) {
            if (! in_array($key, $known, true)) {
                $parts[] = str_replace('_', ' ', (string) $key).': '.$value;
            }
        }

        $description = match (count($parts)) {
            0 => '',
            1 => ' with '.$parts[0],
            default => ' with '.implode(', ', array_slice($parts, 0, -1)).' and '.end($parts),
        };

        return $subject.$description;
    }

    public function negativePrompt(Species $species = Species::Dog): string
    {
        return $species === Species::Cat ? self::CAT_NEGATIVE : self::NEGATIVE;
    }

    public function videoNegativePrompt(Species $species = Species::Dog): string
    {
        return $species === Species::Cat ? self::CAT_VIDEO_NEGATIVE : self::VIDEO_NEGATIVE;
    }

    /**
     * State video prompt from the pet's DNA (breed + traits) and the state —
     * the start image fixes the look, the text repeats it so the model keeps it.
     *
     * Cats (M5-R06-07): cat templates and cat state modifiers; a state the
     * species never has (a cat's `accident` / `chewing`, a dog's
     * `scratching`) throws InvalidArgumentException.
     *
     * @param  array<string, string>  $traits  DNA v2 traits; empty for v1 pets / unknown
     */
    public function videoPrompt(string $breedKey, PetStateEnum $state, array $traits = []): string
    {
        $species = self::speciesOf($breedKey);

        if ($species === Species::Cat) {
            $subject = $traits !== []
                ? $this->describe($breedKey, $traits)
                : (string) config("breed_appearance.{$breedKey}.display_name", 'cat');

            return 'The same '.$subject.' as in the image, '.$state->promptModifier($species).'. '.self::CAT_VIDEO_STYLE;
        }

        $subject = $traits !== []
            ? $this->describe($breedKey, $traits)
            : (string) config("breed_appearance.{$breedKey}.display_name", 'dog');

        return 'The same '.$subject.' as in the image, '.$state->promptModifier().'. '.self::VIDEO_STYLE;
    }
}
