<?php

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Filament\Pages\AiLab;
use App\Jobs\RunMediaLabImage;
use App\Jobs\StorePetMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\MediaLabResult;
use App\Models\MediaLabRun;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\ChildProfileService;
use App\Services\FalAiService;
use App\Services\FalWebhookVerifier;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\MediaLabService;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetDnaService;
use App\Services\Media\PetMediaService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R06-07 — cat AI appearance and media (CAT_SPEC §8, M5-R06_PLAN)
|--------------------------------------------------------------------------
|
| Species templates in PetAppearancePrompt (never the word "dog" in a cat
| prompt), cat breeds in config/breed_appearance.php (domestic cat draft,
| Maine Coon per FIFe C16), state videos per species (`low_energy` =
| bored, `scratching` instead of `accident` / `chewing`), the AI Lab for
| cats (stage cue, species check, per-pet cost) and the legacy DNA v1
| path refusing a cat. No real fal.ai call: Http::preventStrayRequests().
| Dog prompts: DogMediaPromptSnapshotTest.
*/

const CAM_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    Cache::forget(FalWebhookVerifier::CACHE_KEY);
    Cache::forget(FalGateway::BALANCE_FLAG_KEY);
    config([
        'services.fal_ai.key' => 'test-key',
        'app.url' => 'https://api.petprep.si',
        'media.reference_image_profile' => 'nano_banana_pro',
        'media.state_video_profile' => 'kling_v3_pro',
        'media.budget.daily_usd' => 5.0,
        'media.budget.monthly_usd' => 50.0,
        'media.budget.timezone' => 'UTC',
        'media.lab.max_run_usd' => 3.0,
        'media.lab.daily_usd' => 3.0,
        'media.lab.monthly_usd' => 30.0,
    ]);
    seedBreedConfigs();
});

/** Every word "dog" (also "dogs", "Dog") — a cat prompt must have none. */
function camHasDog(string $text): bool
{
    return preg_match('/\bdogs?\b/i', $text) === 1;
}

/** A born, profiled cat with DNA v2 (Maine Coon = purchased challenge, domestic = free). */
function camCat(string $breed = 'maine_coon', string $stage = 'young', ?string $origin = 'bought', bool $legacy = false): Pet
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);
    $factory = $breed === 'maine_coon'
        ? Pet::factory()->purchased()
        : Pet::factory()->state(['plan' => 'free', 'challenge_paid_at' => null, 'challenge_paid_source' => null, 'trial_ends_at' => null]);
    $pet = $factory->create([
        'user_id' => $child->id,
        'breed_type' => $breed,
        'media_status' => 'pending',
        'arrival_age_months' => $legacy ? null : ($stage === 'puppy' ? 3 : 12),
        'life_stage' => $legacy ? null : $stage,
        'origin' => $legacy ? null : $origin,
    ]);
    $pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet, 4242)])->saveQuietly();

    return $pet->fresh();
}

/** A READY reference image on the fake disk. */
function camStoredImage(Pet $pet): PetMedia
{
    $path = $pet->id.'/reference-g1.jpg';
    Storage::disk('pet_media')->put($path, CAM_JPEG.str_repeat("\x00", 256));

    return PetMedia::create([
        'pet_id' => $pet->id, 'kind' => 'image', 'status' => 'ready', 'generation' => 1,
        'storage_path' => $path, 'mime' => 'image/jpeg', 'bytes' => 276, 'profile' => 'nano_banana_pro',
    ]);
}

function camSuperadmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

/** A completed lab image of the breed (source for a video run). */
function camLabImage(string $breed, array $traits): MediaLabResult
{
    $run = MediaLabRun::create(['user_id' => camSuperadmin()->id, 'kind' => 'image', 'breed' => $breed, 'samples' => 1, 'profiles' => ['flux2_pro']]);

    return $run->results()->create([
        'kind' => 'image', 'profile' => 'flux2_pro', 'endpoint' => 'fal-ai/flux-2-pro', 'sample_index' => 0, 'seed' => 42,
        'traits' => $traits, 'prompt' => 'p', 'status' => MediaLabResult::STATUS_COMPLETED,
        'result_url' => 'https://v3.fal.media/files/lab/cat.jpg',
    ]);
}

/* ─────────────────────────── Prompts ─────────────────────────── */

describe('cat prompts (species templates)', function () {
    it('never says "dog" in any cat prompt and always names a cat', function () {
        $prompts = app(PetAppearancePrompt::class);
        $dna = app(PetDnaService::class);

        foreach ([BreedType::DomesticCat, BreedType::MaineCoon] as $breed) {
            foreach (range(1, 40) as $seed) {
                $traits = $dna->sample($breed->value, $seed)['traits'];
                $v2 = $dna->dnaFrom($breed->value, $seed, $dna->sample($breed->value, $seed));
                $texts = [$prompts->imagePrompt($breed->value, $traits), $v2['prompt'], $v2['negative_prompt']];

                foreach (LifeStage::ordered() as $stage) {
                    foreach ([PetOrigin::Bought, PetOrigin::Adopted] as $origin) {
                        $pet = (new Pet)->forceFill(['breed_type' => $breed->value, 'pet_dna' => $v2, 'life_stage' => $stage->value, 'origin' => $origin->value]);
                        $texts[] = $prompts->imagePromptForPet($pet);
                        $texts[] = $prompts->stageEditPrompt($pet, $stage);
                    }
                }

                foreach (PetStateEnum::cases() as $state) {
                    if ($state->appliesTo(Species::Cat)) {
                        $texts[] = $prompts->videoPrompt($breed->value, $state, $traits);
                        $texts[] = $prompts->videoPrompt($breed->value, $state);
                    }
                }

                foreach ($texts as $text) {
                    expect(camHasDog($text))->toBeFalse("'dog' in: {$text}");
                }
                expect($v2['prompt'])->toContain('cat')
                    ->and($v2['negative_prompt'])->toBe(PetAppearancePrompt::CAT_NEGATIVE);
            }
        }

        expect(camHasDog($prompts->negativePrompt(Species::Cat)))->toBeFalse()
            ->and(camHasDog($prompts->videoNegativePrompt(Species::Cat)))->toBeFalse()
            ->and($prompts->videoNegativePrompt(Species::Cat))->toContain('second cat')
            ->and(camHasDog((string) PetOrigin::Adopted->promptCue(Species::Cat)))->toBeFalse()
            ->and(PetOrigin::Adopted->promptCue(Species::Cat))->toContain('adopted cat')->not->toContain('shelter');
    });

    it('builds the reference image prompt from breed, traits, stage and origin — cat style, no personal data', function () {
        $pet = camCat('domestic_cat', 'puppy', 'adopted');
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet);

        expect($prompt)->toStartWith('A photorealistic photograph of a single medium-sized ')
            ->toContain('domestic mixed-breed cat')
            ->toContain('The cat is a young kitten')
            ->toContain('A recently adopted cat')
            ->toContain(PetAppearancePrompt::CAT_ADOPTED_AVOID)
            ->toEndWith(PetAppearancePrompt::CAT_STYLE)
            ->not->toContain('Maine Coon')
            ->and(camHasDog($prompt))->toBeFalse();
    });

    it('adds the Maine Coon features (FIFe C16) and the stage notes (kitten bigger, young not fully grown)', function () {
        $prompts = app(PetAppearancePrompt::class);
        $kitten = camCat('maine_coon', 'puppy');
        $young = camCat('maine_coon', 'young');

        expect($prompts->imagePromptForPet($kitten))
            ->toContain('large ')
            ->toContain('Maine Coon cat')
            ->toContain('a full frill around the neck and chest')
            ->toContain('tufted ears')
            ->toContain('very long, flowing, fully furred tail')
            ->toContain('The cat is a young kitten')
            ->toContain('As a Maine Coon kitten it is already noticeably bigger');

        expect($prompts->imagePromptForPet($young))->toContain('As a young Maine Coon it is not fully grown yet')
            ->and($prompts->stageEditPrompt($young, LifeStage::Young))
            ->toStartWith('The same cat as in the reference image (')
            ->toContain('As a young Maine Coon it is not fully grown yet')
            ->toContain(PetAppearancePrompt::CAT_EDIT_KEEP);

        // Adult / senior and the domestic cat have no stage note.
        expect($prompts->imagePromptForPet(camCat('maine_coon', 'adult')))->not->toContain('As a')
            ->and($prompts->imagePromptForPet(camCat('domestic_cat', 'young')))->not->toContain('As a');
    });

    it('describes cat state videos per species: bored instead of tired, a feather from above, scratching the sofa', function () {
        $prompts = app(PetAppearancePrompt::class);

        expect(PetStateEnum::LowEnergy->promptModifier(Species::Cat))->toContain('bored')->toContain('window')
            ->not->toContain('tired')
            ->and(PetStateEnum::Playing->promptModifier(Species::Cat))->toContain('feather')->toContain('from above')
            ->and(PetStateEnum::Sleeping->promptModifier(Species::Cat))->toContain('curled up in a tight ball')
            ->and(PetStateEnum::Scratching->promptModifier(Species::Cat))->toContain('scratching the side of a fabric sofa');

        $video = $prompts->videoPrompt('maine_coon', PetStateEnum::Hungry, ['coat_color' => 'brown']);
        expect($video)->toStartWith('The same Maine Coon cat')
            ->toContain('empty cat food bowl')
            ->toEndWith(PetAppearancePrompt::CAT_VIDEO_STYLE);
    });

    it('refuses a state the species never has: no accident / chewing for a cat, no scratching for a dog', function () {
        $prompts = app(PetAppearancePrompt::class);

        expect(fn () => PetStateEnum::Accident->promptModifier(Species::Cat))->toThrow(InvalidArgumentException::class)
            ->and(fn () => PetStateEnum::Chewing->promptModifier(Species::Cat))->toThrow(InvalidArgumentException::class)
            ->and(fn () => PetStateEnum::Scratching->promptModifier())->toThrow(InvalidArgumentException::class)
            ->and(fn () => $prompts->videoPrompt('domestic_cat', PetStateEnum::Accident))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $prompts->videoPrompt('mutt', PetStateEnum::Scratching))->toThrow(InvalidArgumentException::class);

        expect(PetStateEnum::petStates())->toHaveCount(6)
            ->and(PetStateEnum::Scratching->isBehaviour())->toBeTrue()
            ->and(PetStateEnum::Scratching->appliesTo(Species::Dog))->toBeFalse()
            ->and(PetStateEnum::Accident->appliesTo(Species::Cat))->toBeFalse();
    });
});

/* ─────────────────────────── Appearance config ─────────────────────────── */

describe('cat appearance data', function () {
    it('has appearance data for every breed and keeps both cat breeds unverified', function () {
        foreach (BreedType::cases() as $breed) {
            expect(PetDnaService::hasAppearance($breed->value))->toBeTrue($breed->value);
        }

        expect(config('breed_appearance.domestic_cat.verified'))->toBeFalse()
            ->and(config('breed_appearance.domestic_cat.source'))->toContain('UNSOURCED')
            ->and(config('breed_appearance.maine_coon.verified'))->toBeFalse()
            ->and(config('breed_appearance.maine_coon.source'))->toContain('C16')
            ->and(config('breed_appearance.maine_coon.sources.eye_color'))->toContain('C16')
            ->and(app(PetDnaService::class)->breeds())->toContain('domestic_cat', 'maine_coon');
    });

    it('keeps the Maine Coon inside the FIFe standard (C16): no excluded colours, blue eyes only with white', function () {
        $options = app(PetDnaService::class)->optionsFor('maine_coon');

        foreach ($options['coat_color'] as $colour) {
            expect($colour)->not->toMatch('/point|chocolate|lilac|cinnamon|fawn|siames/i');
        }

        foreach (range(1, 300) as $seed) {
            $t = app(PetDnaService::class)->sample('maine_coon', $seed)['traits'];
            if (str_contains($t['eye_color'], 'blue')) {
                expect($t['coat_color'])->toBe('white');
            }
        }
    });

    it('samples coherent domestic cat coats: tabby patterns only on tabby colours, blue eyes only with white', function () {
        foreach (range(1, 300) as $seed) {
            $t = app(PetDnaService::class)->sample('domestic_cat', $seed)['traits'];

            if (str_contains($t['coat_pattern'], 'tabby')) {
                expect($t['coat_color'])->toBeIn(['brown', 'ginger', 'grey-blue', 'silver']);
            }
            if ($t['coat_pattern'] === 'solid') {
                expect($t['coat_color'])->toBeIn(['black', 'white', 'grey-blue']);
            }
            if (str_contains($t['eye_color'], 'blue')) {
                expect($t['coat_color'])->toBe('white');
            }
            if ($t['markings'] !== 'no special markings') {
                expect($t['coat_color'])->toBeIn(['brown', 'ginger', 'silver']);
            }
        }
    });
});

/* ─────────────────────────── Pairing / DNA v1 ─────────────────────────── */

describe('cat DNA', function () {
    it('gives a new cat DNA v2 even when the legacy DNA v1 is configured; a dog keeps v1', function () {
        config(['petprep.cats_enabled' => true, 'media.pet_dna_version' => 1]);
        Queue::fake();
        $parent = User::factory()->parent()->create();

        $pin = function (array $body) use ($parent) {
            $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
            app('auth')->forgetGuards();
            actingAsRole($parent);

            return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body))->assertOk()->json('pin');
        };
        $login = function (string $pin, array $features) {
            app('auth')->forgetGuards();
            test()->withHeaders(['Authorization' => '']);

            return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => $features])->assertOk();
        };

        $catPin = $pin(['species' => 'cat', 'breed' => 'domestic_cat', 'origin' => 'bought', 'age_stage' => 'puppy', 'plan' => 'free', 'features' => ['species_cat']]);
        $cat = Pet::findOrFail($login($catPin, ['species_cat'])->json('pet.id'));

        expect($cat->pet_dna['version'])->toBe(2)
            ->and($cat->pet_dna['prompt'])->toContain('domestic mixed-breed cat')
            ->and(camHasDog($cat->pet_dna['prompt']))->toBeFalse()
            ->and($cat->media_status)->toBe('pending');

        $dogPin = $pin(['breed' => 'mutt', 'origin' => 'bought', 'age_stage' => 'puppy', 'plan' => 'free']);
        $dog = Pet::findOrFail($login($dogPin, [])->json('pet.id'));

        expect($dog->pet_dna)->not->toHaveKey('version')
            ->and($dog->pet_dna['prompt_anchor'])->toContain('mutt dog');
    });

    it('refuses a cat on every legacy DNA v1 path', function () {
        $cat = camCat('maine_coon');
        $v1Cat = (new Pet)->forceFill(['breed_type' => 'domestic_cat', 'pet_dna' => ['seed' => 1, 'prompt_anchor' => 'x']]);

        expect(fn () => app(FalAiService::class)->generateInitialPetDna(BreedType::MaineCoon))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(FalAiService::class)->generateInitialPetDna(BreedType::DomesticCat))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(PetAppearancePrompt::class)->legacyPromptForPet($cat))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(PetAppearancePrompt::class)->imagePromptForPet($v1Cat))->toThrow(InvalidArgumentException::class);
    });

    it('leaves a cat without DNA v2 (created before cat media) without media and calls nobody', function () {
        $cat = camCat('domestic_cat');
        $cat->forceFill(['pet_dna' => null])->saveQuietly();

        expect(app(PetMediaService::class)->generateReferenceImage($cat->fresh()))->toBeTrue()
            ->and($cat->fresh()->media_status)->toBe('disabled')
            ->and(PetMedia::where('pet_id', $cat->id)->count())->toBe(0);
        Http::assertNothingSent();
    });
});

/* ─────────────────────────── Pipeline + entitlement ─────────────────────────── */

describe('cat media pipeline', function () {
    it('generates the cat reference image with the cat prompt (fake fal)', function () {
        Queue::fake([StorePetMedia::class]);
        Http::fake(['fal.run/fal-ai/nano-banana-pro' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/cat/ref.jpg']]])]);
        $cat = camCat('maine_coon', 'puppy');

        expect(app(PetMediaService::class)->generateReferenceImage($cat))->toBeTrue();

        Http::assertSent(function (Request $request) {
            $prompt = (string) ($request->data()['prompt'] ?? '');

            return str_contains($request->url(), 'fal-ai/nano-banana-pro')
                && str_contains($prompt, 'Maine Coon cat')
                && str_contains($prompt, 'The cat is a young kitten')
                && ! camHasDog($prompt);
        });
        Queue::assertPushed(StorePetMedia::class, 1);
    });

    it('gives a purchased Maine Coon the six classic states + scratching, never accident / chewing', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        $cat = camCat('maine_coon', 'puppy');
        camStoredImage($cat);

        expect(app(MediaEntitlementService::class)->tierFor($cat))->toBe('full')
            ->and(array_map(fn ($s) => $s->value, app(MediaEntitlementService::class)->videoStatesFor($cat)))
            ->toBe(['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'scratching'])
            ->and(app(PetMediaService::class)->queueStateVideos($cat))->toBe(7);
        Queue::assertPushed(SubmitPetStateVideo::class, 7);
    });

    it('gives a free domestic cat the basic set (idle + sleeping) and a dog never gets scratching', function () {
        $cat = camCat('domestic_cat');
        $dogParent = createParentUser();
        $dog = Pet::factory()->purchased()->create(['user_id' => createChildUser(['parent_id' => $dogParent->id])->id, 'breed_type' => 'border_collie', 'arrival_age_months' => 2, 'life_stage' => 'puppy', 'origin' => 'bought', 'behaviour_events_enabled' => true]);
        $legacyCat = camCat('maine_coon', legacy: true);
        $entitlements = app(MediaEntitlementService::class);

        expect(array_map(fn ($s) => $s->value, $entitlements->videoStatesFor($cat)))->toBe(['idle', 'sleeping'])
            ->and(array_map(fn ($s) => $s->value, $entitlements->videoStatesFor($dog)))
            ->toBe(['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'accident', 'chewing'])
            ->and($entitlements->behaviourApplies($legacyCat, PetStateEnum::Scratching))->toBeFalse();
    });

    it('submits the scratching video with the cat prompt and the cat negative prompt (fake fal)', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        Http::fake(['queue.fal.run/fal-ai/kling-video/v3/pro/image-to-video*' => Http::response(['request_id' => 'req-scratch'])]);
        $cat = camCat('maine_coon');
        camStoredImage($cat);
        app(PetMediaService::class)->queueStateVideos($cat);
        $slot = PetMedia::where('pet_id', $cat->id)->where('state', 'scratching')->sole();

        app(PetMediaService::class)->submitVideo($slot->id);

        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $prompt = (string) ($body['prompt'] ?? '');

            return str_contains($prompt, 'scratching the side of a fabric sofa')
                && str_contains($prompt, 'Maine Coon cat')
                && ! camHasDog($prompt)
                && ! camHasDog((string) ($body['negative_prompt'] ?? ''));
        });
        expect($slot->fresh()->request_id)->toBe('req-scratch');
    });

    it('serves a stored scratching video to a cat and never a chewing / accident video', function () {
        $cat = camCat('maine_coon');
        camStoredImage($cat);
        foreach (['idle', 'scratching', 'chewing', 'accident'] as $state) {
            $path = "{$cat->id}/{$state}-g1.mp4";
            Storage::disk('pet_media')->put($path, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 512));
            PetMedia::create(['pet_id' => $cat->id, 'kind' => 'video', 'state' => $state, 'status' => 'ready', 'generation' => 1,
                'source_generation' => 1, 'storage_path' => $path, 'mime' => 'video/mp4', 'bytes' => 536, 'profile' => 'kling_v3_pro']);
        }

        $videos = (array) app(PetMediaService::class)->payloadFor($cat->fresh())['videos'];

        expect(array_keys($videos))->toBe(['idle', 'scratching']);
    });
});

/* ─────────────────────────── AI Lab ─────────────────────────── */

describe('AI Lab for cats', function () {
    it('runs a Maine Coon image run with the kitten stage and the cat negative prompt', function () {
        Queue::fake();

        $run = app(MediaLabService::class)->startImageRun(camSuperadmin(), 'maine_coon', ['coat_color' => 'silver'], 2, ['flux2_pro'], LifeStage::Puppy);

        expect($run->results)->toHaveCount(2);
        foreach ($run->results as $result) {
            expect($result->prompt)->toContain('Maine Coon cat')->toContain('silver')
                ->toContain('The cat is a young kitten')->toContain('As a Maine Coon kitten')
                ->and(camHasDog($result->prompt))->toBeFalse()
                ->and($result->traits['coat_color'])->toBe('silver');
        }
        Queue::assertPushed(RunMediaLabImage::class, 2);
        Http::assertNothingSent();
    });

    it('keeps the stored DNA prompt when no stage is picked', function () {
        Queue::fake();
        $run = app(MediaLabService::class)->startImageRun(camSuperadmin(), 'domestic_cat', [], 1, ['flux2_pro']);
        $result = $run->results->first();

        expect($result->prompt)->toBe(app(PetAppearancePrompt::class)->imagePrompt('domestic_cat', $result->traits));
    });

    it('animates a cat lab image only with a cat state', function () {
        Queue::fake();
        $lab = app(MediaLabService::class);
        $catImage = camLabImage('maine_coon', ['coat_color' => 'brown']);
        $dogImage = camLabImage('border_collie', ['coat_color' => 'black and white']);

        expect(fn () => $lab->startVideoRun(camSuperadmin(), $catImage->id, ['kling_v3_pro'], PetStateEnum::Chewing))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $lab->startVideoRun(camSuperadmin(), $catImage->id, ['kling_v3_pro'], PetStateEnum::Accident))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $lab->startVideoRun(camSuperadmin(), $dogImage->id, ['kling_v3_pro'], PetStateEnum::Scratching))->toThrow(InvalidArgumentException::class);

        $run = $lab->startVideoRun(camSuperadmin(), $catImage->id, ['kling_v3_pro'], PetStateEnum::Scratching);
        $video = $run->results->first();

        expect($run->pet_state)->toBe('scratching')
            ->and($video->prompt)->toContain('scratching the side of a fabric sofa')->toContain('Maine Coon cat')
            ->and(camHasDog($video->prompt))->toBeFalse()
            ->and(camHasDog((string) ($video->params['negative_prompt'] ?? '')))->toBeFalse();
        Http::assertNothingSent();
    });

    it('estimates a pet\'s media cost per species with the production models (no fal call)', function () {
        $lab = app(MediaLabService::class);

        // nano_banana_pro 0.15 $ / image, kling_v3_pro 0.112 $ / s × 5 s = 0.56 $ / video (CAT_SPEC §8: ≈ 1.27 $ / 4.07 $).
        expect($lab->perPetCostUsd(Species::Cat, 'basic'))->toBe(['images' => 1, 'videos' => 2, 'usd' => 1.27])
            ->and($lab->perPetCostUsd(Species::Cat, 'full'))->toBe(['images' => 1, 'videos' => 7, 'usd' => 4.07])
            ->and($lab->perPetCostUsd(Species::Dog, 'full'))->toBe(['images' => 1, 'videos' => 8, 'usd' => 4.63]);
        Http::assertNothingSent();
    });

    it('offers the cat breeds, the stage and the per-pet cost on the Filament page', function () {
        Queue::fake();
        actingAs(camSuperadmin());

        $this->get('/admin/ai-lab')->assertOk()
            ->assertSee('Maine Coon cat')
            ->assertSee('domestic mixed-breed cat')
            ->assertSee('Per-pet cost')
            ->assertSee('Cat: free set 1 image + 2 videos ~$1.27')
            ->assertSee('(cats only)')
            ->assertSee('(dogs only)');

        Livewire::test(AiLab::class)
            ->set('imageData.breed', 'maine_coon')
            ->set('imageData.samples', 1)
            ->set('imageData.stage', 'young')
            ->set('imageData.profiles', ['flux_schnell'])
            ->call('generateImages')
            ->assertHasNoErrors()
            ->assertNotified('Image run queued');

        expect(MediaLabRun::sole()->breed)->toBe('maine_coon')
            ->and(MediaLabResult::sole()->prompt)->toContain('As a young Maine Coon');
    });
});

/* ─────────────────────────── Migration ─────────────────────────── */

describe('migration 2026_10_28_120000_add_cat_scratching_video', function () {
    it('down() removes only scratching slots and restores the check; up() allows them again', function () {
        $migration = require database_path('migrations/2026_10_28_120000_add_cat_scratching_video.php');
        $cat = camCat('maine_coon');
        $slot = fn (string $state) => DB::table('pet_media')->insert(['pet_id' => $cat->id, 'kind' => 'video', 'state' => $state,
            'status' => 'pending', 'generation' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $slot('idle');
        $slot('scratching');

        $migration->down();
        expect(DB::table('pet_media')->where('pet_id', $cat->id)->pluck('state')->all())->toBe(['idle'])
            ->and(fn () => DB::transaction(fn () => $slot('scratching')))->toThrow(QueryException::class);

        $migration->up();
        $slot('scratching');
        expect(DB::table('pet_media')->where('pet_id', $cat->id)->count())->toBe(2)
            // pets.pet_state never takes scratching (video slots only).
            ->and(fn () => DB::transaction(fn () => DB::table('pets')->where('id', $cat->id)->update(['pet_state' => 'scratching'])))
            ->toThrow(QueryException::class);
    });
});
