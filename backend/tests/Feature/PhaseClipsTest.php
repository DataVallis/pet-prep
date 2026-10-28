<?php

use App\Enums\BreedType;
use App\Enums\CareRefusal;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPhaseClip;
use App\Enums\PetPlan;
use App\Enums\PetPose;
use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
use App\Enums\TrainingCommand;
use App\Events\PetUpdated;
use App\Filament\Pages\FeatureSwitches;
use App\Jobs\GeneratePetKeyframe;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\ResumeStateMedia;
use App\Jobs\SendActivityMediaAlert;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AiSpendLedger;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\Alerts\DiscordWebhook;
use App\Services\AppSettingsService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\ActivityClipRetryService;
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
use App\Services\TrainingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
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
| once by the app. Only pets that use state media (paid full set, version 3,
| both switches on); made on the first purchase of breed + colour + stage and
| shared (0 $ later); after the first-minutes set: the games, then the school (QA m1).
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
        // Life-stage growth edits (editReferenceImage) stay sync.
        'fal.run/fal-ai/nano-banana-pro/edit' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/pc/edit-{$n}.jpg"]]]);
        },
        // Fix 2026-10-28: key / pose images are queued (result via completeFalKeyImages()).
        'queue.fal.run/fal-ai/nano-banana-pro/edit*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "pc-edit-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/pc-edit-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/pc-edit-{$n}"]);
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
        // Fix 2026-10-28: queued key / pose images first (a stored one queues its clips).
        $keys = completeFalKeyImages('https://v3.fal.media/files/pc/');
        $rows = PetMedia::query()->whereIn('kind', ['video', 'transition', 'phase'])
            ->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();

        if ($rows->isEmpty() && $keys === 0) {
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
            $attention = PetPhaseClip::from("school_{$command->value}_attention");
            $performing = PetPhaseClip::from("school_{$command->value}_performing");
            $success = PetPhaseClip::from("school_{$command->value}_success");

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

    it('a paid dog with training gets 3 ball clips then 12 school clips (the game first, QA m1) and their 5 poses', function () {
        config(['services.fal_ai.key' => null]);
        $pet = phcPet();
        $e = app(MediaEntitlementService::class);

        $clips = array_map(fn ($c) => $c->value, $e->phaseClipsFor($pet));
        expect($clips)->toHaveCount(15)
            ->and(array_slice($clips, 0, 3))->toBe(['ball_waiting', 'ball_running', 'ball_fetching'])
            ->and(array_slice($clips, 3))->each->toStartWith('school_')
            ->and(array_map(fn ($p) => $p->value, $e->posesFor($pet)))->toBe(['empty', 'sit', 'come', 'place', 'door'])
            // The shipped config has the same order (the helper only mirrors it).
            ->and((require config_path('media.php'))['state_media']['phase_clips'])->toBe(['ball', 'wand', 'school']);
    });

    it('a paid cat gets the 3 wand clips and 2 poses — no school, no ball', function () {
        config(['services.fal_ai.key' => null]);
        $cat = phcPet('maine_coon', 'brown');
        $e = app(MediaEntitlementService::class);

        expect(array_map(fn ($c) => $c->value, $e->phaseClipsFor($cat)))->toBe(['wand_stalking', 'wand_pouncing', 'wand_catching'])
            ->and(array_map(fn ($p) => $p->value, $e->posesFor($cat)))->toBe(['crouch', 'catch']);
    });

    it('a pet made between the M5-T08 and M5-T09 releases (version 2) keeps its set — no phase clips until a purchase / admin upgrade', function () {
        config(['services.fal_ai.key' => null]);
        $pet = phcPet();
        expect($pet->state_media_version)->toBe(MediaEntitlementService::PHASE_CLIPS_VERSION);

        $pet->forceFill(['state_media_version' => 2])->saveQuietly();
        $e = app(MediaEntitlementService::class);
        expect($e->usesStateMedia($pet->fresh()))->toBeTrue()
            ->and($e->phaseClipsFor($pet->fresh()))->toBe([])
            ->and(app(PetMediaService::class)->payloadFor($pet->fresh())['phase_groups'])->toBe([]);

        // The admin »Upgrade to state media« offers and applies version 3.
        $plan = app(PetMediaService::class)->planMissing($pet->fresh(), upgradeStateMedia: true);
        expect($plan['upgrade'])->toBeTrue();
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
            // The set itself (admin) does not depend on the switch …
            ->and($e->phaseClipsOfSet($pet))->toHaveCount(15)
            // … but the payload follows readiness (QA m2): off → the M5-T08 stage in the app.
            ->and(app(PetMediaService::class)->payloadFor($pet)['phase_groups'])->toBe([]);
    });

    it('school and game clips are 3 s (0.336 $), school performing + state clips 5 s (0.56 $) — a per-purpose length', function () {
        $profiles = app(MediaProfiles::class);

        expect($profiles->clipVideo('school')->durationSeconds)->toBe(3)
            // David 2026-10-23: the school PERFORMING clips (into sit / come / place / door) are 5 s.
            ->and($profiles->clipVideo('school_performing')->durationSeconds)->toBe(5)
            ->and($profiles->clipVideo('school_performing')->estimatedCostUsd())->toBe(0.56)
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

    it('order: first-minutes set → transitions → ball → school; poses before their clips; 3 s clips from pose to pose; cost', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        phcCompleteClips();

        $queue = phcCalls('https://queue.fal.run/fal-ai/kling-video/')->values();
        $prompts = $queue->map(fn (Request $r) => (string) $r['prompt']);
        $kind = fn (string $p): string => match (true) {
            str_contains($p, 'No toys, no ball, no feather') => 'phase',
            str_contains($p, 'pose of the end image') => 'transition',
            default => 'loop',
        };
        $kinds = $prompts->map($kind)->values()->all();

        // The first-minutes loops (idle, sleeping, hungry) and the transitions are queued before the
        // first phase clip; the ball before the school. Since 2026-10-28 key images arrive on the fal
        // webhook, so a later state's loop (its key image still at fal) may follow a phase clip.
        $firstPhase = array_search('phase', $kinds, true);
        expect($firstPhase)->not->toBeFalse()
            ->and(array_slice($kinds, 0, 3))->each->toBe('loop')
            ->and(array_search('transition', $kinds, true))->toBeLessThan($firstPhase)
            ->and(array_count_values($kinds))->toBe(['loop' => 6, 'transition' => 2, 'phase' => 15]);

        $phaseRows = PetMedia::where('pet_id', $pet->id)->where('kind', 'phase')->orderBy('id')->get();
        expect($phaseRows)->toHaveCount(15)
            ->and($phaseRows->pluck('status')->unique()->all())->toBe(['ready'])
            // 4 school performing clips 5 s (David 2026-10-23), the other 11 phase clips 3 s.
            ->and($phaseRows->countBy(fn (PetMedia $r) => (string) $r->duration_seconds)->sortKeys()->all())->toBe(['3' => 11, '5' => 4]);

        // 3 s at Kling (duration "3"; performing "5"), start / end frame = the clip's poses.
        $phaseSubmits = $queue->filter(fn (Request $r) => $kind((string) $r['prompt']) === 'phase')->values();
        expect($phaseSubmits->pluck('duration')->countBy()->sortKeys()->all())->toBe(['3' => 11, '5' => 4])
            // The toy is drawn by the app: the prompt only forbids it.
            ->and((string) $phaseSubmits[0]['prompt'])->toContain('No toys, no ball, no feather');

        $byPrompt = fn (string $needle) => $phaseSubmits->first(fn (Request $r) => str_contains((string) $r['prompt'], $needle));
        $sitDown = $byPrompt('and sits down in the same spot');
        $sitHold = $byPrompt('stays sitting proudly');
        // Fix 2026-10-23: a same-pose clip (success pose → pose) gets NO end frame; performing keeps it.
        expect($sitDown['end_image_url'])->toBe($sitHold['start_image_url'])
            ->and($sitHold->data())->not->toHaveKey('end_image_url')
            ->and($sitDown['start_image_url'])->not->toBe($sitDown['end_image_url']);

        // Pose images: Nano Banana Pro Edit of the reference image, after the key images.
        $edits = phcCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->map(fn (Request $r) => (string) $r['prompt'])->values();
        expect($edits)->toHaveCount(5 + 5)
            ->and($edits->slice(5)->values()[0])->toContain('WITHOUT the dog')
            ->and($edits->slice(5)->values()[1])->toContain('sitting upright')
            ->and($edits->implode(' '))->not->toContain('ball');

        // 1 + 5 key images + 5 poses (0.15 $) · 6 loops + 2 transitions + 4 school performing (0.56 $) · 11 phase clips (0.336 $).
        $expected = round(11 * 0.15 + 12 * 0.56 + 11 * 0.336, 2);
        expect(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe($expected)
            ->and($expected)->toBe(12.07)
            ->and(phcSlot($pet, 'pose', 'sit')->storage_path)->toBe("{$pet->id}/pose-sit-g1.jpg")
            ->and(phcSlot($pet, 'phase', 'school_sit_success')->storage_path)->toBe("{$pet->id}/school_sit_success-g1.mp4");

        // The payload carries the poses and phase clips (signed, never a fal URL).
        $media = app(PetMediaService::class)->payloadFor($pet->fresh());
        expect(array_keys((array) $media['poses']))->toEqualCanonicalizing(['sit', 'come', 'place', 'door', 'empty'])
            ->and(array_keys((array) $media['phases']))->toHaveCount(15)
            ->and(collect((array) $media['phases'])->first())->toStartWith('https://api.petprep.si/api/media/')
            ->and($media['phase_groups'])->toBe(['ball', 'school'])
            ->and(json_encode($media))->not->toContain('fal.media');

        // The broadcast of the full dog set fits Reverb's HTTP publish limit with room for headers.
        $publish = json_encode(['name' => 'pet.updated', 'data' => json_encode(PetUpdated::payloadFor($pet->fresh(), 'video_ready')), 'channels' => ["private-pet.{$pet->id}"]]);
        expect(strlen($publish))->toBeLessThan((int) config('reverb.servers.reverb.max_request_size') - 4096);

        // QA m6: the preparation ends with the transitions as before (no phase clip in it).
        $keys = app(PetPreparationService::class)->expectedKeys($pet->fresh());
        expect(end($keys))->toBe('sleeping_to_idle')
            ->and(array_filter($keys, fn (string $k) => PetPhaseClip::tryFrom($k) !== null))->toBe([])
            ->and(app(PetPreparationService::class)->summary($pet->fresh())->ready)->toBeTrue()
            ->and(app(PetPreparationService::class)->summary($pet->fresh())->schoolPending)->toBeFalse();
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

    it('the readiness of the school = all its clips; play needs its ball phase clips AND the playing loop (v3) — the old list for v1', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = phcBirth(phcPet());
        $readiness = app(GameMediaReadiness::class);

        expect($readiness->requiredClips($pet, GameMediaReadiness::SCHOOL))->toHaveCount(12)
            // QA m1: the cuddles share the play stage — the playing loop (and idle) stay required.
            ->and($readiness->requiredClips($pet, GameMediaReadiness::PLAY))->toBe(['ball_waiting', 'ball_running', 'ball_fetching', 'playing', 'idle'])
            ->and($readiness->ready($pet, GameMediaReadiness::PLAY))->toBeFalse()
            ->and(app(PlayService::class)->mediaReady($pet))->toBeFalse();

        // Stored (own or linked) phase clips alone are not enough: the playing loop too.
        foreach (['ball_waiting', 'ball_running', 'ball_fetching'] as $clip) {
            app(PetMediaService::class)->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::from($clip))
                ->update(['status' => 'ready', 'storage_path' => "{$pet->id}/{$clip}-g1.mp4"]);
        }
        expect($readiness->missing($pet->fresh(), GameMediaReadiness::PLAY))->toEqualCanonicalizing(['playing', 'idle']);
        foreach ([PetStateEnum::Playing, PetStateEnum::Idle] as $state) {
            app(PetMediaService::class)->slot($pet, PetMedia::KIND_VIDEO, $state)
                ->update(['status' => 'ready', 'storage_path' => "{$pet->id}/{$state->value}-g1.mp4"]);
        }
        expect($readiness->ready($pet->fresh(), GameMediaReadiness::PLAY))->toBeTrue()
            ->and($readiness->missing($pet->fresh(), GameMediaReadiness::SCHOOL))->toHaveCount(12)
            // QA m6: the school clips still coming are reported to the app (»Šola se še pripravlja«).
            ->and(app(PetPreparationService::class)->summary($pet->fresh())->toArray()['school_pending'])->toBeTrue();

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
            ->and(phcCalls('https://queue.fal.run/fal-ai/nano-banana-pro/edit')->filter(fn (Request $r) => str_contains((string) $r['prompt'], 'sitting upright'))->count())->toBe(1);
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
            ->and($plan['cost_usd'])->toBe(round(10 * 0.15 + 12 * 0.56 + 11 * 0.336, 4))
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

        expect($first)->toBe(12.07)
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

    it('a look row of a phase clip / pose is never generated for a look whose only pet is on version 2 (sweep reclaim, 0 calls)', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = phcBirth(phcPet('beagle', 'tricolour'));
        $pet->forceFill(['state_media_version' => 2])->saveQuietly();
        $media = app(PetMediaService::class);
        $look = $pet->fresh()->look;
        $pose = $media->lookSlot($look, PetMedia::KIND_POSE, PetPose::Sit, LifeStage::Young);
        $clip = $media->lookSlot($look, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning, LifeStage::Young);
        $before = phcCalls()->count();

        (new GeneratePetKeyframe($pose->id))->handle($media);
        (new SubmitPetStateVideo($clip->id))->handle($media);

        expect(phcCalls()->count())->toBe($before)
            ->and($pose->fresh()->status)->toBe('pending')
            ->and($clip->fresh()->status)->toBe('pending');
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
            // Cost of the cat set (scratching is in every profiled cat's full set): 1 + 6 key images
            // + 2 poses (0.15 $) · 7 loops + 2 transitions (0.56 $) · 3 wand clips (0.336 $) = 7.40 $.
            ->and(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe(round(9 * 0.15 + 9 * 0.56 + 3 * 0.336, 2))
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

            if ($pose !== PetPose::Empty) {
                // Fix 2026-10-27: no half-old / half-new pose with extra legs.
                expect($text)->toContain('exactly four legs and four paws');
            }
        }

        expect($prompts->posePrompt('labrador_retriever', PetPose::Sit, $traits, LifeStage::Young))
            ->toContain('hindquarters resting on the floor')->toContain('sitting upright');

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

/* ─────────────────────────── QA fixes (PR #332 review) ─────────────────────────── */

function phcPhaseSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::PHASE_CLIPS_KEY], ['value' => ['enabled' => $on]]);
    Cache::forget(AppSettingsService::cacheKey(AppSettingsService::PHASE_CLIPS_KEY));
}

describe('QA M2: »School & game clips« superadmin switch', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('Feature switches: on by default; off is saved audited; the payload / readiness use the M5-T08 rules', function () {
        config(['services.fal_ai.key' => null]);
        $pet = phcPet();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);
        $readiness = app(GameMediaReadiness::class);

        Livewire\Livewire::test(FeatureSwitches::class)->assertFormSet(['phase_clips' => true])->call('save')->assertHasNoFormErrors();
        expect(AppSetting::find(AppSettingsService::PHASE_CLIPS_KEY))->toBeNull()
            ->and($readiness->requiredClips($pet, GameMediaReadiness::PLAY))->toContain('ball_running');

        Livewire\Livewire::test(FeatureSwitches::class)->fillForm(['phase_clips' => false])->call('save')->assertHasNoFormErrors()->assertNotified();

        expect(app(AppSettingsService::class)->phaseClips())->toBeFalse()
            ->and(app(AppSettingsService::class)->phaseClips(fresh: true))->toBeFalse()
            ->and(AppSettingChange::where('key', AppSettingsService::PHASE_CLIPS_KEY)->sole()->user_id)->toBe($admin->id)
            // State media stays on — only the phase clips are off.
            ->and(app(MediaEntitlementService::class)->usesStateMedia($pet))->toBeTrue()
            ->and(app(MediaEntitlementService::class)->phaseClipsFor($pet))->toBe([])
            // Server and app agree (QA m2): no phase group, the v2 game list, no school readiness.
            ->and(app(PetMediaService::class)->payloadFor($pet)['phase_groups'])->toBe([])
            ->and($readiness->requiredClips($pet, GameMediaReadiness::PLAY))->toEqualCanonicalizing(['playing', 'idle'])
            ->and($readiness->requiredClips($pet, GameMediaReadiness::SCHOOL))->toBe([]);
    });

    it('off: zero pose / phase fal calls — queue, queued jobs, retry, sweep, regenerate; on again resumes them', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class, ResumeStateMedia::class]);
        phcPhaseSwitch(false);
        $pet = phcBirth(phcPet());
        $media = app(PetMediaService::class);

        // The pipeline queues no pose image / phase clip (state media itself still runs).
        expect(PetMedia::where('pet_id', $pet->id)->whereIn('kind', ['pose', 'phase'])->count())->toBe(0)
            ->and(PetMedia::where('pet_id', $pet->id)->where('kind', 'keyframe')->count())->toBeGreaterThan(0);

        // Slots queued while it was on: their jobs make no fal call.
        $pose = $media->slot($pet, PetMedia::KIND_POSE, PetPose::Sit);
        $phase = $media->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallWaiting);
        $before = phcCalls()->count();
        (new GeneratePetKeyframe($pose->id))->handle($media);
        (new SubmitPetStateVideo($phase->id))->handle($media);
        expect($pose->fresh()->status)->toBe('pending')
            ->and($phase->fresh()->status)->toBe('pending');

        $pose->update(['status' => 'failed', 'error_reason' => 'budget_daily']);
        $phase->update(['status' => 'failed', 'error_reason' => 'budget_daily']);
        expect(app(ReferenceImageRetryService::class)->videoRetryCandidates()->whereIn('kind', ['pose', 'phase'])->count())->toBe(0);
        app(ReferenceImageRetryService::class)->retryDueWithVideos();

        PetMedia::whereKey([$pose->id, $phase->id])->update(['status' => 'running', 'request_id' => null, 'source_url' => null, 'updated_at' => now()->subHour()]);
        $media->sweepStale();
        expect($pose->fresh()->status)->toBe('running')
            ->and($media->regenerate($pose->fresh()))->toBeFalse()
            ->and(phcCalls()->filter(fn (Request $r) => str_contains((string) ($r['prompt'] ?? ''), 'sitting upright') || str_contains((string) ($r['prompt'] ?? ''), 'No toys, no ball'))->count())->toBe(0)
            ->and(phcCalls()->count())->toBe($before);

        // On again (the admin switch): the waiting items are resumed.
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        app(AppSettingsService::class)->updatePhaseClips($admin, true);
        Queue::assertPushed(ResumeStateMedia::class);
    });
});

describe('home first, school and games locked (David 2026-10-28)', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    /** Store a clip / pose slot of the pet (own file). */
    function phcStore(Pet $pet, string $kind, PetStateEnum|PetPose|PetPhaseClip $state): void
    {
        $ext = $kind === PetMedia::KIND_POSE ? 'jpg' : 'mp4';
        app(PetMediaService::class)->slot($pet, $kind, $state)
            ->update(['status' => 'ready', 'storage_path' => "{$pet->id}/{$state->value}-g1.{$ext}", 'error_reason' => null]);
    }

    it('a FAILED ball phase clip or pose keeps the game locked — never the older list again', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = phcBirth(phcPet());
        $media = app(PetMediaService::class);
        $readiness = app(GameMediaReadiness::class);
        foreach ([PetStateEnum::Playing, PetStateEnum::Idle] as $state) {
            phcStore($pet, PetMedia::KIND_VIDEO, $state);
        }

        // Pending ball clips: waited for.
        expect($readiness->ready($pet->fresh(), GameMediaReadiness::PLAY))->toBeFalse();

        // fal reported a failed generation → still locked, the clip is reported as failed.
        $media->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning)->update(['status' => 'failed', 'error_reason' => 'generation_failed']);
        expect($readiness->requiredClips($pet->fresh(), GameMediaReadiness::PLAY))->toBe(['ball_waiting', 'ball_running', 'ball_fetching', 'playing', 'idle'])
            ->and($readiness->ready($pet->fresh(), GameMediaReadiness::PLAY))->toBeFalse()
            ->and(app(PlayService::class)->mediaReady($pet->fresh()))->toBeFalse()
            ->and($readiness->activities($pet->fresh())['play']['failed'])->toBe(['ball_running']);

        // The same for a failed pose image a ball clip is made from (the empty room).
        $media->slot($pet, PetMedia::KIND_POSE, PetPose::Empty)->update(['status' => 'failed', 'error_reason' => 'generation_failed']);
        expect($readiness->ready($pet->fresh(), GameMediaReadiness::PLAY))->toBeFalse()
            ->and($readiness->activities($pet->fresh())['play']['failed'])->toEqualCanonicalizing(['ball_running', 'empty']);

        // Every ball clip shown → open.
        foreach ([PetPhaseClip::BallWaiting, PetPhaseClip::BallRunning, PetPhaseClip::BallFetching] as $clip) {
            phcStore($pet, PetMedia::KIND_PHASE, $clip);
        }
        expect($readiness->ready($pet->fresh(), GameMediaReadiness::PLAY))->toBeTrue();
    });

    it('the school is a start condition: training refused with media_not_ready until every school clip is shown (also after a failure)', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = phcBirth(phcPet());
        disableHygieneEvents($pet);
        $noon = CarbonImmutable::parse('today 12:00', $pet->familyTimezone())->utc();
        Carbon\Carbon::setTestNow($noon);
        $training = app(TrainingService::class);
        $clips = array_values(array_filter(app(MediaEntitlementService::class)->phaseClipsFor($pet), fn (PetPhaseClip $c) => $c->group() === PetPhaseClip::GROUP_SCHOOL));

        expect($pet->trainingEnabled())->toBeTrue()
            ->and($training->mediaReady($pet))->toBeFalse()
            ->and($training->startRefusal($pet->fresh(), null, $noon)['refusal'] ?? null)->toBe(CareRefusal::MediaNotReady);

        // All but one stored, the last one failed → still locked.
        foreach ($clips as $i => $clip) {
            $i === 0
                ? app(PetMediaService::class)->slot($pet, PetMedia::KIND_PHASE, $clip)->update(['status' => 'failed', 'error_reason' => 'http_error'])
                : phcStore($pet, PetMedia::KIND_PHASE, $clip);
        }
        expect($training->startRefusal($pet->fresh(), null, $noon)['refusal'] ?? null)->toBe(CareRefusal::MediaNotReady)
            ->and(app(PetPreparationService::class)->summary($pet->fresh())->schoolPending)->toBeFalse();

        phcStore($pet, PetMedia::KIND_PHASE, $clips[0]);
        expect($training->mediaReady($pet->fresh()))->toBeTrue()
            ->and($training->startRefusal($pet->fresh(), null, $noon))->toBeNull();
    });

    it('pets without school / game clips keep their rules: v1 pet, switch off — the school is always ready', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $old = phcPet();
        $old->forceFill(['state_media_version' => 1])->saveQuietly();
        $readiness = app(GameMediaReadiness::class);
        expect($readiness->ready($old->fresh(), GameMediaReadiness::SCHOOL))->toBeTrue()
            ->and($readiness->activities($old->fresh()))->toBe([]);

        $pet = phcBirth(phcPet());
        expect($readiness->ready($pet, GameMediaReadiness::SCHOOL))->toBeFalse();
        usePhaseClips(false);
        expect($readiness->ready($pet->fresh(), GameMediaReadiness::SCHOOL))->toBeTrue()
            ->and($readiness->requiredClips($pet->fresh(), GameMediaReadiness::PLAY))->toEqualCanonicalizing(['playing', 'idle']);
    });

    it('order: home key images first, the pose images right behind them, no phase clip while a home key image is on its way', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = phcBirth(phcPet());

        // One call (the reference image is stored): every home key image, then every pose image.
        $dispatched = Queue::pushed(GeneratePetKeyframe::class)->map(fn (GeneratePetKeyframe $j) => PetMedia::find($j->petMediaId))->values();
        $kinds = $dispatched->pluck('kind')->all();
        $firstPose = array_search('pose', $kinds, true);
        expect($firstPose)->toBe(5)
            ->and(array_slice($kinds, 0, 5))->each->toBe('keyframe')
            ->and(array_slice($kinds, 5))->each->toBe('pose')
            ->and(count($kinds))->toBe(10);

        // Only the idle loop is submitted; no phase clip (not even ref → ref school attention).
        $submitted = Queue::pushed(SubmitPetStateVideo::class)->map(fn (SubmitPetStateVideo $j) => PetMedia::find($j->petMediaId))->values();
        expect($submitted->pluck('kind')->unique()->values()->all())->toBe(['video'])
            ->and($submitted->pluck('state')->all())->toBe(['idle']);

        // Every home key image stored (poses still coming) → the home loops, the transitions and
        // the clips without poses are submitted; home items before any phase clip.
        foreach (PetMedia::where('pet_id', $pet->id)->where('kind', 'keyframe')->get() as $key) {
            $key->update(['status' => 'ready', 'storage_path' => "{$pet->id}/key-{$key->state}.jpg", 'source_generation' => 1]);
        }
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        app(PetMediaService::class)->queueStateVideos($pet->fresh());
        $kinds = Queue::pushed(SubmitPetStateVideo::class)->map(fn (SubmitPetStateVideo $j) => PetMedia::find($j->petMediaId)->kind)->values()->all();
        $firstPhase = array_search('phase', $kinds, true);
        expect($firstPhase)->not->toBeFalse()
            ->and(array_slice($kinds, 0, $firstPhase))->not->toContain('phase')
            ->and(array_count_values(array_slice($kinds, 0, $firstPhase)))->toBe(['video' => 5, 'transition' => 2])
            ->and(array_slice($kinds, $firstPhase))->each->toBe('phase');
    });
});

describe('ActivityClipRetryService: no dead end for a locked activity (David 2026-10-28, QA #434)', function () {
    beforeEach(function () {
        useUniquePetMedia(true);
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class, SendActivityMediaAlert::class]);
    });

    function acrFail(PetMedia $slot, string $reason, int $minutesAgo = 60, array $extra = []): PetMedia
    {
        $slot->update(['status' => 'failed', 'error_reason' => $reason, 'completed_at' => now()->subMinutes($minutesAgo), ...$extra]);

        return $slot->fresh();
    }

    it('retries a transient failure after 30 min, at most 2 automatic retries per chain (cap inside the UPDATE); then reports once per chain', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        $media = app(PetMediaService::class);
        $retry = app(ActivityClipRetryService::class);
        $pose = acrFail($media->slot($pet, PetMedia::KIND_POSE, PetPose::Sit), 'http_error', 5);
        $phase = acrFail($media->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::SchoolSitAttention), 'generation_failed', 60, ['request_id' => 'old-req', 'attempts' => 7]);

        // The pose failed 5 min ago: not even read (inside the delay); the phase clip: retried —
        // a high lifetime `attempts` does not matter (M1).
        $result = $retry->run();
        expect($result['retried'])->toBe(1)
            ->and($result['reported'])->toBe(0)
            ->and($phase->fresh()->status)->toBe('pending')
            ->and($phase->fresh()->auto_retries)->toBe(1)
            ->and($phase->fresh()->request_id)->toBeNull();
        Queue::assertPushed(SubmitPetStateVideo::class, fn ($j) => $j->petMediaId === $phase->id);

        // Second failure → second retry; third failure → out of retries → reported once.
        acrFail($phase->fresh(), 'generation_failed');
        expect($retry->run()['retried'])->toBe(1)->and($phase->fresh()->auto_retries)->toBe(2);
        acrFail($phase->fresh(), 'generation_failed');
        expect($retry->run(dryRun: true)['reported'])->toBe(1)
            ->and($phase->fresh()->auto_alerted_at)->toBeNull()
            ->and($retry->run()['reported'])->toBe(1)
            ->and($retry->run()['reported'])->toBe(0)
            ->and($phase->fresh()->status)->toBe('failed')
            ->and($phase->fresh()->auto_alerted_at)->not->toBeNull();
        Queue::assertPushed(SendActivityMediaAlert::class, 1);

        // m5: the cap is part of the conditional UPDATE — a stale model can never pass it.
        expect($media->resetForNewGeneration($phase->fresh(), bump: true, autoRetryBelow: ActivityClipRetryService::MAX_AUTO_RETRIES))->toBeFalse();
    });

    it('M1: a stage remake and an admin Regenerate start a new chain (counter and alert cleared)', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        $media = app(PetMediaService::class);
        $phase = acrFail($media->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallWaiting), 'generation_failed', 60, ['auto_retries' => 2, 'auto_alerted_at' => now()]);

        // Admin Regenerate (own media).
        expect($media->regenerate($phase))->toBeTrue()
            ->and($phase->fresh()->auto_retries)->toBe(0)
            ->and($phase->fresh()->auto_alerted_at)->toBeNull();

        // Stage remake: a new reference image generation remakes the clip (queueClip → reset).
        acrFail($phase->fresh(), 'generation_failed', 60, ['auto_retries' => 2, 'auto_alerted_at' => now(), 'source_generation' => 1]);
        $image = $media->imageSlot($pet);
        $image->update(['generation' => $image->generation + 1]);
        foreach (PetMedia::where('pet_id', $pet->id)->whereIn('kind', ['keyframe', 'pose'])->get() as $still) {
            $still->update(['status' => 'ready', 'storage_path' => "{$pet->id}/{$still->kind}-{$still->state}.jpg", 'source_generation' => $image->generation]);
        }
        $media->queueStateVideos($pet->fresh());
        expect($phase->fresh()->status)->toBe('pending')
            ->and($phase->fresh()->auto_retries)->toBe(0)
            ->and($phase->fresh()->auto_alerted_at)->toBeNull();
    });

    it('M2: stale rows never clog the scan — the action cap counts actions, not rows read', function () {
        phcFakeFal();
        $retry = app(ActivityClipRetryService::class);
        $media = app(PetMediaService::class);

        // Older rows that need nothing: not wanted any more (training off), reported, in the delay.
        $off = phcBirth(phcPet());
        foreach ([PetPhaseClip::SchoolSitAttention, PetPhaseClip::SchoolComeAttention, PetPhaseClip::SchoolPlaceAttention] as $clip) {
            acrFail($media->slot($off, PetMedia::KIND_PHASE, $clip), 'generation_failed');
        }
        $off->forceFill(['training_enabled' => false])->saveQuietly();
        $other = phcBirth(phcPet());
        acrFail($media->slot($other, PetMedia::KIND_PHASE, PetPhaseClip::BallWaiting), 'quality_failed', 60, ['auto_alerted_at' => now()]);
        acrFail($media->slot($other, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning), 'http_error', 5);

        // The newest failure is still found with a cap of 1 action.
        $pet = phcBirth(phcPet());
        $phase = acrFail($media->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallFetching), 'invalid_response');
        expect($retry->candidates()->pluck('id')->all())->not->toContain($media->slot($other, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning)->id);
        $result = $retry->run(limit: 1);
        expect($result['retried'])->toBe(1)
            ->and($result['items'][0]['pet_id'])->toBe($pet->id)
            ->and($phase->fresh()->status)->toBe('pending');
    });

    it('M3: timed_out is never resubmitted (the old request may still finish) — reported instead', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        $phase = acrFail(app(PetMediaService::class)->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning), 'timed_out', 60, ['request_id' => 'lost-req']);

        $result = app(ActivityClipRetryService::class)->run();
        expect($result['retried'])->toBe(0)
            ->and($result['reported'])->toBe(1)
            ->and($phase->fresh()->status)->toBe('failed')
            ->and($phase->fresh()->request_id)->toBe('lost-req');
        Queue::assertNotPushed(SubmitPetStateVideo::class, fn ($j) => $j->petMediaId === $phase->id);
    });

    it('M4a: a failed reference image of a pet with phase clips is retried (same cap) and then reported', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class, SendActivityMediaAlert::class, GeneratePetReferenceImage::class]);
        $image = app(PetMediaService::class)->imageSlot($pet);
        $image->update(['status' => 'failed', 'error_reason' => 'http_error', 'storage_path' => null, 'completed_at' => now()->subHour()]);
        $pet->updateQuietly(['media_status' => 'failed', 'media_error' => 'http_error']);
        expect(app(GameMediaReadiness::class)->activities($pet->fresh())['school']['failed'])->toContain('reference');

        expect(app(ActivityClipRetryService::class)->run()['retried'])->toBe(1)
            ->and($image->fresh()->status)->toBe('pending')
            ->and($image->fresh()->auto_retries)->toBe(1)
            ->and($pet->fresh()->media_status)->toBe('pending');
        Queue::assertPushed(GeneratePetReferenceImage::class, fn ($j) => $j->petId === $pet->id);

        $image->fresh()->update(['status' => 'failed', 'error_reason' => 'http_error', 'completed_at' => now()->subHour(), 'auto_retries' => 2]);
        expect(app(ActivityClipRetryService::class)->run()['reported'])->toBe(1);
    });

    it('M4b: a READY clip flagged by the quality check (locks the game again) is reported, never retried', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        $phase = app(PetMediaService::class)->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallWaiting);
        $phase->update(['status' => 'ready', 'storage_path' => "{$pet->id}/ball_waiting-g1.mp4", 'quality' => PetMedia::QUALITY_FLAGGED]);

        $result = app(ActivityClipRetryService::class)->run();
        expect($result['reported'])->toBe(1)->and($result['retried'])->toBe(0)
            ->and($phase->fresh()->status)->toBe('ready');
    });

    it('leaves alone: an older shown file (m4), budget reasons (nightly retry), inactive pets, items the pet does not get, switches off', function () {
        phcFakeFal();
        $media = app(PetMediaService::class);
        $retry = app(ActivityClipRetryService::class);
        $pet = phcBirth(phcPet());
        acrFail($media->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallWaiting), 'generation_failed', 60, ['storage_path' => "{$pet->id}/old.mp4"]);
        acrFail($media->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning), 'budget_daily');
        $inactive = phcBirth(phcPet());
        acrFail($media->slot($inactive, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning), 'generation_failed');
        $inactive->forceFill(['is_active' => false])->saveQuietly();
        $noSchool = phcBirth(phcPet());
        acrFail($media->slot($noSchool, PetMedia::KIND_PHASE, PetPhaseClip::SchoolSitSuccess), 'generation_failed');
        $noSchool->forceFill(['training_enabled' => false])->saveQuietly();

        $result = $retry->run();
        expect($result['retried'])->toBe(0)->and($result['reported'])->toBe(0);

        $own = phcBirth(phcPet());
        acrFail($media->slot($own, PetMedia::KIND_PHASE, PetPhaseClip::BallFetching), 'generation_failed');
        usePhaseClips(false);
        expect($retry->run()['retried'])->toBe(0);
    });

    it('m6 + m2: spend is planned against today\'s budget; a shared look row is paid and counted once for all its buyers', function () {
        phcFakeFal();
        useUniquePetMedia(false);
        $media = app(PetMediaService::class);
        $a = phcBirth(phcPet('labrador_retriever', 'black'));
        $b = phcBirth(phcPet('labrador_retriever', 'black'));
        expect($a->pet_look_id)->not->toBeNull()->and($b->pet_look_id)->toBe($a->pet_look_id);
        $look = PetLook::find($a->pet_look_id);
        $row = $media->lookSlot($look, PetMedia::KIND_PHASE, PetPhaseClip::BallWaiting, LifeStage::from((string) ($a->life_stage?->value ?? $a->life_stage)));
        acrFail($row, 'generation_failed');
        foreach ([$a, $b] as $pet) {
            acrFail($media->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallWaiting), 'generation_failed', 60, ['look_media_id' => $row->id]);
        }
        // Room for exactly one phase clip today.
        $spent = (float) AiSpendLedger::query()->counted()->sum('cost_usd');
        config(['media.budget.daily_usd' => $spent + 0.5]);

        $result = app(ActivityClipRetryService::class)->run();
        expect($result['retried'])->toBe(2)
            ->and($result['skipped_budget'])->toBe(0)
            ->and($row->fresh()->auto_retries)->toBe(1);
        expect(Queue::pushed(SubmitPetStateVideo::class, fn ($j) => PetMedia::find($j->petMediaId)?->state === 'ball_waiting')->count())->toBe(2);

        // Two own-media failures with room for one: the second waits for the next run.
        useUniquePetMedia(true);
        $c = phcBirth(phcPet());
        $d = phcBirth(phcPet());
        acrFail($media->slot($c, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning), 'generation_failed');
        acrFail($media->slot($d, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning), 'generation_failed');
        config(['media.budget.daily_usd' => (float) AiSpendLedger::query()->counted()->sum('cost_usd') + 0.5]);
        $result = app(ActivityClipRetryService::class)->run();
        expect($result['retried'])->toBe(1)->and($result['skipped_budget'])->toBe(1);
    });

    it('command + hourly schedule; the Discord post carries counts only (no pet, family or child)', function () {
        phcFakeFal();
        $pet = phcBirth(phcPet());
        acrFail(app(PetMediaService::class)->slot($pet, PetMedia::KIND_PHASE, PetPhaseClip::BallRunning), 'timed_out');

        $this->artisan('media:retry-activity-clips --dry-run')->assertSuccessful()->expectsOutputToContain('[dry run] Re-queued 0, reported 1');
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression);
        expect($events->first(fn ($e) => str_contains($e, 'media:retry-activity-clips')))->toContain('17 * * * *');

        config(['services.discord.alerts_webhook_url' => 'https://discord.com/api/webhooks/1/secret']);
        Http::fake(['discord.com/api/webhooks/*' => Http::response('', 204)]);
        (new SendActivityMediaAlert(1))->handle(app(DiscordWebhook::class));
        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'discord.com'))->first()[0];
        $body = json_encode($sent->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $child = PetCaretaker::where('pet_id', $pet->id)->first()->user;
        expect($body)->toContain('school / game clip')
            ->and($body)->not->toContain((string) $child->name)
            ->and($body)->not->toContain('"pet_id"')
            ->and($body)->not->toContain('/pets/'.$pet->id);
    });
});

describe('training start over HTTP while the school clips are coming (David 2026-10-28)', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('POST training/start → 422 media_not_ready; the state says training.media_ready false', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = phcBirth(phcPet());
        disableHygieneEvents($pet);
        Carbon\Carbon::setTestNow(CarbonImmutable::parse('today 12:00', $pet->familyTimezone())->utc());
        $caretaker = PetCaretaker::where('pet_id', $pet->id)->first();
        $caretaker->update(['requires_contract' => false]);
        actingAsRole($caretaker->user);

        $response = $this->postJson('/api/child/pet/training/start', ['command' => 'sit']);
        $response->assertStatus(422)->assertJsonPath('reason', 'media_not_ready');

        $state = $this->getJson('/api/child/pet')->assertOk();
        expect($state->json('training.media_ready'))->toBeFalse()
            ->and($state->json('training.can_start'))->toBeFalse()
            ->and($state->json('training.blocked_reason'))->toBe('media_not_ready');
    });
});

describe('QA m6: preparation without the phase clips', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('the preparation (and its 2 h push) ends with the first-minutes set + transitions; the school reports school_pending', function () {
        phcFakeFal();
        Queue::fake([GeneratePetKeyframe::class, SubmitPetStateVideo::class]);
        $pet = phcBirth(phcPet());
        $media = app(PetMediaService::class);
        $prep = app(PetPreparationService::class);

        // Store the image + every expected item; every phase clip is still pending.
        foreach ($prep->expectedKeys($pet->fresh()) as $key) {
            if ($key === PetPreparationService::IMAGE) {
                continue;
            }
            $transition = PetTransition::tryFrom($key);
            $slot = $transition !== null
                ? $media->slot($pet, PetMedia::KIND_TRANSITION, $transition)
                : $media->slot($pet, PetMedia::KIND_VIDEO, PetStateEnum::from($key));
            $slot->update(['status' => 'ready', 'storage_path' => "{$pet->id}/{$key}-g1.mp4"]);
        }

        $summary = $prep->summary($pet->fresh());
        expect(PetMedia::where('pet_id', $pet->id)->where('kind', 'phase')->where('status', 'ready')->count())->toBe(0)
            ->and($summary->ready)->toBeTrue()
            ->and($summary->pending)->toBe([])
            ->and($summary->toArray()['school_pending'])->toBeTrue();

        // A failed school clip is no longer "on its way".
        foreach (app(MediaEntitlementService::class)->phaseClipsFor($pet->fresh()) as $clip) {
            if ($clip->group() === PetPhaseClip::GROUP_SCHOOL) {
                $media->slot($pet, PetMedia::KIND_PHASE, $clip)->update(['status' => 'failed', 'error_reason' => 'generation_failed']);
            }
        }
        expect($prep->summary($pet->fresh())->toArray()['school_pending'])->toBeFalse();
    });
});
