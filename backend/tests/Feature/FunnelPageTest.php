<?php

use App\Filament\Pages\Funnel;
use App\Http\Middleware\SetAdminLocale;
use App\Models\Pet;
use App\Models\User;
use App\Services\Funnel\FunnelService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M5-08 — validation funnel in the superadmin panel (David 2026-11-10)
|--------------------------------------------------------------------------
|
| FunnelService counts families per weekly sign-up cohort (Monday, Ljubljana)
| at every step from registration to the certificate, from tables the game
| already writes. /admin/funnel: superadmin only, numbers only.
|
*/

beforeEach(function () {
    seedBreedConfigs();
});

function fnAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/** @return array{0: User, 1: User, 2: Pet} parent, child, born free mutt — all created "now". */
function fnFamilyWithPet(): array
{
    $parent = User::factory()->parent()->create();
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $child->id]));

    return [$parent, $child, $pet];
}

function fnChildAction(Pet $pet, User $child, string $utc): void
{
    DB::table('activities_log')->insert([
        'pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => 'fed_pet',
        'created_at' => Carbon::parse($utc, 'UTC'),
    ]);
}

function fnCredit(Pet $pet, string $purchasedUtc, string $environment = 'PRODUCTION', bool $refunded = false): void
{
    $at = Carbon::parse($purchasedUtc, 'UTC');
    $eventId = DB::table('purchase_events')->insertGetId([
        'event_id' => (string) Str::uuid(), 'type' => 'NON_RENEWING_PURCHASE', 'payload' => '{}',
        'created_at' => $at, 'updated_at' => $at,
    ]);
    DB::table('challenge_credits')->insert([
        'family_id' => $pet->family_id, 'purchase_event_id' => $eventId, 'product_id' => 'petprep_challenge_12w',
        'environment' => $environment, 'purchased_at' => $at,
        'revoked_at' => $refunded ? $at : null, 'revoke_reason' => $refunded ? 'refund' : null,
        'created_at' => $at, 'updated_at' => $at,
    ]);
}

describe('FunnelService', function () {
    it('counts each family once per step in its sign-up week', function () {
        // Monday 2026-10-05 08:00 UTC → cohort 2026-10-05.
        fnAt('2026-10-05 08:00:00');
        [, $childA, $petA] = fnFamilyWithPet();            // goes all the way to a real purchase + week 4
        [, $childB, $petB] = fnFamilyWithPet();            // active day 1 only, sandbox purchase
        [, , $petC] = fnFamilyWithPet();                   // born, never active, refunded purchase
        User::factory()->parent()->create();               // registered only
        $withChild = User::factory()->parent()->create();  // child added, no pet
        User::factory()->child()->create(['parent_id' => $withChild->id]);

        fnChildAction($petA, $childA, '2026-10-06 09:00:00');
        fnChildAction($petA, $childA, '2026-10-13 09:00:00');
        fnChildAction($petA, $childA, '2026-11-05 09:00:00');
        fnChildAction($petB, $childB, '2026-10-07 09:00:00');
        fnCredit($petA, '2026-10-12 10:00:00');
        fnCredit($petB, '2026-10-12 10:00:00', 'SANDBOX');
        fnCredit($petC, '2026-10-12 10:00:00', 'PRODUCTION', refunded: true);

        // A family of the following week, too young for day 7.
        fnAt('2026-11-09 08:00:00');
        [, $childD, $petD] = fnFamilyWithPet();
        fnChildAction($petD, $childD, '2026-11-10 09:00:00');

        // A superadmin's own family is never counted.
        User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);

        fnAt('2026-11-10 12:00:00');
        $funnel = app(FunnelService::class)->cohorts(12);

        expect(array_column($funnel['rows'], 'cohort'))->toBe(['2026-11-09', '2026-10-05']);

        $october = $funnel['rows'][1];
        expect($october['registered'])->toBe(5)
            ->and($october['child_added'])->toBe(4)
            ->and($october['pet_born'])->toBe(3)
            ->and($october['d1_eligible'])->toBe(3)
            ->and($october['d1'])->toBe(2)
            ->and($october['d7_eligible'])->toBe(3)
            ->and($october['d7'])->toBe(1)
            ->and($october['paid'])->toBe(1)
            ->and($october['paid_test'])->toBe(1)
            ->and($october['paid_voucher'])->toBe(0) // M6-S01: store purchases are never »paid with voucher«
            ->and($october['week4_eligible'])->toBe(2)
            ->and($october['week4'])->toBe(1)
            ->and($october['certificate'])->toBe(0);

        $november = $funnel['rows'][0];
        expect($november['registered'])->toBe(1)
            ->and($november['pet_born'])->toBe(1)
            ->and($november['d1_eligible'])->toBe(1)
            ->and($november['d1'])->toBe(1)
            ->and($november['d7_eligible'])->toBe(0)
            ->and($november['d7'])->toBe(0)
            ->and($november['paid'])->toBe(0);

        expect($funnel['total']['registered'])->toBe(6)
            ->and($funnel['total']['paid'])->toBe(1);
    });

    it('puts a Sunday-night sign-up into the Ljubljana week, not the UTC one', function () {
        // Sunday 2026-10-11 22:30 UTC = Monday 00:30 in Ljubljana → cohort 2026-10-12.
        fnAt('2026-10-11 22:30:00');
        User::factory()->parent()->create();

        fnAt('2026-10-14 12:00:00');
        expect(array_column(app(FunnelService::class)->cohorts(4)['rows'], 'cohort'))->toBe(['2026-10-12']);
    });

    it('shows only the requested number of weeks', function () {
        fnAt('2026-09-01 08:00:00');
        User::factory()->parent()->create();
        fnAt('2026-11-10 12:00:00');
        User::factory()->parent()->create();

        expect(app(FunnelService::class)->cohorts(8)['rows'])->toHaveCount(1)
            ->and(app(FunnelService::class)->cohorts(12)['rows'])->toHaveCount(2);
    });

    it('gives whole-number shares and none without a base', function () {
        expect(FunnelService::percent(1, 3))->toBe(33)
            ->and(FunnelService::percent(0, 0))->toBeNull();
    });
});

describe('Filament page', function () {
    it('is superadmin only, English by default and Slovenian on request', function () {
        fnAt('2026-11-10 12:00:00');
        fnFamilyWithPet();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);

        $this->actingAs($admin)->get('/admin/funnel')->assertOk()
            ->assertSee('Funnel')->assertSee('Pet born')->assertSee('2026-11-09');
        $this->withCookie(SetAdminLocale::COOKIE, 'sl')->get('/admin/funnel')->assertOk()
            ->assertSee('Lijak')->assertSee('Rojen ljubljenček');

        app('auth')->forgetGuards();
        $this->actingAs(User::factory()->parent()->create())->get('/admin/funnel')->assertForbidden();
        app('auth')->forgetGuards();
        $this->actingAs(User::factory()->child()->create())->get('/admin/funnel')->assertForbidden();
    });

    it('refuses the Livewire page for a non-superadmin and falls back from an unknown period', function () {
        $this->actingAs(User::factory()->parent()->create());
        Livewire::test(Funnel::class)->assertForbidden();

        app('auth')->forgetGuards();
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));
        Livewire::test(Funnel::class)->set('weeks', 26)->assertSet('weeks', 26)->set('weeks', 999)->assertSet('weeks', 12);
    });
});
