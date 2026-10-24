<?php

use App\Enums\AdminAlertKind;
use App\Filament\Pages\SystemHealth;
use App\Filament\Widgets\SystemHealth\AppErrorsTable;
use App\Jobs\SendAppErrorAlert;
use App\Models\AppErrorFingerprint;
use App\Models\AppErrorReport;
use App\Models\User;
use App\Services\AppErrors\AppErrorReportService;
use App\Services\AppErrors\AppErrorText;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
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
});

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

    it('throttles per IP at 30 reports a minute', function () {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/app-errors', aeBody(['message' => "boom {$i}"]))->assertNoContent();
        }
        $this->postJson('/api/app-errors', aeBody())->assertTooManyRequests();
        // Another client address has its own bucket.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/app-errors', aeBody())->assertNoContent();
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

    it('refuses the section for anyone but a superadmin', function () {
        $this->actingAs(User::factory()->parent()->create());
        Livewire::test(AppErrorsTable::class)->assertForbidden();
        expect(AppErrorsTable::canView())->toBeFalse();
        $this->get('/admin/system-health')->assertForbidden();
        Livewire::test(SystemHealth::class)->assertForbidden();
    });
});
