<?php

use App\Filament\Widgets\SystemHealth\SystemHealthStats;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaHistory;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Media\ObjectStorageMover;
use App\Services\Media\PetMediaService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M4-05 — pet media on S3-compatible object storage (Hetzner Object Storage).
| Disk `pet_media_s3` is faked (no network): its temporary URLs are built by a
| closure that records the expiry. Covers the presigned redirect of
| GET /api/media/*, unchanged authorization, media:move-to-object-storage
| (dry run, idempotent, limit, verify, prune), faststart skip, pet deletion,
| and the System health tile.
|--------------------------------------------------------------------------
*/

const OS_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
const OS_MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    Storage::fake('pet_media_s3');
    Cache::forget(MediaStorage::STATUS_CACHE_KEY);
    config([
        'app.url' => 'https://api.petprep.si',
        'media.storage.url_ttl_minutes' => 60,
        'media.storage.presigned_ttl_seconds' => 300,
        'filesystems.disks.pet_media_s3.bucket' => 'petprep-media',
        'filesystems.disks.pet_media_s3.key' => 'test-key-id',
        'filesystems.disks.pet_media_s3.secret' => 'test-secret',
    ]);
    // The callback is re-bound to the disk adapter — record into a shared object, not $this.
    $presigned = $this->presigned = new ArrayObject;
    Storage::disk('pet_media_s3')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration, array $options) use ($presigned) {
        $presigned[] = ['path' => $path, 'expires' => $expiration->getTimestamp(), 'options' => $options];

        return "https://petprep-media.fsn1.your-objectstorage.com/pet-media/{$path}?X-Amz-Expires=".($expiration->getTimestamp() - now()->getTimestamp()).'&X-Amz-Signature=abc';
    });
    seedBreedConfigs();
});

/** A born pet of a child in a parent's family. */
function osFamilyPet(): array
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);
    $pet = Pet::factory()->freePlan()->create(['user_id' => $child->id, 'breed_type' => 'mutt', 'media_status' => 'ready']);

    return [$parent, $child, $pet];
}

/** A READY slot whose file is on $disk. */
function osStored(Pet $pet, string $disk = 'pet_media', string $kind = 'video', ?string $state = 'idle', ?string $path = null): PetMedia
{
    $bytes = $kind === 'image' ? OS_JPEG.str_repeat("\x00", 64) : OS_MP4.str_repeat("\x01", 128);
    $path ??= $kind === 'image' ? "{$pet->id}/reference-g1.jpg" : "{$pet->id}/{$state}-g1.mp4";
    Storage::disk($disk)->put($path, $bytes);

    return PetMedia::create([
        'pet_id' => $pet->id, 'kind' => $kind, 'state' => $kind === 'image' ? null : $state, 'status' => 'ready',
        'generation' => 1, 'source_generation' => $kind === 'video' ? 1 : null, 'storage_path' => $path,
        'mime' => $kind === 'image' ? 'image/jpeg' : 'video/mp4', 'bytes' => strlen($bytes),
        'profile' => $kind === 'image' ? 'nano_banana_pro' : 'kling_v3_pro',
    ]);
}

function osLast(ArrayObject $log): array
{
    $all = $log->getArrayCopy();

    return end($all) ?: [];
}

function osPath(string $url): string
{
    $parts = parse_url($url);

    return $parts['path'].'?'.$parts['query'];
}

function osUseObjectStorage(): void
{
    config(['media.storage.disk' => 'pet_media_s3']);
}

/* ───────────────────────────── Config ───────────────────────────── */

it('maps PET_MEDIA_DISK local|s3 to the disks; default stays the local disk', function () {
    expect(config('filesystems.disks.pet_media_s3.driver'))->toBe('s3')
        ->and(config('filesystems.disks.pet_media_s3.visibility'))->toBe('private')
        ->and(config('filesystems.disks.pet_media_s3.request_checksum_calculation'))->toBe('when_required')
        ->and(app(MediaStorage::class)->usesObjectStorage())->toBeFalse();

    foreach (['local' => 'pet_media', 'pet_media' => 'pet_media', 's3' => 'pet_media_s3'] as $env => $disk) {
        putenv("PET_MEDIA_DISK={$env}");
        $_ENV['PET_MEDIA_DISK'] = $_SERVER['PET_MEDIA_DISK'] = $env;
        $config = require config_path('media.php');
        expect($config['storage']['disk'])->toBe($disk);
    }
    putenv('PET_MEDIA_DISK');
    unset($_ENV['PET_MEDIA_DISK'], $_SERVER['PET_MEDIA_DISK']);
});

it('builds a real presigned Hetzner URL from the disk config (aws-sdk, no network)', function () {
    $disk = Storage::build(config('filesystems.disks.pet_media_s3'));

    $url = $disk->temporaryUrl('12/idle-g1.mp4', now()->addMinutes(5), ['ResponseContentType' => 'video/mp4']);

    expect($url)->toStartWith('https://petprep-media.fsn1.your-objectstorage.com/pet-media/12/idle-g1.mp4?')
        ->toContain('X-Amz-Signature=')->toContain('X-Amz-Expires=300')->toContain('response-content-type=video%2Fmp4')
        ->not->toContain('test-secret');
    Http::assertNothingSent();
});

/* ───────────────────── GET /api/media/* on object storage ───────────────────── */

describe('serving from object storage', function () {
    beforeEach(fn () => osUseObjectStorage());

    it('redirects a valid signed URL (no token, caretaker, family parent) to a short-lived presigned URL', function () {
        [$parent, $child, $pet] = osFamilyPet();
        $video = osStored($pet, 'pet_media_s3');
        $url = osPath(app(PetMediaService::class)->signedUrl($video));

        $response = $this->get($url)->assertStatus(302)
            ->assertHeaderMissing('X-Accel-Redirect')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        expect($response->headers->get('Location'))->toStartWith("https://petprep-media.fsn1.your-objectstorage.com/pet-media/{$pet->id}/idle-g1.mp4?")
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($response->getContent())->not->toContain(OS_MP4);

        $presigned = osLast($this->presigned);
        expect($presigned['path'])->toBe("{$pet->id}/idle-g1.mp4")
            ->and($presigned['expires'] - now()->getTimestamp())->toBeLessThanOrEqual(300)->toBeGreaterThanOrEqual(60)
            ->and($presigned['options'])->toMatchArray(['ResponseContentType' => 'video/mp4', 'ResponseContentDisposition' => 'inline']);

        $this->actingAs($child, 'sanctum')->get($url)->assertStatus(302);
        app('auth')->forgetGuards();
        $this->actingAs($parent, 'sanctum')->get($url)->assertStatus(302);
    });

    it('also redirects range requests — the bucket answers the Range (AVPlayer)', function () {
        [, , $pet] = osFamilyPet();
        $video = osStored($pet, 'pet_media_s3');

        $this->get(osPath(app(PetMediaService::class)->signedUrl($video)), ['Range' => 'bytes=0-7'])->assertStatus(302);
    });

    it('never presigns past the expiry of our own signed URL', function () {
        [, , $pet] = osFamilyPet();
        $video = osStored($pet, 'pet_media_s3');
        config(['media.storage.presigned_ttl_seconds' => 3600]);
        $expires = now()->addSeconds(200)->toImmutable();

        $this->get(osPath(app(PetMediaService::class)->signedUrl($video, $expires)))->assertStatus(302);

        expect(osLast($this->presigned)['expires'])->toBeLessThanOrEqual($expires->getTimestamp());
    });

    it('keeps the authorization: another family, tampered / unsigned / expired URLs get 403 and no bucket URL', function () {
        [, , $pet] = osFamilyPet();
        $video = osStored($pet, 'pet_media_s3');
        $url = osPath(app(PetMediaService::class)->signedUrl($video));
        [$otherParent, $otherChild] = osFamilyPet();

        $this->actingAs($otherChild, 'sanctum')->get($url)->assertForbidden()->assertHeaderMissing('Location');
        app('auth')->forgetGuards();
        $this->actingAs($otherParent, 'sanctum')->get($url)->assertForbidden()->assertHeaderMissing('Location');
        app('auth')->forgetGuards();

        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden()->assertHeaderMissing('Location');
        $this->get("/api/media/{$video->id}")->assertForbidden()->assertHeaderMissing('Location');
        $this->travel(91)->minutes();
        $this->get($url)->assertForbidden()->assertHeaderMissing('Location');

        expect($this->presigned)->toHaveCount(0);
    });

    it('returns 404 (no redirect) while the slot has no stored file', function () {
        [, , $pet] = osFamilyPet();
        $slot = PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running']);

        $this->get(osPath(app(PetMediaService::class)->signedUrl($slot)))->assertNotFound()->assertHeaderMissing('Location');
    });

    it('redirects growth-album images the same way', function () {
        [, , $pet] = osFamilyPet();
        Storage::disk('pet_media_s3')->put("{$pet->id}/reference-g1-puppy.jpg", OS_JPEG);
        $entry = PetMediaHistory::create(['pet_id' => $pet->id, 'kind' => 'image', 'life_stage' => 'puppy', 'generation' => 1,
            'storage_path' => "{$pet->id}/reference-g1-puppy.jpg", 'bytes' => 20, 'mime' => 'image/jpeg', 'archived_at' => now()]);

        $this->get(osPath(app(PetMediaService::class)->signedHistoryUrl($entry)))->assertStatus(302);
        expect(osLast($this->presigned)['options']['ResponseContentType'])->toBe('image/jpeg');
    });

    it('keeps the app URL (path + v) independent of the storage: same signed URL before and after the switch', function () {
        [, , $pet] = osFamilyPet();
        $video = osStored($pet, 'pet_media_s3');
        $onS3 = app(PetMediaService::class)->signedUrl($video);
        config(['media.storage.disk' => 'pet_media']);

        expect(app(PetMediaService::class)->signedUrl($video))->toBe($onS3);
    });
});

/* ─────────────────────── media:move-to-object-storage ─────────────────────── */

describe('media:move-to-object-storage', function () {
    it('refuses when the bucket is not configured', function () {
        config(['filesystems.disks.pet_media_s3.bucket' => null]);

        $this->artisan('media:move-to-object-storage --dry-run')->assertFailed()
            ->expectsOutputToContain('not configured');
    });

    it('dry run reports and writes nothing', function () {
        [, , $pet] = osFamilyPet();
        osStored($pet);
        osStored($pet, kind: 'image');

        $this->artisan('media:move-to-object-storage --dry-run')->assertSuccessful();

        expect(Storage::disk('pet_media_s3')->allFiles())->toBe([])
            ->and(Storage::disk('pet_media')->allFiles())->toHaveCount(2);
    });

    it('copies every DB-referenced file (pet, look, history, candidate) with the same path; skips orphans; never deletes local', function () {
        [, , $pet] = osFamilyPet();
        $video = osStored($pet);
        osStored($pet, kind: 'image');
        osStored($pet, state: 'sleeping', path: 'looks/7/sleeping-adult-g1.mp4');
        Storage::disk('pet_media')->put("{$pet->id}/idle-g2.mp4", OS_MP4.'candidate');
        $video->update(['candidate_path' => "{$pet->id}/idle-g2.mp4"]);
        Storage::disk('pet_media')->put("{$pet->id}/reference-g0-puppy.jpg", OS_JPEG);
        PetMediaHistory::create(['pet_id' => $pet->id, 'kind' => 'image', 'life_stage' => 'puppy', 'generation' => 0,
            'storage_path' => "{$pet->id}/reference-g0-puppy.jpg", 'bytes' => 20, 'mime' => 'image/jpeg', 'archived_at' => now()]);
        Storage::disk('pet_media')->put('999/idle-g1.mp4', OS_MP4); // orphan (deleted pet)
        Storage::disk('pet_media')->put('.gitignore', '*');

        $this->artisan('media:move-to-object-storage')->assertSuccessful();

        $copied = Storage::disk('pet_media_s3')->allFiles();
        sort($copied);
        expect($copied)->toBe([
            "{$pet->id}/idle-g1.mp4", "{$pet->id}/idle-g2.mp4", "{$pet->id}/reference-g0-puppy.jpg", "{$pet->id}/reference-g1.jpg", 'looks/7/sleeping-adult-g1.mp4',
        ])
            ->and(Storage::disk('pet_media_s3')->get("{$pet->id}/idle-g1.mp4"))->toBe(Storage::disk('pet_media')->get("{$pet->id}/idle-g1.mp4"))
            ->and(Storage::disk('pet_media')->allFiles())->toHaveCount(7); // nothing local deleted
    });

    it('is idempotent and resumable with --limit', function () {
        [, , $pet] = osFamilyPet();
        osStored($pet);
        osStored($pet, kind: 'image');
        osStored($pet, state: 'sleeping');

        $this->artisan('media:move-to-object-storage --limit=2')->assertSuccessful();
        expect(Storage::disk('pet_media_s3')->allFiles())->toHaveCount(2);

        $this->artisan('media:move-to-object-storage')->assertSuccessful();
        expect(Storage::disk('pet_media_s3')->allFiles())->toHaveCount(3);

        $counts = app(ObjectStorageMover::class)->run(verify: true);
        expect($counts)->toMatchArray(['total' => 3, 'copied' => 0, 'already' => 3, 'verified' => 3, 'mismatched' => 0, 'failed' => 0]);
    });

    it('--verify finds a same-size object with other bytes and re-uploads it', function () {
        [, , $pet] = osFamilyPet();
        osStored($pet);
        $path = "{$pet->id}/idle-g1.mp4";
        $local = Storage::disk('pet_media')->get($path);
        Storage::disk('pet_media_s3')->put($path, str_repeat('X', strlen($local))); // corrupt, same size

        $this->artisan('media:move-to-object-storage --verify')->assertSuccessful()
            ->expectsOutputToContain('mismatch repaired');

        expect(Storage::disk('pet_media_s3')->get($path))->toBe($local);
    });

    it('--verify --dry-run reports a mismatch, repairs nothing and fails', function () {
        [, , $pet] = osFamilyPet();
        osStored($pet);
        $path = "{$pet->id}/idle-g1.mp4";
        $bad = str_repeat('X', strlen(Storage::disk('pet_media')->get($path)));
        Storage::disk('pet_media_s3')->put($path, $bad);

        $this->artisan('media:move-to-object-storage --verify --dry-run')->assertFailed()
            ->expectsOutputToContain('MISMATCH');

        expect(Storage::disk('pet_media_s3')->get($path))->toBe($bad);
    });

    it('re-uploads an object of the wrong size without --verify', function () {
        [, , $pet] = osFamilyPet();
        osStored($pet);
        $path = "{$pet->id}/idle-g1.mp4";
        Storage::disk('pet_media_s3')->put($path, 'partial');

        $this->artisan('media:move-to-object-storage')->assertSuccessful();

        expect(Storage::disk('pet_media_s3')->get($path))->toBe(Storage::disk('pet_media')->get($path));
    });

    it('--prune-local refuses while PET_MEDIA_DISK is still local, or without a full verify', function () {
        [, , $pet] = osFamilyPet();
        osStored($pet);

        $this->artisan('media:move-to-object-storage --verify --prune-local --force')->assertFailed()
            ->expectsOutputToContain('refusing to prune');
        osUseObjectStorage();
        $this->artisan('media:move-to-object-storage --prune-local --force')->assertFailed();
        $this->artisan('media:move-to-object-storage --verify --limit=1 --prune-local --force')->assertFailed();

        expect(Storage::disk('pet_media')->exists("{$pet->id}/idle-g1.mp4"))->toBeTrue();
    });

    it('--prune-local after the switch deletes only verified local copies (orphans stay)', function () {
        [, , $pet] = osFamilyPet();
        osStored($pet);
        Storage::disk('pet_media')->put('999/idle-g1.mp4', OS_MP4);
        $this->artisan('media:move-to-object-storage')->assertSuccessful();
        osUseObjectStorage();

        $this->artisan('media:move-to-object-storage --verify --prune-local --force')->assertSuccessful();

        expect(Storage::disk('pet_media')->exists("{$pet->id}/idle-g1.mp4"))->toBeFalse()
            ->and(Storage::disk('pet_media')->exists('999/idle-g1.mp4'))->toBeTrue()
            ->and(Storage::disk('pet_media_s3')->exists("{$pet->id}/idle-g1.mp4"))->toBeTrue();
    });

    it('makes no HTTP call (zero AI spend)', function () {
        [, , $pet] = osFamilyPet();
        osStored($pet);

        $this->artisan('media:move-to-object-storage --verify')->assertSuccessful();
        Http::assertNothingSent();
    });
});

/* ─────────────────────────── Other object-storage paths ─────────────────────────── */

it('media:faststart does nothing on object storage', function () {
    osUseObjectStorage();
    Storage::disk('pet_media_s3')->put('1/idle-g1.mp4', OS_MP4);

    $this->artisan('media:faststart')->assertSuccessful()->expectsOutputToContain('object storage');
});

it('deleting a pet\'s files on object storage also removes its local leftovers', function () {
    osUseObjectStorage();
    Storage::disk('pet_media_s3')->put('5/idle-g1.mp4', OS_MP4);
    Storage::disk('pet_media')->put('5/idle-g1.mp4', OS_MP4);
    Storage::disk('pet_media')->put('6/idle-g1.mp4', OS_MP4);

    app(PetMediaService::class)->deleteFilesOf(5);

    expect(Storage::disk('pet_media_s3')->exists('5/idle-g1.mp4'))->toBeFalse()
        ->and(Storage::disk('pet_media')->exists('5/idle-g1.mp4'))->toBeFalse()
        ->and(Storage::disk('pet_media')->exists('6/idle-g1.mp4'))->toBeTrue();
});

describe('System health: storage driver + bucket', function () {
    it('reports the local driver and a reachable configured bucket', function () {
        expect(app(MediaStorage::class)->status(fresh: true))->toMatchArray([
            'driver' => 'local', 'disk' => 'pet_media', 'bucket' => 'petprep-media', 'configured' => true, 'reachable' => true, 'error' => null,
        ]);
    });

    it('reports an unreachable bucket without leaking the request', function () {
        osUseObjectStorage();
        $broken = Mockery::mock(Filesystem::class);
        $broken->shouldReceive('exists')->andThrow(new RuntimeException("403 Forbidden\nAuthorization: AWS4-HMAC-SHA256 Credential=test-key-id"));
        Storage::set('pet_media_s3', $broken);

        $status = app(MediaStorage::class)->status(fresh: true);

        expect($status)->toMatchArray(['driver' => 's3', 'reachable' => false])
            ->and($status['error'])->toBe('RuntimeException: 403 Forbidden')->not->toContain('test-key-id');
    });

    it('reports no bucket when PET_MEDIA_S3_* is not set', function () {
        config(['filesystems.disks.pet_media_s3.bucket' => null]);

        expect(app(MediaStorage::class)->status(fresh: true))->toMatchArray(['configured' => false, 'reachable' => null]);
    });

    it('shows the storage tile to a superadmin', function () {
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

        Livewire::test(SystemHealthStats::class)->assertSee('Media storage')->assertSee('petprep-media');
    });
});
