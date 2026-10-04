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

    public const VIDEO_STYLE = 'Keep the dog\'s exact appearance, coat colours and markings from the image. '
        .'Static camera, natural light, realistic natural motion, seamless loop. No people, no text.';

    /**
     * @param  array<string, string>  $traits
     */
    public function imagePrompt(string $breedKey, array $traits): string
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

        return 'A photorealistic photograph of a single '.$subject.$description.'. '.self::STYLE;
    }

    public function negativePrompt(): string
    {
        return self::NEGATIVE;
    }

    public function videoPrompt(string $breedKey, PetStateEnum $state): string
    {
        $displayName = (string) config("breed_appearance.{$breedKey}.display_name", 'dog');

        return 'The same '.$displayName.' as in the image, '.$state->promptModifier().'. '.self::VIDEO_STYLE;
    }
}
