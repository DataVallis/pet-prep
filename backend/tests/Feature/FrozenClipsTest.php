<?php

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPhaseClip;
use App\Enums\PetPlan;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Filament\Resources\PetResource\RelationManagers\MediaRelationManager;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\FalAiService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\FalGateway;
use App\Services\Media\FrozenClipRegenerator;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetMediaService;
use App\Services\PairingService;
use App\Services\Results\PetProfileChoice;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Fix 2026-10-23 — frozen same-frame clips (David »uredi«)
|--------------------------------------------------------------------------
|
| Kling 3.0 Pro given the same image as start AND end frame returned an
| almost frozen clip (production pet #8 »Suki«, Dobermann puppy, v3). Loops
| and phase clips whose start pose = end pose are now sent with the START
| frame only and a clearly-visible-motion prompt; transitions / performing /
| ball running / fetching keep their end frame. `media:regenerate-frozen`
| remakes the clips already stored the frozen way. Never a real fal call.
*/

const FZ_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

const FZ_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

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
    useUniquePetMedia(true);
    seedBreedConfigs();
    seedStageParams();
});

function fzFakeFal(): void
{
    fakeFalJwks();
    $n = 0;
    Http::fake([
        // Life-stage growth edits (editReferenceImage) stay sync.
        'fal.run/fal-ai/nano-banana-pro/edit' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/fz/edit-{$n}.jpg"]]]);
        },
        // Fix 2026-10-28: key / pose images are queued (result via completeFalKeyImages()).
        'queue.fal.run/fal-ai/nano-banana-pro/edit*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "fz-edit-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/fz-edit-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/fz-edit-{$n}"]);
        },
        'fal.run/fal-ai/nano-banana-pro' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/fz/ref-{$n}.jpg"]]]);
        },
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "fz-req-{$n}"]);
        },
        'v3.fal.media/files/fz/*.jpg' => fn () => Http::response(FZ_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/fz/*.mp4' => fn () => Http::response(FZ_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** @return Collection<int, Request> */
function fzQueueCalls(): Collection
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/'))
        ->map(fn (array $pair) => $pair[0])->values();
}

function fzCompleteClips(): void
{
    for ($round = 0; $round < 10; $round++) {
        // Fix 2026-10-28: queued key / pose images first (a stored one queues its clips).
        $keys = completeFalKeyImages('https://v3.fal.media/files/fz/');
        $rows = PetMedia::query()->whereIn('kind', ['video', 'transition', 'phase'])
            ->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();

        if ($rows->isEmpty() && $keys === 0) {
            return;
        }

        foreach ($rows as $row) {
            sendFalWebhook([
                'request_id' => $row->request_id,
                'status' => 'OK',
                'payload' => ['video' => ['url' => "https://v3.fal.media/files/fz/{$row->request_id}.mp4"]],
            ])->assertOk();
        }
    }
}

/** A Suki-like pet: paid Dobermann puppy, v3, training + behaviour videos (8 state videos). */
function fzPet(string $breed = 'dobermann', ?string $colour = 'black-rust'): Pet
{
    $parent = createParentUser();
    $family = app(FamilyService::class)->ensureFamilyFor($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $type = BreedType::from($breed);
    $profile = new PetProfileChoice($type, PetOrigin::Bought, LifeStage::Puppy, ['training', 'behaviour_events'], $type->species(), $colour);

    $pet = DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, PetPlan::Challenge)['pet'])->fresh();
    $pet->forceFill(['plan' => PetPlan::Challenge, 'challenge_paid_at' => now(), 'challenge_paid_source' => ChallengePaidSource::Purchase])->saveQuietly();
    $pet->giveBirth(now());
    $pet->save();
    app(PetMediaService::class)->queueStateVideos($pet->fresh());

    return $pet->fresh();
}

/** Turn the stored clips into what production made before the fix (end frame = start frame). */
function fzMakeFrozen(?Pet $pet = null): void
{
    $same = array_map(fn (PetPhaseClip $c) => $c->value, PetPhaseClip::sameFrameCases());
    $scope = fn ($q) => $pet !== null ? $q->where('pet_id', $pet->id) : $q;
    PetMedia::query()->tap($scope)->where('kind', 'video')->where('recipe', 'start_only')->update(['recipe' => 'loop', 'stored_recipe' => 'loop']);
    PetMedia::query()->tap($scope)->where('kind', 'phase')->whereIn('state', $same)->update(['recipe' => null, 'stored_recipe' => null]);
}

describe('submit: same frame → start frame only', function () {
    it('loops and same-pose phase clips go WITHOUT end_image_url; transitions / performing / ball running WITH it', function () {
        fzFakeFal();
        $pet = fzPet();
        fzCompleteClips();

        $queue = fzQueueCalls();
        $phase = fn (string $needle) => $queue->first(fn (Request $r) => str_contains((string) $r['prompt'], $needle));

        // 8 state loops: start frame only, motion wording, no "Subtle, slow", no "fast motion" negative.
        $loops = $queue->filter(fn (Request $r) => str_contains((string) $r['prompt'], 'so it can loop'))->values();
        expect($loops)->toHaveCount(8);
        foreach ($loops as $r) {
            expect($r->data())->not->toHaveKey('end_image_url')
                ->and((string) $r['prompt'])->toContain('clearly visible')->not->toContain('Subtle, slow')
                ->and((string) $r['negative_prompt'])->not->toContain('fast motion')->toContain('frozen frame');
        }
        expect((string) $phase('asleep and staying curled up')['prompt'])->toContain('rising and falling with each breath');

        // Same-pose phase clips (school attention + success, ball waiting): start only.
        foreach (['looks up at the camera eagerly', 'stays sitting proudly', 'bouncing excitedly on the front paws, the tail wagging fast, ready to run'] as $needle) {
            $r = $phase($needle);
            expect($r)->not->toBeNull($needle)
                ->and($r->data())->not->toHaveKey('end_image_url')
                ->and((string) $r['prompt'])->toContain('ends close to the starting pose')->not->toContain('frame of the end image');
        }

        // Different frames keep the end frame.
        foreach (['and sits down in the same spot', 'sprints out of the frame', 'trots back into the frame'] as $needle) {
            $r = $phase($needle);
            expect($r['end_image_url'])->not->toBeNull()->not->toBe($r['start_image_url'])
                ->and((string) $r['prompt'])->toContain('ending exactly in the frame of the end image');
        }
        $transition = $phase('pose of the end image');
        expect($transition['end_image_url'])->not->toBe($transition['start_image_url']);

        // recipe records it.
        $recipes = PetMedia::where('pet_id', $pet->id)->whereIn('kind', ['video', 'phase', 'transition'])->get()
            ->groupBy(fn (PetMedia $m) => $m->kind.':'.($m->recipe ?? 'null'))->map->count()->sortKeys()->all();
        expect($recipes)->toBe(['phase:null' => 6, 'phase:start_only' => 9, 'transition:null' => 2, 'video:start_only' => 8]);

        // The payload still lists the loops (the app loops them natively).
        expect(app(PetMediaService::class)->payloadFor($pet->fresh())['loops'])->toHaveCount(8);
    });

    it('the one helper: same frame or identical URLs → no end frame', function () {
        expect(FalAiService::endFrameUrl('a', 'a', false))->toBeNull()
            ->and(FalAiService::endFrameUrl('a', 'b', true))->toBeNull()
            ->and(FalAiService::endFrameUrl('a', 'b', false))->toBe('b')
            ->and(FalAiService::endFrameUrl('a', null, false))->toBeNull()
            ->and(array_map(fn (PetPhaseClip $c) => $c->value, PetPhaseClip::sameFrameCases()))->toBe([
                'school_sit_attention', 'school_sit_success', 'school_come_attention', 'school_come_success',
                'school_place_attention', 'school_place_success', 'school_potty_attention', 'school_potty_success',
                'ball_waiting', 'wand_pouncing',
            ]);
    });

    it('cats keep the cat templates (no "dog") and a pounce goes start-only', function () {
        $prompts = app(PetAppearancePrompt::class);
        $traits = ['coat_color' => 'blue', 'coat_length' => 'short'];
        $loop = $prompts->loopVideoPrompt('british_shorthair', PetStateEnum::Sleeping, $traits);
        $pounce = $prompts->phaseVideoPrompt('maine_coon', PetPhaseClip::WandPouncing, $traits);

        expect(strtolower($loop.$pounce.$prompts->loopNegativePrompt(Species::Cat)))->not->toContain('dog')
            ->and($loop)->toContain('lively cat motion')
            ->and($pounce)->toContain('ends close to the starting pose');
    });
});

describe('media:regenerate-frozen', function () {
    it('dry run lists the Suki-like frozen slots (8 loops + 9 phases) with their cost and makes no fal call', function () {
        fzFakeFal();
        $pet = fzPet();
        fzCompleteClips();
        fzMakeFrozen($pet);
        $before = Http::recorded()->count();

        $plan = app(FrozenClipRegenerator::class)->plan($pet->id);
        expect($plan['loops'])->toBe(8)->and($plan['phases'])->toBe(9)
            ->and($plan['cost_usd'])->toBe(round(8 * 0.56 + 9 * 0.336, 4))
            ->and($plan['cost_usd'])->toBe(7.504)
            ->and($plan['pets'])->toBe([$pet->id])->and($plan['looks'])->toBe([]);

        $this->artisan('media:regenerate-frozen', ['--pet' => $pet->id, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: 8 loop(s) + 9 phase clip(s), estimated $7.50')
            ->assertSuccessful();

        expect(Http::recorded()->count())->toBe($before)
            ->and(PetMedia::where('pet_id', $pet->id)->where('status', '!=', 'ready')->count())->toBe(0)
            ->and(AppSettingChange::where('key', FrozenClipRegenerator::AUDIT_KEY)->count())->toBe(0);
    });

    it('real run re-queues exactly those slots as a new generation (old file stays) and audits it', function () {
        fzFakeFal();
        $pet = fzPet();
        fzCompleteClips();
        fzMakeFrozen($pet);
        $frozenIds = app(FrozenClipRegenerator::class)->plan($pet->id)['rows']->pluck('id')->sort()->values()->all();
        $paths = PetMedia::whereKey($frozenIds)->pluck('storage_path', 'id')->all();
        $untouched = PetMedia::where('pet_id', $pet->id)->whereNotIn('id', $frozenIds)->get(['id', 'generation', 'status'])->keyBy('id');

        Queue::fake([SubmitPetStateVideo::class]);
        $this->artisan('media:regenerate-frozen', ['--pet' => $pet->id])
            ->expectsOutputToContain('Queued 17 clip(s) (8 loop(s) + 9 phase clip(s)), estimated $7.50')
            ->assertSuccessful();

        Queue::assertPushed(SubmitPetStateVideo::class, 17);
        $pushed = collect(Queue::pushed(SubmitPetStateVideo::class))->map(fn ($j) => $j->petMediaId)->sort()->values()->all();
        expect($pushed)->toBe($frozenIds);

        foreach (PetMedia::whereKey($frozenIds)->get() as $row) {
            expect($row->status)->toBe('pending')->and($row->generation)->toBe(2)
                ->and($row->storage_path)->toBe($paths[$row->id]); // still servable meanwhile
        }
        foreach (PetMedia::where('pet_id', $pet->id)->whereNotIn('id', $frozenIds)->get() as $row) {
            expect($row->generation)->toBe($untouched[$row->id]->generation)->and($row->status)->toBe('ready');
        }

        $audit = AppSettingChange::where('key', FrozenClipRegenerator::AUDIT_KEY)->sole();
        expect($audit->user_id)->toBeNull()->and($audit->new['loops'])->toBe(8)->and($audit->new['phases'])->toBe(9);

        // The jobs submit through the normal pipeline: start frame only, recipe start_only.
        $calls = fzQueueCalls()->count();
        foreach ($frozenIds as $id) {
            app(PetMediaService::class)->submitVideo($id);
        }
        $new = fzQueueCalls()->slice($calls)->values();
        expect($new)->toHaveCount(17);
        foreach ($new as $r) {
            expect($r->data())->not->toHaveKey('end_image_url');
        }
        fzCompleteClips();
        expect(PetMedia::whereKey($frozenIds)->pluck('recipe')->unique()->all())->toBe(['start_only'])
            ->and(app(FrozenClipRegenerator::class)->plan($pet->id)['rows'])->toHaveCount(0);
    });

    it('QA M1: a remake that fails keeps the frozen file and is offered again; a second run queues nothing', function () {
        fzFakeFal();
        $pet = fzPet();
        fzCompleteClips();
        fzMakeFrozen($pet);
        $media = app(PetMediaService::class);
        $regen = app(FrozenClipRegenerator::class);

        $idle = PetMedia::where('pet_id', $pet->id)->where('kind', 'video')->where('state', 'idle')->sole();
        $oldPath = $idle->storage_path;
        Queue::fake([SubmitPetStateVideo::class]);
        expect($regen->regenerate(null, $pet->id))->toMatchArray(['outcome' => 'queued', 'queued' => 17, 'loops' => 8, 'phases' => 9]);

        // While queued (pending) nothing is offered again: a second run spends nothing.
        $calls = fzQueueCalls()->count();
        expect($regen->plan($pet->id)['rows'])->toHaveCount(0)
            ->and($regen->regenerate(null, $pet->id)['outcome'])->toBe('nothing')
            ->and(AppSettingChange::where('key', FrozenClipRegenerator::AUDIT_KEY)->count())->toBe(1);

        // The remake is submitted (start-only), then fal reports an error.
        $media->submitVideo($idle->id);
        $idle->refresh();
        expect($idle->recipe)->toBe('start_only')->and($idle->stored_recipe)->toBe('loop');
        sendFalWebhook(['request_id' => $idle->request_id, 'status' => 'ERROR', 'error' => 'boom'])->assertOk();

        $idle->refresh();
        expect($idle->status)->toBe('failed')
            ->and($idle->storage_path)->toBe($oldPath)
            ->and($idle->stored_recipe)->toBe('loop')
            ->and(fzQueueCalls()->count())->toBe($calls + 1);

        // Still frozen on screen → listed again (failedRetry path), and the »Made as« label says so.
        expect($regen->plan($pet->id)['rows']->pluck('id')->all())->toBe([$idle->id]);
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));
        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->assertTableColumnStateSet('recipe', 'loop (key image → key image, frozen — regenerate)', $idle);
    });

    it('QA m3: a same-pose phase clip made by a profile without an end frame was never frozen', function () {
        fzFakeFal();
        $pet = fzPet();
        fzCompleteClips();
        fzMakeFrozen($pet);
        PetMedia::where('pet_id', $pet->id)->where('kind', 'phase')->update(['profile' => 'veo31_fast']);

        expect(app(FrozenClipRegenerator::class)->plan($pet->id))->toMatchArray(['loops' => 8, 'phases' => 0]);
    });

    it('respects the switches and the budget; a fixed pet has nothing to do', function () {
        fzFakeFal();
        $pet = fzPet();
        fzCompleteClips();

        // Made after the fix: nothing frozen.
        expect(app(FrozenClipRegenerator::class)->plan($pet->id)['rows'])->toHaveCount(0);

        fzMakeFrozen($pet);
        usePhaseClips(false);
        expect(app(FrozenClipRegenerator::class)->plan($pet->id))->toMatchArray(['loops' => 8, 'phases' => 0]);

        useStateMedia(false);
        expect(app(FrozenClipRegenerator::class)->plan($pet->id)['rows'])->toHaveCount(0);

        useStateMedia();
        usePhaseClips();
        config(['media.budget.daily_usd' => 1.0]);
        $before = Http::recorded()->count();
        $this->artisan('media:regenerate-frozen', ['--pet' => $pet->id])->assertFailed();
        expect(Http::recorded()->count())->toBe($before)
            ->and(PetMedia::where('pet_id', $pet->id)->where('status', 'pending')->count())->toBe(0);
    });

    it('a pet on a shared look: the look rows are remade once, the pool pets follow', function () {
        useUniquePetMedia(false);
        fzFakeFal();
        $a = fzPet();
        $b = fzPet();
        fzCompleteClips();
        expect($a->pet_look_id)->not->toBeNull()->toBe($b->pet_look_id);
        fzMakeFrozen();

        $plan = app(FrozenClipRegenerator::class)->plan($a->id);
        expect($plan['pets'])->toBe([])->and($plan['looks'])->toBe([$a->pet_look_id])
            ->and($plan['loops'])->toBe(8)->and($plan['phases'])->toBe(9)
            ->and(app(FrozenClipRegenerator::class)->plan(lookId: $a->pet_look_id)['rows']->pluck('id')->all())->toBe($plan['rows']->pluck('id')->all())
            // The whole-system run counts each look row once (no per-pet rows).
            ->and(app(FrozenClipRegenerator::class)->plan()['rows'])->toHaveCount(17);

        $this->artisan('media:regenerate-frozen', ['--look' => $a->pet_look_id])->assertSuccessful();
        fzCompleteClips();

        $idleRow = PetMedia::whereNotNull('pet_look_id')->where('kind', 'video')->where('state', 'idle')->sole();
        expect($idleRow->recipe)->toBe('start_only')->and($idleRow->generation)->toBe(2);
        foreach ([$a, $b] as $pet) {
            $slot = PetMedia::where('pet_id', $pet->id)->where('kind', 'video')->where('state', 'idle')->sole();
            expect($slot->storage_path)->toBe($idleRow->storage_path)->and($slot->recipe)->toBe('start_only');
        }
    });

    it('superadmin AI media panel: »Regenerate frozen clips« shows the cost and queues them', function () {
        fzFakeFal();
        $pet = fzPet();
        fzCompleteClips();
        fzMakeFrozen($pet);
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);

        Queue::fake([SubmitPetStateVideo::class]);
        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->assertTableActionVisible('regenerateFrozen')
            ->callTableAction('regenerateFrozen');

        Queue::assertPushed(SubmitPetStateVideo::class, 17);
        expect(AppSettingChange::where('key', FrozenClipRegenerator::AUDIT_KEY)->sole()->user_id)->toBe($admin->id);

        // A non-superadmin admin user can neither see nor call it (nothing queued, no audit).
        fzMakeFrozen($pet);
        PetMedia::where('pet_id', $pet->id)->where('status', 'pending')->update(['status' => 'ready']);
        $this->actingAs(User::factory()->create(['role' => 'parent']));
        expect(MediaRelationManager::canViewForRecord($pet, EditPet::class))->toBeFalse();
        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->assertTableActionHidden('regenerateFrozen'); // also ->authorize(): Filament refuses a hidden / unauthorized call
        Queue::assertPushed(SubmitPetStateVideo::class, 17);
        expect(AppSettingChange::where('key', FrozenClipRegenerator::AUDIT_KEY)->count())->toBe(1);
    });
});
