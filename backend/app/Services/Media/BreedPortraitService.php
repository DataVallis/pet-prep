<?php

namespace App\Services\Media;

use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\Species;
use App\Services\BreedCatalogService;
use App\Services\FalAiService;
use finfo;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Breed portraits for the website animal register (M5-R11, David 2026-10-10).
 *
 * One AI-generated photo per register breed (a realistic studio photograph, like
 * the app's pets — David 2026-10-10), in one consistent PetPrep style, shown on
 * /animals/<species>/<breed> labelled "AI-generated photo".
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
 *
 * Colour variants (`--colours`, David 2026-10-26: the website breed page will show
 * the breed in every standard coat colour): for every colour of
 * config/breed_colours.php except the one the default portrait already shows, an
 * EDIT of that portrait (image-to-image, media.breed_portraits.colour_profile =
 * Nano Banana Pro Edit, the same model family as the key images) that changes only
 * the coat colour / pattern. The reference portrait is sent as a base64 data URI
 * (it is a local file, not on a public host). Output
 * `<species>/<slug>--<colour-key>.webp`, manifest list `colour_variants`. Same
 * AI Lab budget, same square WebP pipeline. `--colour=<key>` (repeatable) limits a
 * run to those colours; COLOUR_HINTS make dilute / brown / sable colours read right.
 */
class BreedPortraitService
{
    public const KIND = 'ai_photo';

    public const LABEL = 'AI-generated photo';

    public const MANIFEST = 'manifest.json';

    public const MANIFEST_SCHEMA = 1;

    /** Separator between the breed slug and the colour key in a colour variant's file name. */
    public const COLOUR_SEPARATOR = '--';

    /**
     * Edit prompt for a colour variant (`{breed}` = display name, `{animal}` =
     * dog / cat, `{colour}` = breed_colours `value`). Only the coat colour and
     * pattern change — the same animal, pose, framing, light and backdrop as the
     * default portrait (PORTRAIT_STYLE).
     */
    public const COLOUR_EDIT_PROMPT = 'Edit this photograph of a {breed}: change ONLY the coat colour and pattern to {colour}, '
        .'a standard {breed} colour, with the colour distribution natural for this breed in that colour. '
        .'{colour_note}Keep everything else exactly the same: the same {animal}, the same pose, body shape, proportions, coat length and fur texture, '
        .'ear carriage, head position and expression, the same framing, crop and camera angle, the same soft diffused neutral studio light '
        .'and the same seamless light fog-grey (#F3F5F2) studio backdrop with its soft natural floor shadow, no coloured glow or coloured light. '
        .'Realistic 35mm photograph, sharp focus on the {animal}, true-to-life fur texture and colours. '
        .'Only the {animal}: no people, no children, no hands, no other animals, no text, no letters, no logo, no watermark, '
        .'no collar, no name tag, no props.';

    /**
     * Per-colour hints for the edit prompt (David 2026-10-26: the first blue-and-white
     * Border Collie came out almost black). Matched on the lower-cased breed_colours
     * `value`; every matching hint is added, in this order, as `{colour_note}` of
     * COLOUR_EDIT_PROMPT. A colour without a match keeps its prompt (and prompt hash)
     * byte-identical, so its committed variant does not become stale.
     *  - `blue merle` is a merle pattern (black patches on a blue-grey base), not dilute;
     *  - `blue` elsewhere = the dilute of black — except merle / roan / speckled / ticked
     *    blues (a mix of black and white hairs, e.g. Cocker blue roan, Cattle Dog blue), whose
     *    `value` already says what they look like;
     *  - `lilac` / `lavender` = dilute of chocolate; `liver` / `chocolate` = solid brown;
     *  - `sable` = banded hairs with black tips.
     *
     * @var list<array{match: string, except?: string, hint: string}>
     */
    public const COLOUR_HINTS = [
        ['match' => '/\bblue merle\b/', 'hint' => 'Blue merle is a merle pattern: a light silvery blue-grey base coat marbled with irregular black patches; the blue-grey base must be clearly visible, not a mostly black coat.'],
        ['match' => '/\bblue\b(?! merle)/', 'except' => '/\b(merle|roan|roaned|speckled|ticked)\b/', 'hint' => 'Blue here is the dilute of black: the blue parts are a clearly slate blue-grey, visibly lighter and bluer than black, not black and not charcoal.'],
        ['match' => '/\b(lilac|lavender)\b/', 'hint' => 'Lilac is the dilute of chocolate: the lilac parts are a pale warm dove grey with a soft pinkish-brown tint, lighter than blue-grey, not brown and not cold grey.'],
        ['match' => '/\b(liver|chocolate)\b/', 'hint' => 'Liver / chocolate is a true brown: the brown parts are a rich chocolate brown with a brown nose, not black and not red or ginger.'],
        ['match' => '/\bsable\b/', 'hint' => 'Sable means banded hairs: a golden to red-tan base coat with darker black-tipped hairs shading the back, shoulders and head, not a solid colour and not black and tan.'],
    ];

    /** Default output: the repo's docs/research/breed-portraits (relative to backend/). */
    public const DEFAULT_OUT = '../docs/research/breed-portraits';

    /**
     * Fixed style for every breed: a realistic studio photograph, the same
     * photographic language as the app's pets (PetAppearancePrompt: "A photorealistic
     * photograph … realistic 35mm photograph"), on the brand fog backdrop
     * (brand/README.md CGP v2). David 2026-10-10: photos, not illustrations; no
     * coloured glow (looks artificial in a photo). `{animal}` = "dog" / "cat" — a
     * cat prompt never says "dog".
     */
    public const PORTRAIT_STYLE = 'Full body in frame, standing naturally, three-quarter side view at the {animal}\'s eye level, '
        .'natural breed-typical proportions, centred in a square composition with even space around it. '
        .'Seamless light fog-grey (#F3F5F2) studio backdrop with a soft natural floor shadow; soft diffused neutral studio light, no coloured glow or coloured light. '
        .'Realistic 35mm photograph, sharp focus on the {animal}, true-to-life fur texture and colours, shallow depth of field, '
        .'the same consistent photographic style for every breed. '
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

        return 'A photorealistic photograph of a single '.$subject.'. '
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
            // The breed_colours key the portrait shows (the colour variants skip it).
            'colour' => $this->colourKeyForValue($breed, $this->portraitTraits($breed)['coat_color'] ?? null),
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

    // ──────────────────────────────────────────────────────────────
    //  Colour variants (`--colours`, David 2026-10-26)
    // ──────────────────────────────────────────────────────────────

    /**
     * The breed's standard coat colours (config/breed_colours.php, picker order).
     *
     * @return list<array{key: string, value: string, en: string, sl: string}>
     */
    public function colours(BreedType $breed): array
    {
        return array_values(array_filter(
            (array) config("breed_colours.{$breed->value}", []),
            fn ($c) => is_array($c) && is_string($c['key'] ?? null) && is_string($c['value'] ?? null),
        ));
    }

    /** The breed_colours key whose `value` is $coatColor, or null. */
    public function colourKeyForValue(BreedType $breed, ?string $coatColor): ?string
    {
        foreach ($this->colours($breed) as $colour) {
            if ($colour['value'] === $coatColor) {
                return $colour['key'];
            }
        }

        return null;
    }

    /**
     * The colour the default portrait already shows: the manifest entry's
     * `colour` (written since 2026-10-26); else the coat colour of the portrait
     * prompt (portraitTraits — what the portrait was generated from); else the
     * breed's first (default) colour. Null when the breed has no colour list.
     *
     * @param  array<string, mixed>|null  $portraitEntry  the reference manifest entry
     */
    public function defaultColourKey(BreedType $breed, ?array $portraitEntry = null): ?string
    {
        $keys = array_column($this->colours($breed), 'key');
        if ($keys === []) {
            return null;
        }

        $fromManifest = $portraitEntry['colour'] ?? null;
        if (is_string($fromManifest) && in_array($fromManifest, $keys, true)) {
            return $fromManifest;
        }

        return $this->colourKeyForValue($breed, $this->portraitTraits($breed)['coat_color'] ?? null) ?? $keys[0];
    }

    /**
     * @param  array{key: string, value: string}  $colour
     */
    public function colourPrompt(BreedType $breed, array $colour): string
    {
        $animal = $breed->species() === Species::Cat ? 'cat' : 'dog';
        $name = (string) config("breed_appearance.{$breed->value}.display_name", str_replace('_', ' ', $breed->value).' '.$animal);

        $hints = self::colourHints($colour['value']);
        $note = $hints === [] ? '' : implode(' ', $hints).' ';

        return strtr(self::COLOUR_EDIT_PROMPT, ['{breed}' => $name, '{animal}' => $animal, '{colour}' => $colour['value'], '{colour_note}' => $note]);
    }

    /**
     * The COLOUR_HINTS that apply to a breed_colours `value` (in COLOUR_HINTS order).
     *
     * @return list<string>
     */
    public static function colourHints(string $value): array
    {
        $value = mb_strtolower($value);
        $hints = [];

        foreach (self::COLOUR_HINTS as $rule) {
            if (preg_match($rule['match'], $value) === 1 && ! (isset($rule['except']) && preg_match($rule['except'], $value) === 1)) {
                $hints[] = $rule['hint'];
            }
        }

        return $hints;
    }

    /** Seed per breed + colour (stable across runs and machines). */
    public static function colourSeedFor(BreedType $breed, string $colourKey): int
    {
        return (int) (crc32('breed-portrait:'.$breed->value.':'.$colourKey) & 0x7FFFFFFF);
    }

    /** "dog/border-collie--blue-merle-white.webp" — relative to the output directory. */
    public static function relativeColourFile(BreedType $breed, string $colourKey, string $extension = 'webp'): string
    {
        return $breed->species()->value.'/'.$breed->slug().self::COLOUR_SEPARATOR.$colourKey.'.'.$extension;
    }

    /**
     * The image EDIT profile for colour variants (media.breed_portraits.colour_profile
     * unless one is named): enabled, takes an input image and an `aspect_ratio`.
     */
    public function colourProfile(?string $key = null): ModelProfile
    {
        $key = $key === null || $key === '' ? (string) config('media.breed_portraits.colour_profile', 'nano_banana_pro_edit') : $key;

        if (! is_array(config("media.profiles.image.{$key}"))) {
            throw new InvalidArgumentException("Unknown image profile {$key}.");
        }

        $profile = $this->profiles->image($key);

        if (! $profile->enabled) {
            throw new InvalidArgumentException("Image profile {$profile->key} is disabled.");
        }

        if (config("media.profiles.image.{$profile->key}.image_param") === null) {
            throw new InvalidArgumentException("Image profile {$profile->key} does not edit an input image — colour variants need an image-edit profile.");
        }

        if (! array_key_exists('aspect_ratio', $profile->params)) {
            throw new InvalidArgumentException("Image profile {$profile->key} has no aspect_ratio parameter — colour variants need a square (1:1) image.");
        }

        return $profile;
    }

    /**
     * The edit input: colour prompt + the reference portrait (image_urls) + seed + square.
     *
     * @param  array{key: string, value: string}  $colour
     * @return array<string, mixed>
     */
    public function colourInput(ModelProfile $profile, BreedType $breed, array $colour, string $referenceImage): array
    {
        $input = $profile->editInput($this->colourPrompt($breed, $colour), $referenceImage, self::colourSeedFor($breed, $colour['key']));
        $input['aspect_ratio'] = '1:1';
        $input['num_images'] = 1;

        return $input;
    }

    /** A local image file as a base64 data URI (fal accepts data URIs for file inputs). */
    public static function dataUri(string $path): string
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException("Reference portrait {$path} could not be read.");
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, ['image/webp', 'image/png', 'image/jpeg'], true)) {
            throw new RuntimeException("Reference portrait {$path} is not an image ({$mime}).");
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * What a colour run would do: one row per breed colour except the default
     * portrait's colour. `generate` / `skip` / `stale` / `force` as plan();
     * `no-reference` when the default portrait is missing in $referenceDir
     * (generate it first — nothing is sent for that breed). `$only` (`--colour`,
     * David 2026-10-26) keeps only those colour keys; null = every colour. An
     * existing variant is still `skip` / `stale` without $force — naming a colour
     * never regenerates an approved file by itself.
     *
     * @param  list<BreedType>  $breeds
     * @param  list<string>|null  $only
     * @return list<array{breed: BreedType, colour: array{key: string, value: string}, default_colour: string, file: string, reference: string, reference_path: string, prompt: string, prompt_hash: string, action: string}>
     */
    public function colourPlan(array $breeds, string $outDir, string $referenceDir, bool $force, ?array $only = null): array
    {
        $references = $this->readManifest($referenceDir);
        $variants = $this->readColourManifest($outDir);
        $rows = [];

        foreach ($breeds as $breed) {
            $entry = $references[$breed->value] ?? null;
            $default = $this->defaultColourKey($breed, is_array($entry) ? $entry : null);
            if ($default === null) {
                continue;
            }

            $reference = is_array($entry) && is_string($entry['file'] ?? null) ? $entry['file'] : self::relativeFile($breed);
            $referencePath = $referenceDir.'/'.$reference;
            $hasReference = is_file($referencePath);

            foreach ($this->colours($breed) as $colour) {
                if ($colour['key'] === $default || ($only !== null && ! in_array($colour['key'], $only, true))) {
                    continue;
                }

                $prompt = $this->colourPrompt($breed, $colour);
                $hash = self::promptHash($prompt);
                $variant = $variants[$breed->value.self::COLOUR_SEPARATOR.$colour['key']] ?? null;
                $file = is_array($variant) && is_string($variant['file'] ?? null) ? $variant['file'] : self::relativeColourFile($breed, $colour['key']);
                $exists = is_file($outDir.'/'.$file);

                $action = match (true) {
                    ! $hasReference => 'no-reference',
                    ! $exists => 'generate',
                    $force => 'force',
                    ($variant['prompt_hash'] ?? null) !== $hash => 'stale',
                    default => 'skip',
                };

                $rows[] = [
                    'breed' => $breed,
                    'colour' => ['key' => $colour['key'], 'value' => $colour['value']],
                    'default_colour' => $default,
                    'file' => $file,
                    'reference' => $reference,
                    'reference_path' => $referencePath,
                    'prompt' => $prompt,
                    'prompt_hash' => $hash,
                    'action' => $action,
                ];
            }
        }

        return $rows;
    }

    /**
     * Generate one colour variant: edit of the reference portrait (lab budget) →
     * download → square WebP → file + `colour_variants` manifest entry.
     *
     * @param  array{key: string, value: string}  $colour
     * @return array<string, mixed> the manifest entry
     */
    public function generateColour(BreedType $breed, array $colour, ModelProfile $profile, string $outDir, string $referencePath, string $reference): array
    {
        $prompt = $this->colourPrompt($breed, $colour);
        $input = $this->colourInput($profile, $breed, $colour, self::dataUri($referencePath));
        $call = $this->gateway->run($profile, $input, AiSpendPurpose::Lab, timeoutSeconds: 180);

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

        $file = self::relativeColourFile($breed, $colour['key'], $image['extension']);
        $this->writeAtomically($outDir.'/'.$file, $image['bytes']);

        $old = $this->readColourManifest($outDir)[$breed->value.self::COLOUR_SEPARATOR.$colour['key']]['file'] ?? null;
        if (is_string($old) && $old !== $file && is_file($outDir.'/'.$old)) {
            @unlink($outDir.'/'.$old);
        }

        $entry = [
            'breed' => $breed->value,
            'species' => $breed->species()->value,
            'colour' => $colour['key'],
            'colour_value' => $colour['value'],
            'file' => $file,
            'reference' => $reference,
            'width' => $image['width'],
            'height' => $image['height'],
            'kind' => self::KIND,
            'profile' => $profile->key,
            'prompt_hash' => self::promptHash($prompt),
            'generated_at' => now()->utc()->toIso8601String(),
            'cost_usd' => round($call['cost_usd'], 4),
        ];

        $this->writeColourManifestEntry($outDir, $entry);

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
        return $this->readManifestDocument($outDir)['portraits'];
    }

    /**
     * Colour variant entries keyed by "<breed>--<colour key>".
     *
     * @return array<string, array<string, mixed>>
     */
    public function readColourManifest(string $outDir): array
    {
        return $this->readManifestDocument($outDir)['colour_variants'];
    }

    /**
     * @return array{portraits: array<string, array<string, mixed>>, colour_variants: array<string, array<string, mixed>>}
     */
    private function readManifestDocument(string $outDir): array
    {
        $path = $outDir.'/'.self::MANIFEST;
        if (! is_file($path)) {
            return ['portraits' => [], 'colour_variants' => []];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['portraits'] ?? null)) {
            throw new RuntimeException("{$path} is not a breed portrait manifest.");
        }

        $portraits = [];
        foreach ($data['portraits'] as $entry) {
            if (is_array($entry) && is_string($entry['breed'] ?? null)) {
                $portraits[$entry['breed']] = $entry;
            }
        }

        $variants = [];
        foreach ((array) ($data['colour_variants'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['breed'] ?? null) && is_string($entry['colour'] ?? null)) {
                $variants[$entry['breed'].self::COLOUR_SEPARATOR.$entry['colour']] = $entry;
            }
        }

        return ['portraits' => $portraits, 'colour_variants' => $variants];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function writeManifestEntry(string $outDir, array $entry): void
    {
        $document = $this->readManifestDocument($outDir);
        $document['portraits'][$entry['breed']] = $entry;

        $this->writeManifest($outDir, $document['portraits'], $document['colour_variants']);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function writeColourManifestEntry(string $outDir, array $entry): void
    {
        $document = $this->readManifestDocument($outDir);
        $document['colour_variants'][$entry['breed'].self::COLOUR_SEPARATOR.$entry['colour']] = $entry;

        $this->writeManifest($outDir, $document['portraits'], $document['colour_variants']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $portraits
     * @param  array<string, array<string, mixed>>  $variants
     */
    private function writeManifest(string $outDir, array $portraits, array $variants): void
    {
        // Stable order: enum order (species, then the register order), unknown keys last;
        // colour variants in breed order, then the breed_colours order.
        $order = array_flip(array_map(fn (BreedType $b) => $b->value, BreedType::cases()));
        uksort($portraits, fn ($a, $b) => [($order[$a] ?? PHP_INT_MAX), $a] <=> [($order[$b] ?? PHP_INT_MAX), $b]);

        $variantRank = function (array $entry) use ($order): array {
            $keys = array_column((array) config('breed_colours.'.$entry['breed'], []), 'key');
            $index = array_search($entry['colour'], $keys, true);

            return [$order[$entry['breed']] ?? PHP_INT_MAX, (string) $entry['breed'], $index === false ? PHP_INT_MAX : $index, (string) $entry['colour']];
        };
        $variants = array_values($variants);
        usort($variants, fn (array $a, array $b) => $variantRank($a) <=> $variantRank($b));

        $manifest = [
            'schema_version' => self::MANIFEST_SCHEMA,
            'generator' => 'backend: php artisan breeds:portraits (M5-R11)',
            'label' => self::LABEL,
            'note' => 'AI-generated breed photos for the website animal register. Show them only with the label "'
                .self::LABEL.'". Prompts come from breed config only (config/breed_appearance.php, config/breed_colours.php) — no pet or child data.',
            'portraits' => array_values($portraits),
        ];

        if ($variants !== []) {
            $manifest['colour_variants'] = $variants;
        }

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
