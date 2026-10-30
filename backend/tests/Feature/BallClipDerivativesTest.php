<?php

use App\Enums\PetPhaseClip;
use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Filament\Resources\PetResource\RelationManagers\MediaRelationManager;
use App\Jobs\GenerateSchoolClipDerivative;
use App\Models\AiSpendLedger;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetMediaDerivative;
use App\Models\User;
use App\Services\Media\GameMediaReadiness;
use App\Services\Media\PetMediaPayload;
use App\Services\Media\SchoolClipDerivativeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Games phase 1 — free ffmpeg derivatives for the ball (GAMES_SPEC §8.2, §8.4, 2026-10-30)
|--------------------------------------------------------------------------
| `ball_waiting` → `ball_waiting_loop` (ping-pong, full size) and `ball_running`
| → its exit side (left | right | center, no file; a superadmin can override).
| Same table / job / backfill as the school clips; 0 $ (never fal). A missing
| derivative never locks the ball game. ffmpeg tests are skipped without it (CI has it).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    config(['app.url' => 'https://api.petprep.si']);
});

function bcdHasFfmpeg(): bool
{
    static $has = null;

    return $has ??= Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful();
}

/** A tiny test-pattern MP4. */
function bcdPattern(int $width, int $height, float $seconds, int $fps = 8): string
{
    $out = tempnam(sys_get_temp_dir(), 'bcd-').'.mp4';
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "testsrc2=size={$width}x{$height}:rate={$fps}:duration={$seconds}",
        '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $bytes;
}

/**
 * A 3 s "ball_running" clip: a grey room, a white 30 × 40 "dog" that starts in the middle
 * and runs out of the frame ($dx px per second; 0 = stays put), then the empty room.
 */
function bcdRunning(int $dx): string
{
    $out = tempnam(sys_get_temp_dir(), 'bcdrun-').'.mp4';
    $x = $dx === 0 ? '57' : "57+t*{$dx}";
    Process::run(['ffmpeg', '-v', 'error', '-y',
        '-f', 'lavfi', '-i', 'color=c=0x505050:s=144x256:r=12:d=3',
        '-f', 'lavfi', '-i', 'color=c=white:s=30x40:r=12:d=3',
        '-filter_complex', "[0:v][1:v]overlay=x='{$x}':y=110:enable='lt(t,2.75)'",
        '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $bytes;
}

function bcdLook(): PetLook
{
    static $n = 0;
    $n++;

    return PetLook::create(['breed_type' => 'mutt', 'pool_index' => 700 + $n, 'trait_fingerprint' => "bcd-{$n}", 'dna' => ['seed' => $n, 'traits' => []]]);
}

function bcdLookClip(PetLook $look, string $state, ?string $bytes = null, float $seconds = 3): PetMedia
{
    $path = "looks/{$look->id}/{$state}-puppy-g1.mp4";
    Storage::disk('pet_media')->put($path, $bytes ?? "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");

    return PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'phase', 'state' => $state, 'life_stage' => 'puppy', 'status' => 'ready',
        'generation' => 1, 'storage_path' => $path, 'mime' => 'video/mp4', 'bytes' => 1000, 'profile' => 'kling_v3_pro',
        'duration_seconds' => $seconds, 'stored_duration_seconds' => $seconds]);
}

function bcdLink(Pet $pet, PetMedia $row): PetMedia
{
    return PetMedia::create(['pet_id' => $pet->id, 'look_media_id' => $row->id, 'kind' => 'phase', 'state' => $row->state, 'status' => 'ready',
        'generation' => 1, 'storage_path' => $row->storage_path, 'mime' => 'video/mp4', 'bytes' => $row->bytes,
        'duration_seconds' => $row->duration_seconds, 'stored_duration_seconds' => $row->stored_duration_seconds]);
}

function bcdPet(): Pet
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);

    return Pet::factory()->freePlan()->create(['user_id' => $child->id, 'breed_type' => 'mutt', 'media_status' => 'ready']);
}

function bcdProbe(string $path): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'bcdprobe-');
    file_put_contents($tmp, Storage::disk('pet_media')->get($path));
    $probe = app(SchoolClipDerivativeService::class)->probe($tmp);
    @unlink($tmp);

    return (array) $probe;
}

it('names the ball derivative keys; wand clips get none in phase 1', function () {
    expect(SchoolClipDerivativeService::keyFor(PetPhaseClip::BallWaiting))->toBe('ball_waiting_loop')
        ->and(SchoolClipDerivativeService::keyFor(PetPhaseClip::BallRunning))->toBe('ball_running_exit_side')
        ->and(SchoolClipDerivativeService::keyFor(PetPhaseClip::BallFetching))->toBeNull()
        ->and(SchoolClipDerivativeService::keyFor(PetPhaseClip::WandStalking))->toBeNull()
        ->and(SchoolClipDerivativeService::phaseSourceStates())->toContain('ball_waiting', 'ball_running', 'school_sit_attention')
        ->not->toContain('ball_fetching', 'wand_stalking');
});

it('makes ball_waiting_loop as a full-size ping-pong (no 1280 cap), at 0 $', function () {
    if (! bcdHasFfmpeg()) {
        $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
    }
    $look = bcdLook();
    $waiting = bcdLookClip($look, 'ball_waiting', bcdPattern(720, 1440, 1, 8), 1);

    GenerateSchoolClipDerivative::dispatchSync($waiting->id);

    $row = PetMediaDerivative::where('pet_media_id', $waiting->id)->sole();
    expect($row->key)->toBe('ball_waiting_loop')->and($row->status)->toBe('ready')
        ->and($row->storage_path)->toBe("looks/{$look->id}/ball_waiting-puppy-g1-loop.mp4")
        ->and($row->exit_side)->toBeNull();

    // 8 frames → 0 … 7, 6 … 1 = 14 frames, full size (a school clip would be 640 × 1280).
    $probe = bcdProbe($row->storage_path);
    expect($probe['frames'])->toBe(14)->and([$probe['width'], $probe['height']])->toBe([720, 1440])
        ->and($row->duration_seconds)->toEqualWithDelta(14 / 8, 0.01);

    expect(AiSpendLedger::count())->toBe(0);
    Http::assertNothingSent();
});

it('measures the exit side of ball_running with ffmpeg (left / right / center), no file, idempotent', function (int $dx, string $side) {
    if (! bcdHasFfmpeg()) {
        $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
    }
    $running = bcdLookClip(bcdLook(), 'ball_running', bcdRunning($dx));
    $service = app(SchoolClipDerivativeService::class);

    expect($service->make($running->id))->toBe('made');
    $row = PetMediaDerivative::where('pet_media_id', $running->id)->sole();
    expect($row->key)->toBe('ball_running_exit_side')->and($row->exit_side)->toBe($side)
        ->and($row->exit_side_overridden)->toBeFalse()
        ->and($row->storage_path)->toBeNull()
        ->and($row->source_path)->toBe($running->storage_path)
        ->and($service->make($running->id))->toBe('up_to_date')
        ->and($service->dueSources()->pluck('id')->all())->not->toContain($running->id)
        ->and(AiSpendLedger::count())->toBe(0);
})->with([
    'runs out left' => [-35, 'left'],
    'runs out right' => [35, 'right'],
    'stays in the middle' => [0, 'center'],
]);

it('serves ball_waiting_loop and phase_meta.ball_running.exit_side in the payload of a shared-look pet; stale = absent', function () {
    $pet = bcdPet();
    $look = bcdLook();
    $waiting = bcdLookClip($look, 'ball_waiting');
    $running = bcdLookClip($look, 'ball_running');
    bcdLink($pet, $waiting);
    $slot = bcdLink($pet, $running);
    Storage::disk('pet_media')->put("looks/{$look->id}/w-loop.mp4", 'loopbytes');
    PetMediaDerivative::create(['pet_media_id' => $waiting->id, 'key' => 'ball_waiting_loop', 'status' => 'ready', 'source_path' => $waiting->storage_path,
        'storage_path' => "looks/{$look->id}/w-loop.mp4", 'bytes' => 9, 'duration_seconds' => 5.75]);
    PetMediaDerivative::create(['pet_media_id' => $running->id, 'key' => 'ball_running_exit_side', 'status' => 'ready', 'source_path' => $running->storage_path,
        'exit_side' => 'right']);

    $media = PetMediaPayload::for($pet->fresh())->toArray();
    $phases = (array) $media['phases'];
    expect(array_keys($phases))->toEqualCanonicalizing(['ball_waiting', 'ball_running', 'ball_waiting_loop'])
        ->and(((array) $media['phase_seconds'])['ball_waiting_loop'])->toBe(5.75)
        ->and($phases['ball_waiting_loop'])->toContain('/derived/ball_waiting_loop')
        ->and((array) $media['phase_meta'])->toBe(['ball_running' => ['exit_side' => 'right']]);

    $url = parse_url($phases['ball_waiting_loop']);
    get($url['path'].'?'.$url['query'])->assertOk();

    // The exit side has no file: its derived URL is a 404.
    $meta = app(SchoolClipDerivativeService::class)->signedUrl($slot, 'ball_running_exit_side', 'x', now()->toImmutable()->addHour());
    $parts = parse_url($meta);
    get($parts['path'].'?'.$parts['query'])->assertNotFound();

    // New source files → both stale → gone from the payload (the app falls back).
    foreach ([$waiting, $running] as $row) {
        $new = str_replace('-g1', '-g2', (string) $row->storage_path);
        $row->update(['storage_path' => $new]);
        PetMedia::where('look_media_id', $row->id)->update(['storage_path' => $new]);
    }
    $media = PetMediaPayload::for($pet->fresh())->toArray();
    expect(array_keys((array) $media['phases']))->not->toContain('ball_waiting_loop')
        ->and((array) $media['phase_meta'])->toBe([]);
    // Always a JSON object.
    expect(json_encode(['m' => $media['phase_meta']]))->toBe('{"m":{}}');
});

it('never locks the ball game when a derivative is missing (readiness unchanged)', function () {
    $pet = bcdPet();
    $look = bcdLook();
    foreach (['ball_waiting', 'ball_running', 'ball_fetching'] as $state) {
        bcdLink($pet, bcdLookClip($look, $state));
    }

    expect(PetMediaDerivative::count())->toBe(0)
        ->and(app(GameMediaReadiness::class)->requiredClips($pet->fresh(), 'play'))->not->toContain('ball_waiting_loop');
});

it('lets a superadmin override the exit side; kept until the clip changes; »auto« measures again', function () {
    Queue::fake();
    $look = bcdLook();
    $running = bcdLookClip($look, 'ball_running');
    $slot = bcdLink(bcdPet(), $running);
    $service = app(SchoolClipDerivativeService::class);

    // The pet's slot → the look row (shared by the look's pets).
    expect($service->setExitSide($slot, 'left', 1))->toBeTrue();
    $row = PetMediaDerivative::where('pet_media_id', $running->id)->sole();
    expect($row->exit_side)->toBe('left')->and($row->exit_side_overridden)->toBeTrue()
        ->and($service->exitSideOf($slot)?->exit_side)->toBe('left')
        // A measured run for the same file leaves it alone.
        ->and($service->make($running->id))->toBe('up_to_date')
        ->and($service->setExitSide($slot, 'up', 1))->toBeFalse();

    // Not a ball_running clip → refused.
    expect($service->setExitSide(bcdLookClip($look, 'ball_waiting'), 'left', 1))->toBeFalse();

    // »auto«: the row goes, the job is queued to measure it again.
    expect($service->setExitSide($slot, null, 1))->toBeTrue()
        ->and(PetMediaDerivative::where('pet_media_id', $running->id)->exists())->toBeFalse();
    Queue::assertPushed(GenerateSchoolClipDerivative::class, fn ($job) => $job->petMediaId === $running->id);

    // A new clip makes an override stale (measured again for the new file).
    $service->setExitSide($slot, 'right', 1);
    $running->update(['storage_path' => "looks/{$look->id}/ball_running-puppy-g2.mp4"]);
    expect($service->exitSideOf($running->fresh()))->toBeNull()
        ->and($service->dueSources()->pluck('id')->all())->toContain($running->id);
});

it('shows »Ball exit« in the AI media panel and lets a superadmin set it', function () {
    Queue::fake();
    $pet = bcdPet();
    $look = bcdLook();
    $slot = bcdLink($pet, $running = bcdLookClip($look, 'ball_running'));
    $waiting = bcdLink($pet, bcdLookClip($look, 'ball_waiting'));
    $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

    Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
        ->assertTableColumnStateSet('ball_exit', 'not measured', $slot)
        ->assertTableActionVisible('ballExitSide', $slot)
        ->assertTableActionHidden('ballExitSide', $waiting)
        ->callTableAction('ballExitSide', $slot, ['exit_side' => 'right'])
        ->assertHasNoTableActionErrors();

    expect(PetMediaDerivative::where('pet_media_id', $running->id)->sole()->only(['exit_side', 'exit_side_overridden']))
        ->toBe(['exit_side' => 'right', 'exit_side_overridden' => true]);

    Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
        ->assertTableColumnStateSet('ball_exit', 'right (admin)', $slot);
});

it('backfills the ball sources with media:school-derivatives (owners only)', function () {
    Queue::fake();
    $look = bcdLook();
    $waiting = bcdLookClip($look, 'ball_waiting');
    $running = bcdLookClip($look, 'ball_running');
    bcdLookClip($look, 'ball_fetching');
    bcdLink(bcdPet(), $waiting);

    Artisan::call('media:school-derivatives', ['--dry-run' => true]);
    Queue::assertNothingPushed();
    expect(Artisan::output())->toContain('2 source clip(s) due')->toContain('ball_waiting')->toContain('ball_running');

    Artisan::call('media:school-derivatives');
    Queue::assertPushed(GenerateSchoolClipDerivative::class, 2);
    Queue::assertPushed(GenerateSchoolClipDerivative::class, fn ($job) => $job->petMediaId === $running->id);

    // Measured meanwhile → not due any more.
    PetMediaDerivative::create(['pet_media_id' => $running->id, 'key' => 'ball_running_exit_side', 'status' => 'ready', 'source_path' => $running->storage_path, 'exit_side' => 'center']);
    expect(app(SchoolClipDerivativeService::class)->dueSources()->pluck('id')->all())->toBe([$waiting->id]);
});
