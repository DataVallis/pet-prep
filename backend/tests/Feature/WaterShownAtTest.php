<?php

use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Water time shown while the pet sleeps (David 2026-10-25)
|--------------------------------------------------------------------------
|
| David's report: a pup at 20:52, still awake, showed »ob 23:58« under
| »Voda« — a refill inside its night sleep (default quiet hours 21:00–07:00)
| read like a midnight task. The child state now carries `water.shown_at`:
| `next_allowed_at`, or the wake time when that falls while the pet sleeps
| (PetSleepService::shownAt). The refill rule (`next_allowed_at`, 422
| `water_too_soon`) is unchanged.
|
| Legacy mutt (−10 %/h awake, ×0.1 in quiet hours), Europe/Ljubljana.
*/

beforeEach(function () {
    seedBreedConfigs();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return Pet a child's legacy mutt at the given local time, acting as the child. */
function wsChild(string $local, float $thirst): Pet
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->create([
        'user_id' => $child->id,
        'born_at' => now()->subDay(),
        'last_decay_at' => now(),
    ]));
    Pet::whereKey($pet->id)->update(['thirst_level' => $thirst, 'last_decay_at' => now()]);
    actingAsRole($child);

    return $pet->refresh();
}

it('shows the wake time when the refill falls into tonight\'s sleep (awake at 20:52, threshold 23:58)', function () {
    // 64.79 − 8/60 h × 10 = 63.457 at 21:00, then −1 %/h: > 2.957 h → 178 min → 23:58.
    wsChild('2026-10-04 20:52', 64.79);

    $this->getJson('/api/child/pet')->assertOk()
        ->assertJsonPath('sleep.asleep', false)
        ->assertJsonPath('water.can_water', false)
        ->assertJsonPath('water.next_allowed_at', '2026-10-04T23:58:00+02:00')
        ->assertJsonPath('water.shown_at', '2026-10-05T07:00:00+02:00');

    // The rule itself is untouched: the refusal still names the server time.
    $this->postJson('/api/child/pet/water')->assertStatus(422)
        ->assertJsonPath('reason', 'water_too_soon')
        ->assertJsonPath('next_allowed_at', '2026-10-04T23:58:00+02:00')
        ->assertJsonPath('state.water.shown_at', '2026-10-05T07:00:00+02:00');
});

it('keeps the server time when the refill falls while the pet is awake (15:30)', function () {
    // 75.4 − t × 10 < 60.5 ⇔ t > 89.4 min → 15:30.
    wsChild('2026-10-04 14:00', 75.4);

    $this->getJson('/api/child/pet')->assertOk()
        ->assertJsonPath('water.next_allowed_at', '2026-10-04T15:30:00+02:00')
        ->assertJsonPath('water.shown_at', '2026-10-04T15:30:00+02:00');
});

it('is null with next_allowed_at when water is allowed now', function () {
    wsChild('2026-10-04 14:00', 50);

    $this->getJson('/api/child/pet')->assertOk()
        ->assertJsonPath('water.can_water', true)
        ->assertJsonPath('water.next_allowed_at', null)
        ->assertJsonPath('water.shown_at', null);
});

it('shows the same morning\'s wake time while the pet already sleeps (after midnight)', function () {
    // 01:00 asleep: 63 − t × 1 < 60.5 ⇔ t > 2.5 h → 03:31 → shown 07:00 the same day.
    wsChild('2026-10-05 01:00', 63);

    $this->getJson('/api/child/pet')->assertOk()
        ->assertJsonPath('sleep.asleep', true)
        ->assertJsonPath('water.next_allowed_at', '2026-10-05T03:31:00+02:00')
        ->assertJsonPath('water.shown_at', '2026-10-05T07:00:00+02:00');
});

it('is DST-safe: the night clocks go back (25 Oct 2026) wakes at 07:00 CET', function () {
    wsChild('2026-10-24 20:52', 64.79);

    $response = $this->getJson('/api/child/pet')->assertOk()
        ->assertJsonPath('water.shown_at', '2026-10-25T07:00:00+01:00');

    $next = Carbon::parse($response->json('water.next_allowed_at'));
    expect($next->greaterThan(Carbon::parse('2026-10-24 21:00', 'Europe/Ljubljana')))->toBeTrue()
        ->and($next->lessThan(Carbon::parse('2026-10-25 07:00', 'Europe/Ljubljana')))->toBeTrue();
});

it('keeps the server time when the parent switched quiet hours off', function () {
    $pet = wsChild('2026-10-04 20:52', 64.79);
    setQuietHours(['family_id' => $pet->family_id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => false]);

    $response = $this->getJson('/api/child/pet')->assertOk();

    expect($response->json('water.shown_at'))->toBe($response->json('water.next_allowed_at'))
        ->and($response->json('water.shown_at'))->not->toBeNull();
});
