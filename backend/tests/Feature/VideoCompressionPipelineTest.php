<?php

use App\Jobs\CompressPetMediaCandidate;
use App\Models\AppSetting;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Services\AppSettingsService;
use App\Services\Media\MediaQualityService;
use App\Services\Media\PetMediaService;
use App\Services\Media\VideoCompressionService;
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
        'profile' => 'kling_v3_pro', 'request_id' => 'vp-cand', 'source_url' => 'https://v3.fal.media/files/vp/x.mp4',
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
