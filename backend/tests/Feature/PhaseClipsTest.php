<?php

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPhaseClip;
use App\Enums\PetPlan;
use App\Enums\PetPose;
use App\Enums\PetStateEnum;
use App\Enums\TrainingCommand;
use App\Events\PetUpdated;
use App\Jobs\GeneratePetKeyframe;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AiSpendLedger;
use App\Models\AppSetting;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Services\AppSettingsService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\GameMediaReadiness;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\MediaProfiles;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetMediaService;
use App\Services\Media\PetPreparationService;
use App\Services\Media\ReferenceImageRetryService;
use App\Services\PairingService;
use App\Services\PlayService;
use App\Services\Results\PetProfileChoice;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| M5-T09 school clips per command and phase / M5-T10 game phase clips
|--------------------------------------------------------------------------
|
| APP_REFINEMENT_SPEC §3 / §10 (David 2026-10-20 / 21): 3 s Kling 3.0 Pro clips
| from / to POSE images (Nano Banana Pro Edit of the reference image), played
| once by the app. Only pets that use state media (paid full set, version 2,
| switch on); made on the first purchase of breed + colour + stage and shared
| (0 $ later); after the first-minutes set: school, then the games.
*/

const PHC_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

const PHC_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

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
        'media.budget.daily_usd' => 1000.0,
        'media.budget.monthly_usd' => 10000.0,
        'media.budget.timezone' => 'UTC',
    ]);
    useStateMedia();
    usePhaseClips();
    seedBreedConfigs();
    seedStageParams();
});

function phcFakeFal(): void
{
    fakeFalJwks();
    $n = 0;
    Http::fake([
        'fal.run/fal-ai/nano-banana-pro/edit' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/pc/edit-{$n}.jpg"]]]);
        },
        'fal.run/fal-ai/nano-banana-pro' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/pc/ref-{$n}.jpg"]]]);
        },
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "pc-req-{$n}"]);
        },
        'v3.fal.media/files/pc/*.jpg' => fn () => Http::response(PHC_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/pc/*.mp4' => fn () => Http::response(PHC_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** @return Collection<int, Request> */
function phcCalls(?string $prefix = null)
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), $prefix ?? 'https://fal.run/') || ($prefix === null && str_starts_with($r->url(), 'https://queue.fal.run/')))
        ->map(fn (array $pair) => $pair[0])->values();
}

function phcCompleteClips(): int
{
    $done = 0;

    for ($round = 0; $round < 10; $round++) {
        $rows = PetMedia::query()->whereIn('kind', ['video', 'transition', 'phase'])
            ->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();

        if ($rows->isEmpty()) {
            break;
        }

        foreach ($rows as $row) {
            sendFalWebhook([
                'request_id' => $row->request_id,
                'status' => 'OK',
                'payload' => ['video' => ['url' => "https://v3.fal.media/files/pc/{$row->request_id}.mp4"]],
            ])->assertOk();
            $done++;
        }
    }

    return $done;
}

/** A new profiled pet as a PIN login with the training feature creates it (state media version 2). */
function phcPet(string $breed = 'labrador_retriever', ?string $colour = 'black', bool $paid = true): Pet
{
    $parent = createParentUser();
    $family = app(FamilyService::class)->ensureFamilyFor($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $type = BreedType::from($breed);
    $profile = new PetProfileChoice($type, PetOrigin::Bought, LifeStage::Young, ['training'], $type->species(), $colour);

    $pet = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, PetPlan::Challenge)['pet'])->fresh();

    if ($paid) {
        $pet->forceFill(['plan' => PetPlan::Challenge, 'challenge_paid_at' => now(), 'challenge_paid_source' => ChallengePaidSource::Purchase])->saveQuietly();
    }

    return $pet->fresh();
}

function phcBirth(Pet $pet): Pet
{
    $pet->giveBirth(now());
    $pet->save();
    app(PetMediaService::class)->queueStateVideos($pet->fresh());

    return $pet->fresh();
}

function phcSlot(Pet $pet, string $kind, string $state): ?PetMedia
{
    return PetMedia::where('pet_id', $pet->id)->where('kind', $kind)->where('state', $state)->first();
}

function phcSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::STATE_MEDIA_KEY], ['value' => ['enabled' => $on]]);
    Cache::forget(AppSettingsService::cacheKey(AppSettingsService::STATE_MEDIA_KEY));
}

/* ─────────────────────────── Phases + entitlement ─────────────────────────── */

describe('phase clips (enum + entitlement)', function () {
    it('every training command has attention → performing → success; the commands are sit, come, place, potty', function () {
        expect(TrainingCommand::values())->toBe(['sit', 'come', 'place', 'potty']);

        foreach (TrainingCommand::cases() as $command) {
            $pose = PetPhaseClip::commandPose($command);
            $attention = PetPhaseClip::school($command, 'attention');
            $performing = PetPhaseClip::school($command, 'performing');
            $success = PetPhaseClip::school($command, 'success');

            // attention: idle → idle · performing: idle → held pose · success: held pose → held pose.
            expect([$attention->startPose(), $attention->endPose()])->toBe([null, null])
                ->and([$performing->startPose(), $performing->endPose()])->toBe([null, $pose])
                ->and([$success->startPose(), $success->endPose()])->toBe([$pose, $pose])
                ->and($attention->purpose())->toBe('school');
        }

        // Ball: waiting (idle) → running (out of frame: the empty room) → fetching (back to idle).
        expect([PetPhaseClip::BallRunning->startPose(), PetPhaseClip::BallRunning->endPose()])->toBe([null, PetPose::Empty])
            ->and([PetPhaseClip::BallFetching->startPose(), PetPhaseClip::BallFetching->endPose()])->toBe([PetPose::Empty, null])
            // Wand: stalking into the crouch, pounces leave from and land in it, catching ends holding the catch.
            ->and([PetPhaseClip::WandStalking->startPose(), PetPhaseClip::WandStalking->endPose()])->toBe([null, PetPose::Crouch])
            ->and([PetPhaseClip::WandPouncing->startPose(), PetPhaseClip::WandPouncing->endPose()])->toBe([PetPose::Crouch, PetPose::Crouch])
            ->and([PetPhaseClip::WandCatching->startPose(), PetPhaseClip::WandCatching->endPose()])->toBe([PetPose::Crouch, PetPose::Catch])
            ->and(PetPhaseClip::BallWaiting->purpose())->toBe('game');
    });

    it('a paid dog with training gets 12 school clips then 3 ball clips (school first, §13) and their 5 poses', function () {
        config(['services.fal_ai.key' => null]);
        $pet = phcPet();
        $e = app(MediaEntitlementService::class);

        $clips = array_map(fn ($c) => $c->value, $e->phaseClipsFor($pet));
        expect($clips)->toHaveCount(15)
            ->and(array_slice($clips, 0, 12))->each->toStartWith('school_')
            ->and(array_slice($clips, 12))->toBe(['ball_waiting', 'ball_running', 'ball_fetching'])
            ->and(array_map(fn ($p) => $p->value, $e->posesFor($pet)))->toBe(['sit', 'come', 'place', 'door', 'empty']);
    });

    it('a paid cat gets the 3 wand clips and 2 poses — no school, no ball', function () {
        config(['services.fal_ai.key' => null]);
        $cat = phcPet('maine_coon', 'brown');
        $e = app(MediaEntitlementService::class);

        expect(array_map(fn ($c) => $c->value, $e->phaseClipsFor($cat)))->toBe(['wand_stalking', 'wand_pouncing', 'wand_catching'])
            ->and(array_map(fn ($p) => $p->value, $e->posesFor($cat)))->toBe(['crouch', 'catch']);
    });

    it('none for: an unpaid (trial) pet, a pet from before M5-T08, the free set, the switch off; no school without training', function () {
        config(['services.fal_ai.key' => null]);
        $e = app(MediaEntitlementService::class);

        $old = phcPet();
        $old->forceFill(['state_media_version' => 1])->saveQuietly();
        $noTraining = phcPet();
        $noTraining->forceFill(['training_enabled' => false])->saveQuietly();

        expect($e->phaseClipsFor(phcPet(paid: false)))->toBe([])
            ->and($e->phaseClipsFor($old->fresh()))->toBe([])
            ->and($e->phaseClipsFor(phcPet('mutt', null, paid: false)))->toBe([])
            ->and(array_map(fn ($c) => $c->value, $e->phaseClipsFor($noTraining->fresh())))->toBe(['ball_waiting', 'ball_running', 'ball_fetching']);

        $pet = phcPet();
        phcSwitch(false);
        expect($e->phaseClipsFor($pet))->toBe([])
            ->and($e->posesFor($pet))->toBe([])
            // The set itself (payload / readiness) does not depend on the switch.
            ->and($e->phaseClipsOfSet($pet))->toHaveCount(15)
            ->and(app(PetMediaService::class)->payloadFor($pet)['phase_groups'])->toBe(['school', 'ball']);
    });

    it('school and game clips are 3 s (0.336 $), state clips stay 5 s (0.56 $) — a per-purpose length', function () {
        $profiles = app(MediaProfiles::class);

        expect($profiles->clipVideo('school')->durationSeconds)->toBe(3)
            ->and($profiles->clipVideo('game')->estimatedCostUsd())->toBe(0.336)
            ->and($profiles->clipVideo('school')->videoInput('https://a/s.jpg', 'p', null, 'https://a/e.jpg'))->toMatchArray(['duration' => '3', 'end_image_url' => 'https://a/e.jpg'])
            ->and($profiles->clipVideo('state')->durationSeconds)->toBe(5)
            ->and($profiles->clipVideo('transition')->estimatedCostUsd())->toBe(0.56)
            ->and($profiles->stateVideo()->durationSeconds)->toBe(5);

        // A length the model does not take → its own default (never a refused fal call).
        config(['media.state_media.clip_seconds.school' => 2]);
        expect($profiles->clipVideo('school')->durationSeconds)->toBe(5);
    });
});

/* ─────────────────────────── Pipeline ─────────────────────────── */

describe('pipeline for a dog with its own media', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('order: first-minutes set → transitions → school → ball; poses before their clips; 3 s clips from pose to pose; cost', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        phcCompleteClips();

        $queue = phcCalls('https://queue.fal.run/')->values();
        $prompts = $queue->map(fn (Request $r) => (string) $r['prompt']);
        $kind = fn (string $p): string => match (true) {
            str_contains($p, 'end of the') || str_contains($p, 'frame of the end image') => 'phase',
            str_contains($p, 'pose of the end image') => 'transition',
            default => 'loop',
        };
        $kinds = $prompts->map($kind)->values()->all();

        // Every loop and transition is queued before the first phase clip; school before ball.
        $firstPhase = array_search('phase', $kinds, true);
        expect($firstPhase)->not->toBeFalse()
            ->and(array_slice($kinds, $firstPhase))->each->toBe('phase')
            ->and(array_count_values($kinds))->toBe(['loop' => 6, 'transition' => 2, 'phase' => 15]);

        $phaseRows = PetMedia::where('pet_id', $pet->id)->where('kind', 'phase')->orderBy('id')->get();
        expect($phaseRows)->toHaveCount(15)
            ->and($phaseRows->pluck('status')->unique()->all())->toBe(['ready'])
            ->and($phaseRows->pluck('duration_seconds')->unique()->all())->toBe([3.0]);

        // 3 s at Kling (duration "3"), start / end frame = the clip's poses.
        $phaseSubmits = $queue->filter(fn (Request $r) => $kind((string) $r['prompt']) === 'phase')->values();
        expect($phaseSubmits->pluck('duration')->unique()->all())->toBe(['3'])
            // The toy is drawn by the app: the prompt only forbids it.
            ->and((string) $phaseSubmits[0]['prompt'])->toContain('No toys, no ball, no feather');

        $byPrompt = fn (string $needle) => $phaseSubmits->first(fn (Request $r) => str_contains((string) $r['prompt'], $needle));
        $sitDown = $byPrompt('sits down on command');
        $sitHold = $byPrompt('holds the sit');
        expect($sitDown['end_image_url'])->toBe($sitHold['start_image_url'])
            ->and($sitHold['start_image_url'])->toBe($sitHold['end_image_url'])
            ->and($sitDown['start_image_url'])->not->toBe($sitDown['end_image_url']);

        // Pose images: Nano Banana Pro Edit of the reference image, after the key images.
        $edits = phcCalls('https://fal.run/fal-ai/nano-banana-pro/edit')->map(fn (Request $r) => (string) $r['prompt'])->values();
        expect($edits)->toHaveCount(5 + 5)
            ->and($edits->slice(5)->values()[0])->toContain('sitting upright')
            ->and($edits->last())->toContain('WITHOUT the dog')
            ->and($edits->implode(' '))->not->toContain('ball');

        // 1 + 5 key images + 5 poses (0.15 $) · 6 loops + 2 transitions (0.56 $) · 15 phase clips (0.336 $).
        $expected = round(11 * 0.15 + 8 * 0.56 + 15 * 0.336, 2);
        expect(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe($expected)
            ->and($expected)->toBe(11.17)
            ->and(phcSlot($pet, 'pose', 'sit')->storage_path)->toBe("{$pet->id}/pose-sit-g1.jpg")
            ->and(phcSlot($pet, 'phase', 'school_sit_success')->storage_path)->toBe("{$pet->id}/school_sit_success-g1.mp4");

        // The payload carries the poses and phase clips (signed, never a fal URL).
        $media = app(PetMediaService::class)->payloadFor($pet->fresh());
        expect(array_keys((array) $media['poses']))->toEqualCanonicalizing(['sit', 'come', 'place', 'door', 'empty'])
            ->and(array_keys((array) $media['phases']))->toHaveCount(15)
            ->and(collect((array) $media['phases'])->first())->toStartWith('https://api.petprep.si/api/media/')
            ->and($media['phase_groups'])->toBe(['school', 'ball'])
            ->and(json_encode($media))->not->toContain('fal.media');

        // The broadcast of the full dog set fits Reverb's HTTP publish limit with room for headers.
        $publish = json_encode(['name' => 'pet.updated', 'data' => json_encode(PetUpdated::payloadFor($pet->fresh(), 'video_ready')), 'channels' => ["private-pet.{$pet->id}"]]);
        expect(strlen($publish))->toBeLessThan((int) config('reverb.servers.reverb.max_request_size') - 4096);

        // Preparation ends with them (school after the transitions, then the games).
        $keys = app(PetPreparationService::class)->expectedKeys($pet->fresh());
        expect(array_slice($keys, -15, 1))->toBe(['school_sit_attention'])
            ->and(end($keys))->toBe('ball_fetching')
            ->and(app(PetPreparationService::class)->summary($pet->fresh())->ready)->toBeTrue();
    });

    it('claims: a duplicate pose job or phase submit is harmless (one fal call per slot)', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        phcCompleteClips();
        $before = phcCalls()->count();
        $media = app(PetMediaService::class);

        expect($media->generateKeyframe(phcSlot($pet, 'pose', 'sit')->id))->toBeTrue()
            ->and($media->submitVideo(phcSlot($pet, 'phase', 'school_sit_success')->id))->toBeTrue()
            ->and($media->queueStateVideos($pet->fresh()))->toBe(0)
            ->and(phcCalls()->count())->toBe($before);
    });

    it('the readiness of the school = all its clips; the ball game needs its phase clips (v2) — the old list for v1', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = phcBirth(phcPet());
        $readiness = app(GameMediaReadiness::class);

        expect($readiness->requiredClips($pet, GameMediaReadiness::SCHOOL))->toHaveCount(12)
            ->and($readiness->requiredClips($pet, GameMediaReadiness::PLAY))->toBe(['ball_waiting', 'ball_running', 'ball_fetching'])
            ->and($readiness->ready($pet, GameMediaReadiness::PLAY))->toBeFalse()
            ->and(app(PlayService::class)->mediaReady($pet))->toBeFalse();

        // Stored (own or linked) phase clips make it ready.
        foreach (['ball_waiting', 'ball_running', 'ball_fetching'] as $clip) {
            app(PetMediaService::class)->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::from($clip))
                ->update(['status' => 'ready', 'storage_path' => "{$pet->id}/{$clip}-g1.mp4"]);
        }
        expect($readiness->ready($pet->fresh(), GameMediaReadiness::PLAY))->toBeTrue()
            ->and($readiness->missing($pet->fresh(), GameMediaReadiness::SCHOOL))->toHaveCount(12);

        // A pet from before M5-T08 keeps the M5-T04 rule (playing + idle videos).
        $old = phcPet();
        $old->forceFill(['state_media_version' => 1])->saveQuietly();
        expect($readiness->requiredClips($old->fresh(), GameMediaReadiness::PLAY))->toEqualCanonicalizing(['playing', 'idle'])
            ->and($readiness->requiredClips($old->fresh(), GameMediaReadiness::SCHOOL))->toBe([]);
    });

    it('switched off: queued pose / phase jobs, the daily retry and the sweep make zero fal calls; on again resumes them', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = phcBirth(phcPet());
        $media = app(PetMediaService::class);
        $pose = $media->slot($pet, PetMedia::KIND_POSE, PetPose::Sit);
        $phase = $media->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::SchoolSitAttention);
        $before = phcCalls()->count();

        phcSwitch(false);

        (new GeneratePetKeyframe($pose->id))->handle($media);
        (new SubmitPetStateVideo($phase->id))->handle($media);
        expect($pose->fresh()->status)->toBe('pending')
            ->and($phase->fresh()->status)->toBe('pending')
            // The readiness never waits for clips that will not come while off (old rules).
            ->and(app(GameMediaReadiness::class)->requiredClips($pet, GameMediaReadiness::PLAY))->toEqualCanonicalizing(['playing', 'idle']);

        $pose->update(['status' => 'failed', 'error_reason' => 'budget_daily']);
        $phase->update(['status' => 'failed', 'error_reason' => 'budget_daily']);
        expect(app(ReferenceImageRetryService::class)->retryDueWithVideos()['videos'])->toBe(0);

        PetMedia::whereKey([$pose->id, $phase->id])->update(['status' => 'running', 'request_id' => null, 'source_url' => null, 'updated_at' => now()->subHour()]);
        expect($media->sweepStale()['reclaimed'])->toBe(0)
            ->and(phcCalls()->count())->toBe($before);

        // On again: the sweep picks up the dead claims; the pose is generated.
        phcSwitch(true);
        expect($media->sweepStale()['reclaimed'])->toBe(2)
            ->and($media->generateKeyframe($pose->id))->toBeTrue()
            ->and(phcCalls('https://fal.run/fal-ai/nano-banana-pro/edit')->filter(fn (Request $r) => str_contains((string) $r['prompt'], 'sitting upright'))->count())->toBe(1);
    });

    it('a pet from before M5-T08 gets no pose image or phase clip automatically; the admin upgrade plans them with their price', function () {
        phcFakeFal();
        $pet = phcPet();
        $pet->forceFill(['state_media_version' => 1])->saveQuietly();
        $pet = phcBirth($pet->fresh());
        phcCompleteClips();

        expect(PetMedia::where('pet_id', $pet->id)->whereIn('kind', ['pose', 'phase'])->count())->toBe(0);

        $plan = app(PetMediaService::class)->planMissing($pet->fresh(), upgradeStateMedia: true);
        expect($plan['poses'])->toHaveCount(5)
            ->and($plan['phases'])->toHaveCount(15)
            ->and($plan['cost_usd'])->toBe(round(10 * 0.15 + 8 * 0.56 + 15 * 0.336, 4))
            ->and(app(PetMediaService::class)->planMissing($pet->fresh())['phases'])->toBe([]);
    });

    it('regenerating a pose image remakes only the phase clips through it', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        phcCompleteClips();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);

        expect(app(PetMediaService::class)->regenerate(phcSlot($pet, 'pose', 'sit')))->toBeTrue();

        $reset = PetMedia::where('pet_id', $pet->id)->where('kind', 'phase')->where('status', 'pending')->pluck('state')->sort()->values()->all();
        expect($reset)->toBe(['school_sit_performing', 'school_sit_success']);
    });
});

describe('shared look of breed + colour', function () {
    it('the first buyer makes the pose images and phase clips once; the next buyer of the same breed + colour + stage costs 0 $', function () {
        phcFakeFal();
        $a = phcBirth(phcPet('beagle', 'tricolour'));
        phcCompleteClips();
        $first = round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2);
        $calls = phcCalls()->count();

        expect($first)->toBe(11.17)
            ->and(PetMedia::whereNotNull('pet_look_id')->where('kind', 'pose')->count())->toBe(5)
            ->and(PetMedia::whereNotNull('pet_look_id')->where('kind', 'phase')->count())->toBe(15);

        $b = phcBirth(phcPet('beagle', 'tricolour'));
        phcCompleteClips();

        expect(phcCalls()->count())->toBe($calls)
            ->and(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe($first)
            ->and(phcSlot($b, 'phase', 'ball_running')->storage_path)->toBe(phcSlot($a, 'phase', 'ball_running')->storage_path)
            ->and(phcSlot($b, 'pose', 'empty')->storage_path)->toStartWith("looks/{$a->pet_look_id}/")
            ->and(array_keys((array) app(PetMediaService::class)->payloadFor($b->fresh())['phases']))->toHaveCount(15)
            ->and(app(GameMediaReadiness::class)->ready($b->fresh(), GameMediaReadiness::PLAY))->toBeTrue();
    });

    it('a cat: wand clips from the crouch, no dog clip ever; payload only the cat\'s', function () {
        phcFakeFal();
        $cat = phcBirth(phcPet('maine_coon', 'brown'));
        phcCompleteClips();

        $phases = PetMedia::where('pet_id', $cat->id)->where('kind', 'phase')->pluck('state')->all();
        expect($phases)->toEqualCanonicalizing(['wand_stalking', 'wand_pouncing', 'wand_catching'])
            ->and(PetMedia::where('pet_id', $cat->id)->where('kind', 'pose')->pluck('state')->all())->toEqualCanonicalizing(['crouch', 'catch'])
            ->and(phcCalls()->map(fn (Request $r) => (string) ($r['prompt'] ?? ''))->implode(' '))->not->toContain(' dog ')
            ->and(app(GameMediaReadiness::class)->requiredClips($cat->fresh(), GameMediaReadiness::WAND))->toBe(['wand_stalking', 'wand_pouncing', 'wand_catching'])
            ->and(app(GameMediaReadiness::class)->ready($cat->fresh(), GameMediaReadiness::WAND))->toBeTrue()
            // A cat's play is cuddles only: no ball clips, the M5-T04 list.
            ->and(app(GameMediaReadiness::class)->requiredClips($cat->fresh(), GameMediaReadiness::PLAY))->toEqualCanonicalizing(['playing', 'idle']);
    });
});

describe('prompts (no personal data, no toy)', function () {
    it('pose and phase prompts come from breed + traits only and never name a ball or a feather', function () {
        $prompts = app(PetAppearancePrompt::class);
        $traits = ['coat_color' => 'black'];

        foreach (PetPose::cases() as $pose) {
            $breed = $pose->species()->value === 'cat' ? 'maine_coon' : 'labrador_retriever';
            $text = $prompts->posePrompt($breed, $pose, $traits, LifeStage::Young);
            expect($text)->not->toContain('ball')->not->toContain('feather')->toContain('no people');
        }

        foreach (PetPhaseClip::cases() as $clip) {
            $breed = $clip->species()->value === 'cat' ? 'maine_coon' : 'labrador_retriever';
            $text = $prompts->phaseVideoPrompt($breed, $clip, $traits, LifeStage::Young);
            expect($text)->toContain('No toys, no ball, no feather')->toContain('No people');
        }
    });

    it('a state the M5-T08 key images use is unchanged (pose images are a separate kind)', function () {
        expect(PetStateEnum::Playing->keyPose())->toContain('playful bow');
    });
});
