<?php

namespace App\Services\Media;

use InvalidArgumentException;

/**
 * One named fal.ai model configuration from config/media.php (M4-02).
 */
final class ModelProfile
{
    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    /**
     * @param  array{unit: string, usd: float, usd_additional?: float}  $pricing
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $label,
        public readonly string $endpoint,
        public readonly bool $enabled,
        public readonly bool $lab,
        public readonly bool $verified,
        public readonly ?string $source,
        public readonly array $pricing,
        public readonly array $params,
        public readonly bool $supportsNegativePrompt,
        public readonly bool $supportsSeed,
        public readonly int $width,
        public readonly int $height,
        public readonly int $durationSeconds,
        public readonly string $imageParam,
        /** Video: clip lengths (s) the fal schema accepts; [] = only $durationSeconds. */
        public readonly array $allowedDurations = [],
        /** Video (M5-T08): input name of the END frame (Kling 3.0 Pro `end_image_url`); null = not supported. */
        public readonly ?string $endImageParam = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $kind, string $key, array $config): self
    {
        $endpoint = (string) ($config['endpoint'] ?? '');

        // The endpoint becomes part of a URL path: only fal-style ids ("owner/model/variant").
        if (preg_match('#^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)+$#i', $endpoint) !== 1) {
            throw new InvalidArgumentException("Media profile {$kind}.{$key}: invalid endpoint id.");
        }

        $pricing = (array) ($config['pricing'] ?? []);
        $unit = (string) ($pricing['unit'] ?? '');

        if (! in_array($unit, ['image', 'megapixel', 'second'], true) || ! is_numeric($pricing['usd'] ?? null)) {
            throw new InvalidArgumentException("Media profile {$kind}.{$key}: pricing needs unit image|megapixel|second and usd.");
        }

        if ((float) $pricing['usd'] < 0 || (isset($pricing['usd_additional']) && (! is_numeric($pricing['usd_additional']) || (float) $pricing['usd_additional'] < 0))) {
            throw new InvalidArgumentException("Media profile {$kind}.{$key}: prices must not be negative.");
        }

        if ($kind === self::KIND_VIDEO && $unit !== 'second') {
            throw new InvalidArgumentException("Media profile {$kind}.{$key}: video pricing must be per second.");
        }

        return new self(
            key: $key,
            kind: $kind,
            label: (string) ($config['label'] ?? $key),
            endpoint: $endpoint,
            enabled: (bool) ($config['enabled'] ?? false),
            lab: (bool) ($config['lab'] ?? true),
            verified: (bool) ($config['verified'] ?? false),
            source: isset($config['source']) ? (string) $config['source'] : null,
            pricing: [
                'unit' => $unit,
                'usd' => (float) $pricing['usd'],
                'usd_additional' => (float) ($pricing['usd_additional'] ?? $pricing['usd']),
            ],
            params: (array) ($config['params'] ?? []),
            supportsNegativePrompt: (bool) ($config['supports_negative_prompt'] ?? false),
            supportsSeed: (bool) ($config['supports_seed'] ?? false),
            width: (int) ($config['width'] ?? 1024),
            height: (int) ($config['height'] ?? 1024),
            durationSeconds: (int) ($config['duration_seconds'] ?? 0),
            imageParam: (string) ($config['image_param'] ?? 'image_url'),
            allowedDurations: array_values(array_map('intval', (array) ($config['durations'] ?? []))),
            endImageParam: isset($config['end_image_param']) && $config['end_image_param'] !== '' ? (string) $config['end_image_param'] : null,
        );
    }

    /** M5-T08: the model takes an end frame (seamless loops, transitions between two poses). */
    public function supportsEndImage(): bool
    {
        return $this->kind === self::KIND_VIDEO && $this->endImageParam !== null;
    }

    /**
     * Clip lengths (seconds) this video profile may be asked for — the values
     * of the model's fal `duration` enum (config `durations`), or just the
     * profile default when the list is not configured.
     *
     * @return list<int>
     */
    public function supportedDurations(): array
    {
        $durations = $this->allowedDurations !== [] ? $this->allowedDurations : [$this->durationSeconds];
        $durations = array_values(array_unique(array_filter($durations, fn (int $s) => $s > 0)));
        sort($durations);

        return $durations;
    }

    public function supportsDuration(int $seconds): bool
    {
        return in_array($seconds, $this->supportedDurations(), true);
    }

    /**
     * A copy of this video profile with another clip length (AI Lab override):
     * the `duration` param keeps the model's format ("5" for Kling, "4s" for
     * Veo) and the cost estimate / ledger units follow the new length.
     *
     * @throws InvalidArgumentException when the model does not accept that length
     */
    public function withDuration(int $seconds): self
    {
        if ($this->kind !== self::KIND_VIDEO || ! $this->supportsDuration($seconds)) {
            throw new InvalidArgumentException(sprintf(
                '%s does not support %d s clips (allowed: %s s).',
                $this->label,
                $seconds,
                implode(', ', $this->supportedDurations()),
            ));
        }

        $params = $this->params;
        $current = (string) ($params['duration'] ?? '');
        $params['duration'] = str_ends_with($current, 's') ? $seconds.'s' : (string) $seconds;

        return new self(
            key: $this->key,
            kind: $this->kind,
            label: $this->label,
            endpoint: $this->endpoint,
            enabled: $this->enabled,
            lab: $this->lab,
            verified: $this->verified,
            source: $this->source,
            pricing: $this->pricing,
            params: $params,
            supportsNegativePrompt: $this->supportsNegativePrompt,
            supportsSeed: $this->supportsSeed,
            width: $this->width,
            height: $this->height,
            durationSeconds: $seconds,
            imageParam: $this->imageParam,
            allowedDurations: $this->allowedDurations,
            endImageParam: $this->endImageParam,
        );
    }

    public function unit(): string
    {
        return $this->pricing['unit'];
    }

    /**
     * Billable units of ONE call: 1 image, started megapixels (fal rounds up), or seconds.
     */
    public function units(): float
    {
        return match ($this->unit()) {
            'image' => 1.0,
            'megapixel' => (float) max(1, (int) ceil(($this->width * $this->height) / 1_000_000)),
            'second' => (float) max(1, $this->durationSeconds),
        };
    }

    /**
     * Estimated USD for ONE call (list price; the fal invoice is the truth).
     */
    public function estimatedCostUsd(): float
    {
        $units = $this->units();

        $cost = match ($this->unit()) {
            'megapixel' => $this->pricing['usd'] + max(0, $units - 1) * $this->pricing['usd_additional'],
            default => $units * $this->pricing['usd'],
        };

        return round($cost, 4);
    }

    /**
     * Input for an image model: prompt (+ seed, + negative prompt where the model supports them) + profile params.
     *
     * @return array<string, mixed>
     */
    public function imageInput(string $prompt, ?int $seed = null, ?string $negativePrompt = null): array
    {
        $input = ['prompt' => $prompt];

        if ($this->supportsSeed && $seed !== null) {
            $input['seed'] = $seed;
        }

        $input += $this->params;

        if ($this->supportsNegativePrompt && filled($negativePrompt)) {
            $input['negative_prompt'] = $negativePrompt;
        }

        return $input;
    }

    /**
     * Input for an image EDIT model (M5-R01 stage growth): prompt + source
     * image URL(s) under the profile's image param (`image_urls` list for
     * Nano Banana Pro Edit) + seed + profile params.
     *
     * @return array<string, mixed>
     */
    public function editInput(string $prompt, string $imageUrl, ?int $seed = null): array
    {
        $input = [
            'prompt' => $prompt,
            $this->imageParam => str_ends_with($this->imageParam, 's') ? [$imageUrl] : $imageUrl,
        ];

        if ($this->supportsSeed && $seed !== null) {
            $input['seed'] = $seed;
        }

        return $input + $this->params;
    }

    /**
     * Input for an image-to-video model.
     *
     * @return array<string, mixed>
     */
    public function videoInput(string $imageUrl, string $prompt, ?string $negativePrompt = null, ?string $endImageUrl = null): array
    {
        $input = [$this->imageParam => $imageUrl, 'prompt' => $prompt] + $this->params;

        // M5-T08: end frame (loop = the start image again; transition = the target pose).
        if ($endImageUrl !== null && $this->supportsEndImage()) {
            $input[(string) $this->endImageParam] = $endImageUrl;
        }

        if ($this->supportsNegativePrompt && filled($negativePrompt)) {
            $input['negative_prompt'] = $negativePrompt;
        }

        return $input;
    }
}
