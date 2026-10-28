<?php

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
use App\Enums\Species;
use App\Filament\Pages\StateMediaUpgrade;
use App\Jobs\UpgradePetsToStateMedia;
use App\Models\AiSpendLedger;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetMediaService;
use App\Services\Media\ReferenceImageRetryService;
use App\Services\Media\StateMediaUpgradePlanner;
use App\Services\PairingService;
use App\Services\Results\PetProfileChoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M5-T16 — superadmin bulk »Upgrade existing pets to new media« (David 2026-10-22)
|--------------------------------------------------------------------------
|
| Existing paid pets never get the version 3 media automatically. The admin first
| sees a calculation (no fal call), grouped per shared look + stage (each look item
| priced once), then upgrades with a click: queued chunk jobs → generateMissing
| (upgradeStateMedia) → one fal call per look item, both switches and the AI budget
| respected.
*/

const BU_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

const BU_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

/**
 * 5 pose images (0.15 $) + 11 school / ball phase clips (3 s × 0.112 $) + 4 school performing
 * clips (5 s × 0.112 $ since David 2026-10-23) of a dog with training.
 */
const BU_V2_TO_V3_DOG_USD = 6.69;

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
    buFakeFal();
});

function buFakeFal(): void
{
    fakeFalJwks();
    $n = 0;
    Http::fake([
        // Fix 2026-10-28: key / pose images are queued (result via completeFalKeyImages()).
        'queue.fal.run/fal-ai/nano-banana-pro/edit*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "bu-edit-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/bu-edit-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/bu-edit-{$n}"]);
        },
        'fal.run/fal-ai/nano-banana-pro' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/bu/ref-{$n}.jpg"]]]);
        },
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "bu-req-{$n}"]);
        },
        'v3.fal.media/files/bu/*.jpg' => fn () => Http::response(BU_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/bu/*.mp4' => fn () => Http::response(BU_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** fal model calls so far (sync images + queue submits; downloads not counted). */
function buCalls(): int
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://fal.run/') || str_starts_with($r->url(), 'https://queue.fal.run/'))->count();
}

function buSpent(): float
{
    return round((float) AiSpendLedger::query()->counted()->sum('cost_usd'), 2);
}

function buCompleteClips(): void
{
    for ($round = 0; $round < 10; $round++) {
        // Fix 2026-10-28: queued key / pose images first (a stored one queues its clips).
        $keys = completeFalKeyImages('https://v3.fal.media/files/bu/');
        $rows = PetMedia::query()->whereIn('kind', ['video', 'transition', 'phase'])
            ->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();

        if ($rows->isEmpty() && $keys === 0) {
            return;
        }

        foreach ($rows as $row) {
            sendFalWebhook([
                'request_id' => $row->request_id,
                'status' => 'OK',
                'payload' => ['video' => ['url' => "https://v3.fal.media/files/bu/{$row->request_id}.mp4"]],
            ])->assertOk();
        }
    }
}

/**
 * An EXISTING paid pet: born on media version `$version` with that version's set already
 * made (complete) — the situation of production pets before the upgrade.
 */
function buExistingPet(int $version = 2, string $breed = 'labrador_retriever', ?string $colour = 'black'): Pet
{
    $parent = createParentUser();
    $family = app(FamilyService::class)->ensureFamilyFor($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $type = BreedType::from($breed);
    $profile = new PetProfileChoice($type, PetOrigin::Bought, LifeStage::Young, ['training'], $type->species(), $colour);

    $pet = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, PetPlan::Challenge)['pet'])->fresh();
    $pet->forceFill(['plan' => PetPlan::Challenge, 'challenge_paid_at' => now(), 'challenge_paid_source' => ChallengePaidSource::Purchase, 'state_media_version' => $version])->saveQuietly();

    $pet = $pet->fresh();
    $pet->giveBirth(now());
    $pet->save();
    app(PetMediaService::class)->queueStateVideos($pet->fresh());
    buCompleteClips();

    return $pet->fresh();
}

function buAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

function buPlanner(): StateMediaUpgradePlanner
{
    return app(StateMediaUpgradePlanner::class);
}

/** Preview → upgrade every listed pet (the page's »Upgrade«), queue sync. */
function buUpgradeAll(?User $admin = null, bool $onlyComplete = false): array
{
    $p = buPlanner()->preview(onlyComplete: $onlyComplete);
    buPlanner()->upgrade($admin ?? buAdmin(), ['only_complete' => $onlyComplete], $p['pet_ids'], $p['cost_usd']);
    Cache::lock(StateMediaUpgradePlanner::LOCK_KEY)->forceRelease();
    buCompleteClips();

    return $p;
}

function buSwitch(string $key, bool $on): void
{
    AppSetting::updateOrCreate(['key' => $key], ['value' => ['enabled' => $on]]);
    Cache::forget(AppSettingsService::cacheKey($key));
}

describe('preview (no spend)', function () {
    it('makes zero fal calls, writes nothing and groups pets of one look; each look item priced once', function () {
        $a = buExistingPet();
        $b = buExistingPet();
        $calls = buCalls();
        $spent = buSpent();
        $ledgerRows = AiSpendLedger::count();

        $p = buPlanner()->preview();

        expect(buCalls())->toBe($calls)
            ->and(buSpent())->toBe($spent)
            ->and(AiSpendLedger::count())->toBe($ledgerRows)
            ->and($a->fresh()->state_media_version)->toBe(2)
            ->and($p['eligible'])->toBe(2)
            ->and($p['pets'])->toBe(2)
            ->and($p['looks'])->toBe(1)
            ->and($p['blocked'])->toBeNull()
            ->and($p['groups'][0])->toMatchArray(['look_id' => $a->pet_look_id, 'breed' => 'labrador_retriever', 'colour' => 'black', 'stage' => 'young', 'unique' => false, 'pets' => 2])
            ->and($p['groups'][0]['pet_ids'])->toEqualCanonicalizing([$a->id, $b->id])
            // a v2 look has key images, loops and transitions already: only poses + phase clips
            ->and($p['groups'][0]['items'])->toBe(['image' => 0, 'keyframes' => 0, 'loops' => 0, 'transitions' => 0, 'poses' => 5, 'phases' => 15])
            ->and($p['cost_usd'])->toBe(BU_V2_TO_V3_DOG_USD)
            // matches the single-pet plan (planMissing) of one pet of the look
            ->and(round(app(PetMediaService::class)->planMissing($a->fresh(), upgradeStateMedia: true)['cost_usd'], 2))->toBe(BU_V2_TO_V3_DOG_USD);
    });

    it('prices pets with unique media per pet', function () {
        useUniquePetMedia(true);
        $a = buExistingPet();
        $b = buExistingPet();

        $p = buPlanner()->preview();

        expect($p['looks'])->toBe(2)
            ->and(collect($p['groups'])->pluck('unique')->unique()->all())->toBe([true])
            ->and(collect($p['groups'])->pluck('pet_id')->all())->toEqualCanonicalizing([$a->id, $b->id])
            ->and($p['cost_usd'])->toBe(13.37) // 2 × 6.686 $ (unrounded per pet)
            ->and($p['groups'][0]['cost_usd'])->toBe(round(app(PetMediaService::class)->planMissing($a->fresh(), upgradeStateMedia: true)['cost_usd'], 4));
    });

    it('compares the total with the budget left and says how many budget days it takes', function () {
        buExistingPet();
        config(['media.budget.daily_usd' => 2.0]);

        $b = buPlanner()->preview()['budget'];

        // the set-up already spent today's 2 $: today does not count (0 $ left), 6.69 $ at 2 $ / day = 4 days
        expect($b['left_today_usd'])->toBe(0.0)
            ->and($b['today_share_percent'])->toBeNull()
            ->and($b['days'])->toBe(4)
            ->and($b['fits_today'])->toBeFalse()
            ->and($b['daily_cap_usd'])->toBe(2.0);

        config(['media.budget.daily_usd' => 25.0]);
        expect(buPlanner()->preview()['budget'])->toMatchArray(['days' => 1, 'fits_today' => true]);
    });

    it('filters by species and breed', function () {
        buExistingPet();

        expect(buPlanner()->preview(breed: BreedType::Beagle)['pets'])->toBe(0)
            ->and(buPlanner()->preview(species: Species::Cat)['pets'])->toBe(0)
            ->and(buPlanner()->preview(species: Species::Dog, breed: BreedType::LabradorRetriever)['pets'])->toBe(1);
    });

    it('the console dry run prints the same calculation without a fal call', function () {
        buExistingPet();
        buExistingPet();
        $calls = buCalls();

        $this->artisan('media:backfill', ['--state-media' => true, '--dry-run' => true])
            ->expectsOutputToContain('2 pet(s) in 1 look(s) (0 already complete), estimated $6.69')
            ->assertSuccessful();

        expect(buCalls())->toBe($calls);
    });
});

describe('upgrade', function () {
    it('N pets sharing one look → one fal call per look item; all move to version 3 with the shared files', function () {
        $pets = [buExistingPet(), buExistingPet(), buExistingPet()];
        $calls = buCalls();
        $spent = buSpent();

        buUpgradeAll();

        // 5 pose images + 15 phase clips — once for the look, not per pet
        expect(buCalls() - $calls)->toBe(20)
            ->and(round(buSpent() - $spent, 2))->toBe(BU_V2_TO_V3_DOG_USD)
            ->and(PetMedia::where('pet_look_id', $pets[0]->pet_look_id)->where('kind', 'phase')->count())->toBe(15);

        foreach ($pets as $pet) {
            expect($pet->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION)
                ->and(PetMedia::where('pet_id', $pet->id)->where('kind', 'phase')->where('status', 'ready')->count())->toBe(15);
        }

        expect(buPlanner()->preview()['pets'])->toBe(0);
    });

    it('a look that is already complete costs 0 $ and upgrades without any fal call', function () {
        buExistingPet(version: MediaEntitlementService::CURRENT_MEDIA_VERSION); // the look has every v3 item
        $old = buExistingPet(version: 2);
        $calls = buCalls();

        $p = buPlanner()->preview(onlyComplete: true);
        expect($p['groups'])->toHaveCount(1)
            ->and($p['groups'][0]['pet_ids'])->toBe([$old->id])
            ->and($p['cost_usd'])->toBe(0.0)
            ->and($p['complete_looks'])->toBe(1);

        buUpgradeAll(onlyComplete: true);

        expect(buCalls())->toBe($calls)
            ->and($old->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION)
            ->and(PetMedia::where('pet_id', $old->id)->where('kind', 'phase')->where('status', 'ready')->count())->toBe(15);
    });

    it('»only complete« leaves looks that would cost money untouched', function () {
        $paying = buExistingPet(version: 2, breed: 'beagle', colour: 'tricolour');
        $calls = buCalls();

        expect(buPlanner()->preview(onlyComplete: true)['pets'])->toBe(0);
        buUpgradeAll(onlyComplete: true);

        expect(buCalls())->toBe($calls)->and($paying->fresh()->state_media_version)->toBe(2);
    });

    it('state media switched off → preview blocked, nothing generated, no pet moved', function () {
        $pet = buExistingPet();
        $calls = buCalls();
        buSwitch(AppSettingsService::STATE_MEDIA_KEY, false);

        $p = buPlanner()->preview();
        expect($p['blocked'])->toBe('state_media_off')->and($p['pets'])->toBe(0);

        // a chunk queued before the switch went off moves nothing either
        Queue::fake([UpgradePetsToStateMedia::class]);
        expect(buPlanner()->upgrade(buAdmin(), [], [$pet->id], 6.69))->toMatchArray(['status' => 'blocked', 'reason' => 'state_media_off']);
        Queue::assertNothingPushed();
        expect(buPlanner()->upgradeChunk([$pet->id]))->toBe(['done' => 0, 'skipped' => 1, 'failed' => 0])
            ->and(buCalls())->toBe($calls)
            ->and($pet->fresh()->state_media_version)->toBe(2);
    });

    it('»School & game clips« off → the pets move to the new set but no pose / phase clip is made', function () {
        $pet = buExistingPet();
        $calls = buCalls();
        buSwitch(AppSettingsService::PHASE_CLIPS_KEY, false);

        $p = buPlanner()->preview();
        expect($p['phase_clips'])->toBeFalse()->and($p['cost_usd'])->toBe(0.0);

        buUpgradeAll();

        expect(buCalls())->toBe($calls)
            ->and($pet->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION)
            ->and(PetMedia::where('pet_id', $pet->id)->whereIn('kind', ['pose', 'phase'])->whereNotIn('status', ['pending'])->count())->toBe(0);
    });

    it('non-eligible pets are never listed or touched (unpaid, game over, unborn, legacy, already v3, inactive)', function () {
        $unpaid = buExistingPet();
        $unpaid->forceFill(['challenge_paid_source' => null, 'challenge_paid_at' => null])->saveQuietly();
        $over = buExistingPet();
        $over->forceFill(['is_game_over' => true])->saveQuietly();
        $unborn = buExistingPet();
        $unborn->forceFill(['born_at' => null])->saveQuietly();
        $legacy = buExistingPet();
        $legacy->forceFill(['arrival_age_months' => null, 'life_stage' => null])->saveQuietly();
        $current = buExistingPet(version: MediaEntitlementService::CURRENT_MEDIA_VERSION);
        $calls = buCalls();

        $ids = [$unpaid->id, $over->id, $unborn->id, $legacy->id, $current->id];
        expect(buPlanner()->preview()['eligible'])->toBe(0)
            ->and(buPlanner()->upgradeChunk($ids))->toBe(['done' => 0, 'skipped' => 5, 'failed' => 0])
            ->and(buCalls())->toBe($calls);

        foreach ([$unpaid, $over, $unborn, $legacy] as $pet) {
            expect($pet->fresh()->state_media_version)->toBe(2);
        }
    });

    it('a capped budget refuses the rest (retried nightly) — nothing is overspent', function () {
        $pet = buExistingPet();
        $spent = buSpent();
        config(['media.budget.daily_usd' => $spent + 1.0]);

        buUpgradeAll();

        expect(buSpent())->toBeLessThanOrEqual($spent + 1.0)
            ->and($pet->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION)
            ->and(PetMedia::whereNotNull('pet_look_id')->whereIn('kind', ['pose', 'phase'])->where('status', 'failed')->where('error_reason', 'budget_daily')->exists())->toBeTrue();
    });
});

describe('admin page', function () {
    it('is only for superadmins', function () {
        $this->actingAs(buAdmin())->get('/admin/state-media-upgrade')->assertOk()->assertSee('Upgrade existing pets to new media');
        $this->actingAs(User::factory()->parent()->create())->get('/admin/state-media-upgrade')->assertForbidden();
    });

    it('shows the calculation, confirms with the total, queues chunk jobs and audits who ran it', function () {
        $a = buExistingPet();
        $b = buExistingPet();
        $admin = buAdmin();
        $this->actingAs($admin);
        Queue::fake([UpgradePetsToStateMedia::class]);

        Livewire::test(StateMediaUpgrade::class)
            ->assertSee('$6.69')
            ->mountAction('upgrade')
            ->assertSee('2 pet(s) in 1 look(s), estimated $6.69')
            ->callMountedAction()
            ->assertNotified();

        Queue::assertPushed(UpgradePetsToStateMedia::class, fn ($job) => collect($job->petIds)->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all());
        $audit = AppSettingChange::where('key', StateMediaUpgradePlanner::AUDIT_KEY)->sole();
        expect($audit->user_id)->toBe($admin->id)
            ->and($audit->new['pets'])->toBe(2)
            ->and((float) $audit->new['estimate_usd'])->toBe(BU_V2_TO_V3_DOG_USD)
            // queueing alone moves no pet and spends nothing
            ->and($a->fresh()->state_media_version)->toBe(2);
    });

    it('chunks large upgrades', function () {
        Queue::fake([UpgradePetsToStateMedia::class]);
        $pet = buExistingPet();
        $ids = array_merge([$pet->id], range(900001, 900000 + StateMediaUpgradePlanner::CHUNK));

        buPlanner()->dispatchChunks($ids);

        Queue::assertPushed(UpgradePetsToStateMedia::class, 2);
    });
});

describe('QA round (#344)', function () {
    it('M1: a pet that became eligible after the confirmation opened → refused »numbers changed«, nothing queued', function () {
        $a = buExistingPet();
        $admin = buAdmin();
        $this->actingAs($admin);
        Queue::fake([UpgradePetsToStateMedia::class]);

        $page = Livewire::test(StateMediaUpgrade::class)->mountAction('upgrade')
            ->assertSee('1 pet(s) in 1 look(s), estimated $6.69');
        $frozen = $page->get('confirmed');
        expect($frozen['pet_ids'])->toBe([$a->id]);

        buExistingPet(); // a new eligible pet of the same look (0 $ more — the look is shared)

        $page->callMountedAction()->assertNotified('Numbers changed, check again');

        Queue::assertNothingPushed();
        expect(AppSettingChange::where('key', StateMediaUpgradePlanner::AUDIT_KEY)->exists())->toBeFalse()
            ->and($a->fresh()->state_media_version)->toBe(2);
    });

    it('M1: a tampered confirmation (more pet ids than were shown) is refused', function () {
        $a = buExistingPet();
        $b = buExistingPet();
        $this->actingAs(buAdmin());
        Queue::fake([UpgradePetsToStateMedia::class]);

        $page = Livewire::test(StateMediaUpgrade::class)->mountAction('upgrade');
        $page->set('confirmed.pet_ids', [$a->id, $b->id, 999999])->callMountedAction()->assertNotified('Numbers changed, check again');

        Queue::assertNothingPushed();
    });

    it('M1: a total above the shown one is refused; a smaller set queues only the intersection', function () {
        $a = buExistingPet();
        $b = buExistingPet();
        Queue::fake([UpgradePetsToStateMedia::class]);

        expect(buPlanner()->upgrade(buAdmin(), [], [$a->id, $b->id], 1.00))->toMatchArray(['status' => 'changed'])
            ->and(buPlanner()->upgrade(buAdmin(), [], [$a->id], BU_V2_TO_V3_DOG_USD))->toMatchArray(['status' => 'changed']);
        Queue::assertNothingPushed();

        // $b left the eligible set (game over) after the preview: only $a is queued
        $b->forceFill(['is_game_over' => true])->saveQuietly();
        $r = buPlanner()->upgrade(buAdmin(), [], [$a->id, $b->id], BU_V2_TO_V3_DOG_USD);

        expect($r)->toMatchArray(['status' => 'queued', 'pets' => 1]);
        Queue::assertPushed(UpgradePetsToStateMedia::class, fn ($job) => $job->petIds === [$a->id] && $job->onlyComplete === false);
        $audit = AppSettingChange::where('key', StateMediaUpgradePlanner::AUDIT_KEY)->sole()->new;
        expect($audit)->toMatchArray(['pets' => 1, 'shown_pets' => 2, 'recomputed_pets' => 1, 'pet_ids' => [$a->id]])
            ->and((float) $audit['estimate_usd'])->toBe(BU_V2_TO_V3_DOG_USD)
            ->and((float) $audit['recomputed_usd'])->toBe(BU_V2_TO_V3_DOG_USD);
    });

    it('M1: »only complete« chunks skip a pet whose fresh plan costs money', function () {
        $paying = buExistingPet(version: 2, breed: 'beagle', colour: 'tricolour');
        $calls = buCalls();

        expect(buPlanner()->upgradeChunk([$paying->id], onlyComplete: true))->toBe(['done' => 0, 'skipped' => 1, 'failed' => 0])
            ->and(buCalls())->toBe($calls)
            ->and($paying->fresh()->state_media_version)->toBe(2);
    });

    it('m6: a double submission is refused while the lock holds', function () {
        $a = buExistingPet();
        Queue::fake([UpgradePetsToStateMedia::class]);

        expect(buPlanner()->upgrade(buAdmin(), [], [$a->id], BU_V2_TO_V3_DOG_USD)['status'])->toBe('queued')
            ->and(buPlanner()->upgrade(buAdmin(), [], [$a->id], BU_V2_TO_V3_DOG_USD)['status'])->toBe('busy');
        Queue::assertPushed(UpgradePetsToStateMedia::class, 1);
    });

    it('M2: a pet whose image is behind its life stage is priced with the full set of its new stage — preview = real spend', function () {
        $pet = buExistingPet();
        $pet->forceFill(['life_stage' => LifeStage::Adult])->saveQuietly();

        $p = buPlanner()->preview();
        $g = $p['groups'][0];
        expect($g)->toMatchArray(['stage' => 'adult', 'stage_remake' => 1])
            ->and($g['items']['image'])->toBe(1)
            ->and($g['items']['keyframes'])->toBeGreaterThan(0)
            ->and($g['items']['loops'])->toBeGreaterThan(0)
            ->and($g['items']['phases'])->toBe(15)
            ->and($p['cost_usd'])->toBeGreaterThan(BU_V2_TO_V3_DOG_USD);

        $spent = buSpent();
        buUpgradeAll();

        expect(round(buSpent() - $spent, 2))->toBe($p['cost_usd'])
            ->and($pet->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION);
    });

    it('m8: a version 1 pet — preview = real spend', function () {
        $pet = buExistingPet(version: 1);

        $p = buPlanner()->preview();
        expect($p['cost_usd'])->toBeGreaterThan(BU_V2_TO_V3_DOG_USD);

        $spent = buSpent();
        buUpgradeAll();

        expect(round(buSpent() - $spent, 2))->toBe($p['cost_usd'])
            ->and($pet->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION);
    });

    it('m8: unique-media pets — preview = real spend', function () {
        useUniquePetMedia(true);
        buExistingPet();
        buExistingPet();

        $p = buPlanner()->preview();
        $spent = buSpent();
        buUpgradeAll();

        expect($p['cost_usd'])->toBe(13.37) // 2 × 6.686 $ (unrounded per pet)
            ->and(round(buSpent() - $spent, 2))->toBe($p['cost_usd']);
    });

    it('m1: an exhausted fal balance blocks the preview and the upgrade', function () {
        $pet = buExistingPet();
        Cache::put(FalGateway::BALANCE_FLAG_KEY, now()->toIso8601String());
        Queue::fake([UpgradePetsToStateMedia::class]);

        expect(buPlanner()->preview()['blocked'])->toBe('fal_balance')
            ->and(buPlanner()->upgrade(buAdmin(), [], [$pet->id], BU_V2_TO_V3_DOG_USD))->toMatchArray(['status' => 'blocked', 'reason' => 'fal_balance']);
        Queue::assertNothingPushed();
        $this->actingAs(buAdmin())->get('/admin/state-media-upgrade')->assertSee('The fal.ai balance is flagged as exhausted');
    });

    it('m3: a pet whose queueing fails is rolled back to its media version; the others go on', function () {
        $a = buExistingPet();
        $b = buExistingPet();
        $ctor = (new ReflectionClass(PetMediaService::class))->getConstructor();
        $media = Mockery::mock(PetMediaService::class, array_map(fn (ReflectionParameter $p) => app($p->getType()->getName()), $ctor->getParameters()))->makePartial();
        $media->shouldReceive('generateMissing')->andReturnUsing(function (Pet $pet) use ($a) {
            $pet->forceFill(['state_media_version' => MediaEntitlementService::CURRENT_MEDIA_VERSION])->saveQuietly();
            if ($pet->id === $a->id) {
                throw new RuntimeException('queue down');
            }

            return ['image' => false, 'videos' => 0];
        });
        app()->instance(PetMediaService::class, $media);

        expect(app(StateMediaUpgradePlanner::class)->upgradeChunk([$a->id, $b->id]))->toBe(['done' => 1, 'skipped' => 0, 'failed' => 1])
            ->and($a->fresh()->state_media_version)->toBe(2)
            ->and($b->fresh()->state_media_version)->toBe(MediaEntitlementService::CURRENT_MEDIA_VERSION);
    });

    it('M3: the console real run queues exactly the dry run (same pets, same spend, audited)', function () {
        buExistingPet();
        buExistingPet();
        $calls = buCalls();
        $spent = buSpent();

        $this->artisan('media:backfill', ['--state-media' => true, '--dry-run' => true, '--limit' => 1])
            ->expectsOutputToContain('2 pet(s) in 1 look(s) (0 already complete), estimated $6.69 (all 2 eligible; --limit is ignored)')
            ->assertSuccessful();
        expect(buCalls())->toBe($calls);

        $this->artisan('media:backfill', ['--state-media' => true, '--limit' => 1])
            ->expectsOutputToContain('Queued the state media upgrade of 2 pet(s), estimated $6.69')
            ->assertSuccessful();
        buCompleteClips();

        $audit = AppSettingChange::where('key', StateMediaUpgradePlanner::AUDIT_KEY)->sole();
        expect(round(buSpent() - $spent, 2))->toBe(BU_V2_TO_V3_DOG_USD)
            ->and($audit->user_id)->toBeNull()
            ->and($audit->new['filters']['source'])->toBe('console')
            ->and(buPlanner()->preview()['pets'])->toBe(0);
    });

    it('m5: the days estimate stops at the monthly cap and resumes on the 1st; confirmation shows today\'s share', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
        buExistingPet();
        AiSpendLedger::query()->delete(); // nothing spent yet today / this month
        config(['media.budget.daily_usd' => 2.0, 'media.budget.monthly_usd' => 3.0]);

        // Oct 30: 2 $ · Oct 31: 1 $ (month cap) · Nov 1: 2 $ · Nov 2: 1 $ (month cap) · Dec 1: 0.69 $ = 33 days
        $b = buPlanner()->preview()['budget'];
        expect($b['days'])->toBe(33)
            ->and($b['today_usd'])->toBe(2.0)
            ->and($b['today_share_percent'])->toBe(100)
            ->and($b['over_month'])->toBeTrue();

        config(['media.budget.daily_usd' => 20.0, 'media.budget.monthly_usd' => 100.0]);
        expect(buPlanner()->preview()['budget'])->toMatchArray(['days' => 1, 'today_share_percent' => 33, 'today_usd' => 6.69]);

        // 100 $ in 100 slots (1 $ each): the nightly retry is not the limit — today 20 $ + 4 × 20 $
        expect(buPlanner()->budgetFor(100.0, ReferenceImageRetryService::DEFAULT_LIMIT))->toMatchArray(['days' => 5])
            // 100 $ in 10 × DEFAULT_LIMIT slots (0.10 $ each): one nightly retry re-queues 10 $ — today 20 $ + 8 × 10 $
            ->and(buPlanner()->budgetFor(100.0, 10 * ReferenceImageRetryService::DEFAULT_LIMIT))->toMatchArray(['days' => 9]);
    });
});
