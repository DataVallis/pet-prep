<?php

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPhaseClip;
use App\Enums\PetPlan;
use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
use App\Filament\Pages\FeatureSwitches;
use App\Jobs\SendFrugalClipFallbackAlert;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AiSpendLedger;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaDerivative;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\FrugalClipPreview;
use App\Services\Media\GameMediaReadiness;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetMediaService;
use App\Services\Media\PetPreparationService;
use App\Services\Media\ReferenceImageRetryService;
use App\Services\Media\SchoolClipDerivativeService;
use App\Services\Media\VideoCompressionService;
use App\Services\PairingService;
use App\Services\Results\PetProfileChoice;
use App\Services\TrainingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Frugal clips (David 2026-10-30) — three AI-cost savings behind `frugal_clips`
|--------------------------------------------------------------------------
| A. one school attention clip (sit) serves come / place / potty;
| (B. sleeping_to_idle = idle_to_sleeping reversed — DROPPED 2026-10-30, David: »looks like
|     rewinding a tape«; made by fal again, media:frugal-unreverse repairs the legacy ones;)
| C. low-motion loops 3 s, served as a seamless ping-pong.
| Default OFF = byte-identical generation; stored media keep being served as before.
| ffmpeg tests run real ffmpeg on tiny generated clips (skipped without it — CI has it).
*/

const FRG_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

const FRG_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

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

function frgSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::FRUGAL_CLIPS_KEY], ['value' => ['enabled' => $on]]);
    Cache::forget(AppSettingsService::cacheKey(AppSettingsService::FRUGAL_CLIPS_KEY));
    // The fresh read is memoised per job / request (scoped service, QA #451 m5).
    app()->forgetScopedInstances();
}

function frgFakeFal(): void
{
    fakeFalJwks();
    $n = 0;
    Http::fake([
        'queue.fal.run/fal-ai/nano-banana-pro/edit*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "fr-edit-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/fr-edit-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/fr-edit-{$n}"]);
        },
        'fal.run/fal-ai/nano-banana-pro' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/fr/ref-{$n}.jpg"]]]);
        },
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "fr-req-{$n}"]);
        },
        'v3.fal.media/files/fr/*.jpg' => fn () => Http::response(FRG_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/fr/*.mp4' => fn () => Http::response(FRG_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

function frgCompleteClips(): void
{
    for ($round = 0; $round < 10; $round++) {
        $keys = completeFalKeyImages('https://v3.fal.media/files/fr/');
        $rows = PetMedia::query()->whereIn('kind', ['video', 'transition', 'phase'])
            ->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();
        if ($rows->isEmpty() && $keys === 0) {
            return;
        }
        foreach ($rows as $row) {
            sendFalWebhook(['request_id' => $row->request_id, 'status' => 'OK',
                'payload' => ['video' => ['url' => "https://v3.fal.media/files/fr/{$row->request_id}.mp4"]]])->assertOk();
        }
    }
}

/** @return Collection<int, Request> the Kling submits, in order */
function frgKlingSubmits(): Collection
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/fal-ai/kling-video/'))
        ->map(fn (array $pair) => $pair[0])->values();
}

/** What a Kling submit made: loop:<state-ish> / transition / phase, by its prompt. */
function frgKind(Request $r): string
{
    $p = (string) $r['prompt'];

    return match (true) {
        str_contains($p, 'No toys, no ball, no feather') => 'phase',
        str_contains($p, 'pose of the end image') => 'transition',
        default => 'loop',
    };
}

/** A new paid, profiled dog with training (state media version 3), like PhaseClipsTest::phcPet. */
function frgPet(string $breed = 'labrador_retriever', ?string $colour = 'black'): Pet
{
    $parent = createParentUser();
    $family = app(FamilyService::class)->ensureFamilyFor($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $type = BreedType::from($breed);
    $profile = new PetProfileChoice($type, PetOrigin::Bought, LifeStage::Young, ['training'], $type->species(), $colour);

    $pet = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, PetPlan::Challenge)['pet'])->fresh();
    $pet->forceFill(['plan' => PetPlan::Challenge, 'challenge_paid_at' => now(), 'challenge_paid_source' => ChallengePaidSource::Purchase])->saveQuietly();

    return $pet->fresh();
}

function frgBirth(Pet $pet): Pet
{
    $pet->giveBirth(now());
    $pet->save();
    app(PetMediaService::class)->queueStateVideos($pet->fresh());

    return $pet->fresh();
}

function frgSlot(Pet $pet, string $kind, string $state): ?PetMedia
{
    return PetMedia::where('pet_id', $pet->id)->where('kind', $kind)->where('state', $state)->first();
}

function frgHasFfmpeg(): bool
{
    static $has = null;

    return $has ??= Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful();
}

/** A tiny moving MP4 (test pattern). */
function frgClip(int $width, int $height, float $seconds, int $fps = 12): string
{
    static $cache = [];
    $key = "{$width}x{$height}-{$seconds}-{$fps}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $out = tempnam(sys_get_temp_dir(), 'frgclip-').'.mp4';
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "testsrc2=size={$width}x{$height}:rate={$fps}:duration={$seconds}",
        '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $cache[$key] = $bytes;
}

/** @return array{frames: int, fps: float, duration: float, width: int, height: int} */
function frgProbe(string $path): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'frgprobe-');
    file_put_contents($tmp, Storage::disk('pet_media')->get($path));
    $probe = app(SchoolClipDerivativeService::class)->probe($tmp);
    @unlink($tmp);

    return (array) $probe;
}

/** A stored clip row of a pet (own media) with a real file. */
function frgStored(Pet $pet, string $kind, string $state, string $bytes, float $seconds, ?string $storedRecipe = null): PetMedia
{
    $path = "{$pet->id}/{$state}-g1.mp4";
    Storage::disk('pet_media')->put($path, $bytes);
    $slot = app(PetMediaService::class)->slot($pet, $kind, match ($kind) {
        'transition' => PetTransition::from($state),
        'phase' => PetPhaseClip::from($state),
        default => PetStateEnum::from($state),
    });
    $slot->update(['status' => 'ready', 'storage_path' => $path, 'mime' => 'video/mp4', 'bytes' => strlen($bytes), 'generation' => 1,
        'duration_seconds' => $seconds, 'stored_duration_seconds' => $seconds, 'stored_recipe' => $storedRecipe, 'recipe' => $storedRecipe]);

    return $slot->fresh();
}

/* ─────────────────────────── Switch ─────────────────────────── */

describe('superadmin switch', function () {
    it('is off by default; saving it on in Feature switches is audited', function () {
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);

        expect(app(AppSettingsService::class)->frugalClips())->toBeFalse()
            ->and(app(MediaEntitlementService::class)->frugalClipsEnabled(fresh: true))->toBeFalse();

        Livewire\Livewire::test(FeatureSwitches::class)->assertFormSet(['frugal_clips' => false])->call('save')->assertHasNoFormErrors();
        expect(AppSetting::find(AppSettingsService::FRUGAL_CLIPS_KEY))->toBeNull();

        Livewire\Livewire::test(FeatureSwitches::class)->fillForm(['frugal_clips' => true])->call('save')->assertHasNoFormErrors()->assertNotified();
        expect(app(AppSettingsService::class)->frugalClips(fresh: true))->toBeTrue()
            ->and(AppSettingChange::where('key', AppSettingsService::FRUGAL_CLIPS_KEY)->sole()->user_id)->toBe($admin->id);
    });
});

/* ─────────────────────────── Generation ─────────────────────────── */

describe('generation (own media)', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('switch OFF: exactly today\'s submits — 6 loops of 5 s, both transitions, 15 phase clips (12.07 $)', function () {
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();

        $submits = frgKlingSubmits();
        $byKind = $submits->groupBy(fn (Request $r) => frgKind($r));
        expect($byKind->map->count()->sortKeys()->all())->toBe(['loop' => 6, 'phase' => 15, 'transition' => 2])
            ->and($byKind['loop']->pluck('duration')->unique()->values()->all())->toBe(['5'])
            ->and($byKind['transition']->pluck('duration')->all())->toBe(['5', '5'])
            ->and($byKind['phase']->pluck('duration')->countBy()->sortKeys()->all())->toBe(['3' => 11, '5' => 4])
            ->and(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe(12.07)
            ->and(PetMedia::whereNotNull('frugal_source')->count())->toBe(0)
            ->and(PetMedia::where('pet_id', $pet->id)->where('kind', 'phase')->where('status', 'ready')->count())->toBe(15)
            ->and(frgSlot($pet, 'transition', 'sleeping_to_idle')->status)->toBe('ready');

        // Nothing derived beyond the school clips (own sleeping_to_idle, 5 s loops).
        expect(app(SchoolClipDerivativeService::class)->dueSources($pet->id)->whereIn('kind', ['video', 'transition'])->count())->toBe(0);
    });

    it('switch ON: only sit attention, sleeping_to_idle made by fal (no reversal), low-motion loops 3 s — 1.68 $ less (10.39 $)', function () {
        frgSwitch(true);
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();

        $submits = frgKlingSubmits();
        $byKind = $submits->groupBy(fn (Request $r) => frgKind($r));
        $attention = $byKind['phase']->filter(fn (Request $r) => str_contains((string) $r['prompt'], 'looks up at the camera eagerly'));
        expect($byKind->map->count()->sortKeys()->all())->toBe(['loop' => 6, 'phase' => 12, 'transition' => 2])
            ->and($attention)->toHaveCount(1)
            ->and($byKind['transition']->pluck('duration')->all())->toBe(['5', '5'])
            ->and($byKind['loop']->pluck('duration')->countBy()->sortKeys()->all())->toBe(['3' => 3, '5' => 3])
            ->and(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe(10.39)
            ->and(round(12.07 - 10.39, 2))->toBe(1.68);

        // The low-motion loops: 3 s at fal and in the slot (the quality check expects 3 s).
        foreach (['sleeping', 'low_energy', 'sick'] as $state) {
            $slot = frgSlot($pet, 'video', $state);
            expect((float) $slot->duration_seconds)->toBe(3.0)->and((float) $slot->stored_duration_seconds)->toBe(3.0)
                ->and(SchoolClipDerivativeService::isShortLoop($slot))->toBeTrue();
        }
        expect((float) frgSlot($pet, 'video', 'hungry')->stored_duration_seconds)->toBe(5.0);

        // Marked, never submitted: only the 3 attention targets. sleeping_to_idle is the pet's own
        // fal clip — never reversed, nothing derived from idle_to_sleeping (David 2026-10-30).
        expect(PetMedia::where('pet_id', $pet->id)->whereNotNull('frugal_source')->pluck('frugal_source', 'state')->sortKeys()->all())->toBe([
            'school_come_attention' => 'school_sit_attention',
            'school_place_attention' => 'school_sit_attention',
            'school_potty_attention' => 'school_sit_attention',
        ])->and(frgSlot($pet, 'phase', 'school_come_attention')->request_id)->toBeNull()
            ->and(frgSlot($pet, 'transition', 'sleeping_to_idle')->status)->toBe('ready')
            ->and(app(SchoolClipDerivativeService::class)->reversalWanted(frgSlot($pet, 'transition', 'idle_to_sleeping')))->toBeFalse()
            ->and(PetMediaDerivative::where('key', 'sleeping_to_idle')->count())->toBe(0);

        // A queued job of a target (e.g. from before the switch) never reaches fal.
        $calls = Http::recorded()->count();
        expect(app(PetMediaService::class)->submitVideo(frgSlot($pet, 'phase', 'school_come_attention')->id))->toBeTrue()
            ->and(app(PetMediaService::class)->queueStateVideos($pet->fresh()))->toBe(0)
            ->and(Http::recorded()->count())->toBe($calls);
    });

    it('switch ON: readiness, preparation, phase_groups and the payload stay consistent (one source: phaseClipsFor)', function () {
        frgSwitch(true);
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        $pet = $pet->fresh();

        $media = app(PetMediaService::class)->payloadFor($pet);
        $phases = (array) $media['phases'];
        expect($media['phase_groups'])->toBe(['ball', 'school'])
            ->and(count(app(MediaEntitlementService::class)->phaseClipsFor($pet)))->toBe(15)
            ->and(array_filter(array_keys($phases), fn ($k) => PetPhaseClip::tryFrom($k) !== null))->toHaveCount(15)
            ->and($phases['school_come_attention'])->toBe($phases['school_sit_attention'])
            ->and($phases['school_potty_attention'])->toBe($phases['school_sit_attention'])
            ->and(((array) $media['phase_seconds'])['school_place_attention'])->toBe(3.0)
            ->and(app(GameMediaReadiness::class)->ready($pet, GameMediaReadiness::SCHOOL))->toBeTrue()
            ->and(app(TrainingService::class)->mediaReady($pet))->toBeTrue()
            ->and(app(PetPreparationService::class)->summary($pet)->schoolPending)->toBeFalse()
            // sleeping_to_idle is the pet's own clip (no reversal to wait for).
            ->and(app(PetPreparationService::class)->summary($pet)->ready)->toBeTrue()
            ->and(array_keys((array) $media['transitions']))->toBe(['idle_to_sleeping', 'sleeping_to_idle'])
            ->and(((array) $media['transitions'])['sleeping_to_idle'])->not->toContain('/derived/');

        // A failed / flagged sit attention keeps the school locked for every command.
        frgSlot($pet, 'phase', 'school_sit_attention')->update(['quality' => PetMedia::QUALITY_FLAGGED]);
        $missing = app(GameMediaReadiness::class)->missing($pet->fresh(), GameMediaReadiness::SCHOOL);
        expect($missing)->toBe(['school_sit_attention'])
            ->and(app(GameMediaReadiness::class)->failedClips($pet->fresh(), app(MediaEntitlementService::class)->phaseClipsFor($pet)))->toBe(['school_sit_attention']);
    });

    it('costs: planMissing / the bulk planner price the frugal set when the switch is on', function () {
        config(['services.fal_ai.key' => null]);
        $media = app(PetMediaService::class);
        $pet = frgPet();
        $pet->giveBirth(now());
        $pet->saveQuietly();

        $off = $media->planMissing($pet->fresh());
        frgSwitch(true);
        // A new job / request reads the switch again (the fresh read is memoised per scope).
        $media = app(PetMediaService::class);
        $on = $media->planMissing($pet->fresh());

        expect($off['phases'])->toHaveCount(15)->and($on['phases'])->toHaveCount(12)
            ->and($on['phases'])->not->toContain('school_come_attention')
            ->and($off['transitions'])->toBe(['idle_to_sleeping', 'sleeping_to_idle'])
            ->and($on['transitions'])->toBe(['idle_to_sleeping', 'sleeping_to_idle'])
            ->and(round($off['cost_usd'] - $on['cost_usd'], 2))->toBe(1.68)
            ->and($media->videoCostUsd(PetStateEnum::Sleeping, true))->toBe(0.336)
            ->and($media->videoCostUsd(PetStateEnum::Sleeping, false))->toBe(0.56)
            ->and($media->videoCostUsd(PetStateEnum::Hungry, true))->toBe(0.56);
    });

    it('existing pets keep their media: 4 own attention clips, own sleeping_to_idle and 5 s loops are served unchanged and nothing is made', function () {
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        $calls = Http::recorded()->count();
        $before = app(PetMediaService::class)->payloadFor($pet->fresh());

        frgSwitch(true);
        expect(app(PetMediaService::class)->queueStateVideos($pet->fresh()))->toBe(0)
            ->and(app(PetMediaService::class)->generateMissing($pet->fresh())['videos'])->toBe(0)
            ->and(Http::recorded()->count())->toBe($calls)
            ->and(PetMedia::whereNotNull('frugal_source')->count())->toBe(0)
            ->and(app(PetMediaService::class)->payloadFor($pet->fresh()))->toEqual($before)
            ->and(app(PetMediaService::class)->planMissing($pet->fresh())['cost_usd'])->toBe(0.0);
    });

    it('switched OFF again: a marked pet keeps its shared clip until it has its own (school never re-locked)', function () {
        frgSwitch(true);
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        frgSwitch(false);
        $pet = $pet->fresh();

        $phases = (array) app(PetMediaService::class)->payloadFor($pet)['phases'];
        expect($phases['school_come_attention'])->toBe($phases['school_sit_attention'])
            ->and(app(GameMediaReadiness::class)->ready($pet, GameMediaReadiness::SCHOOL))->toBeTrue()
            // Off = today's generation: the pet's next run makes its own clips (and then shows them).
            ->and(app(PetMediaService::class)->planMissing($pet)['phases'])->toEqualCanonicalizing(['school_come_attention', 'school_place_attention', 'school_potty_attention'])
            ->and(app(PetMediaService::class)->planMissing($pet)['transitions'])->toBe([]);

        app(PetMediaService::class)->generateMissing($pet);
        // Pending at fal: still served from the source (no gap).
        expect(frgSlot($pet, 'phase', 'school_come_attention')->status)->toBe('running')
            ->and(app(GameMediaReadiness::class)->ready($pet->fresh(), GameMediaReadiness::SCHOOL))->toBeTrue();
        frgCompleteClips();
        $phases = (array) app(PetMediaService::class)->payloadFor($pet->fresh())['phases'];
        expect($phases['school_come_attention'])->not->toBe($phases['school_sit_attention'])
            ->and(frgSlot($pet, 'transition', 'sleeping_to_idle')->status)->toBe('ready');
    });

    it('a pet never touched by the switch is judged as before: a missing come attention keeps the school locked', function () {
        config(['services.fal_ai.key' => null]);
        $pet = frgPet();
        app(PetMediaService::class)->imageSlot($pet)->update(['status' => 'ready', 'storage_path' => "{$pet->id}/reference-g1.jpg"]);
        foreach (PetPhaseClip::ofGroup('school') as $clip) {
            if ($clip !== PetPhaseClip::SchoolComeAttention) {
                app(PetMediaService::class)->slot($pet, PetMedia::KIND_PHASE, $clip)->update(['status' => 'ready', 'storage_path' => "{$pet->id}/{$clip->value}-g1.mp4"]);
            }
        }

        expect(app(GameMediaReadiness::class)->missing($pet->fresh(), GameMediaReadiness::SCHOOL))->toBe(['school_come_attention'])
            ->and((array) app(PetMediaService::class)->payloadFor($pet->fresh())['phases'])->not->toHaveKey('school_come_attention');
    });
});

describe('shared looks', function () {
    it('switch ON: the first buyer makes one attention clip; an existing look with 4 keeps them (0 $ for the next buyer)', function () {
        frgFakeFal();
        // A look made before the switch: all four attention clips + own sleeping_to_idle + 5 s loops.
        $a = frgBirth(frgPet('beagle', 'tricolour'));
        frgCompleteClips();
        $spent = round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2);
        expect($spent)->toBe(12.07);

        frgSwitch(true);
        $b = frgBirth(frgPet('beagle', 'tricolour'));
        frgCompleteClips();
        $phases = (array) app(PetMediaService::class)->payloadFor($b->fresh())['phases'];
        expect(round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2))->toBe($spent)
            ->and(frgSlot($b, 'phase', 'school_come_attention')->storage_path)->toBe(frgSlot($a, 'phase', 'school_come_attention')->storage_path)
            ->and($phases['school_come_attention'])->not->toBe($phases['school_sit_attention'])
            ->and(frgSlot($b, 'transition', 'sleeping_to_idle')->look_media_id)->not->toBeNull()
            ->and(PetMedia::whereNotNull('frugal_source')->count())->toBe(0);

        // A new look with the switch on: one attention clip at look level; sleeping_to_idle made by fal.
        $c = frgBirth(frgPet('beagle', 'lemon-white'));
        frgCompleteClips();
        $look = $c->pet_look_id;
        expect(PetMedia::where('pet_look_id', $look)->where('kind', 'phase')->where('state', 'like', '%_attention')->whereNotNull('storage_path')->pluck('state')->all())->toBe(['school_sit_attention'])
            ->and(PetMedia::where('pet_look_id', $look)->where('kind', 'transition')->whereNotNull('storage_path')->pluck('state')->sort()->values()->all())->toBe(['idle_to_sleeping', 'sleeping_to_idle'])
            // QA #451 m3: the look's target rows carry the marker (never submitted) — attention only.
            ->and(PetMedia::where('pet_look_id', $look)->whereNotNull('frugal_source')->whereNull('request_id')->count())->toBe(3)
            ->and(PetMedia::where('pet_look_id', $look)->where('kind', 'video')->whereIn('state', ['sleeping', 'low_energy', 'sick'])->pluck('stored_duration_seconds')->unique()->values()->all())->toBe([3.0])
            ->and(app(GameMediaReadiness::class)->ready($c->fresh(), GameMediaReadiness::SCHOOL))->toBeTrue()
            ->and(round((float) AiSpendLedger::query()->counted()->sum('cost_usd') - $spent, 2))->toEqualWithDelta(10.39, 0.011);
    });
});

/* ─────────────────────────── Derived clips (ffmpeg) ─────────────────────────── */

describe('derived home clips (real ffmpeg, 0 $)', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('legacy B: a slot MARKED before the reversal was dropped is still served reversed (own length, full size)', function () {
        if (! frgHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
        }
        config(['services.fal_ai.key' => null]);
        $pet = frgPet();
        $source = frgStored($pet, 'transition', 'idle_to_sleeping', frgClip(360, 1440, 2, 12), 2);
        app(PetMediaService::class)->slot($pet, PetMedia::KIND_TRANSITION, PetTransition::SleepingToIdle)->update(['frugal_source' => 'idle_to_sleeping']);

        expect(app(SchoolClipDerivativeService::class)->dueSources($pet->id)->pluck('id')->all())->toBe([$source->id])
            ->and(app(SchoolClipDerivativeService::class)->make($source->id))->toBe('made');
        $row = PetMediaDerivative::where('pet_media_id', $source->id)->sole();
        $probe = frgProbe($row->storage_path);
        // Same frames, same length (no speed-up), NOT downscaled (home screen: > 1280 kept).
        expect($row->key)->toBe('sleeping_to_idle')
            ->and($row->storage_path)->toBe("{$pet->id}/idle_to_sleeping-g1-reverse.mp4")
            ->and($probe['frames'])->toBe(24)
            ->and($probe['duration'])->toEqualWithDelta(2.0, 0.05)
            ->and([$probe['width'], $probe['height']])->toBe([360, 1440]);

        $url = ((array) app(PetMediaService::class)->payloadFor($pet->fresh())['transitions'])['sleeping_to_idle'];
        expect($url)->toContain("/api/media/{$source->id}/derived/sleeping_to_idle");
        get(str_replace('https://api.petprep.si', '', $url))->assertOk();
        expect(AiSpendLedger::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('C: a 3 s low-motion loop is served as a seamless ping-pong under videos.<state>; a 5 s loop is untouched', function () {
        if (! frgHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
        }
        config(['services.fal_ai.key' => null]);
        $pet = frgPet();
        $short = frgStored($pet, 'video', 'sleeping', frgClip(144, 256, 3, 12), 3, PetMedia::RECIPE_START_ONLY);
        $long = frgStored($pet, 'video', 'sick', frgClip(144, 256, 5, 12), 5, PetMedia::RECIPE_START_ONLY);

        // Switch-independent: a stored short loop is the marker (switch OFF here).
        expect(app(SchoolClipDerivativeService::class)->dueSources($pet->id)->pluck('id')->all())->toBe([$short->id])
            ->and(app(SchoolClipDerivativeService::class)->make($short->id))->toBe('made')
            ->and(app(SchoolClipDerivativeService::class)->make($long->id))->toBe('not_source');

        $row = PetMediaDerivative::where('pet_media_id', $short->id)->sole();
        $probe = frgProbe($row->storage_path);
        // 36 frames → 0 … 35, 34 … 1 = 70 frames: starts / ends on frame 0, no doubled frame.
        expect($row->key)->toBe('sleeping_loop')->and($probe['frames'])->toBe(70)
            ->and($row->duration_seconds)->toEqualWithDelta(70 / 12, 0.01);

        $media = app(PetMediaService::class)->payloadFor($pet->fresh());
        expect(((array) $media['videos'])['sleeping'])->toContain("/api/media/{$short->id}/derived/sleeping_loop")
            ->and(((array) $media['videos'])['sick'])->toContain("/api/media/{$long->id}?")
            ->and($media['loops'])->toContain('sleeping', 'sick');
        Http::assertNothingSent();
    });
});

/* ─────────────────────────── Zero-cost preview ─────────────────────────── */

describe('media:frugal-preview', function () {
    it('makes 3 s ping-pongs from stored clips (no reversed transition any more) — no fal call, signed superadmin URLs', function () {
        if (! frgHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
        }
        config(['services.fal_ai.key' => null]);
        $pet = frgPet();
        $transition = frgStored($pet, 'transition', 'idle_to_sleeping', frgClip(144, 256, 2, 12), 2);
        $loop = frgStored($pet, 'video', 'low_energy', frgClip(144, 256, 5, 12), 5, PetMedia::RECIPE_START_ONLY);

        expect(Artisan::call('media:frugal-preview', ['--pet' => $pet->id, '--minutes' => 30]))->toBe(0);
        $output = Artisan::output();

        Storage::disk('pet_media')->assertMissing(FrugalClipPreview::pathFor($transition->id, 'sleeping_to_idle'));
        expect(FrugalClipPreview::keys())->not->toContain('sleeping_to_idle');
        Storage::disk('pet_media')->assertExists(FrugalClipPreview::pathFor($loop->id, 'low_energy_loop'));
        // The first 3 s (36 frames) as a ping-pong: 70 frames.
        expect(frgProbe(FrugalClipPreview::pathFor($loop->id, 'low_energy_loop'))['frames'])->toBe(70)
            ->and($output)->toContain("/api/media/{$loop->id}/frugal-preview/low_energy_loop?expires=")
            ->and(AiSpendLedger::count())->toBe(0)
            // Never in the app payload, never a derived row.
            ->and(json_encode(app(PetMediaService::class)->payloadFor($pet->fresh())))->not->toContain('frugal-preview')
            ->and(PetMediaDerivative::count())->toBe(0);
        Http::assertNothingSent();

        preg_match('#https://api\.petprep\.si(/api/media/'.$loop->id.'/frugal-preview/low_energy_loop\?\S+)#', $output, $m);
        get($m[1])->assertOk();
        // Unsigned / unknown key → refused.
        get("/api/media/{$loop->id}/frugal-preview/low_energy_loop")->assertForbidden();
    });

    it('needs exactly one of --look / --pet', function () {
        expect(Artisan::call('media:frugal-preview'))->toBe(2)
            ->and(Artisan::call('media:frugal-preview', ['--look' => 1, '--pet' => 1]))->toBe(2);
    });
});

describe('ffmpeg recipes (pure)', function () {
    it('home-screen clips keep their size at CRF 21; reverse keeps the length; school clips are capped at 1280 / CRF 23', function () {
        $probe = ['frames' => 120, 'fps' => 24.0, 'duration' => 5.0, 'width' => 1076, 'height' => 1924];
        $reverse = SchoolClipDerivativeService::ffmpegArgs('ffmpeg', 'in', 'out', $probe, SchoolClipDerivativeService::MODE_REVERSE, homeScreen: true);
        $school = SchoolClipDerivativeService::ffmpegArgs('ffmpeg', 'in', 'out', $probe, false);

        expect($reverse)->toContain('reverse')->and(implode(' ', $reverse))->not->toContain('setpts')->not->toContain('scale=')
            ->and($reverse[array_search('-crf', $reverse, true) + 1])->toBe('21')
            ->and(implode(' ', $school))->toContain('scale=-2:1280,')
            ->and($school[array_search('-crf', $school, true) + 1])->toBe('23');
    });
});

/* ─────────────────────────── QA #451 ─────────────────────────── */

/** frgFakeFal + the sync stage edit (life-stage growth). */
function frgFakeFalWithGrowth(): void
{
    $n = 0;
    Http::fake([
        'fal.run/fal-ai/nano-banana-pro/edit' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/fr/grow-{$n}.jpg"]]]);
        },
    ]);
    frgFakeFal();
}

/** Grow a pet to the adult stage (stage image + its whole set) and finish every fal request. */
function frgGrowToAdult(Pet $pet): Pet
{
    $pet->forceFill(['life_stage' => LifeStage::Adult])->saveQuietly();
    expect(app(PetMediaService::class)->startStageTransition($pet->fresh()))->toBe('started');
    frgCompleteClips();

    return $pet->fresh();
}

describe('QA #451 B1: a stage change never dead-ends', function () {
    it('shared look, no adult look rows: adult sit attention serves the targets, adult sleeping_to_idle is made by fal, nothing stuck running', function () {
        frgFakeFalWithGrowth();
        // Made before the switch: the young look has all four attention clips + its own sleeping_to_idle.
        $pet = frgBirth(frgPet('beagle', 'tricolour'));
        frgCompleteClips();
        expect(frgSlot($pet, 'phase', 'school_come_attention')->storage_path)->not->toBeNull();

        frgSwitch(true);

        // B1 / planMissing(stageRemake): the targets of the new stage are not priced.
        $pet->forceFill(['life_stage' => LifeStage::Adult])->saveQuietly();
        $plan = app(PetMediaService::class)->planMissing($pet->fresh(), stageRemake: true);
        expect($plan['image'])->toBe('stage')
            ->and($plan['phases'])->toHaveCount(12)->not->toContain('school_come_attention')
            ->and($plan['transitions'])->toBe(['idle_to_sleeping', 'sleeping_to_idle']);
        $pet->forceFill(['life_stage' => LifeStage::Young])->saveQuietly();

        $pet = frgGrowToAdult($pet->fresh());
        $look = $pet->pet_look_id;

        // No adult target rows made at fal; nothing waits forever.
        expect(PetMedia::where('pet_look_id', $look)->where('life_stage', 'adult')->whereIn('state', ['school_come_attention', 'school_place_attention', 'school_potty_attention'])->whereNotNull('request_id')->count())->toBe(0)
            ->and(PetMedia::where('pet_id', $pet->id)->where('status', 'running')->count())->toBe(0)
            ->and(frgSlot($pet, 'phase', 'school_come_attention')->frugal_source)->toBe('school_sit_attention')
            ->and(frgSlot($pet, 'transition', 'sleeping_to_idle')->frugal_source)->toBeNull()
            ->and(PetMedia::find(frgSlot($pet, 'transition', 'sleeping_to_idle')->look_media_id)?->life_stage)->toBe('adult');

        // The payload serves the ADULT sit attention for every command — never the young file.
        $phases = (array) app(PetMediaService::class)->payloadFor($pet)['phases'];
        $sit = frgSlot($pet, 'phase', 'school_sit_attention');
        expect(PetMedia::find($sit->look_media_id)->life_stage)->toBe('adult')
            ->and($phases['school_come_attention'])->toBe($phases['school_sit_attention'])
            ->and($phases['school_potty_attention'])->toBe($phases['school_sit_attention'])
            ->and(app(GameMediaReadiness::class)->ready($pet, GameMediaReadiness::SCHOOL))->toBeTrue();

        // A queued job of a target waiting for a look row is released, not left running.
        $come = frgSlot($pet, 'phase', 'school_come_attention');
        $adultRow = app(PetMediaService::class)->lookSlot($pet->look, PetMedia::KIND_PHASE, PetPhaseClip::SchoolComeAttention, LifeStage::Adult);
        $come->update(['status' => 'running', 'look_media_id' => $adultRow->id, 'request_id' => null]);
        expect(app(PetMediaService::class)->submitVideo($come->id))->toBeTrue()
            ->and($come->fresh()->status)->toBe('pending')
            ->and($adultRow->fresh()->request_id)->toBeNull();

        // Never a reversal: the adult sleeping_to_idle is the look's own clip.
        $url = ((array) app(PetMediaService::class)->payloadFor($pet->fresh())['transitions'])['sleeping_to_idle'] ?? null;
        expect($url)->not->toBeNull()->not->toContain('/derived/');
    });

    it('a pet with its own media made before the switch: the adult set has one attention clip and both transitions from fal', function () {
        useUniquePetMedia(true);
        frgFakeFalWithGrowth();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        frgSwitch(true);
        $before = frgKlingSubmits()->count();

        $pet = frgGrowToAdult($pet);
        $adult = frgKlingSubmits()->slice($before)->values();
        $attention = $adult->filter(fn (Request $r) => str_contains((string) $r['prompt'], 'looks up at the camera eagerly'));
        $transitions = $adult->filter(fn (Request $r) => frgKind($r) === 'transition');

        expect($attention)->toHaveCount(1)
            ->and($transitions)->toHaveCount(2)
            ->and(PetMedia::where('pet_id', $pet->id)->where('status', 'running')->count())->toBe(0)
            // The young come attention file is stale → served from the adult sit attention.
            ->and(frgSlot($pet, 'phase', 'school_come_attention')->frugal_source)->toBe('school_sit_attention');
        $phases = (array) app(PetMediaService::class)->payloadFor($pet)['phases'];
        expect($phases['school_come_attention'])->toBe($phases['school_sit_attention'])
            ->and(app(GameMediaReadiness::class)->ready($pet, GameMediaReadiness::SCHOOL))->toBeTrue();
    });
});

describe('QA #451 majors / minors', function () {
    beforeEach(fn () => useUniquePetMedia(true));

    it('major 1: a cat with the switch on is unchanged (both transitions, 5 s loops, no marker)', function () {
        frgSwitch(true);
        frgFakeFal();
        $cat = frgBirth(frgPet('maine_coon', 'brown'));
        frgCompleteClips();

        $byKind = frgKlingSubmits()->groupBy(fn (Request $r) => frgKind($r));
        expect($byKind['transition'])->toHaveCount(2)
            ->and($byKind['loop']->pluck('duration')->unique()->values()->all())->toBe(['5'])
            ->and(PetMedia::whereNotNull('frugal_source')->count())->toBe(0)
            ->and(app(MediaEntitlementService::class)->frugalFor($cat->speciesValue()))->toBeFalse()
            ->and(app(FrugalClipPreview::class)->sources(null, $cat->id))->toHaveCount(0);
    });

    it('m1: the nightly retry never picks a target left to its source (filtered in SQL)', function () {
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        $come = frgSlot($pet, 'phase', 'school_come_attention');
        $come->update(['status' => 'failed', 'error_reason' => 'budget_daily', 'storage_path' => null]);
        $retry = app(ReferenceImageRetryService::class);

        expect($retry->videoRetryCandidates()->pluck('id')->all())->toContain($come->id);
        frgSwitch(true);
        expect(app(ReferenceImageRetryService::class)->videoRetryCandidates()->pluck('id')->all())->not->toContain($come->id);
    });

    it('m10: a failed own target with the switch on is not regenerated — served from sit attention', function () {
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        frgSlot($pet, 'phase', 'school_place_attention')->update(['status' => 'failed', 'error_reason' => 'http_error', 'storage_path' => null]);
        frgSwitch(true);
        $calls = frgKlingSubmits()->count();

        app(PetMediaService::class)->generateMissing($pet->fresh());
        expect(frgKlingSubmits()->count())->toBe($calls)
            ->and(frgSlot($pet, 'phase', 'school_place_attention')->frugal_source)->toBe('school_sit_attention')
            ->and(app(GameMediaReadiness::class)->ready($pet->fresh(), GameMediaReadiness::SCHOOL))->toBeTrue();
    });

    it('m10: the sweep never resumes a pet for a slot marked frugal_source', function () {
        frgSwitch(true);
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        PetMedia::where('pet_id', $pet->id)->whereNotNull('frugal_source')->update(['updated_at' => now()->subHour()]);
        frgSwitch(false);
        Queue::fake([SubmitPetStateVideo::class]);

        expect(app(PetMediaService::class)->resumeStateMedia(staleOnly: true))->toBe(0);
        Queue::assertNothingPushed();
    });

    it('m2: a ping-pong that fails for good falls back to fal (5 s loop) and alerts the admins; nothing is reversed', function () {
        if (! frgHasFfmpeg()) {
            $this->markTestSkipped('ffmpeg / ffprobe not installed locally — CI runs this.');
        }
        Queue::fake([SendFrugalClipFallbackAlert::class]);
        frgSwitch(true);
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        $derivatives = app(SchoolClipDerivativeService::class);

        // The fake fal bytes are no video: the first try failed in the pipeline, two more = MAX_ATTEMPTS.
        $i2s = frgSlot($pet, 'transition', 'idle_to_sleeping');
        $sleeping = frgSlot($pet, 'video', 'sleeping');
        expect(PetMediaDerivative::where('pet_media_id', $i2s->id)->count())->toBe(0)
            ->and($derivatives->make($i2s->id))->toBe('not_source');
        $derivatives->make($sleeping->id);
        $derivatives->make($sleeping->id);

        Queue::assertPushed(SendFrugalClipFallbackAlert::class, 1);
        expect(frgSlot($pet, 'transition', 'sleeping_to_idle')->status)->toBe('ready')
            ->and($sleeping->fresh()->request_id)->not->toBeNull()
            ->and((float) $sleeping->fresh()->duration_seconds)->toBe(5.0)
            ->and(frgKlingSubmits()->last()['duration'])->toBe('5');

        frgCompleteClips();
        expect(frgSlot($pet, 'transition', 'sleeping_to_idle')->frugal_source)->toBeNull()
            ->and(array_keys((array) app(PetMediaService::class)->payloadFor($pet->fresh())['transitions']))->toContain('sleeping_to_idle');
    });

    it('m4 / m5: no reversal job without a marked sibling — switch off or ON (the reversal was dropped)', function () {
        config(['services.fal_ai.key' => null]);
        $pet = frgPet();
        $i2s = frgStored($pet, 'transition', 'idle_to_sleeping', FRG_MP4, 2);

        expect(app(SchoolClipDerivativeService::class)->reversalWanted($i2s))->toBeFalse()
            ->and(app(SchoolClipDerivativeService::class)->dueSources($pet->id)->count())->toBe(0);
        frgSwitch(true);
        expect(app(SchoolClipDerivativeService::class)->reversalWanted($i2s))->toBeFalse()
            ->and(app(SchoolClipDerivativeService::class)->dueSources($pet->id)->count())->toBe(0);
        // Only a legacy marked sibling (made before 2026-10-30) still wants its reversal.
        app(PetMediaService::class)->slot($pet, PetMedia::KIND_TRANSITION, PetTransition::SleepingToIdle)->update(['frugal_source' => 'idle_to_sleeping']);
        expect(app(SchoolClipDerivativeService::class)->reversalWanted($i2s))->toBeTrue();
    });

    it('m7: previews expire — the daily prune deletes old files; the command is scheduled', function () {
        Storage::disk('pet_media')->put(FrugalClipPreview::PREFIX.'/1-sleeping_loop.mp4', FRG_MP4);
        Storage::disk('pet_media')->put(FrugalClipPreview::PREFIX.'/2-sleeping_loop.mp4', FRG_MP4);
        // File times are real time: make the first one 25 h old.
        touch(Storage::disk('pet_media')->path(FrugalClipPreview::PREFIX.'/1-sleeping_loop.mp4'), time() - 25 * 3600);

        expect(Artisan::call('media:frugal-preview', ['--prune' => true]))->toBe(0);
        Storage::disk('pet_media')->assertMissing(FrugalClipPreview::PREFIX.'/1-sleeping_loop.mp4');
        Storage::disk('pet_media')->assertExists(FrugalClipPreview::PREFIX.'/2-sleeping_loop.mp4');
        expect(collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'media:frugal-preview --prune')))->toBeTrue();
    });

    it('major 2: every derived-clip ffmpeg call is niced and memory-capped', function () {
        $prefix = SchoolClipDerivativeService::limits();
        expect(array_slice($prefix, 0, 3))->toBe(['nice', '-n', '19']);
        if (in_array('prlimit', $prefix, true)) {
            expect($prefix)->toContain('--as='.(2048 * 1024 * 1024));
        }
    });
});

it('major 2: derived clips and media:compress share one heavy ffmpeg lock', function () {
    config(['media.derivatives.heavy_lock_wait_seconds' => 0, 'services.fal_ai.key' => null]);
    useUniquePetMedia(true);
    $pet = frgPet();
    $loop = frgStored($pet, 'video', 'sleeping', FRG_MP4, 3, PetMedia::RECIPE_START_ONLY);
    $held = Cache::lock(SchoolClipDerivativeService::HEAVY_LOCK, 60);
    expect($held->get())->toBeTrue();

    expect(app(SchoolClipDerivativeService::class)->make($loop->id))->toBe('busy')
        ->and(app(VideoCompressionService::class)->compress($loop->id)['outcome'])->toBe('busy')
        ->and(PetMediaDerivative::count())->toBe(0);

    $held->release();
});

/* ─────────────────────── media:frugal-unreverse (David 2026-10-30) ─────────────────────── */

/**
 * Turn the pet's / look's sleeping_to_idle into what the dropped frugal B left behind: no clip of
 * its own, marked `frugal_source = idle_to_sleeping`, served from a READY reversed derivative of
 * the owner of its idle_to_sleeping file. Returns that derivative.
 */
function frgLegacyReversal(Pet $pet): PetMediaDerivative
{
    $s2i = frgSlot($pet, 'transition', 'sleeping_to_idle');
    $i2s = frgSlot($pet, 'transition', 'idle_to_sleeping');
    $owner = PetMedia::find(SchoolClipDerivativeService::ownerId($i2s));

    $legacy = ['status' => 'pending', 'storage_path' => null, 'request_id' => null, 'source_url' => null, 'status_url' => null, 'response_url' => null, 'look_media_id' => null,
        'bytes' => null, 'stored_recipe' => null, 'frugal_source' => 'idle_to_sleeping'];
    $s2i->update($legacy);
    if ($s2i->fresh()->look_media_id === null && $owner->pet_look_id !== null) {
        PetMedia::where('pet_look_id', $owner->pet_look_id)->where('life_stage', $owner->life_stage)
            ->where('kind', 'transition')->where('state', 'sleeping_to_idle')->update($legacy);
    }

    $path = SchoolClipDerivativeService::derivedPath((string) $owner->storage_path, SchoolClipDerivativeService::SUFFIX_REVERSE);
    Storage::disk('pet_media')->put($path, FRG_MP4);

    return PetMediaDerivative::firstOrCreate(['pet_media_id' => $owner->id, 'key' => 'sleeping_to_idle'], [
        'status' => PetMediaDerivative::STATUS_READY, 'source_path' => $owner->storage_path, 'storage_path' => $path,
        'bytes' => strlen(FRG_MP4), 'duration_seconds' => 5.0, 'attempts' => 0,
    ]);
}

function frgTransitionUrl(Pet $pet): ?string
{
    return ((array) app(PetMediaService::class)->payloadFor($pet->fresh())['transitions'])['sleeping_to_idle'] ?? null;
}

describe('media:frugal-unreverse', function () {
    it('own media: dry run lists the slot at 0.56 $ and changes nothing; the real run makes a fal clip, the reversal stays shown until it is stored, then is deleted', function () {
        useUniquePetMedia(true);
        frgSwitch(true);
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        $derivative = frgLegacyReversal($pet);
        $s2i = frgSlot($pet, 'transition', 'sleeping_to_idle');
        $spent = (float) AiSpendLedger::query()->counted()->sum('cost_usd');
        $submits = frgKlingSubmits()->count();

        // Served reversed, preparation complete (no gap).
        expect(frgTransitionUrl($pet))->toContain('/derived/sleeping_to_idle')
            ->and(app(PetPreparationService::class)->summary($pet->fresh())->ready)->toBeTrue();

        // Dry run: listed, free, nothing touched.
        expect(Artisan::call('media:frugal-unreverse', ['--dry-run' => true]))->toBe(0);
        $output = Artisan::output();
        expect($output)->toContain("pet {$pet->id}")->toContain('generate')->toContain('$0.56')
            ->toContain('Dry run: 1 item(s), 1 pet slot(s), 1 new fal clip(s), estimated $0.56')
            ->and(frgKlingSubmits()->count())->toBe($submits)
            ->and($s2i->fresh()->status)->toBe('pending')
            ->and($s2i->fresh()->frugal_source)->toBe('idle_to_sleeping')
            ->and(PetMediaDerivative::whereKey($derivative->id)->exists())->toBeTrue();

        // Real run: one transition submit; until the clip is stored the reversal is still shown.
        expect(Artisan::call('media:frugal-unreverse'))->toBe(0);
        $new = frgKlingSubmits()->slice($submits)->values();
        expect($new)->toHaveCount(1)->and(frgKind($new[0]))->toBe('transition')->and($new[0]['duration'])->toBe('5')
            ->and($s2i->fresh()->status)->toBe('running')
            ->and(frgTransitionUrl($pet))->toContain('/derived/sleeping_to_idle')
            ->and(app(PetPreparationService::class)->summary($pet->fresh())->ready)->toBeTrue()
            ->and(AppSettingChange::where('key', 'media_frugal_unreverse')->sole()->new['pet_media_ids'])->toBe([$s2i->id]);

        frgCompleteClips();
        expect($s2i->fresh()->status)->toBe('ready')
            ->and($s2i->fresh()->frugal_source)->toBeNull()
            ->and(frgTransitionUrl($pet))->toContain("/api/media/{$s2i->id}?")
            ->and(PetMediaDerivative::where('key', 'sleeping_to_idle')->count())->toBe(0)
            ->and(PetMedia::where('pet_id', $pet->id)->where('status', 'running')->count())->toBe(0)
            ->and(round((float) AiSpendLedger::query()->counted()->sum('cost_usd') - $spent, 2))->toBe(0.56);
        Storage::disk('pet_media')->assertMissing($derivative->storage_path);
        // The source file and every other clip are untouched.
        Storage::disk('pet_media')->assertExists(frgSlot($pet, 'transition', 'idle_to_sleeping')->storage_path);

        // Idempotent: nothing left.
        expect(Artisan::call('media:frugal-unreverse', ['--dry-run' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('No reversed sleeping_to_idle left to replace.');
    });

    it('a shared look is generated ONCE for all its pets; each pet keeps the reversal until the look clip is linked', function () {
        frgSwitch(true);
        frgFakeFal();
        $a = frgBirth(frgPet('beagle', 'tricolour'));
        frgCompleteClips();
        $b = frgBirth(frgPet('beagle', 'tricolour'));
        frgCompleteClips();
        expect($a->pet_look_id)->toBe($b->pet_look_id);
        $derivative = frgLegacyReversal($a);
        frgLegacyReversal($b);
        $spent = (float) AiSpendLedger::query()->counted()->sum('cost_usd');
        $submits = frgKlingSubmits()->count();
        expect(frgTransitionUrl($a))->toContain('/derived/sleeping_to_idle')
            ->and(frgTransitionUrl($b))->toContain('/derived/sleeping_to_idle');

        expect(Artisan::call('media:frugal-unreverse', ['--dry-run' => true]))->toBe(0);
        expect(Artisan::output())->toContain("look {$a->pet_look_id}")
            ->toContain('1 item(s), 2 pet slot(s), 1 new fal clip(s), estimated $0.56');

        expect(Artisan::call('media:frugal-unreverse'))->toBe(0)
            ->and(frgKlingSubmits()->count() - $submits)->toBe(1)
            // Waiting for the look row: still the reversal, never a gap.
            ->and(frgTransitionUrl($a))->toContain('/derived/sleeping_to_idle')
            ->and(frgTransitionUrl($b))->toContain('/derived/sleeping_to_idle');

        frgCompleteClips();
        $row = PetMedia::where('pet_look_id', $a->pet_look_id)->where('kind', 'transition')->where('state', 'sleeping_to_idle')->sole();
        expect($row->status)->toBe('ready')->and($row->frugal_source)->toBeNull()
            ->and(frgSlot($a, 'transition', 'sleeping_to_idle')->look_media_id)->toBe($row->id)
            ->and(frgSlot($b, 'transition', 'sleeping_to_idle')->look_media_id)->toBe($row->id)
            ->and(PetMedia::whereNotNull('frugal_source')->where('kind', 'transition')->count())->toBe(0)
            ->and(frgTransitionUrl($a))->not->toContain('/derived/')
            ->and(PetMediaDerivative::where('key', 'sleeping_to_idle')->count())->toBe(0)
            ->and(PetMedia::where('status', 'running')->whereIn('pet_id', [$a->id, $b->id])->count())->toBe(0)
            ->and(round((float) AiSpendLedger::query()->counted()->sum('cost_usd') - $spent, 2))->toBe(0.56);
        Storage::disk('pet_media')->assertMissing($derivative->storage_path);
    });

    it('refuses a run the AI budget cannot cover — nothing queued, the reversal stays shown', function () {
        useUniquePetMedia(true);
        frgSwitch(true);
        frgFakeFal();
        $pet = frgBirth(frgPet());
        frgCompleteClips();
        frgLegacyReversal($pet);
        $submits = frgKlingSubmits()->count();
        config(['media.budget.daily_usd' => (float) AiSpendLedger::query()->counted()->sum('cost_usd') + 0.10]);

        expect(Artisan::call('media:frugal-unreverse'))->toBe(1)
            ->and(Artisan::output())->toContain('does not fit the AI budget')
            ->and(frgKlingSubmits()->count())->toBe($submits)
            ->and(frgSlot($pet, 'transition', 'sleeping_to_idle')->status)->toBe('pending')
            ->and(frgTransitionUrl($pet))->toContain('/derived/sleeping_to_idle');
    });

    it('is never scheduled', function () {
        expect(collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'frugal-unreverse')))->toBeFalse();
    });
});
