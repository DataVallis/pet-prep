<?php

use App\Jobs\CompressPetMediaCandidate;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetMediaDerivative;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\Media\MediaClipAnalyzer;
use App\Services\Media\MediaQualityService;
use App\Services\Media\MediaStorage;
use App\Services\Media\PetMediaService;
use App\Services\Media\VideoCompressionService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| New clips compressed BEFORE they are shown (David 2026-10-30)
|--------------------------------------------------------------------------
| StorePetMedia → candidate → CheckPetMediaQuality (verdict / check off) →
| CompressPetMediaCandidate → promote the compressed key, or the original on
| reject / failure (never a stuck slot). Switch `video_compression` off → the
| pipeline is unchanged. Real ffmpeg on tiny generated MP4s (skipped without it).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
});

function vpNeedsFfmpeg(): void
{
    static $has = null;
    $has ??= Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful();
    if (! $has) {
        test()->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this (scripts/ci-install-ffmpeg.sh).');
    }
}

/** A tiny moving MP4 (test pattern) encoded at `$crf` (4 = big → compresses; 45 = small → rejected). */
function vpClip(int $crf = 4): string
{
    static $cache = [];
    if (isset($cache[$crf])) {
        return $cache[$crf];
    }

    $out = tempnam(sys_get_temp_dir(), 'vpclip-').'.mp4';
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=144x256:rate=12:duration=2',
        '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', (string) $crf, '-movflags', '+faststart', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $cache[$crf] = $bytes;
}

function vpSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::VIDEO_COMPRESSION_KEY], ['value' => ['enabled' => $on]]);
}

function vpPet(): Pet
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);

    return Pet::factory()->freePlan()->create(['user_id' => $child->id, 'breed_type' => 'mutt', 'media_status' => 'ready']);
}

/** A running clip slot whose fal result (the given MP4 bytes) is ready to download. */
function vpRunningSlot(string $bytes, array $attrs = []): PetMedia
{
    static $n = 0;
    $n++;
    $url = "https://v3.fal.media/files/vp/clip-{$n}.mp4";
    Http::fake([$url => Http::response($bytes, 200, ['Content-Type' => 'video/mp4'])]);

    return PetMedia::create([
        'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'generation' => 1, 'profile' => 'kling_v3_pro',
        'request_id' => "vp-req-{$n}", 'source_url' => $url, 'duration_seconds' => 2,
        ...($attrs['pet_look_id'] ?? null ? [] : ['pet_id' => vpPet()->id]),
        ...$attrs,
    ]);
}

/** A running slot with a candidate already on disk (as after StorePetMedia with a check). */
function vpCandidateSlot(string $bytes): PetMedia
{
    $pet = vpPet();
    $path = "{$pet->id}/idle-g1.mp4";
    Storage::disk('pet_media')->put($path, $bytes);

    return PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'generation' => 1,
        'profile' => 'kling_v3_pro', 'request_id' => 'vp-cand-'.$pet->id, 'source_url' => 'https://v3.fal.media/files/vp/x.mp4',
        'candidate_path' => $path, 'candidate_bytes' => strlen($bytes), 'candidate_mime' => 'video/mp4', 'duration_seconds' => 2]);
}

it('serves a new clip only as its compressed copy (check off): the original is never stored as the shown file and is deleted', function () {
    vpNeedsFfmpeg();
    vpSwitch(true);
    $original = vpClip(4);
    $slot = vpRunningSlot($original);

    app(PetMediaService::class)->storeResult($slot->id);

    $slot->refresh();
    $files = Storage::disk('pet_media')->allFiles((string) $slot->pet_id);
    expect($slot->status)->toBe('ready')
        ->and($slot->storage_path)->toBe("{$slot->pet_id}/idle-g1-c.mp4")
        ->and($slot->candidate_path)->toBeNull()
        ->and($slot->compression_status)->toBe('compressed')
        ->and($slot->compression_path)->toBe($slot->storage_path)
        ->and($slot->original_bytes)->toBe(strlen($original))
        ->and($slot->bytes)->toBeLessThan(strlen($original))
        ->and($slot->original_path)->toBeNull()
        ->and($files)->toBe([$slot->storage_path])
        // Never compressed again by the nightly backfill.
        ->and(app(VideoCompressionService::class)->candidates()->count())->toBe(0);
});

it('compresses after the quality-check verdict and keeps the verdict (shadow mode)', function () {
    vpNeedsFfmpeg();
    vpSwitch(true);
    useQualityCheck('shadow');
    $slot = vpRunningSlot(vpClip(4));

    app(PetMediaService::class)->storeResult($slot->id);

    $slot->refresh();
    expect($slot->status)->toBe('ready')
        ->and($slot->storage_path)->toEndWith('-c.mp4')
        ->and($slot->quality)->not->toBeNull()
        ->and($slot->compression_status)->toBe('compressed');
});

it('promotes the original when the compression is rejected (not enough saving) — never a stuck slot', function () {
    vpNeedsFfmpeg();
    vpSwitch(true);
    $original = vpClip(45);
    $slot = vpRunningSlot($original);

    app(PetMediaService::class)->storeResult($slot->id);

    $slot->refresh();
    expect($slot->status)->toBe('ready')
        ->and($slot->storage_path)->toBe("{$slot->pet_id}/idle-g1.mp4")
        ->and(Storage::disk('pet_media')->get($slot->storage_path))->toBe($original)
        ->and($slot->compression_status)->toBe('rejected')
        ->and($slot->compression_path)->toBe($slot->storage_path)
        ->and(app(VideoCompressionService::class)->candidates()->count())->toBe(0);
});

it('promotes the original when ffmpeg fails, marks one failure, and the nightly backfill may retry it', function () {
    vpNeedsFfmpeg();
    vpSwitch(true);
    $slot = vpCandidateSlot('not a video at all');

    expect(app(MediaQualityService::class)->checkCandidate($slot->id))->toBe('compressing');

    $slot->refresh();
    expect($slot->status)->toBe('ready')
        ->and($slot->storage_path)->toBe("{$slot->pet_id}/idle-g1.mp4")
        ->and($slot->compression_status)->toBe('failed')
        ->and($slot->compression_attempts)->toBe(1)
        ->and(app(VideoCompressionService::class)->candidates()->pluck('id')->all())->toBe([$slot->id]);
});

it('promotes the original when the job gives up (timeout / crash) and a re-dispatch never loops', function () {
    vpSwitch(true);
    $slot = vpCandidateSlot(str_repeat('o', 64));
    $candidate = (string) $slot->candidate_path;

    (new CompressPetMediaCandidate($slot->id, $candidate, null, null))->failed(new RuntimeException('Job timed out'));

    $slot->refresh();
    expect($slot->status)->toBe('ready')
        ->and($slot->storage_path)->toBe($candidate)
        ->and($slot->compression_status)->toBe('failed')
        ->and($slot->compression_note)->toContain('gave up')
        ->and(app(VideoCompressionService::class)->wantsCandidate($slot, $candidate))->toBeFalse();
});

it('leaves the pipeline unchanged while the switch is off (stored at once, uncompressed)', function () {
    vpNeedsFfmpeg();
    $original = vpClip(4);
    $slot = vpRunningSlot($original);

    app(PetMediaService::class)->storeResult($slot->id);

    $slot->refresh();
    expect($slot->status)->toBe('ready')
        ->and($slot->storage_path)->toBe("{$slot->pet_id}/idle-g1.mp4")
        ->and(Storage::disk('pet_media')->get($slot->storage_path))->toBe($original)
        ->and($slot->compression_status)->toBeNull()
        ->and($slot->candidate_path)->toBeNull();
});

it('compresses a shared look file once (the look row)', function () {
    vpNeedsFfmpeg();
    vpSwitch(true);
    $look = PetLook::create(['breed_type' => 'mutt', 'pool_index' => 777, 'trait_fingerprint' => 'vp-look', 'dna' => ['seed' => 1, 'traits' => []]]);
    $slot = vpRunningSlot(vpClip(4), ['pet_look_id' => $look->id, 'life_stage' => 'puppy']);

    app(PetMediaService::class)->storeResult($slot->id);

    $slot->refresh();
    expect($slot->status)->toBe('ready')
        ->and($slot->storage_path)->toBe("looks/{$look->id}/idle-puppy-g1-c.mp4")
        ->and(Storage::disk('pet_media')->allFiles("looks/{$look->id}"))->toBe([$slot->storage_path])
        ->and(PetMedia::where('compression_status', 'compressed')->count())->toBe(1);
});

it('measures with the fast pipeline preset (veryfast) — the nightly backfill keeps slow', function () {
    expect(config('media.compression.pipeline_preset'))->toBe('veryfast')
        ->and(config('media.compression.preset'))->toBe('slow')
        ->and(config('media.compression.pipeline_process_timeout_seconds') * 4)->toBeLessThan((new CompressPetMediaCandidate(1, 'x', null, null))->timeout);
});

/**
 * The service with a hook after a committed pipeline swap (before the promotion).
 */
function vpService(?Closure $beforePromote = null): VideoCompressionService
{
    return new class($beforePromote, app(PetMediaService::class), app(MediaClipAnalyzer::class), app(MediaStorage::class), app(AppSettingsService::class)) extends VideoCompressionService
    {
        public function __construct(private ?Closure $hook, PetMediaService $media, MediaClipAnalyzer $analyzer, MediaStorage $storage, AppSettingsService $settings)
        {
            parent::__construct($media, $analyzer, $storage, $settings);
        }

        protected function beforePromote(PetMedia $row): void
        {
            if ($this->hook !== null) {
                ($this->hook)($row);
            }
        }
    };
}

/** ffprobe JSON of a 2 s 144 × 256 12 fps video-only clip (for Process::fake). */
function vpProbeJson(): string
{
    return json_encode(['streams' => [['codec_type' => 'video', 'width' => 144, 'height' => 256, 'r_frame_rate' => '12/1', 'nb_read_packets' => '24', 'duration' => '2.0']], 'format' => ['duration' => '2.0']]);
}

it('encodes new clips with the pipeline preset (veryfast, 20 s per call) and the backfill with slow (its own timeout)', function () {
    vpSwitch(true);
    Process::fake(fn (PendingProcess $p) => Process::result(str_contains(implode(' ', (array) $p->command), 'ffprobe') ? vpProbeJson() : ''));
    $slot = vpCandidateSlot(str_repeat('v', 64));

    app(MediaQualityService::class)->checkCandidate($slot->id);

    Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '-preset veryfast') && $p->timeout === 20);
    Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '-preset slow'));
    // ffmpeg "made" nothing → failure → the original is shown, never a stuck slot.
    expect($slot->fresh()->status)->toBe('ready')->and(app(VideoCompressionService::class)->lockSeconds())->toBe(4 * 300 + 60);

    $stored = $slot->fresh();
    $stored->update(['compression_path' => null, 'compression_status' => null, 'compression_attempts' => 0]);
    app(VideoCompressionService::class)->compress($stored->id);

    Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), '-preset slow') && $p->timeout === 300);
});

it('takes a pipeline lock sized from the pipeline timeout (QA #452 m1) and releases the job for 30 s while the row is busy', function () {
    vpSwitch(true);
    $slot = vpCandidateSlot(str_repeat('b', 64));
    $lock = Cache::lock('media-compress:'.$slot->id, 600);
    expect($lock->get())->toBeTrue();

    $job = (new CompressPetMediaCandidate($slot->id, (string) $slot->candidate_path, null, null))->withFakeQueueInteractions();
    $job->handle(app(VideoCompressionService::class));

    $job->assertReleased(delay: 30);
    expect($slot->fresh()->status)->toBe('running');
    $lock->release();
});

it('keeps the compressed key when the slot changes between the swap and the promotion, drops the original, and finishes it on the next attempt', function () {
    vpNeedsFfmpeg();
    vpSwitch(true);
    $slot = vpCandidateSlot(vpClip(4));
    $original = (string) $slot->candidate_path;
    $target = VideoCompressionService::targetPath($original);

    $outcome = vpService(fn (PetMedia $r) => PetMedia::whereKey($r->id)->update(['status' => 'pending']))->compressCandidate($slot->id, $original, null, null);

    expect($outcome)->toBe('skipped')
        ->and($slot->fresh()->candidate_path)->toBe($target)
        ->and(Storage::disk('pet_media')->exists($target))->toBeTrue()
        ->and(Storage::disk('pet_media')->exists($original))->toBeFalse();

    // Back to running (e.g. a re-dispatch): the next attempt promotes the compressed key.
    PetMedia::whereKey($slot->id)->update(['status' => 'running']);
    expect(app(VideoCompressionService::class)->compressCandidate($slot->id, $original, null, null))->toBe('compressed')
        ->and($slot->fresh()->storage_path)->toBe($target)
        ->and($slot->fresh()->status)->toBe('ready');
});

it('lets failed() promote a compressed key whose swap committed, and drop a key a killed attempt left before its swap', function () {
    vpSwitch(true);
    $swapped = vpCandidateSlot(str_repeat('s', 64));
    $original = (string) $swapped->candidate_path;
    $target = VideoCompressionService::targetPath($original);
    Storage::disk('pet_media')->put($target, str_repeat('c', 16));
    $swapped->update(['candidate_path' => $target, 'compression_path' => $target, 'compression_status' => 'compressed']);

    (new CompressPetMediaCandidate($swapped->id, $original, null, null))->failed(new RuntimeException('killed after the swap'));

    expect($swapped->fresh()->status)->toBe('ready')->and($swapped->fresh()->storage_path)->toBe($target);

    $killed = vpCandidateSlot(str_repeat('k', 64));
    $killedTarget = VideoCompressionService::targetPath((string) $killed->candidate_path);
    Storage::disk('pet_media')->put($killedTarget, str_repeat('c', 16));

    (new CompressPetMediaCandidate($killed->id, (string) $killed->candidate_path, null, null))->failed(new RuntimeException('killed before the swap'));

    expect($killed->fresh()->status)->toBe('ready')
        ->and($killed->fresh()->storage_path)->toBe($killed->getOriginal('candidate_path'))
        ->and(Storage::disk('pet_media')->exists($killedTarget))->toBeFalse();
});

it('compresses after an enforce pass and makes the school derived clip from the compressed key', function () {
    vpNeedsFfmpeg();
    vpSwitch(true);
    useQualityCheck('enforce');
    $look = PetLook::create(['breed_type' => 'mutt', 'pool_index' => 778, 'trait_fingerprint' => 'vp-school', 'dna' => ['seed' => 2, 'traits' => []]]);
    $slot = vpRunningSlot(vpClip(4), ['pet_look_id' => $look->id, 'life_stage' => 'puppy', 'kind' => 'phase', 'state' => 'school_sit_attention']);

    app(PetMediaService::class)->storeResult($slot->id);

    $slot->refresh();
    $derived = PetMediaDerivative::where('pet_media_id', $slot->id)->first();
    expect($slot->status)->toBe('ready')
        ->and($slot->storage_path)->toBe("looks/{$look->id}/school_sit_attention-puppy-g1-c.mp4")
        ->and($slot->quality)->toBeIn([PetMedia::QUALITY_PASSED, PetMedia::QUALITY_PASSED_TECHNICAL, PetMedia::QUALITY_UNCHECKED])
        ->and($derived)->not->toBeNull()
        ->and($derived->source_path)->toBe($slot->storage_path)
        ->and($derived->isCurrentFor($slot->storage_path))->toBeTrue();
});

it('compresses an »Approve anyway« candidate too (audited at once, the compressed key shown)', function () {
    vpNeedsFfmpeg();
    vpSwitch(true);
    $slot = vpCandidateSlot(vpClip(4));
    $slot->update(['status' => 'failed', 'error_reason' => 'quality_failed', 'error' => 'frozen']);
    $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);

    expect(app(MediaQualityService::class)->approve($slot->fresh(), $admin))->toBeTrue();

    $slot->refresh();
    expect($slot->status)->toBe('ready')
        ->and($slot->storage_path)->toEndWith('-c.mp4')
        ->and($slot->quality)->toBe(PetMedia::QUALITY_APPROVED)
        ->and($slot->compression_status)->toBe('compressed')
        ->and(AppSettingChange::where('key', MediaQualityService::AUDIT_APPROVE)->count())->toBe(1);
});
