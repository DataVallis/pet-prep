<?php

use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\User;
use App\Services\AccountExportService;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| GAMES_SPEC phase 1 — »Zvoki in vibracija v igrah" (David 2026-10-30, V5 + V11)
|--------------------------------------------------------------------------
|
| A parent-only switch per child profile, independent of the other settings:
| on (default) = the child app plays game sounds and haptics; off = none.
| Exposed in the child state (`settings.game_feedback`), the family dashboard
| and the data export. A setting only — no game rule reads it.
*/

beforeEach(function () {
    seedBreedConfigs();
});

/** @return array{0: User, 1: User, 2: Pet} */
function gfFamily(): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id, 'breed_type' => 'mutt']));

    return [$parent, $child, $pet];
}

function gfDashboardRow(User $parent, User $child): array
{
    test()->actingAs($parent, 'sanctum');
    $children = collect(test()->getJson('/api/parent/dashboard')->assertOk()->json('family.children'));

    return $children->firstWhere('id', $child->id);
}

it('defaults to true for every child — in the state, the dashboard, the export and a new profile', function () {
    [$parent, $child] = gfFamily();

    expect($child->refresh()->game_feedback)->toBeTrue();

    test()->actingAs($child, 'sanctum');
    test()->getJson('/api/child/pet')->assertOk()->assertJsonPath('settings.game_feedback', true);

    expect(gfDashboardRow($parent, $child)['game_feedback'])->toBeTrue();

    $export = app(AccountExportService::class)->exportFor($parent);
    expect(collect($export['children'])->firstWhere('id', $child->id)['game_feedback'])->toBeTrue();

    test()->actingAs($parent, 'sanctum');
    test()->postJson('/api/parent/children', ['display_name' => 'Nova'])
        ->assertCreated()->assertJsonPath('child.game_feedback', true);
});

it('lets a parent switch it off and on; state, dashboard and export follow; one broadcast per change', function () {
    [$parent, $child, $pet] = gfFamily();
    Event::fake([PetUpdated::class]);
    test()->actingAs($parent, 'sanctum');

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['game_feedback' => false])
        ->assertOk()
        ->assertExactJson(['child_id' => $child->id, 'adapted_mode' => false, 'media_display' => 'video', 'easy_gestures' => false, 'game_feedback' => false]);
    expect($child->refresh()->game_feedback)->toBeFalse()
        ->and($child->easy_gestures)->toBeFalse();
    Event::assertDispatchedTimes(PetUpdated::class, 1);
    Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'caretaker_settings_changed');

    // The same value again: nothing changes, no broadcast.
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['game_feedback' => false])->assertOk();
    Event::assertDispatchedTimes(PetUpdated::class, 1);

    expect(gfDashboardRow($parent, $child)['game_feedback'])->toBeFalse();
    $export = app(AccountExportService::class)->exportFor($parent);
    expect(collect($export['children'])->firstWhere('id', $child->id)['game_feedback'])->toBeFalse();

    test()->actingAs($child, 'sanctum');
    test()->getJson('/api/child/pet')->assertOk()
        ->assertJsonPath('settings.game_feedback', false)
        ->assertJsonPath('settings.easy_gestures', false);

    test()->actingAs($parent, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['game_feedback' => true])
        ->assertOk()->assertJsonPath('game_feedback', true);
    expect($child->refresh()->game_feedback)->toBeTrue();
    Event::assertDispatchedTimes(PetUpdated::class, 2);
});

it('is independent of the other settings', function () {
    [$parent, $child] = gfFamily();
    test()->actingAs($parent, 'sanctum');

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['easy_gestures' => true])
        ->assertOk()->assertJsonPath('game_feedback', true);

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['game_feedback' => false, 'media_display' => 'picture', 'adapted_mode' => true])
        ->assertOk()
        ->assertExactJson(['child_id' => $child->id, 'adapted_mode' => true, 'media_display' => 'picture', 'easy_gestures' => true, 'game_feedback' => false]);

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['easy_gestures' => false])
        ->assertOk()->assertJsonPath('game_feedback', false);
});

it('validates the body; another family\'s parent gets 404; a child token is refused', function () {
    [$parent, $child] = gfFamily();
    [$otherParent] = gfFamily();

    test()->actingAs($parent, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['game_feedback' => 'loud'])
        ->assertStatus(422)->assertJsonValidationErrors('game_feedback');
    test()->patchJson("/api/parent/children/{$child->id}/settings", [])
        ->assertStatus(422);

    test()->actingAs($otherParent, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['game_feedback' => false])
        ->assertNotFound()->assertJsonPath('reason', 'child_not_found');
    expect($child->refresh()->game_feedback)->toBeTrue();

    test()->actingAs($child, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['game_feedback' => false])->assertForbidden();
    // The child's own settings route accepts only the display; the switch stays the parent's.
    test()->patchJson('/api/child/settings', ['media_display' => 'video', 'game_feedback' => false])->assertOk();
    expect($child->refresh()->game_feedback)->toBeTrue();
});
