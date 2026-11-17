<?php

use App\Filament\Resources\EmailContactResource;
use App\Filament\Resources\EmailContactResource\Pages\ListEmailContacts;
use App\Filament\Resources\EmailContactResource\Pages\ViewEmailContact;
use App\Models\EmailContact;
use App\Models\EmailSignup;
use App\Models\User;
use App\Services\EmailContactCsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M6-S09 — /admin »E-mails (website)« (superadmin only)
|--------------------------------------------------------------------------
|
| List of the contacts with where / what for / targeting context, filters
| (source, purpose, breed, locale, date range — all on the SAME signup),
| contact detail with every signup row, CSV of the filtered list, delete on
| request (the contact and its signups).
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'UTC'));
});

function eaAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

/** @param  array<string, mixed>  $signup */
function eaContact(string $email, array $signup = [], array $contact = []): EmailContact
{
    $c = EmailContact::factory()->create(['email' => $email, ...$contact]);
    EmailSignup::factory()->create(['email_contact_id' => $c->id, ...$signup]);

    return $c;
}

/** The CSV of a query as rows (header first), BOM stripped. */
function eaCsv(Builder $query): array
{
    $stream = fopen('php://memory', 'w+');
    app(EmailContactCsvExport::class)->write($query, $stream);
    rewind($stream);
    $text = stream_get_contents($stream);
    expect(str_starts_with($text, "\xEF\xBB\xBF"))->toBeTrue();
    $rows = [];
    $h = fopen('php://memory', 'w+');
    fwrite($h, substr($text, 3));
    rewind($h);
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $rows[] = $r;
    }

    return $rows;
}

describe('list', function () {
    it('shows the contacts with the readable source, page + section, breed, quiz and UTM', function () {
        $a = eaContact('kviz@example.test', [
            'source' => 'breed_quiz', 'page_path' => '/sl/pred-nakupom-psa/katera-pasma', 'section' => 'result',
            'breed' => 'beagle', 'quiz_result' => ['beagle', 'pug'], 'utm_source' => 'fb',
        ]);
        $b = eaContact('kalk@example.test', ['source' => 'real_cost']);
        $this->actingAs(eaAdmin());

        Livewire::test(ListEmailContacts::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->assertSee('Breed quiz')
            ->assertSee('/sl/pred-nakupom-psa/katera-pasma · result')
            ->assertSee('beagle › pug')
            ->assertSee('Calculator — How much work is a dog');
    });

    it('shows the Slovenian label of the calculator source', function () {
        app()->setLocale('sl');
        expect(EmailSignup::sourceLabel('real_cost'))->toBe('Kalkulator — Koliko je dela s psom')
            ->and(EmailSignup::sourceLabel('early_access_form'))->toBe('Zgodnji dostop')
            ->and(EmailSignup::sourceLabel('breed_quiz'))->toBe('Kviz pasem')
            ->and(EmailSignup::sourceLabel('brand_new_form'))->toBe('brand_new_form');
    });

    it('filters by source, purpose, breed, locale and date range — all on the SAME signup', function () {
        $quizBeagle = eaContact('a@example.test', ['source' => 'breed_quiz', 'breed' => 'beagle', 'locale' => 'sl', 'purpose' => 'newsletter']);
        $calcPug = eaContact('b@example.test', ['source' => 'real_cost', 'breed' => 'pug', 'locale' => 'en', 'purpose' => 'newsletter']);
        $early = eaContact('c@example.test', ['source' => 'early_access_form', 'breed' => null, 'locale' => 'de', 'purpose' => 'early_access']);
        // quiz AND beagle live on DIFFERENT signups of this contact: it must not match source=quiz + breed=beagle
        $mixed = eaContact('d@example.test', ['source' => 'breed_quiz', 'breed' => 'pug']);
        EmailSignup::factory()->create(['email_contact_id' => $mixed->id, 'source' => 'real_cost', 'breed' => 'beagle']);
        $this->actingAs(eaAdmin());

        $filter = fn (array $data) => Livewire::test(ListEmailContacts::class)->filterTable('signup', $data);

        $filter(['source' => 'breed_quiz'])->assertCanSeeTableRecords([$quizBeagle, $mixed])->assertCanNotSeeTableRecords([$calcPug, $early]);
        $filter(['purpose' => 'early_access'])->assertCanSeeTableRecords([$early])->assertCanNotSeeTableRecords([$quizBeagle, $calcPug, $mixed]);
        $filter(['breed' => 'pug'])->assertCanSeeTableRecords([$calcPug, $mixed])->assertCanNotSeeTableRecords([$quizBeagle, $early]);
        $filter(['locale' => 'de'])->assertCanSeeTableRecords([$early])->assertCanNotSeeTableRecords([$quizBeagle, $calcPug, $mixed]);
        $filter(['source' => 'breed_quiz', 'breed' => 'beagle'])->assertCanSeeTableRecords([$quizBeagle])->assertCanNotSeeTableRecords([$mixed, $calcPug, $early]);
    });

    it('filters by signup date range (Ljubljana days)', function () {
        $old = eaContact('old@example.test', ['created_at' => '2026-10-01 12:00:00']);
        $mid = eaContact('mid@example.test', ['created_at' => '2026-11-05 12:00:00']);
        $new = eaContact('new@example.test', ['created_at' => '2026-11-16 23:30:00']); // 00:30 on the 17th in Ljubljana
        $this->actingAs(eaAdmin());

        Livewire::test(ListEmailContacts::class)
            ->filterTable('signup', ['from' => '2026-11-01', 'until' => '2026-11-10'])
            ->assertCanSeeTableRecords([$mid])->assertCanNotSeeTableRecords([$old, $new]);
        Livewire::test(ListEmailContacts::class)
            ->filterTable('signup', ['from' => '2026-11-17'])
            ->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old, $mid]);
    });

    it('searches by e-mail', function () {
        $a = eaContact('tinka@example.test');
        $b = eaContact('janez@example.test');
        $this->actingAs(eaAdmin());

        Livewire::test(ListEmailContacts::class)->searchTable('tinka')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    });
});

describe('contact detail', function () {
    it('shows every signup row of the contact with where it was left', function () {
        $c = eaContact('vec@example.test', ['source' => 'real_cost', 'page_path' => '/sl/koliko-dela-je-z-labradorcem', 'section' => 'summary']);
        EmailSignup::factory()->create([
            'email_contact_id' => $c->id, 'source' => 'early_access_form', 'purpose' => 'early_access',
            'page_path' => '/sl/zgodnji-dostop', 'section' => 'hero', 'utm_campaign' => 'launch', 'referrer_host' => 'google.com',
        ]);
        $this->actingAs(eaAdmin());

        Livewire::test(ViewEmailContact::class, ['record' => $c->getRouteKey()])
            ->assertSee('vec@example.test')
            ->assertSee('/sl/koliko-dela-je-z-labradorcem')
            ->assertSee('/sl/zgodnji-dostop')
            ->assertSee('Early access')
            ->assertSee('campaign=launch')
            ->assertSee('google.com');
    });

    it('deleting a contact in the admin removes its signups too', function () {
        $c = eaContact('izbrisi@example.test');
        EmailSignup::factory()->create(['email_contact_id' => $c->id]);
        $keep = eaContact('ostane@example.test');
        $this->actingAs(eaAdmin());

        Livewire::test(ListEmailContacts::class)->callTableAction('delete', $c);

        expect(EmailContact::pluck('email')->all())->toBe(['ostane@example.test'])
            ->and(EmailSignup::pluck('email_contact_id')->all())->toBe([$keep->id]);
    });

    it('bulk delete removes the selected contacts and their signups', function () {
        $cs = collect(['a', 'b', 'c'])->map(fn (string $n) => eaContact("{$n}@example.test"));
        $this->actingAs(eaAdmin());

        Livewire::test(ListEmailContacts::class)->callTableBulkAction('delete', $cs->take(2));

        expect(EmailContact::count())->toBe(1)->and(EmailSignup::count())->toBe(1);
    });
});

describe('CSV export', function () {
    it('has one row per contact with the latest signup, all sources and purposes, UTC times', function () {
        $c = eaContact('csv@example.test', ['source' => 'real_cost', 'purpose' => 'newsletter'], ['signups_count' => 2]);
        EmailSignup::factory()->create([
            'email_contact_id' => $c->id, 'source' => 'breed_quiz', 'purpose' => 'early_access', 'page_path' => '/sl/kviz', 'section' => 'step5',
            'breed' => 'pug', 'species' => 'dog', 'stage' => 'puppy', 'locale' => 'sl', 'quiz_result' => ['pug', 'beagle', 'akita'], 'quiz_answers' => '0123',
            'utm_source' => 'fb', 'utm_medium' => 'cpc', 'referrer_host' => 'facebook.com',
        ]);

        $rows = eaCsv(EmailContact::query());

        expect($rows[0])->toBe(EmailContactCsvExport::COLUMNS)->and($rows)->toHaveCount(2);
        $r = array_combine($rows[0], $rows[1]);
        expect($r['email'])->toBe('csv@example.test')
            ->and($r['sources'])->toBe('real_cost;breed_quiz')
            ->and($r['purposes'])->toBe('newsletter;early_access')
            ->and($r['latest_source'])->toBe('breed_quiz')
            ->and($r['page_path'])->toBe('/sl/kviz')
            ->and($r['section'])->toBe('step5')
            ->and($r['breed'])->toBe('pug')
            ->and($r['quiz_result'])->toBe('pug;beagle;akita')
            ->and($r['quiz_answers'])->toBe('0123')
            ->and($r['utm_source'])->toBe('fb')
            ->and($r['referrer_host'])->toBe('facebook.com')
            ->and($r['signups_count'])->toBe('2')
            ->and($r['first_seen_at'])->toBe('2026-11-17 10:00:00')
            ->and($r['unsubscribed_at'])->toBe('')
            ->and($r['provider_synced_at'])->toBe('');
    });

    it('neutralises spreadsheet formulas in cells', function () {
        eaContact('+plus@example.test', ['utm_term' => '=HYPERLINK("http://evil")', 'utm_content' => '@cmd', 'utm_medium' => '-1+1']);

        $rows = eaCsv(EmailContact::query());
        $r = array_combine($rows[0], $rows[1]);

        expect($r['email'])->toBe("'+plus@example.test")
            ->and($r['utm_term'])->toStartWith("'=")
            ->and($r['utm_content'])->toBe("'@cmd")
            ->and($r['utm_medium'])->toBe("'-1+1");
    });

    it('exports the filtered list from the list page', function () {
        eaContact('quiz@example.test', ['source' => 'breed_quiz']);
        eaContact('calc@example.test', ['source' => 'real_cost']);
        $this->actingAs(eaAdmin());

        $component = Livewire::test(ListEmailContacts::class)
            ->filterTable('signup', ['source' => 'breed_quiz'])
            ->callAction('export_csv')
            ->assertFileDownloaded();

        $csv = base64_decode(data_get($component->effects, 'download.content'));
        expect($csv)->toContain('quiz@example.test')->not->toContain('calc@example.test')
            ->and(data_get($component->effects, 'download.name'))->toStartWith('email-contacts-')->toEndWith('.csv');
    });

    it('streams only the filtered rows (query level)', function () {
        $quiz = eaContact('quiz@example.test', ['source' => 'breed_quiz']);
        eaContact('calc@example.test', ['source' => 'real_cost']);

        $rows = eaCsv(EmailContact::query()->whereHas('signups', fn ($s) => $s->where('source', 'breed_quiz')));

        expect($rows)->toHaveCount(2)->and($rows[1][0])->toBe($quiz->email);
    });
});

describe('access', function () {
    it('refuses everyone but a superadmin on the route', function () {
        $this->get('/admin/email-contacts')->assertRedirect();

        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => false]));
        $this->get('/admin/email-contacts')->assertForbidden();

        $this->actingAs(eaAdmin());
        $this->get('/admin/email-contacts')->assertOk();
    });

    it('is closed to a parent who is not a superadmin and has no create or edit', function () {
        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => false]));
        expect(EmailContactResource::canAccess())->toBeFalse()->and(EmailContactResource::canViewAny())->toBeFalse();

        $this->actingAs(eaAdmin());
        $c = EmailContact::factory()->create();
        expect(EmailContactResource::canAccess())->toBeTrue()
            ->and(EmailContactResource::canCreate())->toBeFalse()
            ->and(EmailContactResource::canEdit($c))->toBeFalse()
            ->and(EmailContactResource::canView($c))->toBeTrue();
    });
});
