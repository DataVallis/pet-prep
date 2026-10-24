<?php

use App\Enums\AdminAlertKind;
use App\Filament\Pages\SystemHealth;
use App\Models\AiBudgetAlert;
use App\Models\AiSpendLedger;
use App\Models\SystemError;
use App\Models\User;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\Alerts\DiscordWebhookException;
use App\Services\Media\AiBudgetMonitor;
use App\Services\Media\FalGateway;
use App\Services\SystemHealth\ErrorRecorder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M5-T19 — admin alerts in Discord (David 2026-10-24)
|--------------------------------------------------------------------------
|
| Every AI budget / fal.ai balance warning that reaches the superadmins' bell
| is also posted ONCE to the admin Discord channel when
| DISCORD_ALERTS_WEBHOOK_URL is set. Same dedupe as the bell, amounts only,
| a Discord failure never breaks the bell, the URL is never logged or shown.
|
*/

const DA_URL = 'https://discord.com/api/webhooks/123456/secret-token-abc';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    Sleep::fake();
    Cache::forget(FalGateway::BALANCE_FLAG_KEY);
    Cache::forget(AiBudgetMonitor::BALANCE_CACHE_KEY);
    config([
        'services.fal_ai.key' => 'test-key',
        'media.budget.daily_usd' => 10.0,
        'media.budget.monthly_usd' => 100.0,
        'media.lab.daily_usd' => 10.0,
        'media.lab.monthly_usd' => 100.0,
        'media.budget.timezone' => 'UTC',
        'mail.default' => 'log',
        'services.discord.alerts_webhook_url' => DA_URL,
    ]);
});

function daAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

function daSpend(float $usd): void
{
    AiSpendLedger::create(['purpose' => 'state_video', 'profile' => 'kling_v3_pro', 'endpoint' => 'x/y', 'unit' => 'second', 'units' => 5, 'cost_usd' => $usd, 'status' => 'committed']);
}

function daCheck(): int
{
    return app(AiBudgetMonitor::class)->check();
}

function daFakeDiscord(int $status = 204): void
{
    Http::fake(['discord.com/api/webhooks/*' => Http::response($status === 204 ? '' : ['message' => 'Server error'], $status)]);
}

it('posts one Discord message per new threshold and none on repeat runs', function () {
    $admin = daAdmin();
    daFakeDiscord();
    daSpend(8.0); // 80 % of the production daily cap

    expect(daCheck())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $r): bool {
        $embed = $r['embeds'][0];

        return $r->url() === DA_URL
            && $r->method() === 'POST'
            && $embed['color'] === AdminAlertKind::COLOUR_WARNING
            && str_ends_with($embed['url'], '/admin/ai-budget')
            && str_contains($r['content'], '80 %')
            && $r['allowed_mentions'] === ['parse' => []]
            && collect($embed['fields'])->pluck('value')->contains('$8.00 / $10.00')
            && collect($embed['fields'])->pluck('value')->contains('$2.00');
    });

    // Repeat runs: no new Discord post, no new bell.
    expect(daCheck())->toBe(0)->and(daCheck())->toBe(0);
    Http::assertSentCount(1);
    expect($admin->notifications()->count())->toBe(1);

    // 100 % → exactly one more post, red.
    daSpend(2.0);
    expect(daCheck())->toBe(1);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r): bool => $r['embeds'][0]['color'] === AdminAlertKind::COLOUR_DANGER);
    expect(daCheck())->toBe(0);
    Http::assertSentCount(2);
});

it('posts the fal.ai low balance once a day with a link to System health', function () {
    daAdmin();
    daFakeDiscord();
    Cache::forever(AiBudgetMonitor::BALANCE_CACHE_KEY, ['status' => 'ok', 'balance_usd' => 12.5, 'currency' => 'USD', 'error' => null, 'checked_at' => now()->toIso8601String()]);

    expect(daCheck())->toBe(1)->and(daCheck())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r): bool => $r['embeds'][0]['color'] === AdminAlertKind::COLOUR_WARNING
        && str_ends_with($r['embeds'][0]['url'], '/admin/system-health')
        && collect($r['embeds'][0]['fields'])->pluck('value')->contains('$12.50'));
});

it('retries a 429 after Discord\'s retry_after', function () {
    daAdmin();
    Http::fake(['discord.com/api/webhooks/*' => Http::sequence()
        ->push(['message' => 'You are being rate limited.', 'retry_after' => 1.5, 'global' => false], 429)
        ->push('', 204)]);
    daSpend(8.0);

    expect(daCheck())->toBe(1);
    Http::assertSentCount(2);
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 1500);
    expect(SystemError::count())->toBe(0);
});

it('gives up on a 429 that asks to wait too long, without breaking the bell', function () {
    $admin = daAdmin();
    Http::fake(['discord.com/api/webhooks/*' => Http::response(['retry_after' => 60], 429)]);
    daSpend(8.0);

    expect(daCheck())->toBe(1);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
    expect($admin->notifications()->count())->toBe(1);
});

it('a failing Discord never stops the bell, never repeats it and never stops other budgets', function () {
    // phpunit.xml turns the System health capture off for the rest of the suite.
    config(['system_health.capture_enabled' => true, 'system_health.isolated_connection' => false]);
    app()->forgetInstance(ErrorRecorder::class);
    $admin = daAdmin();
    daFakeDiscord(500);
    daSpend(8.0); // production day 80 %
    AiSpendLedger::create(['purpose' => 'lab', 'profile' => 'kling_v3_pro', 'endpoint' => 'x/y', 'unit' => 'second', 'units' => 5, 'cost_usd' => 9.0, 'status' => 'committed']);

    $sent = daCheck();

    expect($sent)->toBe(2)
        ->and($admin->notifications()->count())->toBe($sent)
        ->and(AiBudgetAlert::count())->toBeGreaterThanOrEqual($sent);
    Http::assertSentCount($sent);

    // The claims stay: no bell and no Discord retry on the next run.
    expect(daCheck())->toBe(0);
    Http::assertSentCount($sent);
    expect($admin->notifications()->count())->toBe($sent);

    // Reported to System health without the webhook URL.
    $errors = SystemError::all();
    expect($errors)->not->toBeEmpty()
        ->and($errors->every(fn (SystemError $e): bool => ! str_contains((string) $e->message, 'secret-token-abc') && str_contains((string) $e->message, 'HTTP 500')))->toBeTrue();
});

it('never leaks the webhook URL from a connection error', function () {
    Http::fake(['discord.com/api/webhooks/*' => fn () => throw new ConnectionException('cURL error 28: timed out for '.DA_URL)]);

    expect(fn () => app(DiscordWebhook::class)->send(new AdminAlert(AdminAlertKind::Test, 'Test', 'Body')))
        ->toThrow(function (DiscordWebhookException $e): void {
            expect($e->getMessage())->not->toContain('secret-token-abc')
                ->and($e->getPrevious())->toBeNull();
        });
});

it('sends no HTTP without a webhook URL — the bell still works', function () {
    config(['services.discord.alerts_webhook_url' => null]);
    $admin = daAdmin();
    daSpend(8.0);

    expect(app(DiscordWebhook::class)->configured())->toBeFalse()
        ->and(daCheck())->toBe(1)
        ->and($admin->notifications()->count())->toBe(1);
    Http::assertNothingSent();
});

it('refuses to post inside a database transaction', function () {
    daFakeDiscord();

    expect(fn () => DB::transaction(fn () => app(DiscordWebhook::class)->send(new AdminAlert(AdminAlertKind::Test, 'T', 'B'))))
        ->toThrow(LogicException::class);
    Http::assertNothingSent();
});

it('the System health »Test Discord« button sends one test message and shows the result', function () {
    $this->actingAs(daAdmin());
    daFakeDiscord();

    Livewire::test(SystemHealth::class)
        ->callAction('testDiscord')
        ->assertNotified(__('admin.system_health.discord.sent'));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r): bool => $r['embeds'][0]['color'] === AdminAlertKind::COLOUR_INFO
        && str_contains($r['embeds'][0]['footer']['text'], 'test'));
});

it('the »Test Discord« button shows the error without the URL', function () {
    $this->actingAs(daAdmin());
    Http::fake(['discord.com/api/webhooks/*' => Http::response(['message' => 'Unknown Webhook', 'code' => 10015], 404)]);

    Livewire::test(SystemHealth::class)
        ->callAction('testDiscord')
        ->assertNotified(__('admin.system_health.discord.failed'))
        ->assertDontSee('secret-token-abc');
});

it('the »Test Discord« button says when Discord is not configured, without HTTP', function () {
    config(['services.discord.alerts_webhook_url' => '']);
    $this->actingAs(daAdmin());

    Livewire::test(SystemHealth::class)
        ->callAction('testDiscord')
        ->assertNotified(__('admin.system_health.discord.not_configured'));
    Http::assertNothingSent();
});

it('the »Test Discord« button is superadmin only', function () {
    $this->actingAs(User::factory()->parent()->create());

    Livewire::test(SystemHealth::class)->assertForbidden();
    Http::assertNothingSent();
});
