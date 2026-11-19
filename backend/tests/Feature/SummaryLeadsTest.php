<?php

use App\Models\EmailContact;
use App\Models\EmailSignup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| M6-S05 — parent e-mails from the page »Koliko dela je pes« (THREEJS T9)
|--------------------------------------------------------------------------
|
| POST /api/summary-leads: public, 10 / hour per IP, honeypot `website`,
| consent required, kept for old cached pages since M6-S09: it writes into
| the unified email_contacts / email_signups (ONE contact per e-mail, one
| `newsletter` signup per call), no e-mail sent, no IP stored. The migration
| of the old table: EmailSignupsTest. /admin: EmailSignupsAdminTest.
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

        $contact = EmailContact::sole();
        $signup = EmailSignup::sole();
        expect($contact->email)->toBe('mama.testna@example.test')
            ->and($contact->consent_version)->toBe('2026-11')
            ->and($contact->consented_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00')
            ->and($contact->signups_count)->toBe(1)
            ->and($contact->first_seen_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00')
            ->and($contact->last_seen_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00')
            ->and($contact->unsubscribed_at)->toBeNull()
            ->and($contact->provider_synced_at)->toBeNull()
            ->and($signup->email_contact_id)->toBe($contact->id)
            ->and($signup->purpose)->toBe('newsletter')
            ->and($signup->source)->toBe('real_cost')
            ->and($signup->page_path)->toBeNull()
            ->and($signup->locale)->toBe('sl')
            ->and($signup->breed)->toBe('labrador_retriever')
            ->and($signup->stage)->toBe('adult')
            ->and($signup->created_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00');
    });

    it('accepts the breed_quiz source (M6-S06) with the first breed of the quiz result', function () {
        $this->postJson('/api/summary-leads', slBody(['source' => 'breed_quiz', 'breed' => 'beagle', 'stage' => null]))
            ->assertCreated()->assertExactJson(['ok' => true]);

        $row = EmailSignup::sole();
        expect($row->source)->toBe('breed_quiz')->and($row->breed)->toBe('beagle')->and($row->purpose)->toBe('newsletter');
    });

    it('accepts an absent locale, breed, stage and honeypot', function () {
        $body = slBody();
        unset($body['locale'], $body['breed'], $body['stage'], $body['website']);

        $this->postJson('/api/summary-leads', $body)->assertCreated();

        $row = EmailSignup::sole();
        expect($row->locale)->toBeNull()->and($row->breed)->toBeNull()->and($row->stage)->toBeNull();
    });

    it('keeps ONE contact per e-mail: a repeat visit adds a signup row and answers the same', function () {
        $this->postJson('/api/summary-leads', slBody())->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-12-01 08:30:00', 'UTC'));
        $this->postJson('/api/summary-leads', slBody(['breed' => 'beagle', 'stage' => 'puppy', 'locale' => 'en']))
            ->assertCreated()
            ->assertExactJson(['ok' => true]);

        $contact = EmailContact::sole();
        expect($contact->signups_count)->toBe(2)
            ->and($contact->last_seen_at->toIso8601String())->toBe('2026-12-01T08:30:00+00:00')
            // the first consent and the first visit stay as they were
            ->and($contact->consented_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00')
            ->and($contact->first_seen_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00')
            ->and($contact->created_at->toIso8601String())->toBe('2026-11-15T10:00:00+00:00');

        $signups = $contact->signups;
        expect($signups)->toHaveCount(2)
            ->and($signups->first()->breed)->toBe('labrador_retriever')
            ->and($signups->last()->breed)->toBe('beagle')
            ->and($signups->last()->stage)->toBe('puppy')
            ->and($signups->last()->locale)->toBe('en');
    });

    it('treats letter case and surrounding spaces as the same e-mail', function () {
        $this->postJson('/api/summary-leads', slBody(['email' => 'Mama.Testna@Example.TEST']))->assertCreated();
        $this->postJson('/api/summary-leads', slBody(['email' => '  mama.testna@example.test ']))->assertCreated();
        $this->postJson('/api/summary-leads', slBody(['email' => 'MAMA.TESTNA@EXAMPLE.TEST']))->assertCreated();

        $row = EmailContact::sole();
        expect($row->email)->toBe('mama.testna@example.test')->and($row->signups_count)->toBe(3);
    });

    it('keeps the stored consent unless a NEWER consent version is accepted', function () {
        // A row from before the current sentence existed (unknown / older version).
        EmailContact::factory()->create(['email' => 'stara@example.test', 'consent_version' => '2025-01', 'consented_at' => now()->subYear()]);
        // A row with the current version.
        EmailContact::factory()->create(['email' => 'nova@example.test', 'consent_version' => '2026-11', 'consented_at' => now()->subMonth()]);

        $this->postJson('/api/summary-leads', slBody(['email' => 'stara@example.test']))->assertCreated();
        $this->postJson('/api/summary-leads', slBody(['email' => 'nova@example.test']))->assertCreated();

        $old = EmailContact::where('email', 'stara@example.test')->sole();
        $new = EmailContact::where('email', 'nova@example.test')->sole();
        expect($old->consent_version)->toBe('2026-11')
            ->and($old->consented_at->toDateTimeString())->toBe('2026-11-15 10:00:00')
            ->and($new->consent_version)->toBe('2026-11')
            ->and($new->consented_at->toDateTimeString())->toBe('2026-10-15 10:00:00');
    });

    it('a different e-mail is a second contact', function () {
        $this->postJson('/api/summary-leads', slBody())->assertCreated();
        $this->postJson('/api/summary-leads', slBody(['email' => 'oce.testni@example.test']))->assertCreated();

        expect(EmailContact::count())->toBe(2);
    });

    it('stores nothing about the sender (no IP, no user agent, no name column) and sends nothing', function () {
        Queue::fake();
        $this->postJson('/api/summary-leads', slBody())->assertCreated();

        expect(Schema::hasTable('summary_leads'))->toBeFalse();
        expect(Schema::getColumnListing('email_contacts'))
            ->toBe(['id', 'email', 'first_seen_at', 'last_seen_at', 'signups_count', 'consent_version', 'consented_at', 'unsubscribed_at', 'provider_synced_at', 'created_at', 'updated_at']);
        expect(Schema::getColumnListing('email_signups'))
            ->toBe(['id', 'email_contact_id', 'purpose', 'source', 'page_path', 'section', 'locale', 'species', 'breed', 'stage', 'quiz_result', 'quiz_answers', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer_host', 'consent_version', 'created_at']);
        Queue::assertNothingPushed();
    });

    it('answers the standard Laravel 422 for an invalid body and stores nothing', function (array $override, string $field) {
        $this->postJson('/api/summary-leads', slBody($override))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(EmailContact::count())->toBe(0)->and(EmailSignup::count())->toBe(0);
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
        'the early-access consent version is not for the old endpoint' => [['consent_version' => 'ea-2026-11'], 'consent_version'],
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

        expect(EmailContact::count())->toBe(2);
        // the old slug style with hyphens is stored as the underscore breed type id
        expect(EmailSignup::where('breed', 'cavalier_king_charles_spaniel')->count())->toBe(1);
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

        expect(EmailContact::count())->toBe(0);
    });

    it('is throttled to 10 per hour per IP (429), another IP is not', function () {
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->postJson('/api/summary-leads', slBody(['email' => "t{$i}@example.test"]))->assertCreated();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/summary-leads', slBody())->assertStatus(429);
        expect(EmailContact::count())->toBe(10);

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
