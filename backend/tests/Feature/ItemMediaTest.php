<?php

use App\Enums\PushType;
use App\Enums\Species;
use App\Enums\WishStatus;
use App\Events\PetUpdated;
use App\Filament\Resources\ItemMediaResource\Pages\ListItemMedia;
use App\Jobs\SendItemMediaAlert;
use App\Jobs\SendTreatsWaitingAlert;
use App\Jobs\StoreItemMedia;
use App\Models\AiSpendLedger;
use App\Models\ItemMedia;
use App\Models\ItemMediaNotice;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\PetTreatEvent;
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

/** Answer the submitted CATALOGUE pictures only (the pet's key images / clips keep running). */
function imCompletePictures(): void
{
    ItemMedia::query()->where('kind', 'picture')->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->orderBy('id')->get()
        ->each(fn (ItemMedia $row) => sendFalWebhook(['request_id' => $row->request_id, 'status' => 'OK', 'payload' => ['images' => [['url' => "https://v3.fal.media/files/im/{$row->request_id}.jpg"]]]])->assertOk());
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

    it('bundle: every item takes the parent\'s pick, also the wished one; without a valid pick the wish, then the first design (David 2026-11-19)', function () {
        config(['services.fal_ai.key' => null]);
        [$parent, $child, $pet] = imFamily();
        imAs($child);
        $bowls = postJson('/api/child/wishes', ['item_key' => 'bowls', 'design' => 'dog_bowls_bamboo_stand'])->assertCreated()->json('wish.id');
        $bed = postJson('/api/child/wishes', ['item_key' => 'bed', 'design' => 'dog_bed_wicker_checked'])->assertCreated()->json('wish.id');

        imRedeem($parent, $pet, 'petprep_wish_bundle_welcome', ['wish_id' => $bowls, 'designs' => [
            'bed' => 'dog_bed_wicker_checked', 'bowls' => 'dog_bowls_blue_ceramic', 'toy_1' => 'dog_rope_rainbow',
        ]])->assertOk();
        expect(PetItem::where('pet_id', $pet->id)->pluck('design', 'item_key')->all())
            ->toBe(['bed' => 'dog_bed_wicker_checked', 'bowls' => 'dog_bowls_blue_ceramic', 'toy_1' => 'dog_rope_rainbow'])
            // The open wishes the bundle covers are settled as before.
            ->and(PetWish::whereKey($bowls)->value('status'))->toBe(WishStatus::Bought)
            ->and(PetWish::whereKey($bed)->value('status'))->toBe(WishStatus::Cancelled);

        // An older app without a valid pick for the wished item → the wish's design.
        [$parent2, $child2, $pet2] = imFamily();
        imAs($child2);
        $wish2 = postJson('/api/child/wishes', ['item_key' => 'bowls', 'design' => 'dog_bowls_bamboo_stand'])->assertCreated()->json('wish.id');
        imRedeem($parent2, $pet2, 'petprep_wish_bundle_welcome', ['wish_id' => $wish2, 'designs' => ['bowls' => 'nope']])->assertOk();
        expect(PetItem::where('pet_id', $pet2->id)->pluck('design', 'item_key')->all())
            ->toBe(['bed' => 'dog_bed_round_grey', 'bowls' => 'dog_bowls_bamboo_stand', 'toy_1' => 'dog_rope_red_white']);

        // A real design of another item (a bed design for the bowls) or of the other species is
        // not a valid pick for the wished key → the wish's design too.
        foreach (['dog_bed_wicker_checked', 'cat_bowls_teal_fish'] as $i => $wrong) {
            [$parent3, $child3, $pet3] = imFamily();
            imAs($child3);
            $wish3 = postJson('/api/child/wishes', ['item_key' => 'bowls', 'design' => 'dog_bowls_bamboo_stand'])->assertCreated()->json('wish.id');
            imRedeem($parent3, $pet3, 'petprep_wish_bundle_welcome', ['wish_id' => $wish3, 'designs' => ['bowls' => $wrong]])->assertOk();
            expect(PetItem::where('pet_id', $pet3->id)->where('item_key', 'bowls')->value('design'))->toBe('dog_bowls_bamboo_stand', "case {$i}: {$wrong}");
        }
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

    it('never repeats a refused request: next prompt variant, then gives up → one alert; treats stay locked (never usable without the clip)', function () {
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
        // David 2026-11-15: treats are an ACTION item — given up never means »shown without content«:
        // »Priboljšek« stays disabled, nothing is announced, the alert asks the admin to regenerate.
        expect(imFalSubmits('image'))->toHaveCount(2)
            ->and($row->fresh()->exhausted_at)->not->toBeNull()
            ->and(app(ItemMediaService::class)->readiness($pet->fresh()))->toBe(['treats' => false])
            ->and(app(ItemMediaService::class)->statesFor($pet->fresh())['treats']['given_up'])->toBeTrue()
            ->and(imChildState($child)->json('pet.media.items'))->toBe([])
            ->and(imChildState($child)->json('wishes.treats.ready'))->toBeFalse();
        Queue::assertPushed(SendItemMediaAlert::class, fn ($job) => $job->count === 1 && $job->locked === 1);
        expect(ItemMediaNotice::where('pet_id', $pet->id)->count())->toBe(0)
            ->and(PushNotification::where('type', 'item_ready')->where('pet_id', $pet->id)->count())->toBe(0);
        imAs($child);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'media_not_ready')->assertJsonPath('treats_left', 10);

        $this->travel(2)->hours();
        artisan('media:item-media')->assertSuccessful();
        Queue::assertPushed(SendItemMediaAlert::class, 1);

        // The admin's Regenerate (a new chain) unlocks it — announced once.
        app(ItemMediaService::class)->regenerate($row->fresh());
        imComplete();
        expect(app(ItemMediaService::class)->readiness($pet->fresh()))->toBe(['treats' => true])
            ->and(PushNotification::where('type', 'item_ready')->where('pet_id', $pet->id)->count())->toBe(1);
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 9);
    });

    it('a durable item whose recovery gave up is still shown without its content (it has no action to lock)', function () {
        Queue::fake([SendItemMediaAlert::class]);
        [, $child, $pet] = imFamily(null);
        PetItem::create(['pet_id' => $pet->id, 'item_key' => 'bowls', 'kind' => 'durable', 'variant' => 'dog_bowls', 'design' => 'dog_bowls_steel_stand', 'quantity_total' => 1, 'quantity_left' => 1, 'acquired_at' => now()]);
        artisan('media:item-media')->assertSuccessful();
        imCompletePictures();
        $row = ItemMedia::where('scope', 'pet')->where('kind', 'image')->firstOrFail();
        foreach ([0, 1] as $try) {
            imFailWebhook($row->fresh(), noMedia: true);
            $this->travel(31)->minutes();
            artisan('media:item-media')->assertSuccessful();
        }

        expect($row->fresh()->exhausted_at)->not->toBeNull()
            ->and(app(ItemMediaService::class)->readiness($pet->fresh()))->toBe(['bowls' => true])
            ->and(app(ItemMediaService::class)->statesFor($pet->fresh())['bowls']['given_up'])->toBeTrue()
            ->and(ItemMediaNotice::where('pet_id', $pet->id)->value('outcome'))->toBe('fallback')
            ->and(imChildState($child)->json('wishes.items.0.media_ready'))->toBeTrue();
        Queue::assertPushed(SendItemMediaAlert::class, fn ($job) => $job->count === 1 && $job->locked === 0);
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

/*
| David 2026-11-15 — the general media rule (PRODUCT_SPEC »stanje → medij«): a feature whose
| clip does not exist yet is disabled (»se pripravlja«), never usable without its animation.
| Treats: the clip is queued when the pack is bought; »Priboljšek« is refused 422
| `media_not_ready` (nothing used up) until the clip of the pet's current picture is stored.
*/
describe('treats wait for their clip', function () {
    it('queues the clip once at the purchase, refuses the treat while it is made (count unchanged) and gives it once the clip is there', function () {
        Event::fake([PetUpdated::class]);
        [$parent, $child, $pet] = imFamily(null);

        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk()
            ->assertJsonPath('items.4.key', 'treats')->assertJsonPath('items.4.treats_left', 10)->assertJsonPath('items.4.media_ready', false);
        // Bought → the key image is submitted at once (after commit), exactly once.
        expect(imFalSubmits('image'))->toHaveCount(1)->and(imFalSubmits('clip'))->toHaveCount(0);

        // The contract an OLDER app build meets: 422 with the same body as every treat refusal.
        imAs($child);
        $refusal = postJson('/api/child/pet/treat')->assertStatus(422)
            ->assertJsonPath('status', 'refused')->assertJsonPath('reason', 'media_not_ready')->assertJsonPath('next_at', null)
            ->assertJsonPath('treats_left', 10)->assertJsonPath('given_today', 0)->assertJsonPath('daily_limit', 2)
            ->assertJsonPath('state.wishes.treats', ['left' => 10, 'given_today' => 0, 'daily_limit' => 2, 'ready' => false]);
        expect($refusal->json('message'))->toBeString();
        expect(PetTreatEvent::where('pet_id', $pet->id)->count())->toBe(0)
            ->and(PetItem::where('pet_id', $pet->id)->where('item_key', 'treats')->value('quantity_left'))->toBe(10);
        getJson('/api/child/wishes')->assertJsonPath('items.4.key', 'treats')->assertJsonPath('items.4.media_ready', false)->assertJsonPath('items.4.treats_left', 10);

        // The key image alone is not enough: the clip is what the treat plays.
        $image = ItemMedia::where('kind', 'image')->firstOrFail();
        sendFalWebhook(['request_id' => $image->request_id, 'status' => 'OK', 'payload' => ['images' => [['url' => "https://v3.fal.media/files/im/{$image->request_id}.jpg"]]]])->assertOk();
        expect(imFalSubmits('clip'))->toHaveCount(1);
        imAs($child);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'media_not_ready')->assertJsonPath('treats_left', 10);
        expect(imChildState($child)->json('wishes.treats.ready'))->toBeFalse();

        imComplete();
        // A second pack and later scheduler passes never make it again.
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk()->assertJsonPath('items.4.media_ready', true);
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('image'))->toHaveCount(1)->and(imFalSubmits('clip'))->toHaveCount(1);

        // Ready: the apps were told (PetUpdated `item_media_ready` + the `item_ready` push), the treat is given.
        expect(collect(Event::dispatched(PetUpdated::class))->filter(fn ($e) => $e[0]->eventType === 'item_media_ready'))->toHaveCount(1)
            ->and(PushNotification::where('type', 'item_ready')->where('pet_id', $pet->id)->count())->toBe(1)
            ->and(imChildState($child)->json('wishes.treats'))->toBe(['left' => 20, 'given_today' => 0, 'daily_limit' => 2, 'ready' => true]);
        imAs($child);
        $given = postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('treats_left', 19)->assertJsonPath('given_today', 1)
            ->assertJsonPath('state.wishes.treats', ['left' => 19, 'given_today' => 1, 'daily_limit' => 2, 'ready' => true]);
        expect($given->json('media.video'))->toContain('/api/media/items/')
            ->and(PetTreatEvent::where('pet_id', $pet->id)->count())->toBe(1);
    });

    it('is ready at once when the shared look already has the treat clip (0 $ for the next buyer)', function () {
        $look = imLook();
        [$parent, , $pet] = imFamily($look);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk()->assertJsonPath('items.4.media_ready', false);
        imComplete();
        $calls = imFalSubmits()->count();

        [$parent2, $child2, $pet2] = imFamily($look);
        imRedeem($parent2, $pet2, 'petprep_wish_treats_10')->assertOk()->assertJsonPath('items.4.media_ready', true);
        expect(imFalSubmits()->count())->toBe($calls)
            ->and(imChildState($child2)->json('wishes.treats.ready'))->toBeTrue();
        imAs($child2);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 9);
    });

    it('stays locked while the AI budget is used up — the bought treats are kept — and unlocks once the budget allows', function () {
        config(['media.budget.daily_usd' => 0.01]);
        [$parent, $child, $pet] = imFamily(null);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk()->assertJsonPath('items.4.media_ready', false);
        expect(imFalSubmits())->toHaveCount(0)
            ->and(ItemMedia::where('kind', 'image')->value('error_reason'))->toBe('budget_daily');

        imAs($child);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'media_not_ready')->assertJsonPath('treats_left', 10);
        // Waiting for money is never counted as a retry and never given up.
        $this->travel(3)->hours();
        artisan('media:item-media')->assertSuccessful();
        expect(ItemMedia::where('kind', 'image')->value('exhausted_at'))->toBeNull()
            ->and(imChildState($child)->json('wishes.treats'))->toBe(['left' => 10, 'given_today' => 0, 'daily_limit' => 2, 'ready' => false]);

        config(['media.budget.daily_usd' => 50.0]);
        artisan('media:item-media')->assertSuccessful();
        imComplete();
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 9);
    });

    it('a failed clip keeps the treat locked while it is retried automatically, then unlocks', function () {
        [$parent, $child, $pet] = imFamily(null);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        $image = ItemMedia::where('kind', 'image')->firstOrFail();
        sendFalWebhook(['request_id' => $image->request_id, 'status' => 'OK', 'payload' => ['images' => [['url' => "https://v3.fal.media/files/im/{$image->request_id}.jpg"]]]])->assertOk();
        $clip = ItemMedia::where('kind', 'clip')->firstOrFail();
        imFailWebhook($clip);

        imAs($child);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'media_not_ready')->assertJsonPath('treats_left', 10);

        $this->travel(31)->minutes();
        artisan('media:item-media')->assertSuccessful(); // the automatic retry (1 of 2)
        expect($clip->fresh()->auto_retries)->toBe(1)->and(imFalSubmits('clip'))->toHaveCount(2);
        imComplete();
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 9);
    });

    it('existing owners without a clip: locked at once, queued by the scheduled pass (no manual step), then unlocked', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
        [, $child, $pet] = imFamily(null);
        // A pack owned from before (no content row at all).
        PetItem::create(['pet_id' => $pet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 6, 'acquired_at' => now()->subDays(9)]);

        expect(imChildState($child)->json('wishes.treats'))->toBe(['left' => 6, 'given_today' => 0, 'daily_limit' => 2, 'ready' => false]);
        imAs($child);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'media_not_ready')->assertJsonPath('treats_left', 6);

        artisan('media:item-media')->assertSuccessful();
        artisan('media:item-media')->assertSuccessful();
        imComplete();
        expect(imFalSubmits('image'))->toHaveCount(1)->and(imFalSubmits('clip'))->toHaveCount(1);
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 5);
    });

    it('a new life stage locks the treat again until the new picture has its clip', function () {
        [$parent, $child, $pet] = imFamily(null);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        imComplete();
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 9);

        imPicture($pet, 'adult', null, 2); // the pet grew: a new reference image
        expect(imChildState($child)->json('wishes.treats.ready'))->toBeFalse();
        imAs($child);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'media_not_ready')->assertJsonPath('treats_left', 9);

        artisan('media:item-media')->assertSuccessful();
        imComplete();
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 8);
    });

    it('migration: treat rows given up before the rule get one fresh round of the automatic recovery; durable rows stay given up', function () {
        Queue::fake([SendItemMediaAlert::class]);
        [, $child, $pet] = imFamily(null);
        PetItem::create(['pet_id' => $pet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 10, 'acquired_at' => now()->subDays(5)]);
        artisan('media:item-media')->assertSuccessful();
        $image = ItemMedia::where('item_key', 'treats')->where('kind', 'image')->firstOrFail();
        sendFalWebhook(['request_id' => $image->request_id, 'status' => 'OK', 'payload' => ['images' => [['url' => "https://v3.fal.media/files/im/{$image->request_id}.jpg"]]]])->assertOk();
        $clip = ItemMedia::where('item_key', 'treats')->where('kind', 'clip')->firstOrFail();
        // Given up before the rule (failed generation, retries used, announced as »no action needed«).
        imFailWebhook($clip);
        $clip->refresh()->forceFill(['auto_retries' => 2, 'exhausted_at' => now()->subDays(3), 'alerted_at' => now()->subDays(3), 'completed_at' => now()->subDays(3)])->save();
        $bed = ItemMedia::create(['scope' => 'pet', 'pet_id' => $pet->id, 'item_key' => 'bed', 'design' => 'dog_bed_round_grey', 'kind' => 'image',
            'status' => 'failed', 'error_reason' => 'generation_failed', 'auto_retries' => 2, 'exhausted_at' => now()->subDays(3)]);
        expect(imChildState($child)->json('wishes.treats.ready'))->toBeFalse();

        // QA #1139 m4: given-up treat rows nobody waits for stay as they are — a pet with an empty
        // pack, a game-over pet, a look row at a stage no waiting pet shows.
        [, , $emptyPet] = imFamily(null);
        PetItem::create(['pet_id' => $emptyPet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 0, 'acquired_at' => now()->subDays(5)]);
        [, , $overPet] = imFamily(null, ['is_game_over' => true]);
        PetItem::create(['pet_id' => $overPet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 4, 'acquired_at' => now()->subDays(5)]);
        $look = imLook();
        [, , $lookPet] = imFamily($look); // shows the look's `young` picture, has treats left
        PetItem::create(['pet_id' => $lookPet->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 3, 'acquired_at' => now()->subDays(5)]);
        $givenUp = fn (array $attributes) => ItemMedia::create($attributes + ['item_key' => 'treats', 'design' => 'dog_treats', 'kind' => 'image',
            'status' => 'failed', 'error_reason' => 'generation_failed', 'auto_retries' => 2, 'prompt_variant' => 1, 'exhausted_at' => now()->subDays(3), 'alerted_at' => now()->subDays(3)]);
        $ofEmpty = $givenUp(['scope' => 'pet', 'pet_id' => $emptyPet->id]);
        $ofOver = $givenUp(['scope' => 'pet', 'pet_id' => $overPet->id]);
        $lookYoung = $givenUp(['scope' => 'look', 'pet_look_id' => $look->id, 'life_stage' => 'young']);
        $lookAdult = $givenUp(['scope' => 'look', 'pet_look_id' => $look->id, 'life_stage' => 'adult']);

        $migration = require database_path('migrations/2027_04_07_130000_reopen_given_up_treat_content.php');
        expect($migration->reopenGivenUpTreats())->toBe(2)
            ->and($migration->reopenGivenUpTreats())->toBe(0) // idempotent
            ->and($clip->fresh()->exhausted_at)->toBeNull()
            ->and($clip->fresh()->auto_retries)->toBe(0)
            ->and($lookYoung->fresh()->exhausted_at)->toBeNull()
            ->and($lookYoung->fresh()->prompt_variant)->toBe(0) // QA #1139 m5: the next variant gets its try
            ->and($lookAdult->fresh()->exhausted_at)->not->toBeNull()
            ->and($ofEmpty->fresh()->exhausted_at)->not->toBeNull()
            ->and($ofOver->fresh()->exhausted_at)->not->toBeNull()
            ->and($bed->fresh()->exhausted_at)->not->toBeNull();
        ItemMedia::whereKey([$lookYoung->id, $lookAdult->id, $ofEmpty->id, $ofOver->id])->delete(); // the rest of the test is about the first pet

        // The next scheduled pass retries it (no manual step) and the treat unlocks.
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('clip'))->toHaveCount(2);
        imComplete();
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 9);
        Queue::assertNotPushed(SendItemMediaAlert::class);
    });

    it('QA #1139 M1: a second buyer on a given-up look chain gets ONE fresh chain — and a second give-up alerts again', function () {
        Queue::fake([SendItemMediaAlert::class]);
        $look = imLook();
        [$parent, $child, $pet] = imFamily($look);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        $row = ItemMedia::where('scope', 'look')->where('kind', 'image')->firstOrFail();
        foreach ([0, 1] as $try) {
            imFailWebhook($row->fresh(), noMedia: true);
            $this->travel(31)->minutes();
            artisan('media:item-media')->assertSuccessful();
        }
        // Alerts about the treat row (the catalogue pictures of this test time out on their own).
        $treatAlerts = fn () => Queue::pushed(SendItemMediaAlert::class, fn ($job) => $job->locked === 1)->count();
        expect($row->fresh()->exhausted_at)->not->toBeNull()->and(imFalSubmits('image'))->toHaveCount(2)->and($treatAlerts())->toBe(1);
        // The scheduler alone never touches a given-up chain.
        $this->travel(1)->days();
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('image'))->toHaveCount(2);

        // Another family with the same look + stage buys treats: one fresh chain, once.
        [$parent2, $child2, $pet2] = imFamily($look);
        imRedeem($parent2, $pet2, 'petprep_wish_treats_10')->assertOk()->assertJsonPath('items.4.media_ready', false);
        $row->refresh();
        expect(imFalSubmits('image'))->toHaveCount(3)
            ->and($row->exhausted_at)->toBeNull()->and($row->alerted_at)->toBeNull()
            ->and($row->auto_retries)->toBe(0)->and($row->prompt_variant)->toBe(0);
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('image'))->toHaveCount(3);

        // It gives up again → a SECOND alert (2 pets wait for this one row).
        foreach ([0, 1] as $try) {
            imFailWebhook($row->fresh(), noMedia: true);
            $this->travel(31)->minutes();
            artisan('media:item-media')->assertSuccessful();
        }
        expect($row->fresh()->exhausted_at)->not->toBeNull()->and($treatAlerts())->toBe(2);
        imAs($child2);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'media_not_ready')->assertJsonPath('treats_left', 10);

        // A second pack for the first pet reopens it once more; this time it is made → both pets unlock.
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        expect(imFalSubmits('image'))->toHaveCount(5);
        imComplete();
        artisan('media:item-media')->assertSuccessful();
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 19);
        imAs($child2);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 9);
    });

    it('QA #1139 M1: buying a durable item never reopens a given-up treat chain, and a chain at work is left alone', function () {
        [$parent, , $pet] = imFamily(null);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        $row = ItemMedia::where('item_key', 'treats')->where('kind', 'image')->firstOrFail();
        $service = app(ItemMediaService::class);
        // Running, not given up → nothing to reopen.
        expect($service->reopenGivenUp($pet->fresh(), ['treats']))->toBe(0);

        imFailWebhook($row->fresh());
        $row->refresh()->forceFill(['auto_retries' => 2, 'exhausted_at' => now(), 'alerted_at' => now()])->save();
        expect($service->reopenGivenUp($pet->fresh(), ['bed', 'bowls', 'toy_1']))->toBe(0)
            ->and($row->fresh()->exhausted_at)->not->toBeNull()
            ->and($service->reopenGivenUp($pet->fresh(), ['treats']))->toBe(1)
            ->and($service->reopenGivenUp($pet->fresh(), ['treats']))->toBe(0);
    });

    it('QA #1139 M2 watchdog: one alert a day with the count of pets whose treats are »preparing« for more than 2 h', function () {
        Queue::fake([SendTreatsWaitingAlert::class]);
        Cache::flush();
        config(['media.budget.daily_usd' => 0.01]); // waiting for the AI budget is never given up
        [$parent, , $pet] = imFamily(null);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        // A pet whose own picture never came (no item row at all).
        [$parent2, , $pet2] = imFamily(null, ['media_status' => 'pending'], withPicture: false);
        PetItem::create(['pet_id' => $pet2->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 10, 'acquired_at' => now()]);
        // Not counted: an empty pack, a pet without AI media, a pet that is ready.
        [, , $empty] = imFamily(null);
        PetItem::create(['pet_id' => $empty->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 0, 'acquired_at' => now()]);
        [, , $drawn] = imFamily(null, ['media_status' => 'disabled'], withPicture: false);
        PetItem::create(['pet_id' => $drawn->id, 'item_key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'quantity_total' => 10, 'quantity_left' => 10, 'acquired_at' => now()]);
        $service = app(ItemMediaService::class);

        // Fresh purchases: still within the 2 hours.
        $this->travel(110)->minutes();
        artisan('media:item-media')->assertSuccessful();
        expect($service->stuckActionPets())->toBe(0);
        Queue::assertNothingPushed();

        $this->travel(20)->minutes();
        expect($service->stuckActionPets())->toBe(2);
        artisan('media:item-media')->assertSuccessful();
        Queue::assertPushed(SendTreatsWaitingAlert::class, fn ($job) => $job->count === 2 && $job->hours === 2);

        // Not every 10 minutes: once per day while it lasts.
        $this->travel(10)->minutes();
        artisan('media:item-media')->assertSuccessful();
        $this->travel(3)->hours();
        artisan('media:item-media')->assertSuccessful();
        Queue::assertPushed(SendTreatsWaitingAlert::class, 1);
        $this->travel(1)->days();
        artisan('media:item-media')->assertSuccessful();
        Queue::assertPushed(SendTreatsWaitingAlert::class, 2);

        // The budget allows again → the first pet is made; the pet without a picture still counts.
        config(['media.budget.daily_usd' => 50.0]);
        artisan('media:item-media')->assertSuccessful();
        imComplete();
        expect($service->stuckActionPets())->toBe(1);
        $pet2->delete();
        $this->travel(1)->days();
        artisan('media:item-media')->assertSuccessful();
        Queue::assertPushed(SendTreatsWaitingAlert::class, 2);
    });

    it('QA #1139 M2 watchdog: a given-up chain whose own alert was lost is still reported; the alert texts carry counts only and never contradict themselves', function () {
        Cache::flush();
        Http::fake(['discord.com/api/webhooks/*' => Http::response('', 204)]);
        config(['services.discord.alerts_webhook_url' => 'https://discord.com/api/webhooks/1/secret-token']);
        [$parent, , $pet] = imFamily(null);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        $row = ItemMedia::where('kind', 'image')->firstOrFail();
        imFailWebhook($row->fresh());
        // Given up with `alerted_at` set although Discord never got it (job lost / webhook down).
        $row->refresh()->forceFill(['auto_retries' => 2, 'exhausted_at' => now(), 'alerted_at' => now()])->save();

        $this->travel(121)->minutes();
        expect(app(ItemMediaService::class)->stuckActionPets())->toBe(1);
        artisan('media:item-media')->assertSuccessful(); // sync queue → the watchdog post goes out
        $posts = fn () => Http::recorded(fn (Request $r) => str_contains($r->url(), 'discord.com'))->map(fn (array $pair) => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE))->values();
        expect($posts())->toHaveCount(1)
            ->and($posts()[0])->toContain('1 pet(s)')->toContain('more than 2 h')
            ->and($posts()[0])->not->toContain((string) $parent->email)->not->toContain('secret-token');

        // QA #1139 m3: with locked rows the give-up alert asks for action and never says »no admin action needed«.
        app()->call([new SendItemMediaAlert(3, 1), 'handle']);
        app()->call([new SendItemMediaAlert(2, 0), 'handle']);
        expect($posts()[1])->toContain('ACTION NEEDED for 1 of them')->toContain('The other 2 belong to durable items')
            ->and($posts()[1])->not->toContain('no admin action needed')
            ->and($posts()[2])->toContain('no admin action needed')->not->toContain('ACTION NEEDED');
    });

    it('QA #1139 m6: an empty pack is not regenerated at a new stage and does not count as locked; a new pack brings it back', function () {
        Queue::fake([SendItemMediaAlert::class]);
        [$parent, $child, $pet] = imFamily(null);
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        imComplete();
        PetItem::where('pet_id', $pet->id)->update(['quantity_left' => 0]);

        imPicture($pet, 'adult', null, 2); // the pet grew
        artisan('media:item-media')->assertSuccessful();
        expect(imFalSubmits('image'))->toHaveCount(1)->and(imFalSubmits('clip'))->toHaveCount(1)
            ->and(imChildState($child)->json('wishes.treats'))->toBe(['left' => 0, 'given_today' => 0, 'daily_limit' => 2, 'ready' => true]);

        // A given-up row nobody can use is not »ACTION NEEDED«.
        $row = ItemMedia::where('kind', 'image')->firstOrFail();
        $row->forceFill(['status' => 'failed', 'error_reason' => 'timed_out', 'target_path' => "{$pet->id}/reference-g2.jpg", 'completed_at' => now()->subHour()])->save();
        artisan('media:item-media')->assertSuccessful();
        Queue::assertPushed(SendItemMediaAlert::class, fn ($job) => $job->count === 1 && $job->locked === 0);

        // New treats → the purchase reopens the chain for the new picture.
        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk()->assertJsonPath('items.4.media_ready', false);
        imComplete();
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 9);
    });

    it('never locks a pet that gets no AI media (drawn stage) and reports no_treats before the clip', function () {
        [$parent, $child, $pet] = imFamily(null, ['media_status' => 'disabled'], withPicture: false);
        imAs($child);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'no_treats');
        expect(imChildState($child)->json('wishes.treats.ready'))->toBeTrue(); // no pack → nothing to wait for

        imRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk()->assertJsonPath('items.4.media_ready', true);
        expect(imFalSubmits())->toHaveCount(0);
        imAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 9)->assertJsonPath('media', null)
            ->assertJsonPath('state.wishes.treats.ready', true);
    });
});
