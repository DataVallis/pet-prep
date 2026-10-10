<?php

/*
| M5-R11 breed portraits (David 2026-10-10): `artisan breeds:portraits` makes one
| AI-generated photo per register breed for the website, charged to the
| AI Lab budget. No real fal.ai call: Http::preventStrayRequests() + fakes.
*/

use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\Species;
use App\Models\AiSpendLedger;
use App\Services\Media\BreedPortraitService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.fal_ai.key' => 'test-key',
        'media.reference_image_profile' => 'nano_banana_pro',
        'media.lab.daily_usd' => 3,
        'media.lab.monthly_usd' => 30,
    ]);
    $this->out = sys_get_temp_dir().'/breed-portraits-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->out);
});

function bpService(): BreedPortraitService
{
    return app(BreedPortraitService::class);
}

/** @return array<string, mixed> */
function bpManifest(string $dir): array
{
    return json_decode((string) file_get_contents($dir.'/manifest.json'), true);
}

describe('breeds and prompts', function () {
    it('takes exactly the breeds of the website register (committed export), with its slugs', function () {
        $path = base_path('../docs/research/breed-registry.json');
        if (! is_file($path)) {
            $this->markTestSkipped('docs/research/breed-registry.json is not in this checkout.');
        }
        $registry = json_decode((string) file_get_contents($path), true);
        $speciesSlug = collect($registry['species'])->mapWithKeys(fn ($s) => [$s['id'] => $s['slug']['en']]);

        $expected = collect($registry['breeds'])->map(fn ($b) => "{$b['species']}/{$b['slug']['en']}.webp")->sort()->values()->all();
        $ours = collect(bpService()->breeds())->map(fn (BreedType $b) => BreedPortraitService::relativeFile($b))->sort()->values()->all();

        expect($ours)->toBe($expected)
            ->and($speciesSlug->all())->toBe(['dog' => 'dogs', 'cat' => 'cats']);
    });

    it('leaves out the free breeds (no single breed look) and filters by breed / slug / species', function () {
        $all = array_map(fn (BreedType $b) => $b->value, bpService()->breeds());

        expect($all)->toBe(['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'maine_coon'])
            ->and($all)->not->toContain('mutt')->not->toContain('domestic_cat')
            ->and(bpService()->breeds(['golden-retriever', 'maine_coon']))->toBe([BreedType::GoldenRetriever, BreedType::MaineCoon])
            ->and(bpService()->breeds([], Species::Cat))->toBe([BreedType::MaineCoon]);

        expect(fn () => bpService()->breeds(['mutt']))->toThrow(InvalidArgumentException::class, 'not in the animal register');
    });

    it('describes the typical adult: highest weight, first on a tie, config override (Labrador yellow)', function () {
        $s = bpService();

        expect($s->portraitTraits(BreedType::BorderCollie))->toMatchArray([
            'coat_color' => 'black and white',
            'coat_pattern' => 'with a white collar',
            'markings' => 'a symmetrical white blaze on the face',
            'ear_carriage' => 'semi-erect',
            'eye_color' => 'brown',
        ])
            ->and($s->portraitTraits(BreedType::LabradorRetriever)['coat_color'])->toBe('yellow')
            ->and($s->portraitTraits(BreedType::LabradorRetriever)['eye_color'])->toBe('brown')
            ->and($s->portraitTraits(BreedType::GoldenRetriever)['coat_color'])->toBe('rich gold')
            ->and($s->portraitTraits(BreedType::MaineCoon))->toMatchArray([
                'coat_color' => 'brown',
                'coat_pattern' => 'with classic tabby markings',
                'eye_color' => 'green',
            ]);

        // Without the override the tie black (3) / yellow (3) goes to the first option.
        config(['media.breed_portraits.traits.labrador_retriever' => []]);
        expect($s->portraitTraits(BreedType::LabradorRetriever)['coat_color'])->toBe('black');
    });

    it('refuses an override that is not an allowed option', function () {
        config(['media.breed_portraits.traits.border_collie' => ['coat_color' => 'purple']]);
        expect(fn () => bpService()->portraitTraits(BreedType::BorderCollie))->toThrow(InvalidArgumentException::class, 'not an allowed option');

        config(['media.breed_portraits.traits.border_collie' => ['wings' => 'yes']]);
        expect(fn () => bpService()->portraitTraits(BreedType::BorderCollie))->toThrow(InvalidArgumentException::class, 'unknown trait');

        // Blue eyes only with a merle coat (only_with) — not with the default black and white.
        config(['media.breed_portraits.traits.border_collie' => ['eye_color' => 'blue']]);
        expect(fn () => bpService()->portraitTraits(BreedType::BorderCollie))->toThrow(InvalidArgumentException::class);
    });

    it('builds the prompt from breed features + adult cue + the fixed style', function () {
        $s = bpService();
        $lab = $s->prompt(BreedType::LabradorRetriever);

        expect($lab)->toStartWith('A photorealistic photograph of a single large strongly built, broad-chested Labrador Retriever')
            ->toContain('short, dense coat in yellow')
            ->toContain('"otter" tail')
            ->toContain('The dog is a fully grown adult dog in its prime')
            ->toContain(str_replace('{animal}', 'dog', BreedPortraitService::PORTRAIT_STYLE));

        foreach (['#F3F5F2', 'studio backdrop', 'Realistic 35mm photograph', 'soft natural floor shadow', 'no coloured glow', 'true-to-life fur', 'three-quarter side view', 'standing', 'natural breed-typical proportions',
            'no people', 'no text', 'no logo', 'no name tag', 'same consistent photographic style for every breed'] as $needle) {
            expect($lab)->toContain($needle);
        }

        // David 2026-10-10: realistic photos like the app's pets, not illustrations.
        expect($lab)->not->toMatch('/illustrat/i')->not->toContain('#7FE0B4');

        expect($s->prompt(BreedType::BorderCollie))->toContain('Border Collie')->toContain('black and white with a white collar')
            ->and($s->prompt(BreedType::GoldenRetriever))->toContain('Golden Retriever')->toContain('rich gold');
    });

    it('never says "dog" in a cat prompt (cat templates)', function () {
        $cat = bpService()->prompt(BreedType::MaineCoon);

        expect($cat)->not->toMatch('/\bdogs?\b/i')
            ->toContain('Maine Coon cat')
            ->toContain('lynx-tufted ears')
            ->toContain('a full frill around the neck and chest')
            ->toContain('The cat is a fully grown adult cat in its prime')
            ->toContain('Only the cat:');
    });

    it('is deterministic and carries no pet or child data', function () {
        $s = bpService();

        foreach ($s->breeds() as $breed) {
            $a = $s->prompt($breed);
            expect($s->prompt($breed))->toBe($a)
                ->and(BreedPortraitService::promptHash($a))->toBe(BreedPortraitService::promptHash($s->prompt($breed)))
                ->and(BreedPortraitService::seedFor($breed))->toBe(BreedPortraitService::seedFor($breed));
        }

        // The input is built from breed + config only; profile params stay, aspect becomes square.
        $input = $s->input($s->profile(), BreedType::BorderCollie);
        expect($input)->toMatchArray(['aspect_ratio' => '1:1', 'num_images' => 1, 'resolution' => '1K', 'seed' => BreedPortraitService::seedFor(BreedType::BorderCollie)])
            ->and(array_keys($input))->toEqualCanonicalizing(['prompt', 'seed', 'num_images', 'aspect_ratio', 'resolution', 'output_format', 'safety_tolerance']);
    });

    it('needs a text-to-image profile that can make a square image', function () {
        expect(fn () => bpService()->profile('flux_schnell'))->toThrow(InvalidArgumentException::class, 'aspect_ratio')
            ->and(fn () => bpService()->profile('nano_banana_pro_edit'))->toThrow(InvalidArgumentException::class, 'text-to-image')
            ->and(bpService()->profile()->key)->toBe('nano_banana_pro');
    });
});

describe('breeds:portraits command', function () {
    it('dry run prints every prompt and the estimated cost and makes no HTTP call', function () {
        Http::fake();

        $this->artisan('breeds:portraits', ['--dry-run' => true, '--out' => $this->out])
            ->expectsOutputToContain('[generate] border_collie → dog/border-collie.webp')
            ->expectsOutputToContain('[generate] maine_coon → cat/maine-coon.webp')
            ->expectsOutputToContain(bpService()->prompt(BreedType::LabradorRetriever))
            ->expectsOutputToContain('To generate: 14 image(s), estimated $2.1000')
            ->expectsOutputToContain('Dry run — no fal.ai call, nothing written.')
            ->assertSuccessful();

        Http::assertNothingSent();
        expect(is_dir($this->out))->toBeFalse()
            ->and(AiSpendLedger::count())->toBe(0);
    });

    it('refuses a real run when the default repo folder is not visible (Sail), but a dry run only warns', function () {
        $this->app->setBasePath($this->out.'/backend'); // ../docs/research does not exist there
        Http::fake();

        $this->artisan('breeds:portraits', ['--dry-run' => true])
            ->expectsOutputToContain('pass --out=storage/app/breed-portraits')
            ->assertSuccessful();
        $this->artisan('breeds:portraits')
            ->expectsOutputToContain('Under Sail only backend/ is mounted')
            ->assertFailed();

        Http::assertNothingSent();
    });

    it('generates square WebP files + manifest on the AI Lab budget (ledger rows like lab calls)', function () {
        // The production budget is exhausted — portraits never use it.
        config(['media.budget.daily_usd' => 0, 'media.budget.monthly_usd' => 0]);
        fakeFalImageRun();

        $this->artisan('breeds:portraits', ['--out' => $this->out])
            ->expectsOutputToContain('border_collie: wrote dog/border-collie.webp (1024×1024)')
            ->expectsOutputToContain('Generated 14, failed 0, estimated spend $2.1000 (AI Lab).')
            ->assertSuccessful();

        foreach (['dog/border-collie.webp', 'dog/labrador-retriever.webp', 'dog/golden-retriever.webp', 'dog/french-bulldog.webp', 'dog/german-shepherd-dog.webp', 'dog/cavalier-king-charles-spaniel.webp', 'dog/beagle.webp', 'dog/poodle-standard.webp', 'dog/dachshund.webp', 'dog/australian-shepherd.webp', 'dog/havanese.webp', 'dog/west-highland-white-terrier.webp', 'dog/bernese-mountain-dog.webp', 'cat/maine-coon.webp'] as $file) {
            $path = "{$this->out}/{$file}";
            expect(is_file($path))->toBeTrue()
                ->and((new finfo(FILEINFO_MIME_TYPE))->file($path))->toBe('image/webp')
                ->and(getimagesize($path)[0])->toBe(1024)
                ->and(getimagesize($path)[1])->toBe(1024);
        }

        $manifest = bpManifest($this->out);
        expect($manifest['schema_version'])->toBe(1)
            ->and($manifest['label'])->toBe('AI-generated photo')
            ->and(array_column($manifest['portraits'], 'breed'))->toBe(['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'maine_coon']);

        $lab = $manifest['portraits'][1];
        expect(array_keys($lab))->toBe(['breed', 'species', 'file', 'width', 'height', 'kind', 'profile', 'prompt_hash', 'generated_at', 'cost_usd'])
            ->and($lab)->toMatchArray([
                'breed' => 'labrador_retriever', 'species' => 'dog', 'file' => 'dog/labrador-retriever.webp',
                'width' => 1024, 'height' => 1024, 'kind' => 'ai_photo', 'profile' => 'nano_banana_pro',
                'prompt_hash' => BreedPortraitService::promptHash(bpService()->prompt(BreedType::LabradorRetriever)),
                'cost_usd' => 0.15,
            ])
            ->and($manifest['portraits'][12]['species'])->toBe('dog')
            ->and($manifest['portraits'][13]['species'])->toBe('cat');

        $rows = AiSpendLedger::all();
        expect($rows)->toHaveCount(14)
            ->and($rows->pluck('purpose')->unique()->all())->toBe([AiSpendPurpose::Lab->value])
            ->and($rows->pluck('status')->unique()->all())->toBe([AiSpendLedger::STATUS_COMMITTED])
            ->and($rows->pluck('pet_id')->filter()->all())->toBe([])
            ->and(round((float) $rows->sum('cost_usd'), 2))->toBe(2.1);

        $calls = Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://fal.run/'));
        expect($calls)->toHaveCount(14);
        $body = $calls[0][0]->data();
        expect($body['aspect_ratio'])->toBe('1:1')
            ->and($body['prompt'])->toBe(bpService()->prompt(BreedType::BorderCollie))
            ->and($calls[0][0]->url())->toBe('https://fal.run/fal-ai/nano-banana-pro');
    });

    it('crops a non-square answer to the centre and caps the size at 1200', function () {
        fakeFalImageRun(bytes: fakePngBytes(2000, 1400));

        $this->artisan('breeds:portraits', ['--out' => $this->out, '--breed' => ['border_collie']])->assertSuccessful();

        expect(array_slice(getimagesize("{$this->out}/dog/border-collie.webp"), 0, 2))->toBe([1200, 1200])
            ->and(bpManifest($this->out)['portraits'][0])->toMatchArray(['width' => 1200, 'height' => 1200]);
    });

    it('skips existing portraits, flags a changed prompt and regenerates only with --force', function () {
        fakeFalImageRun();
        $this->artisan('breeds:portraits', ['--out' => $this->out, '--species' => 'cat'])->assertSuccessful();
        $first = bpManifest($this->out)['portraits'][0]['generated_at'];
        expect(Http::recorded())->toHaveCount(2); // fal + download

        $this->travel(1)->hours();
        $this->artisan('breeds:portraits', ['--out' => $this->out, '--species' => 'cat'])
            ->expectsOutputToContain('Nothing to generate.')
            ->assertSuccessful();
        expect(Http::recorded())->toHaveCount(2)
            ->and(AiSpendLedger::count())->toBe(1);

        // The breed config changes → stale, still not regenerated without --force.
        config(['media.breed_portraits.traits.maine_coon' => ['eye_color' => 'copper']]);
        $this->artisan('breeds:portraits', ['--out' => $this->out, '--species' => 'cat'])
            ->expectsOutputToContain('maine_coon: the prompt changed')
            ->expectsOutputToContain('Nothing to generate.')
            ->assertSuccessful();
        expect(AiSpendLedger::count())->toBe(1);

        $this->artisan('breeds:portraits', ['--out' => $this->out, '--breed' => ['maine-coon'], '--force' => true])->assertSuccessful();
        $entry = bpManifest($this->out)['portraits'][0];
        expect(AiSpendLedger::count())->toBe(2)
            ->and($entry['generated_at'])->not->toBe($first)
            ->and($entry['prompt_hash'])->toBe(BreedPortraitService::promptHash(bpService()->prompt(BreedType::MaineCoon)))
            ->and(bpService()->prompt(BreedType::MaineCoon))->toContain('copper eyes');
    });

    it('stops at the AI Lab cap, keeps what it made, and the next run continues', function () {
        config(['media.lab.daily_usd' => 0.2]);
        fakeFalImageRun();

        $this->artisan('breeds:portraits', ['--out' => $this->out])
            ->expectsOutputToContain('border_collie: wrote dog/border-collie.webp')
            ->expectsOutputToContain('labrador_retriever: budget_lab')
            ->expectsOutputToContain('Stopped: 12 more breed(s) not tried.')
            ->assertFailed();

        expect(array_column(bpManifest($this->out)['portraits'], 'breed'))->toBe(['border_collie'])
            ->and(is_file("{$this->out}/dog/labrador-retriever.webp"))->toBeFalse()
            ->and(AiSpendLedger::count())->toBe(1)
            ->and(Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://fal.run/')))->toHaveCount(1);

        // Next day (new lab budget): only the missing ones.
        $this->travel(1)->days();
        config(['media.lab.daily_usd' => 3]);
        $this->artisan('breeds:portraits', ['--out' => $this->out])
            ->expectsOutputToContain('Generated 13, failed 0')
            ->assertSuccessful();
        expect(array_column(bpManifest($this->out)['portraits'], 'breed'))->toBe(['border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'maine_coon']);
    });

    it('writes nothing when fal answers an error (ledger void) and fails the run', function () {
        fakeFalImageRun(status: 500);

        $this->artisan('breeds:portraits', ['--out' => $this->out, '--breed' => ['golden_retriever']])
            ->expectsOutputToContain('golden_retriever: http_error')
            ->assertFailed();

        expect(is_file("{$this->out}/manifest.json"))->toBeFalse()
            ->and(AiSpendLedger::sole()->status)->toBe(AiSpendLedger::STATUS_VOID);
    });

    it('refuses an answer that is not an image (content type sniffed by the downloader)', function () {
        fakeFalImageRun(bytes: '<html>not an image</html>');

        $this->artisan('breeds:portraits', ['--out' => $this->out, '--breed' => ['border_collie']])
            ->expectsOutputToContain('border_collie: ')
            ->assertFailed();

        expect(is_file("{$this->out}/dog/border-collie.webp"))->toBeFalse()
            ->and(is_file("{$this->out}/manifest.json"))->toBeFalse();
    });

    it('refuses an image URL outside the fal media hosts without downloading it', function () {
        Http::fake(['fal.run/*' => Http::response(['images' => [['url' => 'https://evil.example/x.png']]])]);

        $this->artisan('breeds:portraits', ['--out' => $this->out, '--breed' => ['border_collie']])
            ->expectsOutputToContain('No usable image URL')
            ->assertFailed();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
    });

    it('rejects an unknown breed, species or profile before any call', function () {
        Http::fake();

        $this->artisan('breeds:portraits', ['--out' => $this->out, '--breed' => ['poodle']])->assertFailed();
        $this->artisan('breeds:portraits', ['--out' => $this->out, '--species' => 'hamster'])->assertFailed();
        $this->artisan('breeds:portraits', ['--out' => $this->out, '--profile' => 'flux_schnell'])->assertFailed();

        Http::assertNothingSent();
    });

    it('fails clearly without a fal key (no ledger row)', function () {
        config(['services.fal_ai.key' => null]);
        Http::fake();

        $this->artisan('breeds:portraits', ['--out' => $this->out, '--breed' => ['border_collie']])
            ->expectsOutputToContain('border_collie: disabled')
            ->assertFailed();

        Http::assertNothingSent();
        expect(AiSpendLedger::count())->toBe(0);
    });
});
