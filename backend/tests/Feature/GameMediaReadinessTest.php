<?php

use App\Enums\BreedType;
use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\Media\GameMediaReadiness;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-T04 — a game waits for all its clips (David 2026-10-20, APP_REFINEMENT_SPEC §10)
|--------------------------------------------------------------------------
| If ANY clip a game shows is missing for the pet, the game is disabled
| (»Igra se še pripravlja«) — never half working. The play / wand payloads
| report it (`play.media_ready`, `wand.blocked_reason = media_not_ready`) and
| the start endpoints refuse with 422 `media_not_ready`. Readiness is the
| pet's EFFECTIVE media set: its own files or its shared look's (linked).
| A pet without AI media (drawn stage) is always ready.
| Family in Europe/Ljubljana, default quiet hours 21:00–07:00 local.
*/

beforeEach(function () {
    seedLifeStageData();
});

/**
 * A born, awake, clean pet with play: a Border Collie (paid / pre-M3-13 trial) or a cat.
 *
 * @param  'paid'|'legacy_trial'  $plan
 * @return array{0: User, 1: Pet}
 */
function gmFamily(string $plan = 'paid', BreedType $breed = BreedType::BorderCollie): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-20 08:00', 'Europe/Ljubljana')->utc());
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $factory = Pet::factory();
    $factory = $plan === 'paid' ? $factory->purchased() : $factory->legacyTrial();
    $pet = disableHygieneEvents($factory->create([
        'breed_type' => $breed->value,
        'user_id' => $child->id,
        'born_at' => now(),
        'last_decay_at' => now(),
        'last_step_reset_at' => now(),
        'arrival_age_months' => 12,
        'origin' => 'adopted',
    ]));
    Pet::whereKey($pet->id)->update(['play_scheduled_through' => '2999-12-31']);

    // Two hours later: awake, fed, clean, no decay owed.
    Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'Europe/Ljubljana')->utc());
    Pet::whereKey($pet->id)->update(['last_decay_at' => now(), 'hunger_level' => 100, 'thirst_level' => 100, 'hygiene_level' => 100]);

    return [$child, $pet->fresh()];
}

function gmPost(User $child, string $uri, array $body = []): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson($uri, $body);
}

function gmPlay(User $child, string $kind = 'play'): TestResponse
{
    return gmPost($child, '/api/child/pet/play', ['kind' => $kind]);
}

function gmState(User $child): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return getJson('/api/child/pet')->assertOk();
}

/** The pet's reference image stored (media pipeline running: AI media on). */
function gmImage(Pet $pet): PetMedia
{
    $pet->updateQuietly(['media_status' => 'ready']);

    return PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'ready', 'storage_path' => "{$pet->id}/reference-g1.jpg", 'mime' => 'image/jpeg']);
}

/** One video slot of the pet: stored (`ready`) or still being prepared (`pending`). */
function gmClip(Pet $pet, string $state, bool $stored = true): PetMedia
{
    return PetMedia::create([
        'pet_id' => $pet->id,
        'kind' => 'video',
        'state' => $state,
        'status' => $stored ? 'ready' : 'pending',
        'storage_path' => $stored ? "{$pet->id}/{$state}-g1.mp4" : null,
        'mime' => $stored ? 'video/mp4' : null,
    ]);
}

describe('ball game and cuddles (dog)', function () {
    it('refuses the play start with media_not_ready while one clip is missing, and allows it once all are stored', function (string $kind) {
        [$child, $pet] = gmFamily();
        gmImage($pet);
        gmClip($pet, 'idle');
        $playing = gmClip($pet, 'playing', stored: false);

        expect(gmState($child)->json('play'))->toMatchArray(['can_play' => false, 'media_ready' => false]);
        gmPlay($child, $kind)->assertStatus(422)
            ->assertJsonPath('reason', 'media_not_ready')
            ->assertJsonPath('next_allowed_at', null);

        $playing->update(['status' => 'ready', 'storage_path' => "{$pet->id}/playing-g1.mp4", 'mime' => 'video/mp4']);

        expect(gmState($child)->json('play'))->toMatchArray(['can_play' => true, 'media_ready' => true]);
        gmPlay($child, $kind)->assertOk()->assertJsonPath('status', 'accepted');
    })->with(['play', 'cuddle']);

    it('needs only the clips the pet is entitled to (basic tier: idle, no playing clip)', function () {
        [$child, $pet] = gmFamily('legacy_trial');
        gmImage($pet);

        expect(app(GameMediaReadiness::class)->requiredClips($pet, GameMediaReadiness::PLAY))->toBe(['idle'])
            ->and(gmState($child)->json('play.media_ready'))->toBeFalse();

        gmClip($pet, 'idle');

        expect(gmState($child)->json('play'))->toMatchArray(['can_play' => true, 'media_ready' => true]);
        gmPlay($child)->assertOk();
    });

    it('treats a pet without AI media (drawn stage) as ready', function () {
        [$child, $pet] = gmFamily();

        expect($pet->media_status)->toBe('disabled')
            ->and(gmState($child)->json('play.media_ready'))->toBeTrue();
        gmPlay($child)->assertOk();
    });

    it('keeps the game while an older generation of a clip is still served (a regeneration is running)', function () {
        [$child, $pet] = gmFamily();
        gmImage($pet);
        gmClip($pet, 'idle');
        gmClip($pet, 'playing')->update(['status' => 'running']);

        expect(gmState($child)->json('play.media_ready'))->toBeTrue();
    });

    it('counts the clips of the shared look linked into the pet (breed + colour / pool look)', function () {
        [$child, $pet] = gmFamily();
        $look = PetLook::create(['breed_type' => BreedType::BorderCollie->value, 'pool_index' => 1, 'trait_fingerprint' => 'gm-test', 'dna' => [], 'colour_key' => 'black-white']);
        $pet->updateQuietly(['pet_look_id' => $look->id]);
        gmImage($pet);
        $lookRow = fn (string $state) => PetMedia::create(['pet_look_id' => $look->id, 'kind' => 'video', 'state' => $state, 'life_stage' => 'young', 'status' => 'ready', 'storage_path' => "looks/{$look->id}/{$state}-young-g1.mp4", 'mime' => 'video/mp4']);
        $idle = $lookRow('idle');
        $playing = $lookRow('playing');

        // The look already has the files, but they are not linked into this pet yet.
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'ready', 'look_media_id' => $idle->id, 'storage_path' => $idle->storage_path, 'mime' => 'video/mp4']);
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'playing', 'status' => 'running', 'look_media_id' => $playing->id]);
        expect(gmState($child)->json('play.media_ready'))->toBeFalse();

        // Linked (the fan-out copies the look's file path into the pet's slot).
        PetMedia::where('pet_id', $pet->id)->where('state', 'playing')->update(['status' => 'ready', 'storage_path' => $playing->storage_path, 'mime' => 'video/mp4']);
        expect(gmState($child)->json('play.media_ready'))->toBeTrue();
        gmPlay($child)->assertOk();
    });

    it('carries readiness in the PetUpdated broadcast, so the app enables the game without a restart', function () {
        [, $pet] = gmFamily();
        gmImage($pet);
        gmClip($pet, 'idle');
        $playing = gmClip($pet, 'playing', stored: false);

        expect(PetUpdated::payloadFor($pet->fresh())['play'])->toMatchArray(['can_play' => false, 'media_ready' => false]);

        $playing->update(['status' => 'ready', 'storage_path' => "{$pet->id}/playing-g1.mp4", 'mime' => 'video/mp4']);

        expect(PetUpdated::payloadFor($pet->fresh(), 'video_ready')['play'])->toMatchArray(['can_play' => true, 'media_ready' => true]);
    });
});

describe('feather wand (cat)', function () {
    it('refuses the wand start with media_not_ready while a clip is missing, then allows it', function () {
        [$child, $cat] = gmFamily('paid', BreedType::DomesticCat);
        gmImage($cat);
        gmClip($cat, 'idle');
        $playing = gmClip($cat, 'playing', stored: false);

        $wand = gmState($child)->json('wand');
        expect($wand)->toMatchArray(['can_start' => false, 'blocked_reason' => 'media_not_ready', 'next_allowed_at' => null]);

        gmPost($child, '/api/child/pet/wand/start')->assertStatus(422)->assertJsonPath('reason', 'media_not_ready');

        $playing->update(['status' => 'ready', 'storage_path' => "{$cat->id}/playing-g1.mp4", 'mime' => 'video/mp4']);

        expect(PetUpdated::payloadFor($cat->fresh(), 'video_ready')['wand'])->toMatchArray(['can_start' => true, 'blocked_reason' => null]);
        gmPost($child, '/api/child/pet/wand/start')->assertOk();
    });

    it('lets a cat without AI media play the drawn game', function () {
        [$child] = gmFamily('paid', BreedType::DomesticCat);

        gmPost($child, '/api/child/pet/wand/start')->assertOk();
    });
});
