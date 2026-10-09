<?php

use App\Enums\BreedType;
use App\Enums\PushType;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Http\Resources\PairedPetResource;
use App\Jobs\SendPushNotification;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\User;
use App\Services\FamilyInviteService;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetDnaService;
use App\Services\NotificationService;
use App\Services\PetNameService;
use App\Services\Push\ExpoPushClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

/*
|--------------------------------------------------------------------------
| M5-R08 — optional pet name (David 2026-10-09)
|--------------------------------------------------------------------------
| PATCH /api/parent/pets/{pet}/name: a parent of the pet's family sets,
| changes or clears it (any pet: free / paid / paused / ended, dog or cat).
| Children → 403, another family → 404. Validation: 1–20 letters (č š ž …),
| space, hyphen, apostrophe; EN / SL word filter. The name is a label in
| the payloads only — pushes and AI prompts never change because of it.
*/

beforeEach(function () {
    seedBreedConfigs();
    Carbon::setTestNow(Carbon::parse('2026-10-21 08:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function pnmFamily(array $attributes = []): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->pinOnlyChild()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(array_merge([
        'user_id' => $child->id,
    ], $attributes)));

    return [$parent, $child, $pet->fresh()];
}

function pnmPatch(Pet $pet, mixed $name): TestResponse
{
    return patchJson("/api/parent/pets/{$pet->id}/name", ['name' => $name]);
}

describe('PATCH /api/parent/pets/{pet}/name — parent', function () {
    it('sets, renames and clears the name', function () {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);

        pnmPatch($pet, 'Luna')->assertOk()->assertExactJson(['pet_id' => $pet->id, 'name' => 'Luna']);
        expect($pet->fresh()->name)->toBe('Luna');

        pnmPatch($pet, 'Mali Žarek')->assertOk()->assertJsonPath('name', 'Mali Žarek');
        expect($pet->fresh()->name)->toBe('Mali Žarek');

        pnmPatch($pet, null)->assertOk()->assertExactJson(['pet_id' => $pet->id, 'name' => null]);
        expect($pet->fresh()->name)->toBeNull();

        pnmPatch($pet, 'Rex')->assertOk();
        pnmPatch($pet, '   ')->assertOk()->assertJsonPath('name', null);
        pnmPatch($pet, 'Rex')->assertOk();
        pnmPatch($pet, '')->assertOk()->assertJsonPath('name', null);
        expect($pet->fresh()->name)->toBeNull();
    });

    it('normalizes whitespace and the typographic apostrophe', function () {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);

        pnmPatch($pet, "  Gospod \t  Muc  ")->assertOk()->assertJsonPath('name', 'Gospod Muc');
        pnmPatch($pet, 'D’Artagnan')->assertOk()->assertJsonPath('name', "D'Artagnan");
        pnmPatch($pet, "O'Malley-Brown")->assertOk()->assertJsonPath('name', "O'Malley-Brown");
        // Decomposed č (c + combining caron) is stored composed (NFC).
        pnmPatch($pet, "C\u{030C}rni")->assertOk()->assertJsonPath('name', 'Črni');
    });

    it('accepts letters of other alphabets and diacritics', function (string $name) {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);

        pnmPatch($pet, $name)->assertOk()->assertJsonPath('name', $name);
    })->with(['Čopek', 'Šapa', 'Žužek', 'Ćiro', 'Đuro', 'Käthe', 'Niño', 'Zoë', 'Мурка', 'Ŝ', 'Ana-Marija']);

    it('counts characters, not bytes (20 multibyte letters pass, 21 fail)', function () {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);

        pnmPatch($pet, str_repeat('ž', 20))->assertOk();
        pnmPatch($pet, str_repeat('a', 20))->assertOk();
        pnmPatch($pet, str_repeat('ž', 21))->assertStatus(422)
            ->assertJsonPath('codes.name', 'name_too_long')
            ->assertJsonPath('reason', 'name_too_long');
        pnmPatch($pet, str_repeat('a', 21))->assertStatus(422)->assertJsonPath('reason', 'name_too_long');
        // Whitespace is collapsed before counting.
        pnmPatch($pet, 'Abcdefghij      Abcdefghi')->assertOk()->assertJsonPath('name', 'Abcdefghij Abcdefghi');
        expect($pet->fresh()->name)->toBe('Abcdefghij Abcdefghi');
    });

    it('rejects digits, emoji, symbols and names without a letter (name_invalid)', function (mixed $name) {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);
        $pet->forceFill(['name' => 'Luna'])->saveQuietly();

        pnmPatch($pet, $name)->assertStatus(422)
            ->assertJsonPath('codes.name', 'name_invalid')
            ->assertJsonPath('reason', 'name_invalid')
            ->assertJsonStructure(['message', 'errors' => ['name']]);
        expect($pet->fresh()->name)->toBe('Luna');
    })->with([
        'digits' => 'Rex2',
        'only digits' => '123',
        'emoji' => 'Luna 🐶',
        'only emoji' => '🐱',
        'dot' => 'Mr. Rex',
        'underscore' => 'Rex_1',
        'at' => 'R@x',
        'exclamation' => 'Rex!',
        'html' => '<b>Rex</b>',
        'only hyphen' => '-',
        'only apostrophes' => "''",
        'array' => [['Rex']],
        'number' => 42,
        'bool' => true,
    ]);

    it('requires the name field (null clears, a missing key is invalid)', function () {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);

        patchJson("/api/parent/pets/{$pet->id}/name", [])->assertStatus(422)->assertJsonPath('reason', 'name_invalid');
    });

    it('rejects inappropriate words in English and Slovenian (name_not_allowed)', function (string $name) {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);

        pnmPatch($pet, $name)->assertStatus(422)
            ->assertJsonPath('codes.name', 'name_not_allowed')
            ->assertJsonPath('reason', 'name_not_allowed');
        expect($pet->fresh()->name)->toBeNull();
    })->with([
        'en word' => 'Shit',
        'en upper case' => 'BITCH',
        'en whole word in a name' => 'Mister Dick',
        'en fragment' => 'Fuckface',
        'sl word' => 'Pizda',
        'sl with diacritics' => 'Pička',
        'sl diacritics stripped' => 'Picka',
        'sl ščanje' => 'Ščanje',
        'sl fragment' => 'Kurbica',
        'spaced letters' => 'F u c k',
        'hyphenated letters' => 'S-h-i-t',
        'apostrophes' => "K'u'r'a'c",
        'typographic apostrophe' => 'Ku’rac',
        'split word' => 'pi zda',
        'accented trick' => 'Fück',
    ]);

    it('does not block ordinary names that only contain a blocked short word', function (string $name) {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);

        pnmPatch($pet, $name)->assertOk()->assertJsonPath('name', $name);
    })->with(['Bassett', 'Cocker', 'Dickens', 'Sexton', 'Classy', 'Pikica', 'Piškotek', 'Srček', 'Kurt']);

    it('names any pet of the family: cat, free mutt, paid, paused, ended, unborn', function (array $attributes) {
        [$parent, , $pet] = pnmFamily($attributes);
        actingAsRole($parent);

        pnmPatch($pet, 'Smrkec')->assertOk()->assertJsonPath('name', 'Smrkec');
        expect($pet->fresh()->name)->toBe('Smrkec');
    })->with([
        'cat' => [['breed_type' => BreedType::DomesticCat->value]],
        'paid border collie' => [['breed_type' => BreedType::BorderCollie->value, 'plan' => 'challenge', 'challenge_paid_at' => '2026-10-20 08:00:00']],
        'hard stopped' => [['is_hard_stopped' => true]],
        'game over' => [['is_game_over' => true]],
        'inactive' => [['is_active' => false]],
        'unborn' => [['born_at' => null]],
    ]);

    it('lets a second parent of the family name the pet', function () {
        [$parent, , $pet] = pnmFamily();
        $second = User::factory()->parent()->create();
        $code = app(FamilyInviteService::class)->createInvite($parent)['code'];
        app(FamilyInviteService::class)->joinFamily($second, $code);
        actingAsRole($second->refresh());

        pnmPatch($pet, 'Bela')->assertOk();
        expect($pet->fresh()->name)->toBe('Bela');
    });
});

describe('PATCH /api/parent/pets/{pet}/name — authorization', function () {
    it('refuses a child (403) — also with a legacy * token', function () {
        [, $child, $pet] = pnmFamily();

        actingAsRole($child);
        pnmPatch($pet, 'Luna')->assertForbidden();

        app('auth')->forgetGuards();
        $this->actingAsWithAbilities($child, ['*']);
        pnmPatch($pet, 'Luna')->assertForbidden();

        expect($pet->fresh()->name)->toBeNull();
    });

    it('answers 404 pet_not_found for another family\'s pet and unknown ids', function () {
        [, , $pet] = pnmFamily();
        [$stranger] = pnmFamily();
        actingAsRole($stranger);

        pnmPatch($pet, 'Luna')->assertNotFound()->assertJsonPath('reason', 'pet_not_found');
        patchJson('/api/parent/pets/999999/name', ['name' => 'Luna'])->assertNotFound()->assertJsonPath('reason', 'pet_not_found');
        patchJson('/api/parent/pets/abc/name', ['name' => 'Luna'])->assertNotFound();
        expect($pet->fresh()->name)->toBeNull();
    });

    it('requires authentication', function () {
        [, , $pet] = pnmFamily();

        pnmPatch($pet, 'Luna')->assertUnauthorized();
    });
});

describe('payloads carry the name', function () {
    it('shows the name in the child state, the parent dashboard and the family list', function () {
        [$parent, $child, $pet] = pnmFamily();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('pet.name', null);

        app('auth')->forgetGuards();
        actingAsRole($parent);
        pnmPatch($pet, 'Pika')->assertOk();

        $dashboard = getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('pet.name', 'Pika');
        expect(collect($dashboard->json('family.pets'))->firstWhere('id', $pet->id)['name'])->toBe('Pika');

        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('pet.name', 'Pika');

        $paired = (new PairedPetResource($pet->fresh(), $child))->toArray(Request::create('/'));
        expect($paired['name'])->toBe('Pika');
    });

    it('broadcasts one pet_renamed PetUpdated with the name — none when unchanged', function () {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);
        Event::fake([PetUpdated::class]);

        pnmPatch($pet, 'Pika')->assertOk();
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id
            && $e->eventType === PetNameService::EVENT_TYPE
            && $e->payload['name'] === 'Pika');

        pnmPatch($pet, '  Pika ')->assertOk();
        Event::assertDispatchedTimes(PetUpdated::class, 1);

        pnmPatch($pet, null)->assertOk();
        Event::assertDispatchedTimes(PetUpdated::class, 2);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->payload['name'] === null && $e->eventType === 'pet_renamed');
    });

    it('includes the name in the family data export and deletes it with the pet', function () {
        [$parent, , $pet] = pnmFamily();
        actingAsRole($parent);
        pnmPatch($pet, 'Pika')->assertOk();

        $export = getJson('/api/parent/account/export')->assertOk();
        expect(collect($export->json('pets'))->firstWhere('id', $pet->id)['name'])->toBe('Pika');

        $pet->fresh()->delete();
        expect(Pet::whereKey($pet->id)->exists())->toBeFalse();
    });
});

describe('the name never reaches push texts or AI prompts', function () {
    it('leaves every AI prompt of a dog and a cat unchanged', function (BreedType $breed) {
        [$parent, , $pet] = pnmFamily(['breed_type' => $breed->value]);
        $pet = Pet::whereKey($pet->id)->firstOrFail();
        $pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet, 42)])->saveQuietly();
        $prompts = app(PetAppearancePrompt::class);
        $render = fn (Pet $p): array => [
            $prompts->imagePromptForPet($p),
            $prompts->negativePrompt($p->speciesValue()),
            $prompts->videoNegativePrompt($p->speciesValue()),
        ];

        $before = $render($pet->fresh());
        actingAsRole($parent);
        pnmPatch($pet, 'Zvezdica')->assertOk();
        $after = $render($pet->fresh());

        expect($after)->toBe($before);
        foreach ($after as $prompt) {
            expect(mb_strtolower($prompt))->not->toContain('zvezdica');
        }
    })->with([BreedType::Mutt, BreedType::DomesticCat]);

    it('sends the same push text, title and data for a named pet', function (Species $species) {
        config(['push.enabled' => true]);
        $breed = $species === Species::Cat ? BreedType::DomesticCat : BreedType::Mutt;
        // Two identical families; only the second pet has a name.
        $make = function (?string $name) use ($breed): Pet {
            [, $child, $pet] = pnmFamily(['breed_type' => $breed->value, 'hunger_level' => 25]);
            withoutQuietHours($pet);
            if ($name !== null) {
                $pet->forceFill(['name' => $name])->saveQuietly();
            }
            DevicePushToken::create([
                'user_id' => $child->id,
                'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
                'platform' => 'ios',
                'app_version' => '1.0.0',
                'locale' => 'sl',
                'last_seen_at' => now(),
            ]);

            return $pet->fresh();
        };
        $plain = $make(null);
        $named = $make('Zvezdica');

        $sent = new ArrayObject;
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['https://exp.host/--/api/v2/push/send' => function (HttpRequest $request) use ($sent) {
            $tickets = [];
            foreach ($request->data() as $message) {
                $sent->append($message);
                $tickets[] = ['status' => 'ok', 'id' => 'ticket-'.Str::random(10)];
            }

            return Http::response(['data' => $tickets]);
        }]);
        Queue::fake([SendPushNotification::class]);

        $deliver = function (Pet $p) use ($sent): array {
            $before = count($sent);
            $row = app(NotificationService::class)->escalation($p, PushType::SoftWarning, 'hunger');
            (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));
            expect($sent)->toHaveCount($before + 1);
            $message = $sent[$before];
            $data = $message['data'] ?? [];
            expect($data['pet_id'] ?? null)->toBe($p->id);
            unset($data['pet_id']);

            return ['title' => $message['title'] ?? null, 'body' => $message['body'], 'data' => $data];
        };

        $unnamedPush = $deliver($plain);
        $namedPush = $deliver($named);

        expect($namedPush)->toBe($unnamedPush)
            ->and($namedPush['body'])->not->toBe('')
            ->and(json_encode($namedPush, JSON_UNESCAPED_UNICODE))->not->toContain('Zvezdica');
    })->with([Species::Dog, Species::Cat]);
});
