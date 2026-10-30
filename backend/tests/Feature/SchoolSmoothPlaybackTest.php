<?php

use App\Enums\TrainingCommand;
use App\Jobs\GenerateSchoolClipDerivative;
use App\Models\AiSpendLedger;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetMediaDerivative;
use App\Models\PetTrainingSession;
use App\Models\PetTrainingSkill;
use App\Models\User;
use App\Services\Media\Mp4FastStart;
use App\Services\Media\ObjectStorageMover;
use App\Services\Media\PetMediaPayload;
use App\Services\Media\PetMediaService;
use App\Services\Media\SchoolClipDerivativeService;
use App\Services\TrainingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| School smooth playback (David 2026-10-28, docs/engineering/SCHOOL_SMOOTH_PLAYBACK.md)
|--------------------------------------------------------------------------
| §A clips derived with ffmpeg from stored school phase clips (0 $ AI cost):
| attention / success ping-pong loops, release = reversed performing ≤ 2.5 s.
| §B the new schedule: 6 cues, perform_at / release_at, obey_at = perform_at +
| the stored performing length, learning pace × 8 / 6.
| ffmpeg tests run real ffmpeg on tiny generated clips (skipped without it — CI has it).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    config(['app.url' => 'https://api.petprep.si']);
});

function sspAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/** A born dog with a profile and training on (like TrainingTest::trFamily). @return array{0: User, 1: User, 2: Pet} */
function sspFamily(): array
{
    sspAt('2026-10-07 05:00:00');
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = Pet::factory()->mutt()->create([
        'user_id' => $child->id, 'born_at' => now(), 'last_decay_at' => now(), 'last_step_reset_at' => now(),
        'arrival_age_months' => 2, 'training_enabled' => true,
    ]);
    disableHygieneEvents($pet);

    return [$parent, $child, $pet->fresh()];
}

function sspStart(User $child, string $command = 'sit'): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/start', ['command' => $command]);
}

/** @param list<int> $taps */
function sspFinish(User $child, string $id, array $taps): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/finish', ['session_id' => $id, 'taps' => $taps]);
}

/** @return list<int> taps 400+ ms after every obey instant */
function sspPerfectTaps(array $session): array
{
    $taps = [];
    foreach ($session['trials'] as $t) {
        if ($t['obeys']) {
            $taps[] = $t['obey_at_ms'] + 400 + 30 * count($taps);
        }
    }

    return $taps;
}

function sspHasFfmpeg(): bool
{
    static $has = null;

    return $has ??= Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful();
}

/** A tiny moving MP4 (test pattern) made with ffmpeg. */
function sspClip(int $width = 144, int $height = 256, float $seconds = 2, int $fps = 12): string
{
    static $cache = [];
    $key = "{$width}x{$height}-{$seconds}-{$fps}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $out = tempnam(sys_get_temp_dir(), 'sspclip-').'.mp4';
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "testsrc2=size={$width}x{$height}:rate={$fps}:duration={$seconds}",
        '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $cache[$key] = $bytes;
}

/** ffprobe of a file on the media disk. */
function sspProbe(string $path, string $disk = 'pet_media'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'sspprobe-');
    file_put_contents($tmp, Storage::disk($disk)->get($path));
    $probe = app(SchoolClipDerivativeService::class)->probe($tmp);
    $layout = app(Mp4FastStart::class)->fileLayout($tmp);
    @unlink($tmp);

    return [...(array) $probe, 'moov_last' => $layout];
}

function sspLook(): PetLook
{
    static $n = 0;
    $n++;

    return PetLook::create(['breed_type' => 'mutt', 'pool_index' => 900 + $n, 'trait_fingerprint' => "ssp-{$n}", 'dna' => ['seed' => $n, 'traits' => []]]);
}

/** A stored school phase clip of a shared look (the row owning the file). */
function sspLookClip(PetLook $look, string $state, ?string $bytes = null, float $seconds = 3, string $disk = 'pet_media'): PetMedia
{
    $path = "looks/{$look->id}/{$state}-puppy-g1.mp4";
    Storage::disk($disk)->put($path, $bytes ?? "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");

    return PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'phase', 'state' => $state, 'life_stage' => 'puppy', 'status' => 'ready',
        'generation' => 1, 'storage_path' => $path, 'mime' => 'video/mp4', 'bytes' => 1000, 'profile' => 'kling_v3_pro',
        'duration_seconds' => $seconds, 'stored_duration_seconds' => $seconds]);
}

/** A pet slot pointing at a look row (a pool / shared-look pet). */
function sspLink(Pet $pet, PetMedia $row): PetMedia
{
    return PetMedia::create(['pet_id' => $pet->id, 'look_media_id' => $row->id, 'kind' => 'phase', 'state' => $row->state, 'status' => 'ready',
        'generation' => 1, 'storage_path' => $row->storage_path, 'mime' => 'video/mp4', 'bytes' => $row->bytes,
        'duration_seconds' => $row->duration_seconds, 'stored_duration_seconds' => $row->stored_duration_seconds]);
}

function sspPet(): Pet
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);

    return Pet::factory()->freePlan()->create(['user_id' => $child->id, 'breed_type' => 'mutt', 'media_status' => 'ready']);
}

function sspDerived(int $sourceId, string $key, float $seconds, string $path = 'x'): PetMediaDerivative
{
    $source = PetMedia::findOrFail($sourceId);

    return PetMediaDerivative::create(['pet_media_id' => $sourceId, 'key' => $key, 'status' => 'ready', 'source_path' => $source->storage_path,
        'storage_path' => $path, 'bytes' => 10, 'duration_seconds' => $seconds]);
}

// ──────────────────────────────────────────────────────────────
//  §B schedule
// ──────────────────────────────────────────────────────────────

describe('schedule', function () {
    it('has 6 trials with perform_at ≤ obey_at = perform_at + P, window and release, ignores all null, slots chained', function () {
        $service = app(TrainingService::class);
        $rng = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'ssp-shape', true)));

        for ($run = 0; $run < 200; $run++) {
            $s = $service->schedule($run % 2 === 0 ? 0.0 : 100.0, $rng, 5000, 2200);
            expect($s['trials'])->toHaveCount(6)
                ->and(array_column($s['trials'], 'obeys'))->toContain(true, false) // the guarantees
                ->and($s['praise_window_ms'])->toBe(1500)
                ->and($s['min_reaction_ms'])->toBe(150);

            $next = 2000;
            foreach ($s['trials'] as $t) {
                expect($t['cue_at_ms'])->toBe($next);
                if ($t['obeys']) {
                    expect($t['perform_at_ms'])->toBeGreaterThanOrEqual($t['cue_at_ms'] + 300)->toBeLessThanOrEqual($t['cue_at_ms'] + 1500)
                        ->and($t['obey_at_ms'])->toBe($t['perform_at_ms'] + 5000)
                        ->and($t['window_end_ms'])->toBe($t['obey_at_ms'] + 1500)
                        ->and($t['release_at_ms'])->toBe($t['window_end_ms']);
                    $next = $t['release_at_ms'] + 2200 + 700;
                } else {
                    expect([$t['perform_at_ms'], $t['obey_at_ms'], $t['window_end_ms'], $t['release_at_ms']])->toBe([null, null, null, null]);
                    $next = $t['cue_at_ms'] + 4500;
                }
            }
            expect($s['duration_ms'])->toBe($next)
                ->and($s['duration_ms'])->toBeLessThanOrEqual(TrainingService::maxSessionMs(5000, 2200));
        }

        // Six commands of a 5 s clip ≈ 60 s (David 2026-10-28); the reserve is the all-obey worst case.
        expect(TrainingService::maxSessionMs(5000, 2500))->toBe(2000 + 6 * (1500 + 5000 + 1500 + 2500 + 700))
            ->and(TrainingService::maxSessionMs())->toBe(57200);
    });

    it('clamps broken stored lengths (never an endless or instant trial)', function () {
        $rng = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'ssp-clamp', true)));
        $s = app(TrainingService::class)->schedule(100.0, $rng, 60_000, 99_000);
        $t = collect($s['trials'])->firstWhere('obeys', true);

        expect($t['obey_at_ms'] - $t['perform_at_ms'])->toBe(TrainingService::MAX_PERFORMING_MS);
    });

    it('times the session from the pet\'s stored performing clip and its release clip', function () {
        seedLifeStageData();
        [, $child, $pet] = sspFamily();
        $look = sspLook();
        $performing = sspLookClip($look, 'school_place_performing', seconds: 5.04);
        $slot = sspLink($pet, $performing);
        sspDerived($performing->id, 'school_place_release', 2.4);

        expect(app(TrainingService::class)->clipTimings($pet)['place'])->toBe(['performing_ms' => 5040, 'release_ms' => 2400])
            ->and(app(TrainingService::class)->clipTimings($pet)['sit'])->toBe(['performing_ms' => 3000, 'release_ms' => 2500]);

        sspAt('2026-10-08 08:00:00');
        $s = sspStart($child, 'place')->assertOk()
            // The budget reserve follows the longest command: 2 + 6 × (1.5 + 5.04 + 1.5 + 2.4 + 0.7) s.
            ->assertJsonPath('state.training.session_seconds', 69)
            ->json('session');
        foreach ($s['trials'] as $i => $t) {
            if ($t['obeys']) {
                expect($t['obey_at_ms'])->toBe($t['perform_at_ms'] + 5040);
                if (isset($s['trials'][$i + 1])) {
                    expect($s['trials'][$i + 1]['cue_at_ms'])->toBe($t['release_at_ms'] + 2400 + 700);
                }
            }
        }

        // A flagged clip is not shown → the defaults.
        $slot->update(['quality' => PetMedia::QUALITY_FLAGGED]);
        expect(app(TrainingService::class)->clipTimings($pet->fresh())['place'])->toBe(['performing_ms' => 3000, 'release_ms' => 2500]);
    });

    it('scores the new trials with the unchanged rules (first tap decides, reaction floor, window edges)', function () {
        $trials = [
            ['index' => 0, 'cue_at_ms' => 2000, 'obeys' => true, 'perform_at_ms' => 2500, 'obey_at_ms' => 7500, 'window_end_ms' => 9000, 'release_at_ms' => 9000],
            ['index' => 1, 'cue_at_ms' => 12200, 'obeys' => false, 'perform_at_ms' => null, 'obey_at_ms' => null, 'window_end_ms' => null, 'release_at_ms' => null],
            ['index' => 2, 'cue_at_ms' => 16700, 'obeys' => true, 'perform_at_ms' => 17000, 'obey_at_ms' => 22000, 'window_end_ms' => 23500, 'release_at_ms' => 23500],
            ['index' => 3, 'cue_at_ms' => 26700, 'obeys' => true, 'perform_at_ms' => 27000, 'obey_at_ms' => 32000, 'window_end_ms' => 33500, 'release_at_ms' => 33500],
        ];
        // 0: tap while the dog is still performing → too early; 1: waited; 2: last ms → in time; 3: tap during the release → too late.
        $score = TrainingService::score($trials, 1500, [5000, 7700, 23500, 34000]);

        expect(array_column($score['trials'], 'outcome'))->toBe(['too_early', 'waited', 'in_time', 'too_late'])
            ->and($score['successes'])->toBe(1)->and($score['obeyed'])->toBe(3);
    });

    it('keeps the learning pace: progress per on-time praise × 8 / 6 (an old 8-cue schedule × 1)', function () {
        expect(TrainingService::paceFactor(['trials' => array_fill(0, 6, [])]))->toEqualWithDelta(8 / 6, 1e-12)
            ->and(TrainingService::paceFactor(['trials' => array_fill(0, 8, [])]))->toBe(1.0)
            // A full session where every cue is praised in time moves a skill as much as 8 used to.
            ->and(6 * TrainingService::paceFactor(['trials' => array_fill(0, 6, [])]))->toEqualWithDelta(8.0, 1e-12);
    });

    it('resumes and scores a session started with the old 8-cue shape (no perform_at / release_at, pace × 1)', function () {
        seedLifeStageData();
        [, $child, $pet] = sspFamily();
        Pet::whereKey($pet->id)->update(['training_learning_factor' => 1.0]);
        sspAt('2026-10-08 08:00:00');
        $s = sspStart($child)->assertOk()->json('session');

        $old = [];
        for ($i = 0; $i < 8; $i++) {
            $cue = 2000 + 4000 * $i; // fits inside the stored duration
            $old[] = ['index' => $i, 'cue_at_ms' => $cue, 'obeys' => $i < 4, 'obey_at_ms' => $i < 4 ? $cue + 1000 : null, 'window_end_ms' => $i < 4 ? $cue + 2500 : null];
        }
        PetTrainingSession::where('public_id', $s['id'])->update(['schedule' => json_encode(['praise_window_ms' => 1500, 'min_reaction_ms' => 150, 'trials' => $old])]);

        sspAt('2026-10-08 08:00:10');
        app('auth')->forgetGuards();
        actingAsRole($child);
        $trials = getJson('/api/child/pet')->assertOk()->json('training.session.trials');
        expect($trials)->toHaveCount(8)
            ->and(array_keys($trials[0]))->toBe(['index', 'cue_at_ms', 'obeys', 'obey_at_ms', 'window_end_ms']);

        Carbon::setTestNow(Carbon::parse($s['ends_at'])->utc());
        $res = sspFinish($child, $s['id'], [3300, 7400, 11500, 15600])->assertOk()->assertJsonPath('result.successes', 4);
        expect((float) PetTrainingSkill::where('pet_id', $pet->id)->where('command', TrainingCommand::Sit->value)->value('progress'))->toEqualWithDelta(4.0, 1e-9);
        expect($res->json('result.progress_gain'))->toEqual(4);
    });

    it('accepts a finish up to 12 s after the scheduled end (the app waits for its players before its clock starts)', function () {
        seedLifeStageData();
        [, $child] = sspFamily();
        sspAt('2026-10-08 08:00:00');
        $s = sspStart($child)->assertOk()->json('session');

        Carbon::setTestNow(Carbon::parse($s['ends_at'])->utc()->addSeconds(12));
        sspFinish($child, $s['id'], sspPerfectTaps($s))->assertOk()->assertJsonPath('status', 'accepted');
        expect(TrainingService::FINISH_GRACE_SECONDS)->toBeGreaterThanOrEqual(12);
    });
});

// ──────────────────────────────────────────────────────────────
//  §A derived clips (ffmpeg, 0 $)
// ──────────────────────────────────────────────────────────────

describe('derived clips', function () {
    it('names the derived key of every school phase clip', function () {
        expect(SchoolClipDerivativeService::keys())->toContain('school_sit_attention_loop', 'school_sit_success_loop', 'school_sit_release', 'school_potty_release')
            ->toHaveCount(12)
            ->and(SchoolClipDerivativeService::derivedPath('looks/4/school_sit_attention-puppy-g2.mp4', 'loop'))->toBe('looks/4/school_sit_attention-puppy-g2-loop.mp4')
            ->and(SchoolClipDerivativeService::scaleFilter(1080, 1920))->toBe('scale=-2:1280,')
            ->and(SchoolClipDerivativeService::scaleFilter(1920, 1080))->toBe('scale=1280:-2,')
            ->and(SchoolClipDerivativeService::scaleFilter(720, 1280))->toBe('');
    });

    it('makes a seamless ping-pong loop of attention and success and a reversed release ≤ 2.5 s, with ffmpeg, at 0 $', function () {
        if (! sspHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this (scripts/ci-install-ffmpeg.sh).');
        }
        $look = sspLook();
        $attention = sspLookClip($look, 'school_sit_attention', sspClip(144, 256, 2, 12), 2);
        $performing = sspLookClip($look, 'school_sit_performing', sspClip(144, 256, 5, 12), 5);

        GenerateSchoolClipDerivative::dispatchSync($attention->id);
        GenerateSchoolClipDerivative::dispatchSync($performing->id);

        $loop = PetMediaDerivative::where('pet_media_id', $attention->id)->sole();
        $release = PetMediaDerivative::where('pet_media_id', $performing->id)->sole();
        expect($loop->key)->toBe('school_sit_attention_loop')->and($loop->status)->toBe('ready')
            ->and($loop->source_path)->toBe($attention->storage_path)
            ->and($loop->storage_path)->toBe("looks/{$look->id}/school_sit_attention-puppy-g1-loop.mp4")
            ->and($release->key)->toBe('school_sit_release')
            ->and($release->duration_seconds)->toBeLessThanOrEqual(2.5)->toBeGreaterThan(2.0);

        // 24 frames → 0 … 23, 22 … 1 = 46 frames at the source's 12 fps; same size; moov first.
        $probe = sspProbe($loop->storage_path);
        expect($probe['frames'])->toBe(46)->and($probe['fps'])->toEqual(12.0)
            ->and([$probe['width'], $probe['height']])->toBe([144, 256])
            ->and($probe['moov_last'])->toBeFalse()
            ->and($loop->duration_seconds)->toEqualWithDelta(46 / 12, 0.01);

        // Nothing went through fal / the AI ledger.
        expect(AiSpendLedger::count())->toBe(0);
        Http::assertNothingSent();

        // Idempotent: a second run leaves the clip alone.
        $made = $loop->updated_at;
        expect(app(SchoolClipDerivativeService::class)->make($attention->id))->toBe('up_to_date')
            ->and($loop->fresh()->updated_at->equalTo($made))->toBeTrue();
    });

    it('caps the long side at 1280 px', function () {
        if (! sspHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
        }
        $look = sspLook();
        $success = sspLookClip($look, 'school_place_success', sspClip(720, 1440, 1, 8), 1);
        GenerateSchoolClipDerivative::dispatchSync($success->id);

        $probe = sspProbe(PetMediaDerivative::where('pet_media_id', $success->id)->sole()->storage_path);
        expect([$probe['width'], $probe['height']])->toBe([640, 1280]);
    });

    it('works on the object-storage disk (temp copy in, result written through the disk)', function () {
        if (! sspHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
        }
        Storage::fake('pet_media_s3');
        config(['media.storage.disk' => 'pet_media_s3']);
        $look = sspLook();
        $performing = sspLookClip($look, 'school_come_performing', sspClip(144, 256, 3, 12), 3, 'pet_media_s3');

        expect(app(SchoolClipDerivativeService::class)->make($performing->id))->toBe('made');
        $row = PetMediaDerivative::where('pet_media_id', $performing->id)->sole();
        Storage::disk('pet_media_s3')->assertExists($row->storage_path);
        Storage::disk('pet_media')->assertMissing($row->storage_path);
    });

    it('re-makes a derived clip when its source file changes and deletes the old one', function () {
        if (! sspHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
        }
        $look = sspLook();
        $attention = sspLookClip($look, 'school_sit_attention', sspClip(144, 256, 2, 12), 2);
        app(SchoolClipDerivativeService::class)->make($attention->id);
        $old = PetMediaDerivative::where('pet_media_id', $attention->id)->sole()->storage_path;

        $new = "looks/{$look->id}/school_sit_attention-puppy-g2.mp4";
        Storage::disk('pet_media')->put($new, sspClip(144, 256, 1, 12));
        $attention->update(['storage_path' => $new, 'generation' => 2]);

        expect(app(SchoolClipDerivativeService::class)->dueSources()->pluck('id')->all())->toBe([$attention->id])
            ->and(app(SchoolClipDerivativeService::class)->make($attention->id))->toBe('made');
        $row = PetMediaDerivative::where('pet_media_id', $attention->id)->sole();
        expect($row->source_path)->toBe($new)->and($row->storage_path)->toBe("looks/{$look->id}/school_sit_attention-puppy-g2-loop.mp4");
        Storage::disk('pet_media')->assertMissing($old);
    });

    it('records a failure per source file and stops retrying after 3 attempts', function () {
        if (! sspHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
        }
        $look = sspLook();
        $broken = sspLookClip($look, 'school_sit_success', 'not a video at all');
        $service = app(SchoolClipDerivativeService::class);

        for ($i = 0; $i < 3; $i++) {
            expect($service->make($broken->id))->toBe('failed');
        }
        expect(PetMediaDerivative::where('pet_media_id', $broken->id)->sole()->attempts)->toBe(3)
            ->and($service->dueSources()->count())->toBe(0);
    });

    it('queues the derived clip when a school phase clip is stored (owners only: a look row or a unique pet row)', function () {
        Queue::fake();
        $look = sspLook();
        $row = sspLookClip($look, 'school_sit_performing');
        $row->update(['status' => 'running', 'candidate_path' => $row->storage_path, 'candidate_bytes' => 1000, 'candidate_mime' => 'video/mp4', 'storage_path' => null]);

        expect(app(PetMediaService::class)->promoteCandidate($row->fresh(), (string) $row->candidate_path, PetMedia::QUALITY_PASSED, null))->toBeTrue();
        Queue::assertPushed(GenerateSchoolClipDerivative::class, fn ($job) => $job->petMediaId === $row->id);
    });
});

// ──────────────────────────────────────────────────────────────
//  Payload, serving, backfill
// ──────────────────────────────────────────────────────────────

describe('payload and backfill', function () {
    it('lists derived clips in media.phases (signed URL) and phase_seconds (real length) for a shared-look pet; stale = absent', function () {
        $pet = sspPet();
        $look = sspLook();
        $attention = sspLookClip($look, 'school_sit_attention', seconds: 3);
        $performing = sspLookClip($look, 'school_sit_performing', seconds: 5);
        sspLink($pet, $attention);
        sspLink($pet, $performing);
        Storage::disk('pet_media')->put("looks/{$look->id}/a-loop.mp4", 'loopbytes');
        sspDerived($attention->id, 'school_sit_attention_loop', 5.833, "looks/{$look->id}/a-loop.mp4");
        sspDerived($performing->id, 'school_sit_release', 2.458);

        $media = PetMediaPayload::for($pet->fresh())->toArray();
        $phases = (array) $media['phases'];
        $seconds = (array) $media['phase_seconds'];
        expect(array_keys($phases))->toEqualCanonicalizing(['school_sit_attention', 'school_sit_performing', 'school_sit_attention_loop', 'school_sit_release'])
            ->and($seconds['school_sit_attention_loop'])->toBe(5.833)
            ->and($seconds['school_sit_release'])->toBe(2.458)
            ->and($seconds['school_sit_performing'])->toEqual(5.0)
            ->and($phases['school_sit_attention_loop'])->toStartWith('https://api.petprep.si/api/media/')
            ->and($phases['school_sit_attention_loop'])->toContain('/derived/school_sit_attention_loop');

        // The signed URL serves the derived file (no token: the URL is the capability).
        $url = parse_url($phases['school_sit_attention_loop']);
        get($url['path'].'?'.$url['query'])->assertOk();

        // The look row got a new file: the derived clip is stale → not listed, not served.
        $attention->update(['storage_path' => "looks/{$look->id}/school_sit_attention-puppy-g2.mp4"]);
        PetMedia::where('look_media_id', $attention->id)->update(['storage_path' => "looks/{$look->id}/school_sit_attention-puppy-g2.mp4"]);
        expect(array_keys((array) PetMediaPayload::for($pet->fresh())->toArray()['phases']))->not->toContain('school_sit_attention_loop');
        get($url['path'].'?'.$url['query'])->assertNotFound();
    });

    it('refuses a derived clip for a token that may not watch the pet', function () {
        $pet = sspPet();
        $look = sspLook();
        $attention = sspLookClip($look, 'school_sit_attention');
        $slot = sspLink($pet, $attention);
        Storage::disk('pet_media')->put('looks/x-loop.mp4', 'b');
        sspDerived($attention->id, 'school_sit_attention_loop', 4.0, 'looks/x-loop.mp4');

        $url = app(SchoolClipDerivativeService::class)->signedUrl($slot, 'school_sit_attention_loop', 'looks/x-loop.mp4', now()->toImmutable()->addHour());
        $stranger = User::factory()->parent()->create();
        actingAsRole($stranger);
        $parts = parse_url($url);
        getJson($parts['path'].'?'.$parts['query'])->assertForbidden();
    });

    it('backfills in small batches, idempotently, and logs counts (media:school-derivatives)', function () {
        Queue::fake();
        $look = sspLook();
        $rows = collect(['school_sit_attention', 'school_sit_performing', 'school_sit_success', 'school_come_attention'])
            ->map(fn (string $state) => sspLookClip($look, $state));
        // A wand clip and a pet row pointing at a look are never sources (ball clips are since
        // games phase 1 — BallClipDerivativesTest).
        sspLookClip($look, 'wand_stalking');
        sspLink(sspPet(), $rows[0]);

        Artisan::call('media:school-derivatives', ['--limit' => 3, '--dry-run' => true]);
        Queue::assertNothingPushed();
        expect(Artisan::output())->toContain('4 source clip(s) due');

        Artisan::call('media:school-derivatives', ['--limit' => 3]);
        Queue::assertPushed(GenerateSchoolClipDerivative::class, 3);

        // Made meanwhile (ready, current) → not due any more; the 4th is still due.
        foreach ($rows->take(3) as $row) {
            sspDerived($row->id, (string) SchoolClipDerivativeService::keyFor($row->phaseClip()), 3.0);
        }
        expect(app(SchoolClipDerivativeService::class)->dueSources()->pluck('id')->all())->toBe([$rows[3]->id])
            ->and(app(SchoolClipDerivativeService::class)->dueSources(lookId: $look->id + 1000)->count())->toBe(0);

        // --pet: the clips the pet shows through its look.
        $pet = sspPet();
        sspLink($pet, $rows[3]);
        expect(app(SchoolClipDerivativeService::class)->dueSources(petId: $pet->id)->pluck('id')->all())->toBe([$rows[3]->id]);
        expect(AiSpendLedger::count())->toBe(0);
    });

    it('lets media:move-to-object-storage copy derived clips (referenced paths)', function () {
        $look = sspLook();
        $row = sspLookClip($look, 'school_sit_attention');
        sspDerived($row->id, 'school_sit_attention_loop', 4.0, "looks/{$look->id}/school_sit_attention-puppy-g1-loop.mp4");

        expect(app(ObjectStorageMover::class)->referencedPaths())->toContain("looks/{$look->id}/school_sit_attention-puppy-g1-loop.mp4");
    });

    it('is scheduled every 10 minutes', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'media:school-derivatives'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('3-59/10 * * * *');
    });
});
