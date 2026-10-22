<?php

use App\Filament\Pages\AiBudget;
use App\Filament\Widgets\AiBudgetBanner;
use App\Models\AiBudgetAlert;
use App\Models\AiSpendLedger;
use App\Models\User;
use App\Notifications\AiBudgetAlertNotification;
use App\Services\AppSettingsService;
use App\Services\Media\AiBudgetMonitor;
use App\Services\Media\AiBudgetStatus;
use App\Services\Media\FalGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M5-T15 — AI budget warnings (David 2026-10-22)
|--------------------------------------------------------------------------
|
| Warn when only 20 % of a budget is left (≥ 80 % used) and again at 100 %
| (blocked), for the production and the AI Lab daily / monthly caps,
| whatever amount is configured; at most once per threshold per budget day /
| month. Plus the fal.ai account balance (Platform API) with a low-balance
| warning.
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    Cache::forget(FalGateway::BALANCE_FLAG_KEY);
    Cache::forget(AiBudgetMonitor::BALANCE_CACHE_KEY);
    config([
        'services.fal_ai.key' => 'test-key',
        'services.fal_ai.billing_key' => null,
        'media.budget.daily_usd' => 10.0,
        'media.budget.monthly_usd' => 100.0,
        'media.lab.daily_usd' => 10.0,
        'media.lab.monthly_usd' => 100.0,
        'media.budget.timezone' => 'UTC',
        'mail.default' => 'log',
    ]);
});

function bwAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

function bwSpend(float $usd, string $purpose = 'state_video', string $status = 'committed', ?string $error = null): void
{
    AiSpendLedger::create(['purpose' => $purpose, 'profile' => 'kling_v3_pro', 'endpoint' => 'x/y', 'unit' => 'second', 'units' => 5, 'cost_usd' => $usd, 'status' => $status, 'error_reason' => $error]);
}

function bwMonitor(): AiBudgetMonitor
{
    return app(AiBudgetMonitor::class);
}

function bwStatus(string $budget, string $period): AiBudgetStatus
{
    return collect(bwMonitor()->statuses())->first(fn (AiBudgetStatus $s): bool => $s->budget === $budget && $s->period === $period);
}

it('is ok at 79 %, a warning at 80 % and blocked at 100 % of the daily cap', function (float $spent, string $level) {
    bwSpend($spent);

    expect(bwStatus('production', 'day')->level())->toBe($level)
        ->and(bwStatus('production', 'month')->level())->toBe(AiBudgetStatus::OK);
})->with([
    '79 %' => [7.9, AiBudgetStatus::OK],
    '80 %' => [8.0, AiBudgetStatus::WARNING],
    '99 %' => [9.9, AiBudgetStatus::WARNING],
    '100 %' => [10.0, AiBudgetStatus::BLOCKED],
]);

it('counts reserved and committed but not void spend — the AI budget page numbers', function () {
    bwSpend(5.0, status: 'reserved');
    bwSpend(3.0);
    bwSpend(9.0, status: 'void');

    $status = bwStatus('production', 'day');
    expect($status->spentUsd)->toBe(8.0)
        ->and($status->level())->toBe(AiBudgetStatus::WARNING);
});

it('sends no warning at 79 %', function () {
    $admin = bwAdmin();
    bwSpend(7.9);

    expect(bwMonitor()->check())->toBe(0)
        ->and($admin->notifications()->count())->toBe(0)
        ->and(AiBudgetAlert::count())->toBe(0);
});

it('warns the superadmins once at 80 % and once more at 100 %', function () {
    $admin = bwAdmin();
    $parent = User::factory()->create(['role' => 'parent']);
    bwSpend(8.0);

    expect(bwMonitor()->check())->toBe(1);
    $note = $admin->notifications()->first();
    expect($note)->not->toBeNull()
        ->and($note->data['format'])->toBe('filament')
        ->and($note->data['title'])->toContain('80')
        ->and($note->data['body'])->toContain('$8.00')->toContain('$10.00')->toContain('$2.00')
        ->and($parent->notifications()->count())->toBe(0);

    // Same day, more spend under 100 %: no repeat.
    bwSpend(1.0);
    expect(bwMonitor()->check())->toBe(0)
        ->and($admin->notifications()->count())->toBe(1);

    bwSpend(1.0);
    expect(bwMonitor()->check())->toBe(1)
        ->and($admin->notifications()->count())->toBe(2)
        ->and($admin->notifications()->get()->pluck('data.title')->sort()->values()->all())->toBe(['AI budget at 80 %', 'AI budget used up (100 %)']);

    expect(bwMonitor()->check())->toBe(0)
        ->and(AiBudgetAlert::where('budget', 'production')->where('period', 'day')->pluck('threshold')->sort()->values()->all())->toBe([80, 100]);
});

it('warns again on the next budget day, but the month only once', function () {
    $admin = bwAdmin();
    config(['media.budget.monthly_usd' => 20.0]);
    bwSpend(8.0); // day 80 %, month 40 %

    expect(bwMonitor()->check())->toBe(1);

    $this->travelTo(Carbon::parse('2026-10-23 10:00:00', 'UTC'));
    bwSpend(8.0); // new day 80 %, month 80 %

    expect(bwMonitor()->check())->toBe(2)
        ->and($admin->notifications()->count())->toBe(3);

    $this->travelTo(Carbon::parse('2026-10-24 10:00:00', 'UTC'));
    bwSpend(1.0); // day 10 %, month 85 % (already announced)

    expect(bwMonitor()->check())->toBe(0);
});

it('jumping from below 80 % straight to 100 % sends only the blocked warning', function () {
    $admin = bwAdmin();
    bwSpend(10.0);

    expect(bwMonitor()->check())->toBe(1)
        ->and($admin->notifications()->count())->toBe(1)
        ->and($admin->notifications()->first()->data['title'])->toContain('100')
        ->and(AiBudgetAlert::count())->toBe(2);
});

it('keeps the AI Lab budget separate from production', function () {
    $admin = bwAdmin();
    bwSpend(8.5, purpose: 'lab');

    expect(bwStatus('lab', 'day')->level())->toBe(AiBudgetStatus::WARNING)
        ->and(bwStatus('production', 'day')->level())->toBe(AiBudgetStatus::OK)
        ->and(bwMonitor()->check())->toBe(1)
        ->and(AiBudgetAlert::pluck('budget')->all())->toBe(['lab'])
        ->and($admin->notifications()->first()->data['body'])->toContain('AI Lab');

    // Production reaching 80 % is its own warning on the same day.
    bwSpend(8.0);
    expect(bwMonitor()->check())->toBe(1)
        ->and(AiBudgetAlert::where('budget', 'production')->count())->toBe(1);
});

it('re-computes the percentage when the cap changes in /admin', function () {
    $admin = bwAdmin();
    $settings = app(AppSettingsService::class);
    bwSpend(8.0);

    expect(bwStatus('production', 'day')->level())->toBe(AiBudgetStatus::WARNING);

    $settings->updateAiBudget($admin, 25.0, null);
    expect(bwStatus('production', 'day')->percent())->toBe(32.0)
        ->and(bwStatus('production', 'day')->level())->toBe(AiBudgetStatus::OK)
        ->and(bwMonitor()->check())->toBe(0);

    $settings->updateAiBudget($admin, 8.0, null);
    expect(bwStatus('production', 'day')->level())->toBe(AiBudgetStatus::BLOCKED)
        ->and(bwMonitor()->check())->toBe(1);

    // Lab cap from /admin too.
    $settings->updateAiLabBudget($admin, 1.0, null, null);
    bwSpend(0.8, purpose: 'lab');
    expect(bwStatus('lab', 'day')->level())->toBe(AiBudgetStatus::WARNING);
});

it('shows a cap of 0 as blocked but does not announce it', function () {
    bwAdmin();
    config(['media.lab.daily_usd' => 0.0]);

    expect(bwStatus('lab', 'day')->level())->toBe(AiBudgetStatus::BLOCKED)
        ->and(bwMonitor()->check())->toBe(0);
});

it('sends e-mail only when a real mailer is configured', function () {
    Notification::fake();
    $admin = bwAdmin();
    bwSpend(8.0);

    bwMonitor()->check();
    Notification::assertSentTo($admin, AiBudgetAlertNotification::class, fn ($n, array $channels) => $channels === ['database']);

    config(['mail.default' => 'smtp']);
    bwSpend(2.0);
    bwMonitor()->check();
    Notification::assertSentTo($admin, AiBudgetAlertNotification::class, fn ($n, array $channels) => $channels === ['database', 'mail']);
});

it('releases the claim when sending fails, so the next run retries', function () {
    bwAdmin();
    bwSpend(8.0);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('mail down'));

    expect(fn () => bwMonitor()->check())->toThrow(RuntimeException::class)
        ->and(AiBudgetAlert::count())->toBe(0);
});

it('reads the fal.ai balance from the Platform API and warns once a day below the threshold', function () {
    $admin = bwAdmin();
    Http::fake(['api.fal.ai/v1/account/billing*' => Http::response(['username' => 'x', 'credits' => ['current_balance' => 12.5, 'currency' => 'USD']])]);

    $balance = bwMonitor()->refreshFalBalance();
    expect($balance['status'])->toBe('ok')
        ->and($balance['balance_usd'])->toBe(12.5)
        ->and(bwMonitor()->falBalanceLevel())->toBe('low');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'expand=credits') && $r->header('Authorization') === ['Key test-key']);

    expect(bwMonitor()->check())->toBe(1)
        ->and($admin->notifications()->first()->data['title'])->toBe('fal.ai balance low')
        ->and(bwMonitor()->check())->toBe(0);

    // Cached for 15 minutes.
    bwMonitor()->refreshFalBalance();
    Http::assertSentCount(1);

    // Threshold from /admin.
    app(AppSettingsService::class)->updateFalBalanceAlert($admin, 10.0);
    expect(bwMonitor()->falBalanceLevel())->toBe('ok');
});

it('uses FAL_AI_BILLING_KEY and reports a key without the billing scope as no access', function () {
    config(['services.fal_ai.billing_key' => 'billing-key']);
    Http::fake(['api.fal.ai/*' => Http::response(['error' => ['type' => 'forbidden', 'message' => 'Missing scope']], 403)]);

    $balance = bwMonitor()->refreshFalBalance(force: true);

    expect($balance['status'])->toBe('no_access')
        ->and(bwMonitor()->falBalanceLevel())->toBe('unknown')
        ->and(FalGateway::balanceExhaustedAt())->toBeNull();
    Http::assertSent(fn ($r) => $r->header('Authorization') === ['Key billing-key']);
});

it('counts fal_balance refusals of the last 24 h and warns when the balance is exhausted', function () {
    $admin = bwAdmin();
    bwSpend(0.56, status: 'void', error: 'fal_balance');
    bwSpend(0.56, status: 'void', error: 'fal_balance');
    $this->travel(-25)->hours();
    bwSpend(0.56, status: 'void', error: 'fal_balance');
    $this->travelBack();
    Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', 'UTC'));
    Cache::forever(FalGateway::BALANCE_FLAG_KEY, '2026-10-22T09:00:00+00:00');

    expect(bwMonitor()->falBalanceFailures(24))->toBe(2)
        ->and(bwMonitor()->falBalanceLevel())->toBe('exhausted')
        ->and(bwMonitor()->check())->toBe(1)
        ->and($admin->notifications()->first()->data['title'])->toBe('fal.ai balance exhausted');
});

it('the queued job reads the balance and sends the warnings', function () {
    $admin = bwAdmin();
    Http::fake(['api.fal.ai/*' => Http::response(['credits' => ['current_balance' => 100, 'currency' => 'USD']])]);
    bwSpend(8.0);

    $this->artisan('ai:budget-check')->assertSuccessful();

    expect($admin->notifications()->count())->toBe(1)
        ->and(bwMonitor()->falBalance()['balance_usd'])->toBe(100.0);
});

it('shows the banner on the dashboard and System health — yellow at 80 %, red at 100 %', function () {
    $admin = bwAdmin();
    $this->actingAs($admin);

    Livewire::test(AiBudgetBanner::class)
        ->assertSee('AI budgets OK')
        ->assertSeeHtml('data-ai-budget-level="ok"')
        ->assertSee('fal.ai balance not read yet');

    bwSpend(8.0);
    Livewire::test(AiBudgetBanner::class)
        ->assertSeeHtml('data-ai-budget-level="warning"')
        ->assertSee('$8.00')
        ->assertSee('/admin/ai-budget');

    bwSpend(2.0);
    Livewire::test(AiBudgetBanner::class)->assertSeeHtml('data-ai-budget-level="blocked"');

    $this->get('/admin/system-health')->assertOk()->assertSee('AI budget used up');
    $this->get('/admin')->assertOk()->assertSee('AI budget used up');
});

it('hides the banner from non-superadmins', function () {
    $this->actingAs(User::factory()->create(['role' => 'parent']));

    expect(AiBudgetBanner::canView())->toBeFalse();
});

it('the AI budget page explains the cost per new breed + colour + stage and saves the fal threshold', function () {
    $admin = bwAdmin();
    $this->actingAs($admin);

    Livewire::test(AiBudget::class)
        ->assertSee('11.17')
        ->assertSee('12.59')
        ->assertSee('7.40')
        ->assertSee('80 %')
        ->assertDontSee('4.63')
        ->set('data.fal_balance_alert_usd', 35)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(AppSettingsService::class)->falBalanceAlertUsd())->toBe(35.0);
});
