<?php

use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Jobs\CheckPetMediaQuality;
use App\Jobs\SendItemMediaAlert;
use App\Jobs\SendStillQualityAlert;
use App\Models\AiSpendLedger;
use App\Models\AppSettingChange;
use App\Models\ItemMedia;
use App\Models\ItemMediaQualityCheck;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetMedia;
use App\Models\PetMediaQualityCheck;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\ItemMediaService;
use App\Services\Media\MediaProfiles;
use App\Services\Media\MediaQualityService;
use App\Services\Media\MediaVisionCheck;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetMediaService;
use App\Services\Media\StillQualityService;
use App\Services\PairingService;
use App\Services\Results\PetProfileChoice;
use App\Services\Shop\ItemDesigns;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| AI check of still images before a video is made (David 2026-11-04)
|--------------------------------------------------------------------------
| Production: a bought bed's key image (Nano Banana Pro Edit) had a 4th paw under the bed and
| two ghost paws of the original pose on the floor; the clip from it had them too; »Regenerate«
| returned the SAME picture (same seed + prompt + inputs). Now every key / pose / item key image
| and catalogue picture is checked by the vision model before a video is made from it; a fail is
| regenerated with a NEW seed (≤ 2 per chain), then the self-healing fallback; every explicit
| regeneration gets a new seed; item clips get the free technical check. The vision model is
| always Http::fake — never a real fal call.
*/

const SQ_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

const SQ_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00', 'UTC'));
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
        'media.profiles.vision.openrouter_vision_gemini_flash.enabled' => true,
        'media.quality_check.stills_mode' => 'enforce',
        // QA #736 M2: pet / look stills have their own mode (production default shadow) — enforce here.
        'media.quality_check.pet_stills_mode' => 'enforce',
    ]);
    useStateMedia();
    usePhaseClips(false);
    useUniquePetMedia(true);
    seedBreedConfigs();
    seedStageParams();
    $this->sqVerdicts = [];
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * fal fakes. `$pass($row)` decides the vision verdict of the still `$row` (PetMedia | ItemMedia)
 * the request points at (default: pass). Item clips download `$clip()` bytes.
 *
 * @param  callable(PetMedia|ItemMedia): bool|null  $pass
 * @param  callable(): string|null  $clip
 */
function sqFakeFal(?callable $pass = null, ?callable $clip = null): void
{
    fakeFalJwks();
    $n = 0;
    $pass ??= fn () => true;
    Http::fake([
        'fal.run/openrouter/router/vision' => function (Request $r) use ($pass) {
            $url = (string) ($r->data()['image_urls'][0] ?? '');
            $row = sqRowOf($url);
            $ok = $row !== null && $pass($row);
            $rubric = match (true) {
                $row instanceof ItemMedia && $row->isPicture() => MediaVisionCheck::RUBRIC_OBJECT,
                $row instanceof PetMedia && $row->state === 'empty' => MediaVisionCheck::RUBRIC_EMPTY,
                default => MediaVisionCheck::RUBRIC_ANIMAL,
            };
            $answer = array_fill_keys(MediaVisionCheck::STILL_KEYS[$rubric], true);
            if (! $ok) {
                $answer[$rubric === MediaVisionCheck::RUBRIC_ANIMAL ? 'no_ghost_parts' : 'clean'] = false;
            }

            return Http::response(['output' => json_encode($answer + ['verdict' => $ok ? 'pass' : 'fail', 'reason' => $ok ? 'looks fine' : 'two ghost paws on the floor']), 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 60, 'cost' => 0.0004]]);
        },
        // Reference images (text-to-image, sync) and stage edits (sync).
        'fal.run/fal-ai/nano-banana-pro/edit' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/sq/edit-{$n}.jpg"]]]);
        },
        'fal.run/fal-ai/nano-banana-pro' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/sq/ref-{$n}.jpg"]]]);
        },
        'queue.fal.run/fal-ai/nano-banana-pro/edit*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "sq-edit-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/sq-edit-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/sq-edit-{$n}"]);
        },
        'queue.fal.run/fal-ai/nano-banana-pro*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "sq-pic-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/sq-pic-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/sq-pic-{$n}"]);
        },
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "sq-clip-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/kling-video/requests/sq-clip-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/kling-video/requests/sq-clip-{$n}"]);
        },
        'v3.fal.media/files/sq/*.jpg' => fn () => Http::response(SQ_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/sq/*.mp4' => fn () => Http::response($clip !== null ? $clip() : SQ_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** The row a vision request's image URL points at (candidate or stored file of a pet / item row). */
function sqRowOf(string $url): PetMedia|ItemMedia|null
{
    return match (true) {
        preg_match('#/api/media/candidates/(\d+)#', $url, $m) === 1 => PetMedia::find((int) $m[1]),
        preg_match('#/api/media/items/(\d+)#', $url, $m) === 1 => ItemMedia::find((int) $m[1]),
        preg_match('#/api/media/(\d+)#', $url, $m) === 1 => PetMedia::find((int) $m[1]),
        default => null,
    };
}

/** @return Collection<int, array<string, mixed>> request bodies of fal queue submits to the edit model (key / pose / item images). */
function sqEditSubmits(): Collection
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/fal-ai/nano-banana-pro/edit') && ! str_contains($r->url(), '/requests/'))
        ->map(fn (array $pair) => $pair[0]->data())->values();
}

/** @return Collection<int, array<string, mixed>> request bodies of catalogue picture submits (text-to-image, queued). */
function sqPictureSubmits(): Collection
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/fal-ai/nano-banana-pro')
        && ! str_contains($r->url(), '/edit') && ! str_contains($r->url(), '/requests/'))
        ->map(fn (array $pair) => $pair[0]->data())->values();
}

/** @return Collection<int, array<string, mixed>> request bodies of Kling clip submits. */
function sqClipSubmits(): Collection
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/fal-ai/kling') && ! str_contains($r->url(), '/requests/'))
        ->map(fn (array $pair) => $pair[0]->data())->values();
}

/** Vision calls made so far. */
function sqVisionCalls(): int
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'openrouter/router/vision'))->count();
}

/** Deliver every queued key / pose image and clip result (pet slots), several rounds. */
function sqCompletePet(): void
{
    for ($round = 0; $round < 10; $round++) {
        $keys = completeFalKeyImages('https://v3.fal.media/files/sq/');
        $clips = PetMedia::query()->whereIn('kind', ['video', 'transition'])->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();
        foreach ($clips as $row) {
            sendFalWebhook(['request_id' => $row->request_id, 'status' => 'OK', 'payload' => ['video' => ['url' => "https://v3.fal.media/files/sq/{$row->request_id}.mp4"]]])->assertOk();
        }
        if ($keys === 0 && $clips->isEmpty()) {
            break;
        }
    }
}

/** A born, paid Labrador with its OWN media (unique DNA) — key images are its own pet_media slots. */
function sqPet(): Pet
{
    $parent = createParentUser();
    $family = app(FamilyService::class)->ensureFamilyFor($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $type = BreedType::LabradorRetriever;
    $profile = new PetProfileChoice($type, PetOrigin::Bought, LifeStage::Young, [], $type->species(), 'black');
    $plan = $type->isPremium() ? PetPlan::Challenge : PetPlan::Free;
    $pet = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, $plan)['pet'])->fresh();
    $pet->forceFill(['plan' => PetPlan::Challenge, 'challenge_paid_at' => now(), 'challenge_paid_source' => ChallengePaidSource::Purchase])->saveQuietly();
    $pet->giveBirth(now());
    $pet->save();

    return $pet->fresh();
}

function sqKey(Pet $pet, string $state): PetMedia
{
    return PetMedia::where('pet_id', $pet->id)->where('kind', 'keyframe')->where('state', $state)->firstOrFail();
}

/** Kling submits whose start frame is the stored key image of $state (the loop of that state). */
function sqLoopSubmits(Pet $pet, string $state): int
{
    $video = PetMedia::where('pet_id', $pet->id)->where('kind', 'video')->where('state', $state)->first();

    return $video === null ? 0 : AiSpendLedger::query()->where('pet_media_id', $video->id)->where('purpose', AiSpendPurpose::StateVideo->value)->count();
}

/* ─────────────────────────── Pet key / pose images ─────────────────────────── */

describe('pet key images', function () {
    it('checks every key image before its loop is made; a pass stores it (quality passed)', function () {
        sqFakeFal();
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();

        $key = sqKey($pet, 'sleeping');
        $check = PetMediaQualityCheck::where('pet_media_id', $key->id)->sole();
        expect($key->status)->toBe('ready')->and($key->quality)->toBe(PetMedia::QUALITY_PASSED)
            ->and($key->candidate_path)->toBeNull()
            ->and($check->ai_status)->toBe('passed')->and($check->ai_reported_cost_usd)->toBe(0.0004)
            ->and(sqLoopSubmits($pet, 'sleeping'))->toBe(1)
            // Vision calls are production spend (quality_check), linked to the key image.
            ->and(AiSpendLedger::where('pet_media_id', $key->id)->where('purpose', 'quality_check')->count())->toBe(1);
    });

    it('regenerates a failed key image with a NEW seed and makes the loop only from the passed one', function () {
        $fails = ['sleeping' => 1];
        sqFakeFal(function ($row) use (&$fails) {
            if ($row instanceof PetMedia && ($fails[$row->state] ?? 0) > 0) {
                $fails[$row->state]--;

                return false;
            }

            return true;
        });
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();

        $key = sqKey($pet, 'sleeping');
        $dnaSeed = (int) $pet->fresh()->pet_dna['seed'];
        $sleepingSubmits = sqEditSubmits()->filter(fn (array $b) => str_contains((string) $b['prompt'], PetStateEnum::Sleeping->keyPose()))->values();

        expect($key->status)->toBe('ready')->and($key->quality)->toBe(PetMedia::QUALITY_PASSED)
            ->and($key->still_retries)->toBe(1)
            ->and($sleepingSubmits)->toHaveCount(2)
            ->and($sleepingSubmits[0]['seed'])->toBe($dnaSeed)
            ->and($sleepingSubmits[1]['seed'])->not->toBe($dnaSeed)
            ->and(PetMediaQualityCheck::where('pet_media_id', $key->id)->orderBy('generation')->pluck('verdict')->all())->toBe(['fail', 'pass'])
            // ONE loop, made after the passed key image (never from the failed candidate).
            ->and(sqLoopSubmits($pet, 'sleeping'))->toBe(1);
    });

    it('gives up after 2 regenerations: needs review (candidate kept), no loop, ONE Discord alert', function () {
        Queue::fake([SendStillQualityAlert::class]);
        sqFakeFal(fn ($row) => ! ($row instanceof PetMedia && $row->state === 'sleeping'));
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();

        $key = sqKey($pet, 'sleeping');
        expect($key->status)->toBe('failed')->and($key->error_reason)->toBe('quality_failed')
            ->and($key->needsReview())->toBeTrue()
            ->and($key->still_retries)->toBe(2)
            ->and(PetMediaQualityCheck::where('pet_media_id', $key->id)->count())->toBe(3)
            ->and(sqLoopSubmits($pet, 'sleeping'))->toBe(0)
            // Other states are not held up.
            ->and(sqLoopSubmits($pet, 'hungry'))->toBe(1);
        Queue::assertPushed(SendStillQualityAlert::class, 1);
        expect(Cache::get(StillQualityService::ALERT_COUNT_KEY))->toBe(1);

        // »Approve anyway« uses it (audited) — its loop follows.
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        expect(app(MediaQualityService::class)->approve($key, $admin))->toBeTrue();
        sqCompletePet();
        expect(sqKey($pet, 'sleeping')->quality)->toBe(PetMedia::QUALITY_APPROVED)
            ->and(sqLoopSubmits($pet, 'sleeping'))->toBe(1);
    });

    it('shadow mode records the verdict and uses the still anyway (no regeneration)', function () {
        config(['media.quality_check.stills_mode' => 'shadow', 'media.quality_check.pet_stills_mode' => 'shadow']);
        sqFakeFal(fn ($row) => ! ($row instanceof PetMedia && $row->state === 'sleeping'));
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();

        $key = sqKey($pet, 'sleeping');
        expect($key->status)->toBe('ready')->and($key->quality)->toBe(PetMedia::QUALITY_SHADOW_FAILED)
            ->and($key->quality_reason)->toContain('ghost paws')
            ->and($key->still_retries)->toBe(0)
            ->and(sqLoopSubmits($pet, 'sleeping'))->toBe(1);
    });

    it('the still mode is independent of the clip mode (clip check off, stills enforce)', function () {
        useQualityCheck('off');
        expect(app(AppSettingsService::class)->qualityStillsMode(fresh: true))->toBe('enforce')
            ->and(app(AppSettingsService::class)->qualityCheckMode(fresh: true))->toBe('off');

        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        app(AppSettingsService::class)->updateQualityCheck($admin, 'shadow', 'off');
        expect(app(AppSettingsService::class)->qualityStillsMode(fresh: true))->toBe('off')
            ->and(app(AppSettingsService::class)->qualityCheckMode(fresh: true))->toBe('shadow');
        // Saving only the clip mode keeps the stored still mode.
        app(AppSettingsService::class)->updateQualityCheck($admin, 'enforce');
        expect(app(AppSettingsService::class)->qualityStillsMode(fresh: true))->toBe('off');

        // QA #736 M2 / M3: item stills, pet stills and the clip AI call are separate settings.
        app(AppSettingsService::class)->updateQualityCheck($admin, 'shadow', 'enforce', 'shadow', false);
        expect(app(AppSettingsService::class)->qualityStillsMode(fresh: true))->toBe('enforce')
            ->and(app(AppSettingsService::class)->qualityPetStillsMode(fresh: true))->toBe('shadow')
            ->and(app(AppSettingsService::class)->qualityClipAi(fresh: true))->toBeFalse()
            ->and(app(AppSettingsService::class)->qualityCheckMode(fresh: true))->toBe('shadow');
        app(AppSettingsService::class)->updateQualityCheck($admin, 'enforce');
        expect(app(AppSettingsService::class)->qualityPetStillsMode(fresh: true))->toBe('shadow')
            ->and(app(AppSettingsService::class)->qualityClipAi(fresh: true))->toBeFalse();
    });

    it('pet stills in shadow while item stills enforce: a failed pet key image is used, never regenerated (QA #736 M2)', function () {
        config(['media.quality_check.pet_stills_mode' => 'shadow']);
        sqFakeFal(fn ($row) => ! ($row instanceof PetMedia && $row->state === 'sleeping'));
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();

        expect(sqKey($pet, 'sleeping')->quality)->toBe(PetMedia::QUALITY_SHADOW_FAILED)
            ->and(sqKey($pet, 'sleeping')->still_retries)->toBe(0)
            ->and(app(StillQualityService::class)->stillsMode())->toBe('enforce');
    });

    it('aggregates the key image alert: two given up in one window → ONE post with the count (QA #736 m1)', function () {
        Queue::fake([SendStillQualityAlert::class]);
        sqFakeFal(fn ($row) => ! ($row instanceof PetMedia && in_array($row->state, ['sleeping', 'hungry'], true)));
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();

        Queue::assertPushed(SendStillQualityAlert::class, 1);
        expect(StillQualityService::takeAlertCount())->toBe(2)
            ->and(StillQualityService::takeAlertCount())->toBe(0);
    });

    it('a »Needs review« look key image is never claimed again: a second pet of the look → 0 new edits, 0 new checks, 0 new alerts (QA #736 B1)', function () {
        useUniquePetMedia(false);
        Queue::fake([SendStillQualityAlert::class]);
        sqFakeFal(fn ($row) => ! ($row instanceof PetMedia && $row->state === 'sleeping'));
        $first = sqPet();
        app(PetMediaService::class)->queueStateVideos($first);
        sqCompletePet();

        $row = PetMedia::query()->where('pet_look_id', $first->pet_look_id)->whereNull('pet_id')->where('kind', 'keyframe')->where('state', 'sleeping')->sole();
        expect($row->isQualityRejected())->toBeTrue()->and(Cache::get(StillQualityService::ALERT_COUNT_KEY))->toBe(1);
        $generation = $row->generation;
        $edits = sqEditSubmits()->count();
        $vision = sqVisionCalls();

        $second = sqPet();
        expect($second->pet_look_id)->toBe($first->pet_look_id);
        app(PetMediaService::class)->queueStateVideos($second);
        sqCompletePet();
        // Generate missing / a retry run of the first pet touch it neither.
        app(PetMediaService::class)->queueStateVideos($first->fresh(), includeFailed: true);
        sqCompletePet();

        expect(sqEditSubmits()->count())->toBe($edits)
            ->and(sqVisionCalls())->toBe($vision)
            ->and($row->fresh()->isQualityRejected())->toBeTrue()
            ->and($row->fresh()->generation)->toBe($generation)
            ->and($row->fresh()->candidate_path)->not->toBeNull()
            // The new pet's slot shares the verdict (its fallback), never a stuck »waiting«.
            ->and(PetMedia::where('pet_id', $second->id)->where('kind', 'keyframe')->where('state', 'sleeping')->value('error_reason'))->toBe('quality_failed')
            ->and(Cache::get(StillQualityService::ALERT_COUNT_KEY))->toBe(1);
        Queue::assertPushed(SendStillQualityAlert::class, 1);
    });

    it('Generate missing never remakes a »Needs review« still; a reset of a checked still is always a new generation (QA #736 M1)', function () {
        sqFakeFal(fn ($row) => ! ($row instanceof PetMedia && $row->state === 'sleeping'));
        $pet = sqPet();
        $media = app(PetMediaService::class);
        $media->queueStateVideos($pet);
        sqCompletePet();

        $key = sqKey($pet, 'sleeping');
        expect($key->isQualityRejected())->toBeTrue();
        $edits = sqEditSubmits()->count();
        $media->queueStateVideos($pet->fresh(), includeFailed: true);
        sqCompletePet();
        expect(sqEditSubmits()->count())->toBe($edits)->and(sqKey($pet, 'sleeping')->generation)->toBe($key->generation);

        // A reset without a bump of a still whose generation has a verdict → a new generation (own check).
        $hungry = sqKey($pet, 'hungry');
        $before = $hungry->generation;
        expect(PetMediaQualityCheck::where('pet_media_id', $hungry->id)->where('generation', $before)->exists())->toBeTrue()
            ->and($media->resetForNewGeneration($hungry, bump: false))->toBeTrue()
            ->and($hungry->fresh()->generation)->toBe($before + 1);

        // An explicit Regenerate still works on the rejected one (new generation, new seed).
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        expect($media->resetForNewGeneration($key->fresh(), bump: true))->toBeTrue()
            ->and(sqKey($pet, 'sleeping')->status)->toBe('pending');
    });

    it('waits without a claim or a charge while the AI budget is out, then checks once it allows', function () {
        sqFakeFal();
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        // Budget used up after the key images were submitted (before their results arrive).
        $spent = (float) AiSpendLedger::query()->counted()->sum('cost_usd');
        config(['media.budget.daily_usd' => $spent]);
        completeFalKeyImages('https://v3.fal.media/files/sq/');

        $key = sqKey($pet, 'sleeping');
        expect($key->status)->toBe('running')->and($key->candidate_path)->not->toBeNull()
            ->and(PetMediaQualityCheck::where('pet_media_id', $key->id)->count())->toBe(0)
            ->and(sqVisionCalls())->toBe(0)
            ->and(sqLoopSubmits($pet, 'sleeping'))->toBe(0);

        // Money again: the hourly sweep re-runs the check (no new download, no new generation).
        config(['media.budget.daily_usd' => 100.0]);
        Carbon::setTestNow(now()->addMinutes(61));
        $this->artisan('media:sweep')->assertSuccessful();
        sqCompletePet();

        expect(sqKey($pet, 'sleeping')->quality)->toBe(PetMedia::QUALITY_PASSED)
            ->and(sqKey($pet, 'sleeping')->generation)->toBe($key->generation)
            ->and(sqLoopSubmits($pet, 'sleeping'))->toBe(1);
    });

    it('never pays twice: a duplicate check job, a webhook redelivery and a dead worker\'s call', function () {
        sqFakeFal();
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();
        $key = sqKey($pet, 'sleeping');
        $calls = sqVisionCalls();

        CheckPetMediaQuality::dispatchSync($key->id);
        sendFalWebhook(['request_id' => $key->request_id, 'status' => 'OK', 'payload' => ['images' => [['url' => 'https://v3.fal.media/files/sq/again.jpg']]]])->assertOk();
        expect(sqVisionCalls())->toBe($calls)
            ->and(AiSpendLedger::where('pet_media_id', $key->id)->where('purpose', 'quality_check')->count())->toBe(1);

        // A worker died during the paid call (`calling`): the takeover never calls again.
        $slot = sqKey($pet, 'hungry');
        $slot->forceFill(['status' => 'running', 'candidate_path' => $slot->storage_path, 'candidate_bytes' => 10, 'candidate_mime' => 'image/jpeg', 'generation' => $slot->generation + 1])->saveQuietly();
        PetMediaQualityCheck::create(['pet_media_id' => $slot->id, 'generation' => $slot->generation, 'trigger' => 'pipeline', 'status' => 'running', 'ai_status' => 'calling', 'ai_cost_usd' => 0.003]);
        PetMediaQualityCheck::query()->where('pet_media_id', $slot->id)->where('generation', $slot->generation)->update(['updated_at' => now()->subMinutes(11)]);
        CheckPetMediaQuality::dispatchSync($slot->id);
        expect(sqVisionCalls())->toBe($calls)
            ->and(sqKey($pet, 'hungry')->status)->toBe('ready')
            ->and(sqKey($pet, 'hungry')->quality)->toBe(PetMedia::QUALITY_UNCHECKED);
    });

    it('stores a still unchecked when no vision profile is enabled (never blocks media)', function () {
        config(['media.profiles.vision.openrouter_vision_gemini_flash.enabled' => false]);
        sqFakeFal();
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();

        expect(sqKey($pet, 'sleeping')->quality)->toBe(PetMedia::QUALITY_UNCHECKED)
            ->and(sqVisionCalls())->toBe(0)
            ->and(sqLoopSubmits($pet, 'sleeping'))->toBe(1);
    });
});

describe('regenerate = a new seed', function () {
    it('an admin Regenerate of a key image or the reference image never resubmits the same seed', function () {
        sqFakeFal();
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();
        $media = app(PetMediaService::class);
        $before = sqEditSubmits()->count();

        expect($media->regenerate(sqKey($pet, 'sleeping')))->toBeTrue();
        sqCompletePet();
        expect($media->regenerate(sqKey($pet, 'sleeping')))->toBeTrue();
        sqCompletePet();

        $seeds = sqEditSubmits()->slice($before)->filter(fn (array $b) => str_contains((string) $b['prompt'], PetStateEnum::Sleeping->keyPose()))->pluck('seed')->values();
        expect($seeds)->toHaveCount(2)->and($seeds[0])->not->toBe($seeds[1])
            ->and($seeds->all())->not->toContain((int) $pet->fresh()->pet_dna['seed']);

        // The reference image: text-to-image with a fresh seed (never the DNA seed again).
        $image = PetMedia::where('pet_id', $pet->id)->where('kind', 'image')->sole();
        expect($media->regenerate($image))->toBeTrue();
        $refSeeds = Http::recorded(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/nano-banana-pro')->map(fn ($p) => $p[0]->data()['seed'])->values();
        expect($refSeeds)->toHaveCount(2)->and($refSeeds[1])->not->toBe($refSeeds[0])
            ->and($refSeeds[0])->toBe((int) $pet->fresh()->pet_dna['seed']);
    });
});

/* ─────────────────────────── Items ─────────────────────────── */

/** A pet with its own picture owning $design (no purchase flow — the item row is enough). */
function sqItemPet(string $itemKey = 'bed', string $design = 'dog_bed_round_grey'): Pet
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->borderCollie()->create(['user_id' => $child->id, 'born_at' => now()->subDay(), 'last_decay_at' => now(), 'media_status' => 'ready']);
    disableHygieneEvents($pet);
    $path = "{$pet->id}/reference-g1.jpg";
    Storage::disk('pet_media')->put($path, SQ_JPEG.str_repeat("\x00", 64));
    PetMedia::query()->updateOrCreate(['pet_id' => $pet->id, 'kind' => 'image', 'state' => null], ['status' => 'ready', 'storage_path' => $path, 'life_stage' => 'young', 'mime' => 'image/jpeg', 'generation' => 1]);
    PetItem::create(['pet_id' => $pet->id, 'item_key' => $itemKey, 'kind' => 'durable', 'variant' => "dog_{$itemKey}", 'design' => $design, 'quantity_total' => 1, 'quantity_left' => 1, 'acquired_at' => now()]);

    return $pet->fresh();
}

/** Answer every running item request (picture → image → clip), several rounds. */
function sqCompleteItems(): void
{
    for ($round = 0; $round < 10; $round++) {
        $rows = ItemMedia::query()->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            break;
        }
        foreach ($rows as $row) {
            $payload = $row->isStill()
                ? ['images' => [['url' => "https://v3.fal.media/files/sq/{$row->request_id}.jpg"]]]
                : ['video' => ['url' => "https://v3.fal.media/files/sq/{$row->request_id}.mp4"]];
            sendFalWebhook(['request_id' => $row->request_id, 'status' => 'OK', 'payload' => $payload])->assertOk();
        }
    }
}

function sqItemRow(Pet $pet, string $kind): ItemMedia
{
    return ItemMedia::where('pet_id', $pet->id)->where('kind', $kind)->firstOrFail();
}

describe('item media', function () {
    it('checks the catalogue picture (object rubric) and the key image; the clip is made only from a passed key image', function () {
        $fails = ['picture' => 1, 'image' => 1];
        sqFakeFal(function ($row) use (&$fails) {
            if ($row instanceof ItemMedia && ($fails[$row->kind] ?? 0) > 0) {
                $fails[$row->kind]--;

                return false;
            }

            return true;
        });
        $pet = sqItemPet();
        app(ItemMediaService::class)->ensure($pet);
        sqCompleteItems();

        $picture = ItemMedia::where('scope', 'catalogue')->where('design', 'dog_bed_round_grey')->sole();
        $image = sqItemRow($pet, 'image');
        $clip = sqItemRow($pet, 'clip');
        $pictureSubmits = sqPictureSubmits();
        $imageSubmits = sqEditSubmits();

        expect($picture->status)->toBe('ready')->and($picture->quality)->toBe(PetMedia::QUALITY_PASSED)->and($picture->quality_retries)->toBe(1)
            // The retry is a NEW request: fresh seed and the next prompt variant.
            ->and($pictureSubmits)->toHaveCount(2)
            ->and($pictureSubmits[1]['seed'])->not->toBe($pictureSubmits[0]['seed'])
            ->and($pictureSubmits[1]['prompt'])->toBe(app(PetAppearancePrompt::class)->itemPicturePrompt(ItemDesigns::description('dog_bed_round_grey'), 1))
            ->and($image->status)->toBe('ready')->and($image->quality_retries)->toBe(1)->and($image->prompt_variant)->toBe(1)
            ->and($imageSubmits)->toHaveCount(2)->and($imageSubmits[1]['seed'])->not->toBe($imageSubmits[0]['seed'])
            // One clip, from the passed key image.
            ->and(sqClipSubmits())->toHaveCount(1)
            ->and($clip->status)->toBe('ready')->and($clip->source_path)->toBe($image->storage_path)
            ->and(ItemMediaQualityCheck::where('item_media_id', $image->id)->orderBy('generation')->pluck('verdict')->all())->toBe(['fail', 'pass'])
            ->and(AiSpendLedger::where('item_media_id', $image->id)->where('purpose', 'quality_check')->count())->toBe(2);
    });

    it('gives a key image up after 2 regenerations: the item is shown without content, ONE alert, approve anyway works', function () {
        Queue::fake([SendItemMediaAlert::class]);
        sqFakeFal(fn ($row) => ! ($row instanceof ItemMedia && $row->kind === 'image'));
        $pet = sqItemPet();
        app(ItemMediaService::class)->ensure($pet);
        sqCompleteItems();

        $image = sqItemRow($pet, 'image');
        expect($image->status)->toBe('failed')->and($image->error_reason)->toBe('quality_failed')->and($image->needsReview())->toBeTrue()
            ->and($image->quality_retries)->toBe(2)
            ->and(sqClipSubmits())->toHaveCount(0)
            ->and(app(ItemMediaService::class)->statesFor($pet)['bed']['outcome'])->toBe(ItemMediaService::OUTCOME_WAITING);

        // The self-healing gives the chain up (fallback + one alert per run).
        Carbon::setTestNow(now()->addMinutes(31));
        app(ItemMediaService::class)->heal();
        expect(app(ItemMediaService::class)->statesFor($pet->fresh())['bed']['outcome'])->toBe(ItemMediaService::OUTCOME_FALLBACK);
        Queue::assertPushed(SendItemMediaAlert::class, 1);

        // »Approve anyway« (superadmin, audited): the key image is used, its clip follows.
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        expect(app(StillQualityService::class)->approveItem(sqItemRow($pet, 'image'), $admin))->toBeTrue();
        sqCompleteItems();
        expect(sqItemRow($pet, 'image')->quality)->toBe(PetMedia::QUALITY_APPROVED)
            ->and(sqItemRow($pet, 'clip')->status)->toBe('ready')
            ->and(app(ItemMediaService::class)->statesFor($pet->fresh())['bed']['outcome'])->toBe(ItemMediaService::OUTCOME_CONTENT)
            ->and(AppSettingChange::where('key', StillQualityService::AUDIT_APPROVE_ITEM)->count())->toBe(1);
    });

    it('an admin Regenerate of an item row sends a new seed every time', function () {
        sqFakeFal();
        $pet = sqItemPet();
        $items = app(ItemMediaService::class);
        $items->ensure($pet);
        sqCompleteItems();

        expect($items->regenerate(sqItemRow($pet, 'image')))->toBeTrue();
        sqCompleteItems();
        expect($items->regenerate(sqItemRow($pet, 'image')))->toBeTrue();
        sqCompleteItems();

        $seeds = sqEditSubmits()->pluck('seed')->values();
        expect($seeds)->toHaveCount(3)->and($seeds->unique())->toHaveCount(3);

        $picture = ItemMedia::where('scope', 'catalogue')->where('design', 'dog_bed_round_grey')->sole();
        expect($items->regenerate($picture))->toBeTrue();
        sqCompleteItems();
        $pictureSeeds = sqPictureSubmits()->pluck('seed')->values();
        expect($pictureSeeds)->toHaveCount(2)->and($pictureSeeds[1])->not->toBe($pictureSeeds[0]);
    });

    it('waits for budget before the check of an item still (nothing claimed or paid)', function () {
        sqFakeFal();
        $pet = sqItemPet();
        app(ItemMediaService::class)->ensure($pet);
        $spent = (float) AiSpendLedger::query()->counted()->sum('cost_usd');
        config(['media.budget.daily_usd' => $spent]);
        sqCompleteItems();

        $picture = ItemMedia::where('scope', 'catalogue')->where('design', 'dog_bed_round_grey')->sole();
        expect($picture->status)->toBe('running')->and($picture->candidate_path)->not->toBeNull()
            ->and(ItemMediaQualityCheck::count())->toBe(0)->and(sqVisionCalls())->toBe(0);

        config(['media.budget.daily_usd' => 100.0]);
        Carbon::setTestNow(now()->addMinutes(61));
        app(ItemMediaService::class)->sweep();
        sqCompleteItems();
        expect($picture->fresh()->quality)->toBe(PetMedia::QUALITY_PASSED);
    });
});

/** A tiny real MP4 (ffmpeg): moving (test pattern) or frozen (one grey picture). */
function sqClip(string $kind): string
{
    static $cache = [];
    if (isset($cache[$kind])) {
        return $cache[$kind];
    }
    $out = tempnam(sys_get_temp_dir(), 'sqclip-').'.mp4';
    $src = $kind === 'moving' ? 'testsrc2=size=144x256:rate=12:duration=3' : 'color=c=gray:size=144x256:rate=12:duration=3';
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', $src, '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $cache[$kind] = $bytes;
}

describe('item clips (free technical check)', function () {
    it('a frozen item clip fails the technical check in enforce mode and is regenerated; a moving one is stored', function () {
        if (! (Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful())) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed');
        }
        useQualityCheck('enforce');
        config(['media.quality_check.stills_mode' => 'off', 'media.quality_check.pet_stills_mode' => 'off']);
        $served = 0;
        sqFakeFal(null, function () use (&$served) {
            $served++;

            return sqClip($served === 1 ? 'frozen' : 'moving');
        });
        // A toy (not the low-motion bed): the frozen clip is below the normal motion threshold.
        $pet = sqItemPet('toy_1', 'dog_rope_red_white');
        app(ItemMediaService::class)->ensure($pet);
        sqCompleteItems();

        $clip = sqItemRow($pet, 'clip');
        $checks = ItemMediaQualityCheck::where('item_media_id', $clip->id)->orderBy('generation')->get();
        expect($checks->pluck('verdict')->all())->toBe(['fail', 'pass'])
            ->and($checks[0]->reason)->toContain('Technical check')
            ->and($checks[0]->ai_status)->toBe('not_run')
            ->and($clip->status)->toBe('ready')->and($clip->quality)->toBe(PetMedia::QUALITY_PASSED_TECHNICAL)
            ->and($clip->quality_retries)->toBe(1)
            ->and(sqClipSubmits())->toHaveCount(2)
            // Free: no vision call for clips.
            ->and(sqVisionCalls())->toBe(0);
    });

    it('shadow (the clip default) records a frozen item clip and uses it', function () {
        if (! (Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful())) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed');
        }
        useQualityCheck('shadow');
        config(['media.quality_check.stills_mode' => 'off', 'media.quality_check.pet_stills_mode' => 'off']);
        sqFakeFal(null, fn () => sqClip('frozen'));
        $pet = sqItemPet('toy_1', 'dog_rope_red_white');
        app(ItemMediaService::class)->ensure($pet);
        sqCompleteItems();

        $clip = sqItemRow($pet, 'clip');
        expect($clip->status)->toBe('ready')->and($clip->quality)->toBe(PetMedia::QUALITY_SHADOW_FAILED)
            ->and(sqClipSubmits())->toHaveCount(1);
    });
});

/* ─────────────────────────── Stored stills: media:still-check ─────────────────────────── */

describe('media:still-check', function () {
    it('re-checks stored item key images once: dry run shows count + cost, a fail regenerates key image + clip (David\'s bed)', function () {
        // The bed was made before the check existed (stills off), then the check is on.
        config(['media.quality_check.stills_mode' => 'off', 'media.quality_check.pet_stills_mode' => 'off']);
        sqFakeFal(fn ($row) => ! ($row instanceof ItemMedia && $row->kind === 'image' && $row->generation === 1));
        $pet = sqItemPet();
        app(ItemMediaService::class)->ensure($pet);
        sqCompleteItems();
        $old = sqItemRow($pet, 'image');
        $oldClip = sqItemRow($pet, 'clip');
        expect($old->status)->toBe('ready')->and($old->quality)->toBeNull()->and(sqVisionCalls())->toBe(0);

        config(['media.quality_check.stills_mode' => 'enforce', 'media.quality_check.pet_stills_mode' => 'enforce']);
        $this->artisan('media:still-check --dry-run')->assertSuccessful()
            // One output line = one expectation (each expectation consumes its own write).
            ->expectsOutputToContain('Unchecked stored stills: 1 item key image(s), 0 pet key / pose image(s) (pets not selected — --pets). To check now: 1 × ≈ $0.0030 = ≈ $0.0030')
            // QA #736 M2: the regeneration spend is in the estimate too.
            ->expectsOutputToContain('Regenerations if they fail (enforce, at most 10 per run): up to 1 ×')
            ->expectsOutputToContain('Dry run');
        expect(sqVisionCalls())->toBe(0);

        $this->artisan('media:still-check')->assertSuccessful()->expectsOutputToContain('regenerating 1');
        sqCompleteItems();

        $image = sqItemRow($pet, 'image');
        $clip = sqItemRow($pet, 'clip');
        $seeds = sqEditSubmits()->pluck('seed')->values();
        expect($image->status)->toBe('ready')->and($image->quality)->toBe(PetMedia::QUALITY_PASSED)
            ->and($image->storage_path)->not->toBe($old->storage_path)
            ->and($seeds)->toHaveCount(2)->and($seeds[1])->not->toBe($seeds[0])
            // The clip follows the new key image.
            ->and($clip->source_path)->toBe($image->storage_path)->and($clip->storage_path)->not->toBe($oldClip->storage_path)
            ->and(AppSettingChange::where('key', StillQualityService::AUDIT_COMMAND)->count())->toBe(1);

        // Self-limiting: nothing left to check (0 $).
        $calls = sqVisionCalls();
        $this->artisan('media:still-check')->assertSuccessful()->expectsOutputToContain('Unchecked stored stills: 0 item key image(s)');
        expect(sqVisionCalls())->toBe($calls);
    });

    it('stops regenerating at the per-run cap: the rest stays unchecked for the next run (QA #736 M2)', function () {
        config(['media.quality_check.stills_mode' => 'off', 'media.quality_check.pet_stills_mode' => 'off']);
        sqFakeFal(fn ($row) => ! ($row instanceof ItemMedia && $row->kind === 'image'));
        $pet = sqItemPet();
        app(ItemMediaService::class)->ensure($pet);
        sqCompleteItems();

        config(['media.quality_check.stills_mode' => 'enforce', 'media.quality_check.max_recheck_regenerations' => 0]);
        $this->artisan('media:still-check')->assertSuccessful()->expectsOutputToContain('Regeneration cap of this run reached (0)');

        expect(sqVisionCalls())->toBe(0)
            ->and(app(StillQualityService::class)->uncheckedItemImages()->count())->toBe(1)
            ->and(sqItemRow($pet, 'image')->status)->toBe('ready');
    });

    it('--pets is manual and only records verdicts (shown, never regenerated); the schedule runs items only', function () {
        config(['media.quality_check.stills_mode' => 'off', 'media.quality_check.pet_stills_mode' => 'off']);
        sqFakeFal(fn ($row) => ! ($row instanceof PetMedia && $row->state === 'sleeping'));
        $pet = sqPet();
        app(PetMediaService::class)->queueStateVideos($pet);
        sqCompletePet();
        config(['media.quality_check.stills_mode' => 'enforce', 'media.quality_check.pet_stills_mode' => 'enforce']);
        $count = app(StillQualityService::class)->uncheckedPetStills()->count();

        $this->artisan('media:still-check --pets --dry-run')->assertSuccessful()->expectsOutputToContain("{$count} pet key / pose image(s)");
        $this->artisan('media:still-check --pets')->assertSuccessful();

        $key = sqKey($pet, 'sleeping');
        expect($key->status)->toBe('ready')->and($key->quality)->toBe(PetMedia::QUALITY_SHADOW_FAILED)->and($key->isShown())->toBeTrue()
            ->and(sqKey($pet, 'hungry')->quality)->toBe(PetMedia::QUALITY_PASSED)
            ->and(sqVisionCalls())->toBe($count);

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression);
        expect($events->first(fn ($e) => str_contains($e, 'media:still-check')))->toContain('--items')->not->toContain('--pets');
    });
});

/* ─────────────────────────── Prompts + profile ─────────────────────────── */

describe('prompts and profile', function () {
    it('the item key image prompt puts the whole animal on the bed with four legs and no remnants of the old pose', function () {
        $prompts = app(PetAppearancePrompt::class);
        $bed = $prompts->itemImagePrompt('labrador_retriever', 'bed', ItemDesigns::description('dog_bed_round_grey'), ['coat_color' => 'black'], LifeStage::Young, true);
        $bowls = $prompts->itemImagePrompt('labrador_retriever', 'bowls', ItemDesigns::description('dog_bowls_steel_stand'), ['coat_color' => 'black']);
        $key = $prompts->keyframePrompt('labrador_retriever', PetStateEnum::Sleeping, ['coat_color' => 'black']);

        expect($bed)->toContain('exactly four legs and four paws')
            ->toContain('The whole dog lies on the bed')->toContain('no paw or leg hangs out under or beside the bed')
            ->toContain('no extra, ghost-like, faded or semi-transparent paws')
            ->and($bowls)->toContain('nothing else is on the floor')->not->toContain('lies on the bed')
            ->and($key)->toContain('Nothing of the dog\'s pose in the reference image is left behind')
            ->and(strtolower($prompts->itemImagePrompt('domestic_cat', 'bed', ItemDesigns::description('cat_bed_grey_cave'), ['coat_color' => 'grey tabby'])))->not->toContain('dog');
    });

    it('the vision profile is enabled with a verified per-request price; the still rubric asks the strict questions', function () {
        config(['media.profiles.vision.openrouter_vision_gemini_flash.enabled' => true]);
        $profile = app(MediaProfiles::class)->vision();
        $prompt = app(MediaVisionCheck::class)->stillPrompt(MediaVisionCheck::RUBRIC_ANIMAL, Species::Dog, 'lying curled up asleep on a grey round bed');

        expect($profile)->not->toBeNull()->and($profile->estimatedCostUsd())->toBe(0.003)
            ->and(config('media.profiles.vision.openrouter_vision_gemini_flash.verified'))->toBeTrue()
            ->and($prompt)->toContain('exactly one dog')->toContain('exactly four legs and four paws')
            ->toContain('semi-transparent, ghost-like')->toContain('no people, children or hands')
            ->and(MediaVisionCheck::parseStill('animal', '```json {"one_animal":true,"legs_ok":false,"no_ghost_parts":true,"scene_ok":true,"clean":true,"verdict":"pass","reason":"x"}```'))
            ->toMatchArray(['legs_ok' => false])
            ->and(MediaVisionCheck::stillFails('animal', ['one_animal' => true, 'legs_ok' => false, 'no_ghost_parts' => true, 'scene_ok' => true, 'clean' => true, 'verdict' => 'pass']))->toBeTrue()
            ->and(MediaVisionCheck::parseStill('animal', 'not json'))->toBeNull();
    });
});
