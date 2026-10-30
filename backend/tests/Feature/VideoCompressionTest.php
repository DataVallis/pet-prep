<?php

use App\Filament\Pages\FeatureSwitches;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetMediaDerivative;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\Media\MediaClipAnalyzer;
use App\Services\Media\MediaStorage;
use App\Services\Media\PetMediaService;
use App\Services\Media\VideoCompressionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Stored video compression (David 2026-10-30) — media:compress, media:disk-report
|--------------------------------------------------------------------------
| Real ffmpeg on tiny generated MP4s (skipped without ffmpeg — CI has it). A
| "big" original = CRF 4 (re-encoding at CRF 21 saves a lot and looks the same);
| a "small" original = CRF 45 (re-encoding cannot save 30 %). A compressed file
| gets a NEW key (`-c.mp4`); the original goes after the grace period (QA #450).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
});

function vcHasFfmpeg(): bool
{
    static $has = null;

    return $has ??= Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful();
}

function vcNeedsFfmpeg(): void
{
    if (! vcHasFfmpeg()) {
        test()->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this (scripts/ci-install-ffmpeg.sh).');
    }
}

/** A tiny moving MP4 (test pattern) encoded at `$crf`. */
function vcClip(int $crf = 4, float $seconds = 2, int $fps = 12): string
{
    static $cache = [];
    $key = "{$crf}-{$seconds}-{$fps}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $out = tempnam(sys_get_temp_dir(), 'vcclip-').'.mp4';
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "testsrc2=size=144x256:rate={$fps}:duration={$seconds}",
        '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', (string) $crf, $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $cache[$key] = $bytes;
}

/** ffprobe facts of a file on a media disk. */
function vcProbe(string $path, string $disk = 'pet_media'): ?array
{
    $tmp = tempnam(sys_get_temp_dir(), 'vcprobe-');
    file_put_contents($tmp, Storage::disk($disk)->get($path));
    $probe = app(VideoCompressionService::class)->probe($tmp);
    @unlink($tmp);

    return $probe;
}

function vcLook(): PetLook
{
    static $n = 0;
    $n++;

    return PetLook::create(['breed_type' => 'mutt', 'pool_index' => 800 + $n, 'trait_fingerprint' => "vc-{$n}", 'dna' => ['seed' => $n, 'traits' => []]]);
}

/** A stored clip of a shared look (the row owning the file). */
function vcLookClip(PetLook $look, string $kind, string $state, string $bytes, string $disk = 'pet_media', int $generation = 1): PetMedia
{
    $path = "looks/{$look->id}/{$state}-puppy-g{$generation}.mp4";
    Storage::disk($disk)->put($path, $bytes);

    return PetMedia::create(['pet_look_id' => $look->id, 'kind' => $kind, 'state' => $state, 'life_stage' => 'puppy', 'status' => 'ready',
        'generation' => $generation, 'storage_path' => $path, 'mime' => 'video/mp4', 'bytes' => strlen($bytes), 'profile' => 'kling_v3_pro',
        'duration_seconds' => 2, 'stored_duration_seconds' => 2]);
}

function vcPet(): Pet
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);

    return Pet::factory()->freePlan()->create(['user_id' => $child->id, 'breed_type' => 'mutt', 'media_status' => 'ready']);
}

/** A pool pet's slot pointing at a look row. */
function vcLink(Pet $pet, PetMedia $row): PetMedia
{
    return PetMedia::create(['pet_id' => $pet->id, 'look_media_id' => $row->id, 'kind' => $row->kind, 'state' => $row->state, 'status' => 'ready',
        'generation' => 1, 'storage_path' => $row->storage_path, 'mime' => 'video/mp4', 'bytes' => $row->bytes,
        'duration_seconds' => 2, 'stored_duration_seconds' => 2]);
}

function vcSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::VIDEO_COMPRESSION_KEY], ['value' => ['enabled' => $on]]);
}

/** A tiny MP4 with an AAC audio stream. */
function vcClipWithAudio(): string
{
    $out = tempnam(sys_get_temp_dir(), 'vcaudio-').'.mp4';
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=144x256:rate=12:duration=2', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
        '-shortest', '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '4', '-c:a', 'aac', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $bytes;
}

/**
 * The service with test hooks: `before` (after the marker, before the upload), `after`
 * (after the upload, before the DB swap), `extraArgs` (inserted before the output file),
 * `afterCompress` (after each compress()).
 *
 * @param  array{before?: Closure, after?: Closure, extraArgs?: list<string>, afterCompress?: Closure}  $hooks
 */
function vcService(array $hooks = []): VideoCompressionService
{
    return new class($hooks, app(PetMediaService::class), app(MediaClipAnalyzer::class), app(MediaStorage::class)) extends VideoCompressionService
    {
        /** @param array<string, mixed> $hooks */
        public function __construct(private array $hooks, PetMediaService $media, MediaClipAnalyzer $analyzer, MediaStorage $storage)
        {
            parent::__construct($media, $analyzer, $storage);
        }

        protected function beforeUpload(PetMedia $row): void
        {
            if (isset($this->hooks['before'])) {
                ($this->hooks['before'])($row);
            }
        }

        protected function afterUpload(PetMedia $row): void
        {
            if (isset($this->hooks['after'])) {
                ($this->hooks['after'])($row);
            }
        }

        public function encodeArgs(string $in, string $out, array $source): array
        {
            $args = parent::encodeArgs($in, $out, $source);
            array_splice($args, -1, 0, $this->hooks['extraArgs'] ?? []);

            return $args;
        }

        public function compress(int $id, bool $replace = true, bool $recordFailures = true, bool $retryFailed = false): array
        {
            $result = parent::compress($id, $replace, $recordFailures, $retryFailed);
            if (isset($this->hooks['afterCompress'])) {
                ($this->hooks['afterCompress'])($result);
            }

            return $result;
        }
    };
}

describe('compressing one file', function () {
    it('writes a new key when it saves ≥ 30 % with SSIM ≥ 0.98, switches owner + pool rows + derived clip source in one go, and keeps the original for the grace period', function () {
        vcNeedsFfmpeg();
        $look = vcLook();
        $original = vcClip(4);
        $row = vcLookClip($look, 'phase', 'school_sit_attention', $original);
        $old = $row->storage_path;
        $pool = vcLink(vcPet(), $row);
        $derived = PetMediaDerivative::create(['pet_media_id' => $row->id, 'key' => 'school_sit_attention_loop', 'status' => 'ready',
            'source_path' => $old, 'storage_path' => "looks/{$look->id}/x-loop.mp4", 'bytes' => 10, 'duration_seconds' => 3.8]);
        $before = vcProbe($old);

        $result = app(VideoCompressionService::class)->compress($row->id);

        expect($result['outcome'])->toBe('compressed')
            ->and($result['saving_percent'])->toBeGreaterThanOrEqual(30.0)
            ->and($result['ssim'])->toBeGreaterThanOrEqual(0.98)
            ->and($result['min_ssim'])->toBeGreaterThanOrEqual(0.95);

        $row->refresh();
        $new = "looks/{$look->id}/school_sit_attention-puppy-g1-c.mp4";
        $stored = (string) Storage::disk('pet_media')->get($new);
        $after = vcProbe($new);
        expect($row->storage_path)->toBe($new)
            ->and(strlen($stored))->toBeLessThan(strlen($original))
            ->and($row->bytes)->toBe(strlen($stored))
            ->and($row->original_bytes)->toBe(strlen($original))
            ->and($row->original_path)->toBe($old)
            ->and($row->compression_status)->toBe('compressed')
            ->and($row->compression_path)->toBe($new)
            ->and($row->compression_target_path)->toBeNull()
            ->and($row->compressed_at)->not->toBeNull()
            ->and($pool->fresh()->storage_path)->toBe($new)
            ->and($pool->fresh()->bytes)->toBe(strlen($stored))
            // The derived school clip follows the file (still current — never regenerated).
            ->and($derived->fresh()->source_path)->toBe($new)
            ->and($derived->fresh()->isCurrentFor($pool->fresh()->storage_path))->toBeTrue()
            // The original stays until the grace period is over.
            ->and(Storage::disk('pet_media')->get($old))->toBe($original)
            ->and([$after['width'], $after['height'], $after['frames']])->toBe([$before['width'], $before['height'], $before['frames']])
            ->and(abs($after['duration'] - $before['duration']))->toBeLessThanOrEqual(0.05)
            ->and($after['audio'])->toBeFalse();
    });

    it('deletes the original only after the grace period and only when nothing references it', function () {
        vcNeedsFfmpeg();
        Carbon::setTestNow('2026-10-30 22:37:00');
        $look = vcLook();
        $a = vcLookClip($look, 'video', 'idle', vcClip(4));
        $b = vcLookClip($look, 'video', 'sleeping', vcClip(4));
        $service = app(VideoCompressionService::class);
        $service->compress($a->id);
        $service->compress($b->id);
        $oldA = $a->fresh()->original_path;
        $oldB = $b->fresh()->original_path;
        // A pool row linked while the swap ran still shows B's original.
        PetMedia::create(['pet_id' => vcPet()->id, 'kind' => 'video', 'state' => 'sleeping', 'status' => 'ready', 'generation' => 1, 'storage_path' => $oldB]);

        Carbon::setTestNow('2026-10-30 23:37:00');
        expect($service->deleteCompressedOriginals())->toBe(0);
        Storage::disk('pet_media')->assertExists([$oldA, $oldB]);

        Carbon::setTestNow('2026-10-31 02:37:00');
        expect($service->deleteCompressedOriginals())->toBe(1);
        Storage::disk('pet_media')->assertMissing($oldA);
        Storage::disk('pet_media')->assertExists([$oldB, $a->fresh()->storage_path]);
        expect($a->fresh()->original_path)->toBeNull()->and($b->fresh()->original_path)->toBe($oldB);
    });

    it('keeps the original when the saving is below 30 % and never tries that file again', function () {
        vcNeedsFfmpeg();
        $original = vcClip(45);
        $row = vcLookClip(vcLook(), 'video', 'idle', $original);

        expect(app(VideoCompressionService::class)->compress($row->id)['outcome'])->toBe('rejected');

        $row->refresh();
        expect(Storage::disk('pet_media')->get($row->storage_path))->toBe($original)
            ->and($row->bytes)->toBe(strlen($original))
            ->and($row->compression_status)->toBe('rejected')
            ->and($row->compression_note)->toContain('saving')
            ->and($row->compressed_at)->toBeNull()
            ->and(Storage::disk('pet_media')->exists(VideoCompressionService::targetPath($row->storage_path)))->toBeFalse()
            ->and(app(VideoCompressionService::class)->candidates()->pluck('id')->all())->toBe([])
            ->and(app(VideoCompressionService::class)->compress($row->id)['outcome'])->toBe('not_candidate');
    });

    it('keeps the original when the mean SSIM or a single frame is below its threshold', function () {
        vcNeedsFfmpeg();
        config(['media.compression.min_ssim' => 0.99999]);
        $row = vcLookClip(vcLook(), 'transition', 'idle_to_sleeping', vcClip(4));
        $result = app(VideoCompressionService::class)->compress($row->id);
        expect($result['outcome'])->toBe('rejected')->and($result['note'])->toStartWith('SSIM');

        config(['media.compression.min_ssim' => 0.5, 'media.compression.min_frame_ssim' => 0.99999]);
        $other = vcLookClip(vcLook(), 'transition', 'sleeping_to_idle', vcClip(4));
        $result = app(VideoCompressionService::class)->compress($other->id);
        expect($result['outcome'])->toBe('rejected')->and($result['note'])->toStartWith('frame SSIM')
            ->and($other->fresh()->compression_status)->toBe('rejected');
    });

    it('rejects a source with an audio stream and a result whose frames differ', function () {
        vcNeedsFfmpeg();
        $audio = vcLookClip(vcLook(), 'video', 'playing', vcClipWithAudio());
        $result = app(VideoCompressionService::class)->compress($audio->id);
        expect($result['outcome'])->toBe('rejected')->and($result['note'])->toBe('source has an audio stream');

        $row = vcLookClip(vcLook(), 'video', 'hungry', vcClip(4));
        $result = vcService(['extraArgs' => ['-frames:v', '10']])->compress($row->id);
        expect($result['outcome'])->toBe('rejected')->and($result['note'])->toBe('frames 10 ≠ 24');

        $probe = ['frames' => 24, 'duration' => 2.0, 'width' => 144, 'height' => 256];
        expect(VideoCompressionService::mismatch($probe, [...$probe, 'duration' => 2.04]))->toBeNull()
            ->and(VideoCompressionService::mismatch($probe, [...$probe, 'duration' => 2.1]))->toBe('duration 2.100 s ≠ 2.000 s')
            ->and(VideoCompressionService::mismatch($probe, [...$probe, 'width' => 146]))->toBe('size 146x256 ≠ 144x256');
    });

    it('drops the new key when the row gets a new file between the encode and the swap', function () {
        vcNeedsFfmpeg();
        $look = vcLook();
        $row = vcLookClip($look, 'video', 'sleeping', vcClip(4));
        $old = $row->storage_path;
        $regenerated = "looks/{$look->id}/sleeping-puppy-g2.mp4";

        $result = vcService(['before' => function (PetMedia $r) use ($regenerated): void {
            Storage::disk('pet_media')->put($regenerated, vcClip(4));
            PetMedia::whereKey($r->id)->update(['storage_path' => $regenerated, 'generation' => 2]);
        }])->compress($row->id);

        $row->refresh();
        expect($result['outcome'])->toBe('stale')
            ->and($row->storage_path)->toBe($regenerated)
            ->and($row->compression_status)->toBeNull()
            ->and($row->compression_target_path)->toBeNull()
            ->and(Storage::disk('pet_media')->exists(VideoCompressionService::targetPath($old)))->toBeFalse()
            ->and(app(VideoCompressionService::class)->candidates()->pluck('id')->all())->toBe([$row->id]);
    });

    it('undoes an upload whose swap failed (error after the upload) and counts it as a failure', function () {
        vcNeedsFfmpeg();
        $original = vcClip(4);
        $row = vcLookClip(vcLook(), 'video', 'sick', $original);

        $result = vcService(['after' => fn () => throw new RuntimeException('database went away')])->compress($row->id);

        $row->refresh();
        expect($result['outcome'])->toBe('failed')
            ->and($row->storage_path)->toEndWith('sick-puppy-g1.mp4')
            ->and($row->compression_status)->toBe('failed')
            ->and($row->compression_attempts)->toBe(1)
            ->and(Storage::disk('pet_media')->get($row->storage_path))->toBe($original)
            ->and(Storage::disk('pet_media')->exists(VideoCompressionService::targetPath($row->storage_path)))->toBeFalse();
    });

    it('finishes a swap interrupted after the upload (marker + new key) and undoes one whose new key is incomplete — never a failure', function () {
        $look = vcLook();
        $done = vcLookClip($look, 'phase', 'school_come_attention', str_repeat('o', 100));
        $done->update(['bytes' => 100]);
        $pool = vcLink(vcPet(), $done);
        $derived = PetMediaDerivative::create(['pet_media_id' => $done->id, 'key' => 'school_come_attention_loop', 'status' => 'ready',
            'source_path' => $done->storage_path, 'storage_path' => 'looks/l.mp4', 'bytes' => 1, 'duration_seconds' => 1]);
        $half = vcLookClip($look, 'video', 'idle', str_repeat('o', 100));
        foreach ([[$done, 40, 40], [$half, 40, 12]] as [$row, $expected, $written]) {
            $target = VideoCompressionService::targetPath($row->storage_path);
            Storage::disk('pet_media')->put($target, str_repeat('c', $written));
            $row->update(['compression_status' => 'replacing', 'compression_path' => $row->storage_path, 'compression_target_path' => $target,
                'compression_target_bytes' => $expected, 'compression_note' => '-60 %, SSIM 0.9900 (min frame 0.9800)']);
        }
        $oldDone = $done->storage_path;

        expect(app(VideoCompressionService::class)->recoverInterrupted())->toBe(['completed' => 1, 'reset' => 1, 'busy' => 0]);

        $done->refresh();
        $half->refresh();
        expect($done->storage_path)->toBe(VideoCompressionService::targetPath($oldDone))
            ->and($done->compression_status)->toBe('compressed')
            ->and($done->bytes)->toBe(40)->and($done->original_bytes)->toBe(100)->and($done->original_path)->toBe($oldDone)
            ->and($pool->fresh()->storage_path)->toBe($done->storage_path)
            ->and($derived->fresh()->source_path)->toBe($done->storage_path)
            ->and($half->compression_status)->toBeNull()
            ->and($half->compression_attempts)->toBe(0)
            ->and(Storage::disk('pet_media')->exists(VideoCompressionService::targetPath($half->storage_path)))->toBeFalse()
            ->and(app(VideoCompressionService::class)->candidates()->pluck('id')->all())->toBe([$half->id]);
    });

    it('records a failure per file, stops after 3 attempts, and --retry-failed takes it again', function () {
        vcNeedsFfmpeg();
        $row = vcLookClip(vcLook(), 'video', 'hungry', 'not a video at all');
        $service = app(VideoCompressionService::class);

        foreach ([1, 2, 3] as $attempt) {
            expect($service->compress($row->id)['outcome'])->toBe('failed')
                ->and($row->fresh()->compression_attempts)->toBe($attempt);
        }

        expect($service->candidates()->count())->toBe(0)
            ->and($service->candidates(retryFailed: true)->pluck('id')->all())->toBe([$row->id])
            ->and(Storage::disk('pet_media')->get($row->storage_path))->toBe('not a video at all');
    });

    it('works on object storage (temp copy in, the new key written to the bucket, nothing on the local disk)', function () {
        vcNeedsFfmpeg();
        Storage::fake('pet_media_s3');
        config(['media.storage.disk' => 'pet_media_s3']);
        $original = vcClip(4);
        $row = vcLookClip(vcLook(), 'phase', 'ball_waiting', $original, 'pet_media_s3');

        expect(app(VideoCompressionService::class)->compress($row->id)['outcome'])->toBe('compressed');

        $row->refresh();
        $stored = (string) Storage::disk('pet_media_s3')->get($row->storage_path);
        expect($row->storage_path)->toEndWith('-c.mp4')
            ->and(strlen($stored))->toBeLessThan(strlen($original))
            ->and($row->bytes)->toBe(strlen($stored))
            ->and(Storage::disk('pet_media')->allFiles())->toBe([]);
    });
});

describe('selection', function () {
    it('takes each file once — the shared look row, never the pool pets pointing at it — and only shown, settled MP4 clips', function () {
        $look = vcLook();
        $row = vcLookClip($look, 'video', 'idle', 'x');
        $pet = vcPet();
        vcLink($pet, $row);
        vcLink(vcPet(), $row);
        // Own (unique) pet clip: a candidate too.
        $own = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'transition', 'state' => 'idle_to_sleeping', 'status' => 'ready', 'generation' => 1,
            'storage_path' => "{$pet->id}/idle_to_sleeping-g1.mp4", 'mime' => 'video/mp4', 'bytes' => 5]);
        // Not candidates: an image, a flagged clip, a clip in flight, an open candidate, a .mov,
        // a pet row showing a look file without look_media_id, an already compressed key.
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'image', 'life_stage' => 'puppy', 'status' => 'ready', 'generation' => 1, 'storage_path' => 'looks/r.jpg', 'mime' => 'image/jpeg']);
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'hungry', 'life_stage' => 'puppy', 'status' => 'ready', 'generation' => 1, 'storage_path' => 'looks/h.mp4', 'quality' => 'flagged']);
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'playing', 'life_stage' => 'puppy', 'status' => 'running', 'generation' => 2, 'storage_path' => 'looks/p.mp4']);
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'sick', 'life_stage' => 'puppy', 'status' => 'ready', 'generation' => 1, 'storage_path' => 'looks/s.mp4', 'candidate_path' => 'looks/s2.mp4']);
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'sleeping', 'life_stage' => 'puppy', 'status' => 'ready', 'generation' => 1, 'storage_path' => 'looks/z.mov', 'mime' => 'video/quicktime']);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'sick', 'status' => 'ready', 'generation' => 1, 'storage_path' => $row->storage_path]);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'phase', 'state' => 'ball_waiting', 'status' => 'ready', 'generation' => 1, 'storage_path' => "{$pet->id}/ball_waiting-g1-c.mp4"]);

        $service = app(VideoCompressionService::class);
        expect($service->candidates()->orderBy('id')->pluck('id')->all())->toBe([$row->id, $own->id])
            ->and($service->candidates(petId: $pet->id)->orderBy('id')->pluck('id')->all())->toBe([$row->id, $own->id])
            ->and($service->candidates(lookId: $look->id)->pluck('id')->all())->toBe([$row->id]);

        // A file another row has already compressed is never taken again.
        PetMedia::whereKey($own->id)->update(['storage_path' => 'shared/x.mp4']);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'accident', 'status' => 'ready', 'generation' => 1, 'storage_path' => 'shared/x.mp4',
            'compression_status' => 'compressed', 'compression_path' => 'shared/x.mp4']);
        expect($service->candidates()->pluck('id')->all())->toBe([$row->id]);
    });

    it('builds a niced, memory-capped, thread-limited H.264 High CRF 21 slow encode at the source rate, video only, faststart; SSIM with one thread', function () {
        $service = app(VideoCompressionService::class);
        $args = $service->encodeArgs('/in', '/out.mp4', ['rate' => '24/1']);
        $hasPrlimit = Process::run(['prlimit', '--version'])->successful();

        expect(array_slice($args, 0, 3))->toBe(['nice', '-n', '19'])
            ->and(implode(' ', $args))->toContain('-map 0:v:0 -an')
            ->toContain('-c:v libx264 -profile:v high -pix_fmt yuv420p -crf 21 -preset slow -threads 2 -fps_mode cfr -r 24/1 -movflags +faststart /out.mp4')
            ->not->toContain('scale');
        if ($hasPrlimit) {
            expect(array_slice($args, 3, 2))->toBe(['prlimit', '--as='.(2048 * 1024 * 1024)]);
        }
        expect(implode(' ', $service->ssimArgs('/a', '/b', '/s.log')))->toContain('[0:v][1:v]ssim=stats_file=/s.log -threads 1')
            ->and(VideoCompressionService::parseSsim('[Parsed_ssim_0 @ 0x1] SSIM Y:0.991 (20.4) U:0.99 V:0.99 All:0.990512 (20.2)'))->toBe(0.990512)
            ->and(VideoCompressionService::parseSsim('nothing'))->toBeNull()
            ->and(VideoCompressionService::parseSsimStats("n:1 Y:0.99 U:0.99 V:0.99 All:0.991 (20.1)\nn:2 Y:0.95 U:0.96 V:0.96 All:0.953 (13.2)\n"))->toBe(0.953)
            ->and(VideoCompressionService::targetPath('looks/7/idle-puppy-g1.mp4'))->toBe('looks/7/idle-puppy-g1-c.mp4')
            ->and($service->lockSeconds())->toBe(4 * 300 + 60);
    });
});

describe('media:compress command', function () {
    it('is a no-op while the switch is off (default)', function () {
        vcNeedsFfmpeg();
        $original = vcClip(4);
        $row = vcLookClip(vcLook(), 'video', 'idle', $original);

        Artisan::call('media:compress');

        expect(Artisan::output())->toContain('Video compression is off')
            ->and(Storage::disk('pet_media')->allFiles())->toBe([$row->storage_path])
            ->and($row->fresh()->compression_status)->toBeNull()
            ->and(app(AppSettingsService::class)->videoCompression(fresh: true))->toBeFalse();
    });

    it('--dry-run lists the candidates and their total MB and changes nothing (switch on)', function () {
        vcSwitch(true);
        $row = vcLookClip(vcLook(), 'video', 'idle', 'x');
        $row->update(['bytes' => 3 * 1048576]);

        Artisan::call('media:compress', ['--dry-run' => true]);

        expect(Artisan::output())->toContain("candidate #{$row->id}")->toContain('1 candidate file(s), 3.0 MB in total')
            ->and(Storage::disk('pet_media')->allFiles())->toBe([$row->storage_path])
            ->and($row->fresh()->compression_path)->toBeNull();
    });

    it('--sample encodes to temp files and reports saving and SSIM per file without replacing anything (also while off)', function () {
        vcNeedsFfmpeg();
        $original = vcClip(4);
        $rows = [vcLookClip(vcLook(), 'video', 'idle', $original), vcLookClip(vcLook(), 'video', 'idle', $original)];

        Artisan::call('media:compress', ['--sample' => 1]);

        expect(Artisan::output())->toContain("would_compress #{$rows[0]->id}")->toContain('min frame')->toContain('Sample (nothing replaced): 1 file(s)')
            ->not->toContain("#{$rows[1]->id}");
        foreach ($rows as $row) {
            expect(Storage::disk('pet_media')->allFiles(dirname($row->storage_path)))->toBe([$row->storage_path])
                ->and($row->fresh()->compression_path)->toBeNull();
        }
    });

    it('compresses the candidates when on, each shared file once, and --limit caps the files of a run', function () {
        vcNeedsFfmpeg();
        vcSwitch(true);
        $look = vcLook();
        $a = vcLookClip($look, 'video', 'idle', vcClip(4));
        vcLink(vcPet(), $a);
        $b = vcLookClip($look, 'video', 'sleeping', vcClip(4));

        Artisan::call('media:compress', ['--limit' => 1]);
        expect(Artisan::output())->toContain('Done: 1 file(s)')->toContain('(stopped: limit)')
            ->and($a->fresh()->compression_status)->toBe('compressed')
            ->and($b->fresh()->compression_status)->toBeNull();

        Artisan::call('media:compress');
        expect($b->fresh()->compression_status)->toBe('compressed')
            ->and(PetMedia::whereNotNull('compression_status')->count())->toBe(2);
    });

    it('stops starting new files once the time budget is spent', function () {
        vcNeedsFfmpeg();
        vcSwitch(true);
        Carbon::setTestNow('2026-10-30 22:37:00');
        $a = vcLookClip(vcLook(), 'video', 'idle', vcClip(4));
        $b = vcLookClip(vcLook(), 'video', 'idle', vcClip(4));
        app()->instance(VideoCompressionService::class, vcService(['afterCompress' => fn () => Carbon::setTestNow(now()->addMinutes(11))]));

        Artisan::call('media:compress');

        expect(Artisan::output())->toContain('Done: 1 file(s)')->toContain('(stopped: time budget)')
            ->and($a->fresh()->compression_status)->toBe('compressed')
            ->and($b->fresh()->compression_status)->toBeNull();
    });

    it('stops as a system fault after 3 failures in a row without counting them; isolated failures are recorded', function () {
        vcNeedsFfmpeg();
        vcSwitch(true);
        $look = vcLook();
        $broken = collect(['idle', 'sleeping', 'hungry', 'playing'])->map(fn (string $s) => vcLookClip($look, 'video', $s, 'not a video'));

        expect(Artisan::call('media:compress'))->toBe(1)
            ->and(Artisan::output())->toContain('(stopped: system fault)')->toContain('not counted against the files')
            ->and(PetMedia::whereNotNull('compression_status')->count())->toBe(0);

        PetMedia::whereKey($broken->slice(2)->pluck('id')->all())->delete();
        $good = vcLookClip(vcLook(), 'video', 'idle', vcClip(4));

        expect(Artisan::call('media:compress'))->toBe(0)
            ->and($broken[0]->fresh()->compression_status)->toBe('failed')
            ->and($broken[0]->fresh()->compression_attempts)->toBe(1)
            ->and($broken[1]->fresh()->compression_status)->toBe('failed')
            ->and($good->fresh()->compression_status)->toBe('compressed');
    });

    it('deletes originals past the grace period at the start of a run', function () {
        vcNeedsFfmpeg();
        vcSwitch(true);
        Carbon::setTestNow('2026-10-30 22:37:00');
        $row = vcLookClip(vcLook(), 'video', 'idle', vcClip(4));
        app(VideoCompressionService::class)->compress($row->id);
        $original = $row->fresh()->original_path;

        Carbon::setTestNow('2026-10-31 02:37:00');
        Artisan::call('media:compress');

        expect(Artisan::output())->toContain('1 original(s) deleted after the grace period')
            ->and(Storage::disk('pet_media')->exists($original))->toBeFalse()
            ->and(Storage::disk('pet_media')->exists($row->fresh()->storage_path))->toBeTrue();
    });

    it('runs hourly at :37 only between 22:00 and 06:00 Ljubljana (summer and winter), with a lock in the background', function () {
        $event = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'media:compress'))->sole();
        expect($event->expression)->toBe('37 * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->runInBackground)->toBeTrue()
            ->and($event->timezone)->toBe('Europe/Ljubljana');

        // between() captures "now" when the schedule is defined (each schedule:run boots anew):
        // rebuild the schedule at every simulated time, like production does each minute.
        $at = function (string $utc): bool {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
            $schedule = new Schedule;
            app()->instance(Schedule::class, $schedule);
            ScheduleFacade::clearResolvedInstance(Schedule::class);
            require base_path('routes/console.php');

            return collect($schedule->events())->filter(fn ($e) => str_contains((string) $e->command, 'media:compress'))->sole()->filtersPass(app());
        };
        // Summer (UTC+2): 05:37 UTC = 07:37 local → no; 21:37 UTC = 23:37 → yes; 03:37 UTC = 05:37 → yes; 19:37 UTC = 21:37 → no.
        expect($at('2026-07-01 05:37:00'))->toBeFalse()
            ->and($at('2026-07-01 21:37:00'))->toBeTrue()
            ->and($at('2026-07-01 03:37:00'))->toBeTrue()
            ->and($at('2026-07-01 19:37:00'))->toBeFalse()
            // Winter (UTC+1): 21:37 UTC = 22:37 local → yes; 05:37 UTC = 06:37 → no; 04:37 UTC = 05:37 → yes.
            ->and($at('2026-01-15 21:37:00'))->toBeTrue()
            ->and($at('2026-01-15 05:37:00'))->toBeFalse()
            ->and($at('2026-01-15 04:37:00'))->toBeTrue();
    });

    it('is a superadmin feature switch (audited, default off)', function () {
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);
        Livewire::test(FeatureSwitches::class)
            ->assertFormSet(['video_compression' => false])
            ->fillForm(['video_compression' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(AppSettingsService::class)->videoCompression(fresh: true))->toBeTrue()
            ->and(AppSettingChange::where('key', 'video_compression')->sole()->user_id)->toBe($admin->id);
    });
});

describe('media:disk-report', function () {
    it('reports usage by kind, orphans, originals awaiting deletion and old candidates — and deletes nothing', function () {
        $look = vcLook();
        $row = vcLookClip($look, 'video', 'idle', str_repeat('a', 2048));
        $row->update(['bytes' => 2048, 'original_path' => 'looks/idle-orig.mp4', 'compressed_at' => now()]);
        Storage::disk('pet_media')->put('looks/idle-orig.mp4', str_repeat('o', 4096));
        Storage::disk('pet_media')->put('looks/orphan.mp4', 'zzz');
        $stuck = PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'sick', 'life_stage' => 'puppy', 'status' => 'running', 'generation' => 1,
            'candidate_path' => 'looks/sick-cand.mp4', 'candidate_bytes' => 3]);
        Storage::disk('pet_media')->put('looks/sick-cand.mp4', 'ccc');
        PetMedia::whereKey($stuck->id)->update(['updated_at' => now()->subDays(2)]);
        $before = Storage::disk('pet_media')->allFiles();

        Artisan::call('media:disk-report');
        $out = Artisan::output();

        expect($out)->toContain('video')->toContain('Files on disk: 4')
            ->toContain('Orphans (no pet_media / history / derivative / contact sheet row): 1')->toContain('orphan looks/orphan.mp4')
            ->toContain('awaiting deletion (grace period): 1')
            ->toContain('Clip candidates older than 24 h: 1')->toContain('looks/sick-cand.mp4')
            ->toContain('nothing was deleted')
            ->and(Storage::disk('pet_media')->allFiles())->toBe($before);
    });
});
