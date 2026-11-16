<?php

use App\Filament\Resources\SummaryLeadResource;
use App\Filament\Resources\SummaryLeadResource\Pages\ListSummaryLeads;
use App\Models\SummaryLead;
use App\Models\User;
use App\Services\GameDataResetService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M6-S05 — parent e-mails from the page »Koliko dela je pes« (THREEJS T9)
|--------------------------------------------------------------------------
|
| POST /api/summary-leads: public, 10 / hour per IP, honeypot `website`,
| consent required, ONE row per e-mail (a repeat visit updates it), no e-mail
| sent, no IP stored, /admin list + delete (superadmin), never deleted
| automatically (David 2026-11-15).
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
    Http::preventStrayRequests();
});

/** @return array<string, mixed> */
function slBody(array $overrides = []): array
{
    return array_merge([
        'email' => 'mama.testna@example.test',
        'consent' => true,
        'consent_version' => '2026-11',
        'source' => 'real_cost',
        'locale' => 'sl',
        'breed' => 'labrador_retriever',
        'stage' => 'adult',
        'website' => '',
    ], $overrides);
}

function slAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

describe('POST /api/summary-leads', function () {
    it('stores the e-mail with consent and answers 201 {ok: true} without a token', function () {
        $this->postJson('/api/summary-leads', slBody())
            ->assertCreated()
            ->assertExactJson(['ok' => true]);

        $row = SummaryLead::sole();
        expect($row->email)->toBe('mama.testna@example.test')
            ->and($row->consent_version)->toBe('2026-11')
            ->and($row->consented_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00')
            ->and($row->source)->toBe('real_cost')
            ->and($row->locale)->toBe('sl')
            ->and($row->breed)->toBe('labrador_retriever')
            ->and($row->stage)->toBe('adult')
            ->and($row->visits)->toBe(1)
            ->and($row->last_seen_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00');
    });

    it('accepts the breed_quiz source (M6-S06) with the first breed of the quiz result', function () {
        $this->postJson('/api/summary-leads', slBody(['source' => 'breed_quiz', 'breed' => 'beagle', 'stage' => null]))
            ->assertCreated()->assertExactJson(['ok' => true]);

        $row = SummaryLead::sole();
        expect($row->source)->toBe('breed_quiz')->and($row->breed)->toBe('beagle');
    });

    it('accepts an absent locale, breed, stage and honeypot', function () {
        $body = slBody();
        unset($body['locale'], $body['breed'], $body['stage'], $body['website']);

        $this->postJson('/api/summary-leads', $body)->assertCreated();

        $row = SummaryLead::sole();
        expect($row->locale)->toBeNull()->and($row->breed)->toBeNull()->and($row->stage)->toBeNull();
    });

    it('keeps ONE row per e-mail: a repeat visit updates it and answers the same', function () {
        $this->postJson('/api/summary-leads', slBody())->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-12-01 08:30:00', 'UTC'));
        $this->postJson('/api/summary-leads', slBody(['breed' => 'beagle', 'stage' => 'puppy', 'locale' => 'en']))
            ->assertCreated()
            ->assertExactJson(['ok' => true]);

        $row = SummaryLead::sole();
        expect($row->visits)->toBe(2)
            ->and($row->breed)->toBe('beagle')
            ->and($row->stage)->toBe('puppy')
            ->and($row->locale)->toBe('en')
            ->and($row->last_seen_at->toIso8601String())->toBe('2026-12-01T08:30:00+00:00')
            // the first consent and the first visit stay as they were
            ->and($row->consented_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00')
            ->and($row->created_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00');
    });

    it('treats letter case and surrounding spaces as the same e-mail', function () {
        $this->postJson('/api/summary-leads', slBody(['email' => 'Mama.Testna@Example.TEST']))->assertCreated();
        $this->postJson('/api/summary-leads', slBody(['email' => '  mama.testna@example.test ']))->assertCreated();
        $this->postJson('/api/summary-leads', slBody(['email' => 'MAMA.TESTNA@EXAMPLE.TEST']))->assertCreated();

        $row = SummaryLead::sole();
        expect($row->email)->toBe('mama.testna@example.test')->and($row->visits)->toBe(3);
    });

    it('a repeat visit without breed / stage / locale keeps what the earlier visit stored', function () {
        $this->postJson('/api/summary-leads', slBody())->assertCreated();
        $body = slBody();
        unset($body['locale'], $body['breed'], $body['stage']);
        $this->postJson('/api/summary-leads', $body)->assertCreated();

        $row = SummaryLead::sole();
        expect($row->breed)->toBe('labrador_retriever')->and($row->stage)->toBe('adult')->and($row->locale)->toBe('sl')->and($row->visits)->toBe(2);
    });

    it('keeps the stored consent unless a NEWER consent version is accepted', function () {
        // A row from before the current sentence existed (unknown / older version).
        SummaryLead::factory()->create(['email' => 'stara@example.test', 'consent_version' => '2025-01', 'consented_at' => now()->subYear()]);
        // A row with the current version.
        SummaryLead::factory()->create(['email' => 'nova@example.test', 'consent_version' => '2026-11', 'consented_at' => now()->subMonth()]);

        $this->postJson('/api/summary-leads', slBody(['email' => 'stara@example.test']))->assertCreated();
        $this->postJson('/api/summary-leads', slBody(['email' => 'nova@example.test']))->assertCreated();

        $old = SummaryLead::where('email', 'stara@example.test')->sole();
        $new = SummaryLead::where('email', 'nova@example.test')->sole();
        expect($old->consent_version)->toBe('2026-11')
            ->and($old->consented_at->toDateTimeString())->toBe('2026-11-15 10:00:00')
            ->and($new->consent_version)->toBe('2026-11')
            ->and($new->consented_at->toDateTimeString())->toBe('2026-10-15 10:00:00');
    });

    it('a different e-mail is a second row', function () {
        $this->postJson('/api/summary-leads', slBody())->assertCreated();
        $this->postJson('/api/summary-leads', slBody(['email' => 'oce.testni@example.test']))->assertCreated();

        expect(SummaryLead::count())->toBe(2);
    });

    it('stores nothing about the sender (no IP, no name column) and sends nothing', function () {
        Queue::fake();
        $this->postJson('/api/summary-leads', slBody())->assertCreated();

        expect(Schema::getColumnListing('summary_leads'))
            ->toBe(['id', 'email', 'consent_version', 'consented_at', 'source', 'locale', 'breed', 'stage', 'visits', 'last_seen_at', 'created_at', 'updated_at']);
        Queue::assertNothingPushed();
    });

    it('answers the standard Laravel 422 for an invalid body and stores nothing', function (array $override, string $field) {
        $this->postJson('/api/summary-leads', slBody($override))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(SummaryLead::count())->toBe(0);
    })->with([
        'no email' => [['email' => ''], 'email'],
        'bad email' => [['email' => 'not-an-email'], 'email'],
        'email too long' => [['email' => str_repeat('a', 186).'@x.si'], 'email'],
        'email with a NUL byte' => [['email' => "a\0b@example.test"], 'email'],
        'email with a quoted local part and a space' => [['email' => '"a b"@example.test'], 'email'],
        'email with non-ASCII letters' => [['email' => 'žan@example.test'], 'email'],
        'email with a space inside' => [['email' => 'a b@example.test'], 'email'],
        'consent missing' => [['consent' => null], 'consent'],
        'consent not given' => [['consent' => false], 'consent'],
        'unknown consent version' => [['consent_version' => '1999-01'], 'consent_version'],
        'no consent version' => [['consent_version' => null], 'consent_version'],
        'unknown source' => [['source' => 'newsletter'], 'source'],
        'no source' => [['source' => null], 'source'],
        'locale not two letters' => [['locale' => 'slo'], 'locale'],
        'locale upper case' => [['locale' => 'SL'], 'locale'],
        'breed with spaces' => [['breed' => 'labrador retriever'], 'breed'],
        'breed too long' => [['breed' => str_repeat('a', 65)], 'breed'],
        'breed with markup' => [['breed' => '<script>'], 'breed'],
        'unknown stage' => [['stage' => 'senior'], 'stage'],
    ]);

    it('accepts values exactly at the limits and both breed slug styles', function () {
        $this->postJson('/api/summary-leads', slBody([
            'email' => str_repeat('c', 64).'@'.str_repeat('d', 60).'.example.test',
            'breed' => str_repeat('a', 64),
        ]))->assertCreated();
        $this->postJson('/api/summary-leads', slBody(['email' => 'b@example.test', 'breed' => 'cavalier-king-charles-spaniel']))->assertCreated();

        expect(SummaryLead::count())->toBe(2);
    });

    it('honeypot: a filled `website` gets 201 {ok: true} and nothing is stored', function () {
        $this->postJson('/api/summary-leads', slBody(['website' => 'https://spam.example']))
            ->assertCreated()->assertExactJson(['ok' => true]);
        // Even with an otherwise invalid body (a bot learns nothing from a 422).
        $this->postJson('/api/summary-leads', ['website' => 'x'])
            ->assertCreated()->assertExactJson(['ok' => true]);
        // A non-string value counts as filled too.
        $this->postJson('/api/summary-leads', slBody(['website' => ['x']]))
            ->assertCreated()->assertExactJson(['ok' => true]);

        expect(SummaryLead::count())->toBe(0);
    });

    it('is throttled to 10 per hour per IP (429), another IP is not', function () {
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->postJson('/api/summary-leads', slBody(['email' => "t{$i}@example.test"]))->assertCreated();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/summary-leads', slBody())->assertStatus(429);
        expect(SummaryLead::count())->toBe(10);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/summary-leads', slBody())->assertCreated();
    });

    it('allows the petprep.si origin (CORS preflight and the real response)', function () {
        $pre = $this->call('OPTIONS', '/api/summary-leads', [], [], [], [
            'HTTP_ORIGIN' => 'https://petprep.si',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
        expect($pre->headers->get('Access-Control-Allow-Origin'))->toBeIn(['*', 'https://petprep.si']);

        $res = $this->withHeaders(['Origin' => 'https://petprep.si'])->postJson('/api/summary-leads', slBody());
        $res->assertCreated();
        expect($res->headers->get('Access-Control-Allow-Origin'))->toBeIn(['*', 'https://petprep.si']);
    });
});

describe('database', function () {
    it('refuses a second row with the same e-mail and an upper-case e-mail', function () {
        SummaryLead::factory()->create(['email' => 'a@example.test']);

        expect(fn () => SummaryLead::factory()->create(['email' => 'a@example.test']))->toThrow(UniqueConstraintViolationException::class);
        expect(fn () => SummaryLead::factory()->create(['email' => 'B@example.test']))->toThrow(QueryException::class);
        expect(fn () => SummaryLead::factory()->create(['locale' => 'SLO']))->toThrow(QueryException::class);
    });

    it('is kept by the game data reset', function () {
        expect(GameDataResetService::KEEP)->toContain('summary_leads');
    });
});

describe('retention', function () {
    it('never deletes rows automatically: no prune command and nothing scheduled', function () {
        SummaryLead::factory()->create(['created_at' => now()->subYears(6), 'last_seen_at' => now()->subYears(5)]);

        expect(array_keys(Artisan::all()))->not->toContain('summary-leads:prune');
        $scheduled = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'summary-leads'));
        expect($scheduled)->toBeEmpty();
        expect(SummaryLead::count())->toBe(1);
    });
});

describe('/admin', function () {
    it('lists the rows for a superadmin and lets them delete one', function () {
        $lead = SummaryLead::factory()->create(['email' => 'vidna@example.test', 'breed' => 'beagle']);
        $this->actingAs(slAdmin());

        Livewire::test(ListSummaryLeads::class)
            ->assertCanSeeTableRecords([$lead])
            ->callTableAction('delete', $lead);

        expect(SummaryLead::count())->toBe(0);
    });

    it('bulk delete removes the selected rows', function () {
        $leads = SummaryLead::factory()->count(3)->create();
        $this->actingAs(slAdmin());

        Livewire::test(ListSummaryLeads::class)->callTableBulkAction('delete', $leads->take(2));

        expect(SummaryLead::count())->toBe(1);
    });

    it('refuses everyone but a superadmin on the route', function () {
        $this->get('/admin/summary-leads')->assertRedirect();

        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => false]));
        $this->get('/admin/summary-leads')->assertForbidden();

        $this->actingAs(slAdmin());
        $this->get('/admin/summary-leads')->assertOk();
    });

    it('is closed to a parent who is not a superadmin and has no create or edit', function () {
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => false]));
        expect(SummaryLeadResource::canAccess())->toBeFalse()->and(SummaryLeadResource::canViewAny())->toBeFalse();

        $this->actingAs(slAdmin());
        expect(SummaryLeadResource::canAccess())->toBeTrue()
            ->and(SummaryLeadResource::canCreate())->toBeFalse()
            ->and(SummaryLeadResource::canEdit(SummaryLead::factory()->create()))->toBeFalse();
    });
});
