<?php

use App\Models\ChallengeCredit;
use App\Models\Family;
use App\Models\GiftVoucher;
use App\Models\Pet;
use App\Models\User;
use App\Services\ChallengeCreditService;
use App\Services\FamilyService;
use App\Services\UnassignedCreditMonitor;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| M6-S01 regressions R2–R5, R7, R9 (CONTRACT §9.1): RevenueCat store credits
| behave exactly as before, whatever gift credits a family holds.
| (R1 = the existing suites; R6 = StoreCreditSnapshotTest; R8 = FunnelPageTest.)
|--------------------------------------------------------------------------
*/

const RG_NOW = '2026-10-07 10:00:00';
const RG_SECRET = 'rg-webhook-secret';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(RG_NOW, 'UTC'));
    seedBreedConfigs();
    config([
        'services.revenuecat.webhook_secret' => RG_SECRET,
        'services.revenuecat.accept_sandbox' => true,
        'services.revenuecat.challenge_products' => ['petprep_challenge_12w'],
        'services.lemonsqueezy.gift_product_id' => '5202',
        'payments.enforced' => true,
    ]);
    $this->withoutMiddleware([ThrottleRequests::class]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function rgRc(User $parent, array $overrides = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.RG_SECRET])->postJson('/api/webhooks/revenuecat', [
        'api_version' => '1.0',
        'event' => array_merge([
            'id' => (string) Str::uuid(), 'type' => 'NON_RENEWING_PURCHASE', 'app_user_id' => (string) $parent->id,
            'product_id' => 'petprep_challenge_12w', 'store' => 'APP_STORE', 'environment' => 'PRODUCTION',
            'transaction_id' => 'tx-'.Str::random(8), 'purchased_at_ms' => now()->getTimestampMs(), 'expiration_at_ms' => null,
        ], $overrides),
    ]);
}

/** @return array{0: User, 1: User, 2: Family} */
function rgParent(): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    return [$parent, $child, app(FamilyService::class)->familyOf($parent)];
}

/** A redeemed gift voucher + its unassigned web_gift credit (as the redeem endpoint writes them). */
function rgGiftCredit(Family $family, ?Carbon $at = null): ChallengeCredit
{
    $at ??= now();
    $voucher = GiftVoucher::create([
        'key_hash' => hash('sha256', Str::uuid()->toString()), 'key_last4' => 'ab12', 'ls_license_key_id' => random_int(1, 1_000_000),
        'ls_order_id' => random_int(1, 1_000_000), 'environment' => 'PRODUCTION', 'status' => 'redeemed',
        'purchased_at' => $at, 'expires_at' => $at->copy()->addYear(), 'redeemed_at' => $at, 'family_id' => $family->id,
    ]);

    return Carbon::withTestNow($at, fn () => DB::transaction(fn () => app(ChallengeCreditService::class)->createFromGift($family, $voucher)));
}

it('R2: an RC purchase still creates a store credit with the same auto-assignment', function () {
    [$parent, $child, $family] = rgParent();
    $pet = disableHygieneEvents(Pet::factory()->borderCollie()->trial()->create(['user_id' => $child->id]));
    rgGiftCredit($family, now()->subDay()); // a held gift credit changes nothing for the webhook

    rgRc($parent)->assertOk()->assertJsonPath('outcome', 'granted');
    $store = ChallengeCredit::where('source', 'store')->sole();
    expect($store->purchase_event_id)->not->toBeNull()
        ->and($store->gift_voucher_id)->toBeNull()
        ->and($store->pet_id)->toBe($pet->id)
        ->and($store->assigned_via)->toBe(ChallengeCredit::VIA_WEBHOOK)
        ->and(ChallengeCredit::where('source', 'web_gift')->sole()->pet_id)->toBeNull();
});

it('R3: an RC refund without transaction id / product id revokes only a store credit', function () {
    [$parent, , $family] = rgParent();
    // Two waiting pets → the webhook cannot choose → the store credit stays unassigned.
    foreach ([1, 2] as $i) {
        $c = User::factory()->child()->create(['parent_id' => $parent->id]);
        disableHygieneEvents(Pet::factory()->borderCollie()->trial()->create(['user_id' => $c->id]));
    }
    rgRc($parent, ['transaction_id' => 'tx-store'])->assertOk();
    $gift = rgGiftCredit($family, now()->addMinute()); // newer than the store credit

    // Service level: no transaction id, no product id (the branch that matched ANY credit).
    $revoked = DB::transaction(function () use ($family) {
        Family::whereKey($family->id)->lockForUpdate()->first();

        return app(ChallengeCreditService::class)->revokeForRefund($family, null, null, 'evt-r3', now());
    });
    expect($revoked->source)->toBe('store')
        ->and($gift->fresh()->revoked_at)->toBeNull();

    // Nothing else to revoke: the gift credit is never a fallback.
    $none = DB::transaction(fn () => app(ChallengeCreditService::class)->revokeForRefund($family, null, null, 'evt-r3b', now()));
    expect($none)->toBeNull()->and($gift->fresh()->revoked_at)->toBeNull();

    // Webhook level: REFUND without a transaction id.
    rgRc($parent, ['transaction_id' => 'tx-store-2'])->assertOk();
    rgRc($parent, ['type' => 'REFUND', 'transaction_id' => null])->assertJsonPath('outcome', 'revoked');
    expect($gift->fresh()->revoked_at)->toBeNull()
        ->and(ChallengeCredit::where('source', 'store')->whereNull('revoked_at')->count())->toBe(0);
});

it('R3: in a store-only family the same credit as before is revoked (newest unassigned first)', function () {
    [$parent, , $family] = rgParent();
    rgRc($parent, ['transaction_id' => 'tx-old'])->assertOk();
    Carbon::setTestNow(Carbon::parse(RG_NOW, 'UTC')->addHour());
    rgRc($parent, ['transaction_id' => 'tx-new'])->assertOk();

    rgRc($parent, ['type' => 'REFUND', 'transaction_id' => null])->assertJsonPath('outcome', 'revoked');
    expect(ChallengeCredit::where('transaction_id', 'tx-new')->sole()->revoked_at)->not->toBeNull()
        ->and(ChallengeCredit::where('transaction_id', 'tx-old')->sole()->revoked_at)->toBeNull();
});

it('R4: an RC TRANSFER moves unassigned store credits and leaves the gift credit with its family', function () {
    [$a, , $familyA] = rgParent();
    [$b, , $familyB] = rgParent();
    rgRc($a)->assertOk();
    $gift = rgGiftCredit($familyA);

    rgRc($a, [
        'type' => 'TRANSFER', 'app_user_id' => null, 'product_id' => null,
        'transferred_from' => [(string) $a->id], 'transferred_to' => [(string) $b->id],
    ])->assertOk()->assertJsonPath('outcome', 'transferred');

    expect(ChallengeCredit::where('source', 'store')->sole()->family_id)->toBe($familyB->id)
        ->and($gift->fresh()->family_id)->toBe($familyA->id)
        ->and($gift->fresh()->transferred_at)->toBeNull();
});

it('R5: the unassigned-credit monitor alerts a store credit as before and never a gift credit', function () {
    config(['services.discord.alerts_webhook_url' => 'https://discord.com/api/webhooks/1/rg']);
    Http::fake(['discord.com/api/webhooks/*' => Http::response('', 204)]);
    [$parent, , $family] = rgParent();
    foreach ([1, 2] as $i) {
        $c = User::factory()->child()->create(['parent_id' => $parent->id]);
        disableHygieneEvents(Pet::factory()->borderCollie()->trial()->create(['user_id' => $c->id]));
    }
    rgRc($parent)->assertOk();
    $gift = rgGiftCredit($family);

    Carbon::setTestNow(Carbon::parse(RG_NOW, 'UTC')->addMinutes(11));
    $claimed = app(UnassignedCreditMonitor::class)->check();
    expect($claimed)->toBe([ChallengeCredit::where('source', 'store')->sole()->id])
        ->and($gift->fresh()->unassigned_alerted_at)->toBeNull();

    // A family with only a gift credit and a waiting pet: no alert, ever.
    ChallengeCredit::where('source', 'store')->delete();
    Carbon::setTestNow(Carbon::parse(RG_NOW, 'UTC')->addMinutes(30));
    expect(app(UnassignedCreditMonitor::class)->check())->toBe([]);
});

it('R7: the migration gives existing rows source=store, valid constraints, and down() works without gift rows', function () {
    [$parent] = rgParent();
    rgRc($parent)->assertOk();
    $migration = require database_path('migrations/2027_03_25_120000_create_gift_vouchers.php');

    $migration->down();
    expect(DB::getSchemaBuilder()->hasColumn('challenge_credits', 'source'))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasTable('gift_vouchers'))->toBeFalse()
        ->and(DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'challenge_credits' AND column_name = 'purchase_event_id'")->is_nullable)->toBe('NO');

    $migration->up();
    expect(DB::table('challenge_credits')->pluck('source')->all())->toBe(['store']);
    // The CHECK holds for the old row and refuses a store row without its event.
    expect(fn () => DB::transaction(fn () => DB::table('challenge_credits')->insert([
        'family_id' => ChallengeCredit::first()->family_id, 'product_id' => 'x', 'purchased_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'challenge_credits_source_ref_check');

    // down() refuses while a gift credit exists.
    rgGiftCredit(app(FamilyService::class)->familyOf($parent));
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'web_gift');
});

it('R9: one unrevoked credit per pet across both sources', function () {
    [$parent, $child, $family] = rgParent();
    $pet = disableHygieneEvents(Pet::factory()->borderCollie()->trial()->create(['user_id' => $child->id]));
    rgRc($parent)->assertOk(); // auto-assigned to $pet
    $gift = rgGiftCredit($family);

    expect(fn () => DB::transaction(fn () => DB::table('challenge_credits')->where('id', $gift->id)->update([
        'pet_id' => $pet->id, 'assigned_at' => now(), 'assigned_via' => 'parent',
    ])))->toThrow(UniqueConstraintViolationException::class);
});
