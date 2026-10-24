<?php

use App\Enums\AdminAlertKind;
use App\Filament\Pages\FeatureSwitches;
use App\Filament\Pages\SystemHealth;
use App\Filament\Widgets\SystemHealth\AppErrorsTable;
use App\Jobs\SendAppErrorAlert;
use App\Jobs\SendAppErrorSuppressedNotice;
use App\Models\AppErrorFingerprint;
use App\Models\AppErrorReport;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\User;
use App\Services\Alerts\DiscordWebhook;
use App\Services\AppErrors\AppErrorReportService;
use App\Services\AppErrors\AppErrorText;
use App\Services\AppSettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M5-05 — app crash / render-error reports (David 2026-10-19: System health, no Sentry)
|--------------------------------------------------------------------------
|
| POST /api/app-errors with or without a token (only the role is kept),
| strict validation, per-IP / per-user throttle, upsert per fingerprint +
| version + platform, 30-day prune, Discord once per new fingerprint (and on
| an hourly spike) at most once per day, superadmin-only admin section.
|
*/

const AE_DISCORD = 'https://discord.com/api/webhooks/987/app-errors-secret';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 10:15:00', 'UTC'));
    Http::preventStrayRequests();
    Sleep::fake();
    config(['services.discord.alerts_webhook_url' => null]);
    aeSwitch(true);
});

/** QA #375 B1: the superadmin switch »App error reports« (default off). */
function aeSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::APP_ERROR_REPORTS_KEY], ['value' => ['enabled' => $on]]);
    Cache::forget(AppSettingsService::cacheKey(AppSettingsService::APP_ERROR_REPORTS_KEY));
}

/** @return array<string, mixed> */
function aeBody(array $overrides = []): array
{
    return array_merge([
        'scope' => 'child-hud',
        'name' => 'TypeError',
        'message' => "undefined is not an object (evaluating 'pet.media.idle')",
        'stack' => "TypeError: undefined is not an object\n    at PetMediaView (address at index.android.bundle:1:23456)\n    at renderWithHooks (address at index.android.bundle:1:99)",
        'fatal' => false,
        'app_version' => '3.1.4',
        'runtime_version' => 'abc123def',
        'update_id' => '0f8e7d6c-1111-2222-3333-444455556666',
        'build' => 'e70d189',
        'platform' => 'android',
        'os_version' => '35',
        'route' => 'child-hud',
        'occurred_at' => '2026-10-24T10:14:00Z',
    ], $overrides);
}

function aeAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

describe('POST /api/app-errors', function () {
    it('stores a report without a token as a signed-out report', function () {
        $this->postJson('/api/app-errors', aeBody())->assertNoContent();

        $fp = AppErrorFingerprint::sole();
        $report = AppErrorReport::sole();
        expect($fp->scope)->toBe('child-hud')
            ->and($fp->error_name)->toBe('TypeError')
            ->and($fp->message)->toBe('undefined is not an object (evaluating `pet.media.idle`)')
            ->and($fp->first_frame)->toBe('at PetMediaView (address at index.android.bundle:1:23456)')
            ->and($fp->occurrences)->toBe(1)
            ->and($report->app_version)->toBe('3.1.4')
            ->and($report->platform)->toBe('android')
            ->and($report->anonymous_count)->toBe(1)
            ->and($report->child_count)->toBe(0)
            ->and($report->sample['role'])->toBeNull()
            ->and($report->sample['update_id'])->toBe('0f8e7d6c-1111-2222-3333-444455556666')
            ->and($report->sample['occurred_at'])->toBe('2026-10-24T10:14:00+00:00')
            ->and($report->sample['received_at'])->toBe('2026-10-24T10:15:00+00:00');
    });

    it('keeps only the role of a valid token — never the user', function () {
        $child = createChildUser(['name' => 'Lukec']);
        $token = $child->createToken('device', ['child'])->plainTextToken;

        $this->withToken($token)->postJson('/api/app-errors', aeBody())->assertNoContent();

        $report = AppErrorReport::sole();
        expect($report->child_count)->toBe(1)
            ->and($report->sample['role'])->toBe('child')
            ->and(array_keys($report->sample))->not->toContain('user_id')
            ->and(json_encode($report->sample))->not->toContain('Lukec');
        expect(DB::getSchemaBuilder()->getColumnListing('app_error_reports'))->not->toContain('user_id');
    });

    it('ignores an invalid token instead of answering 401', function () {
        $this->withToken('99|not-a-real-token-at-all-xxxxxxxxxx')->postJson('/api/app-errors', aeBody())->assertNoContent();

        expect(AppErrorReport::sole()->anonymous_count)->toBe(1);
    });

    it('validates every field strictly', function (array $override, string $field) {
        $this->postJson('/api/app-errors', aeBody($override))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
        expect(AppErrorReport::count())->toBe(0);
    })->with([
        'unknown scope' => [['scope' => 'redux'], 'scope'],
        'name too long' => [['name' => str_repeat('E', 101)], 'name'],
        'message too long' => [['message' => str_repeat('m', 501)], 'message'],
        'stack too long' => [['stack' => str_repeat('s', 2049)], 'stack'],
        'version free text' => [['app_version' => 'Lukec <3'], 'app_version'],
        'web platform' => [['platform' => 'web'], 'platform'],
        'route with a path' => [['route' => '/pets/12?name=Rex'], 'route'],
        'runtime with spaces' => [['runtime_version' => 'a b'], 'runtime_version'],
        'missing occurred_at' => [['occurred_at' => null], 'occurred_at'],
        'occurred far in the future' => [['occurred_at' => '2026-11-30T00:00:00Z'], 'occurred_at'],
    ]);

    it('throttles signed-out reports per IP at 10 a minute and writes nothing when throttled', function () {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/app-errors', aeBody(['message' => "boom {$i}"]))->assertNoContent();
        }
        $before = AppErrorFingerprint::sum('occurrences');
        $this->postJson('/api/app-errors', aeBody(['message' => 'one too many']))->assertTooManyRequests();
        expect(AppErrorFingerprint::sum('occurrences'))->toBe($before)
            ->and(AppErrorFingerprint::where('message', 'one too many')->exists())->toBeFalse();
        // Another client address has its own bucket.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/app-errors', aeBody())->assertNoContent();
    });

    it('caps signed-out reports per IP at 300 a day', function () {
        for ($i = 0; $i < 300; $i++) {
            if ($i > 0 && $i % 10 === 0) {
                Carbon::setTestNow(Carbon::now()->addMinutes(2));
            }
            $this->postJson('/api/app-errors', aeBody())->assertNoContent();
        }
        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->postJson('/api/app-errors', aeBody())->assertTooManyRequests();
    });

    it('refuses a body over 16 KB with 413', function () {
        $this->call('POST', '/api/app-errors', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(aeBody(['pad' => str_repeat('x', 17000)])))
            ->assertStatus(413);
        expect(AppErrorReport::count())->toBe(0);
    });

    it('stores nothing while the switch is off (default) and tells the app via /api/user', function () {
        AppSetting::query()->whereKey(AppSettingsService::APP_ERROR_REPORTS_KEY)->delete();
        Cache::forget(AppSettingsService::cacheKey(AppSettingsService::APP_ERROR_REPORTS_KEY));

        $this->postJson('/api/app-errors', aeBody())->assertNoContent();
        expect(AppErrorFingerprint::count())->toBe(0);

        $parent = createParentUser();
        $this->actingAs($parent, 'sanctum')->getJson('/api/user')->assertOk()->assertJsonPath('app_error_reports', false);
        aeSwitch(true);
        $this->actingAs($parent, 'sanctum')->getJson('/api/user')->assertJsonPath('app_error_reports', true);
    });

    it('stores an expired or revoked real token as a signed-out report', function () {
        $child = createChildUser();
        $revoked = $child->createToken('device', ['child']);
        $plain = $revoked->plainTextToken;
        $revoked->accessToken->delete();
        $this->withToken($plain)->postJson('/api/app-errors', aeBody())->assertNoContent();

        app('auth')->forgetGuards();
        $expired = $child->createToken('device2', ['child'], Carbon::now()->subMinute());
        $this->withToken($expired->plainTextToken)->postJson('/api/app-errors', aeBody())->assertNoContent();

        $report = AppErrorReport::sole();
        expect($report->anonymous_count)->toBe(2)->and($report->child_count)->toBe(0);
    });

    it('throttles per signed-in user across addresses', function () {
        $parent = createParentUser();
        $token = $parent->createToken('phone', ['parent'])->plainTextToken;
        for ($i = 0; $i < 30; $i++) {
            app('auth')->forgetGuards();
            $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])
                ->postJson('/api/app-errors', aeBody())->assertNoContent();
        }
        app('auth')->forgetGuards();
        $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '198.51.100.200'])
            ->postJson('/api/app-errors', aeBody())->assertTooManyRequests();
    });
});

describe('dedupe and growth', function () {
    it('upserts one row per fingerprint + version + platform', function () {
        $service = app(AppErrorReportService::class);
        // Same error, other bundle offsets and quoted value → same fingerprint.
        $service->record(aeBody(), null);
        $service->record(aeBody(['stack' => "TypeError: x\n    at PetMediaView (address at index.android.bundle:1:777)"]), null);
        $service->record(aeBody(['app_version' => '3.1.5']), null);
        $service->record(aeBody(['platform' => 'ios']), null);
        $service->record(aeBody(['message' => 'Network request failed']), null);

        $fp = AppErrorFingerprint::query()->where('error_name', 'TypeError')->where('message', 'like', 'undefined%')->sole();
        expect(AppErrorFingerprint::count())->toBe(2)
            ->and(AppErrorReport::count())->toBe(4)
            ->and(AppErrorReport::query()->where(['fingerprint' => $fp->fingerprint, 'app_version' => '3.1.4', 'platform' => 'android'])->sole()->occurrences)->toBe(2);
        expect($fp->occurrences)->toBe(4)
            ->and($service->countSince($fp->fingerprint, 24))->toBe(4);
    });

    it('scrubs paths, e-mails and credentials before storing', function () {
        app(AppErrorReportService::class)->record(aeBody([
            'message' => 'Failed for ana@example.com token=abc123secret at /data/user/0/si.petprep/files/x.js',
            'stack' => "Error\n    at load (file:///var/mobile/Containers/Data/Application/1234-ABCD/Library/main.jsbundle:1:2)",
        ]), null);

        $report = AppErrorReport::sole();
        expect($report->sample['message'])->not->toContain('ana@example.com')
            ->and($report->sample['message'])->not->toContain('abc123secret')
            ->and($report->sample['message'])->not->toContain('/data/user')
            ->and($report->sample['stack'])->toContain('main.jsbundle')
            ->and($report->sample['stack'])->not->toContain('Containers');
    });

    it('fingerprints ignore bundle offsets and numbers', function () {
        expect(AppErrorText::fingerprint('E', 'x 12', 'at a (index.bundle:1:2)'))
            ->toBe(AppErrorText::fingerprint('E', 'x 99', 'at a (index.bundle:1:9999)'))
            ->not->toBe(AppErrorText::fingerprint('E', 'x 99', 'at b (index.bundle:1:9999)'));
    });

    it('prunes everything not seen for 30 days', function () {
        $service = app(AppErrorReportService::class);
        $service->record(aeBody(['message' => 'old']), null, Carbon::now()->subDays(31));
        $service->record(aeBody(['message' => 'fresh']), null);

        $this->artisan('system-health:prune')->assertSuccessful();

        expect(AppErrorFingerprint::pluck('message')->all())->toBe(['fresh'])
            ->and(AppErrorReport::count())->toBe(1)
            ->and(DB::table('app_error_hours')->count())->toBe(1);
    });
});

describe('scrubbing (QA #375 M2)', function () {
    it('scrubs each probe before storing', function (string $message, array $absent, array $present) {
        app(AppErrorReportService::class)->record(aeBody(['message' => $message, 'stack' => "Error: {$message}\n    at f (index.android.bundle:1:23456)"]), null);

        $report = AppErrorReport::sole();
        $stored = $report->sample['message'].' '.$report->sample['stack'].' '.AppErrorFingerprint::sole()->message;
        foreach ($absent as $value) {
            expect($stored)->not->toContain($value);
        }
        foreach ($present as $value) {
            expect($report->sample['message'])->toContain($value);
        }
        // The bundle position is kept (fingerprint strips it itself).
        expect($report->sample['stack'])->toContain('index.android.bundle:1:23456');
    })->with([
        'PIN digits' => ['Invalid PIN 4821', ['4821'], ['Invalid PIN']],
        'url query' => ['GET https://api.petprep.si/api/x?name=Rex&email=ana%40example.com failed', ['Rex', 'ana', 'example.com', 'name='], ['?[query]']],
        'encoded e-mail' => ['user ana%40example.com', ['ana', 'example.com'], ['[email]']],
        'phone' => ['call +38640221931 now', ['38640221931', '40221931'], ['{phone}']],
        'quoted value in evaluating' => ["undefined is not a function (evaluating 'Rex')", ['Rex'], ['{s}']],
        'dotted code in evaluating stays' => ["undefined is not an object (evaluating 'pet.media.idle')", [], ['`pet.media.idle`']],
    ]);

    it('cannot remove free-text names — app code never interpolates them (DECISIONS 2026-10-24)', function () {
        app(AppErrorReportService::class)->record(aeBody(['message' => 'Pet Rex not found for Ana Novak']), null);

        // Documented limitation: plain words are not data the scrubber can recognise.
        // The guard is on the app side (errorReporting/__tests__/noNamesInErrors.test.ts).
        expect(AppErrorReport::sole()->sample['message'])->toBe('Pet Rex not found for Ana Novak');
    });

    it('keeps only identifier error names, else Error', function (string $name, string $stored) {
        app(AppErrorReportService::class)->record(aeBody(['name' => $name]), null);
        expect(AppErrorFingerprint::sole()->error_name)->toBe($stored);
    })->with([
        'type error' => ['TypeError', 'TypeError'],
        'api error with space' => ['Api Error', 'Api Error'],
        'e-mail' => ['ana@example.com', 'Error'],
        'quoted name' => ["Error 'Rex'", 'Error'],
        'digits' => ['Error4821', 'Error{n}'],
        'empty' => ['   ', 'Error'],
    ]);

    it('counts new fingerprints above the hourly cap into overflow, without alerts', function () {
        config(['system_health.app_errors.new_fingerprints_per_hour' => 3]);
        $service = app(AppErrorReportService::class);
        foreach (['a', 'b', 'c', 'd', 'e'] as $word) {
            $result = $service->record(aeBody(['message' => "distinct {$word}", 'stack' => "E\n    at {$word} (index.bundle:1:2)"]), null);
        }

        $overflow = AppErrorFingerprint::find(AppErrorReportService::OVERFLOW);
        expect(AppErrorFingerprint::count())->toBe(4)
            ->and($overflow->occurrences)->toBe(2)
            ->and($overflow->message)->not->toContain('distinct')
            ->and($result->alert)->toBeNull();
        // A known fingerprint is still recorded as itself.
        $service->record(aeBody(['message' => 'distinct a', 'stack' => "E\n    at a (index.bundle:1:2)"]), null);
        expect(AppErrorFingerprint::where('message', 'distinct a')->sole()->occurrences)->toBe(2);
    });
});

describe('Discord', function () {
    beforeEach(function () {
        config(['services.discord.alerts_webhook_url' => AE_DISCORD]);
        Http::fake([AE_DISCORD => Http::response(null, 204)]);
    });

    it('posts once for a new fingerprint, never for repeats', function () {
        $this->postJson('/api/app-errors', aeBody())->assertNoContent();
        $this->postJson('/api/app-errors', aeBody())->assertNoContent();
        $this->postJson('/api/app-errors', aeBody(['app_version' => '3.1.5']))->assertNoContent();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => str_contains($r['content'], 'New app error: TypeError')
            && $r['embeds'][0]['footer']['text'] === 'PetPrep · testing · app_error_new'
            && str_ends_with($r['embeds'][0]['url'], '/admin/system-health')
            && ! str_contains(json_encode($r->data()), 'abc123def'));
    });

    it('posts a spike at most once per fingerprint per day', function () {
        config(['system_health.app_errors.spike_per_hour' => 3]);
        $service = app(AppErrorReportService::class);
        $first = $service->record(aeBody(), null);
        expect($first->alert)->toBe(AdminAlertKind::AppErrorNew);
        SendAppErrorAlert::dispatchSync($first->fingerprint, $first->alert);
        $service->record(aeBody(), null);
        $spike = $service->record(aeBody(), null);
        expect($spike->alert)->toBe(AdminAlertKind::AppErrorSpike);
        // Same day → the claim refuses it.
        SendAppErrorAlert::dispatchSync($spike->fingerprint, $spike->alert);
        Http::assertSentCount(1);

        // Next day's spike posts again.
        Carbon::setTestNow(Carbon::now()->addDay()->addHour());
        $service->record(aeBody(), null);
        $service->record(aeBody(), null);
        $again = $service->record(aeBody(), null);
        expect($again->alert)->toBe(AdminAlertKind::AppErrorSpike);
        SendAppErrorAlert::dispatchSync($again->fingerprint, $again->alert);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => str_contains($r['content'], 'App error spike'));
    });

    it('queues nothing for a known fingerprint below the spike', function () {
        Queue::fake();
        $this->postJson('/api/app-errors', aeBody())->assertNoContent();
        $this->postJson('/api/app-errors', aeBody())->assertNoContent();

        Queue::assertPushed(SendAppErrorAlert::class, 1);
        Queue::assertPushedOn('alerts', SendAppErrorAlert::class);
    });

    it('posts at most 10 app error alerts an hour, then one suppressed summary', function () {
        Queue::fake([SendAppErrorSuppressedNotice::class]);
        $service = app(AppErrorReportService::class);
        foreach (range('a', 'm') as $word) {
            $r = $service->record(aeBody(['message' => "distinct {$word}", 'stack' => "E\n    at f{$word} (index.bundle:1:2)"]), null);
            SendAppErrorAlert::dispatchSync($r->fingerprint, $r->alert);
        }

        Http::assertSentCount(10);
        Queue::assertPushed(SendAppErrorSuppressedNotice::class, 1);
        Queue::assertPushedOn('alerts', SendAppErrorSuppressedNotice::class);

        (new SendAppErrorSuppressedNotice(Carbon::now()->utc()->format('YmdH')))->handle(app(DiscordWebhook::class));
        Http::assertSentCount(11);
        Http::assertSent(fn (Request $r): bool => str_contains($r['content'], '3 more app error alerts suppressed'));
    });

    it('does nothing without a webhook URL and keeps the claim free', function () {
        config(['services.discord.alerts_webhook_url' => null]);
        $this->postJson('/api/app-errors', aeBody())->assertNoContent();

        Http::assertNothingSent();
        expect(AppErrorFingerprint::sole()->last_alerted_at)->toBeNull();
    });

    it('never fails the report when Discord is down', function () {
        Http::fake([AE_DISCORD => Http::response(['message' => 'nope'], 500)]);
        $this->postJson('/api/app-errors', aeBody())->assertNoContent();

        expect(AppErrorReport::count())->toBe(1)
            ->and(AppErrorFingerprint::sole()->last_alerted_at)->not->toBeNull();
    });
});

describe('admin', function () {
    it('shows app errors with the 24 h / 7 d window to a superadmin', function () {
        $service = app(AppErrorReportService::class);
        $service->record(aeBody(['message' => 'Recent crash']), null);
        $service->record(aeBody(['message' => 'Recent crash', 'platform' => 'ios', 'app_version' => '3.1.5']), null);
        $service->record(aeBody(['message' => 'Three days ago']), null, Carbon::now()->subDays(3));
        $this->actingAs(aeAdmin());

        $recent = AppErrorFingerprint::query()->where('message', 'Recent crash')->sole();
        $old = AppErrorFingerprint::query()->where('message', 'Three days ago')->sole();

        Livewire::test(AppErrorsTable::class)
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old])
            ->assertSee('3.1.4, 3.1.5')
            ->assertSee('android, ios')
            ->filterTable('window', '168')
            ->assertCanSeeTableRecords([$recent, $old])
            ->assertTableActionExists('view');

        $details = view('filament.pages.system-health.app-error-details', [
            'error' => $recent,
            'reports' => $recent->reports()->get()->sortByDesc('last_seen_at')->values(),
            'last24' => $service->countSince($recent->fingerprint, 24),
        ])->render();
        expect($details)->toContain('PetMediaView')->toContain('3.1.5')->toContain('0 / 0 / 1');

        $this->get('/admin/system-health')->assertOk();
        $footer = (new ReflectionMethod(SystemHealth::class, 'getFooterWidgets'))->invoke(new SystemHealth);
        expect($footer)->toContain(AppErrorsTable::class);
    });

    it('the Feature switches toggle turns reports on, audited, default off', function () {
        AppSetting::query()->whereKey(AppSettingsService::APP_ERROR_REPORTS_KEY)->delete();
        Cache::forget(AppSettingsService::cacheKey(AppSettingsService::APP_ERROR_REPORTS_KEY));
        expect(app(AppSettingsService::class)->appErrorReports())->toBeFalse();
        $admin = aeAdmin();
        $this->actingAs($admin);

        Livewire::test(FeatureSwitches::class)
            ->assertSee('App error reports')
            ->assertFormSet(['app_error_reports' => false])
            ->fillForm(['app_error_reports' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(AppSettingsService::class)->appErrorReports(fresh: true))->toBeTrue();
        $change = AppSettingChange::query()->where('key', AppSettingsService::APP_ERROR_REPORTS_KEY)->sole();
        expect($change->user_id)->toBe($admin->id)->and($change->new)->toBe(['enabled' => true]);
    });

    it('refuses the section for anyone but a superadmin', function () {
        $this->actingAs(User::factory()->parent()->create());
        Livewire::test(AppErrorsTable::class)->assertForbidden();
        expect(AppErrorsTable::canView())->toBeFalse();
        $this->get('/admin/system-health')->assertForbidden();
        Livewire::test(SystemHealth::class)->assertForbidden();
    });
});
