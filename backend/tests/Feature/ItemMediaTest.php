<?php

use App\Enums\PushType;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Filament\Resources\ItemMediaResource\Pages\ListItemMedia;
use App\Jobs\SendItemMediaAlert;
use App\Jobs\StoreItemMedia;
use App\Models\AiSpendLedger;
use App\Models\ItemMedia;
use App\Models\ItemMediaNotice;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetWish;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\CareScoreService;
use App\Services\FalWebhookVerifier;
use App\Services\Media\FalGateway;
use App\Services\Media\ItemMediaService;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Shop\ItemDesigns;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M6-W03 »AI vsebine stvari« (SHOP_SPEC §3 step 4 / §5, David 2026-11-03)
|--------------------------------------------------------------------------
| Designs (child picks, parent buys exactly that one), catalogue pictures,
| pet-with-item key image + clip per shared look + design + stage (0 $ for
| later buyers), stage change, treats once, budget wait, self-healing (prompt
| variant after no_media_generated → exhausted → fallback + one alert), the
| `media.items` payload with signed URLs, `media_ready`, backfill by the
| scheduler, `item_media_ready` + `item_ready` once, pets without AI media, S1.
| Family in Europe/Ljubljana; 2026-11-03 10:00 UTC = 11:00 local (no quiet hours).
*/

const IM_SECRET = 'rc-item-secret';

const IM_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

const IM_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    Cache::forget(FalWebhookVerifier::CACHE_KEY);
    Cache::forget(FalGateway::BALANCE_FLAG_KEY);
    config([
        'services.revenuecat.webhook_secret' => IM_SECRET,
        'services.revenuecat.accept_sandbox' => true,
        'services.fal_ai.key' => 'test-key',
        'app.url' => 'https://api.petprep.si',
        'media.budget.daily_usd' => 50.0,
        'media.budget.monthly_usd' => 500.0,
        'media.budget.timezone' => 'UTC',
        'push.enabled' => true,
    ]);
    $this->withoutMiddleware([ThrottleRequests::class]);
    imFakeFal();
});

afterEach(function () {
    Carbon::setTestNow();
});

function imFakeFal(): void
{
    fakeFalJwks();
    $n = 0;
    Http::fake([
        // Item key images (Nano Banana Pro Edit) — queued.
        'queue.fal.run/fal-ai/nano-banana-pro/edit*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "im-edit-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/im-edit-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/im-edit-{$n}"]);
        },
        // Catalogue pictures (Nano Banana Pro text-to-image) — queued.
        'queue.fal.run/fal-ai/nano-banana-pro*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "im-pic-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/im-pic-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/nano-banana-pro/requests/im-pic-{$n}"]);
        },
        // Clips (Kling).
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "im-clip-{$n}", 'status_url' => "https://queue.fal.run/fal-ai/kling-video/requests/im-clip-{$n}/status", 'response_url' => "https://queue.fal.run/fal-ai/kling-video/requests/im-clip-{$n}"]);
        },
        'v3.fal.media/files/im/*.jpg' => fn () => Http::response(IM_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/im/*.mp4' => fn () => Http::response(IM_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** Answer every submitted item request with a successful signed webhook (loops: picture → image → clip). */
function imComplete(): int
{
    $done = 0;
    for ($round = 0; $round < 8; $round++) {
        $rows = ItemMedia::query()->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            break;
        }
        foreach ($rows as $row) {
            $payload = $row->isStill()
                ? ['images' => [['url' => "https://v3.fal.media/files/im/{$row->request_id}.jpg"]]]
                : ['video' => ['url' => "https://v3.fal.media/files/im/{$row->request_id}.mp4"]];
            sendFalWebhook(['request_id' => $row->request_id, 'status' => 'OK', 'payload' => $payload])->assertOk();
            $done++;
        }
    }

    return $done;
}

/** Fail the running request of $row at fal (webhook ERROR, optionally fal's deterministic refusal). */
function imFailWebhook(ItemMedia $row, bool $noMedia = false): void
{
    sendFalWebhook([
        'request_id' => $row->request_id,
        'status' => 'ERROR',
        'error' => 'Unprocessable',
        'payload' => $noMedia ? ['detail' => [['loc' => ['body'], 'msg' => 'No media generated', 'type' => 'no_media_generated']]] : [],
    ])->assertOk();
}

/** @return Collection<int, Request> */
function imFalSubmits(?string $kind = null)
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://queue.fal.run/')
        && ! str_contains($r->url(), '/requests/')
        && match ($kind) {
            'picture' => str_contains($r->url(), 'nano-banana-pro') && ! str_contains($r->url(), '/edit'),
            'image' => str_contains($r->url(), 'nano-banana-pro/edit'),
            'clip' => ! str_contains($r->url(), 'nano-banana-pro'),
            default => true,
        })->map(fn (array $pair) => $pair[0]);
}

function imLook(string $breed = 'border_collie'): PetLook
{
    return PetLook::create([
        'breed_type' => $breed,
        'pool_index' => random_int(1, 30000),
        'trait_fingerprint' => Str::random(16),
        'dna' => ['seed' => 4242, 'traits' => ['coat_color' => 'black and white']],
        'colour_key' => 'black_white',
    ]);
}

/** The pet shows $stage's reference image — its own, or its look's (pet slot linked to the look row). */
function imPicture(Pet $pet, string $stage = 'young', ?PetLook $look = null, int $generation = 1): PetMedia
{
    $disk = Storage::disk('pet_media');
    if ($look !== null) {
        $path = "looks/{$look->id}/reference-{$stage}-g{$generation}.jpg";
        $disk->put($path, IM_JPEG.str_repeat("\x00", 64));
        $row = PetMedia::query()->updateOrCreate(
            ['pet_look_id' => $look->id, 'kind' => 'image', 'state' => null, 'life_stage' => $stage],
            ['pet_id' => null, 'status' => 'ready', 'storage_path' => $path, 'mime' => 'image/jpeg', 'generation' => $generation],
        );

        return PetMedia::query()->updateOrCreate(
            ['pet_id' => $pet->id, 'kind' => 'image', 'state' => null],
            ['status' => 'ready', 'storage_path' => $path, 'look_media_id' => $row->id, 'life_stage' => $stage, 'mime' => 'image/jpeg', 'generation' => $generation],
        );
    }
    $path = "{$pet->id}/reference-g{$generation}.jpg";
    $disk->put($path, IM_JPEG.str_repeat("\x00", 64));

    return PetMedia::query()->updateOrCreate(
        ['pet_id' => $pet->id, 'kind' => 'image', 'state' => null],
        ['status' => 'ready', 'storage_path' => $path, 'life_stage' => $stage, 'mime' => 'image/jpeg', 'generation' => $generation],
    );
}

/**
 * Parent + child + a born dog with AI media (its own picture, or $look's).
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function imFamily(?PetLook $look = null, array $attributes = [], bool $withPicture = true): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->borderCollie()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now()->subDay(),
        'last_decay_at' => now(),
        'media_status' => 'ready',
        'pet_look_id' => $look?->id,
    ], $attributes));
    disableHygieneEvents($pet);
    if ($withPicture) {
        imPicture($pet, 'young', $look);
    }

    return [$parent, $child, $pet->fresh()];
}

function imAs(User $user): void
{
    app('auth')->forgetGuards();
    actingAsRole($user);
}

/** A store purchase of $product reaching the server (RevenueCat webhook → one wish credit). */
function imBuy(User $parent, string $product = 'petprep_wish_bed'): void
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => 'Bearer '.IM_SECRET])->postJson('/api/webhooks/revenuecat', ['api_version' => '1.0', 'event' => [
        'id' => (string) Str::uuid(),
        'type' => 'NON_RENEWING_PURCHASE',
        'app_user_id' => (string) $parent->id,
        'aliases' => [],
        'product_id' => $product,
        'store' => 'APP_STORE',
        'environment' => 'PRODUCTION',
        'transaction_id' => 'tx-'.Str::random(10),
        'purchased_at_ms' => Carbon::now()->getTimestampMs(),
        'expiration_at_ms' => null,
        'event_timestamp_ms' => Carbon::now()->getTimestampMs(),
    ]])->assertOk();
}

/** @param  array<string, mixed>  $extra */
function imRedeem(User $parent, Pet $pet, string $product = 'petprep_wish_bed', array $extra = []): TestResponse
{
    imBuy($parent, $product);
    imAs($parent);

    return postJson("/api/parent/pets/{$pet->id}/items/redeem", ['product_id' => $product] + $extra);
}

function imChildState(User $child): TestResponse
{
    imAs($child);

    return getJson('/api/child/pet')->assertOk();
}

describe('designs', function () {
    it('defines three designs per durable item and species with unique keys, names and descriptions', function () {
        $all = ItemDesigns::all();
        expect($all)->toHaveCount(24);
        foreach (['dog', 'cat'] as $species) {
            foreach (['bed', 'bowls', 'toy_1', 'toy_2'] as $item) {
                $list = ItemDesigns::for($item, Species::from($species));
                expect($list)->toHaveCount(3);
                foreach ($list as $d) {
                    expect($d['key'])->toStartWith($species.'_')
                        ->and($d['name']['en'])->not->toBe('')
                        ->and($d['name']['sl'])->not->toBe('')
                        ->and(strlen($d['description']))->toBeGreaterThan(40);
                }
            }
        }
        expect(ItemDesigns::for('treats', Species::Dog))->toBe([])
            ->and(ItemDesigns::description('dog_treats'))->toContain('dog biscuit');
    });

    it('lists the designs in the child catalogue (names in the request language); a wish defaults to the first design, refuses only unknown ones', function () {
        [, $child, $pet] = imFamily();
        imAs($child);

        $body = getJson('/api/child/wishes', ['Accept-Language' => 'sl'])->assertOk()->json();
        $bed = collect($body['items'])->firstWhere('key', 'bed');
        $treats = collect($body['items'])->firstWhere('key', 'treats');
        expect($bed['designs'])->toHaveCount(3)
            ->and($bed['designs'][0])->toBe(['key' => 'dog_bed_round_grey', 'name' => 'Okrogla siva postelja', 'image' => null])
            ->and($bed['design'])->toBeNull()
            ->and($treats['designs'])->toBe([]);

        postJson('/api/child/wishes', ['item_key' => 'bed', 'design' => 'cat_bed_grey_cave'])->assertStatus(422)->assertJsonPath('reason', 'invalid_design');
        postJson('/api/child/wishes', ['item_key' => 'treats', 'design' => 'dog_bed_round_grey'])->assertStatus(422)->assertJsonPath('reason', 'invalid_design');
        postJson('/api/child/wishes', ['item_key' => 'bed', 'design' => 'dog_bed_wicker_checked'])->assertCreated()
            ->assertJsonPath('wish.design', 'dog_bed_wicker_checked');
        // QA #655 m2: an app build without the picker sends no design → the item's first design.
        postJson('/api/child/wishes', ['item_key' => 'bowls'])->assertCreated()->assertJsonPath('wish.design', 'dog_bowls_steel_stand');

        $bed = collect(getJson('/api/child/wishes')->json('items'))->firstWhere('key', 'bed');
        expect($bed['status'])->toBe('requested')->and($bed['design'])->toBe('dog_bed_wicker_checked');
    });

    it('buys exactly the wished design; a surprise takes the parent\'s design or the first one; the bundle picks per item', function () {
        config(['services.fal_ai.key' => null]); // no AI here — only the designs
        [$parent, $child, $pet] = imFamily();
        imAs($child);
        $wish = postJson('/api/child/wishes', ['item_key' => 'bowls', 'design' => 'dog_bowls_bamboo_stand'])->assertCreated()->json('wish.id');

        // QA #655 m1: a paid purchase is never refused over a design — the wish's design wins.
        imRedeem($parent, $pet, 'petprep_wish_bowls', ['wish_id' => $wish, 'design' => 'dog_bowls_steel_stand'])->assertOk();
        expect(PetItem::where('pet_id', $pet->id)->where('item_key', 'bowls')->value('design'))->toBe('dog_bowls_bamboo_stand');

        imRedeem($parent, $pet, 'petprep_wish_toy_1', ['design' => 'dog_rope_rainbow'])->assertOk();
        // An unknown design → the first design (no 422).
        $items = imRedeem($parent, $pet, 'petprep_wish_toy_2', ['design' => 'nope'])->assertOk()->json('items');
        expect(PetItem::where('pet_id', $pet->id)->where('item_key', 'toy_1')->value('design'))->toBe('dog_rope_rainbow')
            ->and(PetItem::where('pet_id', $pet->id)->where('item_key', 'toy_2')->value('design'))->toBe('dog_squeaky_duck')
            ->and(collect($items)->firstWhere('key', 'toy_2')['design'])->toBe('dog_squeaky_duck')
            ->and(collect($items)->firstWhere('key', 'bed')['designs'])->toHaveCount(3);

        [$parent2, , $pet2] = imFamily();
        imRedeem($parent2, $pet2, 'petprep_wish_bundle_welcome', ['designs' => ['bowls' => 'dog_bowls_blue_ceramic']])->assertOk();
        expect(PetItem::where('pet_id', $pet2->id)->pluck('design', 'item_key')->all())
            ->toBe(['bed' => 'dog_bed_round_grey', 'bowls' => 'dog_bowls_blue_ceramic', 'toy_1' => 'dog_rope_red_white']);
    });

    it('gives an item owned before designs existed its first design (backfill rule)', function () {
        config(['services.fal_ai.key' => null]);
        [, $child, $pet] = imFamily();
        PetItem::create(['pet_id' => $pet->id, 'item_key' => 'bed', 'kind' => 'durable', 'variant' => 'dog_bed', 'quantity_total' => 1, 'quantity_left' => 1, 'acquired_at' => now()]);

        imChildState($child)->assertJsonPath('wishes.items.0.design', 'dog_bed_round_grey');
    });

    it('gives a cat bundle cat designs: the chosen ones, else the first of each item', function () {
        config(['services.fal_ai.key' => null]);
        seedBreedConfigs();
        [$parent, , $cat] = imFamily(null, ['breed_type' => 'siberian_cat'], withPicture: false);
        imRedeem($parent, $cat, 'petprep_wish_bundle_welcome', ['designs' => ['toy_1' => 'cat_mouse_white_knit', 'bed' => 'dog_bed_round_grey']])->assertOk();

        expect(PetItem::where('pet_id', $cat->id)->pluck('design', 'item_key')->all())
            ->toBe(['bed' => 'cat_basket_wicker_cream', 'bowls' => 'cat_bowls_white_gold', 'toy_1' => 'cat_mouse_white_knit']);
    });

    it('migration backfill: durable items and open wishes get the first design of their species, treats and decided wishes none', function () {
        seedBreedConfigs();
        [, $child, $dog] = imFamily(null, [], withPicture: false);
        [, $catChild, $cat] = imFamily(null, ['breed_type' => 'siberian_cat'], withPicture: false);
        $rows = [
            'dog_bed' => PetItem::create(['pet_id' => $dog->id, 'item_key' => 'bed', 'kind' => 'durable', 'variant' => 'dog_bed', 'quantity_total' => 1, 'quantity_left' => 1, 'acquired_at' => now()]),
            'cat_toy' => PetItem::create(['pet_id' => $cat->id, 'item_key' => 'toy_2', 'kind' => 'durable', 'variant' => 'cat_feather', 'quantity_total' => 1, 'quantity_left' => 1, 'acquired_at' => now()]),
            'treats' => PetItem::create(['pet_id' => $dog->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 10, 'acquired_at' => now()]),
        ];
        $open = PetWish::create(['pet_id' => $cat->id, 'child_user_id' => $catChild->id, 'item_key' => 'bowls', 'status' => 'open']);
        $declined = PetWish::create(['pet_id' => $dog->id, 'child_user_id' => $child->id, 'item_key' => 'bowls', 'status' => 'declined']);

        $migration = require database_path('migrations/2027_03_07_120000_add_item_media.php');
        (new ReflectionMethod($migration, 'backfillDesigns'))->invoke($migration);

        expect($rows['dog_bed']->fresh()->design)->toBe('dog_bed_round_grey')
            ->and($rows['cat_toy']->fresh()->design)->toBe('cat_wand_rainbow_feathers')
            ->and($rows['treats']->fresh()->design)->toBeNull()
            ->and($open->fresh()->design)->toBe('cat_bowls_white_gold')
            ->and($declined->fresh()->design)->toBeNull();
    });
});

describe('catalogue pictures', function () {
    it('makes one picture per design once (square, no animal) through the scheduler', function () {
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('picture'))->toHaveCount(24);
        $body = imFalSubmits('picture')->first()->data();
        expect($body['aspect_ratio'])->toBe('1:1')->and($body['prompt'])->toContain('no animal');
        expect(AiSpendLedger::where('purpose', 'item_media')->count())->toBe(24)
            ->and(round((float) AiSpendLedger::where('purpose', 'item_media')->sum('cost_usd'), 2))->toBe(3.6);

        imComplete();
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('picture'))->toHaveCount(24); // made once

        [, $child] = imFamily();
        imAs($child);
        $image = collect(getJson('/api/child/wishes')->json('items'))->firstWhere('key', 'bed')['designs'][0]['image'];
        expect($image)->toContain('/api/media/items/')->toContain('signature=')->not->toContain('fal.media');
        // A product picture (no child data): served with the signature alone, also to a signed-in app.
        get(str_replace('https://api.petprep.si', '', $image))->assertOk();
    });
});

describe('pet content', function () {
    it('queues the content of a bought item once and shows it in media.items with signed URLs; event + push once', function () {
        Event::fake([PetUpdated::class]);
        $look = imLook();
        [$parent, $child, $pet] = imFamily($look);
        imAs($child);
        $wish = postJson('/api/child/wishes', ['item_key' => 'bed', 'design' => 'dog_bed_navy_bolster'])->json('wish.id');

        imRedeem($parent, $pet, 'petprep_wish_bed', ['wish_id' => $wish])->assertOk()
            ->assertJsonPath('items.0.media_ready', false);
        // Waits for the design's catalogue picture first (the 2nd reference image).
        expect(imFalSubmits('picture'))->toHaveCount(1)->and(imFalSubmits('image'))->toHaveCount(0);
        $state = imChildState($child);
        $state->assertJsonPath('wishes.items.0.media_ready', false)->assertJsonPath('wishes.items.0.design', 'dog_bed_navy_bolster');
        expect($state->json('pet.media.items'))->toBe([]);

        imComplete();

        expect(imFalSubmits('image'))->toHaveCount(1)->and(imFalSubmits('clip'))->toHaveCount(1);
        $edit = imFalSubmits('image')->first()->data();
        expect($edit['image_urls'])->toHaveCount(2)
            ->and($edit['prompt'])->toContain('navy-blue canvas cover')->toContain('second reference image')->toContain('asleep');
        $clip = imFalSubmits('clip')->first()->data();
        expect($clip)->not->toHaveKey('end_image_url')->and($clip['duration'] ?? null)->not->toBe('5');

        $state = imChildState($child)->assertJsonPath('wishes.items.0.media_ready', true);
        $bed = $state->json('pet.media.items.bed');
        expect(array_keys($bed))->toBe(['image', 'video', 'video_seconds', 'loop'])
            ->and($bed['loop'])->toBeTrue()
            ->and($bed['image'])->toContain('/api/media/items/')->toContain('pet='.$pet->id)->not->toContain('fal.media')
            ->and($bed['video'])->toContain('/api/media/items/');
        imAs($child);
        get(str_replace('https://api.petprep.si', '', $bed['video']))->assertOk();

        // Another family's parent may not use the URL with a token.
        [$stranger, , $strangerPet] = imFamily();
        imAs($stranger);
        $path = str_replace('https://api.petprep.si', '', $bed['video']);
        get($path)->assertForbidden();
        // QA #655 m6: the row belongs to another pet → 404 (a correctly signed URL for the wrong pet).
        $row = ItemMedia::where('kind', 'clip')->firstOrFail();
        $wrongPet = str_replace('https://api.petprep.si', '', app(ItemMediaService::class)->signedUrl($row, $strangerPet));
        get($wrongPet)->assertNotFound();
        // fal's start-frame URL (no `pet`) refuses any token, but works without one.
        $falUrl = str_replace('https://api.petprep.si', '', app(ItemMediaService::class)->falFetchUrl($row));
        imAs($child);
        get($falUrl)->assertForbidden();
        app('auth')->forgetGuards();
        $this->flushHeaders();
        get($falUrl)->assertOk();

        app(ItemMediaService::class)->settle($pet->fresh());
        artisan('media:item-media')->assertSuccessful();
        expect(PushNotification::where('type', PushType::ItemReady->value)->where('pet_id', $pet->id)->count())->toBe(1)
            ->and(PushNotification::where('type', PushType::ItemReady->value)->value('metric'))->toBe('bed');
        expect(collect(Event::dispatched(PetUpdated::class))->filter(fn ($e) => $e[0]->eventType === 'item_media_ready' && $e[0]->petId === $pet->id))->toHaveCount(1);

        imAs($parent);
        $item = collect(getJson('/api/parent/wishes')->json('pets.0.items'))->firstWhere('key', 'bed');
        expect($item['media_ready'])->toBeTrue()->and($item['design'])->toBe('dog_bed_navy_bolster');
    });

    it('costs 0 $ for a later buyer of the same design of the same look and stage', function () {
        $look = imLook();
        [$parent, , $pet] = imFamily($look);
        imRedeem($parent, $pet, 'petprep_wish_bed', ['design' => 'dog_bed_round_grey'])->assertOk();
        imComplete();
        $calls = imFalSubmits()->count();
        $spent = (float) AiSpendLedger::sum('cost_usd');

        [$parent2, $child2, $pet2] = imFamily($look);
        imRedeem($parent2, $pet2, 'petprep_wish_bed', ['design' => 'dog_bed_round_grey'])->assertOk()
            ->assertJsonPath('items.0.media_ready', true);
        expect(imFalSubmits()->count())->toBe($calls)->and((float) AiSpendLedger::sum('cost_usd'))->toBe($spent);
        expect(imChildState($child2)->json('pet.media.items.bed.video'))->not->toBeNull();
        expect(PushNotification::where('type', 'item_ready')->where('pet_id', $pet2->id)->count())->toBe(1);

        // Another design of the same look → its own content.
        [$parent3, , $pet3] = imFamily($look);
        imRedeem($parent3, $pet3, 'petprep_wish_bed', ['design' => 'dog_bed_wicker_checked'])->assertOk();
        expect(imFalSubmits('picture')->count())->toBe(2);
    });

    it('makes its own content for a pet with unique media', function () {
        [$parent, $child, $pet] = imFamily(null);
        imRedeem($parent, $pet, 'petprep_wish_toy_2', ['design' => 'dog_squeaky_ball'])->assertOk();
        imComplete();

        expect(ItemMedia::where('scope', 'pet')->where('pet_id', $pet->id)->count())->toBe(2)
            ->and(imChildState($child)->json('pet.media.items.toy_2.loop'))->toBeTrue();
        expect(ItemMedia::where('scope', 'pet')->value('storage_path'))->toStartWith($pet->id.'/item-');
    });

    it('makes new content when the pet reaches a new life stage (shared again), hiding the old stage', function () {
        $look = imLook();
        [$parent, $child, $pet] = imFamily($look);
        imRedeem($parent, $pet, 'petprep_wish_bed', ['design' => 'dog_bed_round_grey'])->assertOk();
        imComplete();
        expect(imChildState($child)->json('pet.media.items.bed'))->not->toBeNull();

        imPicture($pet, 'adult', $look);
        expect(imChildState($child)->json('wishes.items.0.media_ready'))->toBeFalse()
            ->and(imChildState($child)->json('pet.media.items'))->toBe([]);

        artisan('media:item-media')->assertSuccessful();
        imComplete();
        expect(imFalSubmits('image'))->toHaveCount(2)->and(imFalSubmits('clip'))->toHaveCount(2) // one key image + one clip for the new stage
            ->and(imFalSubmits('picture')->filter(fn (Request $r) => str_contains($r->data()['prompt'], 'light grey fleece')))->toHaveCount(1) // the design's picture is not made again
            ->and(ItemMedia::where('scope', 'look')->where('kind', 'image')->pluck('life_stage')->sort()->values()->all())->toBe(['adult', 'young'])
            ->and(imChildState($child)->json('wishes.items.0.media_ready'))->toBeTrue();
        // A new stage refreshes the picture — no second »new bed« push.
        expect(PushNotification::where('type', 'item_ready')->where('pet_id', $pet->id)->count())->toBe(1);
    });

    it('makes the treat content once per look and stage (a pack of 10 is not 10 generations) and returns it from /treat', function () {
        $look = imLook();
        [$parent, $child, $pet] = imFamily($look);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        imComplete();

        expect(imFalSubmits('picture'))->toHaveCount(0)
            ->and(imFalSubmits('image'))->toHaveCount(1)
            ->and(imFalSubmits('clip'))->toHaveCount(1)
            ->and(imFalSubmits('image')->first()->data()['image_urls'])->toHaveCount(1)
            ->and(imFalSubmits('image')->first()->data()['prompt'])->toContain('bone-shaped dog biscuit');

        imAs($child);
        $media = postJson('/api/child/pet/treat')->assertOk()->json('media');
        expect($media['loop'])->toBeFalse()->and($media['video_url'])->toBe($media['video'])->and($media['video'])->toContain('/api/media/items/');
        expect(imChildState($child)->json('pet.media.items.treats.loop'))->toBeFalse();
        expect(collect(imChildState($child)->json('wishes.items'))->pluck('key')->all())->toBe([]); // treats are not a durable item row
    });

    it('waits for the AI budget (»na poti«) and continues once it allows', function () {
        config(['media.budget.daily_usd' => 0.01]);
        [$parent, $child, $pet] = imFamily(null);
        PetItem::create(['pet_id' => $pet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 10, 'acquired_at' => now()]);

        artisan('media:item-media')->assertSuccessful();
        $row = ItemMedia::where('scope', 'pet')->where('kind', 'image')->first();
        expect($row->status)->toBe('failed')->and($row->error_reason)->toBe('budget_daily')
            ->and(imFalSubmits('image'))->toHaveCount(0)
            ->and(app(ItemMediaService::class)->readiness($pet->fresh()))->toBe(['treats' => false]);

        artisan('media:item-media')->assertSuccessful();
        expect($row->fresh()->exhausted_at)->toBeNull()->and($row->fresh()->auto_retries)->toBe(0);

        config(['media.budget.daily_usd' => 50.0]);
        artisan('media:item-media')->assertSuccessful();
        imComplete();
        expect(app(ItemMediaService::class)->readiness($pet->fresh()))->toBe(['treats' => true])
            ->and(imChildState($child)->json('pet.media.items.treats.video'))->not->toBeNull();
    });

    it('never repeats a refused request: next prompt variant, then gives up → shown without content, one alert', function () {
        Queue::fake([SendItemMediaAlert::class]);
        [$parent, $child, $pet] = imFamily(null);
        PetItem::create(['pet_id' => $pet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 10, 'acquired_at' => now()]);
        artisan('media:item-media')->assertSuccessful();
        $row = ItemMedia::where('scope', 'pet')->where('kind', 'image')->firstOrFail();
        imFailWebhook($row, noMedia: true);

        // Too early for the next try.
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('image'))->toHaveCount(1);

        $this->travel(31)->minutes();
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('image'))->toHaveCount(2)
            ->and($row->fresh()->prompt_variant)->toBe(1);
        $prompts = imFalSubmits('image')->map(fn (Request $r) => $r->data()['prompt'])->values();
        expect($prompts[0])->not->toBe($prompts[1]); // never the identical request
        expect(app(ItemMediaService::class)->readiness($pet->fresh()))->toBe(['treats' => false]);

        imFailWebhook($row->fresh(), noMedia: true);
        $this->travel(31)->minutes();
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('image'))->toHaveCount(2)
            ->and($row->fresh()->exhausted_at)->not->toBeNull()
            ->and(app(ItemMediaService::class)->readiness($pet->fresh()))->toBe(['treats' => true])
            ->and(imChildState($child)->json('pet.media.items'))->toBe([]);
        Queue::assertPushed(SendItemMediaAlert::class, fn ($job) => $job->count === 1);
        expect(ItemMediaNotice::where('pet_id', $pet->id)->value('outcome'))->toBe('fallback')
            ->and(PushNotification::where('type', 'item_ready')->where('pet_id', $pet->id)->count())->toBe(1);

        $this->travel(2)->hours();
        artisan('media:item-media')->assertSuccessful();
        Queue::assertPushed(SendItemMediaAlert::class, 1);
    });

    it('retries a generation failure at most twice per chain, then shows the picture without its clip', function () {
        Queue::fake([SendItemMediaAlert::class]);
        [$parent, $child, $pet] = imFamily(null);
        PetItem::create(['pet_id' => $pet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 10, 'acquired_at' => now()]);
        artisan('media:item-media')->assertSuccessful();
        imComplete(); // image ok → clip submitted, then answered ok too
        $clip = ItemMedia::where('kind', 'clip')->firstOrFail();
        // Make the clip fail three times.
        app(ItemMediaService::class)->regenerate($clip->fresh());
        foreach ([0, 1, 2] as $try) {
            imFailWebhook(ItemMedia::find($clip->id));
            $this->travel(31)->minutes();
            artisan('media:item-media')->assertSuccessful();
        }
        $clip->refresh();
        expect($clip->auto_retries)->toBe(2)->and($clip->exhausted_at)->not->toBeNull();
        // The stored clip of the earlier generation was made from the same picture → still shown.
        expect(imChildState($child)->json('pet.media.items.treats.video'))->not->toBeNull();
    });

    it('backfills items bought before W03 through the scheduler, once', function () {
        $look = imLook();
        [, $child, $pet] = imFamily($look);
        PetItem::create(['pet_id' => $pet->id, 'item_key' => 'bed', 'kind' => 'durable', 'variant' => 'dog_bed', 'quantity_total' => 1, 'quantity_left' => 1, 'acquired_at' => now()->subDays(4)]);

        artisan('media:item-media')->assertSuccessful();
        imComplete();
        $calls = imFalSubmits()->count();
        expect(imFalSubmits('image'))->toHaveCount(1)
            ->and(imFalSubmits('image')->first()->data()['prompt'])->toContain('light grey fleece')
            ->and(imChildState($child)->json('pet.media.items.bed.video'))->not->toBeNull();

        artisan('media:item-media')->assertSuccessful();
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits()->count())->toBe($calls)
            ->and(PushNotification::where('type', 'item_ready')->where('pet_id', $pet->id)->count())->toBe(1);
    });

    it('is ready at once without content for a pet without AI media', function () {
        Event::fake([PetUpdated::class]);
        [$parent, $child, $pet] = imFamily(null, ['media_status' => 'disabled'], withPicture: false);

        imRedeem($parent, $pet, 'petprep_wish_bed', ['design' => 'dog_bed_round_grey'])->assertOk()
            ->assertJsonPath('items.0.media_ready', true);
        expect(imFalSubmits())->toHaveCount(0)
            ->and(imChildState($child)->json('pet.media.items'))->toBe([])
            ->and(ItemMediaNotice::where('pet_id', $pet->id)->value('outcome'))->toBe('no_media')
            ->and(PushNotification::where('type', 'item_ready')->where('pet_id', $pet->id)->count())->toBe(1);
        expect(collect(Event::dispatched(PetUpdated::class))->filter(fn ($e) => $e[0]->eventType === 'item_media_ready'))->toHaveCount(1);
    });

    it('never touches game rules (S1) or the pet\'s state media set (S8)', function () {
        $look = imLook();
        [$parent, , $pet] = imFamily($look);
        $before = $pet->only(['hunger_level', 'thirst_level', 'hygiene_level', 'energy_level', 'escalation_level', 'state_media_version', 'pet_state']);
        $scoreOf = function (Pet $p): array {
            $scores = app(CareScoreService::class);

            return $scores->petSummary($scores->board(collect([$p]), 'Europe/Ljubljana'), $p);
        };
        $score = $scoreOf($pet);
        $media = PetMedia::where('pet_id', $pet->id)->count();

        imRedeem($parent, $pet, 'petprep_wish_bundle_welcome')->assertOk();
        imComplete();

        $pet->refresh();
        expect($pet->only(array_keys($before)))->toBe($before)
            ->and($scoreOf($pet))->toBe($score)
            ->and(PetMedia::where('pet_id', $pet->id)->count())->toBe($media);
        expect(ItemMedia::where('scope', 'look')->count())->toBe(6); // bed, bowls, toy_1: image + clip each
    });

    it('answers 404 to a webhook that beats our submit, and the admin list shows the rows', function () {
        [$parent, , $pet] = imFamily(null);
        $row = ItemMedia::create(['scope' => 'pet', 'pet_id' => $pet->id, 'item_key' => 'bed', 'design' => 'dog_bed_round_grey', 'kind' => 'image', 'status' => 'running']);
        AiSpendLedger::create(['purpose' => 'item_media', 'profile' => 'nano_banana_pro_edit', 'endpoint' => 'fal-ai/nano-banana-pro/edit', 'unit' => 'image', 'units' => 1, 'cost_usd' => 0.15, 'status' => 'committed', 'request_id' => 'early-1', 'item_media_id' => $row->id]);

        sendFalWebhook(['request_id' => 'early-1', 'status' => 'OK', 'payload' => ['images' => [['url' => 'https://v3.fal.media/files/im/x.jpg']]]])->assertNotFound();

        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();
        $this->actingAs($admin);
        Livewire::test(ListItemMedia::class)->assertCanSeeTableRecords([$row]);
    });

    it('shows the picture alone while the clip profile is off (never »Na poti« for ever), and refreshes once the clip comes — one push', function () {
        Event::fake([PetUpdated::class]);
        config(['media.profiles.video.kling_v3_pro.enabled' => false]);
        [, $child, $pet] = imFamily(null);
        PetItem::create(['pet_id' => $pet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 10, 'acquired_at' => now()]);

        artisan('media:item-media')->assertSuccessful();
        imComplete();
        expect(imFalSubmits('clip'))->toHaveCount(0)
            ->and(app(ItemMediaService::class)->readiness($pet->fresh()))->toBe(['treats' => true])
            ->and(imChildState($child)->json('pet.media.items.treats.video'))->toBeNull()
            ->and(imChildState($child)->json('pet.media.items.treats.image'))->not->toBeNull()
            ->and(ItemMediaNotice::where('pet_id', $pet->id)->value('outcome'))->toBe('fallback');

        // QA #655 m3: the clip comes later (profile on again) → the same stage is announced again, no second push.
        config(['media.profiles.video.kling_v3_pro.enabled' => true]);
        artisan('media:item-media')->assertSuccessful();
        imComplete();
        expect(imChildState($child)->json('pet.media.items.treats.video'))->not->toBeNull()
            ->and(ItemMediaNotice::where('pet_id', $pet->id)->value('outcome'))->toBe('content')
            ->and(PushNotification::where('type', 'item_ready')->where('pet_id', $pet->id)->count())->toBe(1)
            ->and(collect(Event::dispatched(PetUpdated::class))->filter(fn ($e) => $e[0]->eventType === 'item_media_ready'))->toHaveCount(2);
    });

    it('retries our own failed download from the fal URL (free) before failing the row for a paid regeneration', function () {
        Queue::fake([StoreItemMedia::class]);
        [, , $pet] = imFamily(null);
        $row = ItemMedia::create(['scope' => 'pet', 'pet_id' => $pet->id, 'item_key' => 'bed', 'design' => 'dog_bed_round_grey', 'kind' => 'image',
            'status' => 'running', 'request_id' => 'dl-1', 'source_url' => 'https://v3.fal.media/files/im/dl-1.jpg']);
        $service = app(ItemMediaService::class);

        (new StoreItemMedia($row->id))->failed(new RuntimeException('disk full'));
        expect($row->fresh()->status)->toBe('running')->and($row->fresh()->download_attempts)->toBe(1);

        $this->travel(61)->minutes();
        $service->sweep();
        Queue::assertPushed(StoreItemMedia::class, fn ($job) => $job->itemMediaId === $row->id);
        expect(imFalSubmits())->toHaveCount(0); // no new fal call

        $service->downloadFailed($row->fresh(), 'again');
        $service->downloadFailed($row->fresh(), 'and again');
        expect($row->fresh()->status)->toBe('failed')->and($row->fresh()->error_reason)->toBe('http_error');
    });

    it('lists item files in media:disk-report (not orphans)', function () {
        Storage::disk('pet_media')->put('items/1/dog_bed_round_grey-g1.jpg', IM_JPEG.'x');
        ItemMedia::create(['scope' => 'catalogue', 'item_key' => 'bed', 'design' => 'dog_bed_round_grey', 'kind' => 'picture', 'status' => 'ready',
            'storage_path' => 'items/1/dog_bed_round_grey-g1.jpg', 'bytes' => 21]);

        Artisan::call('media:disk-report');
        expect(Artisan::output())->toContain('item media (catalogue)')
            ->toContain('Orphans (no pet_media / history / derivative / contact sheet / item_media row): 0');
    });

    it('builds species-correct prompts for a cat', function () {
        $prompt = app(PetAppearancePrompt::class)->itemImagePrompt('domestic_cat', 'toy_2', ItemDesigns::description('cat_wand_peacock'), ['coat_color' => 'grey tabby'], null, true);
        expect($prompt)->toContain('cat')->toContain('peacock feather')->not->toMatch('/\bdog\b/');
    });
});
