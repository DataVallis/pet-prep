<?php

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Filament\Pages\AiLab;
use App\Filament\Widgets\AiSpendOverview;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\PollMediaLabResult;
use App\Jobs\RunMediaLabImage;
use App\Jobs\SubmitMediaLabVideo;
use App\Models\AiSpendLedger;
use App\Models\MediaLabResult;
use App\Models\MediaLabRun;
use App\Models\Pet;
use App\Models\User;
use App\Services\FalAiService;
use App\Services\FalWebhookVerifier;
use App\Services\Media\AiCallException;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaLabService;
use App\Services\Media\MediaProfiles;
use App\Services\Media\ModelProfile;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetDnaService;
use App\Services\PairingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M4 part A — model profiles (M4-02), Pet DNA v2 (M4-08), spend caps (M4-07),
| AI Lab (admin only)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Http::preventStrayRequests(); // never reach the real fal.ai from tests
    Cache::forget(FalWebhookVerifier::CACHE_KEY);
    Cache::forget(FalGateway::BALANCE_FLAG_KEY);
    Cache::forget('ai:fal_balance_logged');
    config([
        'services.fal_ai.key' => 'test-key',
        'app.url' => 'https://api.petprep.si',
        'media.budget.daily_usd' => 5.0,
        'media.budget.monthly_usd' => 50.0,
        'media.budget.timezone' => 'UTC',
        'media.lab.max_run_usd' => 3.0,
    ]);
});

function aiSuperadmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

function aiSpend(float $usd, string $status = AiSpendLedger::STATUS_COMMITTED, ?Carbon $at = null): AiSpendLedger
{
    $row = AiSpendLedger::create([
        'purpose' => 'lab',
        'profile' => 'flux2_pro',
        'endpoint' => 'fal-ai/flux-2-pro',
        'unit' => 'image',
        'units' => 1,
        'cost_usd' => $usd,
        'status' => $status,
    ]);

    if ($at) {
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }

    return $row;
}

function aiCompletedLabImage(string $profile = 'flux2_pro', string $url = 'https://v3.fal.media/files/lab/dog.jpg'): MediaLabResult
{
    $run = MediaLabRun::create([
        'user_id' => aiSuperadmin()->id,
        'kind' => 'image',
        'breed' => 'border_collie',
        'samples' => 1,
        'profiles' => [$profile],
    ]);

    return $run->results()->create([
        'kind' => 'image',
        'profile' => $profile,
        'endpoint' => 'fal-ai/flux-2-pro',
        'sample_index' => 0,
        'seed' => 42,
        'traits' => ['coat_color' => 'black and white'],
        'prompt' => 'p',
        'status' => MediaLabResult::STATUS_COMPLETED,
        'result_url' => $url,
    ]);
}

/* ─────────────────────────── Profiles (M4-02) ─────────────────────────── */

describe('model profiles', function () {
    it('parses every configured profile with a fal endpoint id and a price', function () {
        $profiles = app(MediaProfiles::class);

        foreach ([ModelProfile::KIND_IMAGE, ModelProfile::KIND_VIDEO] as $kind) {
            foreach ($profiles->all($kind) as $key => $profile) {
                expect($profile->endpoint)->toMatch('#^[a-z0-9.-]+(/[a-z0-9.-]+)+$#')
                    ->and($profile->estimatedCostUsd())->toBeGreaterThan(0);

                if ($profile->verified) {
                    expect($profile->source)->toContain('https://fal.ai/models/');
                }
            }
        }

        expect(array_keys($profiles->all('image')))->toBe(['flux_schnell', 'flux2_pro', 'nano_banana_pro', 'seedream_v5_lite'])
            ->and(array_keys($profiles->all('video')))->toBe(['kling_v16_legacy', 'kling_v3_pro', 'kling_v26_pro', 'veo31_fast', 'veo31_lite']);
    });

    it('keeps the pre-M4 models as production defaults', function () {
        $profiles = app(MediaProfiles::class);

        expect($profiles->referenceImage()->endpoint)->toBe('fal-ai/flux/schnell')
            ->and($profiles->stateVideo()->endpoint)->toBe('fal-ai/kling-v1.6/pro/image-to-video');
    });

    it('keeps the deprecated legacy video profile out of the lab', function () {
        expect(array_keys(app(MediaProfiles::class)->forLab('video')))->not->toContain('kling_v16_legacy')
            ->and(array_keys(app(MediaProfiles::class)->forLab('image')))->toContain('flux_schnell', 'flux2_pro', 'nano_banana_pro', 'seedream_v5_lite');
    });

    it('estimates cost per call from the unit price', function (string $kind, string $key, float $usd) {
        expect(app(MediaProfiles::class)->get($kind, $key)->estimatedCostUsd())->toEqualWithDelta($usd, 1e-9);
    })->with([
        'flux schnell 1024² = 2 started MP' => ['image', 'flux_schnell', 0.006],
        'flux 2 pro 576×1024 = 1 MP' => ['image', 'flux2_pro', 0.03],
        'nano banana pro per image' => ['image', 'nano_banana_pro', 0.15],
        'seedream 5 lite per image' => ['image', 'seedream_v5_lite', 0.035],
        'kling 3 pro 5 s' => ['video', 'kling_v3_pro', 0.56],
        'kling 2.6 pro 5 s' => ['video', 'kling_v26_pro', 0.35],
        'veo 3.1 fast 4 s' => ['video', 'veo31_fast', 0.40],
        'veo 3.1 lite 4 s' => ['video', 'veo31_lite', 0.12],
    ]);

    it('charges additional megapixels at the additional rate', function () {
        config(['media.profiles.image.flux2_pro.width' => 1440, 'media.profiles.image.flux2_pro.height' => 1440]);

        // 2.07 MP → 3 started MP: 0.03 + 2 × 0.015
        expect(app(MediaProfiles::class)->image('flux2_pro')->estimatedCostUsd())->toEqualWithDelta(0.06, 1e-9);
    });

    it('rejects an endpoint that is not a fal model id (env override)', function () {
        config(['media.profiles.image.flux2_pro.endpoint' => 'https://evil.example/x']);

        app(MediaProfiles::class)->image('flux2_pro');
    })->throws(InvalidArgumentException::class);

    it('builds model input with seed and negative prompt only where supported', function () {
        $profiles = app(MediaProfiles::class);

        expect($profiles->image('nano_banana_pro')->imageInput('a dog', 7, 'cartoon'))
            ->toMatchArray(['prompt' => 'a dog', 'seed' => 7, 'aspect_ratio' => '9:16'])
            ->not->toHaveKey('negative_prompt');

        expect($profiles->video('kling_v3_pro')->videoInput('https://v3.fal.media/x.jpg', 'sits', 'cartoon'))
            ->toMatchArray(['start_image_url' => 'https://v3.fal.media/x.jpg', 'prompt' => 'sits', 'generate_audio' => false, 'negative_prompt' => 'cartoon']);

        expect($profiles->video('veo31_lite')->videoInput('https://v3.fal.media/x.jpg', 'sits'))
            ->toMatchArray(['image_url' => 'https://v3.fal.media/x.jpg', 'duration' => '4s', 'generate_audio' => false, 'aspect_ratio' => '9:16']);
    });
});

/* ─────────────────────────── Pet DNA v2 (M4-08) ─────────────────────────── */

describe('pet DNA v2', function () {
    it('samples the same traits for the same seed and different ones across seeds', function () {
        $dna = app(PetDnaService::class);

        expect($dna->sample('mutt', 123456))->toBe($dna->sample('mutt', 123456));

        $fingerprints = collect(range(1, 30))->map(fn ($seed) => $dna->sample('mutt', $seed)['fingerprint'])->unique();
        expect($fingerprints->count())->toBeGreaterThanOrEqual(28);
    });

    it('only samples options of the breed and respects conditional options', function () {
        $dna = app(PetDnaService::class);
        $options = $dna->optionsFor('border_collie');

        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('border_collie', $seed)['traits'];

            foreach ($traits as $trait => $value) {
                expect($options[$trait])->toContain($value);
            }

            if ($traits['eye_color'] === 'blue') {
                expect($traits['coat_color'])->toBe('blue merle and white');
            }
            if ($traits['eye_color'] === 'one blue and one brown') {
                expect($traits['coat_color'])->toContain('merle');
            }
        }
    });

    it('honours fixed traits and rejects values outside the breed options', function () {
        $dna = app(PetDnaService::class);

        expect($dna->sample('border_collie', 9, ['coat_color' => 'red merle and white'])['traits']['coat_color'])->toBe('red merle and white');
        expect(fn () => $dna->sample('border_collie', 9, ['coat_color' => 'purple']))->toThrow(InvalidArgumentException::class);
    });

    it('marks all breed appearance data as unverified until M1-19', function () {
        foreach (config('breed_appearance') as $breed => $data) {
            expect($data['verified'])->toBeFalse()
                ->and(BreedType::tryFrom($breed))->not->toBeNull();
        }
    });

    it('gives a newly paired pet a v2 DNA with a breed + traits prompt and no personal data', function () {
        Queue::fake();
        seedBreedConfigs();
        $parent = createParentUser(['name' => 'Mojca Novak']);
        $child = createChildUser(['name' => 'Lukec Novak']);
        $pin = app(PairingService::class)->generatePin($parent)['pin'];

        $this->actingAs($child)->postJson('/api/child/pair', ['pin' => $pin])->assertCreated();

        $dna = $child->activePet()->pet_dna;

        expect($dna['version'])->toBe(2)
            ->and($dna['breed'])->toBe('mutt')
            ->and($dna['appearance_verified'])->toBeFalse()
            ->and($dna['seed'])->toBe(PetDnaService::seedFor($child->activePet()->id, $dna['salt']))
            ->and($dna['traits'])->toHaveKeys(['size', 'coat_color', 'eye_color', 'ear_carriage'])
            ->and($dna['prompt_anchor'])->toBe($dna['prompt'])
            ->and($dna['visual_traits'])->toBe($dna['traits'])
            ->and($dna['reference_image_url'])->toBeNull();

        expect($dna['prompt'])
            ->toContain('mixed-breed dog')
            ->toContain('photorealistic')
            ->toContain($dna['traits']['coat_color'])
            ->toContain($dna['traits']['ear_carriage'].' ears')
            ->toContain('no people, no children')
            ->toContain('no watermark')
            ->not->toContain('Lukec')
            ->not->toContain('Mojca')
            ->not->toContain('Novak');
        expect($dna['negative_prompt'])->toContain('watermark');
    });

    it('never gives two v2 pets of one family the same trait combination', function () {
        $parent = createParentUser();
        $child = createChildUser(['parent_id' => $parent->id]);
        $pet = Pet::factory()->create(['user_id' => $child->id, 'breed_type' => 'mutt']);
        $service = app(PetDnaService::class);

        // What the pet would get with this salt on its own…
        $alone = $service->forNewPet($pet, 777);
        expect($alone['attempt'])->toBe(0);

        // …is already taken by a sibling pet of the same family → another combination.
        $sibling = Pet::factory()->create(['user_id' => createChildUser(['parent_id' => $parent->id])->id, 'breed_type' => 'mutt', 'is_active' => true]);
        expect($sibling->family_id)->toBe($pet->family_id);
        $sibling->forceFill(['pet_dna' => $alone])->saveQuietly();

        $again = $service->forNewPet($pet, 777);
        expect($again['attempt'])->toBeGreaterThan(0)
            ->and($again['trait_fingerprint'])->not->toBe($alone['trait_fingerprint'])
            ->and($again['seed'])->toBe($alone['seed']);

        // A pet with the same traits in ANOTHER family does not matter.
        $otherFamilyPet = Pet::factory()->create(['user_id' => createChildUser(['parent_id' => createParentUser()->id])->id, 'breed_type' => 'mutt']);
        $sibling->forceFill(['pet_dna' => null])->saveQuietly();
        $otherFamilyPet->forceFill(['pet_dna' => $alone])->saveQuietly();

        expect($service->forNewPet($pet, 777)['attempt'])->toBe(0);
    });

    it('leaves existing v1 DNA untouched and can fall back to v1 for new pets', function () {
        Queue::fake();
        seedBreedConfigs();
        $legacy = Pet::factory()->withPetDna(['prompt_anchor' => 'old anchor'])->create(['user_id' => createChildUser()->id]);
        $before = $legacy->pet_dna;

        config(['media.pet_dna_version' => 1]);
        $parent = createParentUser();
        $child = createChildUser();
        $pin = app(PairingService::class)->generatePin($parent)['pin'];
        $this->actingAs($child)->postJson('/api/child/pair', ['pin' => $pin])->assertCreated();

        expect($child->activePet()->pet_dna)->not->toHaveKey('version')
            ->and($child->activePet()->pet_dna['prompt_anchor'])->toContain('mutt')
            ->and($legacy->fresh()->pet_dna)->toEqual($before); // jsonb does not keep key order
    });

    it('builds a video prompt from breed and state only', function () {
        $prompt = app(PetAppearancePrompt::class)->videoPrompt('border_collie', PetStateEnum::Sleeping);

        expect($prompt)->toContain('Border Collie')->toContain('sleeping')->toContain('No people');
    });
});

/* ─────────────────────────── Spend caps + ledger (M4-07) ─────────────────────────── */

describe('spend caps and ledger', function () {
    it('records the reference image call in the ledger and keeps the pre-M4 request body', function () {
        Http::fake(['fal.run/fal-ai/flux/schnell' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/ref.jpg']]])]);
        $pet = Pet::factory()->withPetDna(['seed' => 1234, 'prompt_anchor' => 'A dog'])->create(['user_id' => createChildUser()->id, 'media_status' => 'pending']);

        (new GeneratePetReferenceImage($pet->id))->handle(app(FalAiService::class));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/flux/schnell' && $r->data() === [
            'prompt' => 'A dog',
            'seed' => 1234,
            'image_size' => ['width' => 1024, 'height' => 1024],
            'num_inference_steps' => 4,
            'num_images' => 1,
            'enable_safety_checker' => true,
        ]);

        expect(AiSpendLedger::sole())
            ->purpose->toBe('reference_image')
            ->profile->toBe('flux_schnell')
            ->status->toBe('committed')
            ->pet_id->toBe($pet->id)
            ->cost_usd->toEqualWithDelta(0.006, 1e-9);
        expect($pet->fresh())->media_status->toBe('ready')->media_error->toBeNull();
    });

    it('refuses the call before any HTTP when the daily cap is reached; the pet keeps working without media', function () {
        Http::fake();
        aiSpend(4.999);
        $pet = Pet::factory()->withPetDna()->create(['user_id' => createChildUser()->id, 'media_status' => 'pending']);

        (new GeneratePetReferenceImage($pet->id))->handle(app(FalAiService::class)); // no exception → no queue retry

        Http::assertNothingSent();
        expect($pet->fresh())->media_status->toBe('failed')->media_error->toBe('budget_daily')
            ->and(AiSpendLedger::count())->toBe(1);
    });

    it('refuses when the monthly cap is reached even if today is empty', function () {
        Http::fake();
        Carbon::setTestNow('2026-10-20 12:00:00');
        aiSpend(49.999, at: Carbon::parse('2026-10-03 10:00:00'));

        expect(fn () => app(AiSpendGuard::class)->reserve(app(MediaProfiles::class)->image('flux_schnell'), AiSpendPurpose::Lab))
            ->toThrow(fn (AiCallException $e) => expect($e->reason)->toBe(AiCallFailure::BudgetMonthly));
    });

    it('counts reserved and committed rows, not void ones, and resets the daily cap at the budget midnight', function () {
        Carbon::setTestNow('2026-10-20 23:30:00');
        config(['media.budget.daily_usd' => 1.0]);
        $guard = app(AiSpendGuard::class);
        aiSpend(0.5);
        aiSpend(0.4, AiSpendLedger::STATUS_RESERVED);
        aiSpend(10, AiSpendLedger::STATUS_VOID);

        expect($guard->spentTodayUsd())->toEqualWithDelta(0.9, 1e-9)
            ->and($guard->refusalFor(0.15))->toBe(AiCallFailure::BudgetDaily)
            ->and($guard->refusalFor(0.1))->toBeNull();

        Carbon::setTestNow('2026-10-21 00:00:01');
        expect($guard->spentTodayUsd())->toEqualWithDelta(0.0, 1e-9)
            ->and($guard->spentThisMonthUsd())->toEqualWithDelta(0.9, 1e-9);
    });

    it('fails closed with a zero cap', function () {
        config(['media.budget.daily_usd' => 0]);

        expect(app(AiSpendGuard::class)->refusalFor(0.001))->toBe(AiCallFailure::BudgetDaily);
    });

    it('voids the reservation when fal fails and lets the queue retry', function () {
        Http::fake(['fal.run/*' => Http::response(['detail' => 'boom'], 500)]);
        $pet = Pet::factory()->withPetDna()->create(['user_id' => createChildUser()->id, 'media_status' => 'pending']);

        expect(fn () => (new GeneratePetReferenceImage($pet->id))->handle(app(FalAiService::class)))->toThrow(RuntimeException::class);

        expect(AiSpendLedger::sole())->status->toBe('void')->error_reason->toBe('http_error')
            ->and(app(AiSpendGuard::class)->spentTodayUsd())->toEqualWithDelta(0.0, 1e-9);
    });

    it('detects an exhausted fal balance, marks the pet, flags Filament and logs once', function () {
        Http::fake(['fal.run/*' => Http::response(['detail' => 'User is locked. Reason: Exhausted balance. Top up your balance at fal.ai/dashboard/billing.'], 403)]);
        Log::spy();
        $a = Pet::factory()->withPetDna()->create(['user_id' => createChildUser()->id, 'media_status' => 'pending']);
        $b = Pet::factory()->withPetDna()->create(['user_id' => createChildUser()->id, 'media_status' => 'pending']);

        (new GeneratePetReferenceImage($a->id))->handle(app(FalAiService::class));
        (new GeneratePetReferenceImage($b->id))->handle(app(FalAiService::class));

        expect($a->fresh())->media_status->toBe('failed')->media_error->toBe('fal_balance')
            ->and($b->fresh()->media_error)->toBe('fal_balance')
            ->and(AiSpendLedger::where('status', 'void')->where('error_reason', 'fal_balance')->count())->toBe(2)
            ->and(FalGateway::balanceExhaustedAt())->not->toBeNull();
        Log::shouldHaveReceived('critical')->once();
    });

    it('treats HTTP 402 as an exhausted balance too', function () {
        Http::fake(['fal.run/*' => Http::response('Payment Required', 402)]);

        expect(fn () => app(FalGateway::class)->run(app(MediaProfiles::class)->image('flux_schnell'), ['prompt' => 'x'], AiSpendPurpose::Lab))
            ->toThrow(fn (AiCallException $e) => expect($e->reason)->toBe(AiCallFailure::FalBalance));
    });

    it('records state video submissions with the default legacy profile unchanged', function () {
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'req-sv', 'status' => 'IN_QUEUE'])]);
        $pet = Pet::factory()->withPetDna(['reference_image_url' => 'https://v3.fal.media/files/ref.jpg'])->create(['user_id' => createChildUser()->id]);

        expect(app(FalAiService::class)->generatePetVideoState($pet, PetStateEnum::Idle))->toBe('req-sv');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/fal-ai/kling-v1.6/pro/image-to-video?fal_webhook=')
            && $r['image_url'] === 'https://v3.fal.media/files/ref.jpg' && $r['duration'] === '5' && $r['aspect_ratio'] === '9:16' && $r['cfg_scale'] === 0.7);
        expect(AiSpendLedger::sole())->purpose->toBe('state_video')->request_id->toBe('req-sv')->cost_usd->toEqualWithDelta(0.475, 1e-9);
    });
});

/* ─────────────────────────── AI Lab ─────────────────────────── */

describe('AI lab', function () {
    it('queues samples × profiles with one shared trait set per sample', function () {
        Queue::fake();

        $run = app(MediaLabService::class)->startImageRun(aiSuperadmin(), 'border_collie', ['coat_color' => 'blue merle and white'], 3, ['flux2_pro', 'seedream_v5_lite']);

        expect($run->results)->toHaveCount(6)
            ->and($run->estimated_cost_usd)->toEqualWithDelta(3 * (0.03 + 0.035), 1e-9);

        $bySample = $run->results->groupBy('sample_index');
        expect($bySample)->toHaveCount(3);
        foreach ($bySample as $pair) {
            expect($pair[0]->traits)->toBe($pair[1]->traits)
                ->and($pair[0]->seed)->toBe($pair[1]->seed)
                ->and($pair[0]->traits['coat_color'])->toBe('blue merle and white');
        }
        expect($bySample->map(fn ($p) => PetDnaService::fingerprint('border_collie', $p[0]->traits))->unique())->toHaveCount(3);

        Queue::assertPushed(RunMediaLabImage::class, 6);
    });

    it('generates a lab image, stores url, latency and cost, and books the ledger', function () {
        Queue::fake();
        Http::fake(['fal.run/fal-ai/flux-2-pro' => Http::response(['images' => [['url' => 'https://v3.fal.media/files/lab/1.jpg']], 'seed' => 5])]);
        $run = app(MediaLabService::class)->startImageRun(aiSuperadmin(), 'mutt', [], 1, ['flux2_pro']);
        $result = $run->results->first();

        (new RunMediaLabImage($result->id))->handle(app(FalGateway::class), app(MediaProfiles::class), app(FalAiService::class));

        expect($result->fresh())
            ->status->toBe('completed')
            ->result_url->toBe('https://v3.fal.media/files/lab/1.jpg')
            ->latency_ms->toBeInt()
            ->estimated_cost_usd->toEqualWithDelta(0.03, 1e-9);
        expect(AiSpendLedger::sole())->purpose->toBe('lab')->media_lab_result_id->toBe($result->id)->status->toBe('committed');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/flux-2-pro'
            && $r['image_size'] === 'portrait_16_9' && $r['seed'] === $result->seed && str_contains($r['prompt'], 'mixed-breed dog'));

        // A duplicate delivery does not call fal again.
        (new RunMediaLabImage($result->id))->handle(app(FalGateway::class), app(MediaProfiles::class), app(FalAiService::class));
        Http::assertSentCount(1);
    });

    it('marks a lab image failed on exhausted balance and on an untrusted URL', function () {
        Queue::fake();
        Http::fake([
            'fal.run/fal-ai/flux-2-pro' => Http::response(['detail' => 'Exhausted balance'], 403),
            'fal.run/fal-ai/nano-banana-pro' => Http::response(['images' => [['url' => 'https://evil.example/x.jpg']]]),
        ]);
        $run = app(MediaLabService::class)->startImageRun(aiSuperadmin(), 'mutt', [], 1, ['flux2_pro', 'nano_banana_pro']);

        foreach ($run->results as $r) {
            (new RunMediaLabImage($r->id))->handle(app(FalGateway::class), app(MediaProfiles::class), app(FalAiService::class));
        }

        $results = $run->results()->get()->keyBy('profile');
        expect($results['flux2_pro'])->status->toBe('failed')->error_reason->toBe('fal_balance')->estimated_cost_usd->toEqualWithDelta(0.0, 1e-9)
            ->and($results['nano_banana_pro'])->status->toBe('failed')->error_reason->toBe('invalid_response')->result_url->toBeNull();
    });

    it('fails a queued lab image without HTTP when the cap was used up meanwhile', function () {
        Queue::fake();
        Http::fake();
        $run = app(MediaLabService::class)->startImageRun(aiSuperadmin(), 'mutt', [], 1, ['nano_banana_pro']);
        aiSpend(4.95);

        (new RunMediaLabImage($run->results->first()->id))->handle(app(FalGateway::class), app(MediaProfiles::class), app(FalAiService::class));

        Http::assertNothingSent();
        expect($run->results()->first())->status->toBe('failed')->error_reason->toBe('budget_daily');
    });

    it('refuses a run over the per-run limit or the remaining budget, creating nothing', function () {
        Queue::fake();
        $lab = app(MediaLabService::class);

        config(['media.lab.max_run_usd' => 0.5]);
        expect(fn () => $lab->startImageRun(aiSuperadmin(), 'mutt', [], 4, ['nano_banana_pro']))
            ->toThrow(fn (AiCallException $e) => expect($e->reason)->toBe(AiCallFailure::BudgetRun));

        config(['media.lab.max_run_usd' => 3.0]);
        aiSpend(4.9);
        expect(fn () => $lab->startImageRun(aiSuperadmin(), 'mutt', [], 1, ['nano_banana_pro']))
            ->toThrow(fn (AiCallException $e) => expect($e->reason)->toBe(AiCallFailure::BudgetDaily));

        expect(MediaLabRun::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('rejects non-superadmins, unknown or legacy profiles and too many samples', function () {
        $lab = app(MediaLabService::class);

        expect(fn () => $lab->startImageRun(createParentUser(), 'mutt', [], 1, ['flux2_pro']))->toThrow(AuthorizationException::class)
            ->and(fn () => $lab->startImageRun(aiSuperadmin(), 'mutt', [], 5, ['flux2_pro']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $lab->startImageRun(aiSuperadmin(), 'mutt', [], 1, ['dall_e']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $lab->startVideoRun(aiSuperadmin(), aiCompletedLabImage()->id, ['kling_v16_legacy'], PetStateEnum::Idle))->toThrow(InvalidArgumentException::class);
    });

    it('animates a lab image via the fal queue and completes it from the signed webhook', function () {
        Queue::fake();
        fakeFalJwks();
        Http::fake(['queue.fal.run/fal-ai/kling-video/v3/pro/image-to-video*' => Http::response([
            'request_id' => 'lab-req-1',
            'status_url' => 'https://queue.fal.run/fal-ai/kling-video/requests/lab-req-1/status',
            'response_url' => 'https://queue.fal.run/fal-ai/kling-video/requests/lab-req-1',
        ])]);
        $image = aiCompletedLabImage();

        $run = app(MediaLabService::class)->startVideoRun(aiSuperadmin(), $image->id, ['kling_v3_pro'], PetStateEnum::Playing);
        $video = $run->results->first();
        expect($run)->pet_state->toBe('playing')->source_result_id->toBe($image->id)
            ->and($run->estimated_cost_usd)->toEqualWithDelta(0.56, 1e-9);
        Queue::assertPushed(SubmitMediaLabVideo::class, 1);

        (new SubmitMediaLabVideo($video->id))->handle(app(FalGateway::class), app(MediaProfiles::class), app(FalAiService::class));

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/fal-ai/kling-video/v3/pro/image-to-video?fal_webhook='.urlencode('https://api.petprep.si/api/webhooks/fal-ai'))
            && $r['start_image_url'] === 'https://v3.fal.media/files/lab/dog.jpg'
            && $r['generate_audio'] === false
            && str_contains($r['prompt'], 'running joyfully'));
        expect($video->fresh())->status->toBe('running')->request_id->toBe('lab-req-1')->estimated_cost_usd->toEqualWithDelta(0.56, 1e-9);
        expect(AiSpendLedger::sole())->purpose->toBe('lab')->status->toBe('committed')->request_id->toBe('lab-req-1')->media_lab_result_id->toBe($video->id);

        sendFalWebhook([
            'request_id' => 'lab-req-1',
            'status' => 'OK',
            'payload' => ['video' => ['url' => 'https://v3.fal.media/files/lab/dog.mp4']],
        ])->assertOk()->assertJson(['message' => 'Lab result recorded.']);

        expect($video->fresh())->status->toBe('completed')->result_url->toBe('https://v3.fal.media/files/lab/dog.mp4');

        // Repeated delivery is acknowledged and changes nothing.
        sendFalWebhook([
            'request_id' => 'lab-req-1',
            'status' => 'ERROR',
            'error' => 'late',
        ])->assertOk()->assertJson(['message' => 'Already processed.']);
        expect($video->fresh()->status)->toBe('completed');
    });

    it('records a failed lab video from an ERROR webhook and still 404s unknown request ids', function () {
        fakeFalJwks();
        $image = aiCompletedLabImage();
        $video = MediaLabResult::create([
            'media_lab_run_id' => $image->media_lab_run_id,
            'kind' => 'video',
            'profile' => 'veo31_lite',
            'endpoint' => 'fal-ai/veo3.1/lite/image-to-video',
            'prompt' => 'p',
            'request_id' => 'lab-req-2',
            'status' => 'running',
        ]);

        sendFalWebhook(['request_id' => 'lab-req-2', 'status' => 'ERROR', 'error' => 'content policy'])->assertOk();
        expect($video->fresh())->status->toBe('failed')->error_reason->toBe('generation_failed')->error->toBe('content policy');

        sendFalWebhook(['request_id' => 'nobody', 'status' => 'OK', 'payload' => []])->assertNotFound();
    });

    it('can poll a running lab video when the webhook cannot reach the server', function () {
        $image = aiCompletedLabImage();
        $videoRun = MediaLabRun::create(['kind' => 'video', 'breed' => 'border_collie', 'profiles' => ['veo31_lite'], 'pet_state' => 'idle', 'source_result_id' => $image->id]);
        $video = MediaLabResult::create([
            'media_lab_run_id' => $videoRun->id,
            'kind' => 'video',
            'profile' => 'veo31_lite',
            'endpoint' => 'fal-ai/veo3.1/lite/image-to-video',
            'prompt' => 'p',
            'request_id' => 'lab-req-3',
            'status_url' => 'https://queue.fal.run/fal-ai/veo3.1/requests/lab-req-3/status',
            'response_url' => 'https://queue.fal.run/fal-ai/veo3.1/requests/lab-req-3',
            'status' => 'running',
            'started_at' => now()->subMinute(),
        ]);
        Http::fake([
            'queue.fal.run/fal-ai/veo3.1/requests/lab-req-3/status' => Http::response(['status' => 'COMPLETED']),
            'queue.fal.run/fal-ai/veo3.1/requests/lab-req-3' => Http::response(['video' => ['url' => 'https://v3.fal.media/files/lab/3.mp4']]),
        ]);

        Queue::fake();
        expect(app(MediaLabService::class)->pollPending($image->run))->toBe(0); // the image run has no videos
        expect(app(MediaLabService::class)->pollPending(MediaLabRun::find($video->media_lab_run_id)))->toBe(1);
        Queue::assertPushed(PollMediaLabResult::class);

        (new PollMediaLabResult($video->id))->handle(app(FalGateway::class), app(MediaLabService::class));

        expect($video->fresh())->status->toBe('completed')->result_url->toBe('https://v3.fal.media/files/lab/3.mp4')
            ->and(AiSpendLedger::count())->toBe(0); // polling is free
    });
});

/* ─────────────────────────── Filament ─────────────────────────── */

describe('Filament AI Lab', function () {
    it('is reachable only for superadmins', function () {
        $this->actingAs(aiSuperadmin())->get('/admin/ai-lab')->assertOk()->assertSee('AI Lab');
    });

    it('renders the gallery with images, videos, failures and costs', function () {
        Queue::fake();
        $image = aiCompletedLabImage();
        $image->update(['latency_ms' => 4200, 'estimated_cost_usd' => 0.03]);
        $videoRun = MediaLabRun::create(['kind' => 'video', 'breed' => 'border_collie', 'profiles' => ['kling_v3_pro', 'veo31_lite'], 'pet_state' => 'sleeping', 'source_result_id' => $image->id, 'estimated_cost_usd' => 0.68]);
        $videoRun->results()->create(['kind' => 'video', 'profile' => 'kling_v3_pro', 'endpoint' => 'e/x', 'prompt' => 'p', 'status' => 'completed', 'result_url' => 'https://v3.fal.media/files/lab/v.mp4', 'estimated_cost_usd' => 0.56]);
        $videoRun->results()->create(['kind' => 'video', 'profile' => 'veo31_lite', 'endpoint' => 'e/y', 'prompt' => 'p', 'status' => 'failed', 'error_reason' => 'fal_balance', 'error' => 'Exhausted balance']);

        $this->actingAs(aiSuperadmin())->get('/admin/ai-lab')
            ->assertOk()
            ->assertSee('https://v3.fal.media/files/lab/dog.jpg')
            ->assertSee('<video src="https://v3.fal.media/files/lab/v.mp4"', false)
            ->assertSee('Failed: fal_balance')
            ->assertSee('state sleeping')
            ->assertSee('4.2 s');

        // The finished image is offered as a video source.
        Livewire::test(AiLab::class)
            ->set('videoData.source_result_id', $image->id)
            ->set('videoData.profiles', ['veo31_lite'])
            ->set('videoData.state', 'idle')
            ->call('animate')
            ->assertNotified('Video run queued');
    });

    it('is forbidden for a parent without superadmin', function () {
        $this->actingAs(createParentUser())->get('/admin/ai-lab')->assertForbidden();
    });

    it('redirects guests to the admin login', function () {
        $this->get('/admin/ai-lab')->assertRedirect('/admin/login');
    });

    it('starts an image run from the page form', function () {
        Queue::fake();
        $this->actingAs(aiSuperadmin());

        Livewire::test(AiLab::class)
            ->set('imageData.breed', 'border_collie')
            ->set('imageData.samples', 2)
            ->set('imageData.profiles', ['flux_schnell', 'seedream_v5_lite'])
            ->call('generateImages')
            ->assertHasNoErrors()
            ->assertNotified('Image run queued');

        expect(MediaLabRun::sole())->breed->toBe('border_collie')->samples->toBe(2)
            ->and(MediaLabResult::count())->toBe(4);
        Queue::assertPushed(RunMediaLabImage::class, 4);
    });

    it('shows a budget refusal instead of starting the run', function () {
        Queue::fake();
        $this->actingAs(aiSuperadmin());
        aiSpend(5);

        Livewire::test(AiLab::class)
            ->set('imageData.profiles', ['nano_banana_pro'])
            ->call('generateImages')
            ->assertNotified('Not started: budget');

        expect(MediaLabRun::count())->toBe(0);
    });

    it('shows spend today / month and the fal balance alert in the widget', function () {
        $this->actingAs(aiSuperadmin());
        aiSpend(1.25);
        Cache::put(FalGateway::BALANCE_FLAG_KEY, '2026-10-04T10:00:00+00:00');

        Livewire::test(AiSpendOverview::class)
            ->assertSee('$1.25 / $5.00')
            ->assertSee('$1.25 / $50.00')
            ->assertSee('EXHAUSTED');
    });
});
