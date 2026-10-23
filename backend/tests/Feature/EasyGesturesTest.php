<?php

use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\User;
use App\Services\AccountExportService;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| M5-T17 — »Lažje kretnje« / "Easier gestures" (David 2026-10-23)
|--------------------------------------------------------------------------
|
| A parent-only switch per child profile, independent of adapted mode: on =
| the child app uses press-and-hold instead of swipe in every swipe game.
| Default off (swipe only). Exposed in the child state
| (`settings.easy_gestures`), the family dashboard and the data export.
| Game rules and server validation do not change.
*/

beforeEach(function () {
    seedBreedConfigs();
});

/** @return array{0: User, 1: User, 2: Pet} */
function egFamily(): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id, 'breed_type' => 'mutt']));

    return [$parent, $child, $pet];
}

function egDashboardRow(User $parent, User $child): array
{
    test()->actingAs($parent, 'sanctum');
    $children = collect(test()->getJson('/api/parent/dashboard')->assertOk()->json('family.children'));

    return $children->firstWhere('id', $child->id);
}

it('defaults to false for every child — in the state, the dashboard and the export', function () {
    [$parent, $child] = egFamily();

    expect($child->refresh()->easy_gestures)->toBeFalse();

    test()->actingAs($child, 'sanctum');
    test()->getJson('/api/child/pet')->assertOk()->assertJsonPath('settings.easy_gestures', false);

    expect(egDashboardRow($parent, $child)['easy_gestures'])->toBeFalse();

    $export = app(AccountExportService::class)->exportFor($parent);
    expect(collect($export['children'])->firstWhere('id', $child->id)['easy_gestures'])->toBeFalse();
});

it('lets a parent switch it on and off; state, dashboard and export follow; one broadcast per change', function () {
    [$parent, $child, $pet] = egFamily();
    Event::fake([PetUpdated::class]);
    test()->actingAs($parent, 'sanctum');

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['easy_gestures' => true])
        ->assertOk()
        ->assertExactJson(['child_id' => $child->id, 'adapted_mode' => false, 'media_display' => 'video', 'easy_gestures' => true]);
    expect($child->refresh()->easy_gestures)->toBeTrue()
        ->and($child->adapted_mode)->toBeFalse();
    Event::assertDispatchedTimes(PetUpdated::class, 1);
    Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'caretaker_settings_changed');

    // The same value again: nothing changes, no broadcast.
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['easy_gestures' => true])->assertOk();
    Event::assertDispatchedTimes(PetUpdated::class, 1);

    expect(egDashboardRow($parent, $child)['easy_gestures'])->toBeTrue();
    $export = app(AccountExportService::class)->exportFor($parent);
    expect(collect($export['children'])->firstWhere('id', $child->id)['easy_gestures'])->toBeTrue();

    test()->actingAs($child, 'sanctum');
    test()->getJson('/api/child/pet')->assertOk()
        ->assertJsonPath('settings.easy_gestures', true)
        ->assertJsonPath('steps.adapted_mode', false);

    test()->actingAs($parent, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['easy_gestures' => false])
        ->assertOk()->assertJsonPath('easy_gestures', false);
    expect($child->refresh()->easy_gestures)->toBeFalse();
    Event::assertDispatchedTimes(PetUpdated::class, 2);
});

it('is independent of adapted mode and the display', function () {
    [$parent, $child] = egFamily();
    test()->actingAs($parent, 'sanctum');

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => true])
        ->assertOk()->assertJsonPath('easy_gestures', false);

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['easy_gestures' => true, 'media_display' => 'picture'])
        ->assertOk()
        ->assertExactJson(['child_id' => $child->id, 'adapted_mode' => true, 'media_display' => 'picture', 'easy_gestures' => true]);

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => false])
        ->assertOk()->assertJsonPath('easy_gestures', true);
});

it('validates the body; another family\'s parent gets 404; a child token is refused', function () {
    [$parent, $child] = egFamily();
    [$otherParent] = egFamily();

    test()->actingAs($parent, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['easy_gestures' => 'sometimes'])
        ->assertStatus(422)->assertJsonValidationErrors('easy_gestures');

    test()->actingAs($otherParent, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['easy_gestures' => true])
        ->assertNotFound()->assertJsonPath('reason', 'child_not_found');
    expect($child->refresh()->easy_gestures)->toBeFalse();

    test()->actingAs($child, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['easy_gestures' => true])->assertForbidden();
    // The child's own settings route accepts only the display; easy gestures stay the parent's.
    test()->patchJson('/api/child/settings', ['media_display' => 'video', 'easy_gestures' => true])->assertOk();
    expect($child->refresh()->easy_gestures)->toBeFalse();
});
