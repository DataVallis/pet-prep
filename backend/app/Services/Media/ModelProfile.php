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
     * Input for an image-to-video model.
     *
     * @return array<string, mixed>
     */
    public function videoInput(string $imageUrl, string $prompt, ?string $negativePrompt = null): array
    {
        $input = [$this->imageParam => $imageUrl, 'prompt' => $prompt] + $this->params;

        if ($this->supportsNegativePrompt && filled($negativePrompt)) {
            $input['negative_prompt'] = $negativePrompt;
        }

        return $input;
    }
}
