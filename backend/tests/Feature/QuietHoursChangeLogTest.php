<?php

use App\Enums\QuietHoursChangeScope;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Family;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\QuietHoursChange;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\FamilyInviteService;
use App\Services\FamilyService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

/*
|--------------------------------------------------------------------------
| Quiet hours change log (David 2026-11-24, »ja naredi«)
|--------------------------------------------------------------------------
|
| A tester family's quiet hours were overwritten 16 s after sign-up by a PUT
| with every time null; request logs were gone. Every parent save that really
| changes a schedule (family row or a pet's own hours) leaves one
| `quiet_hours_changes` row; superadmins see it read-only in /admin; it is in
| the export and goes with the family.
*/

const QHC_UA = 'PetPrep/4.1.6 (iPhone; iOS 18.1) Expo';
const QHC_FEATURES = 'behaviour_events,training,care_tasks';
const QHC_SCHOOL = ['school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true];

/**
 * @return array{parent: User, child: User, pet: Pet, family: Family}
 */
function qhcFamily(): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id, 'last_decay_at' => now()]));

    return [
        'parent' => $parent,
        'child' => $child,
        'pet' => $pet->fresh(),
        'family' => app(FamilyService::class)->familyOf($parent),
    ];
}

/** @param array<string, mixed> $body */
function qhcPutFamily(User $parent, array $body): TestResponse
{
    actingAs($parent, 'sanctum');

    return putJson('/api/parent/quiet-hours', $body, ['User-Agent' => QHC_UA, 'X-PetPrep-Features' => QHC_FEATURES]);
}

/** @param array<string, mixed>|null $quietHours */
function qhcPutPet(User $parent, Pet $pet, ?array $quietHours): TestResponse
{
    actingAs($parent, 'sanctum');

    return putJson("/api/parent/pets/{$pet->id}/quiet-hours", ['quiet_hours' => $quietHours], ['User-Agent' => QHC_UA]);
}

beforeEach(function () {
    seedBreedConfigs();
    Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('a family save that changes the schedule writes one row with before, after, the parent and the app headers', function () {
    $f = qhcFamily();
    // The default row created with the family is not logged.
    expect(QuietHoursChange::count())->toBe(0);

    qhcPutFamily($f['parent'], QHC_SCHOOL)->assertOk();

    $rows = QuietHoursChange::all();
    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row->family_id)->toBe($f['family']->id)
        ->and($row->pet_id)->toBeNull()
        ->and($row->user_id)->toBe($f['parent']->id)
        ->and($row->scope)->toBe(QuietHoursChangeScope::Family)
        // jsonb does not keep key order → compare as maps.
        ->and($row->before)->toEqual(QuietHours::normaliseValues(QuietHours::DEFAULTS))
        ->and($row->after)->toEqual([
            'school_start' => '08:00', 'school_end' => '13:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00',
            'is_active' => true, 'school_days' => [1, 2, 3, 4, 5],
        ])
        ->and($row->user_agent)->toBe(QHC_UA)
        ->and($row->client_features)->toBe(QHC_FEATURES)
        ->and($row->created_at->utc()->toIso8601String())->toBe('2026-11-24T10:00:00+00:00');

    // The incident's shape: everything null, still active → logged with the null times.
    qhcPutFamily($f['parent'], ['school_start' => null, 'school_end' => null, 'bedtime_start' => null, 'bedtime_end' => null, 'is_active' => true])->assertOk();
    $last = QuietHoursChange::latest('id')->first();
    expect(QuietHoursChange::count())->toBe(2)
        ->and($last->before['school_start'])->toBe('08:00')
        ->and($last->after['bedtime_start'])->toBeNull()
        ->and($last->after['is_active'])->toBeTrue();
});

it('the same values again, or a partial save that keeps them, write no row', function () {
    $f = qhcFamily();
    qhcPutFamily($f['parent'], QHC_SCHOOL)->assertOk();
    qhcPutFamily($f['parent'], QHC_SCHOOL)->assertOk();
    qhcPutFamily($f['parent'], ['school_start' => '08:00', 'school_days' => [1, 2, 3, 4, 5]])->assertOk();

    expect(QuietHoursChange::count())->toBe(1);
});

it('a brand-new family row is logged with before null; a long User-Agent is cut to 255', function () {
    $f = qhcFamily();
    QuietHours::where('family_id', $f['family']->id)->delete();

    actingAs($f['parent'], 'sanctum');
    putJson('/api/parent/quiet-hours', QHC_SCHOOL, ['User-Agent' => str_repeat('a', 400)])->assertOk();

    $row = QuietHoursChange::sole();
    expect($row->before)->toBeNull()
        ->and($row->after['school_end'])->toBe('13:00')
        ->and(strlen($row->user_agent))->toBe(255)
        ->and($row->client_features)->toBeNull();
});

it('a pet\'s own hours write a pet row; same values none; back to the family writes after null', function () {
    $f = qhcFamily();

    qhcPutPet($f['parent'], $f['pet'], QHC_SCHOOL)->assertOk();
    $row = QuietHoursChange::sole();
    expect($row->scope)->toBe(QuietHoursChangeScope::Pet)
        ->and($row->pet_id)->toBe($f['pet']->id)
        ->and($row->family_id)->toBe($f['family']->id)
        ->and($row->user_id)->toBe($f['parent']->id)
        ->and($row->before)->toBeNull()
        ->and($row->after['school_start'])->toBe('08:00')
        ->and($row->user_agent)->toBe(QHC_UA);

    qhcPutPet($f['parent'], $f['pet'], QHC_SCHOOL)->assertOk();
    expect(QuietHoursChange::count())->toBe(1);

    qhcPutPet($f['parent'], $f['pet'], null)->assertOk()->assertJsonPath('quiet_hours_follow_family', true);
    $back = QuietHoursChange::latest('id')->first();
    expect(QuietHoursChange::count())->toBe(2)
        ->and($back->scope)->toBe(QuietHoursChangeScope::Pet)
        ->and($back->before['school_start'])->toBe('08:00')
        ->and($back->after)->toBeNull();

    // Following the family already → nothing.
    qhcPutPet($f['parent'], $f['pet'], null)->assertOk();
    expect(QuietHoursChange::count())->toBe(2);
});

it('the family export contains the change log', function () {
    $f = qhcFamily();
    qhcPutFamily($f['parent'], QHC_SCHOOL)->assertOk();
    qhcPutPet($f['parent'], $f['pet'], QHC_SCHOOL)->assertOk();

    actingAs($f['parent'], 'sanctum');
    getJson('/api/parent/account/export')->assertOk()
        ->assertJsonCount(2, 'quiet_hours_changes')
        ->assertJsonPath('quiet_hours_changes.0.scope', 'family')
        ->assertJsonPath('quiet_hours_changes.0.pet_id', null)
        ->assertJsonPath('quiet_hours_changes.0.by_user_id', $f['parent']->id)
        ->assertJsonPath('quiet_hours_changes.0.before.bedtime_start', '21:00')
        ->assertJsonPath('quiet_hours_changes.0.after.school_start', '08:00')
        ->assertJsonPath('quiet_hours_changes.0.user_agent', QHC_UA)
        ->assertJsonPath('quiet_hours_changes.0.client_features', QHC_FEATURES)
        ->assertJsonPath('quiet_hours_changes.1.scope', 'pet')
        ->assertJsonPath('quiet_hours_changes.1.pet_id', $f['pet']->id)
        ->assertJsonPath('quiet_hours_changes.1.before', null);
});

it('deleting the family removes its change log; a leaving parent is anonymised in a family that stays', function () {
    $f = qhcFamily();
    $other = qhcFamily();
    qhcPutFamily($f['parent'], QHC_SCHOOL)->assertOk();
    qhcPutPet($f['parent'], $f['pet'], QHC_SCHOOL)->assertOk();
    qhcPutFamily($other['parent'], QHC_SCHOOL)->assertOk();

    // A second parent joins the other family, saves, then deletes their account.
    $second = User::factory()->parent()->create();
    $code = app(FamilyInviteService::class)->createInvite($other['parent'])['code'];
    app(FamilyInviteService::class)->joinFamily($second, $code);
    qhcPutFamily($second->refresh(), ['bedtime_start' => '22:00'])->assertOk();
    expect(QuietHoursChange::where('user_id', $second->id)->count())->toBe(1);

    app(AccountDeletionService::class)->deleteFamily($f['family']);
    expect(QuietHoursChange::where('family_id', $f['family']->id)->count())->toBe(0)
        ->and(QuietHoursChange::where('family_id', $other['family']->id)->count())->toBe(2);

    app(AccountDeletionService::class)->deleteParentAccount($second->fresh());
    $kept = QuietHoursChange::where('family_id', $other['family']->id)->orderBy('id')->get();
    expect($kept)->toHaveCount(2)
        ->and($kept[1]->user_id)->toBeNull()
        ->and($kept[1]->user_agent)->toBeNull()
        ->and($kept[1]->client_features)->toBeNull()
        ->and($kept[1]->after['bedtime_start'])->toBe('22:00')
        ->and($kept[0]->user_id)->toBe($other['parent']->id);
});

it('admin: a superadmin sees the family\'s stored quiet hours and the latest changes, read-only', function () {
    $f = qhcFamily();
    qhcPutFamily($f['parent'], QHC_SCHOOL)->assertOk();
    qhcPutPet($f['parent'], $f['pet'], ['school_start' => '09:00', 'school_end' => '12:00', 'bedtime_start' => '20:00', 'bedtime_end' => '06:30', 'is_active' => true])->assertOk();

    actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true, 'email' => 'admin@example.com']));

    Livewire::test(EditUser::class, ['record' => $f['parent']->getRouteKey()])
        ->assertOk()
        ->assertSee('Quiet hours (family)')
        ->assertSee('Family: school 08:00–13:00 (Mon,Tue,Wed,Thu,Fri) · bed 21:00–07:00 · active')
        ->assertSee('Pet #'.$f['pet']->id.' (own hours): school 09:00–12:00')
        ->assertSee('pet #'.$f['pet']->id)
        ->assertSee('follows family')
        ->assertSee(QHC_UA);
});
