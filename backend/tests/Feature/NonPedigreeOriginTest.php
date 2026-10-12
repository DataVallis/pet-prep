<?php

use App\Models\Pet;
use App\Models\User;
use App\Services\ChildProfileService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| David 2026-10-12 — a mutt (or domestic cat) is never bought from a breeder
|--------------------------------------------------------------------------
|
| The app no longer offers "Kupljen pri vzreditelju" for a non-pedigree free
| breed. generate-pin turns a "bought" origin for it into "adopted" (older
| builds may still send it — corrected, never refused), and keeps "bought"
| for a pedigree breed.
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
    seedLifeStageData();
    $this->withoutMiddleware([ThrottleRequests::class]);
});

/** @param array<string, mixed> $body */
function npoPinLogin(array $body): TestResponse
{
    $parent = User::factory()->parent()->create();
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    actingAsRole($parent);
    $pin = postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body))->assertOk()->json('pin');

    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []])->assertOk();
}

it('turns a bought mutt into an adopted one', function (array $body) {
    $petId = npoPinLogin($body + ['origin' => 'bought', 'age_stage' => 'puppy'])->json('pet.id');

    expect(Pet::findOrFail($petId)->origin->value)->toBe('adopted');
})->with([
    'free plan, mutt' => [['plan' => 'free', 'breed' => 'mutt']],
    'old build, no breed (→ mutt)' => [[]],
]);

it('keeps an adopted mutt and a bought pedigree dog as chosen', function () {
    $mutt = npoPinLogin(['plan' => 'free', 'breed' => 'mutt', 'origin' => 'adopted', 'age_stage' => 'puppy'])->json('pet.id');
    $collie = npoPinLogin(['plan' => 'challenge', 'breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])->json('pet.id');

    expect(Pet::findOrFail($mutt)->origin->value)->toBe('adopted')
        ->and(Pet::findOrFail($collie)->origin->value)->toBe('bought');
});
