<?php

use App\Models\Pet;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M6-S01 regression R6 — store-only families answer exactly as before
|--------------------------------------------------------------------------
| GET /api/parent/billing, POST …/challenge/activate (activated / already_active
| / no_credit 409) and POST …/challenge/intent for a family that only ever bought
| in the store, normalised (ids → placeholders) and compared with
| tests/Fixtures/store_credit_responses_snapshot.json. The fixture was recorded
| on main BEFORE M6-S01 (this file uses no M6-S01 code), so a diff here means a
| store purchase now behaves differently — a STOP for the gift voucher work.
| Re-record only on purpose: SNAPSHOT_RECORD=1.
*/

const SC_NOW = '2026-10-07 10:00:00';
const SC_SECRET = 'sc-webhook-secret';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(SC_NOW, 'UTC'));
    seedBreedConfigs();
    config([
        'services.revenuecat.webhook_secret' => SC_SECRET,
        'services.revenuecat.accept_sandbox' => true,
        'services.revenuecat.challenge_products' => ['petprep_challenge_12w'],
        'payments.enforced' => true,
    ]);
    $this->withoutMiddleware([ThrottleRequests::class]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function scPurchase(User $parent): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.SC_SECRET])->postJson('/api/webhooks/revenuecat', [
        'api_version' => '1.0',
        'event' => [
            'id' => (string) Str::uuid(), 'type' => 'NON_RENEWING_PURCHASE', 'app_user_id' => (string) $parent->id,
            'product_id' => 'petprep_challenge_12w', 'store' => 'APP_STORE', 'environment' => 'PRODUCTION',
            'transaction_id' => 'tx-'.Str::random(8), 'purchased_at_ms' => now()->getTimestampMs(), 'expiration_at_ms' => null,
        ],
    ]);
}

/** @return array<string, mixed> */
function scCall(User $parent, string $method, string $uri): array
{
    app('auth')->forgetGuards();
    actingAsRole($parent);
    $res = $method === 'GET' ? getJson($uri) : postJson($uri);

    return ['status' => $res->status(), 'body' => $res->json()];
}

/**
 * @param  array<string, array<int, string>>  $ids  key (pet_id | intent_id) → real id → placeholder
 */
function scNormalise(mixed $value, array $ids): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = is_string($k) && isset($ids[$k]) && is_int($v) ? ($ids[$k][$v] ?? 'id?') : scNormalise($v, $ids);
        }

        return $out;
    }

    return $value;
}

it('R6: billing, activate and intent answer exactly as on main for a store-only family', function () {
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $childA = User::factory()->child()->create(['parent_id' => $parent->id]);
    $childB = User::factory()->child()->create(['parent_id' => $parent->id]);
    $locked = disableHygieneEvents(Pet::factory()->borderCollie()->trial()->create(['user_id' => $childA->id]));
    $free = disableHygieneEvents(Pet::factory()->freePlan()->create([
        'user_id' => $childB->id, 'breed_type' => 'mutt', 'born_at' => Carbon::parse('2026-07-01 08:00:00', 'UTC'), 'arrival_age_months' => 2,
    ]));
    $ids = ['pet_id' => [$locked->id => 'PET_LOCKED', $free->id => 'PET_FREE'], 'intent_id' => []];

    $steps = [];
    $steps['billing_before'] = scCall($parent, 'GET', '/api/parent/billing');
    $steps['activate_no_credit'] = scCall($parent, 'POST', "/api/parent/pets/{$locked->id}/challenge/activate");
    $intent = scCall($parent, 'POST', "/api/parent/pets/{$free->id}/challenge/intent");
    $ids['intent_id'][$intent['body']['intent_id'] ?? 0] = 'INTENT';
    $steps['intent_free'] = $intent;
    // Two pets wait (one locked, one free with an intent): the webhook follows the old rule.
    $steps['purchase_1'] = ['status' => scPurchase($parent)->status(), 'body' => null];
    $steps['billing_after_purchase'] = scCall($parent, 'GET', '/api/parent/billing');
    $steps['activate_locked'] = scCall($parent, 'POST', "/api/parent/pets/{$locked->id}/challenge/activate");
    $steps['activate_again'] = scCall($parent, 'POST', "/api/parent/pets/{$locked->id}/challenge/activate");
    scPurchase($parent)->assertOk();
    $steps['billing_one_held'] = scCall($parent, 'GET', '/api/parent/billing');
    $steps['activate_free'] = scCall($parent, 'POST', "/api/parent/pets/{$free->id}/challenge/activate");
    $steps['billing_end'] = scCall($parent, 'GET', '/api/parent/billing');

    $actual = scNormalise($steps, $ids);
    $fixture = base_path('tests/Fixtures/store_credit_responses_snapshot.json');
    if (getenv('SNAPSHOT_RECORD') === '1') {
        file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    expect($actual)->toBe(json_decode((string) file_get_contents($fixture), true));
});
