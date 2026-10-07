<?php

use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Parent dashboard `contract_signed` (M5-F07)
|--------------------------------------------------------------------------
|
| `contract_signed` means "this child does not (any more) have to sign":
| the pet is born AND the child either signed or is a grandfathered
| caretaker (`pet_caretakers.requires_contract` false — pets born before
| contracts, factory / test pets). Before the fix it meant "a pet_contracts
| row exists", so a grandfathered child showed "the dog waits for the child
| to sign" while the dog decayed and alarmed.
|
*/

const DCS_SVG = ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'];

/**
 * @return array{0: User, 1: User}
 */
function dcsParentAndChild(): array
{
    seedBreedConfigs();
    Carbon::setTestNow(Carbon::parse('2026-10-04 06:00:00', 'UTC'));

    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    return [$parent, $child];
}

/** A second child that joins $pet with a join PIN; signs only when $sign. */
function dcsJoin(User $parent, Pet $pet, bool $sign): User
{
    actingAsRole($parent);
    $pin = postJson('/api/parent/generate-pin', ['pet_id' => $pet->id])->assertOk()->json('pin');

    $child = User::factory()->child()->create(['parent_id' => null]);
    actingAsRole($child);
    postJson('/api/child/pair', ['pin' => $pin])->assertCreated();
    if ($sign) {
        postJson('/api/child/contract', DCS_SVG)->assertCreated();
    }

    return $child->refresh();
}

/**
 * @return array{children: array<int, bool>, caretakers: array<int, bool>, awaiting: bool}
 */
function dcsDashboard(User $parent, Pet $pet): array
{
    actingAsRole($parent);
    $family = getJson('/api/parent/dashboard')->assertOk()->json('family');
    $petRow = collect($family['pets'])->firstWhere('id', $pet->id);

    return [
        'children' => collect($family['children'])->mapWithKeys(fn ($c) => [$c['id'] => $c['contract_signed']])->all(),
        'caretakers' => collect($petRow['caretakers'])->mapWithKeys(fn ($c) => [$c['child_id'] => $c['contract_signed']])->all(),
        'awaiting' => $petRow['awaiting_contract'],
    ];
}

it('reports a grandfathered caretaker (no contract row, requires_contract false) as signed', function () {
    [$parent, $child] = dcsParentAndChild();
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $child->id]));

    // Precondition: the legacy path created a grandfathered caretaker row without a contract.
    expect(PetCaretaker::where('pet_id', $pet->id)->where('user_id', $child->id)->value('requires_contract'))->toBeFalse()
        ->and($pet->contracts()->exists())->toBeFalse()
        ->and($pet->caretakerNeedsContract($child))->toBeFalse();

    $d = dcsDashboard($parent, $pet);

    expect($d['awaiting'])->toBeFalse()
        ->and($d['children'][$child->id])->toBeTrue()
        ->and($d['caretakers'][$child->id])->toBeTrue();
});

it('reports an unborn pet\'s caretaker as not signed', function () {
    [$parent, $child] = dcsParentAndChild();
    $pet = Pet::factory()->mutt()->unborn()->create(['user_id' => $child->id]);

    $d = dcsDashboard($parent, $pet);

    expect($d['awaiting'])->toBeTrue()
        ->and($d['children'][$child->id])->toBeFalse()
        ->and($d['caretakers'][$child->id])->toBeFalse();
});

it('reports a joined child who has not signed yet as not signed, the grandfathered first child as signed', function () {
    [$parent, $child] = dcsParentAndChild();
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $child->id]));
    $second = dcsJoin($parent, $pet, sign: false);

    expect(PetCaretaker::where('pet_id', $pet->id)->where('user_id', $second->id)->value('requires_contract'))->toBeTrue();

    $d = dcsDashboard($parent, $pet);

    expect($d['awaiting'])->toBeFalse()
        ->and($d['children'][$second->id])->toBeFalse()
        ->and($d['caretakers'][$second->id])->toBeFalse()
        ->and($d['children'][$child->id])->toBeTrue()
        ->and($d['caretakers'][$child->id])->toBeTrue();
});

it('reports a child who signed as signed', function () {
    [$parent, $child] = dcsParentAndChild();
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $child->id]));
    $second = dcsJoin($parent, $pet, sign: true);

    $d = dcsDashboard($parent, $pet);

    expect($d['children'][$second->id])->toBeTrue()
        ->and($d['caretakers'][$second->id])->toBeTrue();
});

it('reports the first child of a pet born by their own contract as signed', function () {
    [$parent, $child] = dcsParentAndChild();
    $pet = Pet::factory()->mutt()->unborn()->create(['user_id' => $child->id]);

    actingAsRole($child);
    postJson('/api/child/contract', DCS_SVG)->assertCreated();

    $d = dcsDashboard($parent, $pet->refresh());

    expect($d['awaiting'])->toBeFalse()
        ->and($d['children'][$child->id])->toBeTrue()
        ->and($d['caretakers'][$child->id])->toBeTrue();
});

it('agrees with the per-child lock rule (Pet::caretakerNeedsContract) for every caretaker', function () {
    [$parent, $child] = dcsParentAndChild();
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $child->id]));
    $unsigned = dcsJoin($parent, $pet, sign: false);
    $signed = dcsJoin($parent, $pet, sign: true);

    $d = dcsDashboard($parent, $pet);
    $pet->refresh();

    foreach ([$child, $unsigned, $signed] as $c) {
        expect($d['caretakers'][$c->id])->toBe(! ($pet->isUnborn() || $pet->caretakerNeedsContract($c)));
    }
});
