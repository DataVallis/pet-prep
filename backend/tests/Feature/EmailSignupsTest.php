<?php

use App\Models\EmailContact;
use App\Models\EmailSignup;
use App\Services\GameDataResetService;
use App\Support\WebsiteLocale;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| M6-S09 — every e-mail left on petprep.si in ONE segmented store
|--------------------------------------------------------------------------
|
| POST /api/email-signups: public, 20 / hour per IP, honeypot `website` → 204,
| consent required, ONE contact per e-mail + one signup row per submission
| (source, purpose, page, section, locale, species, breed, stage, quiz, UTM,
| referrer host), no IP / user agent / name stored, no e-mail sent, never
| deleted automatically (David 2026-11-15). The summary_leads migration is
| tested here too; /admin in EmailSignupsAdminTest.
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'UTC'));
    Http::preventStrayRequests();
});

/** @return array<string, mixed> */
function esBody(array $overrides = []): array
{
    return array_merge([
        'email' => 'mama.testna@example.test',
        'consent' => true,
        'consent_version' => '2026-11',
        'purpose' => 'newsletter',
        'source' => 'real_cost',
        'page_path' => '/sl/koliko-dela-je-z-labradorcem',
        'locale' => 'sl',
        'website' => '',
    ], $overrides);
}

/** @return array<string, mixed> */
function esFullBody(array $overrides = []): array
{
    return esBody(array_merge([
        'source' => 'breed_quiz',
        'section' => 'result',
        'species' => 'dog',
        'breed' => 'french_bulldog',
        'stage' => 'puppy',
        'quiz' => ['result' => ['french_bulldog', 'pug', 'beagle'], 'answers' => '012301230'],
        'utm' => ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'launch', 'content' => 'hero', 'term' => 'pes'],
        'referrer_host' => 'Google.com',
    ], $overrides));
}

describe('POST /api/email-signups', function () {
    it('stores the contact and the signup with every field and answers 204 without a token', function () {
        $this->postJson('/api/email-signups', esFullBody())->assertNoContent();

        $contact = EmailContact::sole();
        expect($contact->email)->toBe('mama.testna@example.test')
            ->and($contact->consent_version)->toBe('2026-11')
            ->and($contact->consented_at->toIso8601String())->toBe('2026-11-17T10:00:00+00:00')
            ->and($contact->first_seen_at->toIso8601String())->toBe('2026-11-17T10:00:00+00:00')
            ->and($contact->last_seen_at->toIso8601String())->toBe('2026-11-17T10:00:00+00:00')
            ->and($contact->signups_count)->toBe(1)
            ->and($contact->unsubscribed_at)->toBeNull()
            ->and($contact->provider_synced_at)->toBeNull();

        $s = EmailSignup::sole();
        expect($s->email_contact_id)->toBe($contact->id)
            ->and($s->purpose)->toBe('newsletter')
            ->and($s->source)->toBe('breed_quiz')
            ->and($s->page_path)->toBe('/sl/koliko-dela-je-z-labradorcem')
            ->and($s->section)->toBe('result')
            ->and($s->locale)->toBe('sl')
            ->and($s->species)->toBe('dog')
            ->and($s->breed)->toBe('french_bulldog')
            ->and($s->stage)->toBe('puppy')
            ->and($s->quiz_result)->toBe(['french_bulldog', 'pug', 'beagle'])
            ->and($s->quiz_answers)->toBe('012301230')
            ->and([$s->utm_source, $s->utm_medium, $s->utm_campaign, $s->utm_content, $s->utm_term])->toBe(['newsletter', 'email', 'launch', 'hero', 'pes'])
            ->and($s->referrer_host)->toBe('google.com')
            ->and($s->consent_version)->toBe('2026-11')
            ->and($s->created_at->toIso8601String())->toBe('2026-11-17T10:00:00+00:00');
    });

    it('accepts the minimal body: every optional field stays null', function () {
        $body = esBody();
        unset($body['website']);
        $this->postJson('/api/email-signups', $body)->assertNoContent();

        $s = EmailSignup::sole();
        expect([$s->section, $s->species, $s->breed, $s->stage, $s->quiz_result, $s->quiz_answers, $s->utm_source, $s->referrer_host])
            ->each->toBeNull();
    });

    it('accepts both purposes and a source the server has not heard of yet', function () {
        $this->postJson('/api/email-signups', esBody(['purpose' => 'early_access', 'source' => 'early_access_form', 'email' => 'a@example.test']))->assertNoContent();
        $this->postJson('/api/email-signups', esBody(['source' => 'new_widget_2', 'email' => 'b@example.test']))->assertNoContent();

        expect(EmailSignup::orderBy('id')->pluck('source', 'purpose')->all())->toBe(['early_access' => 'early_access_form', 'newsletter' => 'new_widget_2']);
    });

    it('accepts every website language', function () {
        foreach (WebsiteLocale::ALL as $i => $locale) {
            $this->postJson('/api/email-signups', esBody(['locale' => $locale, 'email' => "l{$i}@example.test"]))->assertNoContent();
        }

        expect(EmailSignup::count())->toBe(count(WebsiteLocale::ALL));
    });

    it('keeps ONE contact per e-mail: a repeat adds a signup row, bumps the counter and answers the same', function () {
        $this->postJson('/api/email-signups', esBody())->assertNoContent();

        Carbon::setTestNow(Carbon::parse('2026-12-01 08:30:00', 'UTC'));
        $this->postJson('/api/email-signups', esBody(['source' => 'breed_quiz', 'purpose' => 'early_access', 'breed' => 'beagle']))->assertNoContent();

        $contact = EmailContact::sole();
        expect($contact->signups_count)->toBe(2)
            ->and($contact->first_seen_at->toIso8601String())->toBe('2026-11-17T10:00:00+00:00')
            ->and($contact->last_seen_at->toIso8601String())->toBe('2026-12-01T08:30:00+00:00')
            ->and($contact->consented_at->toIso8601String())->toBe('2026-11-17T10:00:00+00:00');
        expect($contact->signups->pluck('source')->all())->toBe(['real_cost', 'breed_quiz'])
            ->and($contact->latestSignup()->breed)->toBe('beagle');
    });

    it('treats letter case and surrounding spaces as the same e-mail', function () {
        $this->postJson('/api/email-signups', esBody(['email' => 'Mama.Testna@Example.TEST']))->assertNoContent();
        $this->postJson('/api/email-signups', esBody(['email' => '  mama.testna@example.test ']))->assertNoContent();

        $contact = EmailContact::sole();
        expect($contact->email)->toBe('mama.testna@example.test')->and($contact->signups_count)->toBe(2)->and(EmailSignup::count())->toBe(2);
    });

    it('keeps the stored consent unless a NEWER consent version is accepted', function () {
        EmailContact::factory()->create(['email' => 'stara@example.test', 'consent_version' => '2025-01', 'consented_at' => now()->subYear()]);

        $this->postJson('/api/email-signups', esBody(['email' => 'stara@example.test']))->assertNoContent();

        $old = EmailContact::where('email', 'stara@example.test')->sole();
        expect($old->consent_version)->toBe('2026-11')->and($old->consented_at->toDateTimeString())->toBe('2026-11-17 10:00:00');
    });

    it('a repeat signup never clears an unsubscribe (QA #1316 / #1418)', function () {
        EmailContact::factory()->create(['email' => 'odjava@example.test', 'unsubscribed_at' => now()->subDay()]);

        $this->postJson('/api/email-signups', esBody(['email' => 'odjava@example.test']))->assertNoContent();

        expect(EmailContact::where('email', 'odjava@example.test')->sole()->unsubscribed_at)->not->toBeNull();
    });

    it('accepts the early-access consent version ea-2026-11 and keeps it per signup', function () {
        $this->postJson('/api/email-signups', esBody(['consent_version' => 'ea-2026-11', 'purpose' => 'early_access', 'source' => 'early_access_form', 'section' => 'home_cta']))->assertNoContent();

        expect(EmailContact::sole()->consent_version)->toBe('ea-2026-11')
            ->and(EmailSignup::sole()->consent_version)->toBe('ea-2026-11')->and(EmailSignup::sole()->section)->toBe('home_cta');

        // a later newsletter signup keeps its own version on the row; the contact keeps the highest-ranked one
        $this->postJson('/api/email-signups', esBody(['consent_version' => '2026-11']))->assertNoContent();
        expect(EmailSignup::orderBy('id')->pluck('consent_version')->all())->toBe(['ea-2026-11', '2026-11']);
    });

    it('accepts every section value the website sends', function () {
        foreach (['step5', 'result', 'hero', 'home_cta', 'breed_cta', 'care_cta', 'page_cta'] as $i => $section) {
            $this->postJson('/api/email-signups', esBody(['section' => $section, 'email' => "s{$i}@example.test"]))->assertNoContent();
        }
        expect(EmailSignup::count())->toBe(7);
    });

    it('cuts a query string and fragment off page_path (only the path is stored)', function () {
        $this->postJson('/api/email-signups', esBody(['page_path' => '/sl/kviz?email=x@y.si&utm_source=a#form']))->assertNoContent();

        expect(EmailSignup::sole()->page_path)->toBe('/sl/kviz');
    });

    it('stores nothing about the sender and sends nothing', function () {
        Queue::fake();
        $this->postJson('/api/email-signups', esFullBody())->assertNoContent();

        expect(Schema::getColumnListing('email_signups'))->not->toContain('ip', 'ip_address', 'user_agent', 'name');
        expect(Schema::getColumnListing('email_contacts'))->not->toContain('ip', 'ip_address', 'user_agent', 'name');
        Queue::assertNothingPushed();
    });

    it('answers the standard Laravel 422 for an invalid body and stores nothing', function (array $override, string $field) {
        $this->postJson('/api/email-signups', esFullBody($override))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(EmailContact::count())->toBe(0)->and(EmailSignup::count())->toBe(0);
    })->with([
        'no email' => [['email' => ''], 'email'],
        'bad email' => [['email' => 'not-an-email'], 'email'],
        'email too long' => [['email' => str_repeat('a', 186).'@x.si'], 'email'],
        'email with a NUL byte' => [['email' => "a\0b@example.test"], 'email'],
        'email with non-ASCII letters' => [['email' => 'žan@example.test'], 'email'],
        'consent missing' => [['consent' => null], 'consent'],
        'consent not given' => [['consent' => false], 'consent'],
        'unknown consent version' => [['consent_version' => '1999-01'], 'consent_version'],
        'no consent version' => [['consent_version' => null], 'consent_version'],
        'no purpose' => [['purpose' => null], 'purpose'],
        'unknown purpose' => [['purpose' => 'spam'], 'purpose'],
        'no source' => [['source' => null], 'source'],
        'source with upper case' => [['source' => 'Real_Cost'], 'source'],
        'source too long' => [['source' => str_repeat('a', 33)], 'source'],
        'no page_path' => [['page_path' => null], 'page_path'],
        'page_path without a leading slash' => [['page_path' => 'sl/kviz'], 'page_path'],
        'page_path with a host' => [['page_path' => 'https://petprep.si/sl'], 'page_path'],
        'page_path with a space' => [['page_path' => '/sl/a b'], 'page_path'],
        'page_path too long' => [['page_path' => '/'.str_repeat('a', 300)], 'page_path'],
        'section with spaces' => [['section' => 'hero banner'], 'section'],
        'section too long' => [['section' => str_repeat('a', 41)], 'section'],
        'no locale' => [['locale' => null], 'locale'],
        'unknown locale' => [['locale' => 'ru'], 'locale'],
        'unknown species' => [['species' => 'hamster'], 'species'],
        'breed with a hyphen' => [['breed' => 'french-bulldog'], 'breed'],
        'breed too long' => [['breed' => str_repeat('a', 65)], 'breed'],
        'unknown stage' => [['stage' => 'senior'], 'stage'],
        'quiz not an object' => [['quiz' => 'x'], 'quiz'],
        'quiz result with four ids' => [['quiz' => ['result' => ['a', 'b', 'c', 'd']]], 'quiz.result'],
        'quiz result id with markup' => [['quiz' => ['result' => ['<b>']]], 'quiz.result.0'],
        'quiz answers with letters' => [['quiz' => ['answers' => '01a']], 'quiz.answers'],
        'quiz answers too long' => [['quiz' => ['answers' => str_repeat('1', 21)]], 'quiz.answers'],
        'utm value too long' => [['utm' => ['source' => str_repeat('a', 101)]], 'utm.source'],
        'utm value with a control character' => [['utm' => ['term' => "a\x01b"]], 'utm.term'],
        'referrer with a path' => [['referrer_host' => 'google.com/search'], 'referrer_host'],
        'referrer too long' => [['referrer_host' => str_repeat('a', 191)], 'referrer_host'],
    ]);

    it('accepts values exactly at the limits', function () {
        $this->postJson('/api/email-signups', esFullBody([
            'email' => str_repeat('c', 64).'@'.str_repeat('d', 60).'.example.test',
            'source' => str_repeat('a', 32),
            'page_path' => '/'.str_repeat('p', 299),
            'section' => str_repeat('s', 40),
            'breed' => str_repeat('b', 64),
            'quiz' => ['result' => [str_repeat('x', 64)], 'answers' => str_repeat('9', 20)],
            'utm' => ['source' => str_repeat('u', 100)],
            'referrer_host' => str_repeat('r', 190),
        ]))->assertNoContent();

        expect(EmailSignup::count())->toBe(1);
    });

    it('honeypot: a filled `website` gets 204 and nothing is stored', function () {
        $this->postJson('/api/email-signups', esBody(['website' => 'https://spam.example']))->assertNoContent();
        // Even with an otherwise invalid body (a bot learns nothing from a 422).
        $this->postJson('/api/email-signups', ['website' => 'x'])->assertNoContent();
        // A non-string value counts as filled too.
        $this->postJson('/api/email-signups', esBody(['website' => ['x']]))->assertNoContent();

        expect(EmailContact::count())->toBe(0)->and(EmailSignup::count())->toBe(0);
    });

    it('is throttled to 20 per hour per IP (429), another IP is not', function () {
        for ($i = 1; $i <= 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->postJson('/api/email-signups', esBody(['email' => "t{$i}@example.test"]))->assertNoContent();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/email-signups', esBody())->assertStatus(429);
        expect(EmailContact::count())->toBe(20);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/email-signups', esBody())->assertNoContent();
    });

    it('allows the petprep.si origin (CORS preflight and the real response)', function () {
        $pre = $this->call('OPTIONS', '/api/email-signups', [], [], [], [
            'HTTP_ORIGIN' => 'https://petprep.si',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
        expect($pre->headers->get('Access-Control-Allow-Origin'))->toBeIn(['*', 'https://petprep.si']);

        $res = $this->withHeaders(['Origin' => 'https://petprep.si'])->postJson('/api/email-signups', esBody());
        $res->assertNoContent();
        expect($res->headers->get('Access-Control-Allow-Origin'))->toBeIn(['*', 'https://petprep.si']);
    });

    it('never puts the e-mail address into a log line or an alert when storing fails', function () {
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = $m->message.json_encode($m->context);
        });
        Queue::fake();

        $this->postJson('/api/email-signups', esFullBody())->assertNoContent();
        $this->postJson('/api/email-signups', esFullBody(['consent' => false]))->assertUnprocessable();

        expect(collect($logged)->filter(fn (string $l) => str_contains($l, 'mama.testna')))->toBeEmpty();
        Queue::assertNothingPushed();
    });
});

describe('database', function () {
    it('refuses a second contact with the same e-mail and an upper-case e-mail', function () {
        EmailContact::factory()->create(['email' => 'a@example.test']);

        expect(fn () => EmailContact::factory()->create(['email' => 'a@example.test']))->toThrow(UniqueConstraintViolationException::class);
        expect(fn () => EmailContact::factory()->create(['email' => 'B@example.test']))->toThrow(QueryException::class);
    });

    it('refuses signups the contract does not allow (CHECK constraints)', function (array $override) {
        $contact = EmailContact::factory()->create();

        expect(fn () => EmailSignup::factory()->create(['email_contact_id' => $contact->id, ...$override]))->toThrow(QueryException::class);
    })->with([
        'purpose' => [['purpose' => 'spam']],
        'source upper case' => [['source' => 'Bad']],
        'species' => [['species' => 'bird']],
        'stage' => [['stage' => 'senior']],
        'locale' => [['locale' => 'SLO']],
        'quiz result with four ids' => [['quiz_result' => ['a', 'b', 'c', 'd']]],
    ]);

    it('deleting a contact removes its signups (ON DELETE CASCADE) and no other contact\'s', function () {
        $a = EmailContact::factory()->create();
        $b = EmailContact::factory()->create();
        EmailSignup::factory()->count(2)->create(['email_contact_id' => $a->id]);
        EmailSignup::factory()->create(['email_contact_id' => $b->id]);

        $a->delete();

        expect(EmailSignup::count())->toBe(1)->and(EmailSignup::sole()->email_contact_id)->toBe($b->id);
    });

    it('is kept by the game data reset', function () {
        expect(GameDataResetService::KEEP)->toContain('email_contacts', 'email_signups')->not->toContain('summary_leads');
    });
});

describe('retention', function () {
    it('never deletes rows automatically: no prune command and nothing scheduled', function () {
        $contact = EmailContact::factory()->create(['first_seen_at' => now()->subYears(6), 'last_seen_at' => now()->subYears(5)]);
        EmailSignup::factory()->create(['email_contact_id' => $contact->id, 'created_at' => now()->subYears(6)]);

        $names = array_keys(Artisan::all());
        expect(collect($names)->filter(fn (string $n) => str_contains($n, 'summary-leads') || str_contains($n, 'email-signups') || str_contains($n, 'email-contacts')))->toBeEmpty();
        $scheduled = collect(app(Schedule::class)->events())->filter(fn ($e) => preg_match('/summary-leads|email-signups|email-contacts/', (string) $e->command));
        expect($scheduled)->toBeEmpty();
        expect(EmailContact::count())->toBe(1)->and(EmailSignup::count())->toBe(1);
    });
});

describe('migration of summary_leads', function () {
    function esMigrationPath(): string
    {
        return base_path('database/migrations/2027_04_24_120000_create_email_contacts_and_signups.php');
    }

    /** Puts the database back into the state before the migration: the old table filled, the new ones gone. */
    function esRestoreOldTable(): void
    {
        Schema::dropIfExists('email_signups');
        Schema::dropIfExists('email_contacts');
        Schema::dropIfExists('summary_leads');
        (require base_path('database/migrations/2027_04_08_120000_create_summary_leads_table.php'))->up();
        DB::statement('ALTER TABLE summary_leads DROP CONSTRAINT summary_leads_source_check');
        DB::statement("ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_source_check CHECK (source IN ('real_cost', 'breed_quiz', 'promise_kit_sample'))"); // since the M6-S09 shop

        $row = fn (array $o) => array_merge([
            'consent_version' => '2026-11', 'consented_at' => '2026-11-15 10:00:00', 'source' => 'real_cost', 'locale' => 'sl',
            'breed' => 'labrador_retriever', 'stage' => 'adult', 'visits' => 1, 'last_seen_at' => '2026-11-15 10:00:00',
            'created_at' => '2026-11-15 10:00:00', 'updated_at' => '2026-11-15 10:00:00',
        ], $o);
        DB::table('summary_leads')->insert([
            $row(['email' => 'one@example.test']),
            $row(['email' => 'two@example.test', 'source' => 'breed_quiz', 'locale' => 'xx', 'breed' => 'cavalier-king-charles-spaniel', 'stage' => null,
                'visits' => 3, 'created_at' => '2026-11-10 08:00:00', 'last_seen_at' => '2026-11-16 09:30:00', 'consent_version' => '2025-01', 'consented_at' => '2026-11-10 08:00:00']),
            $row(['email' => 'three@example.test', 'locale' => null, 'breed' => null, 'stage' => 'puppy']),
            $row(['email' => 'four@example.test', 'source' => 'promise_kit_sample', 'locale' => 'de', 'breed' => null, 'stage' => null]),
        ]);
    }

    it('copies every lead into a contact with ONE newsletter signup and drops the old table', function () {
        esRestoreOldTable();

        (require esMigrationPath())->up();

        expect(Schema::hasTable('summary_leads'))->toBeFalse()
            ->and(EmailContact::count())->toBe(4)->and(EmailSignup::count())->toBe(4);

        $two = EmailContact::where('email', 'two@example.test')->sole();
        expect($two->signups_count)->toBe(3)
            ->and($two->first_seen_at->toDateTimeString())->toBe('2026-11-10 08:00:00')
            ->and($two->last_seen_at->toDateTimeString())->toBe('2026-11-16 09:30:00')
            ->and($two->consent_version)->toBe('2025-01')
            ->and($two->consented_at->toDateTimeString())->toBe('2026-11-10 08:00:00')
            ->and($two->unsubscribed_at)->toBeNull()->and($two->provider_synced_at)->toBeNull();

        $s = $two->signups->sole();
        expect($s->purpose)->toBe('newsletter')
            ->and($s->source)->toBe('breed_quiz')
            ->and($s->page_path)->toBeNull()
            // 'xx' is not a website language → null; a hyphenated slug becomes the underscore breed id
            ->and($s->locale)->toBeNull()
            ->and($s->breed)->toBe('cavalier_king_charles_spaniel')
            ->and($s->stage)->toBeNull()
            ->and($s->consent_version)->toBe('2025-01')
            ->and($s->created_at->toDateTimeString())->toBe('2026-11-10 08:00:00');

        $one = EmailContact::where('email', 'one@example.test')->sole()->signups->sole();
        expect($one->source)->toBe('real_cost')->and($one->locale)->toBe('sl')->and($one->breed)->toBe('labrador_retriever')->and($one->stage)->toBe('adult');

        // The Promise kit sample form (M6-S09 shop) keeps its own source.
        $four = EmailContact::where('email', 'four@example.test')->sole()->signups->sole();
        expect($four->source)->toBe('promise_kit_sample')->and($four->purpose)->toBe('newsletter')->and($four->locale)->toBe('de');
    });

    it('down() restores the old table from the contacts', function () {
        esRestoreOldTable();
        $migration = require esMigrationPath();
        $migration->up();

        $migration->down();

        expect(Schema::hasTable('email_contacts'))->toBeFalse()->and(Schema::hasTable('email_signups'))->toBeFalse();
        $rows = DB::table('summary_leads')->orderBy('email')->get()->keyBy('email');
        expect($rows)->toHaveCount(4)
            ->and($rows['four@example.test']->source)->toBe('promise_kit_sample')
            ->and($rows['two@example.test']->visits)->toBe(3)
            ->and($rows['two@example.test']->source)->toBe('breed_quiz')
            ->and($rows['two@example.test']->breed)->toBe('cavalier_king_charles_spaniel')
            ->and($rows['one@example.test']->locale)->toBe('sl');
    });
});
