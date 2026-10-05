<?php

use App\Events\PetUpdated;
use App\Exceptions\AccountDeletionException;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Jobs\DeletePetMediaFiles;
use App\Jobs\GeneratePetReferenceImage;
use App\Jobs\StorePetMedia;
use App\Jobs\SubmitPetStateVideo;
use App\Models\ActivityLog;
use App\Models\AiSpendLedger;
use App\Models\ChildLoginPin;
use App\Models\Family;
use App\Models\FamilyInvite;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetContract;
use App\Models\PetDailyStep;
use App\Models\PetMedia;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\FalWebhookVerifier;
use App\Services\FamilyInviteService;
use App\Services\FamilyService;
use App\Services\Media\PetMediaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M2-08 — account deletion + data export
|--------------------------------------------------------------------------
| Last parent → whole family; another parent remains → only that parent;
| child profile (sole / shared caretaker); export content + secrets;
| throttles (live in testing); files deleted after commit; late fal work
| after a deletion; superadmin protection; family isolation; Filament.
*/

const AD_SVG = 'M10 10 L20 20 L30 15';

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    Cache::forget(FalWebhookVerifier::CACHE_KEY);
    config(['app.url' => 'https://api.petprep.si']);
    seedBreedConfigs();
});

/**
 * Parent + PIN-only child + born pet with a stored reference image, a
 * contract (signature), activities, a step row, quiet hours, an open invite,
 * an open child PIN, tokens for both and an AI ledger row.
 *
 * @return array{parent: User, child: User, pet: Pet, family: Family, image: PetMedia, ledger: AiSpendLedger, invite: FamilyInvite}
 */
function adFamily(string $email = 'mama@example.com'): array
{
    $parent = User::factory()->parent()->create(['email' => $email, 'name' => 'Mama Ana']);
    $child = User::factory()->pinOnlyChild()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = Pet::factory()->withPetDna(['reference_image_url' => 'https://v3.fal.media/files/secret-ref.jpg'])
        ->create(['user_id' => $child->id]);
    $family = app(FamilyService::class)->familyOf($parent);

    $image = adStoredImage($pet);
    $ledger = AiSpendLedger::create([
        'purpose' => 'reference_image', 'profile' => 'nano_banana_pro', 'endpoint' => 'fal-ai/x', 'unit' => 'image',
        'units' => 1, 'cost_usd' => 0.15, 'status' => 'committed', 'request_id' => 'req-ref-'.$pet->id,
        'pet_id' => $pet->id, 'pet_media_id' => $image->id,
    ]);

    PetContract::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'signature_format' => 'svg_path', 'signature' => AD_SVG, 'signed_at' => now()]);
    ActivityLog::create(['pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => 'fed_pet', 'value' => 40]);
    ActivityLog::create(['pet_id' => $pet->id, 'actor_user_id' => null, 'activity_type' => 'ignored_warning', 'value' => 30]);
    PetDailyStep::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'local_date' => now()->toDateString(), 'steps' => 1200]);
    QuietHours::create(['parent_id' => $parent->id, 'school_start' => '08:00', 'school_end' => '14:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true]);
    $invite = FamilyInvite::create(['family_id' => $family->id, 'created_by' => $parent->id, 'code' => strtoupper(Str::random(8)), 'expires_at' => now()->addDay()]);
    ChildLoginPin::create(['family_id' => $family->id, 'child_user_id' => $child->id, 'created_by' => $parent->id, 'pin_hash' => hash_hmac('sha256', '123456', 'k'.$child->id), 'expires_at' => now()->addMinutes(15)]);
    $parent->createToken('iPhone', ['parent']);
    $child->createToken('iPad', ['child']);

    return compact('parent', 'child', 'pet', 'family', 'image', 'ledger', 'invite');
}

function adStoredImage(Pet $pet): PetMedia
{
    $path = "{$pet->id}/reference-g1.jpg";
    Storage::disk('pet_media')->put($path, "\xFF\xD8\xFF\xE0".str_repeat("\x00", 64));

    return PetMedia::create([
        'pet_id' => $pet->id, 'kind' => 'image', 'state' => null, 'status' => 'ready', 'generation' => 1,
        'storage_path' => $path, 'mime' => 'image/jpeg', 'bytes' => 68, 'profile' => 'nano_banana_pro',
    ]);
}

/** A second parent who joined $parent's family with an invite code. */
function adSecondParent(User $parent, string $email = 'oce@example.com'): User
{
    $second = User::factory()->parent()->create(['email' => $email, 'name' => 'Oče Bor']);
    $code = app(FamilyInviteService::class)->createInvite($parent)['code'];
    app(FamilyInviteService::class)->joinFamily($second, $code);

    return $second->refresh();
}

/** A second child of $parent's family that shares $pet (with its own contract). */
function adSharedChild(User $parent, Pet $pet, string $name = 'Luka'): User
{
    $child = User::factory()->pinOnlyChild()->create(['parent_id' => $parent->id, 'name' => $name]);
    app(FamilyService::class)->addCaretaker($pet, $child);
    PetContract::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2', 'signed_at' => now()]);

    return $child;
}

function adAs(User $user, string $ip = '198.51.100.80'): void
{
    app('auth')->forgetGuards();
    test()->withServerVariables(['REMOTE_ADDR' => $ip]);
    actingAsRole($user);
}

function adDeleteAccount(User $parent, array $body = []): TestResponse
{
    adAs($parent);

    return postJson('/api/parent/account/delete', array_merge(['password' => 'password', 'confirm' => true], $body));
}

function adDeleteChild(User $parent, int|string $childId, array $body = []): TestResponse
{
    adAs($parent);

    return deleteJson("/api/parent/children/{$childId}", array_merge(['password' => 'password', 'confirm' => true], $body));
}

function adTokens(User $user): int
{
    return PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $user->id)->count();
}

/* ─────────────────────────── Parent account: last parent ─────────────────────────── */

describe('POST /api/parent/account/delete — last parent', function () {
    it('deletes the whole family: children, pets, media files, contracts, logs, tokens, PINs, invites, quiet hours', function () {
        $f = adFamily();
        $familyId = $f['family']->id;
        $petId = $f['pet']->id;

        adDeleteAccount($f['parent'])->assertOk()->assertExactJson([
            'status' => 'deleted', 'scope' => 'family', 'family_deleted' => true,
            'parents_deleted' => 1, 'children_deleted' => 1, 'pets_deleted' => 1,
        ]);

        expect(User::whereIn('id', [$f['parent']->id, $f['child']->id])->count())->toBe(0)
            ->and(Family::find($familyId))->toBeNull()
            ->and(FamilyMember::where('family_id', $familyId)->count())->toBe(0)
            ->and(Pet::find($petId))->toBeNull()
            ->and(PetMedia::where('pet_id', $petId)->count())->toBe(0)
            ->and(PetContract::where('pet_id', $petId)->count())->toBe(0)
            ->and(PetCaretaker::where('pet_id', $petId)->count())->toBe(0)
            ->and(ActivityLog::where('pet_id', $petId)->count())->toBe(0)
            ->and(PetDailyStep::where('pet_id', $petId)->count())->toBe(0)
            ->and(QuietHours::where('family_id', $familyId)->count())->toBe(0)
            ->and(FamilyInvite::where('family_id', $familyId)->count())->toBe(0)
            ->and(ChildLoginPin::where('family_id', $familyId)->count())->toBe(0)
            ->and(adTokens($f['parent']) + adTokens($f['child']))->toBe(0)
            // Files went after commit (sync queue in tests).
            ->and(Storage::disk('pet_media')->exists($f['image']->storage_path))->toBeFalse()
            ->and(Storage::disk('pet_media')->directories())->not->toContain((string) $petId);

        // Accounting keeps the cost, without the pet.
        $ledger = $f['ledger']->fresh();
        expect($ledger)->not->toBeNull()
            ->and($ledger->pet_id)->toBeNull()
            ->and($ledger->pet_media_id)->toBeNull()
            ->and((float) $ledger->cost_usd)->toBe(0.15);
    });

    it('signs every device out: the old token is rejected afterwards', function () {
        $f = adFamily();
        $plain = $f['parent']->createToken('Pixel', ['parent'])->plainTextToken;

        adDeleteAccount($f['parent'])->assertOk();

        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => 'Bearer '.$plain]);
        getJson('/api/user')->assertUnauthorized();
    });

    it('leaves other families untouched (isolation)', function () {
        $mine = adFamily('mine@example.com');
        $other = adFamily('other@example.com');

        adDeleteAccount($mine['parent'])->assertOk();

        expect(User::find($other['parent']->id))->not->toBeNull()
            ->and(User::find($other['child']->id))->not->toBeNull()
            ->and(Pet::find($other['pet']->id))->not->toBeNull()
            ->and(PetContract::where('pet_id', $other['pet']->id)->count())->toBe(1)
            ->and(Storage::disk('pet_media')->exists($other['image']->storage_path))->toBeTrue()
            ->and(adTokens($other['parent']))->toBe(1)
            ->and(Family::find($other['family']->id))->not->toBeNull();
    });

    it('writes one audit line without personal data', function () {
        Log::spy();
        $f = adFamily();

        adDeleteAccount($f['parent'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($f): bool {
            $json = json_encode($context);

            return str_contains($message, 'family_deleted')
                && $context['family_id'] === $f['family']->id
                && $context['initiated_by'] === 'self'
                && $context['children'] === 1 && $context['pets'] === 1
                && ! str_contains($json, 'mama@example.com')
                && ! str_contains($json, 'Maja')
                && ! str_contains($json, 'Mama Ana');
        })->once();
    });

    it('deletes a parent who has no family yet (only the account)', function () {
        $parent = User::factory()->parent()->create();
        FamilyMember::where('user_id', $parent->id)->delete();

        adDeleteAccount($parent)->assertOk()->assertJson(['scope' => 'parent', 'family_deleted' => false]);
        expect(User::find($parent->id))->toBeNull();
    });
});

/* ─────────────────────────── Parent account: another parent remains ─────────────────────────── */

describe('POST /api/parent/account/delete — another parent remains', function () {
    it('removes only this parent; family, children, pets and quiet hours stay with the other parent', function () {
        $f = adFamily();
        $second = adSecondParent($f['parent']);
        $second->createToken('Phone', ['parent']);

        adDeleteAccount($f['parent'])->assertOk()->assertExactJson([
            'status' => 'deleted', 'scope' => 'parent', 'family_deleted' => false,
            'parents_deleted' => 1, 'children_deleted' => 0, 'pets_deleted' => 0,
        ]);

        expect(User::find($f['parent']->id))->toBeNull()
            ->and(adTokens($f['parent']))->toBe(0)
            ->and(Family::find($f['family']->id))->not->toBeNull()
            ->and(User::find($f['child']->id))->not->toBeNull()
            ->and(Pet::find($f['pet']->id))->not->toBeNull()
            ->and(Storage::disk('pet_media')->exists($f['image']->storage_path))->toBeTrue()
            ->and(adTokens($f['child']))->toBe(1)
            ->and(adTokens($second))->toBe(1)
            // Cascading deprecated mirrors were handed over, not deleted.
            ->and($f['child']->fresh()->parent_id)->toBe($second->id)
            ->and(QuietHours::where('family_id', $f['family']->id)->value('parent_id'))->toBe($second->id)
            // The leaving parent's invite codes are gone.
            ->and(FamilyInvite::where('created_by', $f['parent']->id)->count())->toBe(0)
            // The child PIN the leaving parent issued stays usable for the family.
            ->and(ChildLoginPin::where('child_user_id', $f['child']->id)->value('created_by'))->toBeNull();

        // The remaining parent still sees the whole family.
        adAs($second);
        getJson('/api/parent/dashboard')->assertOk()
            ->assertJsonPath('family.id', $f['family']->id)
            ->assertJsonCount(1, 'family.parents')
            ->assertJsonCount(1, 'family.children')
            ->assertJsonCount(1, 'family.pets');
    });

    it('when both parents leave one after the other, the second one takes the family', function () {
        $f = adFamily();
        $second = adSecondParent($f['parent']);

        adDeleteAccount($second)->assertOk()->assertJson(['scope' => 'parent']);
        expect($f['child']->fresh()->parent_id)->toBe($f['parent']->id); // unchanged

        adDeleteAccount($f['parent'])->assertOk()->assertJson(['scope' => 'family', 'children_deleted' => 1, 'pets_deleted' => 1]);
        expect(Family::find($f['family']->id))->toBeNull()
            ->and(User::find($f['child']->id))->toBeNull();
    });
});

/* ─────────────────────────── Parent account: refusals ─────────────────────────── */

describe('POST /api/parent/account/delete — refusals', function () {
    it('refuses a wrong password and deletes nothing', function () {
        $f = adFamily();

        adDeleteAccount($f['parent'], ['password' => 'wrong-password'])
            ->assertStatus(422)->assertJson(['reason' => 'invalid_password']);

        expect(User::find($f['parent']->id))->not->toBeNull()
            ->and(Pet::find($f['pet']->id))->not->toBeNull();
    });

    it('requires the password and an explicit confirm', function () {
        $f = adFamily();

        adDeleteAccount($f['parent'], ['password' => null])->assertStatus(422)->assertJsonValidationErrors('password');
        adDeleteAccount($f['parent'], ['confirm' => false])->assertStatus(422)->assertJsonValidationErrors('confirm');
        expect(User::find($f['parent']->id))->not->toBeNull();
    });

    it('protects a superadmin account', function () {
        $admin = User::factory()->parent()->create(['is_superadmin' => true]);

        adDeleteAccount($admin)->assertForbidden()->assertJson(['reason' => 'superadmin_protected']);
        expect(User::find($admin->id))->not->toBeNull();

        expect(fn () => app(AccountDeletionService::class)->deleteParentAccount($admin))
            ->toThrow(AccountDeletionException::class);
    });

    it('is a parent route: a child token gets 403', function () {
        $f = adFamily();

        adAs($f['child']);
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])->assertForbidden();
        expect(User::find($f['child']->id))->not->toBeNull();
    });

    it('a legacy * child token is stopped by the policy', function () {
        $child = User::factory()->child()->create();

        app('auth')->forgetGuards();
        test()->actingAsWithAbilities($child, ['*']);
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])->assertForbidden();
        expect(User::find($child->id))->not->toBeNull();
    });

    it('throttles attempts: 5 per 15 minutes per user', function () {
        $f = adFamily();

        foreach (range(1, 5) as $i) {
            adDeleteAccount($f['parent'], ['password' => 'wrong-'.$i])->assertStatus(422);
        }
        adDeleteAccount($f['parent'])->assertStatus(429)->assertHeader('Retry-After');
        expect(User::find($f['parent']->id))->not->toBeNull();

        // Another parent is not affected.
        $other = adFamily('other@example.com');
        adDeleteAccount($other['parent'], ['password' => 'wrong'])->assertStatus(422);

        $this->travel(16)->minutes();
        adDeleteAccount($f['parent'])->assertOk();
    });
});

/* ─────────────────────────── Child profile ─────────────────────────── */

describe('DELETE /api/parent/children/{child}', function () {
    it('deletes the child and the pet they cared for alone, with its media, contract and history', function () {
        $f = adFamily();
        $petId = $f['pet']->id;

        adDeleteChild($f['parent'], $f['child']->id)->assertOk()
            ->assertExactJson(['status' => 'deleted', 'child_id' => $f['child']->id, 'pets_deleted' => 1, 'pets_kept' => 0]);

        expect(User::find($f['child']->id))->toBeNull()
            ->and(adTokens($f['child']))->toBe(0)
            ->and(ChildLoginPin::where('child_user_id', $f['child']->id)->count())->toBe(0)
            ->and(FamilyMember::where('user_id', $f['child']->id)->count())->toBe(0)
            ->and(Pet::find($petId))->toBeNull()
            ->and(PetContract::where('pet_id', $petId)->count())->toBe(0)
            ->and(ActivityLog::where('pet_id', $petId)->count())->toBe(0)
            ->and(Storage::disk('pet_media')->exists($f['image']->storage_path))->toBeFalse()
            ->and($f['ledger']->fresh()->pet_id)->toBeNull()
            // The parent and the family stay.
            ->and(User::find($f['parent']->id))->not->toBeNull()
            ->and(Family::find($f['family']->id))->not->toBeNull()
            ->and(QuietHours::where('family_id', $f['family']->id)->count())->toBe(1)
            ->and(adTokens($f['parent']))->toBe(1);
    });

    it('keeps a shared pet: the other caretaker keeps it, the activities stay without the actor', function () {
        Event::fake([PetUpdated::class]);
        $f = adFamily();
        $luka = adSharedChild($f['parent'], $f['pet']);
        ActivityLog::create(['pet_id' => $f['pet']->id, 'actor_user_id' => $luka->id, 'activity_type' => 'watered_pet', 'value' => 50]);
        $before = ActivityLog::where('pet_id', $f['pet']->id)->count();

        // Maja is the primary caretaker (pets.user_id, ON DELETE CASCADE).
        adDeleteChild($f['parent'], $f['child']->id)->assertOk()
            ->assertJson(['pets_deleted' => 0, 'pets_kept' => 1]);

        $pet = Pet::find($f['pet']->id);
        expect($pet)->not->toBeNull()
            ->and($pet->user_id)->toBe($luka->id)
            ->and($pet->caretakers()->pluck('users.id')->all())->toBe([$luka->id])
            ->and(ActivityLog::where('pet_id', $pet->id)->count())->toBe($before)
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'fed_pet')->value('actor_user_id'))->toBeNull()
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'watered_pet')->value('actor_user_id'))->toBe($luka->id)
            ->and(PetContract::where('pet_id', $pet->id)->pluck('user_id')->all())->toBe([$luka->id])
            ->and(PetDailyStep::where('pet_id', $pet->id)->where('user_id', $f['child']->id)->count())->toBe(0)
            ->and(Storage::disk('pet_media')->exists($f['image']->storage_path))->toBeTrue();

        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'caretaker_removed');

        // Luka keeps playing.
        adAs($luka);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('pet.id', $pet->id)->assertJsonPath('pet.caretakers_count', 1);
    });

    it('deleting the non-primary caretaker of a shared pet leaves pets.user_id alone', function () {
        $f = adFamily();
        $luka = adSharedChild($f['parent'], $f['pet']);

        adDeleteChild($f['parent'], $luka->id)->assertOk()->assertJson(['pets_kept' => 1]);

        expect($f['pet']->fresh()->user_id)->toBe($f['child']->id)
            ->and(User::find($luka->id))->toBeNull();
    });

    it('any parent of the family may delete the child', function () {
        $f = adFamily();
        $second = adSecondParent($f['parent']);

        adDeleteChild($second, $f['child']->id)->assertOk();
        expect(User::find($f['child']->id))->toBeNull();
    });

    it('is family scoped: another family\'s child, a parent id, a missing or non-numeric id → 404', function () {
        $f = adFamily();
        $other = adFamily('other@example.com');
        $second = adSecondParent($f['parent']);

        adDeleteChild($f['parent'], $other['child']->id)->assertNotFound()->assertJson(['reason' => 'child_not_found']);
        adDeleteChild($f['parent'], $second->id)->assertNotFound();
        adDeleteChild($f['parent'], 999999)->assertNotFound();
        adDeleteChild($f['parent'], 'abc')->assertNotFound();

        expect(User::find($other['child']->id))->not->toBeNull()
            ->and(Pet::find($other['pet']->id))->not->toBeNull();
    });

    it('refuses a wrong password and a missing confirm', function () {
        $f = adFamily();

        adDeleteChild($f['parent'], $f['child']->id, ['password' => 'nope'])->assertStatus(422)->assertJson(['reason' => 'invalid_password']);
        adDeleteChild($f['parent'], $f['child']->id, ['confirm' => null])->assertStatus(422)->assertJsonValidationErrors('confirm');
        expect(User::find($f['child']->id))->not->toBeNull();
    });

    it('a child cannot delete anybody', function () {
        $f = adFamily();
        $luka = adSharedChild($f['parent'], $f['pet']);

        adAs($luka);
        deleteJson("/api/parent/children/{$f['child']->id}", ['password' => 'password', 'confirm' => true])->assertForbidden();
        expect(User::find($f['child']->id))->not->toBeNull();
    });
});

/* ─────────────────────────── Files after commit, late work ─────────────────────────── */

describe('after the deletion', function () {
    it('deletes the files only after commit — a rolled-back deletion keeps them', function () {
        $f = adFamily();

        try {
            DB::transaction(function () use ($f) {
                app(AccountDeletionService::class)->deleteParentAccount($f['parent']);
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        // Sync queue: an after-commit job of a rolled-back transaction never runs.
        expect(Pet::find($f['pet']->id))->not->toBeNull()
            ->and(Storage::disk('pet_media')->exists($f['image']->storage_path))->toBeTrue();
    });

    it('queues one file job for the deleted pets; files stay until it runs', function () {
        Queue::fake([DeletePetMediaFiles::class]);
        $f = adFamily();

        app(AccountDeletionService::class)->deleteParentAccount($f['parent']);

        Queue::assertPushed(DeletePetMediaFiles::class, fn (DeletePetMediaFiles $job) => $job->petIds === [$f['pet']->id]);
        expect(Storage::disk('pet_media')->exists($f['image']->storage_path))->toBeTrue();
    });

    it('the file job is idempotent and never touches a pet that still exists', function () {
        $gone = adFamily('gone@example.com');
        $alive = adFamily('alive@example.com');
        AiSpendLedger::detachPets([$gone['pet']->id]);
        Pet::whereKey($gone['pet']->id)->delete(); // query builder: no hook

        $job = new DeletePetMediaFiles([$gone['pet']->id, $alive['pet']->id]);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        expect(Storage::disk('pet_media')->exists($gone['image']->storage_path))->toBeFalse()
            ->and(Storage::disk('pet_media')->exists($alive['image']->storage_path))->toBeTrue();
    });

    it('a single pet delete (Filament) keeps its spend rows — regression: the two SET NULL FKs collided', function () {
        $f = adFamily();

        $f['pet']->delete();

        expect(Pet::find($f['pet']->id))->toBeNull()
            ->and($f['ledger']->fresh())->pet_id->toBeNull()->pet_media_id->toBeNull()
            ->and((float) $f['ledger']->fresh()->cost_usd)->toBe(0.15)
            ->and(Storage::disk('pet_media')->exists($f['image']->storage_path))->toBeFalse();
    });

    it('acknowledges a late fal webhook for a deleted pet (200, nothing recreated)', function () {
        fakeFalJwks();
        $f = adFamily();
        $slot = PetMedia::create(['pet_id' => $f['pet']->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'running', 'request_id' => 'req-late', 'profile' => 'kling_v3_pro']);
        AiSpendLedger::create(['purpose' => 'state_video', 'profile' => 'kling_v3_pro', 'endpoint' => 'x/y', 'unit' => 'second', 'units' => 5, 'cost_usd' => 0.56, 'status' => 'committed', 'request_id' => 'req-late', 'pet_id' => $f['pet']->id, 'pet_media_id' => $slot->id]);

        adDeleteAccount($f['parent'])->assertOk();

        sendFalWebhook(['request_id' => 'req-late', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/late.mp4']]])
            ->assertOk()->assertJson(['message' => 'Superseded.']);

        expect(PetMedia::count())->toBe(0)
            ->and(Storage::disk('pet_media')->allFiles())->toBe([]);
    });

    it('a late fal webhook without any ledger row is a 404, never a crash', function () {
        fakeFalJwks();

        sendFalWebhook(['request_id' => 'req-unknown', 'status' => 'OK', 'payload' => ['video' => ['url' => 'https://v3.fal.media/files/x.mp4']]])
            ->assertNotFound();
    });

    it('queued media jobs of a deleted pet do nothing (no fal call, no file)', function () {
        $f = adFamily();
        $video = PetMedia::create(['pet_id' => $f['pet']->id, 'kind' => 'video', 'state' => 'sleeping', 'status' => 'pending', 'profile' => 'kling_v3_pro']);
        $videoId = $video->id;

        adDeleteAccount($f['parent'])->assertOk();

        (new SubmitPetStateVideo($videoId))->handle(app(PetMediaService::class));
        (new StorePetMedia($videoId))->handle(app(PetMediaService::class));
        (new GeneratePetReferenceImage($f['pet']->id))->handle(app(PetMediaService::class));

        Http::assertNothingSent();
        expect(Storage::disk('pet_media')->allFiles())->toBe([]);
    });

    it('the game-loop tick runs cleanly after a deletion', function () {
        $f = adFamily();
        $other = adFamily('other@example.com');

        adDeleteAccount($f['parent'])->assertOk();

        $this->artisan('pets:process-decay')->assertSuccessful();
        expect(Pet::find($other['pet']->id))->not->toBeNull();
    });
});

/* ─────────────────────────── Export ─────────────────────────── */

describe('GET /api/parent/account/export', function () {
    it('exports the family as a JSON download with history, contracts (signature), scores and signed media links', function () {
        $f = adFamily();
        $second = adSecondParent($f['parent']);

        adAs($f['parent']);
        $response = getJson('/api/parent/account/export')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('format', 'petprep.family-export')
            ->assertJsonPath('version', 1)
            ->assertJsonPath('requested_by.id', $f['parent']->id)
            ->assertJsonPath('family.id', $f['family']->id)
            ->assertJsonCount(2, 'parents')
            ->assertJsonPath('parents.0.email', 'mama@example.com')
            ->assertJsonPath('children.0.nickname', 'Maja')
            ->assertJsonPath('children.0.login', 'pin')
            ->assertJsonPath('children.0.devices', 1)
            ->assertJsonPath('quiet_hours.is_active', true)
            ->assertJsonPath('pets.0.id', $f['pet']->id)
            ->assertJsonPath('pets.0.contracts.0.child_id', $f['child']->id)
            ->assertJsonPath('pets.0.contracts.0.signature_format', 'svg_path')
            ->assertJsonPath('pets.0.contracts.0.signature', AD_SVG)
            ->assertJsonCount(2, 'pets.0.activities')
            ->assertJsonPath('pets.0.daily_steps.0.steps', 1200)
            ->assertJsonPath('pets.0.media.0.kind', 'image')
            ->assertJsonPath('scores.pets.0.pet_id', $f['pet']->id)
            ->assertJsonPath('scores.children.0.child_id', $f['child']->id);

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename="petprep-izvoz-')
            ->and($response->json('pets.0.media.0.url'))->toStartWith('https://api.petprep.si/api/media/')
            ->and($response->json('pets.0.media.0.url'))->toContain('signature=')
            ->and($response->json('parents.1.id'))->toBe($second->id);
    });

    it('never contains secrets: password hashes, tokens, PIN hashes, invite codes, fal URLs / request ids', function () {
        $f = adFamily();
        $plain = $f['parent']->createToken('Laptop', ['parent'])->plainTextToken;
        [, $secretPart] = explode('|', $plain, 2);
        $f['parent']->forceFill(['remember_token' => 'remember-me-secret', 'revenuecat_id' => 'rc-secret-id'])->saveQuietly();

        adAs($f['parent']);
        $raw = getJson('/api/parent/account/export')->assertOk()->getContent();

        $hash = (string) DB::table('users')->where('id', $f['parent']->id)->value('password');
        $pinHash = (string) ChildLoginPin::where('child_user_id', $f['child']->id)->value('pin_hash');

        expect($raw)->not->toContain($hash)
            ->and($raw)->not->toContain('"password"')
            ->and($raw)->not->toContain($secretPart)
            ->and($raw)->not->toContain(hash('sha256', $secretPart))
            ->and($raw)->not->toContain('remember-me-secret')
            ->and($raw)->not->toContain('rc-secret-id')
            ->and($raw)->not->toContain($pinHash)
            ->and($raw)->not->toContain($f['invite']->code)
            ->and($raw)->not->toContain('secret-ref.jpg')
            ->and($raw)->not->toContain('fal.media')
            ->and($raw)->not->toContain('req-ref-');
    });

    it('a parent without a family gets their own account only (GET creates no family)', function () {
        $parent = User::factory()->parent()->create(['email' => 'alone@example.com']);
        FamilyMember::where('user_id', $parent->id)->delete();
        $families = Family::count();

        adAs($parent);
        getJson('/api/parent/account/export')->assertOk()
            ->assertJsonPath('family', null)
            ->assertJsonPath('parents.0.email', 'alone@example.com')
            ->assertJsonPath('pets', []);
        expect(Family::count())->toBe($families);
    });

    it('is family scoped: another family\'s data never appears', function () {
        $mine = adFamily('mine@example.com');
        $other = adFamily('other@example.com');

        adAs($mine['parent']);
        $raw = getJson('/api/parent/account/export')->assertOk()->getContent();

        expect($raw)->not->toContain('other@example.com')
            ->and(collect(json_decode($raw, true)['pets'])->pluck('id')->all())->toBe([$mine['pet']->id]);
    });

    it('answers 413 export_too_large above the row limit', function () {
        config(['privacy.export_max_rows' => 1]);
        $f = adFamily();

        adAs($f['parent']);
        getJson('/api/parent/account/export')->assertStatus(413)->assertJson(['reason' => 'export_too_large']);
    });

    it('throttles: 3 exports per hour per user', function () {
        $f = adFamily();
        $other = adFamily('other@example.com');

        foreach (range(1, 3) as $i) {
            adAs($f['parent']);
            getJson('/api/parent/account/export')->assertOk();
        }
        adAs($f['parent']);
        getJson('/api/parent/account/export')->assertStatus(429)->assertHeader('Retry-After');

        adAs($other['parent']);
        getJson('/api/parent/account/export')->assertOk();
    });

    it('is a parent route: a child gets 403, a guest 401', function () {
        $f = adFamily();

        adAs($f['child']);
        getJson('/api/parent/account/export')->assertForbidden();

        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => '']);
        getJson('/api/parent/account/export')->assertUnauthorized();
    });
});

/* ─────────────────────────── Filament ─────────────────────────── */

describe('Filament: delete family (superadmin)', function () {
    beforeEach(function () {
        $this->admin = User::factory()->parent()->create(['is_superadmin' => true, 'email' => 'admin@example.com']);
        $this->actingAs($this->admin);
    });

    it('deletes the whole family through the same service', function () {
        Log::spy();
        $f = adFamily();
        $second = adSecondParent($f['parent']);

        Livewire::test(ListUsers::class)
            ->callTableAction('deleteFamily', $f['child'])
            ->assertHasNoTableActionErrors();

        expect(Family::find($f['family']->id))->toBeNull()
            ->and(User::whereIn('id', [$f['parent']->id, $second->id, $f['child']->id])->count())->toBe(0)
            ->and(Pet::find($f['pet']->id))->toBeNull()
            ->and(Storage::disk('pet_media')->exists($f['image']->storage_path))->toBeFalse()
            ->and(User::find($this->admin->id))->not->toBeNull();

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => str_contains($m, 'family_deleted') && $c['initiated_by'] === 'admin' && $c['parents'] === 2)->once();
    });

    it('offers it on the edit page too, and no plain user delete anywhere', function () {
        $f = adFamily();

        Livewire::test(EditUser::class, ['record' => $f['parent']->getRouteKey()])
            ->assertActionVisible('deleteFamily')
            ->assertActionDoesNotExist('delete')
            ->callAction('deleteFamily');

        expect(Family::find($f['family']->id))->toBeNull();

        Livewire::test(ListUsers::class)->assertTableBulkActionDoesNotExist('delete');
    });

    it('never deletes a family with a superadmin member', function () {
        $f = adFamily();
        $f['parent']->forceFill(['is_superadmin' => true])->saveQuietly();

        expect(UserResource::deletableFamilyOf($f['child']))->toBeNull();
        Livewire::test(ListUsers::class)->assertTableActionHidden('deleteFamily', $f['child']);
        expect(fn () => app(AccountDeletionService::class)->deleteFamily($f['family']))
            ->toThrow(AccountDeletionException::class);

        expect(Family::find($f['family']->id))->not->toBeNull()
            ->and(User::find($f['child']->id))->not->toBeNull();
    });
});
