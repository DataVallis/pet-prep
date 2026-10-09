<?php

namespace App\Services\Media;

use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\Species;
use App\Services\BreedCatalogService;
use App\Services\FalAiService;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Breed portraits for the website animal register (M5-R11, David 2026-10-10).
 *
 * One AI-generated illustration per register breed, in one consistent PetPrep
 * style, shown on /animals/<species>/<breed> labelled "AI-generated illustration".
 * Run by hand (`artisan breeds:portraits`) — never on a schedule, never for a pet.
 *
 * Prompt = the breed's typical ADULT look (config/breed_appearance.php through
 * PetAppearancePrompt::describe(): for every trait the option with the highest
 * weight, the first one on a tie, unless media.breed_portraits.traits names one)
 * + the adult stage cue + PORTRAIT_STYLE. Built from breed config only — no pet,
 * child or family data exists in this code path.
 *
 * fal.ai: FalGateway::run() with purpose = lab (AI Lab budget, never the
 * production budget; ledger rows like every AI Lab call). Never inside a DB
 * transaction (the gateway and the downloader refuse). The result is downloaded
 * by MediaDownloader (fal host allowlist, size, sniffed content type) and
 * stored as a square WebP (GD).
 *
 * Breeds = every BreedType except the free breed of its species (mutt, domestic
 * cat: a free pet's look is drawn from a pool, there is no one breed look). That
 * is the website register today; BreedPortraitTest checks it against the
 * committed export docs/research/breed-registry.json, whose SPECIES list in
 * scripts/export-breed-registry.mjs is the single definition of the register.
 */
class BreedPortraitService
{
    public const KIND = 'ai_illustration';

    public const LABEL = 'AI-generated illustration';

    public const MANIFEST = 'manifest.json';

    public const MANIFEST_SCHEMA = 1;

    /** Default output: the repo's docs/research/breed-portraits (relative to backend/). */
    public const DEFAULT_OUT = '../docs/research/breed-portraits';

    /**
     * Fixed style for every breed (brand/README.md CGP v2: fog background, graphite,
     * a little mint). `{animal}` = "dog" / "cat" — a cat prompt never says "dog".
     */
    public const PORTRAIT_STYLE = 'Full body in frame, standing naturally, three-quarter side view at the {animal}\'s eye level, '
        .'natural breed-typical proportions, centred in a square composition with even space around it. '
        .'Seamless soft studio background in light fog grey (#F3F5F2) with a soft graphite-grey (#121614) floor shadow '
        .'and a faint mint-green (#7FE0B4) glow low behind the {animal}; soft even studio light. '
        .'Clean photorealistic-illustrative style: detailed digital illustration with true-to-life fur texture and colours, '
        .'the same consistent style for every breed. '
        .'Only the {animal}: no people, no children, no hands, no other animals, no text, no letters, no logo, no watermark, '
        .'no collar, no name tag, no props.';

    public function __construct(
        private readonly PetAppearancePrompt $prompts,
        private readonly MediaProfiles $profiles,
        private readonly FalGateway $gateway,
        private readonly MediaDownloader $downloader,
        private readonly FalAiService $fal,
        private readonly BreedCatalogService $catalog,
        private readonly AiSpendGuard $guard,
    ) {}

    /**
     * Register breeds in enum order (dogs, then cats), optionally filtered.
     *
     * @param  list<string>  $only  breed keys (`border_collie`) or slugs (`border-collie`)
     * @return list<BreedType>
     */
    public function breeds(array $only = [], ?Species $species = null): array
    {
        $register = array_values(array_filter(
            BreedType::cases(),
            fn (BreedType $b) => $b !== $this->catalog->freeBreedFor($b->species())
                && is_array(config("breed_appearance.{$b->value}.traits")),
        ));

        $picked = [];
        foreach ($only as $key) {
            $breed = BreedType::tryFrom($key) ?? BreedType::fromSlug($key);
            if ($breed === null || ! in_array($breed, $register, true)) {
                throw new InvalidArgumentException("Breed \"{$key}\" is not in the animal register (known: "
                    .implode(', ', array_map(fn (BreedType $b) => $b->value, $register)).').');
            }
            $picked[] = $breed;
        }

        $list = $only === [] ? $register : array_values(array_filter($register, fn (BreedType $b) => in_array($b, $picked, true)));

        return array_values(array_filter($list, fn (BreedType $b) => $species === null || $b->species() === $species));
    }

    /**
     * The typical adult look: for every trait (config order) the option with the
     * highest weight among those allowed by earlier picks (`only_with`), the first
     * one on a tie; `media.breed_portraits.traits.<breed>` overrides (must be an
     * allowed option). Deterministic — no seed, no randomness.
     *
     * @return array<string, string>
     */
    public function portraitTraits(BreedType $breed): array
    {
        $traits = (array) config("breed_appearance.{$breed->value}.traits", []);
        $overrides = (array) config("media.breed_portraits.traits.{$breed->value}", []);

        foreach (array_keys($overrides) as $key) {
            if (! array_key_exists($key, $traits)) {
                throw new InvalidArgumentException("media.breed_portraits.traits.{$breed->value}: unknown trait \"{$key}\".");
            }
        }

        $picked = [];
        foreach ($traits as $key => $options) {
            $allowed = [];
            foreach ((array) $options as $option) {
                $value = is_array($option) ? (string) $option['value'] : (string) $option;
                $weight = is_array($option) ? (int) ($option['weight'] ?? 1) : 1;
                $onlyWith = is_array($option) ? (array) ($option['only_with'] ?? []) : [];

                $ok = true;
                foreach ($onlyWith as $other => $values) {
                    if (! in_array($picked[$other] ?? null, (array) $values, true)) {
                        $ok = false;
                    }
                }
                if ($ok) {
                    $allowed[] = ['value' => $value, 'weight' => $weight];
                }
            }

            if ($allowed === []) {
                continue;
            }

            if (array_key_exists($key, $overrides)) {
                $wanted = (string) $overrides[$key];
                if (! in_array($wanted, array_column($allowed, 'value'), true)) {
                    throw new InvalidArgumentException("media.breed_portraits.traits.{$breed->value}.{$key}: \"{$wanted}\" is not an allowed option.");
                }
                $picked[$key] = $wanted;

                continue;
            }

            $best = $allowed[0];
            foreach ($allowed as $candidate) {
                if ($candidate['weight'] > $best['weight']) {
                    $best = $candidate;
                }
            }
            $picked[$key] = $best['value'];
        }

        return $picked;
    }

    public function prompt(BreedType $breed): string
    {
        $species = $breed->species();
        $animal = $species === Species::Cat ? 'cat' : 'dog';
        $subject = $this->prompts->describe($breed->value, $this->portraitTraits($breed), LifeStage::Adult);

        return 'A photorealistic-illustrative portrait of a single '.$subject.'. '
            .'The '.$animal.' is '.LifeStage::Adult->promptCue($species).'. '
            .str_replace('{animal}', $animal, self::PORTRAIT_STYLE);
    }

    public static function promptHash(string $prompt): string
    {
        return 'sha256:'.hash('sha256', $prompt);
    }

    /** Seed per breed (stable across runs and machines) where the model takes one. */
    public static function seedFor(BreedType $breed): int
    {
        return (int) (crc32('breed-portrait:'.$breed->value) & 0x7FFFFFFF);
    }

    /**
     * The image profile: the configured reference-image profile unless one is named.
     * It must be enabled and take an `aspect_ratio` (the portrait asks for 1:1).
     */
    public function profile(?string $key = null): ModelProfile
    {
        $profile = $key === null || $key === '' ? $this->profiles->referenceImage() : $this->profiles->image($key);

        if (! $profile->enabled) {
            throw new InvalidArgumentException("Image profile {$profile->key} is disabled.");
        }

        if (config("media.profiles.image.{$profile->key}.image_param") !== null) {
            throw new InvalidArgumentException("Image profile {$profile->key} edits an input image — portraits need a text-to-image profile.");
        }

        if (! array_key_exists('aspect_ratio', $profile->params)) {
            throw new InvalidArgumentException("Image profile {$profile->key} has no aspect_ratio parameter — portraits need a square (1:1) image.");
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function input(ModelProfile $profile, BreedType $breed): array
    {
        $input = $profile->imageInput($this->prompt($breed), self::seedFor($breed));
        $input['aspect_ratio'] = '1:1';
        $input['num_images'] = 1;

        return $input;
    }

    /** "dog/border-collie.webp" — relative to the output directory (manifest `file`). */
    public static function relativeFile(BreedType $breed, string $extension = 'webp'): string
    {
        return $breed->species()->value.'/'.$breed->slug().'.'.$extension;
    }

    /**
     * What a run would do per breed: `generate` (no file), `skip` (file + manifest
     * entry with the same prompt), `stale` (exists, but the prompt changed — only
     * --force regenerates), `force` (exists, --force).
     *
     * @param  list<BreedType>  $breeds
     * @return list<array{breed: BreedType, file: string, prompt: string, prompt_hash: string, action: string}>
     */
    public function plan(array $breeds, string $outDir, bool $force): array
    {
        $manifest = $this->readManifest($outDir);
        $rows = [];

        foreach ($breeds as $breed) {
            $prompt = $this->prompt($breed);
            $hash = self::promptHash($prompt);
            $entry = $manifest[$breed->value] ?? null;
            $file = is_array($entry) && is_string($entry['file'] ?? null) ? $entry['file'] : self::relativeFile($breed);
            $exists = is_file($outDir.'/'.$file);

            $action = match (true) {
                ! $exists => 'generate',
                $force => 'force',
                ($entry['prompt_hash'] ?? null) !== $hash => 'stale',
                default => 'skip',
            };

            $rows[] = ['breed' => $breed, 'file' => $file, 'prompt' => $prompt, 'prompt_hash' => $hash, 'action' => $action];
        }

        return $rows;
    }

    /** Estimated USD for $calls calls of $profile (list price; the fal invoice is the truth). */
    public function estimate(ModelProfile $profile, int $calls): float
    {
        return round($profile->estimatedCostUsd() * $calls, 4);
    }

    /** Lab budget still free today / this month (estimated USD). */
    public function labBudgetLeft(): array
    {
        return [
            'today' => round(max(0.0, $this->guard->labDailyCapUsd() - $this->guard->spentTodayUsd(true)), 4),
            'month' => round(max(0.0, $this->guard->labMonthlyCapUsd() - $this->guard->spentThisMonthUsd(true)), 4),
        ];
    }

    /**
     * Generate one portrait: fal (lab budget) → download → square WebP → file +
     * manifest entry. Throws AiCallException (budget, balance, HTTP) or
     * RuntimeException / MediaDownloadException (bad answer, bad file).
     *
     * @return array<string, mixed> the manifest entry
     */
    public function generate(BreedType $breed, ModelProfile $profile, string $outDir): array
    {
        $prompt = $this->prompt($breed);
        $call = $this->gateway->run($profile, $this->input($profile, $breed), AiSpendPurpose::Lab, timeoutSeconds: 120);

        $url = $call['body']['images'][0]['url'] ?? null;
        if (! is_string($url) || ! $this->fal->isAllowedMediaUrl($url)) {
            throw new RuntimeException('No usable image URL in the fal.ai answer (cost kept: $'.number_format($call['cost_usd'], 4).').');
        }

        $download = $this->downloader->download(
            $url,
            (int) config('media.storage.max_image_bytes', 25 * 1024 * 1024),
            (array) config('media.storage.image_mimes', ['image/jpeg', 'image/png', 'image/webp']),
        );

        try {
            $image = $this->toSquareWebp((string) file_get_contents($download['path']), $download['mime']);
        } finally {
            @unlink($download['path']);
        }

        $file = self::relativeFile($breed, $image['extension']);
        $this->writeAtomically($outDir.'/'.$file, $image['bytes']);

        // An earlier portrait in another format (no WebP support then) is replaced.
        $old = $this->readManifest($outDir)[$breed->value]['file'] ?? null;
        if (is_string($old) && $old !== $file && is_file($outDir.'/'.$old)) {
            @unlink($outDir.'/'.$old);
        }

        $entry = [
            'breed' => $breed->value,
            'species' => $breed->species()->value,
            'file' => $file,
            'width' => $image['width'],
            'height' => $image['height'],
            'kind' => self::KIND,
            'profile' => $profile->key,
            'prompt_hash' => self::promptHash($prompt),
            'generated_at' => now()->utc()->toIso8601String(),
            'cost_usd' => round($call['cost_usd'], 4),
        ];

        $this->writeManifestEntry($outDir, $entry);

        return $entry;
    }

    /**
     * Square centre crop, longest side ≤ media.breed_portraits.max_size (never
     * upscaled), WebP. Without GD WebP support the original bytes are kept
     * (format and size as fal sent them).
     *
     * @return array{bytes: string, width: int, height: int, extension: string}
     */
    public function toSquareWebp(string $bytes, string $mime): array
    {
        $source = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;

        if ($source === false) {
            throw new RuntimeException("The downloaded image ({$mime}) could not be decoded.");
        }

        $w = imagesx($source);
        $h = imagesy($source);

        if (! function_exists('imagewebp')) {
            return ['bytes' => $bytes, 'width' => $w, 'height' => $h, 'extension' => MediaDownloader::extensionFor($mime)];
        }

        $side = min($w, $h);
        $target = min($side, max(1, (int) config('media.breed_portraits.max_size', 1200)));
        $square = imagecreatetruecolor($target, $target);
        imagecopyresampled($square, $source, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $target, $target, $side, $side);

        ob_start();
        $ok = imagewebp($square, null, (int) config('media.breed_portraits.webp_quality', 88));
        $webp = (string) ob_get_clean();

        if (! $ok || $webp === '') {
            throw new RuntimeException('WebP encoding failed.');
        }

        return ['bytes' => $webp, 'width' => $target, 'height' => $target, 'extension' => 'webp'];
    }

    /**
     * Manifest entries keyed by breed.
     *
     * @return array<string, array<string, mixed>>
     */
    public function readManifest(string $outDir): array
    {
        $path = $outDir.'/'.self::MANIFEST;
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['portraits'] ?? null)) {
            throw new RuntimeException("{$path} is not a breed portrait manifest.");
        }

        $out = [];
        foreach ($data['portraits'] as $entry) {
            if (is_array($entry) && is_string($entry['breed'] ?? null)) {
                $out[$entry['breed']] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function writeManifestEntry(string $outDir, array $entry): void
    {
        $entries = $this->readManifest($outDir);
        $entries[$entry['breed']] = $entry;

        // Stable order: enum order (species, then the register order), unknown keys last.
        $order = array_flip(array_map(fn (BreedType $b) => $b->value, BreedType::cases()));
        uksort($entries, fn ($a, $b) => [($order[$a] ?? PHP_INT_MAX), $a] <=> [($order[$b] ?? PHP_INT_MAX), $b]);

        $manifest = [
            'schema_version' => self::MANIFEST_SCHEMA,
            'generator' => 'backend: php artisan breeds:portraits (M5-R11)',
            'label' => self::LABEL,
            'note' => 'AI-generated breed illustrations for the website animal register. Show them only with the label "'
                .self::LABEL.'". Prompts come from breed config only (config/breed_appearance.php) — no pet or child data.',
            'portraits' => array_values($entries),
        ];

        $this->writeAtomically(
            $outDir.'/'.self::MANIFEST,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
        );
    }

    private function writeAtomically(string $path, string $bytes): void
    {
        File::ensureDirectoryExists(dirname($path));
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $bytes) === false || ! rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException("Could not write {$path}.");
        }
    }
}
