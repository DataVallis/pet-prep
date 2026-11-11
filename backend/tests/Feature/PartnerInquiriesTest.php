<?php

use App\Enums\AdminAlertKind;
use App\Filament\Pages\FeatureSwitches;
use App\Filament\Resources\PartnerInquiryResource;
use App\Filament\Resources\PartnerInquiryResource\Pages\ListPartnerInquiries;
use App\Jobs\SendPartnerInquiryAlert;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\PartnerInquiry;
use App\Models\User;
use App\Services\AppSettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M6-S03 — partner inquiries (WEBSITE_SALES_SPEC §5.2)
|--------------------------------------------------------------------------
|
| POST /api/partner-inquiries: public, 5 / hour per IP, honeypot `website`,
| superadmin switch »Partner inquiries« (default on, off → 503), Discord alert
| without sender data, read-only /admin list + view (superadmin), 12-month prune.
|
*/

const PI_DISCORD = 'https://discord.com/api/webhooks/555/partner-inquiry-secret';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    config(['services.discord.alerts_webhook_url' => null]);
});

/** @return array<string, mixed> */
function piBody(array $overrides = []): array
{
    return array_merge([
        'company' => 'Zavarovalnica Triglav Test d.d.',
        'contact_name' => 'Mojca Testna',
        'email' => 'mojca.testna@example.test',
        'type' => 'insurer',
        'message' => 'Zanima nas sodelovanje, prosimo za klic.',
        'website' => '',
        'locale' => 'sl',
    ], $overrides);
}

function piSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::PARTNER_INQUIRIES_KEY], ['value' => ['enabled' => $on]]);
    Cache::forget(AppSettingsService::cacheKey(AppSettingsService::PARTNER_INQUIRIES_KEY));
}

function piAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

describe('POST /api/partner-inquiries', function () {
    it('stores the inquiry and answers 201 {ok: true} without a token', function () {
        $this->postJson('/api/partner-inquiries', piBody())
            ->assertCreated()
            ->assertExactJson(['ok' => true]);

        $row = PartnerInquiry::sole();
        expect($row->company)->toBe('Zavarovalnica Triglav Test d.d.')
            ->and($row->contact_name)->toBe('Mojca Testna')
            ->and($row->email)->toBe('mojca.testna@example.test')
            ->and($row->type->value)->toBe('insurer')
            ->and($row->locale)->toBe('sl');
    });

    it('accepts every type, an absent locale and an absent honeypot', function () {
        foreach (['series', 'sponsored_content', 'insurer', 'directory', 'shelter', 'other'] as $i => $type) {
            $body = piBody(['type' => $type, 'email' => "p{$i}@example.test"]);
            unset($body['locale'], $body['website']);
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.'.($i + 1)])
                ->postJson('/api/partner-inquiries', $body)->assertCreated();
        }

        expect(PartnerInquiry::count())->toBe(6)
            ->and(PartnerInquiry::whereNull('locale')->count())->toBe(6);
    });

    it('does not store the sender address (no IP column)', function () {
        $this->postJson('/api/partner-inquiries', piBody())->assertCreated();

        expect(Schema::getColumnListing('partner_inquiries'))
            ->toBe(['id', 'company', 'contact_name', 'email', 'type', 'message', 'locale', 'created_at', 'updated_at']);
    });

    it('answers the standard Laravel 422 for an invalid body and stores nothing', function (array $override, string $field) {
        $this->postJson('/api/partner-inquiries', piBody($override))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(PartnerInquiry::count())->toBe(0);
    })->with([
        'no company' => [['company' => ''], 'company'],
        'company too long' => [['company' => str_repeat('a', 161)], 'company'],
        'no contact' => [['contact_name' => ''], 'contact_name'],
        'contact too long' => [['contact_name' => str_repeat('a', 121)], 'contact_name'],
        'no email' => [['email' => ''], 'email'],
        'bad email' => [['email' => 'not-an-email'], 'email'],
        'email too long' => [['email' => str_repeat('a', 186).'@x.si'], 'email'],
        'unknown type' => [['type' => 'advertising'], 'type'],
        'no type' => [['type' => null], 'type'],
        'no message' => [['message' => ''], 'message'],
        'message too long' => [['message' => str_repeat('a', 4001)], 'message'],
        'unknown locale' => [['locale' => 'de'], 'locale'],
    ]);

    it('accepts values exactly at the limits', function () {
        $this->postJson('/api/partner-inquiries', piBody([
            'company' => str_repeat('a', 160),
            'contact_name' => str_repeat('b', 120),
            'email' => str_repeat('c', 64).'@'.str_repeat('d', 60).'.example.test',
            'message' => str_repeat('m', 4000),
        ]))->assertCreated();
    });

    it('honeypot: a filled `website` gets 201 {ok: true}, nothing is stored and no alert is sent', function () {
        Queue::fake();

        $this->postJson('/api/partner-inquiries', piBody(['website' => 'https://spam.example']))
            ->assertCreated()->assertExactJson(['ok' => true]);
        // Even with an otherwise invalid body (a bot learns nothing from a 422).
        $this->postJson('/api/partner-inquiries', ['website' => 'x'])
            ->assertCreated()->assertExactJson(['ok' => true]);

        expect(PartnerInquiry::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('is throttled to 5 per hour per IP (429), another IP is not', function () {
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->postJson('/api/partner-inquiries', piBody(['email' => "t{$i}@example.test"]))->assertCreated();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/partner-inquiries', piBody())->assertStatus(429);
        expect(PartnerInquiry::count())->toBe(5);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/partner-inquiries', piBody())->assertCreated();
    });

    it('the switch is on by default and off answers 503 and stores nothing', function () {
        expect(app(AppSettingsService::class)->partnerInquiries())->toBeTrue();

        piSwitch(false);
        Queue::fake();
        $this->postJson('/api/partner-inquiries', piBody())->assertStatus(503);
        expect(PartnerInquiry::count())->toBe(0);
        Queue::assertNothingPushed();

        piSwitch(true);
        $this->postJson('/api/partner-inquiries', piBody())->assertCreated();
    });

    it('the Feature switches toggle is on by default and its change is audited', function () {
        $admin = piAdmin();
        $this->actingAs($admin);

        Livewire::test(FeatureSwitches::class)
            ->assertFormSet(['partner_inquiries' => true])
            ->fillForm(['partner_inquiries' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(AppSettingsService::class)->partnerInquiries(fresh: true))->toBeFalse();
        $change = AppSettingChange::where('key', AppSettingsService::PARTNER_INQUIRIES_KEY)->sole();
        expect($change->user_id)->toBe($admin->id)->and($change->new)->toBe(['enabled' => false]);
    });

    it('allows the petprep.si origin (CORS preflight and the real response)', function () {
        $pre = $this->call('OPTIONS', '/api/partner-inquiries', [], [], [], [
            'HTTP_ORIGIN' => 'https://petprep.si',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
        expect($pre->headers->get('Access-Control-Allow-Origin'))->toBeIn(['*', 'https://petprep.si']);

        $res = $this->withHeaders(['Origin' => 'https://petprep.si'])->postJson('/api/partner-inquiries', piBody());
        $res->assertCreated();
        expect($res->headers->get('Access-Control-Allow-Origin'))->toBeIn(['*', 'https://petprep.si']);
    });
});

describe('Discord alert', function () {
    it('is queued once per inquiry with the type only', function () {
        Queue::fake();

        $this->postJson('/api/partner-inquiries', piBody(['type' => 'shelter']))->assertCreated();

        Queue::assertPushed(SendPartnerInquiryAlert::class, 1);
        Queue::assertPushed(SendPartnerInquiryAlert::class, fn (SendPartnerInquiryAlert $j) => $j->type === 'shelter');
    });

    it('carries "New partner inquiry, type: <type>" and the admin link, and no personal data', function () {
        config(['services.discord.alerts_webhook_url' => PI_DISCORD]);
        Http::fake([PI_DISCORD => Http::response('', 204)]);

        $this->postJson('/api/partner-inquiries', piBody(['type' => 'sponsored_content']))->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $raw = $request->body();
            $embed = $request['embeds'][0];

            expect($request['content'])->toBe('New partner inquiry, type: sponsored_content')
                ->and($embed['title'])->toBe('New partner inquiry, type: sponsored_content')
                ->and($embed['description'])->toBe(url('/admin/partner-inquiries'))
                ->and($embed['url'])->toBe(url('/admin/partner-inquiries'))
                ->and($embed['fields'])->toBe([])
                ->and($embed['footer']['text'])->toContain(AdminAlertKind::PartnerInquiry->value);
            foreach (['Triglav', 'Mojca', 'Testna', 'mojca.testna', 'example.test', 'Zanima nas'] as $pii) {
                expect($raw)->not->toContain($pii);
            }
            expect($raw)->not->toContain('"locale"');

            return true;
        });
    });

    it('sends nothing when no webhook is configured, and a failed post never fails the request', function () {
        $this->postJson('/api/partner-inquiries', piBody())->assertCreated();
        Http::assertNothingSent();

        config(['services.discord.alerts_webhook_url' => PI_DISCORD]);
        Http::fake([PI_DISCORD => Http::response('{"message":"boom"}', 500)]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->postJson('/api/partner-inquiries', piBody(['email' => 'two@example.test']))->assertCreated();
        expect(PartnerInquiry::count())->toBe(2);
    });
});

describe('pruning', function () {
    it('deletes inquiries older than 12 months and keeps newer ones', function () {
        $old = PartnerInquiry::factory()->create(['created_at' => now()->subMonths(12)->subDay()]);
        $edge = PartnerInquiry::factory()->create(['created_at' => now()->subMonths(11)]);
        $new = PartnerInquiry::factory()->create();

        $this->artisan('partner-inquiries:prune')->assertSuccessful();

        expect(PartnerInquiry::pluck('id')->all())->toEqualCanonicalizing([$edge->id, $new->id])
            ->and(PartnerInquiry::find($old->id))->toBeNull();
    });

    it('is scheduled daily with an explicit overlap expiry', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'partner-inquiries:prune'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('43 3 * * *')
            ->and($event->expiresAt)->toBeLessThanOrEqual(60);
    });
});

describe('/admin partner inquiries', function () {
    it('refuses everyone but a superadmin (list and view), also a plain parent', function () {
        $inquiry = PartnerInquiry::factory()->create();

        $this->actingAs(User::factory()->parent()->create());
        $this->get('/admin/partner-inquiries')->assertForbidden();
        $this->get('/admin/partner-inquiries/'.$inquiry->id)->assertForbidden();
        expect(PartnerInquiryResource::canAccess())->toBeFalse()
            ->and(PartnerInquiryResource::canViewAny())->toBeFalse()
            ->and(PartnerInquiryResource::canDelete($inquiry))->toBeFalse();
    });

    it('a superadmin lists and views inquiries, read only, and may delete', function () {
        $inquiry = PartnerInquiry::factory()->create(['company' => 'Pasja Šola d.o.o.', 'message' => "Prva vrstica\nDruga vrstica"]);
        $this->actingAs(piAdmin());

        $this->get('/admin/partner-inquiries')->assertOk()->assertSee('Pasja Šola d.o.o.');
        $this->get('/admin/partner-inquiries/'.$inquiry->id)->assertOk()->assertSee('Prva vrstica');
        expect(PartnerInquiryResource::canCreate())->toBeFalse()
            ->and(PartnerInquiryResource::canEdit($inquiry))->toBeFalse()
            ->and(PartnerInquiryResource::canDelete($inquiry))->toBeTrue();
        $this->get('/admin/partner-inquiries/'.$inquiry->id.'/edit')->assertNotFound();

        Livewire::test(ListPartnerInquiries::class)
            ->assertCanSeeTableRecords([$inquiry])
            ->callTableAction('delete', $inquiry);
        expect(PartnerInquiry::count())->toBe(0);
    });
});
