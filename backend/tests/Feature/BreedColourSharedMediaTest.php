<?php

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
use App\Filament\Pages\FeatureSwitches;
use App\Filament\Resources\PetLookResource\Pages\ListPetLooks;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\StorePetMedia;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\ChildProfileService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyService;
use App\Services\Media\BreedColourCatalogue;
use App\Services\Media\FalGateway;
use App\Services\Media\PetLookPoolService;
use App\Services\Media\PetMediaService;
use App\Services\Media\SharedBreedLookService;
use App\Services\PairingService;
use App\Services\Results\LookRegeneration;
use App\Services\Results\PetProfileChoice;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R21 — breed colours + shared AI media for paid breeds (David
| 2026-10-14): colour picker data (standard colours only), colour on the new
| pet, one shared look per breed + colour while "unique AI media per pet" is
| off (default), reuse without a fal call, concurrency, switch on = unique,
| mutt unchanged, admin regenerate + switch.
|--------------------------------------------------------------------------
*/

const BC_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

const BC_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

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
    ]);
    seedBreedConfigs();
    seedStageParams();
});

function bcFakeFal(): void
{
    fakeFalJwks();
    $n = 0;
    Http::fake([
        'fal.run/fal-ai/nano-banana-pro/edit' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/bc/edit-{$n}.jpg"]]]);
        },
        'fal.run/fal-ai/nano-banana-pro' => function () use (&$n) {
            $n++;

            return Http::response(['images' => [['url' => "https://v3.fal.media/files/bc/ref-{$n}.jpg"]]]);
        },
        'queue.fal.run/*' => function () use (&$n) {
            $n++;

            return Http::response(['request_id' => "req-{$n}"]);
        },
        'v3.fal.media/files/bc/*.jpg' => fn () => Http::response(BC_JPEG.str_repeat("\x00", 256), 200, ['Content-Type' => 'image/jpeg']),
        'v3.fal.media/files/bc/*.mp4' => fn () => Http::response(BC_MP4.str_repeat("\x00", 512), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

/** Answer every submitted look video with a successful signed webhook. */
function bcCompleteLookVideos(): int
{
    $rows = PetMedia::query()->whereNotNull('pet_look_id')->where('kind', 'video')
        ->where('status', 'running')->whereNotNull('request_id')->whereNull('source_url')->get();

    foreach ($rows as $row) {
        sendFalWebhook([
            'request_id' => $row->request_id,
            'status' => 'OK',
            'payload' => ['video' => ['url' => "https://v3.fal.media/files/bc/{$row->request_id}.mp4"]],
        ])->assertOk();
    }

    return $rows->count();
}

/** Paid fal calls so far (image runs + video submits). */
function bcFalCalls(): int
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://fal.run/') || str_starts_with($r->url(), 'https://queue.fal.run/'))->count();
}

/** A new profiled pet exactly as a PIN login creates it; a new family unless $parent is given. */
function bcPet(string $breed = 'labrador_retriever', ?string $colour = null, ?User $parent = null, LifeStage $stage = LifeStage::Young): Pet
{
    $parent ??= createParentUser();
    $family = app(FamilyService::class)->ensureFamilyFor($parent);
    $child = createChildUser(['parent_id' => $parent->id]);
    $type = BreedType::from($breed);
    $profile = new PetProfileChoice($type, PetOrigin::Bought, $stage, [], $type->species(), $colour);
    $plan = $type->isPremium() ? PetPlan::Challenge : PetPlan::Free;

    return DB::transaction(fn () => app(PairingService::class)->attachChildToPet($family, $child, null, $profile, $plan)['pet'])->fresh();
}

function bcImage(Pet $pet): PetMedia
{
    return PetMedia::where('pet_id', $pet->id)->where('kind', 'image')->sole();
}

/* ─────────────────────────── Colour data + picker API ─────────────────────────── */

describe('colour catalogue', function () {
    it('lists exactly the standard coat colours of breed_appearance for every paid breed (and none for free breeds)', function () {
        $catalogue = app(BreedColourCatalogue::class);

        foreach (BreedType::cases() as $breed) {
            $appearance = config("breed_appearance.{$breed->value}.traits.coat_color");
            $colours = $catalogue->for($breed);

            if (! $breed->isPremium()) {
                expect($colours)->toBe([], "{$breed->value} is free — no picker");

                continue;
            }

            $values = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, $appearance);
            expect(array_column($colours, 'value'))->toBe($values, "{$breed->value}: picker ≠ breed_appearance coat_color");

            $keys = array_column($colours, 'key');
            expect($keys)->toBe(array_values(array_unique($keys)), "{$breed->value}: duplicate key");
            foreach ($colours as $c) {
                expect($c['key'])->toMatch('/^[a-z]+(-[a-z]+)*$/')
                    ->and(trim($c['en']))->not->toBe('')
                    ->and(trim($c['sl']))->not->toBe('');
            }
        }
    });

    it('GET /api/breeds sends the colours of paid breeds in the request language; free breeds get none', function () {
        actingAsRole(User::factory()->parent()->create());

        $en = collect(getJson('/api/breeds', ['Accept-Language' => 'en'])->assertOk()->json('breeds'))->keyBy('breed');
        expect($en['mutt']['colours'])->toBe([])
            ->and($en['french_bulldog']['colours'])->toBe([
                ['key' => 'fawn', 'label' => 'Fawn'],
                ['key' => 'brindle', 'label' => 'Brindle'],
                ['key' => 'light-fawn', 'label' => 'Light fawn'],
            ])
            ->and($en['west_highland_white_terrier']['colours'])->toBe([['key' => 'white', 'label' => 'White']]);

        $sl = collect(getJson('/api/breeds', ['Accept-Language' => 'sl'])->assertOk()->json('breeds'))->keyBy('breed');
        expect($sl['french_bulldog']['colours'][1])->toBe(['key' => 'brindle', 'label' => 'Tigrast']);
    });
});

describe('POST /api/parent/generate-pin colour', function () {
    beforeEach(fn () => $this->withoutMiddleware([ThrottleRequests::class]));

    function bcPin(array $body): TestResponse
    {
        $parent = User::factory()->parent()->create();
        $child = app(ChildProfileService::class)->createChild($parent, 'Kid', null);
        app('auth')->forgetGuards();
        actingAsRole($parent);

        return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id, 'origin' => 'bought', 'age_stage' => 'young', 'plan' => 'challenge'], $body));
    }

    it('stores a colour of the breed on the PIN profile', function () {
        bcPin(['breed' => 'labrador_retriever', 'colour' => 'chocolate'])->assertOk()
            ->assertJsonPath('pet_profile.colour', 'chocolate')
            ->assertJsonPath('pet_profile.breed', 'labrador_retriever');
    });

    it('refuses a colour the breed does not have, a colour for a free breed, and a colour without a breed', function () {
        bcPin(['breed' => 'labrador_retriever', 'colour' => 'blue-merle'])->assertStatus(422)->assertJsonPath('reason', 'colour_invalid');
        bcPin(['breed' => 'mutt', 'colour' => 'black', 'plan' => 'free'])->assertStatus(422)->assertJsonPath('reason', 'colour_invalid');
        bcPin(['colour' => 'black', 'plan' => 'free'])->assertStatus(422)->assertJsonValidationErrors('colour');
    });

    it('end to end: PIN with a colour → pin-login creates the pet with that colour and the shared look; the second family costs 0 fal calls', function () {
        bcFakeFal();
        $login = function (string $pin): Pet {
            app('auth')->forgetGuards();
            test()->withHeaders(['Authorization' => '']);
            $petId = postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []])
                ->assertOk()->json('pet.id');

            return Pet::findOrFail($petId);
        };
        $birthWithVideos = function (Pet $pet): Pet {
            $pet->giveBirth(now());
            $pet->save();
            app(PetMediaService::class)->queueStateVideos($pet->fresh());
            bcCompleteLookVideos();

            return $pet->fresh();
        };

        $a = $birthWithVideos($login(bcPin(['breed' => 'beagle', 'colour' => 'lemon-white'])->assertOk()->json('pin')));
        $look = PetLook::find($a->pet_look_id);
        expect($a->coat_colour)->toBe('lemon-white')
            ->and($look->colour_key)->toBe('lemon-white')
            ->and($look->breed_type)->toBe(BreedType::Beagle);

        $firstFamilyCalls = bcFalCalls();
        $videos = PetMedia::where('pet_id', $a->id)->where('kind', 'video')->count();
        expect($firstFamilyCalls)->toBe(1 + $videos)   // one image + each basic video, once
            ->and($videos)->toBeGreaterThan(0);

        $b = $birthWithVideos($login(bcPin(['breed' => 'beagle', 'colour' => 'lemon-white'])->assertOk()->json('pin')));

        expect($b->family_id)->not->toBe($a->family_id)
            ->and($b->pet_look_id)->toBe($a->pet_look_id)
            ->and($b->coat_colour)->toBe('lemon-white')
            ->and(bcFalCalls())->toBe($firstFamilyCalls)   // no image run, no video submit
            ->and(PetMedia::where('pet_id', $b->id)->pluck('status')->unique()->all())->toBe(['ready'])
            ->and(PetMedia::where('pet_id', $b->id)->where('kind', 'video')->count())->toBe($videos);
    });

    it('old app builds without a colour keep working (colour null on the PIN)', function () {
        bcPin(['breed' => 'labrador_retriever'])->assertOk()->assertJsonPath('pet_profile.colour', null);
    });
});

/* ─────────────────────────── Shared looks (switch off = default) ─────────────────────────── */

describe('shared media per breed + colour (default)', function () {
    it('is off-by-default "unique media": two families buying a chocolate Labrador share one look', function () {
        config(['services.fal_ai.key' => null]);
        expect(app(AppSettingsService::class)->uniquePetMedia())->toBeFalse();

        $a = bcPet('labrador_retriever', 'chocolate');
        $b = bcPet('labrador_retriever', 'chocolate');
        $black = bcPet('labrador_retriever', 'black');

        expect($a->pet_look_id)->not->toBeNull()
            ->and($b->pet_look_id)->toBe($a->pet_look_id)
            ->and($black->pet_look_id)->not->toBe($a->pet_look_id)
            ->and($a->coat_colour)->toBe('chocolate')
            ->and($a->pet_dna['traits']['coat_color'])->toBe('chocolate brown')
            ->and($b->pet_dna['trait_fingerprint'])->toBe($a->pet_dna['trait_fingerprint'])
            ->and($black->pet_dna['traits']['coat_color'])->toBe('black');

        $look = PetLook::find($a->pet_look_id);
        expect($look->colour_key)->toBe('chocolate')
            ->and($look->breed_type)->toBe(BreedType::LabradorRetriever)
            ->and(PetLook::where('breed_type', 'labrador_retriever')->count())->toBe(2);
    });

    it('generates the image once: the second pet of the same breed + colour reuses it without any fal call', function () {
        bcFakeFal();

        $a = bcPet('french_bulldog', 'brindle');   // reference image runs at pairing (sync queue)
        $afterFirst = bcFalCalls();
        expect($afterFirst)->toBe(1)
            ->and(bcImage($a)->status)->toBe('ready');

        $b = bcPet('french_bulldog', 'brindle');
        expect(bcFalCalls())->toBe($afterFirst)
            ->and(bcImage($b)->status)->toBe('ready')
            ->and(bcImage($b)->storage_path)->toBe(bcImage($a)->storage_path)
            ->and(bcImage($b)->storage_path)->toStartWith('looks/')
            ->and($b->fresh()->media_status)->toBe('ready');

        // Another colour is another look → one more generation.
        bcPet('french_bulldog', 'fawn');
        expect(bcFalCalls())->toBe($afterFirst + 1);
    });

    it('an old app build without a colour gets a weighted colour and the shared look of that colour', function () {
        config(['services.fal_ai.key' => null]);

        $pet = bcPet('labrador_retriever', null);
        $keys = array_column(app(BreedColourCatalogue::class)->for(BreedType::LabradorRetriever), 'key');

        expect($keys)->toContain($pet->coat_colour)
            ->and(PetLook::find($pet->pet_look_id)->colour_key)->toBe($pet->coat_colour)
            ->and(app(BreedColourCatalogue::class)->keyForValue(BreedType::LabradorRetriever, $pet->pet_dna['traits']['coat_color']))->toBe($pet->coat_colour);
    });

    it('two families at once: one look, one generation — the second pet waits for it and is linked when it is stored', function () {
        bcFakeFal();
        Queue::fake([GeneratePetReferenceImage::class, StorePetMedia::class]);

        $a = bcPet('beagle', 'lemon-white');
        $b = bcPet('beagle', 'lemon-white');
        expect($b->pet_look_id)->toBe($a->pet_look_id)->and(PetLook::count())->toBe(1);

        $media = app(PetMediaService::class);
        $media->generateReferenceImage($a);   // claims the look row → one fal call
        $media->generateReferenceImage($b);   // row already running → waits, no call
        expect(bcFalCalls())->toBe(1)
            ->and(bcImage($b)->status)->toBe('running')
            ->and(bcImage($b)->look_media_id)->not->toBeNull();

        $row = PetMedia::whereNotNull('pet_look_id')->where('kind', 'image')->sole();
        $media->storeResult($row->id);        // stored once → fanned out to both pets

        expect(bcImage($a)->status)->toBe('ready')
            ->and(bcImage($b)->status)->toBe('ready')
            ->and(bcImage($b)->storage_path)->toBe($row->fresh()->storage_path)
            ->and(bcFalCalls())->toBe(1);
    });

    it('creating the shared look twice (race) returns the same row', function () {
        $service = app(SharedBreedLookService::class);
        $one = $service->lookFor(BreedType::Boxer, 'brindle');
        $two = $service->lookFor(BreedType::Boxer, 'brindle');

        expect($two->id)->toBe($one->id)->and(PetLook::count())->toBe(1);

        // The DB guard itself: a second (breed, colour) row is ignored (partial unique index).
        $ignored = PetLook::query()->insertOrIgnore([
            'breed_type' => 'boxer',
            'pool_index' => 99,
            'colour_key' => 'brindle',
            'trait_fingerprint' => 'another-combination',
            'dna' => json_encode($one->dna),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        expect($ignored)->toBe(0)->and(PetLook::count())->toBe(1);

        // The colour's pool index is taken by another look (reordered list) → the next free index.
        PetLook::query()->where('id', $one->id)->update(['colour_key' => 'other']);
        $three = $service->lookFor(BreedType::Boxer, 'brindle');
        expect($three->id)->not->toBe($one->id)
            ->and($three->colour_key)->toBe('brindle')
            ->and($three->pool_index)->toBe($one->pool_index + 1);
    });

    it('still shares when a paid breed has no colour list (fail safe: colour sampled from its appearance, never unique by accident)', function () {
        config(['services.fal_ai.key' => null, 'breed_colours.west_highland_white_terrier' => null]);
        expect(app(BreedColourCatalogue::class)->for(BreedType::WestHighlandWhiteTerrier))->toBe([])
            ->and(app(SharedBreedLookService::class)->appliesTo(BreedType::WestHighlandWhiteTerrier, 12))->toBeTrue();

        // The Westie standard has one colour → both pets land on the same look.
        $a = bcPet('west_highland_white_terrier');
        $b = bcPet('west_highland_white_terrier');
        $look = PetLook::find($a->pet_look_id);

        expect($look)->not->toBeNull()
            ->and($b->pet_look_id)->toBe($a->pet_look_id)
            ->and($look->colour_key)->toStartWith('auto-')
            ->and(BreedColourCatalogue::isDerivedKey($look->colour_key))->toBeTrue()
            ->and($a->coat_colour)->toBeNull()   // no picker label for a derived key
            ->and($a->pet_dna['traits']['coat_color'])->toBe(config('breed_appearance.west_highland_white_terrier.traits.coat_color')[0] ?? null)
            ->and(PetLook::count())->toBe(1);

        // A breed with several colours and no list: every sampled colour is one of its appearance options.
        config(['breed_colours.labrador_retriever' => null]);
        $lab = bcPet('labrador_retriever');
        $values = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.labrador_retriever.traits.coat_color'));
        expect($lab->pet_look_id)->not->toBeNull()
            ->and($values)->toContain($lab->pet_dna['traits']['coat_color'])
            ->and(PetLook::find($lab->pet_look_id)->colour_key)->toStartWith('auto-');
    });

    it('keeps the mutt (and the free cat) on their pool: no colour, pool look', function () {
        config(['services.fal_ai.key' => null]);

        $mutt = bcPet('mutt');
        expect($mutt->pet_look_id)->not->toBeNull()
            ->and(PetLook::find($mutt->pet_look_id)->colour_key)->toBeNull()
            ->and($mutt->coat_colour)->toBeNull();
    });

    it('existing pets keep their media when the switch changes', function () {
        config(['services.fal_ai.key' => null]);
        $shared = bcPet('labrador_retriever', 'black');
        useUniquePetMedia(true);
        $unique = bcPet('labrador_retriever', 'black');

        expect($shared->fresh()->pet_look_id)->not->toBeNull()
            ->and($unique->pet_look_id)->toBeNull();
    });
});

/* ─────────────────────────── Switch on = unique ─────────────────────────── */

describe('unique AI media per pet (switch on)', function () {
    it('gives every new paid pet its own DNA with the chosen colour, no shared look', function () {
        config(['services.fal_ai.key' => null]);
        useUniquePetMedia(true);

        $a = bcPet('labrador_retriever', 'chocolate');
        $b = bcPet('labrador_retriever', 'chocolate');

        expect($a->pet_look_id)->toBeNull()
            ->and($a->pet_dna)->toHaveKey('salt')
            ->and($a->pet_dna['traits']['coat_color'])->toBe('chocolate brown')
            ->and($a->coat_colour)->toBe('chocolate')
            ->and($b->pet_dna['seed'])->not->toBe($a->pet_dna['seed'])
            ->and(PetLook::count())->toBe(0);

        // A rare colour (weight 1 of 11) every time — chosen, not sampled.
        foreach (range(1, 3) as $i) {
            $rare = bcPet('labrador_retriever', 'light-liver');
            expect($rare->pet_dna['traits']['coat_color'])->toBe('light liver brown')
                ->and($rare->coat_colour)->toBe('light-liver');
        }
    });

    it('generates per pet (one fal call each)', function () {
        bcFakeFal();
        useUniquePetMedia(true);

        bcPet('french_bulldog', 'brindle');
        bcPet('french_bulldog', 'brindle');

        expect(bcFalCalls())->toBe(2);
    });
});

/* ─────────────────────────── Admin ─────────────────────────── */

describe('admin', function () {
    it('regenerates a shared look once for all its pets; old files stay until the new ones are stored', function () {
        bcFakeFal();
        $a = bcPet('pomeranian', 'cream');
        $b = bcPet('pomeranian', 'cream');
        $old = bcImage($a)->storage_path;
        expect(bcFalCalls())->toBe(1);

        $look = PetLook::find($a->pet_look_id);
        $result = app(PetMediaService::class)->regenerateLook($look);   // sync queue: runs now
        expect($result->outcome)->toBe(LookRegeneration::QUEUED)
            ->and($result->pets)->toBe(2)
            ->and($result->stages)->toBe(['young']);

        expect(bcFalCalls())->toBe(2)
            ->and(bcImage($a)->storage_path)->not->toBe($old)
            ->and(bcImage($b)->storage_path)->toBe(bcImage($a)->storage_path)
            ->and(bcImage($a)->storage_path)->toContain('-g2.')
            ->and(bcImage($a)->status)->toBe('ready');

        // A second regenerate right after (the first is stored) works again: one more call, generation 3.
        expect(app(PetMediaService::class)->regenerateLook($look)->outcome)->toBe(LookRegeneration::QUEUED)
            ->and(bcFalCalls())->toBe(3)
            ->and(bcImage($a)->storage_path)->toContain('-g3.')
            ->and(bcImage($b)->storage_path)->toBe(bcImage($a)->storage_path);

        // Nothing while fal is off.
        config(['services.fal_ai.key' => null]);
        expect(app(PetMediaService::class)->regenerateLook($look)->outcome)->toBe(LookRegeneration::AI_OFF);
    });

    it('regenerates only the stages its active pets are at; an earlier stage stays READY and the new image grows from it', function () {
        bcFakeFal();
        $puppy = bcPet('pomeranian', 'cream', null, LifeStage::Puppy);   // look puppy row (text-to-image)
        $young = bcPet('pomeranian', 'cream', null, LifeStage::Young);   // look young row (edit of the puppy image)
        expect($young->pet_look_id)->toBe($puppy->pet_look_id);

        $look = PetLook::find($young->pet_look_id);
        $puppyRow = PetMedia::where('pet_look_id', $look->id)->where('kind', 'image')->where('life_stage', 'puppy')->sole();
        $youngRow = PetMedia::where('pet_look_id', $look->id)->where('kind', 'image')->where('life_stage', 'young')->sole();
        expect([$puppyRow->status, $youngRow->status])->toBe(['ready', 'ready']);

        // The puppy is no longer played: only the young stage has an active pet.
        $puppy->forceFill(['is_active' => false])->saveQuietly();
        $calls = bcFalCalls();

        $result = app(PetMediaService::class)->regenerateLook($look);

        expect($result->outcome)->toBe(LookRegeneration::QUEUED)
            ->and($result->stages)->toBe(['young'])
            ->and($result->pets)->toBe(1)
            ->and(bcFalCalls())->toBe($calls + 1)
            ->and($puppyRow->fresh()->only(['status', 'generation', 'storage_path']))->toBe($puppyRow->only(['status', 'generation', 'storage_path']))
            ->and($youngRow->fresh()->generation)->toBe($youngRow->generation + 1)
            ->and(bcImage($young)->storage_path)->toBe($youngRow->fresh()->storage_path)
            ->and(bcImage($young)->storage_path)->toContain('reference-young-g2.');

        // The new young image is an EDIT of the kept puppy image (same animal, older).
        $last = Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://fal.run/'))->last()[0];
        expect($last->url())->toBe('https://fal.run/fal-ai/nano-banana-pro/edit');
    });

    it('tells "busy" from a stale row: running / submitted rows block, a pending row without a request or a dead claim does not', function () {
        bcFakeFal();
        $pet = bcPet('boxer', 'brindle');
        $look = PetLook::find($pet->pet_look_id);
        $row = PetMedia::where('pet_look_id', $look->id)->where('kind', 'image')->sole();
        Queue::fake([GeneratePetReferenceImage::class]);
        $service = app(PetMediaService::class);

        $row->forceFill(['status' => 'running', 'request_id' => null, 'source_url' => null])->save();
        expect($service->regenerateLook($look)->outcome)->toBe(LookRegeneration::BUSY);

        $row->forceFill(['status' => 'pending', 'request_id' => 'req-live'])->save();
        expect($service->regenerateLook($look)->outcome)->toBe(LookRegeneration::BUSY);
        Queue::assertNothingPushed();

        // Stale: pending, no fal request (its job died) → regenerate proceeds.
        $row->forceFill(['status' => 'pending', 'request_id' => null])->save();
        $result = $service->regenerateLook($look);
        expect($result->outcome)->toBe(LookRegeneration::QUEUED)->and($result->pets)->toBe(1);
        Queue::assertPushed(GeneratePetReferenceImage::class, 1);

        // Dead claim: running, no request, older than the stale-claim window.
        $row->forceFill(['status' => 'running', 'request_id' => null, 'source_url' => null])->save();
        PetMedia::whereKey($row->id)->update(['updated_at' => now()->subMinutes(PetMediaService::STALE_CLAIM_MINUTES + 1)]);
        PetMedia::where('pet_id', $pet->id)->where('kind', 'image')->update(['status' => 'ready']);
        expect($service->regenerateLook($look)->outcome)->toBe(LookRegeneration::QUEUED);
    });

    it('says when no active pet uses the look (nothing reset) and shows each outcome in Filament', function () {
        bcFakeFal();
        $pet = bcPet('boxer', 'fawn');
        $look = PetLook::find($pet->pet_look_id);
        $row = PetMedia::where('pet_look_id', $look->id)->where('kind', 'image')->sole();
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

        $row->forceFill(['status' => 'running'])->save();
        Livewire::test(ListPetLooks::class)->callTableAction('regenerate', $look)
            ->assertNotified(__('admin.looks.regenerate_busy'));

        $row->forceFill(['status' => 'ready'])->save();
        $pet->forceFill(['is_active' => false])->saveQuietly();
        Livewire::test(ListPetLooks::class)->callTableAction('regenerate', $look)
            ->assertNotified(__('admin.looks.regenerate_no_pets'));
        expect($row->fresh()->generation)->toBe($row->generation);

        $pet->forceFill(['is_active' => true])->saveQuietly();
        Livewire::test(ListPetLooks::class)->callTableAction('regenerate', $look)
            ->assertNotified(__('admin.looks.regenerate_queued', ['count' => 1]));

        config(['services.fal_ai.key' => null]);
        Livewire::test(ListPetLooks::class)->callTableAction('regenerate', $look)
            ->assertNotified(__('admin.looks.regenerate_ai_off'));
    });

    it('superadmin switches "unique AI media per pet" in Feature switches (audited, default off)', function () {
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);

        Livewire::test(FeatureSwitches::class)
            ->assertFormSet(['unique_pet_media' => false])
            ->fillForm(['unique_pet_media' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(AppSettingsService::class)->uniquePetMedia())->toBeTrue()
            ->and(AppSetting::find(AppSettingsService::UNIQUE_MEDIA_KEY)->value)->toBe(['enabled' => true])
            ->and(AppSettingChange::where('key', AppSettingsService::UNIQUE_MEDIA_KEY)->sole()->user_id)->toBe($admin->id);

        $this->get('/admin/pet-looks')->assertOk();
        $this->actingAs(User::factory()->parent()->create())->get('/admin/pet-looks')->assertForbidden();
    });

    it('saves only the switch that changed (no audit row for the other one) and lists both in the history', function () {
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);

        // Unchanged save: nothing written for either key.
        Livewire::test(FeatureSwitches::class)->call('save')->assertHasNoFormErrors();
        expect(AppSetting::count())->toBe(0)->and(AppSettingChange::count())->toBe(0);

        // Only the media switch changes → only its row.
        Livewire::test(FeatureSwitches::class)->fillForm(['unique_pet_media' => true])->call('save');
        expect(AppSettingChange::pluck('key')->all())->toBe([AppSettingsService::UNIQUE_MEDIA_KEY])
            ->and(AppSetting::find(AppSettingsService::CATS_KEY))->toBeNull();

        // Only the cats switch changes → only its row.
        Livewire::test(FeatureSwitches::class)->fillForm(['cats_mode' => 'everyone'])->call('save');
        expect(AppSettingChange::orderBy('id')->pluck('key')->all())->toBe([AppSettingsService::UNIQUE_MEDIA_KEY, AppSettingsService::CATS_KEY]);

        $keys = Livewire::test(FeatureSwitches::class)->instance()->getChanges()->pluck('key')->all();
        expect($keys)->toContain(AppSettingsService::UNIQUE_MEDIA_KEY)->toContain(AppSettingsService::CATS_KEY);

        // Saving "off" over no row is no change (service level, the default).
        AppSetting::whereKey(AppSettingsService::UNIQUE_MEDIA_KEY)->delete();
        Cache::forget(AppSettingsService::cacheKey(AppSettingsService::UNIQUE_MEDIA_KEY));
        app(AppSettingsService::class)->updateUniquePetMedia($admin, false);
        expect(AppSetting::find(AppSettingsService::UNIQUE_MEDIA_KEY))->toBeNull();
    });

    it('counts only free-pool looks as the pool; shared paid looks are counted apart (AI Lab)', function () {
        config(['services.fal_ai.key' => null]);
        bcPet('mutt');
        bcPet('labrador_retriever', 'black');
        bcPet('labrador_retriever', 'chocolate');

        $pool = app(PetLookPoolService::class);
        expect($pool->counts())->toBe(['mutt' => 1])
            ->and($pool->sharedCount())->toBe(2);
    });
});
