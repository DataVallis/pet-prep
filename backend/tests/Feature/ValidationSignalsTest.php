<?php

use App\Enums\Phase2PriceChoice;
use App\Filament\Pages\Funnel;
use App\Http\Middleware\SetAdminLocale;
use App\Models\EmailContact;
use App\Models\EmailSignup;
use App\Models\ParentDashboardOpen;
use App\Models\Pet;
use App\Models\Phase2Interest;
use App\Models\User;
use App\Services\EmailSignupService;
use App\Services\FamilyInviteService;
use App\Services\Funnel\FunnelService;
use App\Services\Validation\Phase2InterestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/*
|--------------------------------------------------------------------------
| Validation signals — VALIDATION_PLAN §4.1 / §4.4, M5-08 (David 2026-11-21)
|--------------------------------------------------------------------------
|
| Contract: docs/engineering/VALIDATION_API.md. Acquisition source at sign-up
| (an invalid answer never blocks it), the one-time answer via settings,
| parent dashboard opens (H6, ≤ 1 per 30 min) and the Phase-2 fake door (H7,
| e-mail into the unified e-mail store), export / deletion and the funnel.
| Social sign-in cases live in SocialSignInTest (its token helpers).
|
*/

beforeEach(function () {
    // The signals themselves; the admin switch modes are in ValidationSignalsSwitchTest.
    useValidationSignals('everyone');
});

function vsAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

function vsAs(User $user): void
{
    app('auth')->forgetGuards();
    actingAsRole($user);
}

/** @param  array<string, mixed>  $overrides */
function vsRegister(array $overrides = [], string $ip = '198.51.100.140'): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => ''])->withServerVariables(['REMOTE_ADDR' => $ip]);

    return postJson('/api/register', array_merge([
        'name' => 'Mama Ana',
        'email' => 'ana@example.com',
        'password' => 'Varno1Geslo',
        'password_confirmation' => 'Varno1Geslo',
        'timezone' => 'Europe/Ljubljana',
        'accept_terms' => true,
    ], $overrides));
}

/** @return array{0: User, 1: User, 2: Pet} parent, child, born mutt — all created "now". */
function vsFamilyWithPet(): array
{
    $parent = User::factory()->parent()->create();
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $child->id]));

    return [$parent, $child, $pet];
}

function vsSecondParent(User $parent): User
{
    $second = User::factory()->parent()->create();
    $code = app(FamilyInviteService::class)->createInvite($parent)['code'];
    app(FamilyInviteService::class)->joinFamily($second, $code);

    return $second->refresh();
}

function vsOpen(User $parent, string $utc): void
{
    DB::table('parent_dashboard_opens')->insert([
        'user_id' => $parent->id, 'family_id' => $parent->family->id, 'opened_at' => Carbon::parse($utc, 'UTC'), 'trigger' => 'launch',
    ]);
}

/* ─────────────────────── 1. Acquisition source ─────────────────────── */

describe('acquisition source at POST /api/register', function () {
    it('stores the source with its detail for ad / other', function () {
        vsRegister(['acquisition_source' => 'ad', 'acquisition_detail' => '  JESEN-26  '])->assertCreated();
        vsRegister(['email' => 'b@example.com', 'acquisition_source' => 'other', 'acquisition_detail' => 'Plakat v vrtcu'], '198.51.100.141')->assertCreated();

        $ad = User::where('email', 'ana@example.com')->sole();
        $other = User::where('email', 'b@example.com')->sole();
        expect($ad->acquisition_source)->toBe('ad')
            ->and($ad->acquisition_detail)->toBe('JESEN-26')
            ->and($ad->acquisition_recorded_at)->not->toBeNull()
            ->and($other->acquisition_source)->toBe('other')
            ->and($other->acquisition_detail)->toBe('Plakat v vrtcu');
    });

    it('keeps the detail only for other / ad', function () {
        vsRegister(['acquisition_source' => 'instagram', 'acquisition_detail' => 'some reel'])->assertCreated();

        $user = User::sole();
        expect($user->acquisition_source)->toBe('instagram')
            ->and($user->acquisition_detail)->toBeNull();
    });

    it('never blocks the sign-up over an invalid answer: null stored, logged at info', function (mixed $source, mixed $detail) {
        Log::spy();

        vsRegister(['acquisition_source' => $source, 'acquisition_detail' => $detail])->assertCreated();

        $user = User::sole();
        expect($user->acquisition_source)->toBeNull()
            ->and($user->acquisition_detail)->toBeNull()
            ->and($user->acquisition_recorded_at)->toBeNull();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'acquisition_source ignored'));
    })->with([
        'unknown value' => ['radio', null],
        'wrong case' => ['Instagram', 'x'],
        'a number' => [42, null],
        'an array' => [['ad'], 'CODE'],
    ]);

    it('cuts a long detail to 60 characters and ignores a detail that is not text', function () {
        vsRegister(['acquisition_source' => 'ad', 'acquisition_detail' => str_repeat('a', 80)])->assertCreated();
        vsRegister(['email' => 'b@example.com', 'acquisition_source' => 'other', 'acquisition_detail' => ['x']], '198.51.100.141')->assertCreated();

        expect(User::where('email', 'ana@example.com')->value('acquisition_detail'))->toBe(str_repeat('a', 60))
            ->and(User::where('email', 'b@example.com')->value('acquisition_source'))->toBe('other')
            ->and(User::where('email', 'b@example.com')->value('acquisition_detail'))->toBeNull();
    });

    it('stores nothing for an old app that sends no fields', function () {
        vsRegister()->assertCreated();

        expect(User::sole()->acquisition_source)->toBeNull();
    });

    it('tells the app through GET /api/user (parents; null for a child)', function () {
        $token = vsRegister(['acquisition_source' => 'tiktok'])->assertCreated()->json('token');

        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
        getJson('/api/user')->assertOk()->assertJsonPath('acquisition_source', 'tiktok');

        $child = User::factory()->child()->create(['parent_id' => User::sole()->id]);
        DB::table('users')->where('id', $child->id)->update(['acquisition_source' => 'instagram']);
        $this->withHeaders(['Authorization' => '']);
        vsAs($child->fresh());
        getJson('/api/user')->assertOk()->assertJsonPath('acquisition_source', null);
    });
});

describe('acquisition source via PATCH /api/parent/settings', function () {
    it('accepts the answer once, without a timezone; later answers are ignored with 200', function () {
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        patchJson('/api/parent/settings', ['acquisition_source' => 'ad', 'acquisition_detail' => 'FB-1'])->assertOk()
            ->assertJsonPath('settings.acquisition_source', 'ad')
            ->assertJsonPath('settings.timezone', $parent->timezone);
        $recordedAt = $parent->fresh()->acquisition_recorded_at;

        $this->travel(1)->hours();
        patchJson('/api/parent/settings', ['acquisition_source' => 'instagram'])->assertOk()
            ->assertJsonPath('settings.acquisition_source', 'ad');

        $fresh = $parent->fresh();
        expect($fresh->acquisition_source)->toBe('ad')
            ->and($fresh->acquisition_detail)->toBe('FB-1')
            ->and($fresh->acquisition_recorded_at->equalTo($recordedAt))->toBeTrue();
    });

    it('ignores an invalid answer with 200 and keeps the chance', function () {
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        patchJson('/api/parent/settings', ['acquisition_source' => 'billboard'])->assertOk()
            ->assertJsonPath('settings.acquisition_source', null);
        patchJson('/api/parent/settings', ['acquisition_source' => 'search', 'acquisition_detail' => 'dropped'])->assertOk()
            ->assertJsonPath('settings.acquisition_source', 'search');

        expect($parent->fresh()->acquisition_detail)->toBeNull();
    });

    it('takes the timezone and the answer together, and PUT still needs a timezone without them', function () {
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        patchJson('/api/parent/settings', ['timezone' => 'Europe/Berlin', 'acquisition_source' => 'website'])->assertOk()
            ->assertJsonPath('settings.timezone', 'Europe/Berlin')
            ->assertJsonPath('settings.acquisition_source', 'website');
        putJson('/api/parent/settings', [])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        patchJson('/api/parent/settings', [])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        // PUT is unchanged: timezone required, the acquisition fields are not taken there.
        $other = User::factory()->parent()->create();
        vsAs($other);
        putJson('/api/parent/settings', ['acquisition_source' => 'ad'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        putJson('/api/parent/settings', ['timezone' => 'Europe/Ljubljana', 'acquisition_source' => 'ad'])->assertOk()
            ->assertExactJson(['message' => 'Settings updated successfully.', 'settings' => ['timezone' => 'Europe/Ljubljana']]);
        expect($other->fresh()->acquisition_source)->toBeNull();
        vsAs($parent);

        expect($parent->fresh()->family->timezone)->toBe('Europe/Berlin');
    });

    it('stores the answer even when the timezone in the same PATCH hits the 24 h cooldown (m1)', function () {
        $parent = User::factory()->parent()->create();
        vsAs($parent);
        patchJson('/api/parent/settings', ['timezone' => 'Europe/Berlin'])->assertOk();

        patchJson('/api/parent/settings', ['timezone' => 'Europe/Paris', 'acquisition_source' => 'youtube'])
            ->assertUnprocessable()->assertJsonPath('reason', 'timezone_change_too_soon');

        expect($parent->fresh()->acquisition_source)->toBe('youtube')
            ->and($parent->fresh()->family->timezone)->toBe('Europe/Berlin');
    });

    it('is parent only', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        vsAs($child);

        patchJson('/api/parent/settings', ['acquisition_source' => 'ad'])->assertForbidden();
        expect($child->fresh()->acquisition_source)->toBeNull();
    });
});

/* ─────────────────────── 2. Dashboard opens (H6) ─────────────────────── */

describe('POST /api/parent/dashboard-opened', function () {
    it('keeps at most one open per parent per 30 minutes', function () {
        vsAt('2026-11-21 08:00:00');
        $parent = User::factory()->parent()->create();
        $other = User::factory()->parent()->create();
        vsAs($parent);

        postJson('/api/parent/dashboard-opened', ['trigger' => 'launch'])->assertNoContent();
        vsAt('2026-11-21 08:10:00');
        postJson('/api/parent/dashboard-opened', ['trigger' => 'foreground'])->assertNoContent();
        vsAt('2026-11-21 08:29:59');
        postJson('/api/parent/dashboard-opened')->assertNoContent();
        expect(ParentDashboardOpen::where('user_id', $parent->id)->count())->toBe(1);

        // Another parent has their own window.
        vsAs($other);
        postJson('/api/parent/dashboard-opened', ['trigger' => 'push'])->assertNoContent();

        vsAs($parent);
        vsAt('2026-11-21 08:30:00');
        postJson('/api/parent/dashboard-opened', ['trigger' => 'push'])->assertNoContent();

        $rows = ParentDashboardOpen::where('user_id', $parent->id)->orderBy('opened_at')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->trigger?->value)->toBe('launch')
            ->and($rows[0]->family_id)->toBe($parent->family->id)
            ->and($rows[1]->trigger?->value)->toBe('push')
            ->and(ParentDashboardOpen::where('user_id', $other->id)->count())->toBe(1);
    });

    it('stores an unknown or missing trigger as null (never a 422)', function () {
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        postJson('/api/parent/dashboard-opened', ['trigger' => 'poll'])->assertNoContent();

        expect(ParentDashboardOpen::sole()->trigger)->toBeNull();
    });

    it('is parent only', function () {
        $parent = User::factory()->parent()->create();
        vsAs(User::factory()->child()->create(['parent_id' => $parent->id]));

        postJson('/api/parent/dashboard-opened')->assertForbidden();
        expect(ParentDashboardOpen::count())->toBe(0);
    });

    it('prunes opens older than 400 days', function () {
        vsAt('2026-11-21 08:00:00');
        $parent = User::factory()->parent()->create();
        vsOpen($parent, '2025-10-16 07:59:00'); // 401 days
        vsOpen($parent, '2025-10-18 08:00:00'); // 399 days

        $this->artisan('dashboard-opens:prune')->assertSuccessful();

        expect(ParentDashboardOpen::count())->toBe(1);
    });
});

/* ─────────────────────── 3. Phase-2 fake door (H7) ─────────────────────── */

describe('POST /api/parent/phase2-interest', function () {
    it('records the first tap and moves the last one', function () {
        vsAt('2026-11-21 08:00:00');
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        postJson('/api/parent/phase2-interest/viewed')->assertNoContent();
        vsAt('2026-11-22 09:00:00');
        postJson('/api/parent/phase2-interest/viewed')->assertNoContent();

        $row = Phase2Interest::sole();
        expect($row->first_viewed_at->toDateTimeString())->toBe('2026-11-21 08:00:00')
            ->and($row->last_viewed_at->toDateTimeString())->toBe('2026-11-22 09:00:00')
            ->and($row->submitted_at)->toBeNull()
            ->and($row->family_id)->toBe($parent->family->id);
    });

    it('needs an e-mail when notify is true (422 email_required, nothing stored)', function () {
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => null])
            ->assertUnprocessable()->assertJsonPath('code', 'email_required')->assertJsonValidationErrors('email');
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => '   '])
            ->assertUnprocessable()->assertJsonPath('code', 'email_required');
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => 'not an address'])
            ->assertUnprocessable()->assertJsonPath('code', 'email_invalid');
        postJson('/api/parent/phase2-interest', ['price_choice' => 'yearly', 'notify' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('price_choice');

        expect(Phase2Interest::count())->toBe(0)->and(EmailContact::count())->toBe(0);
    });

    it('upserts one row per parent and tells the dashboard', function () {
        vsAt('2026-11-21 08:00:00');
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('phase2_interest', ['submitted' => false, 'notify' => false, 'price_choice' => null]);

        postJson('/api/parent/phase2-interest', ['price_choice' => 'monthly_2_99', 'notify' => false])
            ->assertOk()->assertExactJson(['status' => 'saved']);
        vsAt('2026-11-22 08:00:00');
        postJson('/api/parent/phase2-interest', ['price_choice' => 'monthly_5_99', 'notify' => false, 'email' => null])->assertOk();

        $row = Phase2Interest::sole();
        expect($row->price_choice?->value)->toBe('monthly_5_99')
            ->and($row->notify)->toBeFalse()
            ->and($row->submitted_at->toDateTimeString())->toBe('2026-11-22 08:00:00')
            // Submitted without a recorded tap: the first answer counts as the first view.
            ->and($row->first_viewed_at->toDateTimeString())->toBe('2026-11-21 08:00:00')
            ->and(EmailContact::count())->toBe(0);

        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('phase2_interest', ['submitted' => true, 'notify' => false, 'price_choice' => 'monthly_5_99']);
        postJson('/api/parent/phase2-interest', ['price_choice' => 'monthly_5_99', 'notify' => true, 'email' => 'mama@example.com'])->assertOk();
        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('phase2_interest', ['submitted' => true, 'notify' => true, 'price_choice' => 'monthly_5_99']);

        // Another parent of the same family has not answered.
        $second = vsSecondParent($parent);
        vsAs($second);
        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('phase2_interest.submitted', false);
    });

    it('puts the e-mail into the unified e-mail store and reuses an existing contact', function () {
        // The same address already left on the website (newsletter).
        app(EmailSignupService::class)->record([
            'email' => 'ana@example.com', 'consent_version' => '2026-11', 'purpose' => 'newsletter', 'source' => 'real_cost',
        ]);
        $parent = User::factory()->parent()->create(['email' => 'ana@example.com']);
        vsAs($parent);

        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => ' Ana@Example.com '])->assertOk();

        $contact = EmailContact::sole();
        $signup = EmailSignup::where('source', 'app_phase2')->sole();
        expect($contact->email)->toBe('ana@example.com')
            ->and($contact->signups_count)->toBe(2)
            // The website consent is never replaced by the app checkbox.
            ->and($contact->consent_version)->toBe('2026-11')
            ->and($signup->purpose)->toBe('early_access')
            ->and($signup->consent_version)->toBe(Phase2InterestService::CONSENT_VERSION)
            ->and(Phase2Interest::sole()->email_signup_id)->toBe($signup->id)
            ->and(Phase2Interest::sole()->notify)->toBeTrue();

        // The same answer again adds no second signup.
        postJson('/api/parent/phase2-interest', ['price_choice' => 'monthly_2_99', 'notify' => true, 'email' => 'ana@example.com'])->assertOk();
        expect(EmailSignup::where('source', 'app_phase2')->count())->toBe(1);
    });

    it('moves the address when it changes and removes it when notify is unticked', function () {
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => 'old@example.com'])->assertOk();
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => 'new@example.com'])->assertOk();

        expect(EmailContact::pluck('email')->all())->toBe(['new@example.com'])
            ->and(Phase2Interest::sole()->email_signup_id)->toBe(EmailSignup::sole()->id);

        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => false, 'email' => 'new@example.com'])->assertOk();

        $row = Phase2Interest::sole();
        expect(EmailContact::count())->toBe(0)
            ->and(EmailSignup::count())->toBe(0)
            ->and($row->email_signup_id)->toBeNull()
            ->and($row->notify)->toBeFalse();
    });

    it('withdraws the consent with {notify: false} alone, keeping the stored price (QA #1480 B1)', function () {
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        // No earlier answer: the price is still required.
        postJson('/api/parent/phase2-interest', ['notify' => false])->assertUnprocessable()->assertJsonValidationErrors('price_choice');

        postJson('/api/parent/phase2-interest', ['price_choice' => 'monthly_2_99', 'notify' => true, 'email' => 'mama@example.com'])->assertOk();
        // `email` is ignored when notify is false (exclude_unless), even an invalid one.
        postJson('/api/parent/phase2-interest', ['notify' => false, 'email' => 'not an address'])->assertOk()->assertExactJson(['status' => 'saved']);

        $row = Phase2Interest::sole();
        expect($row->price_choice?->value)->toBe('monthly_2_99')
            ->and($row->notify)->toBeFalse()
            ->and($row->email_signup_id)->toBeNull()
            ->and(EmailContact::count())->toBe(0);
        getJson('/api/parent/dashboard')->assertOk()
            ->assertJsonPath('phase2_interest', ['submitted' => true, 'notify' => false, 'price_choice' => 'monthly_2_99']);

        // A notify: true without a price is still a normal answer and needs the price.
        postJson('/api/parent/phase2-interest', ['notify' => true, 'email' => 'mama@example.com'])->assertUnprocessable()->assertJsonValidationErrors('price_choice');
    });

    it('removes only this parent\'s signup when two parents share one address (QA #1480 M4)', function () {
        $mama = User::factory()->parent()->create();
        $oce = vsSecondParent($mama);

        vsAs($mama);
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => 'family@example.com'])->assertOk();
        vsAs($oce);
        postJson('/api/parent/phase2-interest', ['price_choice' => 'monthly_5_99', 'notify' => true, 'email' => 'family@example.com'])->assertOk();

        $contact = EmailContact::sole();
        expect($contact->signups_count)->toBe(2)->and($contact->signups()->count())->toBe(2);

        vsAs($mama);
        postJson('/api/parent/phase2-interest', ['notify' => false])->assertOk();

        $contact->refresh();
        $oceRow = Phase2Interest::where('user_id', $oce->id)->sole();
        expect($contact->signups()->pluck('id')->all())->toBe([$oceRow->email_signup_id])
            ->and($contact->signups_count)->toBe(1);
        vsAs($oce);
        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('phase2_interest.notify', true);

        // The other parent's account deletion takes the last signup and the contact with it.
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])->assertOk();
        expect(EmailContact::count())->toBe(0)->and(EmailSignup::count())->toBe(0);
    });

    it('treats a consent whose signup is gone (admin delete on request) as no consent everywhere (m4)', function () {
        vsAt('2026-11-21 08:00:00');
        $parent = User::factory()->parent()->create();
        vsAs($parent);
        postJson('/api/parent/phase2-interest/viewed')->assertNoContent();
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => 'gone@example.com'])->assertOk();
        expect(app(FunnelService::class)->phase2(12)['notify'])->toBe(1);

        EmailContact::where('email', 'gone@example.com')->delete(); // /admin → E-mails → Delete

        expect(Phase2Interest::sole()->notify)->toBeTrue(); // the stored flag is untouched …
        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('phase2_interest.notify', false); // … but never exposed
        getJson('/api/parent/account/export')->assertOk()
            ->assertJsonPath('parents.0.phase2_interest.notify', false)
            ->assertJsonPath('parents.0.phase2_interest.notify_email', null);
        expect(app(FunnelService::class)->phase2(12)['notify'])->toBe(0);
    });

    it('is parent only', function () {
        $parent = User::factory()->parent()->create();
        vsAs(User::factory()->child()->create(['parent_id' => $parent->id]));

        postJson('/api/parent/phase2-interest/viewed')->assertForbidden();
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => false])->assertForbidden();
        expect(Phase2Interest::count())->toBe(0);
    });
});

/* ─────────────────────── 4. Export and deletion ─────────────────────── */

describe('GDPR export and account deletion', function () {
    it('exports the acquisition answer, the dashboard opens and the Phase-2 answer of each parent', function () {
        vsAt('2026-11-21 08:00:00');
        $parent = User::factory()->parent()->create();
        vsAs($parent);
        patchJson('/api/parent/settings', ['acquisition_source' => 'ad', 'acquisition_detail' => 'JESEN-26'])->assertOk();
        postJson('/api/parent/dashboard-opened', ['trigger' => 'launch'])->assertNoContent();
        postJson('/api/parent/phase2-interest/viewed')->assertNoContent();
        postJson('/api/parent/phase2-interest', ['price_choice' => 'monthly_2_99', 'notify' => true, 'email' => 'notify@example.com'])->assertOk();

        getJson('/api/parent/account/export')->assertOk()
            ->assertJsonPath('parents.0.acquisition.source', 'ad')
            ->assertJsonPath('parents.0.acquisition.detail', 'JESEN-26')
            ->assertJsonPath('parents.0.acquisition.recorded_at', '2026-11-21T08:00:00+00:00')
            ->assertJsonPath('parents.0.dashboard_opens', [['opened_at' => '2026-11-21T08:00:00+00:00', 'trigger' => 'launch']])
            ->assertJsonPath('parents.0.phase2_interest.price_choice', 'monthly_2_99')
            ->assertJsonPath('parents.0.phase2_interest.notify', true)
            ->assertJsonPath('parents.0.phase2_interest.notify_email', 'notify@example.com')
            ->assertJsonPath('parents.0.phase2_interest.submitted_at', '2026-11-21T08:00:00+00:00');
    });

    it('exports nulls / empty lists for a parent without answers', function () {
        $parent = User::factory()->parent()->create();
        vsAs($parent);

        getJson('/api/parent/account/export')->assertOk()
            ->assertJsonPath('parents.0.acquisition', null)
            ->assertJsonPath('parents.0.dashboard_opens', [])
            ->assertJsonPath('parents.0.phase2_interest', null);
    });

    it('deletes the rows and the app e-mail with the account; website signups of the address stay', function () {
        $parent = User::factory()->parent()->create(['email' => 'mama@example.com']);
        $keeper = User::factory()->parent()->create();
        // The address the parent leaves in the app was also used on the website.
        app(EmailSignupService::class)->record([
            'email' => 'shared@example.com', 'consent_version' => '2026-11', 'purpose' => 'newsletter', 'source' => 'breed_quiz',
        ]);

        vsAs($parent);
        postJson('/api/parent/dashboard-opened')->assertNoContent();
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => 'shared@example.com'])->assertOk();
        vsAs($keeper);
        postJson('/api/parent/dashboard-opened')->assertNoContent();
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => 'only-app@example.com'])->assertOk();

        vsAs($parent);
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])->assertOk();

        $shared = EmailContact::where('email', 'shared@example.com')->sole();
        expect(ParentDashboardOpen::where('user_id', $parent->id)->count())->toBe(0)
            ->and(Phase2Interest::where('user_id', $parent->id)->count())->toBe(0)
            ->and($shared->signups()->pluck('source')->all())->toBe(['breed_quiz'])
            ->and($shared->signups_count)->toBe(1)
            // Another family's rows are untouched.
            ->and(ParentDashboardOpen::where('user_id', $keeper->id)->count())->toBe(1)
            ->and(EmailContact::where('email', 'only-app@example.com')->exists())->toBeTrue();

        // An address that was only in the app goes with its account.
        vsAs($keeper);
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])->assertOk();
        expect(EmailContact::where('email', 'only-app@example.com')->exists())->toBeFalse()
            ->and(Phase2Interest::count())->toBe(0)
            ->and(ParentDashboardOpen::count())->toBe(0);
    });
});

/* ─────────────────────── 5. Funnel ─────────────────────── */

describe('funnel additions', function () {
    beforeEach(function () {
        seedBreedConfigs();
    });

    it('breaks the steps down by acquisition source with an unknown row', function () {
        vsAt('2026-11-02 08:00:00');
        [$adParent] = vsFamilyWithPet();
        $adParent->forceFill(['acquisition_source' => 'ad', 'acquisition_recorded_at' => now()])->save();
        $insta = User::factory()->parent()->create();
        $insta->forceFill(['acquisition_source' => 'instagram', 'acquisition_recorded_at' => now()])->save();
        $insta2 = User::factory()->parent()->create();
        $insta2->forceFill(['acquisition_source' => 'instagram', 'acquisition_recorded_at' => now()])->save();
        User::factory()->parent()->create(); // no answer
        User::factory()->create(['role' => 'parent', 'is_superadmin' => true, 'acquisition_source' => 'ad']); // never counted

        vsAt('2026-11-21 12:00:00');
        $rows = collect(app(FunnelService::class)->sources(12))->keyBy('source');

        expect($rows->keys()->all())->toBe(['instagram', 'ad', 'unknown'])
            ->and($rows['ad']['registered'])->toBe(1)
            ->and($rows['ad']['pet_born'])->toBe(1)
            ->and($rows['instagram']['registered'])->toBe(2)
            ->and($rows['instagram']['pet_born'])->toBe(0)
            ->and($rows['unknown']['registered'])->toBe(1);
    });

    it('counts H6 = one parent with ≥ 3 opens in days 8–14 after the first birth, out of eligible families', function () {
        vsAt('2026-11-01 10:00:00');
        [$hit] = vsFamilyWithPet();                 // 3 opens on days 8–14 → counts
        [$early] = vsFamilyWithPet();               // 3 opens, but one on day 7 → no
        [$split] = vsFamilyWithPet();  // 2 + 2 opens by two parents → no (per parent)
        $splitSecond = vsSecondParent($split);

        foreach (['2026-11-08 10:00:00', '2026-11-11 10:00:00', '2026-11-15 09:59:00'] as $at) {
            vsOpen($hit, $at);
        }
        foreach (['2026-11-08 09:59:00', '2026-11-09 10:00:00', '2026-11-10 10:00:00'] as $at) {
            vsOpen($early, $at);
        }
        foreach (['2026-11-09 10:00:00', '2026-11-10 10:00:00'] as $at) {
            vsOpen($split, $at);
            DB::table('parent_dashboard_opens')->insert(['user_id' => $splitSecond->id, 'family_id' => $split->family->id, 'opened_at' => Carbon::parse($at)->addHour(), 'trigger' => null]);
        }

        // A family born 10 days before "now" is not eligible yet.
        vsAt('2026-11-12 10:00:00');
        vsFamilyWithPet();

        vsAt('2026-11-22 10:00:00');
        $total = app(FunnelService::class)->cohorts(12)['total'];

        expect($total['h6_eligible'])->toBe(3)
            ->and($total['h6'])->toBe(1);
    });

    it('counts the Phase-2 taps, answers, notify share and the price split', function () {
        vsAt('2026-11-21 08:00:00');
        $parents = User::factory()->parent()->count(4)->create();
        $svc = app(Phase2InterestService::class);
        foreach ($parents as $p) {
            $svc->viewed($p);
        }
        $svc->submit($parents[0], Phase2PriceChoice::Monthly299, true, 'a@example.com');
        $svc->submit($parents[1], Phase2PriceChoice::Free, false, null);
        $svc->submit($parents[2], Phase2PriceChoice::Monthly299, true, 'c@example.com');
        // A superadmin's answer is never counted.
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $svc->viewed($admin);

        expect(app(FunnelService::class)->phase2(12))->toBe([
            'viewed' => 4,
            'submitted' => 3,
            'notify' => 2,
            'prices' => ['free' => 1, 'monthly_2_99' => 2, 'monthly_5_99' => 0],
        ]);
    });

    it('shows the H6 column, the source table and the Phase-2 block on /admin/funnel (en + sl)', function () {
        vsAt('2026-11-21 12:00:00');
        $parent = User::factory()->parent()->create();
        $parent->forceFill(['acquisition_source' => 'school_kindergarten', 'acquisition_recorded_at' => now()])->save();
        app(Phase2InterestService::class)->viewed($parent);
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);

        $this->actingAs($admin)->get('/admin/funnel')->assertOk()
            ->assertSee('H6: parent opens 3+')
            ->assertSee('By acquisition source')
            ->assertSee('School / kindergarten')
            ->assertSee('We got a real pet');
        $this->withCookie(SetAdminLocale::COOKIE, 'sl')->get('/admin/funnel')->assertOk()
            ->assertSee('Po viru prihoda')
            ->assertSee('Šola / vrtec')
            ->assertSee('Dobili smo pravo žival');

        Livewire::test(Funnel::class)->assertOk();
    });
});
