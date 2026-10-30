<?php

use App\Enums\PetPhaseClip;
use App\Jobs\GenerateSchoolClipDerivative;
use App\Models\AiSpendLedger;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetMediaDerivative;
use App\Services\Media\GameMediaReadiness;
use App\Services\Media\PetMediaPayload;
use App\Services\Media\SchoolClipDerivativeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Games phase 2 — `wand_crouch_loop` (GAMES_SPEC §5.4 / §8.2, 2026-10-30)
|--------------------------------------------------------------------------
| The ping-pong of the LAST second of `wand_stalking` (the clip ends crouched),
| made by the same free ffmpeg pipeline as `ball_waiting_loop` (0 $, never fal),
| served as `media.phases.wand_crouch_loop`. A missing loop never locks the
| wand game. ffmpeg tests are skipped without it (CI has it).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    config(['app.url' => 'https://api.petprep.si']);
});

function wclHasFfmpeg(): bool
{
    static $has = null;

    return $has ??= Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful();
}

function wclPattern(int $width, int $height, float $seconds, int $fps = 8): string
{
    $out = tempnam(sys_get_temp_dir(), 'wcl-').'.mp4';
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "testsrc2=size={$width}x{$height}:rate={$fps}:duration={$seconds}",
        '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $bytes;
}

function wclLook(): PetLook
{
    static $n = 0;
    $n++;

    return PetLook::create(['breed_type' => 'domestic_cat', 'pool_index' => 800 + $n, 'trait_fingerprint' => "wcl-{$n}", 'dna' => ['seed' => $n, 'traits' => []]]);
}

function wclLookClip(PetLook $look, string $state, ?string $bytes = null, float $seconds = 3): PetMedia
{
    $path = "looks/{$look->id}/{$state}-adult-g1.mp4";
    Storage::disk('pet_media')->put($path, $bytes ?? "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");

    return PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'phase', 'state' => $state, 'life_stage' => 'adult', 'status' => 'ready',
        'generation' => 1, 'storage_path' => $path, 'mime' => 'video/mp4', 'bytes' => 1000, 'profile' => 'kling_v3_pro',
        'duration_seconds' => $seconds, 'stored_duration_seconds' => $seconds]);
}

function wclLink(Pet $pet, PetMedia $row): PetMedia
{
    return PetMedia::create(['pet_id' => $pet->id, 'look_media_id' => $row->id, 'kind' => 'phase', 'state' => $row->state, 'status' => 'ready',
        'generation' => 1, 'storage_path' => $row->storage_path, 'mime' => 'video/mp4', 'bytes' => $row->bytes,
        'duration_seconds' => $row->duration_seconds, 'stored_duration_seconds' => $row->stored_duration_seconds]);
}

function wclCat(): Pet
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);

    return Pet::factory()->create(['user_id' => $child->id, 'breed_type' => 'domestic_cat', 'media_status' => 'ready']);
}

it('names wand_stalking → wand_crouch_loop, keeps the full size and ping-pongs only the tail', function () {
    $slot = new PetMedia(['kind' => 'phase', 'state' => 'wand_stalking']);
    $probe = ['frames' => 24, 'fps' => 8.0, 'duration' => 3.0, 'width' => 720, 'height' => 1440];

    expect(SchoolClipDerivativeService::keyFor(PetPhaseClip::WandStalking))->toBe('wand_crouch_loop')
        ->and(SchoolClipDerivativeService::keepsSourceSize($slot))->toBeTrue()
        ->and(SchoolClipDerivativeService::modeOf($slot))->toBe(SchoolClipDerivativeService::MODE_LOOP)
        // 1 s at 8 fps = the last 8 frames.
        ->and(SchoolClipDerivativeService::tailFramesOf($slot, $probe))->toBe(8)
        ->and(SchoolClipDerivativeService::tailFramesOf(new PetMedia(['kind' => 'phase', 'state' => 'ball_waiting']), $probe))->toBeNull();

    $args = implode(' ', SchoolClipDerivativeService::ffmpegArgs('ffmpeg', 'in.mp4', 'out.mp4', $probe, SchoolClipDerivativeService::MODE_LOOP, homeScreen: true, tailFrames: 8));
    expect($args)->toContain('trim=start_frame=16,setpts=PTS-STARTPTS,split[f][b]')
        ->and($args)->toContain('trim=start_frame=1:end_frame=7')
        ->and($args)->not->toContain('scale=');
});

it('makes wand_crouch_loop from the last second of wand_stalking at full size, at 0 $', function () {
    if (! wclHasFfmpeg()) {
        $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
    }
    $look = wclLook();
    $stalking = wclLookClip($look, 'wand_stalking', wclPattern(720, 1440, 3, 8), 3);

    GenerateSchoolClipDerivative::dispatchSync($stalking->id);

    $row = PetMediaDerivative::where('pet_media_id', $stalking->id)->sole();
    expect($row->key)->toBe('wand_crouch_loop')->and($row->status)->toBe('ready')
        ->and($row->storage_path)->toBe("looks/{$look->id}/wand_stalking-adult-g1-loop.mp4");

    // 24 frames; the last 8 (16 … 23) → 16 … 23, 22 … 17 = 14 frames, full size (no 1280 cap).
    $tmp = tempnam(sys_get_temp_dir(), 'wclprobe-');
    file_put_contents($tmp, Storage::disk('pet_media')->get($row->storage_path));
    $probe = (array) app(SchoolClipDerivativeService::class)->probe($tmp);
    @unlink($tmp);
    expect($probe['frames'])->toBe(14)->and([$probe['width'], $probe['height']])->toBe([720, 1440])
        ->and($row->duration_seconds)->toEqualWithDelta(14 / 8, 0.01)
        ->and(app(SchoolClipDerivativeService::class)->make($stalking->id))->toBe('up_to_date')
        ->and(AiSpendLedger::count())->toBe(0);
    Http::assertNothingSent();
});

it('serves wand_crouch_loop in the cat\'s payload like the ball loop; stale = absent', function () {
    $pet = wclCat();
    $look = wclLook();
    $stalking = wclLookClip($look, 'wand_stalking');
    wclLink($pet, $stalking);
    Storage::disk('pet_media')->put("looks/{$look->id}/c-loop.mp4", 'loopbytes');
    PetMediaDerivative::create(['pet_media_id' => $stalking->id, 'key' => 'wand_crouch_loop', 'status' => 'ready', 'source_path' => $stalking->storage_path,
        'storage_path' => "looks/{$look->id}/c-loop.mp4", 'bytes' => 9, 'duration_seconds' => 1.75]);

    $media = PetMediaPayload::for($pet->fresh())->toArray();
    $phases = (array) $media['phases'];
    expect(array_keys($phases))->toEqualCanonicalizing(['wand_stalking', 'wand_crouch_loop'])
        ->and(((array) $media['phase_seconds'])['wand_crouch_loop'])->toBe(1.75)
        ->and($phases['wand_crouch_loop'])->toContain('/derived/wand_crouch_loop');
    $url = parse_url($phases['wand_crouch_loop']);
    get($url['path'].'?'.$url['query'])->assertOk();

    $new = str_replace('-g1', '-g2', (string) $stalking->storage_path);
    $stalking->update(['storage_path' => $new]);
    PetMedia::where('look_media_id', $stalking->id)->update(['storage_path' => $new]);
    expect(array_keys((array) PetMediaPayload::for($pet->fresh())->toArray()['phases']))->not->toContain('wand_crouch_loop');
});

it('never locks the wand game when the crouch loop is missing (readiness unchanged)', function () {
    $pet = wclCat();
    $look = wclLook();
    foreach (['wand_stalking', 'wand_pouncing', 'wand_catching'] as $state) {
        wclLink($pet, wclLookClip($look, $state));
    }

    expect(PetMediaDerivative::count())->toBe(0)
        ->and(app(GameMediaReadiness::class)->requiredClips($pet->fresh(), GameMediaReadiness::WAND))->not->toContain('wand_crouch_loop')
        ->and(app(SchoolClipDerivativeService::class)->dueSources()->pluck('state')->all())->toBe(['wand_stalking']);
});
