<?php

use App\Console\Commands\CheckGameLoopCommand;
use App\Enums\AdminAlertKind;
use App\Filament\Widgets\SystemHealth\SystemHealthStats;
use App\Models\User;
use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| DEPLOYMENT.md D34 — game loop stall (incident 2026-11-04)
|--------------------------------------------------------------------------
|
| After a reboot a killed `pets:process-decay` run left its overlap mutex
| (default expiry 24 h) and the scheduler silently skipped the game loop for
| ~9 h. Now every withoutOverlapping() has a short explicit expiry, and
| `system-health:game-loop-check` (its own minutely event) posts ONE Discord
| `game_loop_stalled` alert per stall episode when the heartbeat is > 5 min old.
|
*/

const GLS_URL = 'https://discord.com/api/webhooks/123456/secret-token-abc';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
    Http::preventStrayRequests();
    Sleep::fake();
    config([
        'services.discord.alerts_webhook_url' => GLS_URL,
        'system_health.heartbeat_stale_minutes' => 5,
    ]);
    Cache::flush();
});

/** Discord answers 204 (one fake per test: a second Http::fake() would not replace the stub). */
function glsFakeDiscord(): void
{
    Http::fake(['discord.com/api/webhooks/*' => Http::response(null, 204)]);
}

/** One check run at "now + $minutes" (the clock moves forward). */
function glsCheckAt(int $minutes): void
{
    Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'UTC')->addMinutes($minutes));
    Artisan::call('system-health:game-loop-check');
}

function glsBeatAt(int $minutes): void
{
    app(SystemHealthService::class)->beat(Carbon::parse('2026-11-04 12:00:00', 'UTC')->addMinutes($minutes));
}

it('stays quiet while the game loop ticks', function () {
    glsFakeDiscord();
    for ($m = 0; $m <= 20; $m++) {
        glsBeatAt($m);
        glsCheckAt($m);
    }

    Http::assertNothingSent();
});

it('posts ONE danger alert with the minutes only when the heartbeat is older than 5 min', function () {
    glsFakeDiscord();
    glsBeatAt(0);
    for ($m = 1; $m <= 5; $m++) {
        glsCheckAt($m);
    }
    Http::assertNothingSent(); // exactly 5 min old is not stale yet (same rule as the System health tile)

    glsCheckAt(6);
    glsCheckAt(7);
    glsCheckAt(30);
    glsCheckAt(9 * 60);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $r): bool {
        $embed = $r['embeds'][0];
        $fields = collect($embed['fields'])->pluck('value', 'name')->all();

        return $embed['color'] === AdminAlertKind::COLOUR_DANGER
            && str_ends_with($embed['url'], '/admin/system-health')
            && str_contains($embed['footer']['text'], 'game_loop_stalled')
            && $fields[__('admin.game_loop_stalled.discord.minutes')] === '6'
            && $fields[__('admin.game_loop_stalled.discord.threshold')] === '5'
            && ! str_contains(json_encode($r->data()), GLS_URL);
    });
});

it('re-arms after the heartbeat recovers: a second stall posts again', function () {
    glsFakeDiscord();
    glsBeatAt(0);
    for ($m = 1; $m <= 8; $m++) {
        glsCheckAt($m);
    }
    Http::assertSentCount(1);

    // The loop runs again (e.g. after schedule:clear-cache) …
    for ($m = 9; $m <= 12; $m++) {
        glsBeatAt($m);
        glsCheckAt($m);
    }
    Http::assertSentCount(1);

    // … and stalls again → one new post.
    for ($m = 13; $m <= 25; $m++) {
        glsCheckAt($m);
    }
    Http::assertSentCount(2);
});

it('observes once after a gap in its own runs (scheduler restart / maintenance) — no false alarm', function () {
    glsFakeDiscord();
    glsBeatAt(0);
    glsCheckAt(0);

    // Scheduler down / app in maintenance for 20 min: neither the tick nor the check ran.
    glsCheckAt(20);
    Http::assertNothingSent();

    // The tick of that minute beats → the next check sees a healthy loop.
    glsBeatAt(20);
    glsCheckAt(21);
    Http::assertNothingSent();

    // If the loop really stays down, the second consecutive check alerts.
    glsCheckAt(40);
    glsCheckAt(41);
    Http::assertSentCount(1);
});

it('alerts when no heartbeat was ever recorded, counted from the first check that saw none', function () {
    glsFakeDiscord();
    for ($m = 0; $m <= 4; $m++) {
        glsCheckAt($m);
    }
    Http::assertNothingSent();

    glsCheckAt(5);
    Http::assertNothingSent();
    glsCheckAt(6);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r): bool => collect($r['embeds'][0]['fields'])->pluck('value', 'name')->all()[__('admin.game_loop_stalled.discord.minutes')] === '6');
});

it('a failed post releases the claim so the next minute retries', function () {
    Http::fake(['discord.com/api/webhooks/*' => Http::sequence()
        ->push(['message' => 'nope'], 500)
        ->push(null, 204)]);
    glsBeatAt(0);
    for ($m = 1; $m <= 6; $m++) {
        glsCheckAt($m);
    }
    Http::assertSentCount(1);

    glsCheckAt(7);
    Http::assertSentCount(2);
    glsCheckAt(8);
    Http::assertSentCount(2);
});

it('sends nothing and claims nothing without a Discord webhook', function () {
    config(['services.discord.alerts_webhook_url' => '']);
    glsBeatAt(0);
    for ($m = 1; $m <= 10; $m++) {
        glsCheckAt($m);
    }

    Http::assertNothingSent();
    expect(Cache::has(CheckGameLoopCommand::CLAIM_PREFIX.Carbon::parse('2026-11-04 12:00:00', 'UTC')->getTimestamp()))->toBeFalse();
});

it('is its own minutely event in the background with a short overlap lock', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'system-health:game-loop-check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(2)
        ->and($event->runInBackground)->toBeTrue();
});

it('gives every withoutOverlapping() event an explicit expiry far below the 24 h default', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->withoutOverlapping);

    expect($events)->not->toBeEmpty();
    foreach ($events as $event) {
        expect($event->expiresAt)->toBeLessThanOrEqual(60, (string) $event->command);
    }

    $byCommand = fn (string $c) => $events->first(fn ($e) => str_contains((string) $e->command, $c));
    expect($byCommand('pets:process-decay')->expiresAt)->toBe(3)
        ->and($byCommand('push:dispatch-scheduled')->expiresAt)->toBe(3);
});

it('a stale decay mutex blocks the tick only until its expiry', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'pets:process-decay'));

    // A killed run left its mutex behind.
    expect($event->mutex->create($event))->toBeTrue()
        ->and($event->filtersPass(app()))->toBeFalse();

    $this->travel(4)->minutes();

    expect($event->filtersPass(app()))->toBeTrue();
});

it('System health shows the stalled loop red with the recovery hint', function () {
    glsBeatAt(0);
    Carbon::setTestNow(Carbon::parse('2026-11-04 12:10:00', 'UTC'));
    $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

    $component = Livewire::test(SystemHealthStats::class);
    $tile = collect((fn () => $this->getStats())->call($component->instance()))
        ->first(fn ($s) => $s->getLabel() === __('admin.system_health.stats.heartbeat'));

    expect($tile->getColor())->toBe('danger')
        ->and((string) $tile->getDescription())->toContain('schedule:clear-cache');
});
