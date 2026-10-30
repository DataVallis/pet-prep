<?php

use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\User;
use App\Services\AccountExportService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| M5-T11 — »Prikaz: Video / Slika« (APP_REFINEMENT_SPEC §1)
|--------------------------------------------------------------------------
|
| A display preference per child profile: `video` (default) or `picture` (the
| key image of the current state, no video download). The child sets it in the
| child app; a parent of the family can set it for the child. Exposed in the
| child state (`settings.media_display`) and the family dashboard.
*/

beforeEach(function () {
    seedBreedConfigs();
});

/** @return array{0: User, 1: User, 2: Pet} */
function mdFamily(): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id, 'breed_type' => 'mutt']));

    return [$parent, $child, $pet];
}

it('defaults to video for every child — in the state, the dashboard and the export', function () {
    [$parent, $child] = mdFamily();

    test()->actingAs($child, 'sanctum');
    test()->getJson('/api/child/pet')->assertOk()->assertJsonPath('settings.media_display', 'video');

    test()->actingAs($parent, 'sanctum');
    $children = collect(test()->getJson('/api/parent/dashboard')->assertOk()->json('family.children'));
    expect($children->firstWhere('id', $child->id)['media_display'])->toBe('video');

    $export = app(AccountExportService::class)->exportFor($parent);
    expect(collect($export['children'])->firstWhere('id', $child->id)['media_display'])->toBe('video');
});

it('lets the child switch to picture and back; the state follows; one broadcast per change', function () {
    [, $child, $pet] = mdFamily();
    Event::fake([PetUpdated::class]);
    test()->actingAs($child, 'sanctum');

    test()->patchJson('/api/child/settings', ['media_display' => 'picture'])
        ->assertOk()->assertExactJson(['media_display' => 'picture']);
    expect($child->refresh()->media_display->value)->toBe('picture');
    Event::assertDispatchedTimes(PetUpdated::class, 1);
    Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'caretaker_settings_changed');

    test()->getJson('/api/child/pet')->assertOk()->assertJsonPath('settings.media_display', 'picture');

    // The same value again: nothing changes, no broadcast.
    test()->patchJson('/api/child/settings', ['media_display' => 'picture'])->assertOk();
    Event::assertDispatchedTimes(PetUpdated::class, 1);

    test()->patchJson('/api/child/settings', ['media_display' => 'video'])->assertOk()->assertJsonPath('media_display', 'video');
    Event::assertDispatchedTimes(PetUpdated::class, 2);
});

it('validates the child body and refuses a parent token on the child route', function () {
    [$parent, $child] = mdFamily();

    test()->actingAs($child, 'sanctum');
    test()->patchJson('/api/child/settings', [])->assertStatus(422)->assertJsonValidationErrors('media_display');
    test()->patchJson('/api/child/settings', ['media_display' => 'gif'])->assertStatus(422)->assertJsonValidationErrors('media_display');
    // A child cannot switch adapted mode through its own settings (only the display is accepted).
    test()->patchJson('/api/child/settings', ['media_display' => 'video', 'adapted_mode' => true])->assertOk();
    expect($child->refresh()->adapted_mode)->toBeFalse();

    test()->actingAs($parent, 'sanctum');
    test()->patchJson('/api/child/settings', ['media_display' => 'picture'])->assertForbidden();
});

it('lets a parent of the family set it for the child (alone or with adapted mode)', function () {
    [$parent, $child] = mdFamily();
    test()->actingAs($parent, 'sanctum');

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['media_display' => 'picture'])
        ->assertOk()
        ->assertExactJson(['child_id' => $child->id, 'adapted_mode' => false, 'media_display' => 'picture', 'easy_gestures' => false, 'game_feedback' => true]);
    expect($child->refresh()->media_display->value)->toBe('picture');

    // adapted mode alone leaves the display as it is
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => true])
        ->assertOk()->assertJsonPath('media_display', 'picture')->assertJsonPath('adapted_mode', true);

    test()->patchJson("/api/parent/children/{$child->id}/settings", ['adapted_mode' => false, 'media_display' => 'video'])
        ->assertOk()->assertExactJson(['child_id' => $child->id, 'adapted_mode' => false, 'media_display' => 'video', 'easy_gestures' => false, 'game_feedback' => true]);

    $children = collect(test()->getJson('/api/parent/dashboard')->assertOk()->json('family.children'));
    expect($children->firstWhere('id', $child->id)['media_display'])->toBe('video');
});

it('validates the parent body; another family\'s child is a 404; a child token is refused', function () {
    [$parent, $child] = mdFamily();
    [$otherParent] = mdFamily();

    test()->actingAs($parent, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", [])->assertStatus(422);
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['media_display' => 'slideshow'])
        ->assertStatus(422)->assertJsonValidationErrors('media_display');

    test()->actingAs($otherParent, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['media_display' => 'picture'])
        ->assertNotFound()->assertJsonPath('reason', 'child_not_found');
    expect($child->refresh()->media_display->value)->toBe('video');

    test()->actingAs($child, 'sanctum');
    test()->patchJson("/api/parent/children/{$child->id}/settings", ['media_display' => 'picture'])->assertForbidden();
});

it('stores only the two values (CHECK constraint)', function () {
    [, $child] = mdFamily();

    expect(fn () => DB::table('users')->where('id', $child->id)->update(['media_display' => 'gif']))
        ->toThrow(QueryException::class);
});
