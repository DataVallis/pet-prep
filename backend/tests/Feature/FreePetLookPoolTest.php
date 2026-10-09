<?php

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Http\Controllers\PetMediaController;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\StorePetMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AiSpendLedger;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetMediaHistory;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountExportService;
use App\Services\ChallengeService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaLabService;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetGrowthService;
use App\Services\Media\PetLookPoolService;
use App\Services\Media\PetMediaService;
use App\Services\PairingService;
use App\Services\Results\PetProfileChoice;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| M4-10 — shared appearance pool for free pets (David 2026-10-09): lazy fill
| to 20 looks per free breed, reuse without a fal call, family uniqueness,
| purchase keeps the look (extra videos stored on the look), stage
| transitions, concurrency guard, deletion keeps shared files.
|--------------------------------------------------------------------------
*/

const LP_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
const LP_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

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
        'media.budget.daily_usd' => 50.0,
        'media.budget.monthly_usd' => 500.0,
        'media.budget.timezone' => 'UTC',
        'media.look_pool.enabled' => true,
        'media.look_pool.size' => 20,
    ]);
    seedBreedConfigs();
    seedStageParams();
});

/** fal fakes: every image / submit gets a unique result; downloads return real magic bytes. */
function lpFakeFal(): void
{
    fakeFalJwks();
    $n = 0;
    Http::fake([
        'fal.run/fal-ai/nano-banana-pro/edit' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/look/edit-{$n}.jpg"]]]);
        },
        'fal.run/fal-ai/nano-banana-pro' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/look/ref-{$n}.jpg"]]]);
        },
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "req-{$n}"]);
        },
        // A fresh response per download (a shared one is read only once).
        'v3.fal.media/files/look/*.jpg' => fn () => Http::response(LP_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/look/*.mp4' => fn () => Http::response(LP_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** Paid fal calls so far (image runs + video submits; no downloads, no JWKS). */
function lpFalCalls(): int
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://fal.run/') || str_starts_with($r->url(), 'https://queue.fal.run/'))->count();
}

/** Every HTTP request so far (a reused look needs none at all). */
function lpAllCalls(): int
{
    return Http::recorded()->count();
}

/**
 * A new profiled pet created exactly like a PIN login does (PairingService
 * inside the family transaction). A new child per pet (one active pet per child).
 */
function lpPet(?User $parent = null, string $breed = 'mutt', string $stage = 'puppy', string $origin = 'bought', ?PetPlan $plan = null): Pet
{
    $parent ??= createParentUser();
    $family = app(FamilyService::class)->ensureFamilyFor($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $type = BreedType::from($breed);
    $profile = new PetProfileChoice($type, PetOrigin::from($origin), LifeStage::from($stage), [], $type->species());
    $plan ??= $type->isPremium() ? PetPlan::Challenge : PetPlan::Free;

    $pet = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, $plan)['pet']);

    return $pet->fresh();
}

function lpBirth(Pet $pet): Pet
{
    $pet->giveBirth(now());
    $pet->save();

    return $pet->fresh();
}

/** Answer every submitted, unanswered look video with a successful signed webhook. */
function lpCompleteLookVideos(): int
{
    $rows = PetMedia::query()->whereNotNull('pet_look_id')->videos()
        ->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();

    foreach ($rows as $row) {
        sendFalWebhook([
            'request_id' => $row->request_id,
            'status' => 'OK',
            'payload' => ['video' => ['url' => "https://v3.fal.media/files/look/{$row->request_id}.mp4"]],
        ])->assertOk();
    }

    return $rows->count();
}

/** A pool pet with its stored basic set (image + idle + sleeping) through the real pipeline. */
function lpPetWithBasicSet(?User $parent = null, string $stage = 'puppy'): Pet
{
    $pet = lpPet($parent, 'mutt', $stage);   // reference image runs at pairing (sync queue)
    $pet = lpBirth($pet);
    app(PetMediaService::class)->queueStateVideos($pet);
    lpCompleteLookVideos();

    return $pet->fresh();
}

/** Path + query of one of our absolute signed URLs. */
function lpPath(string $url): string
{
    $parts = parse_url($url);

    return $parts['path'].'?'.$parts['query'];
}

function lpPurchase(Pet $pet): void
{
    DB::transaction(function () use ($pet): void {
        $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();
        // No app path buys a free-breed challenge today (M5-F03) — simulated for the look rule.
        $locked->forceFill(['plan' => PetPlan::Challenge]);
        app(ChallengeService::class)->markPaid($locked, ChallengePaidSource::Purchase, now());
    });
}

/* ─────────────────────────── Pool fill + pick ─────────────────────────── */

describe('pool fill', function () {
    it('creates a new look for every new free pet until the pool has 20, then reuses existing looks', function () {
        config(['services.fal_ai.key' => null]); // no media here — only the pick

        $pets = [];
        for ($i = 1; $i <= 20; $i++) {
            $pets[] = lpPet(); // a new family each time: the family rule never interferes
        }

        $looks = PetLook::where('breed_type', 'mutt')->orderBy('pool_index')->get();
        expect($looks)->toHaveCount(20)
            ->and($looks->pluck('pool_index')->all())->toBe(range(1, 20))
            ->and($looks->pluck('trait_fingerprint')->unique())->toHaveCount(20)
            ->and(collect($pets)->pluck('pet_look_id')->unique())->toHaveCount(20);

        // The pet's DNA is the look's DNA v2 (traits, fingerprint, prompt — no name, no origin) + the look id.
        $first = $pets[0];
        $look = $first->look;
        expect($first->pet_dna)->toMatchArray([
            'version' => 2,
            'look_id' => $look->id,
            'traits' => $look->dna['traits'],
            'trait_fingerprint' => $look->trait_fingerprint,
            'prompt' => $look->dna['prompt'],
        ]);

        $extra = lpPet();
        expect(PetLook::count())->toBe(20)
            ->and($looks->pluck('id')->all())->toContain($extra->pet_look_id);
    });

    it('lets a family never show the same look twice while the pool has one it does not use', function () {
        config(['services.fal_ai.key' => null, 'media.look_pool.size' => 3]);
        lpPet();
        lpPet();
        lpPet(); // pool full: 3 looks from other families

        $parent = createParentUser();
        $a = lpPet($parent);
        $b = lpPet($parent);
        $c = lpPet($parent);

        expect(PetLook::count())->toBe(3)
            ->and(collect([$a, $b, $c])->pluck('pet_look_id')->unique()->sort()->values()->all())
            ->toBe(PetLook::orderBy('id')->pluck('id')->all());

        // Every look is used in the family → any look is allowed.
        $d = lpPet($parent);
        expect(PetLook::pluck('id')->all())->toContain($d->pet_look_id)
            ->and(PetLook::count())->toBe(3);
    });

    it('creates the family a fresh look while the pool is filling (never one the family shows)', function () {
        config(['services.fal_ai.key' => null]);
        $parent = createParentUser();
        $a = lpPet($parent);
        $b = lpPet($parent);

        expect($a->pet_look_id)->not->toBe($b->pet_look_id)
            ->and($a->pet_dna['trait_fingerprint'])->not->toBe($b->pet_dna['trait_fingerprint']);
    });

    it('keeps the unique DNA for a paid breed, a legacy-profile pet and with the pool switched off', function () {
        config(['services.fal_ai.key' => null]);

        $collie = lpPet(null, 'border_collie', 'young');
        expect($collie->pet_look_id)->toBeNull()
            ->and($collie->pet_dna)->toHaveKey('salt')
            ->and($collie->pet_dna)->not->toHaveKey('look_id');

        // No profile (old PIN) → a legacy mutt: pre-M5 rules, its own DNA.
        $parent = createParentUser();
        $family = app(FamilyService::class)->ensureFamilyFor($parent);
        $child = createChildUser(['parent_id' => $parent->id]);
        $legacy = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, null, PetPlan::Free)['pet'])->fresh();
        expect($legacy->isLegacyProfile())->toBeTrue()->and($legacy->pet_look_id)->toBeNull();

        config(['media.look_pool.enabled' => false]);
        $off = lpPet();
        expect($off->pet_look_id)->toBeNull()->and($off->pet_dna)->toHaveKey('salt');

        expect(PetLook::count())->toBe(0);
    });

    it('fills a separate pool for the free domestic cat (cat prompts, no "dog")', function () {
        config(['services.fal_ai.key' => null, 'petprep.cats_enabled' => true]);

        $cat = lpPet(null, 'domestic_cat', 'puppy');
        $dog = lpPet();

        expect($cat->look->breed_type)->toBe(BreedType::DomesticCat)
            ->and($dog->look->breed_type)->toBe(BreedType::Mutt)
            ->and(PetLook::where('breed_type', 'domestic_cat')->count())->toBe(1)
            ->and($cat->pet_dna['prompt'])->toContain('cat')
            ->and(preg_match('/\bdogs?\b/i', $cat->pet_dna['prompt']))->toBe(0)
            ->and(preg_match('/\bdogs?\b/i', app(PetAppearancePrompt::class)->stagedImagePrompt('domestic_cat', $cat->look->traits(), LifeStage::Puppy)))->toBe(0);
    });
});

/* ─────────────────────────── Media reuse ─────────────────────────── */

describe('look media', function () {
    it('generates a look once and gives the next pet of the look its files with no HTTP call at all', function () {
        lpFakeFal();
        $a = lpPetWithBasicSet();

        $image = PetMedia::where('pet_id', $a->id)->images()->sole();
        $lookImage = PetMedia::findOrFail($image->look_media_id);
        expect($lookImage->pet_look_id)->toBe($a->pet_look_id)
            ->and($lookImage->pet_id)->toBeNull()
            ->and($lookImage->storage_path)->toBe("looks/{$a->pet_look_id}/reference-puppy-g1.jpg")
            ->and($image->storage_path)->toBe($lookImage->storage_path)
            ->and($image->cost_usd)->toEqualWithDelta(0.0, 1e-9)
            ->and($lookImage->cost_usd)->toEqualWithDelta(0.15, 1e-9)
            ->and(lpFalCalls())->toBe(3); // 1 image + idle + sleeping

        // The look's prompt has no origin cue and no personal data.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/nano-banana-pro'
            && $r['prompt'] === app(PetAppearancePrompt::class)->stagedImagePrompt('mutt', $a->look->traits(), LifeStage::Puppy));

        // The ledger counts the spend once, linked to the look rows — no pet.
        $ledger = AiSpendLedger::query()->counted()->get();
        expect($ledger)->toHaveCount(3)
            ->and($ledger->pluck('pet_id')->unique()->all())->toBe([null])
            ->and((float) $ledger->sum('cost_usd'))->toEqualWithDelta(0.15 + 2 * 0.56, 1e-9);

        // Pool of 1 → the next free pet (another family) gets the same look.
        config(['media.look_pool.size' => 1]);
        Event::fake([PetUpdated::class]);
        $before = lpAllCalls();

        $b = lpBirth(lpPet());
        app(PetMediaService::class)->queueStateVideos($b);

        expect($b->pet_look_id)->toBe($a->pet_look_id)
            ->and(lpAllCalls())->toBe($before) // no fal call, no download
            ->and(AiSpendLedger::count())->toBe(3);

        $bSlots = PetMedia::where('pet_id', $b->id)->orderBy('kind')->orderBy('state')->get();
        $aSlots = PetMedia::where('pet_id', $a->id)->orderBy('kind')->orderBy('state')->get();
        expect($bSlots->pluck('status')->unique()->all())->toBe(['ready'])
            ->and($bSlots->pluck('storage_path')->all())->toBe($aSlots->pluck('storage_path')->all())
            ->and(Storage::disk('pet_media')->allFiles((string) $b->id))->toBe([]); // nothing duplicated on disk

        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $b->id && $e->eventType === 'reference_image_ready');
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $b->id && $e->eventType === 'video_ready');

        // B's own signed URLs (its slot ids) serve the shared files.
        $media = app(PetMediaService::class)->payloadFor($b->fresh());
        expect($media['status'])->toBe('ready')
            ->and($media['reference_image_url'])->toStartWith("https://api.petprep.si/api/media/{$bSlots->firstWhere('kind', 'image')->id}?");
        $this->get(lpPath($media['videos']->sleeping))->assertOk()->assertHeader('Content-Type', 'video/mp4');
    });

    it('keeps the look on purchase, stores the extra full-set videos on the look and reuses them for the next purchase', function () {
        lpFakeFal();
        $a = lpPetWithBasicSet();
        $lookId = $a->pet_look_id;
        $imagePath = PetMedia::where('pet_id', $a->id)->images()->value('storage_path');
        $calls = lpFalCalls();

        lpPurchase($a);

        // The missing full-set videos of a dog without behaviour events: 4 submits on the look.
        expect(lpFalCalls())->toBe($calls + 4)
            ->and(lpCompleteLookVideos())->toBe(4);

        $a = $a->fresh();
        expect($a->pet_look_id)->toBe($lookId)
            ->and(PetMedia::where('pet_id', $a->id)->images()->value('storage_path'))->toBe($imagePath)
            ->and(PetMedia::where('pet_look_id', $lookId)->videos()->where('status', 'ready')->orderBy('state')->pluck('state')->all())
            ->toBe(['hungry', 'idle', 'low_energy', 'playing', 'sick', 'sleeping'])
            ->and(app(PetMediaService::class)->payloadFor($a)['status'])->toBe('ready');

        // The next purchase of the same look: everything from the look, 0 $.
        config(['media.look_pool.size' => 1]);
        $b = lpBirth(lpPet());
        app(PetMediaService::class)->queueStateVideos($b);
        $before = lpAllCalls();
        $ledger = AiSpendLedger::count();

        lpPurchase($b);

        expect($b->fresh()->pet_look_id)->toBe($lookId)
            ->and(lpAllCalls())->toBe($before)
            ->and(AiSpendLedger::count())->toBe($ledger)
            ->and(PetMedia::where('pet_id', $b->id)->videos()->where('status', 'ready')->count())->toBe(6)
            ->and(app(PetMediaService::class)->payloadFor($b->fresh())['status'])->toBe('ready');
    });

    it('grows a look to the next stage once (edit of the earlier stage) and reuses it for the next pet; the growth album shows the look stages', function () {
        lpFakeFal();
        $a = lpPetWithBasicSet();
        $calls = lpFalCalls();

        $a->forceFill(['life_stage' => LifeStage::Young])->saveQuietly();
        expect(app(PetMediaService::class)->startStageTransition($a->fresh()))->toBe('started');
        lpCompleteLookVideos();

        // 1 edit (from the puppy image) + idle + sleeping of the young stage.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://fal.run/fal-ai/nano-banana-pro/edit'
            && str_starts_with((string) ($r['image_urls'][0] ?? ''), 'https://api.petprep.si/api/media/'));
        expect(lpFalCalls())->toBe($calls + 3);

        $lookYoung = PetMedia::where('pet_look_id', $a->pet_look_id)->images()->where('life_stage', 'young')->sole();
        expect($lookYoung->storage_path)->toBe("looks/{$a->pet_look_id}/reference-young-g1.jpg")
            ->and(PetMedia::where('pet_id', $a->id)->images()->value('storage_path'))->toBe($lookYoung->storage_path);

        // Pet B on the same look: puppy, then young — all from the look.
        config(['media.look_pool.size' => 1]);
        $b = lpBirth(lpPet());
        app(PetMediaService::class)->queueStateVideos($b);
        $before = lpAllCalls();

        $b->forceFill(['life_stage' => LifeStage::Young])->saveQuietly();
        expect(app(PetMediaService::class)->startStageTransition($b->fresh()))->toBe('started');

        expect(lpAllCalls())->toBe($before)
            ->and(PetMedia::where('pet_id', $b->id)->images()->value('storage_path'))->toBe($lookYoung->storage_path)
            ->and(PetMedia::where('pet_id', $b->id)->videos()->pluck('life_stage')->unique()->all())->toBe(['young'])
            ->and(PetMediaHistory::where('pet_id', $b->id)->value('storage_path'))->toBe("looks/{$a->pet_look_id}/reference-puppy-g1.jpg")
            ->and(PetMediaHistory::where('pet_id', $a->id)->value('storage_path'))->toBe("looks/{$a->pet_look_id}/reference-puppy-g1.jpg");

        $album = app(PetGrowthService::class)->albumFor($b->fresh())->entries;
        expect(collect($album)->pluck('life_stage')->all())->toBe(['puppy', 'young'])
            ->and($album[1]['is_current'])->toBeTrue();
        $this->get(lpPath($album[0]['image_url']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });
});

describe('prompts', function () {
    it('uses the unchanged dog templates for a look (= a bought pet with the same traits; DogMediaPromptSnapshotTest guards the bytes)', function () {
        config(['services.fal_ai.key' => null]);
        $pet = lpPet(null, 'mutt', 'young', 'adopted');
        $prompts = app(PetAppearancePrompt::class);
        $traits = $pet->look->traits();

        $bought = (new Pet)->forceFill(['breed_type' => 'mutt', 'origin' => 'bought', 'life_stage' => 'young', 'pet_dna' => $pet->pet_dna]);

        // The look ignores the adopter's origin (shared by bought and adopted pets).
        expect($prompts->stagedImagePrompt('mutt', $traits, LifeStage::Young))->toBe($prompts->imagePromptForPet($bought))
            ->and($prompts->stageEditPromptFor('mutt', $traits, LifeStage::Adult))->toBe($prompts->stageEditPrompt($bought, LifeStage::Adult))
            ->and($prompts->stagedImagePrompt('mutt', $traits, LifeStage::Young))->not->toContain('shelter');
    });
});

/* ─────────────────────────── Concurrency ─────────────────────────── */

describe('concurrency', function () {
    it('never makes two fal calls for one look image when two pets need it at once', function () {
        lpFakeFal();
        Queue::fake([GeneratePetReferenceImage::class, StorePetMedia::class]);
        config(['media.look_pool.size' => 1]);
        $a = lpPet();
        $b = lpPet();
        expect($b->pet_look_id)->toBe($a->pet_look_id);

        $media = app(PetMediaService::class);
        $media->generateReferenceImage($a);
        $media->generateReferenceImage($b); // the look row is claimed by A's job → B waits
        $media->generateReferenceImage($b); // a duplicate job changes nothing

        expect(lpFalCalls())->toBe(1);
        $row = PetMedia::whereNotNull('pet_look_id')->images()->sole();
        expect(PetMedia::where('pet_id', $b->id)->images()->sole())
            ->status->toBe('running')->look_media_id->toBe($row->id);

        $media->storeResult($row->id); // the download stores the look file once and links both pets

        foreach ([$a, $b] as $pet) {
            expect(PetMedia::where('pet_id', $pet->id)->images()->sole())
                ->status->toBe('ready')->storage_path->toBe($row->fresh()->storage_path)
                ->and($pet->fresh()->media_status)->toBe('ready');
        }
        expect(lpFalCalls())->toBe(1);
    });

    it('never submits one look video twice when two pets need it at once', function () {
        lpFakeFal();
        config(['media.look_pool.size' => 1]);
        $a = lpBirth(lpPet());
        $b = lpBirth(lpPet());
        $calls = lpFalCalls();

        Queue::fake([SubmitPetStateVideo::class]);
        $media = app(PetMediaService::class);
        $media->queueStateVideos($a);
        $media->queueStateVideos($b);
        $aIdle = PetMedia::where('pet_id', $a->id)->videos()->where('state', 'idle')->sole();
        $bIdle = PetMedia::where('pet_id', $b->id)->videos()->where('state', 'idle')->sole();

        $media->submitVideo($aIdle->id);
        $media->submitVideo($bIdle->id);
        $media->submitVideo($bIdle->id);

        expect(lpFalCalls())->toBe($calls + 1)
            ->and($bIdle->fresh())->status->toBe('running')->request_id->toBeNull();

        lpCompleteLookVideos();

        expect($aIdle->fresh()->status)->toBe('ready')
            ->and($bIdle->fresh()->status)->toBe('ready')
            ->and($bIdle->fresh()->storage_path)->toBe($aIdle->fresh()->storage_path)
            ->and(lpFalCalls())->toBe($calls + 1);
    });

    it('fails the waiting pets with the look (budget) and generates the look once on the retry', function () {
        lpFakeFal();
        Queue::fake([GeneratePetReferenceImage::class]);
        config(['media.look_pool.size' => 1, 'media.budget.daily_usd' => 0.01]);
        $a = lpPet();
        $b = lpPet();
        $media = app(PetMediaService::class);

        $media->generateReferenceImage($b);  // B waits for the look row …
        $media->generateReferenceImage($a);  // … A's attempt is refused by the budget

        expect(lpFalCalls())->toBe(0)
            ->and($a->fresh()->media_error)->toBe('budget_daily')
            ->and($b->fresh()->media_status)->toBe('failed')
            ->and(PetMedia::where('pet_id', $b->id)->images()->value('error_reason'))->toBe('budget_daily');

        config(['media.budget.daily_usd' => 50.0]);
        $media->generateReferenceImage($b->fresh());
        $media->generateReferenceImage($a->fresh());

        expect(lpFalCalls())->toBe(1)
            ->and($a->fresh()->media_status)->toBe('ready')
            ->and($b->fresh()->media_status)->toBe('ready');
    });
});

/* ─────────────────────────── Deletion, export, admin ─────────────────────────── */

describe('deletion and export', function () {
    it('keeps the shared look files when a pet and a whole family are deleted; the other pet still plays and exports them', function () {
        lpFakeFal();
        config(['media.look_pool.size' => 1]);
        $a = lpPetWithBasicSet();
        $bParent = createParentUser();
        $b = lpBirth(lpPet($bParent));
        app(PetMediaService::class)->queueStateVideos($b);
        $files = Storage::disk('pet_media')->allFiles("looks/{$a->pet_look_id}");
        expect($files)->toHaveCount(3);

        // GDPR: the whole family of A goes (pets, media rows, files of its pets).
        app(AccountDeletionService::class)->deleteFamily($a->family);

        expect(Pet::find($a->id))->toBeNull()
            ->and(Storage::disk('pet_media')->allFiles("looks/{$a->pet_look_id}"))->toBe($files)
            ->and(PetMedia::whereNotNull('pet_look_id')->count())->toBe(3)
            ->and(PetLook::count())->toBe(1);

        $media = app(PetMediaService::class)->payloadFor($b->fresh());
        $this->get(lpPath($media['reference_image_url']))->assertOk();
        $this->get(lpPath($media['videos']->idle))->assertOk();

        // The export of B's family still carries B's media links (its own signed URLs).
        $export = app(AccountExportService::class)->exportFor($bParent);
        $pet = collect($export['pets'])->firstWhere('id', $b->id);
        expect($pet['media'])->toHaveCount(3)
            ->and(collect($pet['media'])->pluck('url')->every(fn ($url) => str_contains($url, '/api/media/')))->toBeTrue();

        // Deleting B too never touches the look files.
        $b->fresh()->delete();
        expect(Storage::disk('pet_media')->allFiles("looks/{$a->pet_look_id}"))->toBe($files);
    });

    it('serves look files through Caddy (safe relative path)', function () {
        expect(preg_match(PetMediaController::SAFE_RELATIVE_PATH, 'looks/12/reference-puppy-g1.jpg'))->toBe(1)
            ->and(preg_match(PetMediaController::SAFE_RELATIVE_PATH, 'looks/12/idle-young-g2.mp4'))->toBe(1)
            ->and(preg_match(PetMediaController::SAFE_RELATIVE_PATH, 'looks/../.env'))->toBe(0)
            ->and(preg_match(PetMediaController::SAFE_RELATIVE_PATH, 'looks/x/a.jpg'))->toBe(0);
    });

    it('refuses an admin regenerate of a pool pet (the media belong to the shared look)', function () {
        lpFakeFal();
        $a = lpPetWithBasicSet();
        $slot = PetMedia::where('pet_id', $a->id)->images()->sole();

        expect(app(PetMediaService::class)->regenerate($slot))->toBeFalse();
    });

    it('plans 0 $ for a pool pet whose look already has the media', function () {
        lpFakeFal();
        $a = lpPetWithBasicSet();
        config(['media.look_pool.size' => 1]);
        Queue::fake([GeneratePetReferenceImage::class]);
        $b = lpBirth(lpPet());

        expect(app(PetMediaService::class)->planMissing($b))->toMatchArray(['image' => 'generate', 'cost_usd' => 0.0]);
    });

    it('reports the pool size and the one-off fill cost', function () {
        config(['services.fal_ai.key' => null]);
        lpPet();

        expect(app(PetLookPoolService::class)->counts())->toBe(['mutt' => 1])
            ->and(app(MediaLabService::class)->lookPoolFillCostUsd(Species::Dog))
            ->toMatchArray(['looks' => 20, 'stages' => 4, 'per_stage_usd' => 1.27, 'usd' => 101.6]);
    });
});
