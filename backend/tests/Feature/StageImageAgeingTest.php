<?php

use App\Enums\AiCallFailure;
use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
use App\Filament\Pages\FeatureSwitches;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\RegeneratePetStageMedia;
use App\Jobs\SendStageImageAlert;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaQualityCheck;
use App\Models\User;
use App\Services\Alerts\DiscordWebhook;
use App\Services\AppSettingsService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaVisionCheck;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetMediaService;
use App\Services\Media\StillQualityService;
use App\Services\PairingService;
use App\Services\Results\PetProfileChoice;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Life-stage images must look older (David 2026-11-28)
|--------------------------------------------------------------------------
| Production: mutt »Bafo« (shared look 1) grew puppy → young at midnight; the young image (a Nano
| Banana Pro EDIT of the puppy image) still showed the fluffy puppy — the edit prompt said »change
| only what ageing changes« and the full-body framing hid the size. Now (1) the stage edit prompt
| describes the new body and keeps only the identity; (2) the previous stage's image + the new one go
| to the vision model (»same animal AND visibly older«): shadow (default) records the verdict on the
| row, enforce remakes the stage image ≤ 2× with the next prompt variant, then keeps the last one and
| sends ONE aggregated Discord alert. The vision model is always Http::fake — never a real fal call.
*/

const SAI_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-28 08:00:00', 'UTC'));
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    Cache::forget(FalWebhookVerifier::CACHE_KEY);
    Cache::forget(FalGateway::BALANCE_FLAG_KEY);
    config([
        'services.fal_ai.key' => 'test-key',
        'app.url' => 'https://api.petprep.si',
        'media.reference_image_profile' => 'nano_banana_pro',
        'media.state_video_profile' => 'kling_v3_pro',
        'media.stage_edit_profile' => 'nano_banana_pro_edit',
        'media.budget.daily_usd' => 50.0,
        'media.budget.monthly_usd' => 500.0,
        'media.budget.timezone' => 'UTC',
        'media.profiles.vision.openrouter_vision_gemini_flash.enabled' => true,
        'media.quality_check.stage_mode' => 'shadow',
    ]);
    seedBreedConfigs();
    seedStageParams();
    Queue::fake([GeneratePetReferenceImage::class, SubmitPetStateVideo::class, SendStageImageAlert::class]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * fal fakes: stage edits (sync), the first reference image (sync), downloads, and the vision model.
 * `$verdicts` = the »looks older« answers in order (true = pass); after the list every check passes.
 *
 * @param  list<bool>  $verdicts
 */
function saiFakeFal(array $verdicts = []): void
{
    $n = 0;
    $i = 0;
    Http::fake([
        'fal.run/openrouter/router/vision' => function (Request $r) use (&$i, $verdicts) {
            $ok = $verdicts[$i] ?? true;
            $i++;
            $answer = array_fill_keys(MediaVisionCheck::STILL_KEYS[MediaVisionCheck::RUBRIC_STAGE], true);
            if (! $ok) {
                $answer['visibly_older'] = false;
            }

            return Http::response(['output' => json_encode($answer + ['verdict' => $ok ? 'pass' : 'fail', 'reason' => $ok ? 'clearly an adolescent dog' : 'still looks like the same fluffy puppy']), 'usage' => ['cost' => 0.0004]]);
        },
        'fal.run/fal-ai/nano-banana-pro/edit' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/sai/edit-{$n}.jpg"]]]);
        },
        'fal.run/fal-ai/nano-banana-pro' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/sai/ref-{$n}.jpg"]]]);
        },
        'v3.fal.media/files/sai/*.jpg' => fn () => Http::response(SAI_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

/** @return Collection<int, array<string, mixed>> request bodies of stage edits */
function saiEdits(): Collection
{
    return Http::recorded(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/nano-banana-pro/edit')->map(fn (array $p) => $p[0]->data())->values();
}

/** @return Collection<int, array<string, mixed>> request bodies of vision calls */
function saiVisionCalls(): Collection
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'openrouter/router/vision'))->map(fn (array $p) => $p[0]->data())->values();
}

/**
 * Run every queued GeneratePetReferenceImage (Queue::fake keeps them) until none is left — the
 * real queue runs each one after the job that dispatched it ended (its unique lock released).
 */
function saiDrain(): void
{
    $done = 0;
    for ($round = 0; $round < 10; $round++) {
        $jobs = Queue::pushed(GeneratePetReferenceImage::class)->values();
        if ($jobs->count() <= $done) {
            return;
        }
        foreach ($jobs->slice($done) as $job) {
            $done++;
            (new UniqueLock(Cache::driver()))->release($job);
            $job->handle(app(PetMediaService::class));
        }
    }
}

/** A born young mutt with its OWN media and a stored PUPPY reference image (its growth source). */
function saiYoungDog(string $breed = 'mutt'): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->state(['breed_type' => $breed])->create([
        'user_id' => $child->id,
        'born_at' => now()->subWeeks(3),
        'arrival_age_months' => 2,
        'life_stage' => 'young',
        'media_status' => 'ready',
        'plan' => 'free',
        'challenge_paid_at' => null,
        'challenge_paid_source' => null,
    ]))->fresh();
    $path = "{$pet->id}/reference-g1.jpg";
    Storage::disk('pet_media')->put($path, SAI_JPEG.str_repeat("\x00", 256));
    $old = PetMedia::create([
        'pet_id' => $pet->id, 'kind' => 'image', 'state' => null, 'status' => 'ready', 'generation' => 1,
        'storage_path' => $path, 'mime' => 'image/jpeg', 'bytes' => 276, 'profile' => 'nano_banana_pro', 'life_stage' => 'puppy',
    ]);

    return [$pet, $old];
}

/** Grow the pet into its (already set) new stage through the real job, then run the queued work. */
function saiGrow(Pet $pet): PetMedia
{
    (new RegeneratePetStageMedia($pet->id))->handle(app(PetMediaService::class));
    saiDrain();

    return PetMedia::where('pet_id', $pet->id)->images()->sole();
}

describe('stage edit prompts', function () {
    it('describes the new body and keeps only the identity (young, adult, senior) — no »change only what ageing changes«', function () {
        $prompts = app(PetAppearancePrompt::class);
        $traits = ['size' => 'medium', 'coat_color' => 'black', 'coat_length' => 'short'];

        $young = $prompts->stageEditPromptFor('mutt', $traits, LifeStage::Young);
        expect($young)
            ->toStartWith('The same dog as in the reference image (')
            ->toContain('adolescent young dog')
            ->toContain('clearly no longer a puppy')
            ->toContain('clearly longer legs')
            ->toContain('a longer muzzle')
            ->toContain('a head that is smaller in proportion to the body')
            ->toContain('a leaner and deeper chest')
            ->toContain('the soft, fluffy puppy coat is replaced by the sleeker, smoother adult coat')
            ->toContain(PetAppearancePrompt::EDIT_KEEP)
            ->toContain('Keep only the identity')
            ->toContain('the same ear shape and ear set')
            ->toContain('Change the body proportions, the size and the coat texture and length to the new age')
            ->toContain('camera distance and angle as in the reference image')
            ->toEndWith(PetAppearancePrompt::STAGE_EDIT_STYLE)
            ->not->toContain('Change only what ageing changes')
            ->not->toContain(PetAppearancePrompt::STAGE_EDIT_VARIANT_NOTES[1]);

        expect($prompts->stageEditPromptFor('mutt', $traits, LifeStage::Adult))->toContain('clearly a fully grown adult dog')->toContain('nothing lanky')
            ->and($prompts->stageEditPromptFor('mutt', $traits, LifeStage::Senior))->toContain('clearly an old dog now')->toContain('greying muzzle')
            // An adopted dog keeps the neutral cue.
            ->and($prompts->stageEditPromptFor('mutt', $traits, LifeStage::Young, PetOrigin::Adopted))->toContain(PetAppearancePrompt::ADOPTED_AVOID);
    });

    it('adds the next, stronger note per retry variant (capped at the last variant)', function () {
        $prompts = app(PetAppearancePrompt::class);
        $traits = ['coat_color' => 'black'];

        $v1 = $prompts->stageEditPromptFor('mutt', $traits, LifeStage::Young, null, 1);
        $v2 = $prompts->stageEditPromptFor('mutt', $traits, LifeStage::Young, null, 2);

        expect($v1)->toContain(PetAppearancePrompt::STAGE_EDIT_VARIANT_NOTES[1])->not->toContain(PetAppearancePrompt::STAGE_EDIT_VARIANT_NOTES[2])
            ->and($v2)->toContain(PetAppearancePrompt::STAGE_EDIT_VARIANT_NOTES[2])
            ->and($prompts->stageEditPromptFor('mutt', $traits, LifeStage::Young, null, 7))->toBe($v2);
    });

    it('gives cats the same structure without the word dog (the Maine Coon stage note still follows)', function () {
        $prompts = app(PetAppearancePrompt::class);
        $traits = ['coat_color' => 'brown tabby', 'coat_length' => 'long'];

        foreach ([LifeStage::Young, LifeStage::Adult, LifeStage::Senior] as $stage) {
            foreach ([0, 1, 2] as $variant) {
                $text = $prompts->stageEditPromptFor('maine_coon', $traits, $stage, PetOrigin::Adopted, $variant);
                expect(preg_match('/\bdogs?\b/i', $text))->toBe(0)
                    ->and($text)->toContain(PetAppearancePrompt::CAT_EDIT_KEEP)->toContain(PetAppearancePrompt::CAT_STAGE_EDIT_STYLE)
                    ->not->toContain('Change only what ageing changes');
            }
        }
        expect($prompts->stageEditPromptFor('maine_coon', $traits, LifeStage::Young))->toContain('clearly no longer a kitten')->toContain('As a young Maine Coon it is not fully grown yet');
    });
});

describe('»looks older« check mode', function () {
    it('defaults to shadow without an admin row and is saved with the other quality modes', function () {
        $settings = app(AppSettingsService::class);
        config(['media.quality_check.stage_mode' => null]);
        expect(AppSettingsService::qualityStageDefault())->toBe('shadow')
            ->and($settings->qualityStageMode(fresh: true))->toBe('shadow');

        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $settings->updateQualityCheck($admin, 'shadow', 'enforce', 'shadow', true, 'enforce');
        expect($settings->qualityStageMode(fresh: true))->toBe('enforce')
            ->and($settings->qualityPetStillsMode(fresh: true))->toBe('shadow');

        // Saving the other modes keeps the stage mode.
        $settings->updateQualityCheck($admin, 'enforce');
        expect($settings->qualityStageMode(fresh: true))->toBe('enforce');

        expect(fn () => $settings->updateQualityCheck($admin, 'shadow', stageMode: 'sometimes'))->toThrow(InvalidArgumentException::class);
    });

    it('is a select on the admin Feature switches page next to the pet image check mode (audited)', function () {
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);

        Livewire::test(FeatureSwitches::class)
            ->assertFormSet(['quality_stage' => 'shadow'])
            ->fillForm(['quality_stage' => 'enforce'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(AppSettingsService::class)->qualityStageMode(fresh: true))->toBe('enforce')
            ->and(AppSettingChange::where('key', 'quality_check')->latest('id')->first()->user_id)->toBe($admin->id);
    });
});

describe('»looks older« check of a pet\'s own stage image', function () {
    it('shadow: sends the previous stage + the new image, records a pass on the row and uses the image', function () {
        saiFakeFal([true]);
        [$pet, $old] = saiYoungDog();

        $image = saiGrow($pet);

        $calls = saiVisionCalls();
        expect($calls)->toHaveCount(1)
            ->and($calls[0]['image_urls'][0])->toStartWith("https://api.petprep.si/api/media/{$old->id}?")
            ->and($calls[0]['image_urls'][1])->toStartWith("https://api.petprep.si/api/media/candidates/{$image->id}?")
            ->and($calls[0]['prompt'])->toContain('Image 1 shows a dog at an earlier age: a young puppy')
            ->and($calls[0]['prompt'])->toContain('adolescent young dog')
            ->and($calls[0]['prompt'])->toContain('"visibly_older": true|false');
        expect($image->status)->toBe('ready')
            ->and($image->life_stage)->toBe('young')
            ->and($image->storage_path)->not->toBe($old->storage_path)
            ->and($image->candidate_path)->toBeNull()
            ->and($image->quality)->toBe(PetMedia::QUALITY_PASSED)
            ->and(saiEdits())->toHaveCount(1)
            ->and(PetMediaQualityCheck::where('pet_media_id', $image->id)->sole()->verdict)->toBe('pass');
        // The videos of the new stage follow the stored image.
        Queue::assertPushed(SubmitPetStateVideo::class);
    });

    it('shadow: a fail is recorded (would fail) but the image is used and never remade', function () {
        saiFakeFal([false, false, false]);
        [$pet] = saiYoungDog();

        $image = saiGrow($pet);

        expect($image->status)->toBe('ready')
            ->and($image->quality)->toBe(PetMedia::QUALITY_SHADOW_FAILED)
            ->and($image->quality_reason)->toContain('still looks like the same fluffy puppy')
            ->and(saiEdits())->toHaveCount(1)
            ->and(saiVisionCalls())->toHaveCount(1)
            ->and((int) $image->still_retries)->toBe(0);
        Queue::assertNotPushed(SendStageImageAlert::class);
        Queue::assertPushed(SubmitPetStateVideo::class);
    });

    it('enforce: a fail is remade with the next prompt variant and a fresh seed, a pass is used', function () {
        config(['media.quality_check.stage_mode' => 'enforce']);
        saiFakeFal([false, true]);
        [$pet] = saiYoungDog();

        $image = saiGrow($pet);

        $edits = saiEdits();
        expect($edits)->toHaveCount(2)
            ->and($edits[0]['prompt'])->not->toContain(PetAppearancePrompt::STAGE_EDIT_VARIANT_NOTES[1])
            ->and($edits[1]['prompt'])->toContain(PetAppearancePrompt::STAGE_EDIT_VARIANT_NOTES[1])
            ->and($edits[1]['seed'])->not->toBe($edits[0]['seed'])
            // Both edits grow from the stored PUPPY image (the pet showed it until the pass).
            ->and($edits[1]['image_urls'][0])->toBe($edits[0]['image_urls'][0]);
        expect($image->status)->toBe('ready')
            ->and($image->quality)->toBe(PetMedia::QUALITY_PASSED)
            ->and((int) $image->still_retries)->toBe(1)
            ->and(saiVisionCalls())->toHaveCount(2);
        Queue::assertNotPushed(SendStageImageAlert::class);
    });

    it('enforce: after 2 remakes the LAST image is kept (failed — kept) and ONE alert is queued', function () {
        config(['media.quality_check.stage_mode' => 'enforce']);
        saiFakeFal([false, false, false, false]);
        [$pet, $old] = saiYoungDog();

        $image = saiGrow($pet);

        $edits = saiEdits();
        expect($edits)->toHaveCount(3)
            ->and($edits[2]['prompt'])->toContain(PetAppearancePrompt::STAGE_EDIT_VARIANT_NOTES[2])
            ->and(saiVisionCalls())->toHaveCount(3);
        expect($image->status)->toBe('ready')
            ->and($image->storage_path)->not->toBe($old->storage_path)
            ->and($image->candidate_path)->toBeNull()
            ->and($image->quality)->toBe(PetMedia::QUALITY_SHADOW_FAILED)
            ->and($image->quality_reason)->toStartWith('Stage check failed 3×')
            ->and($image->isShown())->toBeTrue();
        Queue::assertPushed(SendStageImageAlert::class, 1);
        Queue::assertPushed(SubmitPetStateVideo::class);
        // The aggregated alert carries only a count.
        expect(StillQualityService::takeStageAlertCount())->toBe(1);
    });

    it('enforce: a remake the budget cannot pay is not started — the image is kept and counted', function () {
        config(['media.quality_check.stage_mode' => 'enforce', 'media.budget.daily_usd' => 0.2]);
        saiFakeFal([false, true]);
        [$pet] = saiYoungDog();

        $image = saiGrow($pet);

        expect(saiEdits())->toHaveCount(1)
            ->and($image->status)->toBe('ready')
            ->and($image->quality)->toBe(PetMedia::QUALITY_SHADOW_FAILED)
            ->and($image->quality_reason)->toContain('no budget for a new stage edit');
        Queue::assertPushed(SendStageImageAlert::class, 1);
    });

    it('fails closed without budget for the check: enforce waits (nothing paid, previous image still shown), shadow uses it unchecked', function () {
        config(['media.quality_check.stage_mode' => 'enforce', 'media.budget.daily_usd' => 0.15]);
        saiFakeFal();
        [$pet, $old] = saiYoungDog();

        $image = saiGrow($pet);

        expect(saiVisionCalls())->toHaveCount(0)
            ->and(PetMediaQualityCheck::where('pet_media_id', $image->id)->count())->toBe(0)
            ->and($image->status)->toBe('running')
            ->and($image->candidate_path)->not->toBeNull()
            ->and($image->storage_path)->toBe($old->storage_path);

        // Shadow never holds a stage image for the budget.
        config(['media.quality_check.stage_mode' => 'shadow']);
        app(StillQualityService::class)->checkStageCandidate($image);
        $image->refresh();
        expect($image->status)->toBe('ready')
            ->and($image->quality)->toBe(PetMedia::QUALITY_UNCHECKED)
            ->and(saiVisionCalls())->toHaveCount(0);
    });

    it('off: the stage image is stored at once, no vision call', function () {
        config(['media.quality_check.stage_mode' => 'off']);
        saiFakeFal();
        [$pet] = saiYoungDog();

        $image = saiGrow($pet);

        expect($image->status)->toBe('ready')->and($image->quality)->toBeNull()->and(saiVisionCalls())->toHaveCount(0);
    });

    it('never checks a same-stage regenerate of a pet\'s own slot (text-to-image, no earlier stage to compare)', function () {
        saiFakeFal();
        [$pet, $old] = saiYoungDog();
        // An admin regenerate at the SAME stage: the stored file is not an earlier stage's image.
        $old->update(['life_stage' => 'young']);

        expect(app(PetMediaService::class)->stageImageSource($old->fresh()))->toBeNull();
        app(PetMediaService::class)->regenerate($old->fresh());
        saiDrain();

        expect(PetMedia::where('pet_id', $pet->id)->images()->sole()->status)->toBe('ready')
            ->and(saiVisionCalls())->toHaveCount(0);
    });
});

describe('»looks older« alert and retry planning', function () {
    it('posts the aggregated alert with the count only — no pet, look or family fields, no AI Lab hint', function () {
        config(['services.discord.alerts_webhook_url' => 'https://discord.com/api/webhooks/1/test']);
        Http::fake(['discord.com/*' => Http::response(null, 204)]);
        StillQualityService::countStageAlert();
        StillQualityService::countStageAlert();
        StillQualityService::countStageAlert();

        (new SendStageImageAlert)->handle(app(DiscordWebhook::class));

        $posts = Http::recorded(fn (Request $r) => str_contains($r->url(), 'discord.com'))->map(fn (array $p) => $p[0]->data())->values();
        expect($posts)->toHaveCount(1);
        $embed = $posts[0]['embeds'][0];
        expect($posts[0]['content'])->toContain('3 life-stage image')
            ->and($embed['fields'])->toBe([])
            ->and($embed['description'])->not->toContain('AI Lab')
            ->and($embed['description'])->toContain('re-renders it from text')
            ->and($embed['description'])->toContain('AI media → Shared looks → »Regenerate media«')
            ->and(StillQualityService::takeStageAlertCount())->toBe(0);
    });

    it('refuses a remake while the fal balance is flagged exhausted (fail closed), allows it otherwise', function () {
        expect(app(PetMediaService::class)->stageRetryRefusal())->toBeNull();

        Cache::put(FalGateway::BALANCE_FLAG_KEY, now()->toIso8601String(), now()->addDay());

        expect(app(PetMediaService::class)->stageRetryRefusal())->toBe(AiCallFailure::FalBalance->value);
    });
});

describe('»looks older« check of a shared look\'s stage image', function () {
    /** A born pool mutt (free breed → shared look) with its stored puppy look image. */
    function saiPoolPet(): Pet
    {
        config(['media.look_pool.enabled' => true, 'media.look_pool.size' => 20]);
        $parent = createParentUser();
        $family = app(FamilyService::class)->ensureFamilyFor($parent);
        $child = createChildUser(['parent_id' => $parent->id]);
        $profile = new PetProfileChoice(BreedType::Mutt, PetOrigin::Bought, LifeStage::Puppy, [], BreedType::Mutt->species());
        $pet = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, PetPlan::Free)['pet'])->fresh();
        saiDrain(); // the puppy look image (text-to-image) — no check: no earlier stage
        $pet->giveBirth(now());
        $pet->save();

        return $pet->fresh();
    }

    it('enforce: compares with the look\'s puppy image, remakes the look row through a waiting pet, links the pass', function () {
        config(['media.quality_check.stage_mode' => 'enforce']);
        saiFakeFal([false, true]);
        $pet = saiPoolPet();
        $puppy = PetMedia::where('pet_look_id', $pet->pet_look_id)->images()->where('life_stage', 'puppy')->sole();
        expect($puppy->status)->toBe('ready')->and(saiVisionCalls())->toHaveCount(0);

        $pet->forceFill(['life_stage' => LifeStage::Young])->saveQuietly();
        expect(app(PetMediaService::class)->startStageTransition($pet->fresh()))->toBe('started');
        saiDrain();

        $young = PetMedia::where('pet_look_id', $pet->pet_look_id)->images()->where('life_stage', 'young')->sole();
        $calls = saiVisionCalls();
        expect($calls)->toHaveCount(2)
            ->and($calls[0]['image_urls'][0])->toStartWith("https://api.petprep.si/api/media/{$puppy->id}?")
            ->and($calls[0]['image_urls'][1])->toStartWith("https://api.petprep.si/api/media/candidates/{$young->id}?")
            ->and(saiEdits())->toHaveCount(2)
            ->and(saiEdits()[1]['prompt'])->toContain(PetAppearancePrompt::STAGE_EDIT_VARIANT_NOTES[1]);
        expect($young->status)->toBe('ready')
            ->and($young->quality)->toBe(PetMedia::QUALITY_PASSED)
            ->and($young->storage_path)->toBe("looks/{$pet->pet_look_id}/reference-young-g2.jpg")
            ->and(PetMedia::where('pet_id', $pet->id)->images()->value('storage_path'))->toBe($young->storage_path);
    });

    /** The look's young row with a failed candidate, plus linked pets of the same look (in id order). */
    function saiFailedYoungLookRow(Pet $pet): PetMedia
    {
        Storage::disk('pet_media')->put("looks/{$pet->pet_look_id}/candidate.jpg", SAI_JPEG);

        return PetMedia::create([
            'pet_look_id' => $pet->pet_look_id, 'kind' => 'image', 'state' => null, 'status' => 'running', 'generation' => 1,
            'life_stage' => 'young', 'profile' => 'nano_banana_pro_edit', 'candidate_path' => "looks/{$pet->pet_look_id}/candidate.jpg",
            'candidate_mime' => 'image/jpeg', 'candidate_bytes' => 20,
        ]);
    }

    function saiLinkedPet(Pet $like, PetMedia $lookRow, string $status, bool $active): Pet
    {
        $child = createChildUser(['parent_id' => $like->user->parent_id]);
        $other = disableHygieneEvents(Pet::factory()->state(['breed_type' => 'mutt'])->create([
            'user_id' => $child->id, 'pet_look_id' => $like->pet_look_id, 'life_stage' => 'young', 'born_at' => now()->subWeeks(3), 'arrival_age_months' => 2,
            'is_active' => $active, 'plan' => 'free', 'challenge_paid_at' => null, 'challenge_paid_source' => null,
        ]))->fresh();
        PetMedia::create(['pet_id' => $other->id, 'kind' => 'image', 'state' => null, 'status' => $status, 'generation' => 1, 'life_stage' => 'young', 'look_media_id' => $lookRow->id]);

        return $other;
    }

    function saiRetryStage(PetMedia $row): string
    {
        $service = app(StillQualityService::class);

        return (fn () => $this->retryStage($row->fresh(), (string) $row->candidate_path, 'still a puppy'))->call($service);
    }

    it('enforce: a look row remake goes to an ACTIVE pet still waiting for it — never a ready or inactive linked pet with a lower id', function () {
        saiFakeFal();
        $pet = saiPoolPet();
        $row = saiFailedYoungLookRow($pet);
        // Lower ids first: the pool pet's own slot is READY (linked), an inactive pet waits, then the waiting active pet.
        PetMedia::where('pet_id', $pet->id)->images()->update(['look_media_id' => $row->id, 'status' => 'ready']);
        $inactive = saiLinkedPet($pet, $row, 'pending', false);
        $waiting = saiLinkedPet($pet, $row, 'pending', true);
        $before = Queue::pushed(GeneratePetReferenceImage::class)->count();

        expect(app(StillQualityService::class)->lookStageRetryPetId($row))->toBe($waiting->id)
            ->and(saiRetryStage($row))->toBe('retrying');

        $pushed = Queue::pushed(GeneratePetReferenceImage::class)->slice($before)->values();
        expect($pushed)->toHaveCount(1)
            ->and($pushed[0]->petId)->toBe($waiting->id)
            ->and($pushed[0]->petId)->not->toBe($inactive->id);
        $row->refresh();
        expect($row->status)->toBe('pending')->and((int) $row->generation)->toBe(2)->and($row->candidate_path)->toBeNull();
    });

    it('enforce: no pet waits for the look row — it stays pending for the next pet of the look, no job, nothing paid', function () {
        saiFakeFal();
        $pet = saiPoolPet();
        $row = saiFailedYoungLookRow($pet);
        PetMedia::where('pet_id', $pet->id)->images()->update(['look_media_id' => $row->id, 'status' => 'ready']);
        saiLinkedPet($pet, $row, 'pending', false);
        $before = Queue::pushed(GeneratePetReferenceImage::class)->count();
        $edits = saiEdits()->count();

        expect(saiRetryStage($row))->toBe('retry_queued');

        expect(Queue::pushed(GeneratePetReferenceImage::class)->count())->toBe($before)
            ->and(saiEdits()->count())->toBe($edits);
        $row->refresh();
        expect($row->status)->toBe('pending')
            ->and((int) $row->still_retries)->toBe(1)
            ->and($row->candidate_path)->toBeNull();
        Storage::disk('pet_media')->assertMissing("looks/{$pet->pet_look_id}/candidate.jpg");
    });
});
