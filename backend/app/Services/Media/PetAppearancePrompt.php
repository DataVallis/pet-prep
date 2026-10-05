<?php

namespace App\Services\Media;

use App\Enums\PetStateEnum;

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
