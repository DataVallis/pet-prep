<?php

namespace App\Services\Media;

use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetStateEnum;
use App\Models\Pet;

/**
 * Turns a breed + sampled traits into the image / video prompts (M4-08).
 *
 * Prompt = breed name + natural description of the traits + fixed photography
 * style. Built only from breed config and traits — never from a pet name,
 * child nickname or any other personal data.
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

    /**
     * @param  array<string, string>  $traits
     */
    public function imagePrompt(string $breedKey, array $traits): string
    {
        return 'A photorealistic photograph of a single '.$this->describe($breedKey, $traits).'. '.self::STYLE;
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
        $stage ??= $pet->life_stage;
        $subject = $traits !== [] ? $this->describe($breedKey, $traits) : $this->displayName($breedKey);

        return trim(implode(' ', array_filter([
            'A photorealistic photograph of a single '.$subject.'.',
            $stage !== null ? 'The dog is '.$stage->promptCue().'.' : null,
            $this->originSentence($this->originOf($pet)),
            self::STYLE,
        ])));
    }

    /**
     * DNA v1 pets (fixed prompt anchor, M4): the stored prompt + the stage /
     * origin cues (M5-R01). Without a stage and for a bought dog the stored
     * prompt is returned unchanged.
     */
    public function legacyPromptForPet(Pet $pet): string
    {
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
        $subject = $traits !== [] ? $this->describe($breedKey, $traits) : $this->displayName($breedKey);

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

    private function originSentence(?PetOrigin $origin): ?string
    {
        $cue = $origin?->promptCue();

        return $cue === null ? null : $cue.'. '.self::ADOPTED_AVOID;
    }

    private function displayName(string $breedKey): string
    {
        return (string) config("breed_appearance.{$breedKey}.display_name", str_replace('_', ' ', $breedKey).' dog');
    }

    /**
     * "medium sturdy mixed-breed dog with a short coat in … and …" — breed + traits only.
     *
     * @param  array<string, string>  $traits
     */
    public function describe(string $breedKey, array $traits): string
    {
        $breed = (array) config("breed_appearance.{$breedKey}", []);
        $displayName = (string) ($breed['display_name'] ?? str_replace('_', ' ', $breedKey).' dog');
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

    public function negativePrompt(): string
    {
        return self::NEGATIVE;
    }

    public function videoNegativePrompt(): string
    {
        return self::VIDEO_NEGATIVE;
    }

    /**
     * State video prompt from the pet's DNA (breed + traits) and the state —
     * the start image fixes the look, the text repeats it so the model keeps it.
     *
     * @param  array<string, string>  $traits  DNA v2 traits; empty for v1 pets / unknown
     */
    public function videoPrompt(string $breedKey, PetStateEnum $state, array $traits = []): string
    {
        $subject = $traits !== []
            ? $this->describe($breedKey, $traits)
            : (string) config("breed_appearance.{$breedKey}.display_name", 'dog');

        return 'The same '.$subject.' as in the image, '.$state->promptModifier().'. '.self::VIDEO_STYLE;
    }
}
