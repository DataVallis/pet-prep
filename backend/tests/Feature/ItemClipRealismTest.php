<?php

use App\Console\Commands\ItemRecheckCommand;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Jobs\SendItemMediaAlert;
use App\Models\AiSpendLedger;
use App\Models\AppSettingChange;
use App\Models\ItemMedia;
use App\Models\ItemMediaQualityCheck;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\FalWebhookVerifier;
use App\Services\Media\FalGateway;
use App\Services\Media\ItemMediaService;
use App\Services\Media\MediaVisionCheck;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\StillQualityService;
use App\Services\Shop\ItemDesigns;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Item content realism (David 2026-11-19)
|--------------------------------------------------------------------------
| Production: Suki (Dobermann, 7 months) got the »Navy cushion bed«; its »Sleeping« clip showed a
| razor-sharp, studio-lit bed pasted into a soft room, floating above the floor with a detached
| shadow blob, and the big dog lying ON TOP of a too-small bed. The key image passed the old animal
| rubric (it only asked about paws and ghosts) and item clips only had the free technical check in
| shadow. Now: the key image rubric asks grounded / integrated / scale, every item clip gets an AI
| realism check (contact sheet + its start frame) in the ITEM mode (enforce), a clip whose OBJECT is
| wrong regenerates its key image, flagged files are never shown, prompts re-render the item into
| the room at the breed's size, and `media:item-recheck` re-checks what is already stored.
| The vision model is always Http::fake — never a real fal call.
*/

const IRC_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    Cache::forget(FalWebhookVerifier::CACHE_KEY);
    Cache::forget(FalGateway::BALANCE_FLAG_KEY);
    config([
        'services.fal_ai.key' => 'test-key',
        'app.url' => 'https://api.petprep.si',
        'media.state_video_profile' => 'kling_v3_pro',
        'media.budget.daily_usd' => 100.0,
        'media.budget.monthly_usd' => 1000.0,
        'media.budget.timezone' => 'UTC',
        'media.profiles.vision.openrouter_vision_gemini_flash.enabled' => true,
        // Production defaults: item checks enforce, the clip's technical check shadow, clip AI on.
        'media.quality_check.stills_mode' => 'enforce',
        'media.quality_check.clip_ai' => true,
    ]);
    useQualityCheck('shadow');
    seedBreedConfigs();
    seedStageParams();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** ffmpeg + ffprobe are needed for the clip's contact sheet (production image + CI have them). */
function ircNeedsFfmpeg(): void
{
    if (! (Process::run(['ffmpeg', '-version'])->successful() && Process::run(['ffprobe', '-version'])->successful())) {
        test()->markTestSkipped('ffmpeg / ffprobe not installed');
    }
}

/** A tiny real moving MP4 (ffmpeg test pattern, 3 s). */
function ircClipBytes(): string
{
    static $bytes = null;
    if ($bytes !== null) {
        return $bytes;
    }
    $out = tempnam(sys_get_temp_dir(), 'ircclip-').'.mp4';
    Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=144x256:rate=12:duration=3', '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', $out])->throw();
    $bytes = (string) file_get_contents($out);
    @unlink($out);

    return $bytes;
}

/**
 * fal fakes. `$fails($row, $rubric)` returns the rubric keys the vision model answers FALSE for the
 * file it is shown (an item row: key image or clip — via its contact sheet); [] = pass.
 *
 * @param  callable(ItemMedia, string): list<string>|null  $fails
 */
function ircFakeFal(?callable $fails = null): void
{
    fakeFalJwks();
    $n = 0;
    $fails ??= fn () => [];
    Http::fake([
        'fal.run/openrouter/router/vision' => function (Request $r) use ($fails) {
            $row = ircRowOf((string) ($r->data()['image_urls'][0] ?? ''));
            $rubric = match (true) {
                $row === null => MediaVisionCheck::RUBRIC_OBJECT,
                $row->isPicture() => MediaVisionCheck::RUBRIC_OBJECT,
                $row->isClip() => MediaVisionCheck::RUBRIC_ITEM_CLIP,
                default => MediaVisionCheck::RUBRIC_ITEM,
            };
            $bad = $row === null ? [] : $fails($row, $rubric);
            if ($bad === ['__malformed']) {
                // A vision answer without a usable verdict (paid, but no rubric JSON).
                return Http::response(['output' => 'Sorry, I cannot tell.', 'usage' => ['cost' => 0.0008]]);
            }
            $answer = array_fill_keys(MediaVisionCheck::STILL_KEYS[$rubric], true);
            foreach ($bad as $key) {
                $answer[$key] = false;
            }

            return Http::response(['output' => json_encode($answer + ['verdict' => $bad === [] ? 'pass' : 'fail', 'reason' => $bad === [] ? 'looks real' : 'the bed floats above the floor like a pasted photo']), 'usage' => ['cost' => 0.0008]]);
        },
        'queue.fal.run/fal-ai/nano-banana-pro/edit*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "irc-edit-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/irc-edit-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/irc-edit-{$n}"]);
        },
        'queue.fal.run/fal-ai/nano-banana-pro*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "irc-pic-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/irc-pic-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/irc-pic-{$n}"]);
        },
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "irc-clip-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/kling-video/requests/irc-clip-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/kling-video/requests/irc-clip-{$n}"]);
        },
        'v3.fal.media/files/irc/*.jpg' => fn () => Http::response(IRC_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/irc/*.mp4' => fn () => Http::response(ircClipBytes(), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** The item row a vision request's first image points at (item key image / candidate, or a clip's contact sheet). */
function ircRowOf(string $url): ?ItemMedia
{
    return match (true) {
        preg_match('#/api/media/item-quality-sheets/(\d+)#', $url, $m) === 1 => ItemMediaQualityCheck::find((int) $m[1])?->itemMedia,
        preg_match('#/api/media/items/(\d+)#', $url, $m) === 1 => ItemMedia::find((int) $m[1]),
        default => null,
    };
}

/** Answer every running item request (picture → image → clip), `$rounds` rounds. */
function ircComplete(int $rounds = 12): void
{
    for ($round = 0; $round < $rounds; $round++) {
        $rows = ItemMedia::query()->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            break;
        }
        foreach ($rows as $row) {
            $payload = $row->isStill()
                ? ['images' => [['url' => "https://v3.fal.media/files/irc/{$row->request_id}.jpg"]]]
                : ['video' => ['url' => "https://v3.fal.media/files/irc/{$row->request_id}.mp4"]];
            sendFalWebhook(['request_id' => $row->request_id, 'status' => 'OK', 'payload' => $payload])->assertOk();
        }
    }
}

/** A Dobermann with its own picture owning $design (David's case: Suki and the navy bed). */
function ircPet(string $itemKey = 'bed', string $design = 'dog_bed_navy_bolster'): Pet
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->dobermann()->create(['user_id' => $child->id, 'born_at' => now()->subDay(), 'last_decay_at' => now(), 'media_status' => 'ready']);
    disableHygieneEvents($pet);
    $path = "{$pet->id}/reference-g1.jpg";
    Storage::disk('pet_media')->put($path, IRC_JPEG.str_repeat("\x00", 64));
    PetMedia::query()->updateOrCreate(['pet_id' => $pet->id, 'kind' => 'image', 'state' => null], ['status' => 'ready', 'storage_path' => $path, 'life_stage' => 'young', 'mime' => 'image/jpeg', 'generation' => 1]);
    PetItem::create(['pet_id' => $pet->id, 'item_key' => $itemKey, 'kind' => $itemKey === 'treats' ? 'consumable' : 'durable', 'variant' => "dog_{$itemKey}", 'design' => $itemKey === 'treats' ? null : $design, 'quantity_total' => $itemKey === 'treats' ? 5 : 1, 'quantity_left' => $itemKey === 'treats' ? 5 : 1, 'acquired_at' => now()]);

    return $pet->fresh();
}

function ircRow(Pet $pet, string $kind): ItemMedia
{
    return ItemMedia::where('pet_id', $pet->id)->where('kind', $kind)->firstOrFail();
}

/** @return Collection<int, array<string, mixed>> request bodies of a fal queue submit kind ('edit' = key images, 'kling' = clips). */
function ircSubmits(string $kind): Collection
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), $kind === 'edit' ? 'https://queue.fal.run/fal-ai/nano-banana-pro/edit' : 'https://queue.fal.run/fal-ai/kling')
        && ! str_contains($r->url(), '/requests/'))
        ->map(fn (array $pair) => $pair[0]->data())->values();
}

/** @return Collection<int, array<string, mixed>> request bodies sent to the vision model. */
function ircVision(): Collection
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'openrouter/router/vision'))->map(fn (array $pair) => $pair[0]->data())->values();
}

/* ─────────────────────────── Generation ─────────────────────────── */

describe('prompts', function () {
    it('re-renders the item into the room, sizes the bed for the breed and puts the dog IN it', function () {
        ircFakeFal();
        $pet = ircPet();
        app(ItemMediaService::class)->ensure($pet);
        ircComplete();

        $edit = ircSubmits('edit')->first();
        $clip = ircSubmits('kling')->first();

        // Dobermann: adult weight 32–45 kg (breed_stage_params) → extra-large, 120 cm inside.
        expect(app(ItemMediaService::class)->sizeOf('dobermann'))->toBe(['class' => 'extra-large', 'bed_cm' => 120])
            ->and($edit['prompt'])->toContain('lying curled up asleep in a low rectangular dog bed')
            ->toContain('The bed is a bed for a extra-large dog, about 120 cm long inside')
            ->toContain('never a small bed under a big dog')
            ->toContain('The whole dog lies in the bed, on its cushion inside its rim or sides')
            ->toContain('not a pasted cut-out or product photo')->toContain('soft natural contact shadow')
            ->toContain('Use the second reference image only for the object\'s design')
            ->toContain('same natural light, focus and depth of field as the reference image')
            ->not->toContain('sharp focus')
            ->and($clip['prompt'])->toContain('The dog stays lying inside the bed')->toContain('it never floats, grows, shrinks or changes');
    });

    it('size classes come from the adult weight; cats and unknown breeds get none', function () {
        expect(ItemMediaService::sizeClassFor(5))->toBe(['class' => 'small', 'bed_cm' => 60])
            ->and(ItemMediaService::sizeClassFor(25))->toBe(['class' => 'medium-sized', 'bed_cm' => 80])
            ->and(ItemMediaService::sizeClassFor(40))->toBe(['class' => 'large', 'bed_cm' => 100])
            ->and(ItemMediaService::sizeClassFor(70))->toBe(['class' => 'extra-large', 'bed_cm' => 120])
            ->and(app(ItemMediaService::class)->sizeOf('chihuahua')['class'])->toBe('small')
            ->and(app(ItemMediaService::class)->sizeOf('domestic_cat'))->toBeNull()
            ->and(app(ItemMediaService::class)->sizeOf('no_such_breed'))->toBeNull()
            ->and(strtolower(app(PetAppearancePrompt::class)->itemImagePrompt('domestic_cat', 'bed', ItemDesigns::description('cat_bed_grey_cave'), ['coat_color' => 'grey tabby'], null, true)))->not->toMatch('/\bdog\b/');
    });
});

/* ─────────────────────────── The key image gate ─────────────────────────── */

describe('item key image realism rubric', function () {
    it('a pasted, floating bed fails the key image check and is regenerated with a new seed before any clip is made', function () {
        $fails = 1;
        ircFakeFal(function (ItemMedia $row, string $rubric) use (&$fails) {
            if ($rubric === MediaVisionCheck::RUBRIC_ITEM && $fails > 0) {
                $fails--;

                return ['item_grounded', 'item_integrated', 'scale_ok'];
            }

            return [];
        });
        $pet = ircPet();
        app(ItemMediaService::class)->ensure($pet);
        ircComplete();

        $image = ircRow($pet, 'image');
        $checks = ItemMediaQualityCheck::where('item_media_id', $image->id)->orderBy('generation')->get();
        $edits = ircSubmits('edit');
        $prompt = ircVision()->first(fn (array $b) => str_contains((string) $b['prompt'], 'item_grounded') && ! str_contains((string) $b['prompt'], 'contact sheet'));

        expect($checks->pluck('verdict')->all())->toBe(['fail', 'pass'])
            ->and($checks->pluck('rubric')->unique()->values()->all())->toBe([MediaVisionCheck::RUBRIC_ITEM])
            ->and($checks[0]->reason)->toContain('floats')
            ->and($edits)->toHaveCount(2)->and($edits[1]['seed'])->not->toBe($edits[0]['seed'])
            // The clip is made once, from the passed key image.
            ->and(ircSubmits('kling'))->toHaveCount(1)
            ->and(ircRow($pet, 'clip')->source_path)->toBe($image->storage_path)
            ->and($prompt['prompt'])->toContain('not on top of a too-small bed')->toContain('detached shadow blob')->toContain('razor-sharp product look');
    });
});

/* ─────────────────────────── The clip gate ─────────────────────────── */

describe('item clip AI realism check', function () {
    it('checks every item clip (contact sheet + start frame) before it is shown; a pass stores it', function () {
        ircNeedsFfmpeg();
        ircFakeFal();
        $pet = ircPet();
        app(ItemMediaService::class)->ensure($pet);
        ircComplete();

        $clip = ircRow($pet, 'clip');
        $image = ircRow($pet, 'image');
        $check = ItemMediaQualityCheck::where('item_media_id', $clip->id)->sole();
        $call = ircVision()->first(fn (array $b) => str_contains((string) $b['image_urls'][0], '/api/media/item-quality-sheets/'));

        expect($clip->status)->toBe('ready')->and($clip->quality)->toBe(PetMedia::QUALITY_PASSED)
            ->and($check->rubric)->toBe(MediaVisionCheck::RUBRIC_ITEM_CLIP)->and($check->ai_status)->toBe('passed')
            // A pass frees the contact sheet.
            ->and($check->sheet_path)->toBeNull()
            ->and($call['image_urls'])->toHaveCount(2)->and($call['image_urls'][1])->toContain("/api/media/items/{$image->id}")
            ->and($call['prompt'])->toContain('lying curled up asleep in')->toContain('scale_ok')
            ->and(AiSpendLedger::where('item_media_id', $clip->id)->where('purpose', 'quality_check')->count())->toBe(1)
            ->and(app(ItemMediaService::class)->statesFor($pet)['bed']['outcome'])->toBe(ItemMediaService::OUTCOME_CONTENT);
    });

    it('David\'s bed: a clip whose bed floats is never shown — its key image is regenerated, then a new clip passes', function () {
        ircNeedsFfmpeg();
        $clipFails = 1;
        $pet = null;
        $shownWhileRegenerating = [];
        ircFakeFal(function (ItemMedia $row, string $rubric) use (&$clipFails, &$pet, &$shownWhileRegenerating) {
            if ($rubric === MediaVisionCheck::RUBRIC_ITEM_CLIP && $clipFails > 0) {
                $clipFails--;

                return ['item_grounded', 'item_integrated'];
            }
            if ($rubric === MediaVisionCheck::RUBRIC_ITEM && $clipFails === 0) {
                // The new key image is being checked: nothing of the failed chain may be shown meanwhile.
                $state = app(ItemMediaService::class)->statesFor($pet->fresh())['bed'];
                $shownWhileRegenerating[] = [$state['outcome'], $state['image']?->id, $state['clip']?->id];
            }

            return [];
        });
        $pet = ircPet();
        $items = app(ItemMediaService::class);
        $items->ensure($pet);
        ircComplete();

        $image = ircRow($pet, 'image');
        $clip = ircRow($pet, 'clip');
        $firstCheck = ItemMediaQualityCheck::where('item_media_id', $clip->id)->orderBy('id')->first();

        expect(ircSubmits('edit'))->toHaveCount(2)->and(ircSubmits('kling'))->toHaveCount(2)
            ->and($firstCheck->verdict)->toBe('fail')->and($firstCheck->sheet_path)->not->toBeNull()
            ->and(Storage::disk('pet_media')->exists($firstCheck->sheet_path))->toBeTrue()
            ->and($image->status)->toBe('ready')->and($image->quality_retries)->toBe(1)->and($image->prompt_variant)->toBe(1)
            ->and($clip->status)->toBe('ready')->and($clip->source_path)->toBe($image->storage_path)
            // The failed clip's try is counted on the clip too (one budget per chain).
            ->and($clip->quality_retries)->toBe(1)
            ->and($shownWhileRegenerating)->toBe([[ItemMediaService::OUTCOME_WAITING, null, null]])
            // The failed candidate never became the stored file.
            ->and($clip->generation)->toBeGreaterThan($firstCheck->generation)->and($clip->storage_path)->toEndWith("-g{$clip->generation}.mp4")
            ->and($items->statesFor($pet->fresh())['bed']['outcome'])->toBe(ItemMediaService::OUTCOME_CONTENT);

        // The contact sheet of the failed clip is served signed (fal / admin), never without a signature.
        $sheet = app(StillQualityService::class)->itemSheetUrl($firstCheck);
        $this->get(str_replace('https://api.petprep.si', '', $sheet))->assertOk();
        $this->get("/api/media/item-quality-sheets/{$firstCheck->id}")->assertForbidden();
    });

    it('a clip that keeps failing is retried within the budget with calmer prompts; the bed falls back AT ONCE (David 2026-11-19), treats stay locked', function () {
        ircNeedsFfmpeg();
        Queue::fake([SendItemMediaAlert::class]);
        Cache::forget(ItemMediaService::GIVE_UP_WINDOW_KEY);
        Cache::forget(ItemMediaService::GIVE_UP_COUNT_KEY);
        ircFakeFal(fn (ItemMedia $row, string $rubric) => $rubric === MediaVisionCheck::RUBRIC_ITEM_CLIP ? ['action_done'] : []);
        $items = app(ItemMediaService::class);

        // The bed: 3 tries (1 + max_still_retries), each calmer, then given up at once — no review wait.
        $bed = ircPet('bed', 'dog_bed_round_grey');
        $items->ensure($bed);
        ircComplete();

        $clip = ircRow($bed, 'clip');
        $image = ircRow($bed, 'image');
        $prompts = ircSubmits('kling')->pluck('prompt')->values();
        $state = $items->statesFor($bed->fresh())['bed'];
        $payload = $items->payloadFor($bed->fresh());

        expect($clip->status)->toBe('failed')->and($clip->error_reason)->toBe('quality_failed')
            ->and($clip->isExhausted())->toBeTrue()->and($clip->error)->not->toContain('needs review')
            ->and(AiSpendLedger::where('item_media_id', $clip->id)->where('purpose', 'item_media')->count())->toBe(3)
            ->and($image->quality_retries)->toBe(0)
            // Escalating variants: 0 → + »perfectly still« → the shortest, most static wording.
            ->and($prompts)->toHaveCount(3)
            ->and($prompts[0])->toContain('sound asleep and completely still')->not->toContain('Perfectly still')
            ->and($prompts[1])->toContain('Perfectly still, like a photograph')
            ->and($prompts[2])->toStartWith('A still, calm video of the same')
            ->and(ircSubmits('kling')->pluck('negative_prompt')->unique()->values()->all())->toBe([app(PetAppearancePrompt::class)->sleepNegativePrompt()])
            // The passed key image is the bed's picture now: no clip, media_ready true.
            ->and($state['outcome'])->toBe(ItemMediaService::OUTCOME_FALLBACK)
            ->and($state['image']?->id)->toBe($image->id)->and($state['clip'])->toBeNull()
            ->and($items->readiness($bed->fresh())['bed'])->toBeTrue()
            ->and($payload['bed']['image'])->toContain("/api/media/items/{$image->id}")->and($payload['bed']['video'])->toBeNull()
            ->and(Cache::get(ItemMediaService::GIVE_UP_COUNT_KEY))->toBe(1);
        // One counting alert, queued for the end of its window (takes the count then).
        Queue::assertPushed(SendItemMediaAlert::class, fn (SendItemMediaAlert $job) => $job->count === null);
        Queue::assertPushed(SendItemMediaAlert::class, 1);

        // Treats are unchanged (DECISIONS 2026-11-15): »Needs review«, heal() gives up after the delay, the action stays locked.
        $treats = ircPet('treats', '');
        $items->ensure($treats);
        ircComplete();
        $treatClip = ircRow($treats, 'clip');
        expect($treatClip->needsReview())->toBeTrue()->and($treatClip->exhausted_at)->toBeNull()
            ->and($treatClip->error)->toContain('needs review');

        Carbon::setTestNow(now()->addMinutes(31));
        $items->heal();
        expect($items->actionReady($treats->fresh(), 'treats'))->toBeFalse()
            ->and($items->statesFor($bed->fresh())['bed']['outcome'])->toBe(ItemMediaService::OUTCOME_FALLBACK);
        Queue::assertPushed(SendItemMediaAlert::class, 2);
        Queue::assertPushed(SendItemMediaAlert::class, fn (SendItemMediaAlert $job) => $job->count === 1 && $job->locked === 1);

        // The window's alert posts the count once (and resets it).
        expect(ItemMediaService::takeGiveUpCount())->toBe(1)->and(ItemMediaService::takeGiveUpCount())->toBe(0);
    });

    it('a durable key image that never passes falls back to the item without content at once (no key image passed)', function () {
        Queue::fake([SendItemMediaAlert::class]);
        Cache::forget(ItemMediaService::GIVE_UP_WINDOW_KEY);
        Cache::forget(ItemMediaService::GIVE_UP_COUNT_KEY);
        ircFakeFal(fn (ItemMedia $row, string $rubric) => $rubric === MediaVisionCheck::RUBRIC_ITEM ? ['item_grounded'] : []);
        $items = app(ItemMediaService::class);
        $pet = ircPet('bed', 'dog_bed_round_grey');
        $items->ensure($pet);
        ircComplete();

        $image = ircRow($pet, 'image');
        $edits = ircSubmits('edit')->pluck('prompt')->values();
        $state = $items->statesFor($pet->fresh())['bed'];

        expect($image->isExhausted())->toBeTrue()->and($image->error_reason)->toBe('quality_failed')
            ->and($edits)->toHaveCount(3)
            // Stricter placement every try; the last one leads with the floor contact.
            ->and($edits[0])->toContain('soft contact shadow lies under the entire base')->not->toStartWith('Most important')
            ->and($edits[2])->toStartWith('Most important: the object stands ON the floor')
            ->and(ircSubmits('kling'))->toHaveCount(0)
            ->and($state['outcome'])->toBe(ItemMediaService::OUTCOME_FALLBACK)->and($state['image'])->toBeNull()
            ->and($items->readiness($pet->fresh())['bed'])->toBeTrue()
            ->and($items->payloadFor($pet->fresh()))->not->toHaveKey('bed');
        Queue::assertPushed(SendItemMediaAlert::class, 1);
    });

    it('waits for budget before the clip AI check: nothing claimed, paid or shown', function () {
        ircNeedsFfmpeg();
        ircFakeFal();
        $pet = ircPet();
        $items = app(ItemMediaService::class);
        $items->ensure($pet);
        // Picture + key image through, then the clip with no budget left for its check.
        for ($i = 0; $i < 3; $i++) {
            $running = ItemMedia::query()->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();
            if ($running->contains(fn (ItemMedia $r) => $r->isClip())) {
                break;
            }
            ircComplete(1);
        }
        config(['media.budget.daily_usd' => (float) AiSpendLedger::query()->counted()->sum('cost_usd')]);
        ircComplete();

        $clip = ircRow($pet, 'clip');
        expect($clip->status)->toBe('running')->and($clip->candidate_path)->not->toBeNull()
            ->and(ItemMediaQualityCheck::where('item_media_id', $clip->id)->count())->toBe(0)
            ->and($items->statesFor($pet)['bed']['clip'])->toBeNull();

        config(['media.budget.daily_usd' => 100.0]);
        Carbon::setTestNow(now()->addMinutes(61));
        $items->sweep();
        expect($clip->fresh()->status)->toBe('ready')->and($clip->fresh()->quality)->toBe(PetMedia::QUALITY_PASSED);
    });

    it('item mode shadow records a failing clip and uses it; the clip AI switch off leaves the technical check alone', function () {
        ircNeedsFfmpeg();
        config(['media.quality_check.stills_mode' => 'shadow']);
        ircFakeFal(fn (ItemMedia $row, string $rubric) => $rubric === MediaVisionCheck::RUBRIC_ITEM_CLIP ? ['scale_ok'] : []);
        $pet = ircPet();
        app(ItemMediaService::class)->ensure($pet);
        ircComplete();
        expect(ircRow($pet, 'clip')->quality)->toBe(PetMedia::QUALITY_SHADOW_FAILED)->and(ircSubmits('kling'))->toHaveCount(1);

        config(['media.quality_check.stills_mode' => 'enforce', 'media.quality_check.clip_ai' => false]);
        $other = ircPet('bed', 'dog_bed_round_grey');
        $calls = ircVision()->count();
        app(ItemMediaService::class)->ensure($other);
        ircComplete();
        $check = ItemMediaQualityCheck::where('item_media_id', ircRow($other, 'clip')->id)->sole();
        expect($check->ai_status)->toBe('not_run')->and(ircRow($other, 'clip')->quality)->toBe(PetMedia::QUALITY_PASSED_TECHNICAL)
            // Only the new key image (and its catalogue picture) were AI-checked — not the clip.
            ->and(ircVision()->count() - $calls)->toBe(2);
    });
});

/* ─────────────────────────── Stored files: media:item-recheck ─────────────────────────── */

describe('media:item-recheck', function () {
    it('is a dry run by default; --run flags a pasted stored bed (never shown again), regenerates it and its clip; a second run finds nothing', function () {
        ircNeedsFfmpeg();
        // Made before the realism check (checks off).
        config(['media.quality_check.stills_mode' => 'off']);
        useQualityCheck('off');
        $judged = [];
        ircFakeFal(function (ItemMedia $row, string $rubric) use (&$judged) {
            $judged[] = $row->id;

            return $rubric === MediaVisionCheck::RUBRIC_ITEM && $row->generation === 1 ? ['item_integrated', 'scale_ok'] : [];
        });
        $pet = ircPet();
        $items = app(ItemMediaService::class);
        $items->ensure($pet);
        ircComplete();
        $oldImage = ircRow($pet, 'image');
        $oldClip = ircRow($pet, 'clip');
        expect($items->statesFor($pet)['bed']['outcome'])->toBe(ItemMediaService::OUTCOME_CONTENT)->and(ircVision())->toHaveCount(0);

        config(['media.quality_check.stills_mode' => 'enforce']);
        useQualityCheck('shadow');

        $this->artisan('media:item-recheck')->assertSuccessful()
            ->expectsOutputToContain('1 key image(s), 1 clip(s). To check: 2 × ≈ $0.0030 = ≈ $0.0060')
            ->expectsOutputToContain('Regenerations if they fail (enforce, at most 10 per run): up to 2 ×')
            ->expectsOutputToContain('Dry run');
        expect(ircVision())->toHaveCount(0)->and(ItemMediaQualityCheck::count())->toBe(0);

        $this->artisan('media:item-recheck --run')->assertSuccessful()->expectsOutputToContain('regenerating 1');

        // Flagged at once: the pasted bed and the clip made from it are no longer shown.
        $flagged = ircRow($pet, 'image');
        expect($flagged->quality)->toBe(PetMedia::QUALITY_FLAGGED)->and($flagged->status)->toBe('running')
            ->and($items->statesFor($pet->fresh())['bed']['image'])->toBeNull()
            ->and($items->payloadFor($pet->fresh()))->toBe([])
            ->and(AppSettingChange::where('key', 'media_item_recheck')->count())->toBe(1);

        ircComplete();
        $image = ircRow($pet, 'image');
        $clip = ircRow($pet, 'clip');
        expect($image->status)->toBe('ready')->and($image->quality)->toBe(PetMedia::QUALITY_PASSED)->and($image->storage_path)->not->toBe($oldImage->storage_path)
            ->and($clip->status)->toBe('ready')->and($clip->source_path)->toBe($image->storage_path)->and($clip->storage_path)->not->toBe($oldClip->storage_path)
            ->and($items->statesFor($pet->fresh())['bed']['outcome'])->toBe(ItemMediaService::OUTCOME_CONTENT);

        // Self-limiting: the new files were checked by the pipeline with the current rubric (0 $).
        $calls = ircVision()->count();
        $this->artisan('media:item-recheck --run')->assertSuccessful()->expectsOutputToContain('0 key image(s), 0 clip(s)');
        expect(ircVision()->count())->toBe($calls);
    });

    it('a stored clip whose bed floats regenerates its key image first; approved files are never re-checked', function () {
        ircNeedsFfmpeg();
        config(['media.quality_check.stills_mode' => 'off']);
        useQualityCheck('off');
        ircFakeFal(fn (ItemMedia $row, string $rubric) => $rubric === MediaVisionCheck::RUBRIC_ITEM_CLIP && $row->generation <= 2 && $row->quality === null ? ['item_grounded'] : []);
        $pet = ircPet();
        $items = app(ItemMediaService::class);
        $items->ensure($pet);
        ircComplete();
        // The key image passes the realism rubric (stored check), only its clip shows the bed floating.
        $image = ircRow($pet, 'image');
        ItemMediaQualityCheck::create(['item_media_id' => $image->id, 'generation' => $image->generation, 'trigger' => 'recheck', 'status' => 'decided', 'rubric' => 'item', 'verdict' => 'pass', 'ai_status' => 'passed', 'decided_at' => now()]);
        $approved = ircPet('bed', 'dog_bed_round_grey');
        $items->ensure($approved);
        ircComplete();
        ItemMedia::where('pet_id', $approved->id)->update(['quality' => PetMedia::QUALITY_APPROVED]);

        config(['media.quality_check.stills_mode' => 'enforce']);
        $this->artisan('media:item-recheck --run')->assertSuccessful()->expectsOutputToContain('regenerating_key_image 1');

        expect(ircRow($pet, 'clip')->quality)->toBe(PetMedia::QUALITY_FLAGGED)->and(ircRow($pet, 'clip')->status)->toBe('pending')
            ->and(ircRow($pet, 'image')->quality)->toBe(PetMedia::QUALITY_FLAGGED)->and(ircRow($pet, 'image')->status)->toBe('running')
            ->and($items->statesFor($pet->fresh())['bed']['clip'])->toBeNull()
            ->and(ItemMediaQualityCheck::whereIn('item_media_id', ItemMedia::where('pet_id', $approved->id)->pluck('id'))->count())->toBe(0);

        ircComplete();
        expect(ircRow($pet, 'clip')->status)->toBe('ready')->and(ircRow($pet, 'clip')->source_path)->toBe(ircRow($pet, 'image')->storage_path)
            ->and($items->statesFor($pet->fresh())['bed']['outcome'])->toBe(ItemMediaService::OUTCOME_CONTENT);
    });
});

/* ─────────────────────────── Rubric ─────────────────────────── */

describe('rubric', function () {
    it('the item rubrics ask grounded / integrated / scale and fail on any false key', function () {
        $vision = app(MediaVisionCheck::class);
        $still = $vision->stillPrompt(MediaVisionCheck::RUBRIC_ITEM, Species::Dog, 'lying curled up asleep in a navy bed');
        $clip = $vision->itemClipPrompt(Species::Dog, 'sleeping peacefully in its bed', 'lying curled up asleep in a navy bed');
        $ok = array_fill_keys(MediaVisionCheck::STILL_KEYS[MediaVisionCheck::RUBRIC_ITEM], true) + ['verdict' => 'pass'];

        expect($still)->toContain('item_grounded')->toContain('item_integrated')->toContain('scale_ok')->toContain('lies INSIDE it')
            ->and($clip)->toContain('contact sheet')->toContain('first frame at full size')->toContain('no_artifacts')
            ->and(MediaVisionCheck::stillFails(MediaVisionCheck::RUBRIC_ITEM, $ok))->toBeFalse()
            ->and(MediaVisionCheck::stillFails(MediaVisionCheck::RUBRIC_ITEM, ['scale_ok' => false] + $ok))->toBeTrue()
            ->and(MediaVisionCheck::parseStill(MediaVisionCheck::RUBRIC_ITEM, (string) json_encode(['one_animal' => true, 'verdict' => 'pass'])))->toBeNull();
    });
});

/* ─────────────────────────── QA #1414 ─────────────────────────── */

/** A pet whose item content was made before the realism check (checks off): image + clip ready, content shown. */
function ircMadeBefore(string $itemKey = 'bed', string $design = 'dog_bed_navy_bolster'): Pet
{
    config(['media.quality_check.stills_mode' => 'off']);
    useQualityCheck('off');
    $pet = ircPet($itemKey, $design);
    app(ItemMediaService::class)->ensure($pet);
    ircComplete();
    config(['media.quality_check.stills_mode' => 'enforce']);
    useQualityCheck('shadow');

    return $pet->fresh();
}

describe('QA #1414', function () {
    it('M3: a file flagged before this change (no flagged_at) stays shown; only a new flag hides it', function () {
        ircNeedsFfmpeg();
        ircFakeFal();
        $pet = ircMadeBefore('treats', '');
        $items = app(ItemMediaService::class);
        // As production may have them: flagged by the old still check, never hidden.
        ItemMedia::where('pet_id', $pet->id)->update(['quality' => PetMedia::QUALITY_FLAGGED, 'flagged_at' => null]);

        expect($items->statesFor($pet->fresh())['treats']['outcome'])->toBe(ItemMediaService::OUTCOME_CONTENT)
            ->and($items->actionReady($pet->fresh(), 'treats'))->toBeTrue();

        $image = ircRow($pet, 'image');
        expect($items->recordQuality($image, (string) $image->storage_path, PetMedia::QUALITY_FLAGGED, 'pasted'))->toBeTrue()
            ->and($image->fresh()->isHidden())->toBeTrue()
            ->and($items->statesFor($pet->fresh())['treats']['image'])->toBeNull()
            ->and($items->actionReady($pet->fresh(), 'treats'))->toBeFalse();
    });

    it('M1: a flag tells the pets at once; an app URL of a hidden file answers 404, the signature-only URL still serves it', function () {
        ircNeedsFfmpeg();
        ircFakeFal(fn (ItemMedia $row, string $rubric) => $rubric === MediaVisionCheck::RUBRIC_ITEM ? ['item_integrated'] : []);
        $pet = ircMadeBefore();
        $image = ircRow($pet, 'image');
        $appUrl = app(ItemMediaService::class)->signedUrl($image, $pet);
        $this->get(str_replace('https://api.petprep.si', '', $appUrl))->assertOk();

        Event::fake([PetUpdated::class]);
        $this->artisan('media:item-recheck --run --max-regenerations=1')->assertSuccessful();

        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'item_media_ready');
        expect(ItemMedia::find($image->id)->isHidden())->toBeTrue();
        $this->get(str_replace('https://api.petprep.si', '', $appUrl))->assertNotFound();
        $this->get(str_replace('https://api.petprep.si', '', app(ItemMediaService::class)->signedUrl(ItemMedia::find($image->id))))->assertOk();
    });

    it('M2: the recheck checks only what an active owner shows now (used-up treats and inactive pets are skipped)', function () {
        ircNeedsFfmpeg();
        ircFakeFal();
        $shown = ircMadeBefore();
        $usedUp = ircMadeBefore('treats', '');
        PetItem::where('pet_id', $usedUp->id)->update(['quantity_left' => 0]);
        $inactive = ircMadeBefore('bed', 'dog_bed_round_grey');
        $inactive->forceFill(['is_active' => false])->saveQuietly();

        $candidates = app(StillQualityService::class)->recheckCandidates();
        expect($candidates->pluck('pet_id')->unique()->values()->all())->toBe([$shown->id])
            ->and($candidates->map(fn (ItemMedia $r) => $r->kind)->all())->toBe(['image', 'clip']);

        $this->artisan('media:item-recheck')->assertSuccessful()->expectsOutputToContain('1 key image(s), 1 clip(s).');
        $this->artisan('media:item-recheck --run')->assertSuccessful();
        expect(ItemMediaQualityCheck::whereIn('item_media_id', ItemMedia::whereIn('pet_id', [$usedUp->id, $inactive->id])->pluck('id'))->count())->toBe(0);
    });

    it('M4: a stored clip that fails for any reason starts a fresh key image chain, never a clip from the same start frame', function () {
        ircNeedsFfmpeg();
        ircFakeFal(fn (ItemMedia $row, string $rubric) => $rubric === MediaVisionCheck::RUBRIC_ITEM_CLIP && $row->quality === null ? ['anatomy_ok'] : []);
        $pet = ircMadeBefore();
        $image = ircRow($pet, 'image');
        // The key image already used both of its pipeline retries: a fresh chain anyway.
        $image->update(['quality_retries' => 2]);
        ItemMediaQualityCheck::create(['item_media_id' => $image->id, 'generation' => $image->generation, 'trigger' => 'recheck', 'status' => 'decided', 'rubric' => 'item', 'verdict' => 'pass', 'ai_status' => 'passed', 'decided_at' => now()]);
        $edits = ircSubmits('edit')->count();

        $this->artisan('media:item-recheck --run')->assertSuccessful()->expectsOutputToContain('regenerating_key_image 1');

        expect(ircSubmits('edit')->count())->toBe($edits + 1)
            ->and(ircRow($pet, 'image')->quality_retries)->toBe(1)->and(ircRow($pet, 'image')->prompt_variant)->toBe(0)
            ->and(ircRow($pet, 'clip')->isHidden())->toBeTrue();
    });

    it('m1: an item clip checked without its AI part says »not AI-checked« and why', function () {
        ircNeedsFfmpeg();
        config(['media.quality_check.clip_ai' => false]);
        useQualityCheck('enforce');
        ircFakeFal();
        $pet = ircPet();
        app(ItemMediaService::class)->ensure($pet);
        ircComplete();

        $clip = ircRow($pet, 'clip');
        expect($clip->quality)->toBe(PetMedia::QUALITY_PASSED_TECHNICAL)
            ->and($clip->quality_reason)->toBe('Not AI-checked (clip AI call switched off)');
    });

    it('m2: a vision answer without a verdict is checked again (bounded), then used »not AI-checked«; in a recheck it is an error, not a pass, and not re-selected', function () {
        ircNeedsFfmpeg();
        $malformed = 1;
        ircFakeFal(function (ItemMedia $row, string $rubric) use (&$malformed) {
            if ($rubric === MediaVisionCheck::RUBRIC_ITEM_CLIP && $malformed > 0) {
                $malformed--;

                return ['__malformed'];
            }

            return [];
        });
        $pet = ircPet();
        $stills = app(StillQualityService::class);
        app(ItemMediaService::class)->ensure($pet);
        ircComplete();
        $clip = ircRow($pet, 'clip');
        // The sync queue cannot release: the check came back »retry«, the clip waits unshown.
        expect($clip->status)->toBe('running')->and($clip->candidate_path)->not->toBeNull()
            ->and(ItemMediaQualityCheck::where('item_media_id', $clip->id)->count())->toBe(0);
        expect($stills->checkItemCandidate($clip->id))->toBe('promoted')
            ->and($clip->fresh()->quality)->toBe(PetMedia::QUALITY_PASSED);

        // Always malformed: after VISION_ERROR_RETRIES the clip is used unchecked.
        $malformed = 99;
        $other = ircPet('bed', 'dog_bed_round_grey');
        app(ItemMediaService::class)->ensure($other);
        ircComplete();
        $otherClip = ircRow($other, 'clip');
        // The pipeline's own run was try 1 (»retry«); the job's next runs: try 2 »retry«, try 3 decides.
        $outcomes = [];
        for ($i = 0; $i < StillQualityService::VISION_ERROR_RETRIES; $i++) {
            $outcomes[] = $stills->checkItemCandidate($otherClip->id);
        }
        expect($outcomes)->toBe(['retry', 'promoted'])->and($otherClip->fresh()->quality)->toBe(PetMedia::QUALITY_UNCHECKED)
            ->and(AiSpendLedger::where('item_media_id', $otherClip->id)->where('purpose', 'quality_check')->count())->toBe(StillQualityService::VISION_ERROR_RETRIES + 1);

        // A recheck whose vision call errors: »error«, nothing recorded on the file, not audited, not re-selected.
        $old = ircMadeBefore('bed', 'dog_bed_wicker_checked');
        $malformed = 99;
        ItemMediaQualityCheck::create(['item_media_id' => ircRow($old, 'image')->id, 'generation' => ircRow($old, 'image')->generation, 'trigger' => 'recheck', 'status' => 'decided', 'rubric' => 'item', 'verdict' => 'pass', 'ai_status' => 'passed', 'decided_at' => now()]);
        $audits = AppSettingChange::where('key', ItemRecheckCommand::AUDIT_KEY)->count();
        // Two clips: the old bed's and the one used unchecked above (never AI-decided).
        $this->artisan('media:item-recheck --run')->assertSuccessful()->expectsOutputToContain('error 2');
        expect(ircRow($old, 'clip')->quality)->toBeNull()->and($otherClip->fresh()->quality)->toBe(PetMedia::QUALITY_UNCHECKED)
            ->and(AppSettingChange::where('key', ItemRecheckCommand::AUDIT_KEY)->count())->toBe($audits);
        $this->artisan('media:item-recheck')->assertSuccessful()->expectsOutputToContain('0 key image(s), 0 clip(s)');
    });

    it('m4: a clip whose start frame is not its current key image is never judged against it (no paid call)', function () {
        ircNeedsFfmpeg();
        ircFakeFal();
        $pet = ircMadeBefore();
        $clip = ircRow($pet, 'clip');
        $clip->update(['source_path' => 'somewhere/else.jpg']);
        $calls = ircVision()->count();

        expect(app(StillQualityService::class)->recheckItemRealism($clip->fresh())['outcome'])->toBe('skipped')
            ->and(ircVision()->count())->toBe($calls);
    });

    it('m5: one real run at a time; --max-regenerations=0 is fine in a dry run', function () {
        ircFakeFal();
        $this->artisan('media:item-recheck --max-regenerations=0')->assertSuccessful()->expectsOutputToContain('at most 0 per run')->expectsOutputToContain('Dry run');

        ircNeedsFfmpeg();
        ircMadeBefore();
        $lock = Cache::lock(ItemRecheckCommand::LOCK_KEY, 60);
        expect($lock->get())->toBeTrue();
        $this->artisan('media:item-recheck --run')->assertSuccessful()->expectsOutputToContain('Another media:item-recheck --run is running');
        $lock->release();
        expect(ItemMediaQualityCheck::where('trigger', 'recheck')->count())->toBe(0);
    });
});

/* ─────────────────────────── Bowls: the drinking clip (David 2026-11-20) ─────────────────────────── */

describe('bowls drinking clip check', function () {
    it('judges the drinking clip on drinking (intent + scene), the eating clip on eating — same start frame', function () {
        ircNeedsFfmpeg();
        ircFakeFal();
        $pet = ircPet('bowls', 'dog_bowls_steel_stand');
        app(ItemMediaService::class)->ensure($pet);
        ircComplete();

        $image = ircRow($pet, 'image');
        $eat = ircRow($pet, 'clip');
        $drink = ircRow($pet, 'drink_clip');
        $sheets = ircVision()->filter(fn (array $b) => str_contains((string) $b['image_urls'][0], '/api/media/item-quality-sheets/'))->values();
        $drinkCall = $sheets->first(fn (array $b) => str_contains((string) $b['prompt'], PetAppearancePrompt::DRINK_MOTION));
        $eatCall = $sheets->first(fn (array $b) => ! str_contains((string) $b['prompt'], 'drinking water'));

        expect($sheets)->toHaveCount(2)
            ->and($drinkCall['prompt'])->toContain('with its head lowered over the water bowl')->not->toContain('eating from the food bowl')
            ->and($drinkCall['image_urls'][1])->toContain("/api/media/items/{$image->id}")
            ->and($eatCall['prompt'])->toContain('eating from the food bowl')
            ->and($eatCall['image_urls'][1])->toContain("/api/media/items/{$image->id}")
            ->and(ItemMediaQualityCheck::where('item_media_id', $drink->id)->sole()->rubric)->toBe(MediaVisionCheck::RUBRIC_ITEM_CLIP)
            ->and([$eat->status, $drink->status])->toBe(['ready', 'ready'])
            ->and($drink->source_path)->toBe($image->storage_path)
            ->and(app(ItemMediaService::class)->statesFor($pet->fresh())['bowls']['drink_clip']?->id)->toBe($drink->id);
    });

    it('a drinking clip that fails its check on every try is given up at once — drink_video null, the bowls stay ready', function () {
        ircNeedsFfmpeg();
        Queue::fake([SendItemMediaAlert::class]);
        ircFakeFal(fn (ItemMedia $row, string $rubric) => $row->isDrinkClip() ? ['action_done'] : []);
        $pet = ircPet('bowls', 'dog_bowls_steel_stand');
        app(ItemMediaService::class)->ensure($pet);
        ircComplete();

        $drink = ircRow($pet, 'drink_clip');
        $payload = app(ItemMediaService::class)->payloadFor($pet->fresh());
        expect($drink->status)->toBe('failed')->and($drink->exhausted_at)->not->toBeNull()
            ->and(ItemMediaQualityCheck::where('item_media_id', $drink->id)->count())->toBe(StillQualityService::maxRetries() + 1)
            ->and($payload['bowls']['video'])->not->toBeNull()->and($payload['bowls']['drink_video'])->toBeNull()
            ->and(app(ItemMediaService::class)->readiness($pet->fresh()))->toBe(['bowls' => true])
            ->and(ircRow($pet, 'clip')->status)->toBe('ready')->and(ircRow($pet, 'image')->isShown())->toBeTrue();
    });
});
