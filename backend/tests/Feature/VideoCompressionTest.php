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
use App\Services\Media\VideoCompressionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Stored video compression (David 2026-10-30) — media:compress, media:disk-report
|--------------------------------------------------------------------------
| Real ffmpeg on tiny generated MP4s (skipped without ffmpeg — CI has it). A
| "big" original = CRF 4 (re-encoding at CRF 21 saves a lot and looks the same);
| a "small" original = CRF 45 (re-encoding cannot save 30 %).
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

describe('compressing one file', function () {
    it('replaces a clip at the same path when it saves ≥ 30 % with SSIM ≥ 0.98 (same size, frames, duration) and updates bytes of every row showing it', function () {
        vcNeedsFfmpeg();
        $look = vcLook();
        $original = vcClip(4);
        $row = vcLookClip($look, 'phase', 'school_sit_attention', $original);
        $pool = vcLink(vcPet(), $row);
        $derived = PetMediaDerivative::create(['pet_media_id' => $row->id, 'key' => 'school_sit_attention_loop', 'status' => 'ready',
            'source_path' => $row->storage_path, 'storage_path' => 'looks/x-loop.mp4', 'bytes' => 10, 'duration_seconds' => 3.8]);
        $before = vcProbe($row->storage_path);

        $result = app(VideoCompressionService::class)->compress($row->id);

        expect($result['outcome'])->toBe('compressed')
            ->and($result['saving_percent'])->toBeGreaterThanOrEqual(30.0)
            ->and($result['ssim'])->toBeGreaterThanOrEqual(0.98);

        $stored = (string) Storage::disk('pet_media')->get($row->storage_path);
        $after = vcProbe($row->storage_path);
        $row->refresh();
        expect(strlen($stored))->toBeLessThan(strlen($original))
            ->and($row->bytes)->toBe(strlen($stored))
            ->and($row->original_bytes)->toBe(strlen($original))
            ->and($row->compression_status)->toBe('compressed')
            ->and($row->compression_path)->toBe($row->storage_path)
            ->and($row->compressed_at)->not->toBeNull()
            ->and($row->storage_path)->toBe("looks/{$look->id}/school_sit_attention-puppy-g1.mp4")
            ->and($pool->fresh()->bytes)->toBe(strlen($stored))
            // Same path: the derived school clip still belongs to the file (no regeneration).
            ->and($derived->fresh()->isCurrentFor($row->storage_path))->toBeTrue()
            ->and([$after['width'], $after['height'], $after['frames']])->toBe([$before['width'], $before['height'], $before['frames']])
            ->and(abs($after['duration'] - $before['duration']))->toBeLessThanOrEqual(0.05)
            ->and($after['audio'])->toBeFalse()
            ->and(Storage::disk('pet_media')->allFiles("looks/{$look->id}"))->toBe([$row->storage_path]);
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
            ->and(app(VideoCompressionService::class)->candidates()->pluck('id')->all())->toBe([])
            ->and(app(VideoCompressionService::class)->compress($row->id)['outcome'])->toBe('not_candidate');
    });

    it('keeps the original when SSIM is below the threshold', function () {
        vcNeedsFfmpeg();
        config(['media.compression.min_ssim' => 0.99999]);
        $original = vcClip(4);
        $row = vcLookClip(vcLook(), 'transition', 'idle_to_sleeping', $original);

        $result = app(VideoCompressionService::class)->compress($row->id);

        expect($result['outcome'])->toBe('rejected')->and($result['note'])->toContain('SSIM')
            ->and(Storage::disk('pet_media')->get($row->storage_path))->toBe($original)
            ->and($row->fresh()->compression_status)->toBe('rejected');
    });

    it('never compresses a compressed file twice; a new generation (new path) is a candidate again', function () {
        vcNeedsFfmpeg();
        $look = vcLook();
        $row = vcLookClip($look, 'video', 'sleeping', vcClip(4));
        $service = app(VideoCompressionService::class);

        expect($service->compress($row->id)['outcome'])->toBe('compressed');
        $once = Storage::disk('pet_media')->get($row->fresh()->storage_path);
        expect($service->candidates()->count())->toBe(0)
            ->and($service->compress($row->id)['outcome'])->toBe('not_candidate')
            ->and(Storage::disk('pet_media')->get($row->fresh()->storage_path))->toBe($once);

        $new = "looks/{$look->id}/sleeping-puppy-g2.mp4";
        Storage::disk('pet_media')->put($new, vcClip(4));
        $row->update(['storage_path' => $new, 'generation' => 2]);

        expect($service->candidates()->pluck('id')->all())->toBe([$row->id]);
    });

    it('records a failure per file and stops after 3 attempts', function () {
        vcNeedsFfmpeg();
        $row = vcLookClip(vcLook(), 'video', 'hungry', 'not a video at all');
        $service = app(VideoCompressionService::class);

        foreach ([1, 2, 3] as $attempt) {
            expect($service->compress($row->id)['outcome'])->toBe('failed')
                ->and($row->fresh()->compression_attempts)->toBe($attempt);
        }

        expect($service->candidates()->count())->toBe(0)
            ->and(Storage::disk('pet_media')->get($row->storage_path))->toBe('not a video at all');
    });

    it('works on object storage (temp copy in, the same key replaced, nothing on the local disk)', function () {
        vcNeedsFfmpeg();
        Storage::fake('pet_media_s3');
        config(['media.storage.disk' => 'pet_media_s3']);
        $original = vcClip(4);
        $row = vcLookClip(vcLook(), 'phase', 'ball_waiting', $original, 'pet_media_s3');

        expect(app(VideoCompressionService::class)->compress($row->id)['outcome'])->toBe('compressed');

        $stored = (string) Storage::disk('pet_media_s3')->get($row->storage_path);
        expect(strlen($stored))->toBeLessThan(strlen($original))
            ->and($row->fresh()->bytes)->toBe(strlen($stored))
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
        // Not candidates: an image, a flagged clip, a clip in flight, an open candidate, a .mov.
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'image', 'life_stage' => 'puppy', 'status' => 'ready', 'generation' => 1, 'storage_path' => 'looks/r.jpg', 'mime' => 'image/jpeg']);
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'hungry', 'life_stage' => 'puppy', 'status' => 'ready', 'generation' => 1, 'storage_path' => 'looks/h.mp4', 'quality' => 'flagged']);
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'playing', 'life_stage' => 'puppy', 'status' => 'running', 'generation' => 2, 'storage_path' => 'looks/p.mp4']);
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'sick', 'life_stage' => 'puppy', 'status' => 'ready', 'generation' => 1, 'storage_path' => 'looks/s.mp4', 'candidate_path' => 'looks/s2.mp4']);
        PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'sleeping', 'life_stage' => 'puppy', 'status' => 'ready', 'generation' => 1, 'storage_path' => 'looks/z.mov', 'mime' => 'video/quicktime']);

        $service = app(VideoCompressionService::class);
        expect($service->candidates()->orderBy('id')->pluck('id')->all())->toBe([$row->id, $own->id])
            ->and($service->candidates(petId: $pet->id)->orderBy('id')->pluck('id')->all())->toBe([$row->id, $own->id])
            ->and($service->candidates(lookId: $look->id)->pluck('id')->all())->toBe([$row->id]);
    });

    it('builds a niced, thread-limited H.264 High CRF 21 slow encode at the source rate, video only, faststart', function () {
        $args = app(VideoCompressionService::class)->encodeArgs('/in', '/out.mp4', ['rate' => '24/1']);

        expect(array_slice($args, 0, 3))->toBe(['nice', '-n', '19'])
            ->and(implode(' ', $args))->toContain('-map 0:v:0 -an')
            ->toContain('-c:v libx264 -profile:v high -pix_fmt yuv420p -crf 21 -preset slow -threads 2 -fps_mode cfr -r 24/1 -movflags +faststart')
            ->not->toContain('scale');
        expect(VideoCompressionService::parseSsim('[Parsed_ssim_0 @ 0x1] SSIM Y:0.991 (20.4) U:0.99 V:0.99 All:0.990512 (20.2)'))->toBe(0.990512)
            ->and(VideoCompressionService::parseSsim('nothing'))->toBeNull();
    });
});

describe('media:compress command', function () {
    it('is a no-op while the switch is off (default)', function () {
        vcNeedsFfmpeg();
        $original = vcClip(4);
        $row = vcLookClip(vcLook(), 'video', 'idle', $original);

        Artisan::call('media:compress');

        expect(Artisan::output())->toContain('Video compression is off')
            ->and(Storage::disk('pet_media')->get($row->storage_path))->toBe($original)
            ->and($row->fresh()->compression_status)->toBeNull()
            ->and(app(AppSettingsService::class)->videoCompression(fresh: true))->toBeFalse();
    });

    it('--dry-run lists the candidates and their total MB and changes nothing (switch on)', function () {
        vcSwitch(true);
        $original = vcClip(4);
        $row = vcLookClip(vcLook(), 'video', 'idle', $original);
        $row->update(['bytes' => 3 * 1048576]);

        Artisan::call('media:compress', ['--dry-run' => true]);

        expect(Artisan::output())->toContain("candidate #{$row->id}")->toContain('1 candidate file(s), 3.0 MB in total')
            ->and(Storage::disk('pet_media')->get($row->storage_path))->toBe($original)
            ->and($row->fresh()->compression_path)->toBeNull();
    });

    it('--sample encodes to temp files and reports saving and SSIM per file without replacing anything (also while off)', function () {
        vcNeedsFfmpeg();
        $original = vcClip(4);
        $rows = [vcLookClip(vcLook(), 'video', 'idle', $original), vcLookClip(vcLook(), 'video', 'idle', $original)];

        Artisan::call('media:compress', ['--sample' => 1]);

        expect(Artisan::output())->toContain("would_compress #{$rows[0]->id}")->toContain('SSIM')->toContain('Sample (nothing replaced): 1 file(s)')
            ->not->toContain("#{$rows[1]->id}");
        foreach ($rows as $row) {
            expect(Storage::disk('pet_media')->get($row->storage_path))->toBe($original)
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

    it('is scheduled hourly at night with a lock, and the switch is a superadmin feature switch (audited, default off)', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'media:compress'));
        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('37 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->timezone)->toBe('Europe/Ljubljana');

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
    it('reports usage by kind, orphans, old candidates and leftover temp files — and deletes nothing', function () {
        $look = vcLook();
        $row = vcLookClip($look, 'video', 'idle', str_repeat('a', 2048));
        $row->update(['bytes' => 2048]);
        Storage::disk('pet_media')->put('looks/orphan.mp4', 'zzz');
        Storage::disk('pet_media')->put('looks/x.mp4'.VideoCompressionService::TEMP_SUFFIX, 'tmp');
        $stuck = PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => 'sick', 'life_stage' => 'puppy', 'status' => 'running', 'generation' => 1,
            'candidate_path' => 'looks/sick-cand.mp4', 'candidate_bytes' => 3]);
        Storage::disk('pet_media')->put('looks/sick-cand.mp4', 'ccc');
        PetMedia::whereKey($stuck->id)->update(['updated_at' => now()->subDays(2)]);
        $before = Storage::disk('pet_media')->allFiles();

        Artisan::call('media:disk-report');
        $out = Artisan::output();

        expect($out)->toContain('video')->toContain('Orphans')->toContain('orphan looks/orphan.mp4')
            ->toContain('Leftover compression temp files: 1')
            ->toContain('Clip candidates older than 24 h: 1')->toContain('looks/sick-cand.mp4')
            ->toContain('nothing was deleted')
            ->and(Storage::disk('pet_media')->allFiles())->toBe($before);
    });
});
