<?php

/*
| Breed portrait colour variants (David 2026-10-26): `artisan breeds:portraits --colours`
| edits the existing default portrait into every other standard coat colour of
| config/breed_colours.php (Nano Banana Pro Edit, AI Lab budget). First test
| breed: border_collie (7 colours, the default portrait shows black and white).
| No real fal.ai call: Http::preventStrayRequests() + fakes.
*/

use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Models\AiSpendLedger;
use App\Services\Media\BreedPortraitService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.fal_ai.key' => 'test-key',
        'media.breed_portraits.colour_profile' => 'nano_banana_pro_edit',
        'media.lab.daily_usd' => 3,
        'media.lab.monthly_usd' => 30,
    ]);
    $this->out = sys_get_temp_dir().'/breed-portrait-colours-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->out);
    File::deleteDirectory($this->out.'-ref');
});

function bpcService(): BreedPortraitService
{
    return app(BreedPortraitService::class);
}

/** @return array<string, mixed> */
function bpcManifest(string $dir): array
{
    return json_decode((string) file_get_contents($dir.'/manifest.json'), true);
}

/**
 * A default portrait + manifest entry, as `breeds:portraits` writes it.
 *
 * @param  array<string, mixed>  $extra  extra manifest fields (e.g. `colour`)
 */
function bpcReference(string $dir, BreedType $breed = BreedType::BorderCollie, array $extra = []): string
{
    $bytes = fakePngBytes(1024, 1024);
    File::ensureDirectoryExists($dir.'/'.$breed->species()->value);
    file_put_contents($dir.'/'.BreedPortraitService::relativeFile($breed), $bytes);
    file_put_contents($dir.'/manifest.json', json_encode([
        'schema_version' => 1,
        'portraits' => [[
            'breed' => $breed->value, 'species' => $breed->species()->value,
            'file' => BreedPortraitService::relativeFile($breed), 'width' => 1024, 'height' => 1024,
            'kind' => 'ai_photo', 'profile' => 'nano_banana_pro', 'prompt_hash' => 'sha256:x',
            'generated_at' => '2026-10-10T05:47:02+00:00', 'cost_usd' => 0.15,
        ] + $extra],
    ]));

    return $bytes;
}

/** border_collie colour keys except the default black-white, in breed_colours order. */
function bpcBorderCollieVariants(): array
{
    return array_values(array_diff(array_column(config('breed_colours.border_collie'), 'key'), ['black-white']));
}

describe('colour plan and prompt', function () {
    it('takes every breed colour except the one the default portrait shows (prompt traits → black and white)', function () {
        bpcReference($this->out);
        $plan = bpcService()->colourPlan([BreedType::BorderCollie], $this->out, $this->out, false);

        expect(bpcService()->defaultColourKey(BreedType::BorderCollie))->toBe('black-white')
            ->and(array_column(array_column($plan, 'colour'), 'key'))->toBe(bpcBorderCollieVariants())
            ->and($plan)->toHaveCount(6)
            ->and(array_unique(array_column($plan, 'action')))->toBe(['generate'])
            ->and($plan[0]['file'])->toBe('dog/border-collie--tricolour.webp')
            ->and($plan[0]['reference'])->toBe('dog/border-collie.webp');
    });

    it('uses the manifest colour of the default portrait when it names one, else the first colour', function () {
        bpcReference($this->out, extra: ['colour' => 'tricolour']);
        $keys = array_column(array_column(bpcService()->colourPlan([BreedType::BorderCollie], $this->out, $this->out, false), 'colour'), 'key');

        expect($keys)->toContain('black-white')->not->toContain('tricolour')->toHaveCount(6);

        // No manifest colour and a prompt coat colour outside the list → the first (default) colour.
        config(['breed_colours.border_collie' => [
            ['key' => 'one', 'value' => 'one colour', 'en' => 'One', 'sl' => 'Ena'],
            ['key' => 'two', 'value' => 'two colour', 'en' => 'Two', 'sl' => 'Dve'],
        ]]);
        expect(bpcService()->defaultColourKey(BreedType::BorderCollie))->toBe('one');
    });

    it('changes only the coat colour in the edit prompt, with the standard colour value', function () {
        $prompt = bpcService()->colourPrompt(BreedType::BorderCollie, ['key' => 'blue-merle-white', 'value' => 'blue merle and white']);

        expect($prompt)->toStartWith('Edit this photograph of a Border Collie: change ONLY the coat colour and pattern to blue merle and white')
            ->toContain('the same dog, the same pose')
            ->toContain('the same framing, crop and camera angle')
            ->toContain('#F3F5F2')
            ->toContain('no text')
            ->toContain('Realistic 35mm photograph');

        $cat = bpcService()->colourPrompt(BreedType::MaineCoon, ['key' => 'x', 'value' => 'silver tabby']);
        expect($cat)->not->toMatch('/\bdogs?\b/i')->toContain('Only the cat:');
    });

    it('needs an image-edit profile that makes a square image', function () {
        expect(bpcService()->colourProfile()->key)->toBe('nano_banana_pro_edit')
            ->and(fn () => bpcService()->colourProfile('nano_banana_pro'))->toThrow(InvalidArgumentException::class, 'image-edit profile')
            ->and(fn () => bpcService()->colourProfile('nope'))->toThrow(InvalidArgumentException::class, 'Unknown image profile');
    });
});

describe('breeds:portraits --colours', function () {
    it('dry run prints the six prompts and 6 × the edit price, no HTTP call', function () {
        bpcReference($this->out);
        Http::fake();
        $price = bpcService()->colourProfile()->estimatedCostUsd();

        $run = $this->artisan('breeds:portraits', ['--colours' => true, '--breed' => ['border_collie'], '--dry-run' => true, '--out' => $this->out])
            ->expectsOutputToContain('[generate] border_collie tricolour → dog/border-collie--tricolour.webp (reference dog/border-collie.webp, default colour black-white)')
            ->expectsOutputToContain(sprintf('To generate: 6 image(s), estimated $%.4f', 6 * $price))
            ->expectsOutputToContain('Dry run — no fal.ai call, nothing written.');

        foreach (config('breed_colours.border_collie') as $colour) {
            if ($colour['key'] !== 'black-white') {
                $run->expectsOutputToContain('change ONLY the coat colour and pattern to '.$colour['value'].',');
            }
        }
        $run->doesntExpectOutputToContain('border-collie--black-white')->assertSuccessful();

        Http::assertNothingSent();
        expect(AiSpendLedger::count())->toBe(0)
            ->and(round(6 * $price, 4))->toBe(0.9)
            ->and(glob($this->out.'/dog/border-collie--*'))->toBe([]);
    });

    it('edits the existing portrait (reference passed) into six WebP variants + manifest entries on the AI Lab budget', function () {
        $this->freezeTime();
        $reference = bpcReference($this->out);
        fakeFalImageRun(endpoint: 'fal-ai/nano-banana-pro/edit', file: 'variant.png');

        $this->artisan('breeds:portraits', ['--colours' => true, '--breed' => ['border_collie'], '--out' => $this->out])
            ->expectsOutputToContain('border_collie red-merle-white: wrote dog/border-collie--red-merle-white.webp (1024×1024)')
            ->expectsOutputToContain('Generated 6, failed 0, estimated spend $0.9000 (AI Lab).')
            ->assertSuccessful();

        foreach (bpcBorderCollieVariants() as $key) {
            $path = "{$this->out}/dog/border-collie--{$key}.webp";
            expect(is_file($path))->toBeTrue()
                ->and((new finfo(FILEINFO_MIME_TYPE))->file($path))->toBe('image/webp')
                ->and(array_slice(getimagesize($path), 0, 2))->toBe([1024, 1024]);
        }
        expect(is_file("{$this->out}/dog/border-collie--black-white.webp"))->toBeFalse();

        $manifest = bpcManifest($this->out);
        expect(array_column($manifest['portraits'], 'breed'))->toBe(['border_collie']) // the default portrait stays
            ->and(array_column($manifest['colour_variants'], 'colour'))->toBe(bpcBorderCollieVariants());
        expect($manifest['colour_variants'][3])->toMatchArray([
            'breed' => 'border_collie', 'species' => 'dog', 'colour' => 'blue-merle-white', 'colour_value' => 'blue merle and white',
            'file' => 'dog/border-collie--blue-merle-white.webp', 'reference' => 'dog/border-collie.webp',
            'width' => 1024, 'height' => 1024, 'kind' => 'ai_photo', 'profile' => 'nano_banana_pro_edit', 'cost_usd' => 0.15,
            'prompt_hash' => BreedPortraitService::promptHash(bpcService()->colourPrompt(BreedType::BorderCollie, ['key' => 'blue-merle-white', 'value' => 'blue merle and white'])),
        ])->and($manifest['colour_variants'][3]['generated_at'])->toBe(now()->utc()->toIso8601String());

        $calls = Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://fal.run/'))->values();
        expect($calls)->toHaveCount(6);
        foreach ($calls as $i => [$request]) {
            $colour = config('breed_colours.border_collie')[$i + 1];
            $body = $request->data();
            expect($request->url())->toBe('https://fal.run/fal-ai/nano-banana-pro/edit')
                ->and($body['image_urls'])->toBe(['data:image/png;base64,'.base64_encode($reference)])
                ->and($body['prompt'])->toContain('to '.$colour['value'].',')
                ->and($body['aspect_ratio'])->toBe('1:1')
                ->and($body['num_images'])->toBe(1)
                ->and($body['seed'])->toBe(BreedPortraitService::colourSeedFor(BreedType::BorderCollie, $colour['key']));
        }

        $rows = AiSpendLedger::all();
        expect($rows)->toHaveCount(6)
            ->and($rows->pluck('purpose')->unique()->all())->toBe([AiSpendPurpose::Lab->value])
            ->and($rows->pluck('pet_id')->filter()->all())->toBe([]);
    });

    it('skips existing variants and regenerates only with --force', function () {
        bpcReference($this->out);
        fakeFalImageRun(endpoint: 'fal-ai/nano-banana-pro/edit', file: 'variant.png');

        $this->artisan('breeds:portraits', ['--colours' => true, '--breed' => ['border_collie'], '--out' => $this->out])->assertSuccessful();
        $this->artisan('breeds:portraits', ['--colours' => true, '--breed' => ['border_collie'], '--out' => $this->out])
            ->expectsOutputToContain('Nothing to generate.')
            ->assertSuccessful();
        expect(AiSpendLedger::count())->toBe(6);

        $this->artisan('breeds:portraits', ['--colours' => true, '--breed' => ['border_collie'], '--out' => $this->out, '--force' => true])
            ->expectsOutputToContain('Generated 6, failed 0')
            ->assertSuccessful();
        expect(AiSpendLedger::count())->toBe(12)
            ->and(bpcManifest($this->out)['colour_variants'])->toHaveCount(6);
    });

    it('reads the reference from --reference-dir and writes only to --out', function () {
        bpcReference($this->out.'-ref');
        fakeFalImageRun(endpoint: 'fal-ai/nano-banana-pro/edit', file: 'variant.png');

        $this->artisan('breeds:portraits', ['--colours' => true, '--breed' => ['border_collie'], '--out' => $this->out, '--reference-dir' => $this->out.'-ref'])
            ->assertSuccessful();

        expect(bpcManifest($this->out)['portraits'])->toBe([])
            ->and(bpcManifest($this->out)['colour_variants'])->toHaveCount(6)
            ->and(glob($this->out.'-ref/dog/border-collie--*'))->toBe([]);
    });

    it('sends nothing for a breed without a default portrait, and fails the run', function () {
        Http::fake();

        $this->artisan('breeds:portraits', ['--colours' => true, '--breed' => ['border_collie'], '--out' => $this->out])
            ->expectsOutputToContain('border_collie: no default portrait dog/border-collie.webp in the reference directory')
            ->expectsOutputToContain('To generate: 0 image(s)')
            ->assertFailed();

        Http::assertNothingSent();
        expect(AiSpendLedger::count())->toBe(0);
    });

    it('rejects a text-to-image profile or a missing reference dir before any call', function () {
        Http::fake();

        $this->artisan('breeds:portraits', ['--colours' => true, '--out' => $this->out, '--profile' => 'nano_banana_pro'])->assertFailed();
        $this->artisan('breeds:portraits', ['--colours' => true, '--out' => $this->out, '--reference-dir' => $this->out.'/nope'])->assertFailed();

        Http::assertNothingSent();
    });

    it('stops at the AI Lab cap and keeps the finished variants', function () {
        config(['media.lab.daily_usd' => 0.2]);
        bpcReference($this->out);
        fakeFalImageRun(endpoint: 'fal-ai/nano-banana-pro/edit', file: 'variant.png');

        $this->artisan('breeds:portraits', ['--colours' => true, '--breed' => ['border_collie'], '--out' => $this->out])
            ->expectsOutputToContain('border_collie tricolour: wrote')
            ->expectsOutputToContain('border_collie red-white: budget_lab')
            ->expectsOutputToContain('Stopped: 4 more colour variant(s) not tried.')
            ->assertFailed();

        expect(array_column(bpcManifest($this->out)['colour_variants'], 'colour'))->toBe(['tricolour']);
    });
});
