<?php

use App\Enums\AiSpendPurpose;
use App\Enums\PetPhaseClip;
use App\Enums\PetStateEnum;
use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Filament\Resources\PetResource\RelationManagers\MediaRelationManager;
use App\Jobs\CheckPetMediaQuality;
use App\Models\AiSpendLedger;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaQualityCheck;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\FalWebhookVerifier;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaClipAnalyzer;
use App\Services\Media\MediaProfiles;
use App\Services\Media\MediaQualityService;
use App\Services\Media\MediaVisionCheck;
use App\Services\Media\PetMediaService;
use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Automatic clip quality check (David 2026-10-23)
|--------------------------------------------------------------------------
|
| Production media 123 (pet 8, Dobermann puppy, school_sit_performing): the dog stood,
| then its legs »melted« into the sitting end frame. Every new clip is now a candidate
| until a technical (ffmpeg) + AI vision check passes; a failure is regenerated ONCE,
| a second failure is »Needs review« (not shown). Real ffmpeg on tiny generated MP4s
| (skipped with a reason without ffmpeg); the vision model is always Http::fake — never
| a real fal call.
*/

const MQ_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

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
        'media.budget.daily_usd' => 50.0,
        'media.budget.monthly_usd' => 500.0,
        'media.budget.timezone' => 'UTC',
        'media.profiles.vision.openrouter_vision_gemini_flash.enabled' => true,
    ]);
    useQualityCheck();
    seedBreedConfigs();
});

function mqHasFfmpeg(): bool
{
    static $has = null;

    return $has ??= Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful();
}

/** A tiny MP4 made with ffmpeg: moving (test pattern), frozen (one grey picture) or spike (grey → white cut). */
function mqClip(string $kind, int $seconds = 5): string
{
    static $cache = [];
    $key = "{$kind}-{$seconds}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $out = tempnam(sys_get_temp_dir(), 'mqclip-').'.mp4';
    $half = $seconds / 2;
    $input = match ($kind) {
        'moving' => ['-f', 'lavfi', '-i', "testsrc2=size=144x256:rate=12:duration={$seconds}"],
        'frozen' => ['-f', 'lavfi', '-i', "color=c=gray:size=144x256:rate=12:duration={$seconds}"],
        'spike' => ['-f', 'lavfi', '-i', "color=c=gray:size=144x256:rate=12:duration={$half}", '-f', 'lavfi', '-i', "color=c=white:size=144x256:rate=12:duration={$half}", '-filter_complex', '[0][1]concat=n=2:v=1'],
    };
    Process::run(['ffmpeg', '-v', 'error', '-y', ...$input, '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $cache[$key] = $bytes;
}

function mqVisionAnswer(bool $pass, string $reason = 'ok'): array
{
    return ['output' => json_encode([
        'anatomy_ok' => $pass, 'same_animal' => true, 'action_done' => true, 'artifacts' => false,
        'verdict' => $pass ? 'pass' : 'fail', 'reason' => $reason,
    ]), 'usage' => ['prompt_tokens' => 2400, 'completion_tokens' => 40, 'cost' => 0.0008]];
}

/**
 * fal fakes: queue submits get mq-req-N, each webhook result URL returns the next clip of
 * $clips, the vision call answers the next of $answers.
 *
 * @param  list<string>  $clips  mqClip kinds
 * @param  list<array<string, mixed>>  $answers
 */
function mqFakeFal(array $clips, array $answers = []): void
{
    fakeFalJwks();
    $req = 0;
    $clip = 0;
    $answer = 0;
    Http::fake([
        'queue.fal.run/*' => function () use (&$req) {
            $req++;

            return Http::response(['request_id' => "mq-req-{$req}"]);
        },
        'v3.fal.media/files/mq/*' => function () use (&$clip, $clips) {
            $kind = $clips[min($clip, count($clips) - 1)];
            $clip++;

            return Http::response(mqClip($kind), 200, ['Content-Type' => 'video/mp4']);
        },
        'fal.run/openrouter/router/vision' => function () use (&$answer, $answers) {
            $body = $answers[min($answer, max(0, count($answers) - 1))] ?? mqVisionAnswer(true);
            $answer++;

            return Http::response($body);
        },
    ]);
}

/** A free mutt with a stored reference image (its idle video is a plain 5 s state video). */
function mqPet(): Pet
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);
    $pet = Pet::factory()->freePlan()->create(['user_id' => $child->id, 'breed_type' => 'mutt', 'media_status' => 'ready']);
    $path = "{$pet->id}/reference-g1.jpg";
    Storage::disk('pet_media')->put($path, MQ_JPEG.str_repeat("\x00", 256));
    PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'ready', 'generation' => 1, 'storage_path' => $path, 'mime' => 'image/jpeg', 'bytes' => 274, 'profile' => 'nano_banana_pro']);

    return $pet;
}

/** A stored (ready) idle clip of the pet, made from ffmpeg bytes. */
function mqStoredClip(Pet $pet, string $kind = 'moving', ?string $quality = null): PetMedia
{
    $path = "{$pet->id}/idle-g1.mp4";
    Storage::disk('pet_media')->put($path, mqClip($kind));

    return PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'ready', 'generation' => 1,
        'source_generation' => 1, 'storage_path' => $path, 'mime' => 'video/mp4', 'bytes' => 1000, 'profile' => 'kling_v3_pro',
        'duration_seconds' => 5, 'stored_duration_seconds' => 5, 'quality' => $quality]);
}

/** Submit the slot (sync queue) and deliver every pending fal webhook. */
function mqRun(PetMedia $slot): void
{
    for ($i = 0; $i < 6; $i++) {
        $slot->refresh();
        if ($slot->status !== 'running' || $slot->request_id === null || $slot->source_url !== null) {
            return;
        }
        sendFalWebhook(['request_id' => $slot->request_id, 'status' => 'OK', 'payload' => ['video' => ['url' => "https://v3.fal.media/files/mq/{$slot->request_id}.mp4"]]])->assertOk();
    }
}

function mqVisionCalls(): int
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'fal.run/openrouter/router/vision'))->count();
}

function mqQueueCalls(): int
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/'))->count();
}

/* ─────────────────────────── Pure metrics + rubric ─────────────────────────── */

describe('technical metrics (pure)', function () {
    $m = fn (array $diffs, ?float $ssim = null, float $duration = 5.0) => ['duration' => $duration, 'frames' => count($diffs) + 1, 'diffs' => $diffs, 'end_ssim' => $ssim];

    it('passes a clip with steady motion, fails a frozen one (production media 126: ~0.08 / 255)', function () use ($m) {
        expect(MediaClipAnalyzer::evaluate($m(array_fill(0, 59, 2.5)), 5.0, false)['ok'])->toBeTrue();

        $frozen = MediaClipAnalyzer::evaluate($m(array_fill(0, 59, 0.08)), 5.0, false);
        expect($frozen['ok'])->toBeFalse()->and($frozen['failures'][0])->toContain('frozen')
            ->and($frozen['metrics']['mean_diff'])->toBe(0.08);
    });

    it('fails a sudden jump (one frame difference far above the median)', function () use ($m) {
        $diffs = array_fill(0, 59, 1.0);
        $diffs[30] = 40.0;
        expect(MediaClipAnalyzer::evaluate($m($diffs), 5.0, false)['failures'])->toContain('sudden jump: one frame difference 40.0 vs median 1.00 (cut / morph)');
    });

    it('media 123: standing still, then motion bunched in the last quarter → fail for a clip WITH an end frame only', function () use ($m) {
        $diffs = [...array_fill(0, 44, 0.2), ...array_fill(0, 15, 6.0)];
        $withEnd = MediaClipAnalyzer::evaluate($m($diffs), 5.0, true);
        expect($withEnd['ok'])->toBeFalse()->and(implode(' ', $withEnd['failures']))->toContain('motion bunched at the end')
            ->and($withEnd['metrics']['tail_motion_share'])->toBeGreaterThan(0.7);
        // A start-only clip has no end pose to morph into.
        expect(implode(' ', MediaClipAnalyzer::evaluate($m($diffs), 5.0, false)['failures']))->not->toContain('bunched');
    });

    it('fails a last frame unlike the end image, a wrong length and an unreadable file', function () use ($m) {
        expect(MediaClipAnalyzer::evaluate($m(array_fill(0, 59, 2.0), 0.2), 5.0, true)['failures'])->toContain('last frame does not match the end image (SSIM 0.20)')
            ->and(MediaClipAnalyzer::evaluate($m(array_fill(0, 59, 2.0), 0.2), 5.0, false)['ok'])->toBeTrue()
            ->and(MediaClipAnalyzer::evaluate($m(array_fill(0, 30, 2.0), null, 2.5), 5.0, false)['failures'])->toContain('duration 2.50 s instead of 5 s')
            ->and(MediaClipAnalyzer::evaluate(['duration' => null, 'frames' => 0, 'diffs' => [], 'end_ssim' => null], 5.0, false)['ok'])->toBeFalse();
    });

    it('reads the rubric JSON (also in a ``` fence) and fails on any bad field', function () {
        $ok = '{"anatomy_ok": true, "same_animal": true, "action_done": true, "artifacts": false, "verdict": "pass", "reason": "fine"}';
        expect(MediaVisionCheck::parse("```json\n{$ok}\n```"))->toMatchArray(['verdict' => 'pass'])
            ->and(MediaVisionCheck::fails(MediaVisionCheck::parse($ok)))->toBeFalse()
            ->and(MediaVisionCheck::fails(MediaVisionCheck::parse(str_replace('"anatomy_ok": true', '"anatomy_ok": false', $ok))))->toBeTrue()
            ->and(MediaVisionCheck::fails(MediaVisionCheck::parse(str_replace('"artifacts": false', '"artifacts": true', $ok))))->toBeTrue()
            ->and(MediaVisionCheck::parse('the dog looks fine'))->toBeNull()
            ->and(MediaVisionCheck::parse('{"verdict": "maybe"}'))->toBeNull();
    });
});

describe('technical metrics on real ffmpeg clips', function () {
    beforeEach(function () {
        if (! mqHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed (CI installs it; production image has it).');
        }
    });

    it('moving passes; frozen and a hard cut fail', function () {
        $analyzer = app(MediaClipAnalyzer::class);
        $measure = function (string $kind) use ($analyzer) {
            $file = tempnam(sys_get_temp_dir(), 'mq-');
            file_put_contents($file, mqClip($kind));
            $result = MediaClipAnalyzer::evaluate($analyzer->measure($file), 5.0, false);
            @unlink($file);

            return $result;
        };

        $moving = $measure('moving');
        expect($moving['ok'])->toBeTrue()->and($moving['metrics']['duration'])->toEqualWithDelta(5.0, 0.2);
        expect($measure('frozen')['failures'][0])->toContain('frozen');
        expect(implode(' ', $measure('spike')['failures']))->toContain('sudden jump');
    });
});

/* ─────────────────────────── Pipeline ─────────────────────────── */

describe('pipeline: candidate → check → servable / one retry / needs review', function () {
    beforeEach(function () {
        if (! mqHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed (CI installs it; production image has it).');
        }
    });

    it('a good clip passes both checks and becomes servable (one vision call, quality_check ledger row)', function () {
        mqFakeFal(['moving'], [mqVisionAnswer(true)]);
        $pet = mqPet();
        $slot = app(PetMediaService::class)->slot($pet, 'video', PetStateEnum::Idle);
        app(PetMediaService::class)->submitVideo($slot->id);
        mqRun($slot);

        $slot->refresh();
        expect($slot)->status->toBe('ready')->quality->toBe('passed')->candidate_path->toBeNull()
            ->storage_path->toBe("{$pet->id}/idle-g1.mp4")->stored_duration_seconds->toBe(5.0)
            ->and(mqVisionCalls())->toBe(1)
            ->and((array) app(PetMediaService::class)->payloadFor($pet->fresh())['videos'])->toHaveKey('idle');

        $check = PetMediaQualityCheck::sole();
        expect($check)->verdict->toBe('pass')->ai_status->toBe('passed')->technical_ok->toBeTrue()->ai_reported_cost_usd->toBe(0.0008)
            ->sheet_path->toBeNull(); // a pass frees the contact sheet
        $ledger = AiSpendLedger::where('purpose', AiSpendPurpose::QualityCheck->value)->sole();
        expect($ledger)->status->toBe('committed')->unit->toBe('request')->pet_media_id->toBe($slot->id);

        // The vision request: rubric + contact sheet + reference image, no child data.
        $vision = Http::recorded(fn (Request $r) => str_contains($r->url(), 'openrouter/router/vision'))->first()[0];
        expect($vision['model'])->toBe('google/gemini-2.5-flash')
            ->and($vision['image_urls'])->toHaveCount(2)
            ->and($vision['image_urls'][0])->toContain('/api/media/quality-sheets/')
            ->and((string) $vision['prompt'])->toContain('anatomy_ok')->toContain('same dog');
    });

    it('frozen clip: no vision call, ONE automatic regeneration; a frozen retry → needs review, not shown, no loop', function () {
        mqFakeFal(['frozen', 'frozen', 'moving'], [mqVisionAnswer(true)]);
        $pet = mqPet();
        $service = app(PetMediaService::class);
        $slot = $service->slot($pet, 'video', PetStateEnum::Idle);
        $service->submitVideo($slot->id);
        mqRun($slot);

        $slot->refresh();
        expect($slot)->status->toBe('failed')->error_reason->toBe('quality_failed')->generation->toBe(2)
            ->quality_retry_generation->toBe(2)->candidate_path->toBe("{$pet->id}/idle-g2.mp4")->storage_path->toBeNull()
            ->and($slot->needsReview())->toBeTrue()
            ->and((string) $slot->error)->toContain('frozen')
            ->and(mqQueueCalls())->toBe(2)   // the first submit + ONE automatic retry
            ->and(mqVisionCalls())->toBe(0); // the technical check failed first — nothing paid
        expect(PetMediaQualityCheck::where('pet_media_id', $slot->id)->pluck('verdict', 'generation')->all())->toBe([1 => 'fail', 2 => 'fail'])
            ->and(Storage::disk('pet_media')->exists("{$pet->id}/idle-g1.mp4"))->toBeFalse(); // the first candidate is gone

        // Not shown: the app gets no idle clip (its fallback, like any failed slot).
        expect((array) $service->payloadFor($pet->fresh())['videos'])->not->toHaveKey('idle');

        // No loop: the pipeline, the nightly retry and the sweep leave it alone.
        // (queueStateVideos also queues the free pet's missing `sleeping` video — another slot.)
        $service->queueStateVideos($pet->fresh());
        $this->artisan('media:retry')->assertSuccessful();
        $service->sweepStale();
        expect($slot->fresh())->generation->toBe(2)->request_id->toBe('mq-req-2')
            ->and($slot->fresh()->needsReview())->toBeTrue()
            ->and(AiSpendLedger::where('pet_media_id', $slot->id)->where('purpose', 'state_video')->count())->toBe(2);

        // Counted on System health / the dashboard.
        expect(app(SystemHealthService::class)->needsReviewMediaCount())->toBe(1);
    });

    it('a regenerate keeps the old file visible while the candidate is checked; a failed candidate never replaces it', function () {
        mqFakeFal(['spike', 'spike']);
        $pet = mqPet();
        $old = mqStoredClip($pet, 'moving', 'passed');
        $oldPath = $old->storage_path;

        app(PetMediaService::class)->regenerate($old);
        mqRun($old);

        $old->refresh();
        expect($old)->status->toBe('failed')->error_reason->toBe('quality_failed')->storage_path->toBe($oldPath)->quality->toBe('passed')
            ->and(Storage::disk('pet_media')->exists($oldPath))->toBeTrue()
            // The old (good) file is still what the app shows.
            ->and((array) app(PetMediaService::class)->payloadFor($pet->fresh())['videos'])->toHaveKey('idle');
    });

    it('the vision model fails it → one retry; the retry passes → served', function () {
        mqFakeFal(['moving', 'moving'], [mqVisionAnswer(false, 'the back legs melt into the floor'), mqVisionAnswer(true)]);
        $pet = mqPet();
        $slot = app(PetMediaService::class)->slot($pet, 'video', PetStateEnum::Idle);
        app(PetMediaService::class)->submitVideo($slot->id);
        mqRun($slot);

        expect($slot->fresh())->status->toBe('ready')->quality->toBe('passed')->generation->toBe(2)
            ->and(mqVisionCalls())->toBe(2)->and(mqQueueCalls())->toBe(2);
        expect(PetMediaQualityCheck::where('generation', 1)->sole())->ai_status->toBe('failed')->reason->toContain('back legs melt')
            ->sheet_path->not->toBeNull(); // kept for the review of a failed clip
    });

    it('budget out for the vision call → the technical result decides, served as »not AI-checked«', function () {
        // The 5 s video (0.56 $) fits, the 0.01 $ vision reservation does not.
        config(['media.budget.daily_usd' => 0.565]);
        mqFakeFal(['moving']);
        $pet = mqPet();
        $slot = app(PetMediaService::class)->slot($pet, 'video', PetStateEnum::Idle);
        app(PetMediaService::class)->submitVideo($slot->id);
        mqRun($slot);

        expect($slot->fresh())->status->toBe('ready')->quality->toBe('passed_technical')
            ->and(mqVisionCalls())->toBe(0)
            ->and(PetMediaQualityCheck::sole())->ai_status->toBe('skipped_budget')->reason->toContain('Not AI-checked');
    });

    it('vision profile disabled (price not verified) → technical only, no fal call for it', function () {
        config(['media.profiles.vision.openrouter_vision_gemini_flash.enabled' => false]);
        mqFakeFal(['moving']);
        $pet = mqPet();
        $slot = app(PetMediaService::class)->slot($pet, 'video', PetStateEnum::Idle);
        app(PetMediaService::class)->submitVideo($slot->id);
        mqRun($slot);

        expect($slot->fresh()->quality)->toBe('passed_technical')->and(mqVisionCalls())->toBe(0)
            ->and(PetMediaQualityCheck::sole()->ai_status)->toBe('disabled');
    });

    it('a duplicate check job never measures or pays twice (the check row is the claim)', function () {
        mqFakeFal(['moving'], [mqVisionAnswer(true)]);
        $pet = mqPet();
        $slot = app(PetMediaService::class)->slot($pet, 'video', PetStateEnum::Idle);
        app(PetMediaService::class)->submitVideo($slot->id);
        mqRun($slot);
        (new CheckPetMediaQuality($slot->id))->handle(app(MediaQualityService::class));

        expect(PetMediaQualityCheck::count())->toBe(1)->and(mqVisionCalls())->toBe(1);
    });

    it('»Approve anyway« (superadmin, audited) serves the twice-failed candidate', function () {
        mqFakeFal(['frozen', 'frozen']);
        $pet = mqPet();
        $slot = app(PetMediaService::class)->slot($pet, 'video', PetStateEnum::Idle);
        app(PetMediaService::class)->submitVideo($slot->id);
        mqRun($slot);
        expect($slot->fresh()->needsReview())->toBeTrue();

        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);
        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->filterTable('needs_review')
            ->assertCanSeeTableRecords([$slot->fresh()])
            ->assertTableColumnStateSet('quality', 'needs review', $slot->fresh())
            ->callTableAction('approveQuality', $slot->fresh());

        expect($slot->fresh())->status->toBe('ready')->quality->toBe('approved')->storage_path->toBe("{$pet->id}/idle-g2.mp4")->candidate_path->toBeNull()
            ->and((array) app(PetMediaService::class)->payloadFor($pet->fresh())['videos'])->toHaveKey('idle')
            ->and(AppSettingChange::where('key', 'media_quality_approve')->sole()->user_id)->toBe($admin->id);
    });

    it('a non-superadmin cannot approve', function () {
        mqFakeFal(['frozen', 'frozen']);
        $pet = mqPet();
        $slot = app(PetMediaService::class)->slot($pet, 'video', PetStateEnum::Idle);
        app(PetMediaService::class)->submitVideo($slot->id);
        mqRun($slot);

        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => false]));
        expect(MediaRelationManager::canViewForRecord($pet, EditPet::class))->toBeFalse();
    });
});

describe('switch »Quality check« off = the old behaviour', function () {
    it('a downloaded clip is stored at once, unchecked (no ffmpeg, no vision call, no check row)', function () {
        app(AppSettingsService::class)->updateQualityCheck(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]), false);
        mqFakeFal(['moving']);
        if (! mqHasFfmpeg()) {
            Http::fake(['v3.fal.media/files/mq/*' => Http::response("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4'])]);
        }
        $pet = mqPet();
        $slot = app(PetMediaService::class)->slot($pet, 'video', PetStateEnum::Idle);
        app(PetMediaService::class)->submitVideo($slot->id);
        mqRun($slot);

        expect($slot->fresh())->status->toBe('ready')->quality->toBeNull()->candidate_path->toBeNull()
            ->and(PetMediaQualityCheck::count())->toBe(0)->and(mqVisionCalls())->toBe(0)
            ->and(AppSettingChange::where('key', AppSettingsService::QUALITY_CHECK_KEY)->count())->toBe(1);
    });
});

/* ─────────────────────────── media:quality-check ─────────────────────────── */

describe('media:quality-check (stored clips)', function () {
    beforeEach(function () {
        if (! mqHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed (CI installs it; production image has it).');
        }
    });

    it('--dry-run: technical only, nothing written, no paid call', function () {
        mqFakeFal(['moving']);
        $pet = mqPet();
        $clip = mqStoredClip($pet, 'frozen');

        $this->artisan('media:quality-check', ['--pet' => $pet->id, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: technical check only')
            ->expectsOutputToContain('FAIL')
            ->assertSuccessful();

        expect($clip->fresh()->quality)->toBeNull()
            ->and(PetMediaQualityCheck::count())->toBe(0)
            ->and(Http::recorded())->toHaveCount(0);
    });

    it('real run flags a bad stored clip (not shown) and --regenerate re-queues it once; good clips pass with the AI check', function () {
        mqFakeFal(['moving'], [mqVisionAnswer(true)]);
        $bad = mqPet();
        $badClip = mqStoredClip($bad, 'frozen');
        $good = mqPet();
        $goodClip = mqStoredClip($good, 'moving');

        $this->artisan('media:quality-check', ['--regenerate' => true])
            ->expectsOutputToContain('2 clip(s) to check')
            ->expectsOutputToContain('Re-queued 1 clip(s) once')
            ->assertSuccessful();

        expect($goodClip->fresh()->quality)->toBe('passed')
            ->and($badClip->fresh())->quality->toBe('flagged')->generation->toBe(2)->quality_retry_generation->toBe(2)->status->toBe('running')
            ->and(mqVisionCalls())->toBe(1)   // only the clip that passed the technical check
            ->and(mqQueueCalls())->toBe(1)    // the one regeneration
            // Flagged → not shown while the new clip is made (the app's fallback).
            ->and((array) app(PetMediaService::class)->payloadFor($bad->fresh())['videos'])->not->toHaveKey('idle')
            ->and(AppSettingChange::where('key', 'media_quality_check')->sole()->new['failed_ids'])->toBe([$badClip->id]);

        // The regenerated clip (moving) passes → shown again; it is never re-queued a second time.
        mqRun($badClip);
        expect($badClip->fresh())->status->toBe('ready')->quality->toBe('passed')
            ->and(app(MediaQualityService::class)->regenerateFlagged($badClip->fresh()))->toBeFalse();
    });
});

/* ─────────────────────────── 5 s school performing clips ─────────────────────────── */

describe('school performing clips are 5 s (David 2026-10-23)', function () {
    it('performing → 5 s at Kling (0.56 $), attention / success / game clips stay 3 s; better sit prompt', function () {
        $profiles = app(MediaProfiles::class);
        foreach (PetPhaseClip::cases() as $clip) {
            $seconds = $profiles->clipVideo($clip->purpose())->durationSeconds;
            expect($seconds)->toBe($clip->group() === 'school' && $clip->phase() === 'performing' ? 5 : 3, $clip->value);
        }
        expect($profiles->clipVideo('school_performing')->videoInput('https://a/s.jpg', 'p')['duration'])->toBe('5')
            ->and(app(PetMediaService::class)->phaseClipCostUsd(PetPhaseClip::SchoolSitPerforming))->toBe(0.56)
            ->and(PetPhaseClip::SchoolSitPerforming->motion())->toContain('slowly lowers its hindquarters, the back legs fold first')
            ->and(PetPhaseClip::SchoolSitPerforming->motion())->toContain('keep their shape');
    });

    it('the payload carries each stored phase clip\'s length (older 3 s performing clips stay 3 s)', function () {
        $pet = mqPet();
        foreach (['school_sit_performing' => 5.0, 'school_come_performing' => 3.0, 'school_sit_success' => null] as $state => $seconds) {
            $path = "{$pet->id}/{$state}-g1.mp4";
            Storage::disk('pet_media')->put($path, 'x');
            PetMedia::create(['pet_id' => $pet->id, 'kind' => 'phase', 'state' => $state, 'status' => 'ready', 'generation' => 1, 'storage_path' => $path,
                'mime' => 'video/mp4', 'bytes' => 1, 'duration_seconds' => $seconds ?? 3.0, 'stored_duration_seconds' => $seconds]);
        }

        expect((array) app(PetMediaService::class)->payloadFor($pet->fresh())['phase_seconds'])
            ->toEqualCanonicalizing(['school_sit_performing' => 5.0, 'school_come_performing' => 3.0, 'school_sit_success' => 3.0])
            ->toHaveKey('school_sit_performing', 5.0);
    });
});
