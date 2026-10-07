<?php

use App\Jobs\GeneratePetReferenceImage;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaHistory;
use App\Models\User;
use App\Services\Media\PetGrowthService;
use App\Services\Media\PetMediaService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R04 part 2 — growth album: the pet's reference images across life
| stages (pet_media_history + current slot), GET /api/child/pet/growth,
| GET /api/parent/pets/{pet}/growth, GET /api/media/history/{history},
| account export and deletion.
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana (UTC+2 until 2026-10-25 03:00, then UTC+1).
| Pet born 2026-10-05 08:00 UTC (10:00 local), puppy of 2 months at arrival:
| +1 month per completed week.
*/

const GA_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

beforeEach(function () {
    seedLifeStageData();
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    config(['app.url' => 'https://api.petprep.si']);
    $this->withoutMiddleware([ThrottleRequests::class]);
    Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'UTC'));
});

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function gaFamily(array $attributes = []): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->pinOnlyChild()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse('2026-10-05 08:00:00', 'UTC'),
        'arrival_age_months' => 2,
        'life_stage' => 'young',
    ], $attributes)));

    return [$parent, $child, $pet->fresh()];
}

function gaFile(Pet $pet, int $generation): string
{
    $path = "{$pet->id}/reference-g{$generation}.jpg";
    Storage::disk('pet_media')->put($path, GA_JPEG.str_repeat("\x00", 64 + $generation));

    return $path;
}

/** An archived image of an earlier stage (file on the fake disk unless $withFile = false). */
function gaHistory(Pet $pet, ?string $stage, int $generation, ?string $takenAtUtc, bool $withFile = true): PetMediaHistory
{
    $path = $withFile ? gaFile($pet, $generation) : "{$pet->id}/reference-g{$generation}.jpg";

    return PetMediaHistory::create([
        'pet_id' => $pet->id, 'kind' => 'image', 'life_stage' => $stage, 'generation' => $generation,
        'storage_path' => $path, 'bytes' => 80, 'mime' => 'image/jpeg',
        'archived_at' => now(), 'taken_at' => $takenAtUtc !== null ? Carbon::parse($takenAtUtc, 'UTC') : null,
    ]);
}

/** The current image slot (READY, stored at $completedAtUtc). */
function gaCurrent(Pet $pet, ?string $stage, int $generation, string $completedAtUtc): PetMedia
{
    return PetMedia::create([
        'pet_id' => $pet->id, 'kind' => 'image', 'state' => null, 'status' => 'ready', 'generation' => $generation,
        'storage_path' => gaFile($pet, $generation), 'mime' => 'image/jpeg', 'bytes' => 80, 'profile' => 'nano_banana_pro',
        'life_stage' => $stage, 'completed_at' => Carbon::parse($completedAtUtc, 'UTC'),
    ]);
}

function gaPath(string $url): string
{
    $parts = parse_url($url);

    return $parts['path'].'?'.$parts['query'];
}

function gaAs(User $user): void
{
    app('auth')->forgetGuards();
    actingAsRole($user);
}

/** puppy (g1, before birth) → young (g2, week 4) → adult (g3, current, week 8). */
function gaGrownPet(): array
{
    [$parent, $child, $pet] = gaFamily(['life_stage' => 'adult']);
    $h1 = gaHistory($pet, 'puppy', 1, '2026-10-05 07:00:00');
    $h2 = gaHistory($pet, 'young', 2, '2026-11-02 12:00:00');
    $current = gaCurrent($pet, 'adult', 3, '2026-11-30 12:00:00');

    return [$parent, $child, $pet, $h1, $h2, $current];
}

/* ─────────────────────────── Timeline ─────────────────────────── */

describe('growth timeline', function () {
    it('lists the archived images oldest first and the current image last, with stage, age, taken_at and signed URLs', function () {
        [, $child, $pet, $h1, $h2, $current] = gaGrownPet();
        gaAs($child);

        $body = getJson('/api/child/pet/growth')->assertOk()->json();

        expect($body['pet_id'])->toBe($pet->id)
            ->and($body['expires_at'])->not->toBeNull()
            ->and(collect($body['growth'])->map(fn (array $e) => collect($e)->except('image_url')->all())->all())->toBe([
                ['generation' => 1, 'life_stage' => 'puppy', 'age_months' => 2, 'taken_at' => '2026-10-05T07:00:00+00:00', 'is_current' => false],
                ['generation' => 2, 'life_stage' => 'young', 'age_months' => 6, 'taken_at' => '2026-11-02T12:00:00+00:00', 'is_current' => false],
                ['generation' => 3, 'life_stage' => 'adult', 'age_months' => 10, 'taken_at' => '2026-11-30T12:00:00+00:00', 'is_current' => true],
            ])
            ->and($body['growth'][0]['image_url'])->toStartWith("https://api.petprep.si/api/media/history/{$h1->id}?")
            ->and($body['growth'][1]['image_url'])->toStartWith("https://api.petprep.si/api/media/history/{$h2->id}?")
            ->and($body['growth'][2]['image_url'])->toStartWith("https://api.petprep.si/api/media/{$current->id}?");
        // The current entry is the same picture as the pet's reference image.
        $state = getJson('/api/child/pet')->assertOk()->json('pet');
        expect($state['media']['reference_image_url'])->toBe($body['growth'][2]['image_url']);
    });

    it('gives the parent of the family the same album by pet id', function () {
        [$parent, $child, $pet] = gaGrownPet();
        gaAs($child);
        $childView = getJson('/api/child/pet/growth')->assertOk()->json();

        gaAs($parent);
        getJson("/api/parent/pets/{$pet->id}/growth")->assertOk()->assertExactJson($childView);
    });

    it('marks the archived image as current while the next stage image is pending or failed — no duplicate', function (string $status) {
        [, $child, $pet] = gaFamily();
        $h1 = gaHistory($pet, 'puppy', 1, '2026-10-05 07:00:00');
        // The stage transition archived g1 and bumped the slot; the old file is still served.
        PetMedia::create([
            'pet_id' => $pet->id, 'kind' => 'image', 'status' => $status, 'generation' => 2, 'storage_path' => $h1->storage_path,
            'mime' => 'image/jpeg', 'bytes' => 80, 'life_stage' => 'puppy', 'completed_at' => now(),
        ]);
        gaAs($child);

        $growth = getJson('/api/child/pet/growth')->assertOk()->json('growth');

        expect($growth)->toHaveCount(1)
            ->and($growth[0])->toMatchArray(['generation' => 1, 'life_stage' => 'puppy', 'is_current' => true])
            ->and($growth[0]['image_url'])->toStartWith("https://api.petprep.si/api/media/history/{$h1->id}?");
    })->with(['pending', 'running', 'failed']);

    it('skips history rows whose file is missing and a current slot without a stored file', function () {
        [, $child, $pet] = gaFamily();
        gaHistory($pet, 'puppy', 1, '2026-10-05 07:00:00', withFile: false);
        $h2 = gaHistory($pet, 'young', 2, '2026-11-02 12:00:00');
        // The next generation failed before anything was stored (no file).
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'failed', 'generation' => 3, 'error_reason' => 'budget_daily']);
        gaAs($child);

        $growth = getJson('/api/child/pet/growth')->assertOk()->json('growth');

        expect($growth)->toHaveCount(1)
            ->and($growth[0])->toMatchArray(['generation' => $h2->generation, 'life_stage' => 'young', 'is_current' => false]);
    });

    it('skips a current READY slot whose file disappeared', function () {
        [, $child, $pet] = gaFamily();
        $slot = gaCurrent($pet, 'young', 1, '2026-10-05 07:00:00');
        Storage::disk('pet_media')->delete($slot->storage_path);
        gaAs($child);

        getJson('/api/child/pet/growth')->assertOk()->assertExactJson(['pet_id' => $pet->id, 'growth' => [], 'expires_at' => null]);
    });

    it('falls back to the file time for rows archived before taken_at existed', function () {
        [, $child, $pet] = gaFamily();
        $h1 = gaHistory($pet, 'puppy', 1, null);
        gaAs($child);

        $taken = getJson('/api/child/pet/growth')->assertOk()->json('growth.0.taken_at');

        expect($taken)->toBe(Carbon::createFromTimestampUTC(Storage::disk('pet_media')->lastModified($h1->storage_path))->toIso8601String());
    });

    it('gives a pet with one picture a single current entry (the app shows the album from 2 entries)', function () {
        [, $child, $pet] = gaFamily(['life_stage' => 'puppy']);
        $slot = gaCurrent($pet, 'puppy', 1, '2026-10-05 07:30:00');
        gaAs($child);

        $growth = getJson('/api/child/pet/growth')->assertOk()->json('growth');

        expect($growth)->toHaveCount(1)
            ->and(collect($growth[0])->except('image_url')->all())->toBe([
                'generation' => 1, 'life_stage' => 'puppy', 'age_months' => 2, 'taken_at' => '2026-10-05T07:30:00+00:00', 'is_current' => true,
            ])
            ->and($growth[0]['image_url'])->toStartWith("https://api.petprep.si/api/media/{$slot->id}?");
    });

    it('gives a legacy pet its single image without stage or age, and a pet without any image an empty album', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->pinOnlyChild()->create(['parent_id' => $parent->id]);
        $legacy = Pet::factory()->create(['user_id' => $child->id]); // arrival_age_months null
        expect($legacy->isLegacyProfile())->toBeTrue();
        gaCurrent($legacy, null, 1, '2026-10-01 10:00:00');

        $growth = app(PetGrowthService::class)->albumFor($legacy->fresh())->toArray();
        expect($growth['growth'])->toHaveCount(1)
            ->and($growth['growth'][0])->toMatchArray(['life_stage' => null, 'age_months' => null, 'is_current' => true, 'taken_at' => '2026-10-01T10:00:00+00:00']);

        [, , $empty] = gaFamily();
        expect(app(PetGrowthService::class)->albumFor($empty)->toArray())->toBe(['pet_id' => $empty->id, 'growth' => [], 'expires_at' => null]);
    });

    it('stores when the archived image was taken at the stage transition', function () {
        Queue::fake([GeneratePetReferenceImage::class]);
        config(['services.fal_ai.key' => 'test-key']);
        [, , $pet] = gaFamily(['life_stage' => 'young']);
        $slot = gaCurrent($pet, 'puppy', 1, '2026-10-05 07:30:00');

        expect(app(PetMediaService::class)->startStageTransition($pet->fresh()))->toBe('started');

        $row = PetMediaHistory::where('pet_id', $pet->id)->sole();
        expect($row->storage_path)->toBe($slot->storage_path)
            ->and($row->taken_at?->toIso8601String())->toBe('2026-10-05T07:30:00+00:00')
            ->and($row->archived_at?->toIso8601String())->toBe('2026-12-01T09:00:00+00:00');
        Queue::assertPushed(GeneratePetReferenceImage::class);
    });
});

/* ─────────────────────────── Access ─────────────────────────── */

describe('growth endpoints access', function () {
    it('requires a token (401)', function () {
        [, , $pet] = gaGrownPet();

        getJson('/api/child/pet/growth')->assertUnauthorized();
        getJson("/api/parent/pets/{$pet->id}/growth")->assertUnauthorized();
    });

    it('hides another family\'s pet from a parent (404) and keeps roles apart (403)', function () {
        [$parent, $child, $pet] = gaGrownPet();
        [$otherParent, $otherChild, $otherPet] = gaFamily();

        gaAs($otherParent);
        getJson("/api/parent/pets/{$pet->id}/growth")->assertNotFound()->assertJsonPath('reason', 'pet_not_found');
        getJson('/api/parent/pets/999999/growth')->assertNotFound();
        getJson('/api/parent/pets/abc/growth')->assertNotFound();
        getJson("/api/parent/pets/{$otherPet->id}/growth")->assertOk()->assertJsonPath('pet_id', $otherPet->id);

        // The other family's child only ever sees their own pet.
        gaAs($otherChild);
        getJson('/api/child/pet/growth')->assertOk()->assertJsonPath('pet_id', $otherPet->id);

        gaAs($child);
        getJson("/api/parent/pets/{$pet->id}/growth")->assertForbidden();
        gaAs($parent);
        getJson('/api/child/pet/growth')->assertForbidden();
    });

    it('answers 404 no_pet for a child without a pet', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->pinOnlyChild()->create(['parent_id' => $parent->id]);
        gaAs($child);

        getJson('/api/child/pet/growth')->assertNotFound()->assertJsonPath('reason', 'no_pet');
    });
});

/* ─────────────────────────── Signed history route ─────────────────────────── */

describe('GET /api/media/history/{history}', function () {
    it('serves an archived image for a valid signature — without a token, to a caretaker and to a family parent', function () {
        [$parent, $child, , $h1] = gaGrownPet();
        $url = gaPath(app(PetMediaService::class)->signedHistoryUrl($h1));

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('X-Accel-Redirect');
        expect($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');

        $this->actingAs($child, 'sanctum')->get($url)->assertOk();
        app('auth')->forgetGuards();
        $this->actingAs($parent, 'sanctum')->get($url)->assertOk();
    });

    it('refuses another family, an expired, tampered, swapped or unsigned URL', function () {
        [, , , $h1] = gaGrownPet();
        [$otherParent, $otherChild, $otherPet] = gaFamily();
        $otherRow = gaHistory($otherPet, 'puppy', 1, '2026-10-05 07:00:00');
        $url = gaPath(app(PetMediaService::class)->signedHistoryUrl($h1));

        $this->actingAs($otherChild, 'sanctum')->get($url)->assertForbidden();
        app('auth')->forgetGuards();
        $this->actingAs($otherParent, 'sanctum')->get($url)->assertForbidden();
        app('auth')->forgetGuards();

        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        $this->get(preg_replace('/history\/\d+/', 'history/'.$otherRow->id, $url))->assertForbidden();
        $this->get("/api/media/history/{$h1->id}")->assertForbidden();
        // A signature for the history route never opens the slot route (unknown id → 404, else 403).
        expect($this->get(str_replace('/api/media/history/', '/api/media/', $url))->status())->toBeIn([403, 404]);

        $this->travel(91)->minutes();
        $this->get($url)->assertForbidden();
    });

    it('returns 404 when the archived file is gone', function () {
        [, , $pet] = gaFamily();
        $row = gaHistory($pet, 'puppy', 1, '2026-10-05 07:00:00', withFile: false);

        $this->get(gaPath(app(PetMediaService::class)->signedHistoryUrl($row)))->assertNotFound();
    });

    it('hands the archived file to Caddy in production (X-Accel-Redirect)', function () {
        config(['media.storage.serve_via' => 'caddy']);
        [$parent, , $pet, $h1] = gaGrownPet();
        $url = gaPath(app(PetMediaService::class)->signedHistoryUrl($h1));

        $response = $this->get($url)->assertOk()
            ->assertHeader('X-Accel-Redirect', "/{$pet->id}/reference-g1.jpg")
            ->assertHeader('Content-Type', 'image/jpeg');
        expect($response->getContent())->toBe('');

        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden()->assertHeaderMissing('X-Accel-Redirect');
        $this->actingAs($parent, 'sanctum')->get($url)->assertOk()->assertHeader('X-Accel-Redirect', "/{$pet->id}/reference-g1.jpg");
    });
});

/* ─────────────────────────── Export and deletion ─────────────────────────── */

describe('account export and deletion', function () {
    it('exports the growth album with signed links that open the archived images', function () {
        [$parent, , $pet, $h1, , $current] = gaGrownPet();
        gaAs($parent);

        $exported = collect(getJson('/api/parent/account/export')->assertOk()->json('pets'))->firstWhere('id', $pet->id);

        expect($exported['growth'])->toHaveCount(3)
            ->and(array_column($exported['growth'], 'generation'))->toBe([1, 2, 3])
            ->and(array_column($exported['growth'], 'life_stage'))->toBe(['puppy', 'young', 'adult'])
            ->and($exported['growth'][0]['image_url'])->toStartWith("https://api.petprep.si/api/media/history/{$h1->id}?")
            ->and($exported['growth'][2]['image_url'])->toStartWith("https://api.petprep.si/api/media/{$current->id}?")
            ->and($exported['growth_expires_at'])->not->toBeNull();

        app('auth')->forgetGuards();
        $this->get(gaPath($exported['growth'][0]['image_url']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });

    it('deletes archived images with the child who cared for the pet alone', function () {
        [$parent, $child, $pet, $h1, $h2] = gaGrownPet();
        gaAs($parent);

        deleteJson("/api/parent/children/{$child->id}", ['password' => 'password', 'confirm' => true])->assertOk()
            ->assertJsonPath('pets_deleted', 1);

        expect(PetMediaHistory::where('pet_id', $pet->id)->count())->toBe(0)
            ->and(Storage::disk('pet_media')->exists($h1->storage_path))->toBeFalse()
            ->and(Storage::disk('pet_media')->exists($h2->storage_path))->toBeFalse()
            ->and(Storage::disk('pet_media')->exists((string) $pet->id))->toBeFalse();
        $this->get(gaPath(app(PetMediaService::class)->signedHistoryUrl($h1)))->assertNotFound();
    });

    it('deletes archived images with the last parent\'s account (whole family)', function () {
        [$parent, , $pet, $h1, $h2, $current] = gaGrownPet();
        gaAs($parent);

        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])->assertOk();

        expect(Pet::find($pet->id))->toBeNull()
            ->and(PetMediaHistory::where('pet_id', $pet->id)->count())->toBe(0)
            ->and(Storage::disk('pet_media')->exists($h1->storage_path))->toBeFalse()
            ->and(Storage::disk('pet_media')->exists($h2->storage_path))->toBeFalse()
            ->and(Storage::disk('pet_media')->exists($current->storage_path))->toBeFalse();
    });
});
