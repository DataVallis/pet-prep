<?php

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
use App\Filament\Pages\FeatureSwitches;
use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Filament\Resources\PetResource\RelationManagers\MediaRelationManager;
use App\Jobs\GeneratePetKeyframe;
use App\Jobs\ResumeStateMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AiSpendLedger;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\ChallengeService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\MediaProfiles;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetMediaService;
use App\Services\Media\PetPreparationService;
use App\Services\Media\ReferenceImageRetryService;
use App\Services\PairingService;
use App\Services\Results\PetProfileChoice;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M5-T08 — media per state (APP_REFINEMENT_SPEC §7, David 2026-10-20 / 21)
|--------------------------------------------------------------------------
|
| Key image per state (Nano Banana Pro Edit of the reference image), state
| videos made from that key image (fix 2026-10-23: start frame only — the same image as Kling end_image_url froze the clip),
| transitions idle ↔ sleeping (start = pose A, end = pose B). Entitlement
| only in MediaEntitlementService; order fits PetPreparationService; claims
| and budget unchanged; a shared look (breed + colour) makes them once —
| 0 $ for later buyers; pets from before M5-T08 get nothing automatically.
*/

const SM_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

const SM_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

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
        'media.stage_edit_profile' => 'nano_banana_pro_edit',
        'media.budget.daily_usd' => 100.0,
        'media.budget.monthly_usd' => 1000.0,
        'media.budget.timezone' => 'UTC',
    ]);
    useStateMedia();
    // M5-T09 / T10 phase clips have their own tests (PhaseClipsTest).
    usePhaseClips(false);
    seedBreedConfigs();
    seedStageParams();
});

/** fal fakes: image runs (text-to-image + edits), queue submits, result downloads. */
function smFakeFal(): void
{
    fakeFalJwks();
    $n = 0;
    Http::fake([
        // Life-stage growth edits (editReferenceImage) stay sync.
        'fal.run/fal-ai/nano-banana-pro/edit' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/sm/edit-{$n}.jpg"]]]);
        },
        // Fix 2026-10-28: key / pose images are queued (result via completeFalKeyImages()).
        'queue.fal.run/fal-ai/nano-banana-pro/edit*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "sm-edit-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/sm-edit-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/sm-edit-{$n}"]);
        },
        'fal.run/fal-ai/nano-banana-pro' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/sm/ref-{$n}.jpg"]]]);
        },
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "sm-req-{$n}"]);
        },
        'v3.fal.media/files/sm/*.jpg' => fn () => Http::response(SM_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/sm/*.mp4' => fn () => Http::response(SM_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** @return Collection<int, Request> */
function smCalls(?string $prefix = null)
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), $prefix ?? 'https://fal.run/') || ($prefix === null && str_starts_with($r->url(), 'https://queue.fal.run/')))
        ->map(fn (array $pair) => $pair[0])->values();
}

/** Answer every submitted clip (pet slots and look rows) with a successful signed webhook. */
function smCompleteClips(): int
{
    $done = 0;

    // A stored key image queues the next clips: loop until nothing is waiting for a webhook.
    for ($round = 0; $round < 6; $round++) {
        // Fix 2026-10-28: queued key / pose images first (a stored one queues its clips).
        $keys = completeFalKeyImages('https://v3.fal.media/files/sm/');
        $rows = PetMedia::query()->whereIn('kind', ['video', 'transition'])
            ->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();

        if ($rows->isEmpty() && $keys === 0) {
            break;
        }

        foreach ($rows as $row) {
            sendFalWebhook([
                'request_id' => $row->request_id,
                'status' => 'OK',
                'payload' => ['video' => ['url' => "https://v3.fal.media/files/sm/{$row->request_id}.mp4"]],
            ])->assertOk();
            $done++;
        }
    }

    return $done;
}

/**
 * A new profiled pet as a PIN login creates it (state media version 2). A
 * paid breed with the unique switch off shares the breed + colour look.
 */
function smPet(string $breed = 'labrador_retriever', ?string $colour = 'black', bool $paid = true, LifeStage $stage = LifeStage::Young): Pet
{
    $parent = createParentUser();
    $family = app(FamilyService::class)->ensureFamilyFor($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $type = BreedType::from($breed);
    $profile = new PetProfileChoice($type, PetOrigin::Bought, $stage, [], $type->species(), $colour);
    $plan = $type->isPremium() ? PetPlan::Challenge : PetPlan::Free;

    $pet = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, $plan)['pet'])->fresh();

    if ($paid) {
        $pet->forceFill(['plan' => PetPlan::Challenge, 'challenge_paid_at' => now(), 'challenge_paid_source' => ChallengePaidSource::Purchase])->saveQuietly();
    }

    return $pet->fresh();
}

/** Birth (first contract) + queue the media like signContract() does after commit. */
function smBirth(Pet $pet): Pet
{
    $pet->giveBirth(now());
    $pet->save();
    app(PetMediaService::class)->queueStateVideos($pet->fresh());
    // 2026-10-28: key images are queued at fal — deliver their webhooks (the loops follow).
    completeFalKeyImages();

    return $pet->fresh();
}

function smSlot(Pet $pet, string $kind, ?string $state): ?PetMedia
{
    return PetMedia::where('pet_id', $pet->id)->where('kind', $kind)
        ->when($state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $state))->first();
}

/* ─────────────────────────── Profile + fal input ─────────────────────────── */

describe('Kling end frame', function () {
    it('the production video profile sends end_image_url (fal docs, 2026-10-21) for different frames', function () {
        $profile = app(MediaProfiles::class)->stateVideo();
        expect($profile->key)->toBe('kling_v3_pro')
            ->and($profile->supportsEndImage())->toBeTrue()
            ->and($profile->videoInput('https://a/start.jpg', 'p', null, 'https://a/end.jpg'))
            ->toMatchArray(['start_image_url' => 'https://a/start.jpg', 'end_image_url' => 'https://a/end.jpg', 'duration' => '5', 'generate_audio' => false]);

        // A profile without an end frame never gets one (Veo).
        expect(app(MediaProfiles::class)->video('veo31_fast')->videoInput('https://a/s.jpg', 'p', null, 'https://a/e.jpg'))
            ->not->toHaveKey('end_image_url');
    });

    it('key images are Nano Banana Pro Edit at 0.15 $; a 5 s Kling clip is 0.56 $', function () {
        expect(app(MediaProfiles::class)->keyframe()->endpoint)->toBe('fal-ai/nano-banana-pro/edit')
            ->and(app(MediaProfiles::class)->keyframe()->estimatedCostUsd())->toBe(0.15)
            ->and(app(MediaProfiles::class)->stateVideo()->estimatedCostUsd())->toBe(0.56);
    });
});

/* ─────────────────────────── Entitlement ─────────────────────────── */

describe('entitlement (MediaEntitlementService)', function () {
    it('a purchased full-set pet gets key images of every state but idle and both idle ↔ sleeping transitions', function () {
        config(['services.fal_ai.key' => null]);
        $pet = smPet();
        $e = app(MediaEntitlementService::class);

        expect($pet->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION)
            ->and($e->usesStateMedia($pet))->toBeTrue()
            ->and(array_map(fn ($s) => $s->value, $e->keyframeStatesFor($pet)))->toBe(['sleeping', 'low_energy', 'hungry', 'sick', 'playing'])
            ->and($e->transitionsFor($pet))->toBe([PetTransition::IdleToSleeping, PetTransition::SleepingToIdle]);
    });

    it('the free basic set keeps its pre-M5-T08 media (no key images, no transitions)', function () {
        config(['services.fal_ai.key' => null]);
        $mutt = smPet('mutt', null, paid: false);
        $e = app(MediaEntitlementService::class);

        expect($e->tierFor($mutt))->toBe(MediaEntitlementService::TIER_BASIC)
            ->and($e->usesStateMedia($mutt))->toBeFalse()
            ->and($e->keyframeStatesFor($mutt))->toBe([])
            ->and($e->transitionsFor($mutt))->toBe([]);
    });

    it('an unpaid (trial) challenge pet, a pet from before M5-T08 and a legacy-profile pet get none', function () {
        config(['services.fal_ai.key' => null]);
        $e = app(MediaEntitlementService::class);

        $trial = smPet(paid: false);
        $old = smPet();
        $old->forceFill(['state_media_version' => 1])->saveQuietly();
        $legacy = Pet::factory()->create(['user_id' => createChildUser()->id, 'plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase, 'state_media_version' => 2]);

        expect($e->usesStateMedia($trial))->toBeFalse()
            ->and($e->usesStateMedia($old->fresh()))->toBeFalse()
            ->and($e->usesStateMedia($legacy))->toBeFalse();
    });

    it('the switch off (or no key image profile) → the pre-M5-T08 set', function () {
        config(['services.fal_ai.key' => null]);
        $pet = smPet();
        useStateMedia(false);
        expect(app(MediaEntitlementService::class)->usesStateMedia($pet))->toBeFalse();

        useStateMedia();
        config(['media.state_media.keyframe_profile' => 'does_not_exist']);
        expect(app(MediaEntitlementService::class)->usesStateMedia($pet))->toBeFalse();
    });

    it('new pets are version 2 only with a profile; existing pets stay version 1 (column default)', function () {
        config(['services.fal_ai.key' => null]);
        expect(smPet()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION)
            ->and(Pet::factory()->create(['user_id' => createChildUser()->id])->fresh()->state_media_version)->toBe(1);
    });
});

/* ─────────────────────────── Pipeline (unique media) ─────────────────────────── */

describe('pipeline for a pet with its own media', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('order: reference → idle loop + key images of sleeping / hungry first → the other states → transitions last', function () {
        smFakeFal();
        $pet = smBirth(smPet());

        // Reference image at pairing, then at birth: idle loop (from the reference image) and the
        // key images in preparation order. Clips of the other states wait for their key image.
        $calls = smCalls()->map(fn (Request $r) => [$r->url(), $r['prompt'] ?? null])->all();
        expect($calls[0][0])->toBe('https://fal.run/fal-ai/nano-banana-pro')                          // reference
            ->and($calls[1][0])->toStartWith('https://queue.fal.run/fal-ai/kling-video/v3/pro')        // idle loop
            ->and($calls[2][0])->toStartWith('https://queue.fal.run/fal-ai/nano-banana-pro/edit?')                  // sleeping key
            ->and($calls[2][1])->toContain('lying curled up asleep');

        // Key image edits in preparation order: sleeping, hungry, playing, then the rest.
        $edits = smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->map(fn (Request $r) => (string) $r['prompt'])->values();
        expect($edits)->toHaveCount(5)
            ->and($edits[0])->toContain('asleep')
            ->and($edits[1])->toContain('empty dog food bowl')
            ->and($edits[2])->toContain('playful bow');

        $keyOrder = PetMedia::where('pet_id', $pet->id)->where('kind', 'keyframe')->orderBy('completed_at')->orderBy('id')->pluck('state')->all();
        expect($keyOrder)->toBe(['sleeping', 'hungry', 'playing', 'low_energy', 'sick']);

        // Fix 2026-10-23: every loop starts on its key image WITHOUT an end frame (the same
        // image as end frame froze the clip on Kling) and asks for clearly visible motion.
        $loopSubmits = smCalls('https://queue.fal.run/fal-ai/kling-video/')->filter(fn (Request $r) => ! str_contains((string) $r['prompt'], 'start image'))->values();
        expect($loopSubmits)->toHaveCount(6);
        foreach ($loopSubmits as $r) {
            expect($r->data())->not->toHaveKey('end_image_url')
                ->and($r['prompt'])->toContain('clearly visible')->toContain('close to the starting pose so it can loop')
                ->and($r['prompt'])->not->toContain('Subtle, slow')
                ->and($r['negative_prompt'])->not->toContain('fast motion');
        }
        expect(PetMedia::where('pet_id', $pet->id)->where('kind', 'video')->whereNotNull('request_id')->pluck('recipe')->unique()->all())->toBe(['start_only']);

        // Transitions are submitted only after every first-minutes clip, from two different poses.
        $transitions = smCalls('https://queue.fal.run/fal-ai/kling-video/')->filter(fn (Request $r) => str_contains((string) $r['prompt'], 'start image'))->values();
        expect($transitions)->toHaveCount(2)
            ->and($transitions[0]['start_image_url'])->not->toBe($transitions[0]['end_image_url']);
        $allQueue = smCalls('https://queue.fal.run/fal-ai/kling-video/')->values();
        $firstTransition = $allQueue->search(fn (Request $r) => str_contains((string) $r['prompt'], 'start image'));
        $essentialLoops = $allQueue->take($firstTransition)->filter(fn (Request $r) => ! str_contains((string) $r['prompt'], 'start image'))->count();
        expect($essentialLoops)->toBeGreaterThanOrEqual(3); // idle, sleeping, hungry before any transition

        smCompleteClips();
        $media = app(PetMediaService::class)->payloadFor($pet->fresh());
        expect($media['status'])->toBe('ready')
            ->and($media['state_media'])->toBeTrue()
            ->and(array_keys((array) $media['keyframes']))->toEqualCanonicalizing(['sleeping', 'low_energy', 'hungry', 'sick', 'playing'])
            ->and(array_keys((array) $media['transitions']))->toEqualCanonicalizing(['idle_to_sleeping', 'sleeping_to_idle'])
            ->and($media['loops'])->toEqualCanonicalizing(['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing']);

        // 1 reference + 5 key images (0.15 $) and 6 loops + 2 transitions (0.56 $) = 5.38 $.
        expect(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe(5.38)
            ->and(smSlot($pet, 'transition', 'idle_to_sleeping')->storage_path)->toBe("{$pet->id}/idle_to_sleeping-g1.mp4")
            ->and(smSlot($pet, 'keyframe', 'sleeping')->storage_path)->toBe("{$pet->id}/key-sleeping-g1.jpg");

        // Preparation knows the transitions and finished once everything is stored.
        expect(app(PetPreparationService::class)->summary($pet->fresh())->ready)->toBeTrue()
            ->and(app(PetPreparationService::class)->expectedKeys($pet->fresh()))->toContain('idle_to_sleeping');
    });

    it('claims: a duplicate key image job or submit is harmless (one fal call per slot)', function () {
        smFakeFal();
        $pet = smBirth(smPet());
        $before = smCalls()->count();

        $media = app(PetMediaService::class);
        $key = smSlot($pet, 'keyframe', 'sleeping');
        expect($media->generateKeyframe($key->id))->toBeTrue()
            ->and($media->submitVideo(smSlot($pet, 'video', 'sleeping')->id))->toBeTrue()
            ->and($media->queueStateVideos($pet->fresh()))->toBe(0)
            ->and(smCalls()->count())->toBe($before);
    });

    it('budget: a refused key image fails its slot, the loop waits, nothing else breaks; the daily retry re-runs it', function () {
        smFakeFal();
        config(['media.budget.daily_usd' => 0.75]); // reference (0.15) + idle loop (0.56) fit, then the cap
        $pet = smBirth(smPet());

        $key = smSlot($pet, 'keyframe', 'sleeping');
        expect($key->status)->toBe('failed')
            ->and($key->error_reason)->toBe('budget_daily')
            ->and(smSlot($pet, 'video', 'sleeping')->status)->toBe('pending')
            ->and(smSlot($pet, 'video', 'sleeping')->request_id)->toBeNull();

        config(['media.budget.daily_usd' => 100.0]);
        app(ReferenceImageRetryService::class)->retryDueWithVideos();
        smCompleteClips(); // 2026-10-28: the key image comes on the fal webhook
        expect(smSlot($pet, 'keyframe', 'sleeping')->status)->toBe('ready');
    });

    it('a loop waits for its key image (never a clip of another pose); a stored key image queues it', function () {
        smFakeFal();
        Queue::fake([GeneratePetKeyframe::class]); // the key images never arrive
        $pet = smBirth(smPet());

        expect(smSlot($pet, 'video', 'hungry')->request_id)->toBeNull()
            ->and(smSlot($pet, 'video', 'idle')->request_id)->not->toBeNull() // idle = the reference image pose
            ->and(app(PetMediaService::class)->payloadFor($pet->fresh())['videos'])->not->toHaveKey('hungry');
    });

    it('admin regenerate of a key image remakes its loop and the transitions through that pose', function () {
        smFakeFal();
        $pet = smBirth(smPet());
        smCompleteClips();
        $loopGen = smSlot($pet, 'video', 'sleeping')->generation;

        expect(app(PetMediaService::class)->regenerate(smSlot($pet, 'keyframe', 'sleeping')))->toBeTrue();
        smCompleteClips();

        expect(smSlot($pet, 'keyframe', 'sleeping')->generation)->toBe(2)
            ->and(smSlot($pet, 'video', 'sleeping')->generation)->toBeGreaterThan($loopGen)
            ->and(smSlot($pet, 'transition', 'idle_to_sleeping')->generation)->toBeGreaterThan(1)
            ->and(smSlot($pet, 'video', 'hungry')->generation)->toBe(1);
    });
});

/* ─────────────────────────── Shared looks (breed + colour) ─────────────────────────── */

describe('shared look of breed + colour', function () {
    it('the first buyer makes the key images, loops and transitions once; the next buyer of the same breed + colour costs 0 $', function () {
        smFakeFal();
        $a = smBirth(smPet('beagle', 'tricolour'));
        smCompleteClips();
        $firstCost = round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2);
        $calls = smCalls()->count();

        expect($firstCost)->toBe(5.38)
            ->and(PetMedia::whereNotNull('pet_look_id')->where('kind', 'keyframe')->count())->toBe(5)
            ->and(PetMedia::whereNotNull('pet_look_id')->where('kind', 'transition')->count())->toBe(2);

        $b = smBirth(smPet('beagle', 'tricolour'));
        smCompleteClips();

        expect(smCalls()->count())->toBe($calls)
            ->and(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe($firstCost);

        $mediaB = app(PetMediaService::class)->payloadFor($b->fresh());
        expect($mediaB['status'])->toBe('ready')
            ->and(array_keys((array) $mediaB['keyframes']))->toHaveCount(5)
            ->and(array_keys((array) $mediaB['transitions']))->toHaveCount(2)
            ->and(smSlot($b, 'keyframe', 'sleeping')->storage_path)->toBe(smSlot($a, 'keyframe', 'sleeping')->storage_path)
            ->and(smSlot($b, 'transition', 'sleeping_to_idle')->storage_path)->toStartWith("looks/{$a->pet_look_id}/");
    });

    it('a look whose clips were made before M5-T08 remakes them once as loops for the first new buyer (old pets get the new files, 0 $)', function () {
        smFakeFal();
        useStateMedia(false);
        $old = smBirth(smPet('beagle', 'tricolour'));
        smCompleteClips();
        expect(smSlot($old, 'video', 'idle')->recipe)->toBeNull();
        $oldPath = smSlot($old, 'video', 'idle')->storage_path;

        useStateMedia();
        $old->forceFill(['state_media_version' => 1])->saveQuietly();
        $new = smBirth(smPet('beagle', 'tricolour'));
        smCompleteClips();

        $row = PetMedia::whereNotNull('pet_look_id')->where('kind', 'video')->where('state', 'idle')->sole();
        expect($row->recipe)->toBe('start_only')
            ->and(smSlot($new, 'video', 'idle')->storage_path)->toBe($row->storage_path)
            ->and($row->storage_path)->not->toBe($oldPath)
            // The pre-M5-T08 pet shows the remade shared clip too (one file per look) — no extra cost.
            ->and(smSlot($old, 'video', 'idle')->storage_path)->toBe($row->storage_path)
            ->and(PetMedia::where('pet_id', $old->id)->whereIn('kind', ['keyframe', 'transition'])->count())->toBe(0);
    });
});

/* ─────────────────────────── Existing pets: no automatic backfill ─────────────────────────── */

describe('existing pets', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('a pet from before M5-T08 gets no key image, loop or transition from the pipeline; the admin plan offers the upgrade', function () {
        smFakeFal();
        $pet = smPet();
        $pet->forceFill(['state_media_version' => 1])->saveQuietly();
        $pet = smBirth($pet->fresh());
        smCompleteClips();

        expect(PetMedia::where('pet_id', $pet->id)->whereIn('kind', ['keyframe', 'transition'])->count())->toBe(0)
            ->and(smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->count())->toBe(0)
            ->and(PetMedia::where('pet_id', $pet->id)->where('kind', 'video')->pluck('recipe')->unique()->all())->toBe([null]);

        $plan = app(PetMediaService::class)->planMissing($pet->fresh(), upgradeStateMedia: true);
        expect($plan['upgrade'])->toBeTrue()
            ->and($plan['keyframes'])->toHaveCount(5)
            ->and($plan['transitions'])->toHaveCount(2)
            ->and($plan['videos'])->toHaveCount(6)                // remade as loops
            ->and($plan['cost_usd'])->toBe(round(5 * 0.15 + 8 * 0.56, 4))
            ->and(app(PetMediaService::class)->planMissing($pet->fresh(), upgradeStateMedia: false)['keyframes'])->toBe([]);

        // Admin "Upgrade to state media" = the explicit upgrade (M5-T08b: "Generate missing" never upgrades).
        app(PetMediaService::class)->generateMissing($pet->fresh(), upgradeStateMedia: true);
        smCompleteClips();
        expect($pet->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION)
            ->and(PetMedia::where('pet_id', $pet->id)->where('kind', 'keyframe')->where('status', 'ready')->count())->toBe(5)
            ->and(smSlot($pet, 'video', 'idle')->recipe)->toBe('start_only');
    });

    it('a new purchase of a pet from before M5-T08 upgrades it (the purchase pays for the full set)', function () {
        config(['services.fal_ai.key' => null]);
        $pet = smPet(paid: false);
        $pet->forceFill(['state_media_version' => 1, 'born_at' => now(), 'last_decay_at' => now()])->saveQuietly();

        DB::transaction(fn () => app(ChallengeService::class)->markPaid(Pet::whereKey($pet->id)->lockForUpdate()->first(), ChallengePaidSource::Purchase, now()));

        expect($pet->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION);
    });
});

/* ─────────────────────────── Prompts (no personal data) ─────────────────────────── */

describe('prompts', function () {
    it('key image, loop and transition prompts come from breed + traits only and keep the look', function () {
        config(['services.fal_ai.key' => null]);
        $pet = smPet();
        $pet->forceFill(['name' => 'Luna'])->saveQuietly();
        $prompts = app(PetAppearancePrompt::class);
        [$breed, $traits] = $prompts->subjectOf($pet->fresh());

        $key = $prompts->keyframePrompt($breed, PetStateEnum::Sleeping, $traits, LifeStage::Young);
        $loop = $prompts->loopVideoPrompt($breed, PetStateEnum::Sleeping, $traits);
        $move = $prompts->transitionVideoPrompt($breed, PetTransition::IdleToSleeping, $traits);

        foreach ([$key, $loop, $move] as $p) {
            expect($p)->not->toContain('Luna')->and($p)->toContain('Labrador');
        }
        expect($key)->toContain('Change only the pose')
            ->and($loop)->toContain('so it can loop')->not->toContain('Subtle, slow')
            ->and($move)->toContain('ending exactly in the pose of the end image');
    });

    it('a cat gets cat poses and never the word "dog"', function () {
        $prompts = app(PetAppearancePrompt::class);
        $traits = ['coat_color' => 'blue', 'coat_length' => 'short'];
        foreach ([
            $prompts->keyframePrompt('british_shorthair', PetStateEnum::Sleeping, $traits),
            $prompts->loopVideoPrompt('british_shorthair', PetStateEnum::Sleeping, $traits),
            $prompts->transitionVideoPrompt('british_shorthair', PetTransition::SleepingToIdle, $traits),
        ] as $p) {
            expect(strtolower($p))->not->toContain('dog');
        }
    });
});

/* ─────────────────────────── Superadmin AI media panel ─────────────────────────── */

describe('superadmin AI media panel', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('lists the key images, loops and transitions with how they were made, and regenerates a key image', function () {
        smFakeFal();
        $pet = smBirth(smPet());
        smCompleteClips();
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

        $key = smSlot($pet, 'keyframe', 'sleeping');
        $transition = smSlot($pet, 'transition', 'idle_to_sleeping');
        $loop = smSlot($pet, 'video', 'sleeping');

        Queue::fake([GeneratePetKeyframe::class]);
        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$key, $transition, $loop])
            ->assertTableColumnStateSet('recipe', 'loop (from key image, start frame only)', $loop)
            ->assertTableColumnStateSet('recipe', 'pose → pose', $transition)
            ->assertTableColumnStateSet('recipe', 'edit of reference', $key)
            ->callTableAction('regenerate', $key);

        Queue::assertPushed(GeneratePetKeyframe::class, fn ($job) => $job->petMediaId === $key->id);
        expect($key->fresh()->generation)->toBe(2);
    });
});

/* ─────────────────────────── M5-T08b: the superadmin switch stops the spend ─────────────────────────── */

/** M5-T08b: the /admin switch (app_settings.state_media). */
function smSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::STATE_MEDIA_KEY], ['value' => ['enabled' => $on]]);
    Cache::forget(AppSettingsService::cacheKey(AppSettingsService::STATE_MEDIA_KEY));
}

/** Run every faked media job pushed so far that has not run yet, until none is left. */
function smDrainJobs(array &$ran): int
{
    $count = 0;

    for ($round = 0; $round < 10; $round++) {
        $todo = collect(Queue::pushedJobs())->flatten(1)->pluck('job')
            ->reject(fn ($job) => isset($ran[spl_object_id($job)]))->values();

        if ($todo->isEmpty()) {
            break;
        }

        foreach ($todo as $job) {
            $ran[spl_object_id($job)] = true;
            try {
                $job->handle(app(PetMediaService::class));
            } catch (RuntimeException) {
                // a retryable failure — the test counts fal calls, not retries
            } finally {
                // As a real worker does after the job: release its ShouldBeUnique lock.
                (new UniqueLock(Cache::driver()))->release($job);
            }
            $count++;
        }
    }

    return $count;
}

describe('M5-T08b superadmin switch', function () {
    it('Feature switches: state media is on by default, off is saved audited and turns usesStateMedia off', function () {
        config(['services.fal_ai.key' => null]);
        $pet = smPet();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);

        Livewire::test(FeatureSwitches::class)->assertFormSet(['state_media' => true])->call('save')->assertHasNoFormErrors();
        expect(AppSetting::find(AppSettingsService::STATE_MEDIA_KEY))->toBeNull(); // unchanged default → no row

        Livewire::test(FeatureSwitches::class)->fillForm(['state_media' => false])->call('save')->assertHasNoFormErrors()->assertNotified();

        expect(app(AppSettingsService::class)->stateMedia())->toBeFalse()
            ->and(AppSetting::find(AppSettingsService::STATE_MEDIA_KEY)->value)->toBe(['enabled' => false])
            ->and(AppSettingChange::where('key', AppSettingsService::STATE_MEDIA_KEY)->sole()->user_id)->toBe($admin->id)
            ->and(app(MediaEntitlementService::class)->usesStateMedia($pet))->toBeFalse()
            ->and(Livewire::test(FeatureSwitches::class)->instance()->getChanges()->pluck('key')->all())->toContain(AppSettingsService::STATE_MEDIA_KEY);

        smSwitch(true);
        expect(app(MediaEntitlementService::class)->usesStateMedia($pet))->toBeTrue();
    });

    it('switched off: a queued key image / transition job, the daily retry and the sweep make zero fal calls; on again resumes', function () {
        useUniquePetMedia(true);
        smFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = smBirth(smPet());
        $media = app(PetMediaService::class);
        $key = smSlot($pet, 'keyframe', 'sleeping');
        $transition = $media->slot($pet, PetMedia::KIND_TRANSITION, PetTransition::IdleToSleeping);
        $before = smCalls()->count();
        $pushed = count(Queue::pushedJobs()[GeneratePetKeyframe::class] ?? []) + count(Queue::pushedJobs()[SubmitPetStateVideo::class] ?? []);

        smSwitch(false);

        // Queued jobs: the slots stay pending, nothing reaches fal.
        (new GeneratePetKeyframe($key->id))->handle($media);
        (new SubmitPetStateVideo($transition->id))->handle($media);
        expect($key->fresh()->status)->toBe('pending')
            ->and($transition->fresh()->status)->toBe('pending');

        // Daily retry: failed key images / transitions are not re-queued.
        $key->update(['status' => 'failed', 'error_reason' => 'budget_daily']);
        $transition->update(['status' => 'failed', 'error_reason' => 'budget_daily']);
        expect(app(ReferenceImageRetryService::class)->retryDueWithVideos()['videos'])->toBe(0)
            ->and($key->fresh()->status)->toBe('failed');

        // Sweep: dead claims of key images / transitions are not re-queued.
        PetMedia::whereKey([$key->id, $transition->id])->update(['status' => 'running', 'request_id' => null, 'source_url' => null, 'updated_at' => now()->subHour()]);
        expect($media->sweepStale()['reclaimed'])->toBe(0);

        $pushedAfter = count(Queue::pushedJobs()[GeneratePetKeyframe::class] ?? []) + count(Queue::pushedJobs()[SubmitPetStateVideo::class] ?? []);
        expect(smCalls()->count())->toBe($before)
            ->and($pushedAfter)->toBe($pushed);

        // On again: the sweep re-queues the key image and it is generated.
        smSwitch(true);
        expect($media->sweepStale()['reclaimed'])->toBe(2);
        expect($media->generateKeyframe($key->id))->toBeTrue()
            ->and(smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->count())->toBe(1);
    });

    it('switched off: look rows (shared breed + colour) are not generated either', function () {
        smFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = smBirth(smPet('beagle', 'tricolour'));
        $media = app(PetMediaService::class);
        $look = $pet->fresh()->look;
        $row = $media->lookSlot($look, PetMedia::KIND_KEYFRAME, PetStateEnum::Sleeping, LifeStage::Young);
        $trow = $media->lookSlot($look, PetMedia::KIND_TRANSITION, PetTransition::IdleToSleeping, LifeStage::Young);
        $before = smCalls()->count();

        smSwitch(false);
        (new GeneratePetKeyframe($row->id))->handle($media);
        (new SubmitPetStateVideo($trow->id))->handle($media);
        (new GeneratePetKeyframe(smSlot($pet, 'keyframe', 'sleeping')->id))->handle($media);

        expect(smCalls()->count())->toBe($before)
            ->and($row->fresh()->status)->toBe('pending')
            ->and($trow->fresh()->status)->toBe('pending');
    });
});

describe('M5-T08b old pets only', function () {
    /** A shared look used only by a pet from before M5-T08 (its clips made the old way). */
    function smOldLookPet(): Pet
    {
        smFakeFal();
        useStateMedia(false);
        $old = smBirth(smPet('beagle', 'tricolour'));
        smCompleteClips();
        $old->forceFill(['state_media_version' => 1])->saveQuietly();
        useStateMedia();

        return $old->fresh();
    }

    it('retry, sweep and regenerateLook make no key image / transition call; a clip marked as a loop goes back to the old clip', function () {
        $old = smOldLookPet();
        $media = app(PetMediaService::class);
        $look = $old->look;

        // Leftovers: a key image + transition look row and slots of the old pet, failed / dead.
        $krow = $media->lookSlot($look, PetMedia::KIND_KEYFRAME, PetStateEnum::Sleeping, LifeStage::Young);
        $trow = $media->lookSlot($look, PetMedia::KIND_TRANSITION, PetTransition::IdleToSleeping, LifeStage::Young);
        $kslot = $media->slot($old, PetMedia::KIND_KEYFRAME, PetStateEnum::Sleeping);
        $tslot = $media->slot($old, PetMedia::KIND_TRANSITION, PetTransition::IdleToSleeping);
        PetMedia::whereKey([$kslot->id, $tslot->id])->update(['status' => 'failed', 'error_reason' => 'budget_daily']);
        PetMedia::whereKey([$krow->id, $trow->id])->update(['status' => 'running', 'updated_at' => now()->subHour()]);
        $before = smCalls()->count();

        expect(app(ReferenceImageRetryService::class)->retryDueWithVideos()['videos'])->toBe(0);
        $media->sweepStale();
        expect(smCalls()->count())->toBe($before);

        // A look clip marked as a loop (e.g. by a buyer who is gone) — the old pet's request
        // falls back to the pre-M5-T08 clip instead of waiting for a key image forever.
        $idleRow = PetMedia::whereNotNull('pet_look_id')->where('kind', 'video')->where('state', 'idle')->sole();
        $idleRow->update(['status' => 'failed', 'recipe' => 'loop', 'request_id' => null]);
        smSlot($old, 'video', 'idle')->update(['status' => 'pending', 'request_id' => null]);
        $media->submitVideo(smSlot($old, 'video', 'idle')->id);

        $submit = smCalls('https://queue.fal.run/fal-ai/kling-video/')->last();
        expect($idleRow->fresh()->recipe)->toBeNull()
            ->and($idleRow->fresh()->request_id)->not->toBeNull()
            ->and($submit['end_image_url'] ?? null)->toBeNull();
        smCompleteClips();

        // regenerateLook: new image + old clips; no key image edit, no transition, no loop marker.
        PetMedia::whereNotNull('pet_look_id')->where('kind', 'video')->where('state', 'sleeping')->update(['recipe' => 'loop']);
        $edits = smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->count();
        $media->regenerateLook($look->fresh());
        smCompleteClips();

        $transitionSubmits = smCalls('https://queue.fal.run/fal-ai/kling-video/')->filter(fn (Request $r) => str_contains((string) $r['prompt'], 'start image'))->count();
        expect(smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->count())->toBe($edits)
            ->and($transitionSubmits)->toBe(0)
            ->and(PetMedia::whereNotNull('pet_look_id')->where('kind', 'video')->whereNotNull('recipe')->count())->toBe(0)
            ->and($krow->fresh()->status)->not->toBe('ready');
    });
});

describe('M5-T08b concurrent first buyers', function () {
    it('two first buyers of the same look + stage at the same time → one fal call per item', function () {
        smFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $a = smBirth(smPet('beagle', 'tricolour'));
        $b = smBirth(smPet('beagle', 'tricolour'));
        $media = app(PetMediaService::class);

        // B's jobs run while A's call of the same look row is in flight (re-entrant fake).
        $bKey = smSlot($b, 'keyframe', 'sleeping')->id;
        $bIdle = smSlot($b, 'video', 'idle')->id;
        $reentered = ['edit' => false, 'queue' => false];
        Http::fake([
            'queue.fal.run/fal-ai/nano-banana-pro/edit*' => function () use (&$reentered, $bKey, $media) {
                if (! $reentered['edit']) {
                    $reentered['edit'] = true;
                    $media->generateKeyframe($bKey);
                }

                return Http::response(['request_id' => 'sm-edit-'.uniqid()]);
            },
            'queue.fal.run/*' => function () use (&$reentered, $bIdle, $media) {
                if (! $reentered['queue']) {
                    $reentered['queue'] = true;
                    $media->submitVideo($bIdle);
                }

                return Http::response(['request_id' => 'sm-req-'.uniqid()]);
            },
        ]);

        $ran = [];
        for ($i = 0; $i < 6; $i++) {
            smDrainJobs($ran);
            smCompleteClips();
        }

        expect($reentered)->toBe(['edit' => true, 'queue' => true])
            ->and(smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->count())->toBe(5)
            ->and(smCalls('https://queue.fal.run/fal-ai/kling-video/')->count())->toBe(8)
            ->and(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe(5.38)
            ->and($media->payloadFor($a->fresh())['status'])->toBe('ready')
            ->and($media->payloadFor($b->fresh())['status'])->toBe('ready');
    });
});

describe('M5-T08b admin panel', function () {
    it('"Generate missing" never upgrades an old pet; "Upgrade to state media" does and shows the cost', function () {
        useUniquePetMedia(true);
        smFakeFal();
        $pet = smPet();
        $pet->forceFill(['state_media_version' => 1])->saveQuietly();
        $pet = smBirth($pet->fresh());
        smCompleteClips();
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->assertTableActionVisible('upgradeStateMedia')
            ->callTableAction('generateMissing');
        expect($pet->fresh()->state_media_version)->toBe(1);
        Queue::assertNotPushed(GeneratePetKeyframe::class);

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->mountTableAction('upgradeStateMedia')
            ->assertSee('$5.23')
            ->callMountedTableAction();
        expect($pet->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION);
        Queue::assertPushed(GeneratePetKeyframe::class);

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet->fresh(), 'pageClass' => EditPet::class])
            ->assertTableActionHidden('upgradeStateMedia');
    });
});

/* ─────────────────────────── M5-T08b QA round: resume, payload, regenerate, fresh read ─────────────────────────── */

describe('M5-T08b QA — turning the switch back on resumes', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    /** Born pet whose key image jobs ran while the switch was off (slots stay pending, 0 calls). */
    function smPetWaitingWhileOff(array &$ran): Pet
    {
        smFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = smBirth(smPet());

        smSwitch(false);
        smDrainJobs($ran);

        expect(smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->count())->toBe(0)
            ->and(PetMedia::where('pet_id', $pet->id)->where('kind', 'keyframe')->pluck('status')->unique()->all())->toBe(['pending']);

        return $pet;
    }

    it('the admin switch off → on queues ResumeStateMedia (only on a real flip to on)', function () {
        config(['services.fal_ai.key' => null]);
        Queue::fake([ResumeStateMedia::class]);
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $settings = app(AppSettingsService::class);

        $settings->updateStateMedia($admin, true);   // already on (default)
        Queue::assertNotPushed(ResumeStateMedia::class);

        $settings->updateStateMedia($admin, false);
        Queue::assertNotPushed(ResumeStateMedia::class);

        $settings->updateStateMedia($admin, true);
        Queue::assertPushed(ResumeStateMedia::class, 1);
    });

    it('switch on (ResumeStateMedia) → every key image waiting from the off time is made exactly once, then loops + transitions', function () {
        $ran = [];
        $pet = smPetWaitingWhileOff($ran);

        smSwitch(true);
        (new ResumeStateMedia)->handle(app(PetMediaService::class));
        for ($i = 0; $i < 6; $i++) {
            smDrainJobs($ran);
            smCompleteClips();
        }

        $edits = smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->map(fn (Request $r) => (string) $r['prompt']);
        expect($edits)->toHaveCount(5)
            ->and($edits->filter(fn (string $p) => str_contains($p, 'asleep')))->toHaveCount(1)
            ->and(app(PetMediaService::class)->payloadFor($pet->fresh())['status'])->toBe('ready')
            ->and(app(PetPreparationService::class)->summary($pet->fresh())->ready)->toBeTrue();

        // A second resume / sweep changes nothing (claims + ready slots).
        (new ResumeStateMedia)->handle(app(PetMediaService::class));
        $this->travel(15)->minutes();
        app(PetMediaService::class)->sweepStale();
        smDrainJobs($ran);
        expect(smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit'))->toHaveCount(5);
    });

    it('safety net: media:sweep resumes slots left pending while off (older than the claim timeout), once per slot', function () {
        $ran = [];
        $pet = smPetWaitingWhileOff($ran);
        smSwitch(true);

        // Fresh pending slots are not touched by the sweep (a job may be on its way).
        expect(app(PetMediaService::class)->sweepStale()['resumed'])->toBe(0);

        $this->travel(PetMediaService::STALE_CLAIM_MINUTES + 1)->minutes();
        expect(app(PetMediaService::class)->sweepStale()['resumed'])->toBe(1);
        smDrainJobs($ran);
        completeFalKeyImages(); // 2026-10-28: key images come on the fal webhook

        $edits = smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->map(fn (Request $r) => (string) $r['prompt']);
        expect($edits)->toHaveCount(5)
            ->and($edits->filter(fn (string $p) => str_contains($p, 'asleep')))->toHaveCount(1)
            ->and(smSlot($pet, 'keyframe', 'sleeping')->status)->toBe('ready');
    });

    it('while still off the sweep resumes nothing', function () {
        $ran = [];
        smPetWaitingWhileOff($ran);
        $this->travel(PetMediaService::STALE_CLAIM_MINUTES + 1)->minutes();

        expect(app(PetMediaService::class)->sweepStale()['resumed'])->toBe(0);
        smDrainJobs($ran);
        expect(smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit'))->toHaveCount(0);
    });
});

describe('M5-T08b QA — off never downgrades stored media; no regenerate; fresh read', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('switch off: the payload still says state_media and serves the stored key images, transitions and loops', function () {
        smFakeFal();
        $pet = smBirth(smPet());
        smCompleteClips();

        smSwitch(false);
        $media = app(PetMediaService::class)->payloadFor($pet->fresh());
        expect($media['state_media'])->toBeTrue()
            ->and(array_keys((array) $media['keyframes']))->toHaveCount(5)
            ->and(array_keys((array) $media['transitions']))->toHaveCount(2)
            ->and($media['loops'])->toHaveCount(6);

        // A pet without the set is unchanged (older clips, no flag).
        $old = smPet();
        $old->forceFill(['state_media_version' => 1])->saveQuietly();
        expect(app(PetMediaService::class)->payloadFor($old->fresh())['state_media'])->toBeFalse();
    });

    it('switch off: Regenerate of a key image / transition is refused and hidden in the admin panel; a state video still can', function () {
        smFakeFal();
        $pet = smBirth(smPet());
        smCompleteClips();
        smSwitch(false);
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

        $key = smSlot($pet, 'keyframe', 'sleeping');
        $transition = smSlot($pet, 'transition', 'idle_to_sleeping');
        $video = smSlot($pet, 'video', 'hungry');

        expect(app(PetMediaService::class)->regenerate($key))->toBeFalse()
            ->and(app(PetMediaService::class)->regenerate($transition))->toBeFalse()
            ->and($key->fresh()->generation)->toBe(1);

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->assertTableActionHidden('regenerate', $key)
            ->assertTableActionHidden('regenerate', $transition)
            ->assertTableActionVisible('regenerate', $video);
    });

    it('the fal entry points read the switch fresh: off stops a worker even while "on" is still cached', function () {
        smFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = smBirth(smPet());

        expect(app(AppSettingsService::class)->stateMedia())->toBeTrue(); // "on" cached now
        AppSetting::updateOrCreate(['key' => AppSettingsService::STATE_MEDIA_KEY], ['value' => ['enabled' => false]]); // no cache forget
        expect(app(AppSettingsService::class)->stateMedia())->toBeTrue()            // the cached read
            ->and(app(AppSettingsService::class)->stateMedia(fresh: true))->toBeFalse();

        app(PetMediaService::class)->generateKeyframe(smSlot($pet, 'keyframe', 'sleeping')->id);
        app(PetMediaService::class)->submitVideo(app(PetMediaService::class)->slot($pet, PetMedia::KIND_TRANSITION, PetTransition::IdleToSleeping)->id);
        expect(smCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit'))->toHaveCount(0)
            ->and(smSlot($pet, 'keyframe', 'sleeping')->status)->toBe('pending');
    });
});
